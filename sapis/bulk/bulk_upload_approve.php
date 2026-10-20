<?php
/**
 * bulk_upload_approve.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 確認画面での承認を受け取り、ai_case を作成して解析待ちにする。
 *
 * 受信 (JSON):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *   files    : [ { seq:1, include:true, posting_period:"P2", end_date:"2025-03-31" }, ... ]
 *              省略した seq は判定結果のまま採用する。
 *              include=false の行は SKIPPED にして案件を作らない。
 *              end_date は担当者が確認した決算日（今期）。省略時は期判定AIの値。
 *              期判定AIの値から変更されていれば、読取後に案件の決算日をこの値にする。
 *              変更されていなければ、読取AIが書類から読んだ決算日をそのまま使い、
 *              期判定AIの値と照合する（bulk_analyze.php の 4.5 / 7 を参照）。
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

$body = bulk_read_request();   // POST 専用（GET は 405）

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
if ($batch['mode'] === BULK_MODE_DIRECT) {
    bulk_error('承認せずのバッチは承認の対象ではありません（受付後に自動で案件を登録します）。', 409);
}
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

    // 担当者が確認した決算日。期判定AIの値から変わっていれば、読取後に案件へ反映する。
    // 送られてこなければ期判定AIの値をそのまま採用する（＝変更なし）。
    if (array_key_exists('end_date', $o)) {
        $raw = trim((string)$o['end_date']);
        $endDate = bulk_parse_date($raw);
        if ($raw !== '' && $endDate === null) {
            bulk_error('#' . $seq . ' の決算日「' . $raw . '」を日付として読めません（例: 2025-03-31）。', 400);
        }
    } else {
        $endDate = $r['detected_end_date'];
    }

    // 既に案件が作られていれば作り直さない（再承認時の二重作成を防ぐ）
    if ($r['ai_case_id'] !== null) {
        bulk_update_file($conn, (int)$r['id'], ['status' => BULK_F_APPROVED, 'posting_period' => $period,
                                                'confirmed_end_date' => $endDate]);
        $approved++;
        continue;
    }

    // ── ai_case を作る ──
    // 【1案件=1ファイル前提】1行（1ファイル）ごとに案件を1件作っている。
    // 複数ファイルを1案件にする場合は、ここを case_group ごとのループに変え、
    // 同じ組のファイルをまとめて bulk_create_case() に渡す（README 参照）。
    //
    // 【1案件=1ファイル前提】帳票キーを期区分から1つだけ決めている。
    // 複数ファイルの場合は、ファイルごとに「どの期を含むか」から決める必要がある
    // （例: 今期+前期 → currentAndPreviousBalanceSheet、
    //       前期+前々期 → previousAndTwoAgoBalanceSheet）。
    $fileKey = ($period === 'P2') ? 'currentAndPreviousBalanceSheet'
             : (($period === 'P3') ? 'doublePreviousAndTwoAgoBalanceSheet'
             : 'currentTermBalanceSheet');

    $caseName = bulk_case_name($batch_id, $seq, $r['original_name']);
    try {
        $aiCaseId = bulk_create_case($conn, $user_id, $caseName, [[
            'original_name' => $r['original_name'],
            'gcs_path'      => $r['gcs_path'],
            'file_key'      => $fileKey,
        ]]);
    } catch (Throwable $e) {
        bulk_log("APPROVE COPY_NG batch_id={$batch_id} seq={$seq} " . $e->getMessage());
        bulk_update_file($conn, (int)$r['id'], [
            'status'        => BULK_F_ERROR,
            'error_message' => '変換用ファイルの準備に失敗しました',
        ]);
        continue;
    }

    bulk_update_file($conn, (int)$r['id'], [
        'status'             => BULK_F_APPROVED,
        'posting_period'     => $period,
        'confirmed_end_date' => $endDate,
        'ai_case_id'         => $aiCaseId,
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
