<?php
/**
 * analygent_login_start.php
 *
 * 目的:
 * - company_cd + email + password を検証
 * - OTP(6桁)をメール送信
 * - challenge_id を返す
 *
 * 前提（ユーザー要件）:
 * - companies テーブルは存在しない
 * - users テーブルに company_id, company_cd がある
 * - users の主キーは id ではなく user_id
 * - users のメール列は mail ではなく email
 * - login_otp_challenges テーブル構造はユーザー提示の通り
 */

require_once './common.php';
require_once './system_log_helper.php';
require_once './ana_password_helper.php';
require './vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=UTF-8');

function respond($status, $message, $extra = []) {
    http_response_code(200);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
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

function gen_challenge_id(): string {
    return 'ch_' . bin2hex(random_bytes(16));
}

function gen_otp_code(): string {
    $n = random_int(0, 999999);
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}

function now_dt(mysqli $conn): string {
    // DBのタイムゾーンに合わせたい場合は SELECT NOW() を使う
    $rs = $conn->query("SELECT NOW() AS now_dt");
    $row = $rs ? $rs->fetch_assoc() : null;
    return $row && isset($row['now_dt']) ? $row['now_dt'] : date('Y-m-d H:i:s');
}

function send_login_otp_mail(string $email, string $name, string $companyCd, string $otpCode, int $expiresSeconds): bool {
    global $mail_smtp_user, $mail_smtp_password, $www_dir;

    $template_path = $www_dir . "/sapis/mailtemplate_login";
    if (!file_exists($template_path)) {
        error_log("メールテンプレートが存在しません: $template_path");
        return false;
    }

    $body = file_get_contents($template_path);
    if ($body === false) {
        error_log("メールテンプレートの読み込みに失敗: $template_path");
        return false;
    }

    $expiresMinutes = (int)ceil($expiresSeconds / 60);

    // テンプレート置換（テンプレート側に無ければそのまま残るだけなので安全）
    $body = str_replace('${name}', $name, $body);
    $body = str_replace('${otp_code}', $otpCode, $body);
    $body = str_replace('${expires_minutes}', (string)$expiresMinutes, $body);
    $body = str_replace('${company_cd}', $companyCd, $body);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->Port = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->SMTPAuth = true;
        $mail->Username = $mail_smtp_user;
        $mail->Password = $mail_smtp_password;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom('no-reply@corp.analygent.com', 'Analygent');
        $mail->addReplyTo('no-reply@corp.analygent.com', 'Analygent');
        $mail->Sender = 'no-reply@corp.analygent.com';

        $mail->addAddress($email, $name);
        $mail->Subject = "【Analygent】ログイン用ワンタイムパスワード(OTP)";
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond('error', 'POSTで呼び出してください', ['error_code' => 'METHOD_NOT_ALLOWED']);
}

$data = get_request_data();

$company_cd = trim((string)($data['company_cd'] ?? ''));
$email      = trim((string)($data['email'] ?? ''));
$password   = (string)($data['password'] ?? '');

if ($company_cd === '' || $email === '' || $password === '') {
    respond('error', 'company_cd, email, password は必須です', ['error_code' => 'VALIDATION_ERROR']);
}

if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $company_cd)) {
    respond('error', 'company_cd が不正です', ['error_code' => 'INVALID_COMPANY']);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond('error', '無効なメールアドレスです', ['error_code' => 'INVALID_EMAIL']);
}

global $conn;

// IP/UA
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 1) ユーザー認証（users.company_cd で会社を特定）
// パスワードは SQL の条件にせず、取得した保存値と PHP で照合する。
// 保存値がハッシュなら password_verify、ハッシュ化以前の平文なら比較して一致時にハッシュへ置き換える。
$user = null;
try {
    $stmt = $conn->prepare(
        "SELECT u.user_id, u.username, u.password, u.email, u.company_id, u.company_cd,
                u.otp_enabled, u.auth, u.bumon_id, u.must_change_password,
                COALESCE(cu.otp_required, 0) AS otp_required,
                COALESCE(cu.billing_status, u.billing_status) AS billing_status
           FROM users u
           LEFT JOIN users cu ON cu.user_id = u.company_id
          WHERE u.company_cd = ?
            AND u.email      = ?
            AND u.status > 0
            AND u.type = 'Z'
          ORDER BY u.user_id"
    );
    $stmt->bind_param('ss', $company_cd, $email);
    $stmt->execute();
    $rs = $stmt->get_result();
    while ($row = $rs->fetch_assoc()) {
        if (ana_pw_verify_and_upgrade($conn, (int)$row['user_id'], $password, $row['password'] ?? null)) {
            $user = $row;
            break;
        }
    }
    $stmt->close();
} catch (Throwable $e) {
    respond('error', 'ログイン処理でエラーが発生しました', ['error_code' => 'SERVER_ERROR']);
}

