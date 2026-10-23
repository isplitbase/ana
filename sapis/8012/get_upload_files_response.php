<?php
include "./common.php";
global $conn;
global $uploadDir;
global $backendbase;
global $python_base;
global $super_message;
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

// ログイン必須 ＋ 案件が同じ会社のものか確認（loginkey が無い／他社の案件ならエラーで終了）
require_once __DIR__ . "/_case_access_helper.php";
$user_id = ana_require_case_access($loginkey, $ai_case_id);
if (!isset($ai_case_id) || $ai_case_id==null) {
	echo json_encode([
		"status" => "error",
		"message" => "異常アクセス"
	]);
	exit;
}
// SQL構文修正：$parameters を直接埋め込まず、? を使う
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status='IMGTYPE'
        WHERE ai_case_id = ?
        AND status IN ('IMED', 'UPST')
");

$stmt->bind_param("i", $ai_case_id);
$stmt->execute();

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT imgtype_request, upload_file_keys, upload_file_name
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();

$request="";
$upload_file_keys_raw = "";
$upload_file_name_raw = "";
// 結果をループして出力
while ($re = $result->fetch_assoc()) {
	$request= $re['imgtype_request'];
	$upload_file_keys_raw = (string)($re['upload_file_keys'] ?? '');
	$upload_file_name_raw = (string)($re['upload_file_name'] ?? '');
}

$request=json_decode($request,true);

// imgtype_request の pdf_names は Cloud Run(pdf-converter) が付けた GCS 物理名
// <ai_case_id>-<upload_file_key>.pdf のため、画面には実際のアップロードファイル名を出す。
// upload_file_keys と upload_file_name は upload_files.php の同じ UPDATE で
// 保存されるため、並び順が対応している。
$__keys  = explode("|,|", $upload_file_keys_raw);
$__names = explode("|,|", $upload_file_name_raw);
$__key_to_name = array();
foreach ($__keys as $__i => $__k) {
    $__k = trim($__k);
    if ($__k === '') { continue; }
    if (isset($__names[$__i]) && trim($__names[$__i]) !== '') {
        $__key_to_name[$__k] = trim($__names[$__i]);
    }
}
if (!empty($__key_to_name) && is_array($request)
    && !empty($request["images"]) && is_array($request["images"])) {
    foreach ($request["images"] as $__idx => $__img) {
        if (!is_array($__img)) { continue; }
        $__k = isset($__img["upload_file_key"]) ? trim((string)$__img["upload_file_key"]) : '';
        if ($__k !== '' && isset($__key_to_name[$__k])) {
            $request["images"][$__idx]["pdf_names"] = $__key_to_name[$__k];
        }
    }
}
// 2026-10-23 追加：画像の署名付き URL を、開くたびに作り直す。
// imgtype_request に保存してある URL は PDF を画像にした時点で作られ、7日で切れる。
// 一覧から古い案件の「ファイル確認」を開くと、期限切れで画像が出なかった。
// 作り直しに失敗したときは、保存してある URL のまま返す（今までと同じ動き）。
if (is_array($request) && !empty($request["images"]) && is_array($request["images"])) {
	try {
		global $gcs_bucket, $google_application_credentials;
		$__autoload = file_exists(__DIR__ . "/vendor/autoload.php") ? __DIR__ . "/vendor/autoload.php" : __DIR__ . "/../vendor/autoload.php";
		if (file_exists($__autoload)) { require_once $__autoload; }
		if (!empty($gcs_bucket) && !empty($google_application_credentials) && class_exists("\\Google\\Cloud\\Storage\\StorageClient")) {
			$__storage = new \Google\Cloud\Storage\StorageClient(["keyFilePath" => $google_application_credentials]);
			$__bucket = $__storage->bucket($gcs_bucket);
			$__expires = new DateTime("+6 days");   // V4 署名の上限は7日
			foreach ($request["images"] as $__idx => $__img) {
				$__u = is_array($__img) ? (string)($__img["images_urls"] ?? "") : "";
				if (!preg_match('#^https://storage\.googleapis\.com/([^/?]+)/([^?]+)#', $__u, $__m)) { continue; }
				if ($__m[1] !== $gcs_bucket) { continue; }
				try {
					$request["images"][$__idx]["images_urls"] = $__bucket->object(rawurldecode($__m[2]))->signedUrl($__expires, ["version" => "v4"]);
				} catch (Throwable $__e) {
					error_log("get_upload_files_response: 署名の作り直しに失敗 ai_case_id={$ai_case_id} " . $__e->getMessage());
				}
			}
		}
	} catch (Throwable $__e) {
		error_log("get_upload_files_response: 署名の作り直しを飛ばした ai_case_id={$ai_case_id} " . $__e->getMessage());
	}
}
$request["status"]="success";
$request["message"]="帳票種類識別完了";
// JSONとして整形出力（日本語そのまま）
echo json_encode($request, JSON_UNESCAPED_UNICODE);
exit;

