<?php
/**
 * bulk_upload_add.php
 * ─────────────────────────────────────────────────────────────────────────────
 * バッチへファイルを追加する。1リクエストにつき最大 BULK_FILES_PER_REQUEST 本。
 *
 * 受信 (multipart/form-data):
 *   loginkey    : string  必須
 *   batch_id    : string  必須
 *   file1 / file2 / file3 : PDF（または files[] 形式）
 *   as_one_case : bool    省略可。true なら、このリクエストのファイル（最大3本）を1案件にまとめる
 *                         （ドラッグ＆ドロップ1回＝1案件）。承認せずのバッチでのみ使える。
 *                         1本でも受け付けられないファイルがあれば、何も保存せず 400 を返す。
 *
 * 返却:
 *   { status:"success", batch_id, case_group, accepted:[{seq, name, size}], total_files, skipped:[...] }
 *
 * 備考:
 *   - PHP の max_file_uploads=20 のため、100本を1リクエストで送ることはできない
 *     （21本目以降が無言で捨てられる）。必ず分割して送ること。
 *   - ここでは GCS へ保存して明細行を作るだけ。期判定や解析は行わない。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag, $gcs_bucket, $google_application_credentials, $port;

if (!empty($google_application_credentials)) {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $google_application_credentials);
}

$body     = bulk_read_request();   // POST 専用（GET は 405）。multipart なので $_POST が返る
$loginkey = $body['loginkey'] ?? null;
$batch_id = $body['batch_id'] ?? null;
// このリクエストで送ったファイルを1案件にまとめるか（ドラッグ＆ドロップ1回＝1案件）
$asOneCase = filter_var($body['as_one_case'] ?? false, FILTER_VALIDATE_BOOLEAN);

if (empty($batch_id)) {
    bulk_error('batch_id が指定されていません。');
}

// ── 認証 ──
$user_id = null;
if ($damy_flag !== "OK") {
    if (empty($loginkey)) { bulk_error('loginkey が指定されていません。', 401); }
    $user_id = get_user_id_for_now($loginkey);
    if ($user_id === null) { bulk_error('異常アクセス@' . $loginkey, 401); }
}

$company_id = 0;
if ($user_id !== null) {
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $company_id = (int)($stmt->get_result()->fetch_assoc()['company_id'] ?? 0);
    $stmt->close();
}

$batch = bulk_fetch_batch($conn, $batch_id, $company_id);
if ($batch['status'] !== BULK_ST_OPEN) {
    bulk_error('このバッチは受付を終了しています（状態: ' . $batch['status'] . '）。', 409);
}

// ── 受け取るファイルを集める ──
// file1/file2/file3 形式と files[] 形式の両方を受ける。
$incoming = [];
foreach ($_FILES as $key => $info) {
    if ($key === 'meta') { continue; }
    if (is_array($info['name'])) {
        foreach ($info['name'] as $i => $nm) {
            $incoming[] = [
                'name'  => $nm,
                'tmp'   => $info['tmp_name'][$i],
                'size'  => (int)$info['size'][$i],
                'error' => (int)$info['error'][$i],
            ];
        }
    } else {
        $incoming[] = [
            'name'  => $info['name'],
            'tmp'   => $info['tmp_name'],
            'size'  => (int)$info['size'],
            'error' => (int)$info['error'],
        ];
    }
}

if (!$incoming) {
    bulk_error('ファイルが送信されていません。');
}
if (count($incoming) > BULK_FILES_PER_REQUEST) {
    bulk_error('1回に送れるファイルは ' . BULK_FILES_PER_REQUEST . ' 本までです（送信: ' . count($incoming) . ' 本）。');
}

// ── 現在の件数と次の連番 ──
$stmt = $conn->prepare("SELECT COALESCE(MAX(seq), 0) AS mx, COUNT(*) AS cnt FROM bulk_batch_files WHERE batch_id = ?");
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$cur = $stmt->get_result()->fetch_assoc();
$stmt->close();
$seq   = (int)$cur['mx'];
$count = (int)$cur['cnt'];

if ($count + count($incoming) > BULK_MAX_FILES) {
    bulk_error('1バッチあたりのファイル数は ' . BULK_MAX_FILES . ' 本までです（現在 ' . $count . ' 本）。', 409);
}

// ── 1案件にまとめる場合（as_one_case）──
// 承認せずのときだけ。まとめたファイルは、ワーカーが通常画面と同じ getpdfinfo.php に
// 1回でまとめて渡し、1案件として登録する（getpdfinfo.php は最大3ファイルを1案件にする作り）。
// 意図と違う組み合わせの案件ができないよう、1つでも受け付けられないファイルがあれば
// 何も保存せずに断る（全部か、何もしないか）。
$caseGroup = null;
if ($asOneCase) {
    if ($batch['mode'] !== BULK_MODE_DIRECT) {
        bulk_error('1案件にまとめる指定（as_one_case）は、承認せず（no_review）のバッチでのみ使えます。');
    }
    $bad = [];
    foreach ($incoming as $f) {
        $ext = strtolower(pathinfo(basename($f['name']), PATHINFO_EXTENSION));
        if ($f['error'] !== UPLOAD_ERR_OK)        { $bad[] = ['name' => $f['name'], 'reason' => 'アップロードに失敗しました（code=' . $f['error'] . '）']; }
        elseif ($f['size'] > BULK_MAX_FILE_SIZE) { $bad[] = ['name' => $f['name'], 'reason' => '1ファイル ' . (BULK_MAX_FILE_SIZE / 1024 / 1024) . 'MB を超えています']; }
        elseif ($ext !== 'pdf')                  { $bad[] = ['name' => $f['name'], 'reason' => 'PDF以外は受け付けていません']; }
    }
    if ($bad) {
        bulk_error('受け付けられないファイルがあるため、この案件は登録しませんでした。', 400, ['skipped' => $bad]);
    }
    $stmt = $conn->prepare("SELECT COALESCE(MAX(case_group), 0) + 1 AS g FROM bulk_batch_files WHERE batch_id = ?");
    $stmt->bind_param('s', $batch_id);
    $stmt->execute();
    $caseGroup = (int)$stmt->get_result()->fetch_assoc()['g'];
    $stmt->close();
}
$groupSaved = [];   // まとめる場合に、途中で失敗したら取り消すための記録

$accepted = [];
$skipped  = [];

foreach ($incoming as $f) {
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $skipped[] = ['name' => $f['name'], 'reason' => 'アップロードに失敗しました（code=' . $f['error'] . '）'];
        continue;
    }
    if ($f['size'] > BULK_MAX_FILE_SIZE) {
        $skipped[] = ['name' => $f['name'], 'reason' => '1ファイル ' . (BULK_MAX_FILE_SIZE / 1024 / 1024) . 'MB を超えています'];
        continue;
    }
    $ext = strtolower(pathinfo(basename($f['name']), PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        $skipped[] = ['name' => $f['name'], 'reason' => 'PDF以外は受け付けていません'];
        continue;
    }

    $seq++;
    // GCSバケットは 8012/8056 で共用のため、パスにポートを入れて環境を分ける。
    // batch_id はDBごとの連番なので、入れないと両環境で同じIDが採番され
    // 先にアップした側のPDFを上書きしてしまう。
    $objectName = BULK_GCS_PREFIX . $port . '/' . $batch_id . '/' . sprintf('%03d', $seq) . '.pdf';

    try {
        $gcsUri = bulk_upload_to_gcs($f['tmp'], $gcs_bucket, $objectName);
    } catch (Throwable $e) {
        bulk_log("ADD GCS_FAIL batch_id={$batch_id} name={$f['name']} err=" . $e->getMessage());
        if ($caseGroup !== null) {
            // まとめる場合は、ここまでに保存した分も取り消して、案件ごと断る
            foreach ($groupSaved as $g) {
                bulk_delete_gcs($g['uri']);
                $conn->query("DELETE FROM bulk_batch_files WHERE id = " . (int)$g['id']);
            }
            bulk_refresh_counts($conn, $batch_id);
            bulk_error('ファイルの保存に失敗したため、この案件は登録しませんでした。もう一度お試しください。', 500,
                       ['skipped' => [['name' => $f['name'], 'reason' => '保存に失敗しました']]]);
        }
        $skipped[] = ['name' => $f['name'], 'reason' => '保存に失敗しました'];
        $seq--;   // 連番を戻す
        continue;
    }

    $status = BULK_F_RECEIVED;
    $stmt = $conn->prepare(
        "INSERT INTO bulk_batch_files
           (batch_id, seq, original_name, gcs_path, size_bytes, case_group, status, create_at, update_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, now(), now())"
    );
    $stmt->bind_param('sissiis', $batch_id, $seq, $f['name'], $gcsUri, $f['size'], $caseGroup, $status);
    $stmt->execute();
    $groupSaved[] = ['id' => (int)$conn->insert_id, 'uri' => $gcsUri];
    $stmt->close();

    $accepted[] = ['seq' => $seq, 'name' => $f['name'], 'size' => $f['size']];
}

bulk_refresh_counts($conn, $batch_id);
bulk_log("ADD batch_id={$batch_id} accepted=" . count($accepted) . " skipped=" . count($skipped));

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bulk_batch_files WHERE batch_id = ?");
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

bulk_json([
    'status'      => 'success',
    'batch_id'    => $batch_id,
    'case_group'  => $caseGroup,   // 1案件にまとめた場合の組番号（まとめない場合は null）
    'accepted'    => $accepted,
    'skipped'     => $skipped,
    'total_files' => $total,
]);
