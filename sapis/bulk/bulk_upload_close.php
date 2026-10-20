<?php
/**
 * bulk_upload_close.php
 * ─────────────────────────────────────────────────────────────────────────────
 * ファイル送信を締め切り、ワーカーへ処理を渡す（status を QUEUED にするだけ）。
 *
 * 受信 (JSON または form):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *
 * 返却:
 *   { status:"success", batch_id, total_files, batch_status:"QUEUED" }
 *
 * 備考:
 *   重い処理はここでは行わない。cron で毎分起動する bulk_worker.php が
 *   QUEUED のバッチを拾って期判定を行う。画面は bulk_upload_status.php を
 *   5秒間隔で呼んで進捗を表示する。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$body = bulk_read_request();   // POST 専用（GET は 405）

$loginkey = $body['loginkey'] ?? null;
$batch_id = $body['batch_id'] ?? null;

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
if ($batch['status'] !== BULK_ST_OPEN) {
    bulk_error('このバッチは既に受付を終了しています（状態: ' . $batch['status'] . '）。', 409);
}

$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM bulk_batch_files WHERE batch_id = ?");
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['cnt'];
$stmt->close();

if ($total === 0) {
    bulk_error('ファイルが1本も登録されていません。', 409);
}

bulk_update_batch($conn, $batch_id, [
    'status'  => BULK_ST_QUEUED,
    'message' => '処理の順番を待っています。',
]);
bulk_refresh_counts($conn, $batch_id);
bulk_log("CLOSE batch_id={$batch_id} total={$total} mode={$batch['mode']}");

$noReview = ($batch['mode'] === BULK_MODE_DIRECT);
if ($noReview) {
    // 承認せず：すぐ登録を始める（cron の次の起動を待たない）
    bulk_kick_worker();
}

bulk_json([
    'status'       => 'success',
    'batch_id'     => $batch_id,
    'total_files'  => $total,
    'batch_status' => BULK_ST_QUEUED,
    'no_review'    => $noReview,
    'message'      => $noReview
        ? '受付を締め切りました。サーバで1件ずつ案件を登録します（案件一覧に順に「帳票識別中」で出ます）。'
          . '失敗したファイルは bulk_upload_status.php（detail: true） または bulk_upload_failures.php で取得できます。'
        : '受付を締め切りました。処理の進捗は bulk_upload_status.php で取得してください。',
]);
