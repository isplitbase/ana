<?php
require_once './common.php';
require_once './ana_password_helper.php';

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

function generate_check_code() {
    $str = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPUQRSTUVWXYZ';
    $str_r = substr(str_shuffle($str), 0, 15);
    return date('YmdHis') . $str_r;
}

function generate_company_cd($company_id, $conn) {
    // 例: company_id=23 -> C0023{d}  (dは0-9の1桁)
    $base = 'C' . str_pad((string)(int)$company_id, 4, '0', STR_PAD_LEFT);

    for ($i = 0; $i < 20; $i++) {
        $suffix = (string)random_int(0, 9);
        $company_cd = $base . $suffix;

        $stmt = $conn->prepare("SELECT 1 FROM users WHERE company_cd = ? LIMIT 1");
        if (!$stmt) {
            // prepare失敗時は一旦候補を返す（ユニーク制約があるならDBが守ってくれる）
            return $company_cd;
        }
        $stmt->bind_param("s", $company_cd);
        $stmt->execute();
        $stmt->store_result();
        $exists = ($stmt->num_rows > 0);
        $stmt->close();

        if (!$exists) {
            return $company_cd;
        }
    }
    // どうしても衝突する場合はタイムスタンプを付加
    return $base . substr((string)time(), -1);
}


function send_activation_mail($email, $name, $check_code, $company_cd) {
    global $mail_smtp_user, $mail_smtp_password, $www_dir;

    $activation_link = ana_front_url("auth/company-activate?key=" . urlencode($check_code));

    $template_path = $www_dir . "/sapis/mailtemplate";
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
    $body = str_replace('${activation_link}', $activation_link, $body);

    
    $body =     $body = str_replace('${company_cd}', (string)$company_cd, $body);
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
        $mail->Subject = "【Analygent】ユーザー登録認証のご案内";
        $mail->Body    = $body;

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

function mb_len($s) {
    return mb_strlen((string)$s, 'UTF-8');
}

function to_halfwidth_digits($s) {
    return preg_replace_callback('/[０-９]/u', function ($m) {
        return chr(ord($m[0]) - 0xFEE0);
    }, (string)$s);
}

// -------------------------
// 入力受け取り（JSON/POST両対応）
// -------------------------
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) $data = [];
} else {
    $data = $_POST;
}

$company_name = trim((string)($data['company_name'] ?? '')); // 必須 max300
$user_name    = trim((string)($data['user_name'] ?? ''));    // 必須 max150
$address      = trim((string)($data['address'] ?? ''));      // 任意 max500
$email        = trim((string)($data['email'] ?? ''));        // 必須 max300
$password     = (string)($data['password'] ?? '');           // 必須 max50（trimしない）
$tel          = trim((string)($data['tel'] ?? ''));          // 任意 max50

$username_for_users = $user_name;

// ★このファイルから登録されるユーザーは「マスター管理者」
$auth = 300;

// -------------------------
// 入力検証（必須）
// -------------------------
if ($company_name === '' || $user_name === '' || $email === '' || $password === '') {
    respond('error', '無効な入力です（会社名・担当者名・メールアドレス・パスワードは必須）');
}

if (mb_len($company_name) > 300) respond('error', '会社名は300文字以内にしてください');
if (mb_len($user_name) > 150)    respond('error', '担当者名は150文字以内にしてください');
if ($address !== '' && mb_len($address) > 500) respond('error', '住所は500文字以内にしてください');
if (mb_len($email) > 300)        respond('error', 'メールアドレスは300文字以内にしてください');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond('error', '無効なメールアドレスです');
}

// フリーメールは企業登録できない（フロントと同じ一覧。フロントを通らない呼び出しもここで止める）
if (ana_is_free_email_domain(ana_email_domain($email))) {
    respond('error', 'フリーメールアドレスは登録できません。企業ドメインのメールアドレスをご入力ください');
}

// パスワード条件（フロントと同じ：8〜50文字、半角英数字記号、英字・数字・記号を各1文字以上）
$password_error = ana_pw_policy_error($password);
if ($password_error !== null) {
    respond('error', $password_error);
}
$password_hash = ana_pw_hash($password);

if ($tel !== '') {
    $tel = to_halfwidth_digits($tel);
    $tel = preg_replace('/\s+/', '', $tel);

    if (strlen($tel) > 50) {
        respond('error', '電話番号は50文字以内にしてください');
    }
    if (!preg_match('/^[0-9\-\+\(\)]+$/', $tel)) {
        respond('error', '電話番号は半角数字と「-」「+」と括号のみで入力してください');
    }
}

// -------------------------
// DB処理
// -------------------------
global $conn;

// check_code生成（UPDATEでもINSERTでも使う）
$check_code = generate_check_code();

// まず「本登録済み(email重複)」をブロックする
$stmt = $conn->prepare("
    SELECT user_id, company_id  FROM users
    WHERE email = ?
      AND type='Z'
      AND status > 0
      AND status < 999
      AND company_name is not null
    LIMIT 1
");
if (!$stmt) {
    respond('error', 'DBエラー(prepare)です', ['detail' => 'select registered user prepare failed']);
}
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    respond('error', 'このメールアドレスは既に登録されています');
}
$stmt->close();


// 次に「仮登録が残っている（status=-1）既存ユーザー」を探す
$pending_user_id = null;

