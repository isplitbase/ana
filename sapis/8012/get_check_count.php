<?php

/**
 * ai_case の集計結果を読み込み、画像URLの再生成、ページ→シート種別推定、
 * OpenAI を使った並び替え（$aitext5）と整合性チェック（$aitext4）を実施し、
 * 整形した JSON を返却するエンドポイント。
 */

include "./common.php";
require 'vendor/autoload.php'; // AWS SDK がまだ読み込まれていない場合

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

global $conn;
global $uploadDir;
global $backendbase;
global $python_base;
global $super_message;
global $s3_access_key;
global $s3_secret_key;
global $s3_region;
global $s3_bucket_name;
global $damy_flag;

// JSONデータを受け取って配列に変換
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$loginkey = $data['loginkey'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;

if($damy_flag!="OK"){
    $user_id=null;
    if (isset($loginkey)) {
        $user_id=get_user_id_for_now($loginkey);
        if($user_id==null){
            echo json_encode([
                "status" => "error",
                "message" => "異常アクセス"
            ]);
            exit;
        }
    }
}
if (!isset($ai_case_id) || $ai_case_id==null) {
    echo json_encode([
        "status" => "error",
        "message" => "異常アクセス"
    ]);
    exit;
}


/**
 * loginkey から users.mail を取得（get_user_info.php のSQLに合わせる）
 */
function get_user_mail_by_loginkey(mysqli $conn, string $loginkey): array
{
    $stmt = $conn->prepare("
        SELECT u.mail
        FROM users u
        JOIN login_history l ON u.id = l.user_id
        WHERE l.loginkey = ?
          AND u.status > 0 AND u.status < 999
          AND u.type = 'Z'
        ORDER BY l.create_at DESC
        LIMIT 1
    ");

    if (!$stmt) {
        return [null, "DBのSELECT準備に失敗しました", $conn->error];
    }

    $stmt->bind_param("s", $loginkey);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if (!$row || empty($row['mail'])) {
        return [null, "ユーザー情報（mail）が取得できません", null];
    }

    return [$row['mail'], null, null];
}
/** =========================
 *  users.mail 取得
 *  ========================= */
$user_mail = "dummy@isplit.jp";
list($user_mail, $um_err, $um_detail) = get_user_mail_by_loginkey($conn, $loginkey);
if (!$user_mail) {
	http_response_code(500);
	header('Content-Type: application/json; charset=utf-8');
	echo json_encode([
		"status" => "error",
		"message" => $um_err ?? "users.mail の取得に失敗しました",
		"detail" => $um_detail,
	], JSON_UNESCAPED_UNICODE);
	exit;
}

$domain = "";
if (strpos($user_mail, '@') !== false) {
    $domain = strtolower(trim(substr(strrchr($user_mail, '@'), 1)));
}
$kousyaflag=false;
if ($domain === "isplit.jp") {
	$kousyaflag=true;
}

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT cbc_cout
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$cbc_cout=0;
// 結果をループして出力
while ($re = $result->fetch_assoc()) {
	$cbc_cout=$re['cbc_cout'];
}

$once=true;
if($cbc_cout>0){
	$once=false;
}
$request["cbc_cout"]=$cbc_cout;
$request["once"]=$once;
// JSONとして整形出力（日本語そのまま）
echo json_encode($request, JSON_UNESCAPED_UNICODE);
exit;

