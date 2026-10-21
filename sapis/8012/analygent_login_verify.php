<?php
/**
 * analygent_login_verify.php (users-only / login_otp_challenges構造準拠)
 *
 * 前提:
 * - companies テーブルなし
 * - users: user_id, email, company_id, company_cd, (company_nameは初期ユーザのみ)
 * - login_otp_challenges:
 *   id, challenge_id, company_id, user_id, otp_hash, status, fail_count, sent_count_10min,
 *   last_sent_at, expires_at, verified_at, client_ip, user_agent, created_at, updated_at
 */

require_once './common.php';
require_once './system_log_helper.php';

header('Content-Type: application/json; charset=UTF-8');

function respond($status, $message, $extra = []) {
    http_response_code(200);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// auth 数値をロール名に変換
function auth_to_role($auth) {
    $a = (int)$auth;
    switch ($a) {
        case 300: return 'Admin';
        case 200: return 'MasterAdmin';
        case 100: return 'User';
        default:  return 'Unknown';
    }
}

function get_request_data() {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
    return $_POST;
}

function gen_loginkey(): string {
    return bin2hex(random_bytes(32));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond('error', 'POSTで呼び出してください', ['error_code' => 'METHOD_NOT_ALLOWED']);
}

$data = get_request_data();

$challenge_id = trim((string)($data['challenge_id'] ?? ''));
$otp_code     = trim((string)($data['otp_code'] ?? ''));
$email        = trim((string)($data['email'] ?? '')); // 保持されている場合に使う可能性があるため

if ($challenge_id === '' || $otp_code === '') {
    respond('error', 'パラメータ不足です', ['error_code' => 'MISSING_PARAMS']);
}

$now = date('Y-m-d H:i:s');

// 1) チャレンジ取得（失効/状態/回数等のチェックは既存処理維持）
$stmt = $conn->prepare(
    "SELECT id, challenge_id, company_id, user_id, otp_hash, status, fail_count, expires_at
       FROM login_otp_challenges
      WHERE challenge_id = ?
      LIMIT 1"
);
$stmt->bind_param('s', $challenge_id);
$stmt->execute();
$res = $stmt->get_result();
$ch = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$ch) {
    respond('error', '認証に失敗しました（チャレンジが存在しません）', ['error_code' => 'CHALLENGE_NOT_FOUND']);
}

$challenge_db_id = (int)$ch['id'];
$company_id      = (int)$ch['company_id'];
$user_id         = (int)$ch['user_id'];
$status          = (int)$ch['status'];
$fail_count      = (int)$ch['fail_count'];
$expires_at      = (string)$ch['expires_at'];

if ($status !== 0) {
    respond('error', '認証に失敗しました（状態が不正です） '.$status, ['error_code' => 'INVALID_STATUS']);
}

if ($expires_at !== '' && $expires_at < $now) {
    respond('error', '認証に失敗しました（有効期限切れです）', ['error_code' => 'EXPIRED']);
}

// 2) OTP検証（既存処理維持）
$otp_ok = password_verify($otp_code, (string)$ch['otp_hash']);
if (!$otp_ok) {
    // fail_count +1 など既存処理維持
    $stmt = $conn->prepare(
        "UPDATE login_otp_challenges
            SET fail_count = fail_count + 1,
                updated_at = NOW()
          WHERE id = ?"
    );
    $stmt->bind_param('i', $challenge_db_id);
    $stmt->execute();
    $new_fail_count = $fail_count + 1;
    $stmt->close();

    // LOGIN_FAILED_LIMIT: 3回以上失敗
    $log_action = $new_fail_count >= 3 ? 'LOGIN_FAILED_LIMIT' : 'OTP_FAILED';
    $log_level  = $new_fail_count >= 3 ? 'warn' : 'warn';
    $log_msg    = $new_fail_count >= 3
        ? "ログイン連続失敗が{$new_fail_count}回発生しました"
        : 'OTP認証に失敗しました';
    write_system_log($conn, $log_level, 'AUTH', $log_action, $log_msg,
        tenant_id:  $company_id ?: null,
        user_id:    $user_id ?: null,
        ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
        detail:     ['fail_count' => $new_fail_count, 'challenge_id' => $challenge_id]
    );

    respond('error', '認証に失敗しました（OTPが不正です）', ['error_code' => 'INVALID_OTP']);
}

