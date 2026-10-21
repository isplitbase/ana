<?php
require_once './common.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require './vendor/autoload.php';

// -------------------------
// 共通レスポンス
// -------------------------
function respond($status, $message, $extra = []) {
    http_response_code(200);
    $ok = ($status === 'success');

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(array_merge([
        'ok' => $ok,
        'status' => $status,
        'message' => $message
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function mb_len($s) {
    return mb_strlen((string)$s, 'UTF-8');
}

function generate_check_code() {
    $str = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPUQRSTUVWXYZ';
    $str_r = substr(str_shuffle($str), 0, 15);
    return date('YmdHis') . $str_r;
}

function auth_label($auth) {
    $auth = (int)$auth;
    if ($auth === 300) return '管理者マスター';
    if ($auth === 200) return '管理者';
    if ($auth === 100) return 'ユーザ';
    return '不明';
}

/**
 * 10桁: 半角英数字（大小区別）
 */
function generate_auto_password_10() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $len = 10;
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $out;
}

/**
 * /sapis/mailtemplate_add_user を使ってメール送信
 * 置換:
 *  ${name}
 *  ${email}
 *  ${initial_password}
 *  ${activation_link}
 *  ${auth}
 *  ${auth_label}
 *  ${password_note}
 */
function send_add_user_mail_by_template($email, $name, $initial_password, $check_code, $auth, $company_cd, $password_note = '') {
    global $mail_smtp_user, $mail_smtp_password, $www_dir;

	$activation_link = "https://corp.analygent.com/auth/activate?key=" . urlencode($check_code);

    $template_path = $www_dir . "/sapis/mailtemplate_add_user";
    // フォールバック（共通テンプレートがある場合）
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

    $body = str_replace('${name}', $name, $body);
    $body = str_replace('${email}', $email, $body);
    $body = str_replace('${initial_password}', $initial_password, $body);
    $body = str_replace('${activation_link}', $activation_link, $body);
    $body = str_replace('${company_cd}', (string)$company_cd, $body);

    $body = str_replace('${auth}', (string)(int)$auth, $body);
    $body = str_replace('${auth_label}', auth_label($auth), $body);
    $body = str_replace('${password_note}', $password_note, $body);

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
        $mail->Subject = "【Analygent】ユーザー追加のお知らせ（初期パスワード）";
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error (add_user): " . $mail->ErrorInfo);
        return false;
    }
}

function get_email_domain($email) {
    $email = strtolower(trim((string)$email));
    $pos = strrpos($email, '@');
    if ($pos === false) return '';
    return substr($email, $pos + 1);
}

function is_free_email_domain($domain) {
    $free_domains = [
        'gmail.com','googlemail.com',
        'yahoo.co.jp','yahoo.com',
        'outlook.com','hotmail.com','live.com',
        'icloud.com','me.com',
        'aol.com',
        'proton.me','protonmail.com'
    ];
    return in_array($domain, $free_domains, true);
}

/**
 * company 内のマスター管理者(email)から企業ドメインを取得
 */
function get_company_master_domain(mysqli $conn, int $company_id) {
    $stmt = $conn->prepare("
        SELECT email
        FROM users
        WHERE company_id = ?
          AND auth = 300
          AND status > 0 AND status < 999
          AND type='Z'
        ORDER BY create_at ASC
        LIMIT 1
    ");
    if (!$stmt) return null;

    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['email'])) return null;
    return get_email_domain($row['email']);
}


// -------------------------
// 入力受け取り（JSON/POST両対応）
// -------------------------
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid JSON: ' . json_last_error_msg()
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $data = $_POST;
}

$loginkey     = $data['loginkey'] ?? null;
$user_name    = trim((string)($data['user_name'] ?? ''));
$email        = trim((string)($data['email'] ?? ''));
$auth_raw     = $data['auth'] ?? null;
$password     = (string)($data['password'] ?? ''); // trimしない
$password     = 'ISPLITAUTOPASSWORD';
$bumon_id_raw = $data['bumon_id'] ?? null;

// -------------------------
// 必須チェック
// -------------------------
if (empty($loginkey)) {
    respond('error', 'パラメータが不足しています（loginkey）');
}

// ログイン中の user_id を取得（common.php 側の関数想定）
$operator_user_id = get_user_id_for_now($loginkey);
if ($operator_user_id === null) {
    respond('error', '異常アクセス（認証に失敗しました）');
}

if ($user_name === '' || mb_len($user_name) > 150) {
    respond('error', 'user_name は必須で150文字以内です');
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond('error', '無効なメールアドレスです');
}

$auth = (int)$auth_raw;
if (!in_array($auth, [300, 200, 100], true)) {
    respond('error', 'auth は 300/200/100 のいずれかを指定してください');
}

$bumon_id = null;
if ($bumon_id_raw !== null && $bumon_id_raw !== '') {
    if (!is_numeric($bumon_id_raw)) {
        respond('error', 'bumon_id は数値で指定してください');
    }
    $bumon_id = (int)$bumon_id_raw;
    if ($bumon_id <= 0) {
        respond('error', 'bumon_id が不正です');
    }
}

