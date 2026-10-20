<?php
/**
 * bulk_upload_init.php
 * ─────────────────────────────────────────────────────────────────────────────
 * まとめてアップロードのバッチを開始し、batch_id を発行する。
 *
 * 受信 (application/json または form):
 *   loginkey  : string  必須
 *   no_review : bool    省略可。true なら「承認せず」。確認画面を通さず、
 *                       通常画面のアップロードと同じ流れで1ファイル1案件として登録する
 *                       （案件は「帳票識別中」になり、以後は通常の流れ）
 *
 * 返却:
 *   { status:"success", batch_id:"251015-03", no_review:false,
 *     files_per_request:3, max_files:100 }
 *
 * 備考:
 *   ファイルはこのAPIでは受け取らない。batch_id を得たあと
 *   bulk_upload_add.php へ 3本ずつ送る。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$body = bulk_read_request();   // POST 専用（GET は 405）

$loginkey = $body['loginkey'] ?? null;
// 承認せず（D）か、承認あり（B）か。form で送られた "1" / "true" も受け付ける。
$noReview = filter_var($body['no_review'] ?? false, FILTER_VALIDATE_BOOLEAN);
$mode     = $noReview ? BULK_MODE_DIRECT : BULK_MODE_FILE;

// ── 認証（既存 sapis と同じ作法）──
$user_id = null;
if ($damy_flag !== "OK") {
    if (empty($loginkey)) {
        bulk_error('loginkey が指定されていません。', 401);
    }
    $user_id = get_user_id_for_now($loginkey);
    if ($user_id === null) {
        bulk_error('異常アクセス@' . $loginkey, 401);
    }
}

// ── 所属会社を取得（一覧の絞り込みと権限判定に使う）──
$company_id = 0;
if ($user_id !== null) {
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $company_id = (int)($row['company_id'] ?? 0);
}

// ── バッチ作成 ──
$batch_id = bulk_new_batch_id($conn);
$status   = BULK_ST_OPEN;

$stmt = $conn->prepare(
    "INSERT INTO bulk_batches
       (batch_id, user_id, company_id, mode, status, create_at, update_at)
     VALUES (?, ?, ?, ?, ?, now(), now())"
);
$stmt->bind_param('siiss', $batch_id, $user_id, $company_id, $mode, $status);
$stmt->execute();
$stmt->close();

bulk_log("INIT batch_id={$batch_id} user_id={$user_id} company_id={$company_id} mode={$mode}");

bulk_json([
    'status'            => 'success',
    'batch_id'          => $batch_id,
    'no_review'         => $noReview,
    'files_per_request' => BULK_FILES_PER_REQUEST,
    'max_files'         => BULK_MAX_FILES,
    'max_file_size'     => BULK_MAX_FILE_SIZE,
    'message'           => 'バッチを開始しました。bulk_upload_add.php へ ' . BULK_FILES_PER_REQUEST . ' 本ずつ送信してください。',
]);
