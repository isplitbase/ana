<?php
require_once './common.php';
require_once './system_log_helper.php';

// 共通関数
function respond($status, $message, $extra = []) {
	http_response_code(200);
	echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
	exit;
}

// リクエストデータの取得
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') !== false) {
	$data = json_decode(file_get_contents("php://input"), true);
} else {
	$data = $_POST;
}

// DBコネクション
global $conn;

// パラメータ取得
$key = $data['key'] ?? '';

if (!$key) {
	respond('error', '認証が失敗しました（キーがありません）');
}

// loginkey作成
$str = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPUQRSTUVWXYZ';
$str_r = substr(str_shuffle($str), 0, 20);
$loginkey = date('YmdHis') . $str_r;

// IPとユーザーエージェントの取得
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$param_json = json_encode([
	'ip' => $ip,
	'user_agent' => $user_agent
], JSON_UNESCAPED_UNICODE);

// check_codeと一致するユーザーを検索（必要情報も取得）
$stmt = $conn->prepare("SELECT user_id, username, email, create_at, company_id, company_cd, bumon_id,auth FROM users WHERE check_code = ?");
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();

if (!($row = $result->fetch_assoc())) {
	respond('error', '認証に失敗しました（キーが無効です）');
}

$user_id   = (int)$row['user_id'];
$username  = (string)$row['username'];
$email     = (string)$row['email'];
$create_at = $row['create_at'];
$auth = $row['auth'];
$company_cd = $row['company_cd'];
$company_id = isset($row['company_id']) ? (int)$row['company_id'] : -1;
$bumon_id   = isset($row['bumon_id']) ? (int)$row['bumon_id'] : 0;

// ==============================
// ✅ 追加：部門が削除されていないかチェック
// bumon_id が設定されているユーザのみチェック（0/NULLは対象外）
// ==============================
if ($bumon_id > 0) {
	// company_id が取れない場合は安全側で失敗
	if ($company_id < 0) {
		respond('error', '認証に失敗しました（company_idが取得できません）');
	}

	$stmt_b = $conn->prepare("SELECT bumon_id FROM bumon WHERE company_id = ? AND bumon_id = ? LIMIT 1");
	if (!$stmt_b) {
		respond('error', '認証処理中にエラーが発生しました（部門チェックSQL準備失敗）');
	}
	$stmt_b->bind_param("ii", $company_id, $bumon_id);
	$stmt_b->execute();
	$res_b = $stmt_b->get_result();
	$exists_bumon = ($res_b && $res_b->fetch_assoc()) ? true : false;
	$stmt_b->close();

	if (!$exists_bumon) {
		// ★ここが要件：該当部門が削除されていたら警告メッセージ＋認証失敗扱い
		respond('error', '該当部門が削除されたため認証できません。管理者にお問い合わせください', [
			'company_id' => $company_id,
			'bumon_id' => $bumon_id
		]);
	}
}

// トランザクション開始
$conn->begin_transaction();

try {
	// login_history に記録（param付き）
	$stmt_insert = $conn->prepare("
		INSERT INTO login_history (user_id, loginkey, create_at, status, param) 
		VALUES (?, ?, NOW(), 'OK', ?)
	");
	$stmt_insert->bind_param("iss", $user_id, $loginkey, $param_json);
	$stmt_insert->execute();

	// users.statusを100に更新
	$stmt_update = $conn->prepare("UPDATE users SET status = 100, update_user_id = NULL WHERE user_id = ?");
	$stmt_update->bind_param("i", $user_id);
	$stmt_update->execute();

	// コミット
	$conn->commit();

	write_system_log($conn, 'info', 'TENANT', 'USER_CREATED', 'ユーザーが追加されました',
        tenant_id:  $company_id > 0 ? $company_id : null,
        user_id:    $user_id,
        username:   $username ?: $email,
        ip_address: $ip
    );

	respond('success', '認証ができました', [
        'loginkey' => $loginkey,
        'name' => $username,
        'email' => $email,
        'create_at' => $create_at,
        'company_id' => $company_id,
        'company_cd' => $company_cd,
        'auth' => $auth,
		'must_change_password' => true,
	]);
} catch (Exception $e) {
	$conn->rollback();
	error_log("認証成功処理で例外: " . $e->getMessage());
    write_system_log($conn, 'error', 'TENANT', 'USER_CREATE_FAILED', 'ユーザー作成に失敗しました',
        tenant_id:  $company_id > 0 ? $company_id : null,
        user_id:    $user_id,
        ip_address: $ip,
        detail:     ['error' => $e->getMessage()]
    );
	respond('error', '認証処理中にエラーが発生しました');
}
?>