// -------------------------
// パスワード自動生成
// - 未指定/空  OR "ISPLITAUTOPASSWORD" の場合、10桁の半角英数字を生成
// -------------------------
$auto_password_generated = false;
if ($password === '' || $password === 'ISPLITAUTOPASSWORD') {
    $password = generate_auto_password_10();
    $auto_password_generated = true;
}

// パスワード：半角英数字記号のみ / 50文字以内
if (strlen($password) > 50) {
    respond('error', 'パスワードは50文字以内にしてください');
}
if (!preg_match('/^[\x21-\x7E]+$/', $password)) {
    respond('error', 'パスワードは半角英数字記号のみで入力してください');
}

// -------------------------
// DB処理
// -------------------------
global $conn;

// 操作ユーザの company_id / auth を取得
$stmt = $conn->prepare("SELECT user_id, company_id, company_cd, auth, status FROM users WHERE user_id = ? LIMIT 1");
if (!$stmt) {
    respond('error', 'DBエラー(prepare)です', ['detail' => 'operator select prepare failed']);
}
$stmt->bind_param("i", $operator_user_id);
$stmt->execute();
$result = $stmt->get_result();
$operator = $result ? $result->fetch_assoc() : null;
$stmt->close();

if (!$operator) {
    respond('error', '異常アクセス（操作ユーザが存在しません）');
}

$operator_status = (int)$operator['status'];
if ($operator_status <= 0 || $operator_status >= 999) {
    respond('error', '異常アクセス（操作ユーザが無効です）');
}

$operator_auth = (int)($operator['auth'] ?? 0);
$company_id    = (int)($operator['company_id'] ?? -1);
$company_cd    = trim((string)($operator['company_cd'] ?? ''));

if ($company_id < 0) {
    respond('error', '操作ユーザの company_id が不正です');
}
if ($company_cd === '') {
    respond('error', '操作ユーザの company_cd が未設定です');
}

// -------------------------
// 権限制御ルール
// 300: 300/200/100 を作れる
// 200: 100 を作れる
// 100: 作れない
// -------------------------
$allowed_to_create = [];
if ($operator_auth === 300) {
    $allowed_to_create = [300, 200, 100];
} elseif ($operator_auth === 200) {
    $allowed_to_create = [100];
} elseif ($operator_auth === 100) {
    $allowed_to_create = [];
}

if (empty($allowed_to_create)) {
    respond('error', '権限がありません（ユーザー追加不可）');
}
if (!in_array($auth, $allowed_to_create, true)) {
    respond('error', '権限がありません（指定されたauthのユーザーは作成できません）');
}

// -------------------------
// 追加仕様（削除APIと同じ権限思想）
// auth=300の操作ユーザはすべて作成できる（既存の allowed_to_create により担保）
// auth=200の操作ユーザはauth=300のユーザを作成できません（念のため二重ガード）
// -------------------------
if ($operator_auth === 200 && $auth === 300) {
    respond('error', '権限がありません（管理者はマスター管理者を作成できません）');
}

// -------------------------
// 3-2 / 3-3: auth=300 指定時のメール制約
// - フリーアドレスはマスター管理者不可
// - 企業ドメイン一致のみマスター管理者可
// -------------------------
if ($auth === 300) {
    $email_domain = get_email_domain($email);
    if ($email_domain === '') {
        respond('error', 'メールアドレスの形式が不正です');
    }

    // 3-2: フリーアドレス禁止
    if (is_free_email_domain($email_domain)) {
        respond('error', 'フリーアドレスはマスター管理者に指定できません');
    }

    // 3-3: 企業ドメイン一致チェック（company内の既存マスターのドメインを採用）
    $company_domain = get_company_master_domain($conn, $company_id);
    if ($company_domain === null) {
        respond('error', '企業ドメインを判定できません（マスター管理者が未設定です）');
    }
    if ($email_domain !== $company_domain) {
        respond('error', '企業ドメインと一致しないためマスター管理者に指定できません', [
            'email_domain' => $email_domain,
            'company_domain' => $company_domain
        ]);
    }
}