if (!$user) {
    respond('error', 'メールアドレスまたはパスワードが正しくありません', ['error_code' => 'INVALID_LOGIN']);
}
unset($user['password']);

$user_id              = (int)$user['user_id'];
$company_id           = (int)$user['company_id'];
$username             = (string)($user['username'] ?? '');
$otp_enabled          = (int)($user['otp_enabled'] ?? 1); // デフォルトON
$otp_required         = (int)($user['otp_required'] ?? 0); // テナントポリシー
$auth                 = (int)($user['auth'] ?? 100);
$bumon_id             = $user['bumon_id'] ?? null;
// 列の値は 'YES' / 'NO'。(int) すると常に 0 になるため、login_verify と同じく真偽値で返す
$must_change_password = ((string)($user['must_change_password'] ?? 'NO')) === 'YES';
$billing_status       = (string)($user['billing_status'] ?? 'active');
// is_platform_admin: company_id=0 かつ auth>=200 のユーザのみ true
$is_platform_admin    = ($company_id === 0 && $auth >= 200);

// OTPをスキップして直接loginkey発行できる条件:
// ユーザー個別設定がOFF かつ テナントポリシーも強制なし
if ($otp_enabled === 0 && $otp_required === 0) {
    // company_name を取得（verify.php と同じロジック）
    $company_name = '';
    $stmtCo = $conn->prepare("SELECT company_name FROM users WHERE user_id = ? LIMIT 1");
    if ($stmtCo) {
        $stmtCo->bind_param("i", $company_id);
        $stmtCo->execute();
        $rCo = $stmtCo->get_result()->fetch_assoc();
        $stmtCo->close();
        $company_name = (string)($rCo['company_name'] ?? '');
    }

    // loginkey 発行
    $loginkey   = bin2hex(random_bytes(32));
    $param_json = json_encode(['ip' => $ip, 'user_agent' => $user_agent], JSON_UNESCAPED_UNICODE);

    $stmtIns = $conn->prepare(
        "INSERT INTO login_history (user_id, loginkey, create_at, status, param) VALUES (?, ?, NOW(), 'OK', ?)"
    );
    if (!$stmtIns) {
        respond('error', 'ログイン処理でエラーが発生しました', ['error_code' => 'SERVER_ERROR']);
    }
    $stmtIns->bind_param("iss", $user_id, $loginkey, $param_json);
    $stmtIns->execute();
    $stmtIns->close();

    respond('success', 'ログインしました', [
        'otp_required'        => false,
        'loginkey'            => $loginkey,
        'user_id'             => $user_id,
        'username'            => $username,
        'email'               => $email,
        'company_id'          => $company_id,
        'company_cd'          => $company_cd,
        'company_name'        => $company_name,
        'auth'                => $auth,
        'bumon_id'            => $bumon_id,
        'must_change_password'=> $must_change_password,
        'billing_status'      => $billing_status,
        'is_platform_admin'   => $is_platform_admin,
    ]);
}

// 2) OTPチャレンジ取得（未検証＆期限内の最新）
$challenge = null;
try {
    $stmt = $conn->prepare(
        "SELECT *
           FROM login_otp_challenges
          WHERE user_id    = ?
            AND company_id = ?
            AND verified_at IS NULL
            AND expires_at  > NOW()
          ORDER BY updated_at DESC
          LIMIT 1"
    );
    $stmt->bind_param('ii', $user_id, $company_id);
    $stmt->execute();
    $challenge = $stmt->get_result()->fetch_assoc();
} catch (Throwable $e) {
    respond('error', 'OTPチャレンジの取得に失敗しました', ['error_code' => 'SERVER_ERROR']);
}

$otp_ttl_seconds = 10 * 60; // 10分
$cooldown_seconds = 60;     // 1分
$max_per_10min = 5;

$now = now_dt($conn);
$now_ts = strtotime($now);

// 既存challengeがあれば再利用、なければ新規
$challenge_id = $challenge['challenge_id'] ?? gen_challenge_id();
$last_sent_at = $challenge['last_sent_at'] ?? null;
$sent_count_10min = isset($challenge['sent_count_10min']) ? (int)$challenge['sent_count_10min'] : 0;

