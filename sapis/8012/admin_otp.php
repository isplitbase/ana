<?php
/**
 * admin_otp.php
 * 管理者によるOTP一括ON/OFF API
 *
 * POST パラメータ:
 *   loginkey : ログイン中のキー
 *   action   : "on" | "off"
 *
 * 権限: users.auth > 100 (Admin=200 or MasterAdmin=300)
 */

require_once './common.php';

header('Content-Type: application/json; charset=utf-8');

function respond($status, $message, $extra = []) {
    http_response_code(200);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// POST のみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond('error', 'POSTで呼び出してください', ['error_code' => 'METHOD_NOT_ALLOWED']);
}

// パラメータ取得
$body = json_decode(file_get_contents('php://input'), true);
$loginkey = trim((string)($body['loginkey'] ?? $_POST['loginkey'] ?? ''));
$action   = trim((string)($body['action']   ?? $_POST['action']   ?? ''));

if ($loginkey === '' || $action === '') {
    respond('error', 'loginkey と action は必須です', ['error_code' => 'MISSING_PARAMS']);
}

if (!in_array($action, ['on', 'off'], true)) {
    respond('error', 'action は "on" か "off" を指定してください', ['error_code' => 'INVALID_ACTION']);
}

// ログイン状態チェック
$user_id = get_user_id_for_now($loginkey);
if (!$user_id) {
    respond('error', 'ログインセッションが無効です', ['error_code' => 'UNAUTHORIZED']);
}

// ユーザー情報取得（auth・company_id）
$stmt = $conn->prepare("SELECT auth, company_id FROM users WHERE user_id = ? AND status > 0 AND status < 999 LIMIT 1");
if (!$stmt) {
    respond('error', 'DBエラーが発生しました', ['error_code' => 'DB_ERROR']);
}
$stmt->bind_param("i", $user_id);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    respond('error', 'ユーザーが見つかりません', ['error_code' => 'USER_NOT_FOUND']);
}

// 権限チェック（auth > 100）
$auth       = (int)$me['auth'];
$company_id = (int)$me['company_id'];

if ($auth <= 100) {
    respond('error', '管理者権限が必要です', ['error_code' => 'FORBIDDEN']);
}

// テナントポリシー(otp_required)を会社代表レコード(user_id=company_id)に更新
$otp_value    = ($action === 'on') ? 1 : 0;
$otp_required = $otp_value; // action=on → otp_required=1、action=off → otp_required=0

$stmtPolicy = $conn->prepare("
    UPDATE users
        SET otp_required = ?, update_at = NOW(), update_user_id = ?
    WHERE user_id = ?
    LIMIT 1
");
if (!$stmtPolicy) {
    respond('error', 'DBエラーが発生しました', ['error_code' => 'DB_ERROR']);
}
$stmtPolicy->bind_param("iii", $otp_required, $user_id, $company_id);
$stmtPolicy->execute();
$stmtPolicy->close();

// 同じ会社の全ユーザーのotp_enabledを一括更新
// action=on  → 全員を強制ON
// action=off → 全員をOFF（個別設定に戻す場合は各ユーザーがchange_otp.phpで変更）
$stmtUpd = $conn->prepare("
    UPDATE users
        SET otp_enabled = ?, update_at = NOW(), update_user_id = ?
    WHERE company_id = ?
      AND status > 0 AND status < 999
");
if (!$stmtUpd) {
    respond('error', 'DBエラーが発生しました', ['error_code' => 'DB_ERROR']);
}
$stmtUpd->bind_param("iii", $otp_value, $user_id, $company_id);
$stmtUpd->execute();
$affected = $stmtUpd->affected_rows;
$stmtUpd->close();

respond('success', 'OTP設定を更新しました', [
    'action'         => $action,
    'otp_required'   => $otp_required,
    'otp_enabled'    => $otp_value,
    'company_id'     => $company_id,
    'affected_users' => $affected,
]);
