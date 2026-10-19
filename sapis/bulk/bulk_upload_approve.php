<?php
/**
 * bulk_upload_approve.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 確認画面での承認を受け取り、ai_case を作成して解析待ちにする。
 *
 * 受信 (JSON):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *   files    : [ { seq:1, include:true, posting_period:"P2" }, ... ]
 *              省略した seq は判定結果のまま採用する。
 *              include=false の行は SKIPPED にして案件を作らない。
 *
 * 返却:
 *   { status:"success", batch_id, approved:n, skipped:n, batch_status:"APPROVED" }
 *
 * 備考:
 *   - ai_case はここで初めて作る。承認前の中途半端な案件を
 *     ファイル一覧に出さないため。
 *   - 解析そのものは行わない。ワーカーが APPROVED のバッチを拾って実行する。
 *   - ポイントは通常の解析と同じ扱い（解析実行時に既存APIが消費する）。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { $body = $_POST; }

$loginkey = $body['loginkey'] ?? null;
$batch_id = $body['batch_id'] ?? null;
$overrides = is_array($body['files'] ?? null) ? $body['files'] : [];

if (empty($batch_id)) { bulk_error('batch_id が指定されていません。'); }

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
if ($batch['status'] !== BULK_ST_REVIEW) {
    bulk_error('このバッチは確認待ちの状態ではありません（状態: ' . $batch['status'] . '）。', 409);
}

// seq => 指定内容 に変換
$ovr = [];
foreach ($overrides as $o) {
    if (!is_array($o) || !isset($o['seq'])) { continue; }
    $ovr[(int)$o['seq']] = $o;
}

$stmt = $conn->prepare(
    "SELECT * FROM bulk_batch_files WHERE batch_id = ? AND status <> '" . BULK_F_SKIPPED . "' ORDER BY seq"
);
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$approved = 0;
$skipped  = 0;

foreach ($rows as $r) {
    $seq = (int)$r['seq'];
    $o   = $ovr[$seq] ?? [];

    $include = array_key_exists('include', $o) ? (bool)$o['include'] : ($r['status'] !== BULK_F_ERROR);
    if (!$include) {
        bulk_update_file($conn, (int)$r['id'], ['status' => BULK_F_SKIPPED]);
        $skipped++;
        continue;
    }

    $period = $o['posting_period'] ?? $r['posting_period'];
    if (!in_array($period, ['P1', 'P2', 'P3'], true)) {
        bulk_error('#' . $seq . ' の期区分が未指定です。確認画面で指定してください。', 400);
    }

    // 既に案件が作られていれば作り直さない（再承認時の二重作成を防ぐ）
    if ($r['ai_case_id'] !== null) {
        bulk_update_file($conn, (int)$r['id'], ['status' => BULK_F_APPROVED, 'posting_period' => $period]);
        $approved++;
        continue;
    }

    // ── ai_case を作る ──
    // upload_file_keys は 1案件=1ファイルのため期区分に対応する1つだけ。
    $fileKey = ($period === 'P2') ? 'currentAndPreviousBalanceSheet'
             : (($period === 'P3') ? 'doublePreviousAndTwoAgoBalanceSheet'
             : 'currentTermBalanceSheet');

    $caseName = bulk_case_name($batch_id, $seq, $r['original_name']);
    $status   = 'UPST';   // 既存フローと同じ「アップロード済み・変換待ち」

    $stmt = $conn->prepare(
        "INSERT INTO ai_case
           (user_id, update_user_id, create_at, update_at, status, case_name,
            upload_file_name, upload_file_keys, full_file_Paths)
         VALUES (?, ?, now(), now(), ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisssss', $user_id, $user_id, $status, $caseName,
                      $r['original_name'], $fileKey, $r['gcs_path']);
    $stmt->execute();
    $aiCaseId = (int)$conn->insert_id;
    $stmt->close();

    // ── GCS 上で <案件ID>-<キー>.pdf へ複製する ──
    // pdf-converter がファイル名から ai_case_id を抽出する仕様のため
    // （^(\d+)[-_] に一致しないと 500 になる）、受付時の
    // bulk/<port>/<batch>/<seq>.pdf のままでは変換できない。
    try {
        $dstObject = $aiCaseId . '-' . $fileKey . '.pdf';
        $newUri = bulk_copy_gcs($r['gcs_path'], $dstObject, $GLOBALS['gcs_bucket'] ?? 'analygent');
        $stmt = $conn->prepare("UPDATE ai_case SET full_file_Paths = ?, update_at = now() WHERE ai_case_id = ?");
        $stmt->bind_param('si', $newUri, $aiCaseId);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        bulk_log("APPROVE COPY_NG batch_id={$batch_id} seq={$seq} case={$aiCaseId} " . $e->getMessage());
        bulk_update_file($conn, (int)$r['id'], [
            'status'        => BULK_F_ERROR,
            'error_message' => '変換用ファイルの準備に失敗しました',
        ]);
        continue;
    }

    bulk_update_file($conn, (int)$r['id'], [
        'status'         => BULK_F_APPROVED,
        'posting_period' => $period,
        'ai_case_id'     => $aiCaseId,
    ]);
    $approved++;
}

if ($approved === 0) {
    bulk_error('承認されたファイルがありません。', 409);
}

bulk_refresh_counts($conn, $batch_id);
bulk_update_batch($conn, $batch_id, [
    'status'  => BULK_ST_APPROVED,
    'message' => '承認されました。解析の順番を待っています。',
]);
bulk_log("APPROVE batch_id={$batch_id} approved={$approved} skipped={$skipped}");

bulk_json([
    'status'       => 'success',
    'batch_id'     => $batch_id,
    'approved'     => $approved,
    'skipped'      => $skipped,
    'batch_status' => BULK_ST_APPROVED,
    'message'      => '承認しました。解析の進捗は bulk_upload_status.php で取得してください。',
]);