// -------------------------
// 既存チェック（同じ会社内）
// 1) 本登録済み（status>0）→ エラー
// 2) 仮登録（status=-1）→ UPDATE（再送）
// -------------------------
$stmt = $conn->prepare("
    SELECT user_id
    FROM users
    WHERE email = ?
      AND company_id = ?
      AND type='Z'
      AND status > 0
      AND status < 999
    LIMIT 1
");
if (!$stmt) {
    respond('error', 'DBエラー(prepare)です', ['detail' => 'select registered prepare failed']);
}
$stmt->bind_param("si", $email, $company_id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    respond('error', 'このメールアドレスは既に登録されています');
}
$stmt->close();

// 仮登録（status=-1）を探す（あればUPDATE対象）
$pending_user_id = null;
$stmt = $conn->prepare("
    SELECT user_id
    FROM users
    WHERE email = ?
      AND company_id = ?
      AND type='Z'
      AND status = -1
    ORDER BY update_at DESC
    LIMIT 1
");
if (!$stmt) {
    respond('error', 'DBエラー(prepare)です', ['detail' => 'select pending prepare failed']);
}
$stmt->bind_param("si", $email, $company_id);
$stmt->execute();
$stmt->bind_result($pending_user_id);
$stmt->fetch();
$stmt->close();

// bumon_id が指定されている場合のみチェックする
if (!empty($bumon_id)) {

    $sql_bumon_check = "SELECT 1 FROM bumon WHERE company_id = ? AND bumon_id = ? LIMIT 1";
    $stmt_bumon_check = $conn->prepare($sql_bumon_check);

    if (!$stmt_bumon_check) {
        respond('error', 'DBエラー(prepare)です', ['detail' => 'bumon check prepare failed']);
    }

    $stmt_bumon_check->bind_param("ii", $company_id, $bumon_id);
    $stmt_bumon_check->execute();

    $stmt_bumon_check->store_result();

    if ($stmt_bumon_check->num_rows === 0) {
        $stmt_bumon_check->close();
        respond('error', '指定された部門が存在しません。', [
            'company_id' => $company_id,
            'bumon_id' => $bumon_id,
            'email' => $email,
            'auth' => $auth,
            'auth_label' => auth_label($auth),
        ]);
    }

    $stmt_bumon_check->close();
}

// check_code生成（UPDATE/INSERT共通）
$check_code = generate_check_code();

// 会社名・住所はこのAPIでは不要（既存踏襲）
$company_name = '';
$address      = '';

// plan/point は既存踏襲（NOT NULL対策）
$plan_id = 100;
$point   = 3000;

// status は -1（仮登録）
$status = -1;

$conn->begin_transaction();

try {

    // -------------------------
    // ① 仮登録がある → UPDATE
    // -------------------------
    if (!empty($pending_user_id)) {
        $sql = "
            UPDATE users
            SET
                username         = ?,
                password         = ?,
                check_code       = ?,
                company_cd       = ?,
                auth             = ?,
                bumon_id         = ?,
                update_at        = NOW(),
                must_change_password='YES',
                update_user_id   = ?
            WHERE user_id = ?
              AND company_id = ?
              AND type='Z'
              AND status = -1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('update pending prepare failed');
        }

        $stmt->bind_param(
            "ssssiiiii",
            $user_name,
            $password,
            $check_code,
            $company_cd,
            $auth,
            $bumon_id,
            $operator_user_id,
            $pending_user_id,
            $company_id
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('update pending execute failed');
        }
        $stmt->close();

        $conn->commit();

        // メール送信
        $password_note = $auto_password_generated ? '（パスワードは自動生成されました）' : '';
        $mail_ok = send_add_user_mail_by_template($email, $user_name, $password, $check_code, $auth, $company_cd, $password_note);

        respond('success', 'ユーザー追加（仮登録の更新）しました', [
            'mail_sent' => (bool)$mail_ok,
            'user_id' => (int)$pending_user_id,
            'auth' => $auth,
            'auth_label' => auth_label($auth),
        ]);
    }

    // -------------------------
    // ② 新規 → INSERT
    // -------------------------
	$sql = "
	  INSERT INTO users (
		username, md, password, status, type, email, plan_id, point,
		check_code, company_name, address, auth, company_id, bumon_id, company_cd, create_at, update_at, must_change_password, create_user_id
	  ) VALUES (
		?, '', ?, ?, 'Z', ?, ?, ?,
		?, NULL, NULL, ?, ?, ?, ?, NOW(), NOW(), 'YES', ?
	  )
	";
	$stmt = $conn->prepare($sql);
	if (!$stmt) {
		throw new Exception('insert prepare failed');
	}

	$stmt->bind_param(
	  "ssisiisiiisi",
	  $user_name,
	  $password,
	  $status,
	  $email,
	  $plan_id,
	  $point,
	  $check_code,
	  $auth,
	  $company_id,
	  $bumon_id,
	  $company_cd,
	  $operator_user_id
	);


    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('insert execute failed');
    }

    $new_user_id = (int)$stmt->insert_id;
    $stmt->close();

    $conn->commit();

    // メール送信
    $password_note = $auto_password_generated ? '（パスワードは自動生成されました）' : '';
    $mail_ok = send_add_user_mail_by_template($email, $user_name, $password, $check_code, $auth, $company_cd, $password_note);

    respond('success', 'ユーザー追加（仮登録）しました', [
        'mail_sent' => (bool)$mail_ok,
        'user_id' => $new_user_id,
        'auth' => $auth,
        'auth_label' => auth_label($auth),
    ]);

} catch (Throwable $e) {
    $conn->rollback();
    error_log("DB Error (analygent_add_user.php): " . $e->getMessage());
    respond('error', 'サーバーエラー', ['detail' => $e->getMessage()]);
}