// 3) 認証成功（既存処理維持）
$stmt = $conn->prepare(
    "UPDATE login_otp_challenges
        SET status = 1,
            verified_at = NOW(),
            updated_at = NOW()
      WHERE id = ?"
);
$stmt->bind_param('i', $challenge_db_id);
$stmt->execute();
$stmt->close();

// 4) users 取得
// ・status=999（強制停止中）のユーザーもログインは通す
//   → フロントは billing_status:'suspended' を見てバナー表示
//   → 業務系APIは common.php の billing_status チェックで 402 を返す
$stmt = $conn->prepare(
    "SELECT user_id, username, email, company_id, company_cd, auth, bumon_id, must_change_password, billing_status
       FROM users
      WHERE user_id = ?
        AND status > 0
      LIMIT 1"
);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$user = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$user) {
    respond('error', 'ユーザーが存在しないか、ログイン条件を満たしていません', ['error_code' => 'INVALID_LOGIN']);
}

$username       = (string)($user['username'] ?? '');
$email          = (string)($user['email'] ?? '');
$company_cd     = (string)($user['company_cd'] ?? '');
$auth           = (int)($user['auth'] ?? 0);
$bumon_id       = isset($user['bumon_id']) && $user['bumon_id'] !== '' ? (int)$user['bumon_id'] : null;
$must_change_password = ((string)($user['must_change_password'] ?? 'NO')) === 'YES';

// billing_status は企業マスター（user_id = company_id）の値を使う
// 普通ユーザー（user_id != company_id）は自身の billing_status を参照しない
if ($company_id !== 0 && $user_id !== $company_id) {
    $stmt = $conn->prepare("SELECT billing_status FROM users WHERE user_id = ? AND company_id = ? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ii', $company_id, $company_id);
        $stmt->execute();
        $master = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $billing_status = (string)($master['billing_status'] ?? 'active');
    } else {
        $billing_status = 'active';
    }
} else {
    $billing_status = (string)($user['billing_status'] ?? 'active');
}

// is_platform_admin: company_id=0 かつ auth>=200 のユーザのみ true
$is_platform_admin = ($company_id === 0 && $auth >= 200);
// 5) company_name を初期ユーザ（user_id = company_id）のレコードから取得（既存処理維持）
$company_name = null;
try {
    $stmt = $conn->prepare(
        "SELECT company_name
           FROM users
          WHERE user_id = ?
          LIMIT 1"
    );
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($row = $res->fetch_assoc())) {
        $company_name = $row['company_name'] ?? null;
    }
    $stmt->close();
} catch (Exception $e) {
    $company_name = null;
}

// 6) loginkey発行（既存処理維持）
$loginkey = gen_loginkey();
// IPとユーザーエージェント
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$param_json = json_encode([
    'ip' => $ip,
    'user_agent' => $user_agent
], JSON_UNESCAPED_UNICODE);
// login_history に記録（param付き）
$stmt_insert = $conn->prepare("
	INSERT INTO login_history (user_id, loginkey, create_at, status, param)
	VALUES (?, ?, NOW(), 'OK', ?)
");
$stmt_insert->bind_param("iss", $user_id, $loginkey, $param_json);
$stmt_insert->execute();
$stmt_insert->close();


// 7) LOGIN_SUCCESS ログ
write_system_log($conn, 'info', 'AUTH', 'LOGIN_SUCCESS', 'ログインしました',
    tenant_id:   $company_id !== 0 ? $company_id : null,
    tenant_name: $company_name,
    user_id:     $user_id,
    username:    $username ?: $email,
    ip_address:  $ip
);

// 8) 出力（要求どおり company_id/company_cd/company_name を含める）
respond('success', 'ログインしました', [
    'loginkey'             => $loginkey,
    'user_id'              => $user_id,
    'username'             => $username,
    'email'                => $email,
    'company_id'           => $company_id,
    'company_cd'           => $company_cd,
    'company_name'         => $company_name,
    'auth'                 => $auth,
    'bumon_id'             => $bumon_id,
    'must_change_password' => $must_change_password,
    'billing_status'       => $billing_status,
    'is_platform_admin'    => $is_platform_admin,
]);
