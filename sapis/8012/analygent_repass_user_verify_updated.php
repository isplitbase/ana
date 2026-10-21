<?php
require_once './common.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond('error', 'POSTで呼び出してください', ['error_code' => 'METHOD_NOT_ALLOWED']);
}

$data = get_request_data();

$loginkey     = trim((string)($data['loginkey'] ?? '')); // 無くてもOK（未ログイン対応）
$key          = trim((string)($data['key'] ?? ''));
$new_password = (string)($data['new_password'] ?? '');

if ($key === '' || $new_password === '') {
    respond('error', 'パラメータ不足です', ['error_code' => 'MISSING_PARAMS']);
}

if (strlen($new_password) < 8) {
    respond('error', 'パスワードは8文字以上にしてください', ['error_code' => 'WEAK_PASSWORD']);
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    respond('error', 'DB接続が初期化されていません（common.phpの$connを確認してください）', ['error_code' => 'DB_NOT_READY']);
}

$purpose = 'repass';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

try {
    $conn->begin_transaction();

    // 1) challenge を取得（key=challenge_id）
    $stmt = $conn->prepare("
        SELECT id, company_id, user_id, otp_hash, status, fail_count, expires_at, verified_at
        FROM other_otp_challenges
        WHERE challenge_id = ?
          AND purpose = ?
        LIMIT 1
    ");
    $stmt->bind_param("ss", $key, $purpose);
    $stmt->execute();
    $ch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$ch) {
        $conn->rollback();
        respond('error', '認証情報が見つかりません。再度実行してください。', ['error_code' => 'CHALLENGE_NOT_FOUND']);
    }

    $challenge_db_id = (int)$ch['id'];
    $challenge_user_id = (int)$ch['user_id'];
    $challenge_company_id = (int)$ch['company_id'];

    // 2) loginkey がある場合は「本人一致」を追加でチェック
    if ($loginkey !== '') {
        $stmt = $conn->prepare("
            SELECT u.user_id, u.company_id
            FROM users u
            JOIN login_history l ON u.user_id = l.user_id
            WHERE l.loginkey = ?
              AND u.status > 0 AND u.status < 999 AND u.type='Z' AND l.status='OK'
            ORDER BY l.create_at DESC
            LIMIT 1
        ");
        $stmt->bind_param("s", $loginkey);
        $stmt->execute();
        $u = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$u) {
            $conn->rollback();
            respond('error', '認証に失敗しました（loginkeyが無効です）', ['error_code' => 'INVALID_LOGINKEY']);
        }

        if ((int)$u['user_id'] !== $challenge_user_id || (int)$u['company_id'] !== $challenge_company_id) {
            $conn->rollback();
            respond('error', '認証に失敗しました（本人確認に失敗しました）', ['error_code' => 'MISMATCH_USER']);
        }
    }

    // 3) すでに使用済み
    if (!empty($ch['verified_at'])) {
        $conn->rollback();
        respond('error', 'このリンクはすでに使用されています。再度実行してください。', ['error_code' => 'ALREADY_USED']);
    }

    // 4) 期限切れ
    $expires_at = (string)$ch['expires_at'];
    if (strtotime($expires_at) < time()) {
        $stmt = $conn->prepare("
            UPDATE other_otp_challenges
            SET status = 9, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $challenge_db_id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();
        respond('error', 'リンクの有効期限が切れました。再度実行してください。', ['error_code' => 'EXPIRED']);
    }

    // 5) key照合（login_verifyと同じ：password_verify）
    $otp_hash = (string)$ch['otp_hash'];
    if ($otp_hash === '' || !password_verify($key, $otp_hash)) {
        $stmt = $conn->prepare("
            UPDATE other_otp_challenges
            SET fail_count = fail_count + 1,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->bind_param("i", $challenge_db_id);
        $stmt->execute();
        $stmt->close();

        $conn->rollback();
        respond('error', 'リンクが正しくありません', ['error_code' => 'KEY_INVALID']);
    }

    // 6) verified更新（status=1）
    $stmt = $conn->prepare("
        UPDATE other_otp_challenges
        SET verified_at = NOW(),
            status = 1,
            client_ip = ?,
            user_agent = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $ip, $user_agent, $challenge_db_id);
    $stmt->execute();
    $stmt->close();

    // 7) パスワード更新（users.password 確定）
    $new_hash = password_hash($new_password, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("
        UPDATE users
        SET password = ?, update_at = NOW(), update_user_id = NULL
        WHERE user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $new_hash, $challenge_user_id);
    $stmt->execute();
    $stmt->close();

// 招待中ユーザー(status=-1)は、初回パスワード変更完了で有効化する
$stmt = $conn->prepare("
    UPDATE users
    SET status = 100, update_at = NOW(), update_user_id = NULL
    WHERE user_id = ?
      AND status = -1
    LIMIT 1
");
$stmt->bind_param("i", $challenge_user_id);
$stmt->execute();
$stmt->close();

    $conn->commit();
    respond('success', 'パスワードを更新しました');

} catch (Throwable $e) {
    $conn->rollback();
    error_log("repass_verify error: " . $e->getMessage());
    respond('error', 'サーバーエラー', ['error_code' => 'SERVER_ERROR']);
}