$stmt = $conn->prepare("
    SELECT user_id, company_id, company_cd
    FROM users
    WHERE email = ?
      AND type='Z'
      AND status = -1
      AND company_name IS NOT NULL
    ORDER BY update_at DESC
    LIMIT 1
");
if (!$stmt) {
    respond('error', 'DBエラー(prepare)です', ['detail' => 'select pending user prepare failed']);
}
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($pending_user_id, $pending_company_id_tmp, $pending_company_cd_tmp);
$stmt->fetch();
$stmt->close();



$pending_company_id = ($pending_company_id_tmp !== null && (int)$pending_company_id_tmp > 0) ? (int)$pending_company_id_tmp : (int)$pending_user_id;
// ---- トランザクション開始（UPDATE/INSERTの一括保証）
$conn->begin_transaction();

try {

    // -------------------------
    // ① 仮登録がある → UPDATE
    // -------------------------
    if (!empty($pending_user_id)) {

        $sql = "
            UPDATE users
            SET
                username       = ?,
                password       = ?,
                check_code     = ?,
                company_name   = ?,
                address        = ?,
                auth           = ?,
                update_at      = NOW(),
                update_user_id = NULL
            WHERE user_id = ?
              AND status = -1
              AND type='Z'
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('update pending prepare failed');
        }

        $stmt->bind_param(
            "sssssii",
            $username_for_users,
            $password_hash,
            $check_code,
            $company_name,
            $address,
            $auth,
            $pending_user_id
        );

        if (!$stmt->execute()) {
            $stmt->close();
            throw new Exception('update pending execute failed');
        }
        $stmt->close();

        // 認証メールに載せる company_cd は仮登録時に発行済みのものを使う。
        // 未発行の場合（古いデータなど）は、新規登録と同じ手順で発行してセットする。
        $company_cd_for_mail = trim((string)($pending_company_cd_tmp ?? ''));
        if ($company_cd_for_mail === '') {
            $company_cd_for_mail = generate_company_cd($pending_company_id, $conn);
            $stmt_cd = $conn->prepare("UPDATE users SET company_cd = ?, update_at = NOW(), update_user_id = NULL WHERE company_id = ? OR user_id = ?");
            if (!$stmt_cd) {
                throw new Exception('update pending company_cd prepare failed');
            }
            $pending_user_id_int = (int)$pending_user_id;
            $stmt_cd->bind_param("sii", $company_cd_for_mail, $pending_company_id, $pending_user_id_int);
            if (!$stmt_cd->execute()) {
                $stmt_cd->close();
                throw new Exception('update pending company_cd execute failed');
            }
            $stmt_cd->close();
        }

        // UPDATE時は company_id は変えない（既存のまま）
        $conn->commit();

        // メール送信（DB確定後）
        if (send_activation_mail($email, $user_name, $check_code, $company_cd_for_mail)) {
            respond('success', '既存の仮登録ユーザーを更新し、認証メールを再送しました。', [
                'email' => $email,
                'user_id' => (int)$pending_user_id
            ]);
        } else {
            respond('error', '仮登録ユーザーの更新は成功しましたが、認証メールの送信に失敗しました。', [
                'user_id' => (int)$pending_user_id
            ]);
        }
    }

    // -------------------------
    // ② 仮登録がない → INSERT 新規作成
    // -------------------------
    $sql = "
        INSERT INTO users (
            create_at,
            username,
            password,
            status,
            type,
            email,
            plan_id,
            point,
            check_code,
            company_name,
            address,
            auth,
            update_at,
            create_user_id
        )
        VALUES (
            NOW(),
            ?,
            ?,
            -1,
            'Z',
            ?,
            300,
            3000,
            ?,
            ?,
            ?,
            ?,
            NOW(),
            NULL
        )
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception('insert prepare failed');
    }

    $stmt->bind_param(
        "ssssssi",
        $username_for_users,
        $password_hash,
        $email,
        $check_code,
        $company_name,
        $address,
        $auth
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('insert execute failed');
    }
    $stmt->close();

    $new_user_id = (int)$conn->insert_id;
    if ($new_user_id <= 0) {
        throw new Exception('insert_id not available');
    }

    // company_id に user_id をセット
    $stmt = $conn->prepare("UPDATE users SET company_id = ?, update_at = NOW(), update_user_id = NULL WHERE user_id = ?");
    if (!$stmt) {
        throw new Exception('update company_id prepare failed');
    }
    $stmt->bind_param("ii", $new_user_id, $new_user_id);

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception('update company_id execute failed');
    }
    $stmt->close();

    // company_cd（企業ログインID）を生成してセット
    $company_cd_for_mail = generate_company_cd($new_user_id, $conn);
    $stmt_cd = $conn->prepare("UPDATE users SET company_cd = ?, update_at = NOW(), update_user_id = NULL WHERE company_id = ?");
    if ($stmt_cd) {
        $stmt_cd->bind_param("si", $company_cd_for_mail, $new_user_id);
        if (!$stmt_cd->execute()) {
            $stmt_cd->close();
            throw new Exception('update company_cd execute failed');
        }
        $stmt_cd->close();
    }

    $conn->commit();

} catch (Throwable $e) {
    // このファイルの Exception は PHPMailer の Exception（use 文）なので、
    // mysqli の例外も含めてロールバックするよう Throwable で受ける
    $conn->rollback();
    error_log("DB Transaction Error: " . $e->getMessage());
    respond('error', 'ユーザー仮登録に失敗しました（DB更新）');
}

// メール送信（DB確定後に実施）
if (send_activation_mail($email, $user_name, $check_code, $company_cd_for_mail)) {
    respond('success', 'ユーザー仮登録に成功しました。認証メールを送信しました。', [
        'email' => $email,
        'user_id' => $new_user_id,
        'company_id' => $new_user_id
    ]);
} else {
    respond('error', '仮登録は成功しましたが、認証メールの送信に失敗しました。', [
        'user_id' => $new_user_id,
        'company_id' => $new_user_id
    ]);
}
