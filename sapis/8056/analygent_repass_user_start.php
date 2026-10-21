<?php
require_once './common.php';
require_once './ana_password_helper.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './vendor/autoload.php';

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

function gen_key(): string {
    return bin2hex(random_bytes(32)); // 64 hex
}

/**
 * /sapis/mailtemplate_repass を使ってパスワード変更メール送信
 * 置換:
 *  ${name}
 *  ${email}
 *  ${repass_link}
 *  ${company_cd}
 *  ${password_note}
 */
function send_repass_mail_by_template_local($email, $name, $check_code, $company_cd, $password_note = '',$must_change_password) {
    global $mail_smtp_user, $mail_smtp_password, $www_dir;
	$repass_link = ana_front_url("auth/reset-password?key=" . urlencode($check_code));
	if($must_change_password=="YES"){
		$repass_link = ana_front_url("auth/first-password-change?key=" . urlencode($check_code));
	}

    $template_path = $www_dir . "/sapis/mailtemplate_repass";
    if (!file_exists($template_path)) {
        $fallback = $www_dir . "/sapis/mailtemplate";
        if (file_exists($fallback)) {
            $template_path = $fallback;
        }
    }

    if (!file_exists($template_path)) {
        error_log("メールテンプレートが存在しません: $template_path");
        return false;
    }

    $body = file_get_contents($template_path);
    if ($body === false) {
        error_log("メールテンプレートの読み込みに失敗しました: $template_path");
        return false;
    }

    $body = str_replace('${name}', (string)$name, $body);
    $body = str_replace('${email}', (string)$email, $body);
    $body = str_replace('${repass_link}', (string)$repass_link, $body);
    $body = str_replace('${company_cd}', (string)$company_cd, $body);
    $body = str_replace('${password_note}', (string)$password_note, $body);

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        $mail->SMTPAuth   = true;
        $mail->Username   = $mail_smtp_user;
        $mail->Password   = $mail_smtp_password;

        $mail->CharSet = 'UTF-8';

        $mail->setFrom('no-reply@corp.analygent.com', 'Analygent');
        $mail->addReplyTo('no-reply@corp.analygent.com', 'Analygent');
        $mail->Sender = 'no-reply@corp.analygent.com';

        $mail->addAddress($email, $name);
        $mail->Subject = "【Analygent】パスワード変更の確認";
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error (repass start login): " . $mail->ErrorInfo);
        return false;
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond('error', 'POSTで呼び出してください', ['error_code' => 'METHOD_NOT_ALLOWED']);
}

$data = get_request_data();
$loginkey = trim((string)($data['loginkey'] ?? ''));

if ($loginkey === '') {
    respond('error', 'パラメータ不足です', ['error_code' => 'MISSING_PARAMS']);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond('error', 'DB接続が初期化されていません（common.phpの$connを確認してください）', ['error_code' => 'DB_NOT_READY']);
}

$purpose = 'repass';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// loginkey → user
$stmt = $conn->prepare("
    SELECT u.user_id, u.username, u.email, u.company_id, u.company_cd,must_change_password
    FROM users u
    JOIN login_history l ON u.user_id = l.user_id
    WHERE l.loginkey = ?
      AND u.status > 0 AND u.status < 999 AND u.type='Z' AND l.status='OK'
    ORDER BY l.create_at DESC
    LIMIT 1
");
$stmt->bind_param("s", $loginkey);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    respond('error', '認証に失敗しました（loginkeyが無効です）', ['error_code' => 'INVALID_LOGINKEY']);
}

$user_id    = (int)$user['user_id'];
$username   = (string)$user['username'];
$email      = (string)$user['email'];
$company_id = (int)$user['company_id'];
$company_cd = (string)$user['company_cd'];
$must_change_password = (string)$user['must_change_password'];
if ($email === '') {
    respond('error', 'メールアドレスが未設定のため処理できません', ['error_code' => 'EMAIL_NOT_SET']);
}

$key_plain = gen_key();
$otp_hash  = password_hash($key_plain, PASSWORD_DEFAULT);
$expires_at = date('Y-m-d H:i:s', time() + 30 * 60);

try {
    $conn->begin_transaction();

    // 直近10分の送信回数
    $stmt = $conn->prepare("
        SELECT sent_count_10min, last_sent_at
        FROM other_otp_challenges
        WHERE user_id = ? AND company_id = ? AND purpose = ?
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->bind_param("iis", $user_id, $company_id, $purpose);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $sent_count_10min = 1;
    if ($last) {
        $prev_count = (int)($last['sent_count_10min'] ?? 1);
        $prev_last_sent = (string)($last['last_sent_at'] ?? '');
        if ($prev_last_sent !== '' && strtotime($prev_last_sent) > time() - 10 * 60) {
            $sent_count_10min = $prev_count + 1;
        }
    }
    if ($sent_count_10min > 3) {
        $conn->rollback();
        respond('error', '短時間に送信しすぎです。しばらくしてから再実行してください', ['error_code' => 'RATE_LIMIT']);
    }

    // 登録（status=0）
    $stmt = $conn->prepare("
        INSERT INTO other_otp_challenges
            (challenge_id, purpose, company_id, user_id, otp_hash, status, fail_count,
             sent_count_10min, last_sent_at, expires_at,
             client_ip, user_agent, created_at, updated_at)
        VALUES
            (?, ?, ?, ?, ?, 0, 0,
             ?, NOW(), ?,
             ?, ?, NOW(), NOW())
    ");
    // 9個
    $stmt->bind_param(
        "ssiisisss",
        $key_plain,
        $purpose,
        $company_id,
        $user_id,
        $otp_hash,
        $sent_count_10min,
        $expires_at,
        $ip,
        $user_agent
    );
    $stmt->execute();
    $stmt->close();

    // メール送信（テンプレ）
    if (!send_repass_mail_by_template_local($email, $username, $key_plain, $company_cd, '', $must_change_password)) {
        $conn->rollback();
        respond('error', 'メール送信に失敗しました', ['error_code' => 'MAIL_FAILED']);
    }

    $conn->commit();

    respond('success', '確認メールを送信しました', [
        'expires_at' => $expires_at
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log("repass_start error: " . $e->getMessage());
    respond('error', 'サーバーエラー', ['error_code' => 'SERVER_ERROR']);
}
