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
$request["status"]="success";
$request["message"]="帳票種類識別完了";
// JSONとして整形出力（日本語そのまま）
echo json_encode($request, JSON_UNESCAPED_UNICODE);
exit;