// 10分カウントは last_sent_at が 10分以上前ならリセット
if ($last_sent_at) {
    $last_ts = strtotime($last_sent_at);
    if ($last_ts !== false && ($now_ts - $last_ts) >= (10 * 60)) {
        $sent_count_10min = 0;
    }
}

// 1分クールダウン
if ($last_sent_at) {
    $last_ts = strtotime($last_sent_at);
    if ($last_ts !== false) {
        $elapsed = $now_ts - $last_ts;
        if ($elapsed < $cooldown_seconds) {
            respond('error', 'OTP送信は1分に1回までです。少し待ってから再度お試しください。', [
                'error_code' => 'OTP_TOO_FREQUENT',
                'retry_after' => $cooldown_seconds - $elapsed,
                'otp_required' => true,
                'challenge_id' => $challenge_id,
            ]);
        }
    }
}

// 10分で最大5回
if ($sent_count_10min >= $max_per_10min) {
    // last_sent_at を起点に 10分経つまで待つ（厳密ではないがテーブル構造上これが最も自然）
    $retry_after = 10 * 60;
    if ($last_sent_at) {
        $last_ts = strtotime($last_sent_at);
        if ($last_ts !== false) {
            $retry_after = max(0, ($last_ts + 10*60) - $now_ts);
        }
    }
    respond('error', 'OTP送信回数が上限に達しました。時間をおいて再度お試しください。', [
        'error_code' => 'OTP_RATE_LIMITED',
        'retry_after' => $retry_after,
        'otp_required' => true,
        'challenge_id' => $challenge_id,
    ]);
}

// 3) OTP生成・メール送信
$otp_code = gen_otp_code();
$otp_hash = password_hash($otp_code, PASSWORD_DEFAULT);

$expires_at = date('Y-m-d H:i:s', $now_ts + $otp_ttl_seconds);

$mail_ok = send_login_otp_mail($email, $username, $company_cd, $otp_code, $otp_ttl_seconds);
if (!$mail_ok) {
    write_system_log($conn, 'error', 'AUTH', 'OTP_SEND_FAILED', 'OTPメールの送信に失敗しました',
        tenant_id:  $company_id !== 0 ? $company_id : null,
        user_id:    $user_id,
        username:   $username ?: $email,
        ip_address: $ip ?? null,
        detail:     ['email' => substr($email, 0, 3) . '***']
    );
    respond('error', 'OTPメールの送信に失敗しました', ['error_code' => 'OTP_MAIL_FAILED']);
}

// 4) login_otp_challenges 保存（UPDATE or INSERT）
try {
    if ($challenge) {
        $new_sent_count = $sent_count_10min + 1;
        $stmt = $conn->prepare(
            "UPDATE login_otp_challenges
                SET otp_hash        = ?,
                    status          = 0,
                    fail_count      = 0,
                    sent_count_10min= ?,
                    last_sent_at    = NOW(),
                    expires_at      = ?,
                    client_ip       = ?,
                    user_agent      = ?,
                    updated_at      = NOW()
              WHERE challenge_id    = ?"
        );
        $stmt->bind_param('sissss', $otp_hash, $new_sent_count, $expires_at, $ip, $user_agent, $challenge_id);
        $stmt->execute();
    } else {
        $new_sent_count = 1;
        $stmt = $conn->prepare(
            "INSERT INTO login_otp_challenges
                (challenge_id, company_id, user_id, otp_hash, status, fail_count, sent_count_10min, last_sent_at, expires_at, verified_at, client_ip, user_agent, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, 0, 0, ?, NOW(), ?, NULL, ?, ?, NOW(), NOW())"
        );
        $stmt->bind_param('siisisss', $challenge_id, $company_id, $user_id, $otp_hash, $new_sent_count, $expires_at, $ip, $user_agent);
        $stmt->execute();
    }
} catch (Throwable $e) {
    respond('error', 'OTPチャレンジの保存に失敗しました', ['error_code' => 'SERVER_ERROR']);
}

write_system_log($conn, 'info', 'AUTH', 'OTP_SENT', 'OTPを送信しました',
    tenant_id:  $company_id !== 0 ? $company_id : null,
    user_id:    $user_id,
    username:   $username ?: $email,
    ip_address: $ip ?? null,
    detail:     ['email' => substr($email, 0, 3) . '***']
);

respond('success', 'OTPをメールに送信しました', [
    'otp_required'        => true,
    'challenge_id'        => $challenge_id,
    'otp_expires_in'      => $otp_ttl_seconds,
    'resend_available_in' => $cooldown_seconds,
    'company_cd'          => $company_cd,
    'is_platform_admin'   => $is_platform_admin,
]);
