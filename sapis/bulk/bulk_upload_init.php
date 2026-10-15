<?php
/**
 * bulk_upload_init.php
 * ─────────────────────────────────────────────────────────────────────────────
 * まとめてアップロードのバッチを開始し、batch_id を発行する。
 *
 * 受信 (application/json または form):
 *   loginkey : string  必須
 *   mode     : "A" | "B"  確認画面の方式（A=ページ単位 / B=ファイル単位）
 *
 * 返却:
 *   { status:"success", batch_id:"251015-03", mode:"B",
 *     files_per_request:3, max_files:100 }
 *
 * 備考:
 *   ファイルはこのAPIでは受け取らない。batch_id を得たあと
 *   bulk_upload_add.php へ 3本ずつ送る。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { $body = $_POST; }

$loginkey = $body['loginkey'] ?? null;
$mode     = strtoupper((string)($body['mode'] ?? BULK_MODE_FILE));

if (!in_array($mode, [BULK_MODE_PAGE, BULK_MODE_FILE], true)) {
    bulk_error('mode は A（ページ単位で確認）か B（ファイル単位で確認）を指定してください。');
}

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
    'mode'              => $mode,
    'files_per_request' => BULK_FILES_PER_REQUEST,
    'max_files'         => BULK_MAX_FILES,
    'max_file_size'     => BULK_MAX_FILE_SIZE,
    'message'           => 'バッチを開始しました。bulk_upload_add.php へ ' . BULK_FILES_PER_REQUEST . ' 本ずつ送信してください。',
]);
