<?php
include "./common.php";
include "./_ai_case_list_helper.php";
global $conn;
global $damy_flag;

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json");

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// JSONデータを受け取って配列に変換
$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$loginkey                 = $data['loginkey']              ?? null;
$delete_pdf_timing_cd_new = $data['delete_pdf_timing_cd']  ?? null;
$row_start_given          = isset($data['row_start']);
$row_end_given            = isset($data['row_end']);
$row_start                = $row_start_given ? (int)$data['row_start'] : 1;
$row_end                  = $row_end_given   ? (int)$data['row_end']   : null;
$search_text              = (isset($data['search_text']) && $data['search_text'] !== '') ? $data['search_text'] : null;

// ログインキーでユーザ認証
if ($damy_flag != "OK") {
    $user_id = null;
    if (isset($loginkey)) {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id == null) {
            echo json_encode(["status" => "error", "message" => "異常アクセス"]);
            exit;
        }
    } else {
        echo json_encode(["status" => "error", "message" => "異常アクセス"]);
        exit;
    }
}

// ログインユーザの auth チェック（画面と同じく、マスター管理者（auth 300）のみ設定可）
$stmt_auth = $conn->prepare("SELECT auth FROM users WHERE user_id = ?");
$stmt_auth->bind_param("i", $user_id);
$stmt_auth->execute();
$auth_row = $stmt_auth->get_result()->fetch_assoc();
if (!$auth_row || (int)$auth_row['auth'] !== 300) {
    echo json_encode(["status" => "error", "message" => "権限がありません"]);
    exit;
}

// delete_pdf_timing_cd の入力チェック
if (empty($delete_pdf_timing_cd_new)) {
    echo json_encode(["status" => "error", "message" => "delete_pdf_timing_cd が未指定です"]);
    exit;
}

// delete_pdf_timing_cd が delete_pdf_timings テーブルに存在するか確認
$stmt_check = $conn->prepare("SELECT delete_pdf_timing_cd FROM delete_pdf_timings WHERE delete_pdf_timing_cd = ?");
$stmt_check->bind_param("s", $delete_pdf_timing_cd_new);
$stmt_check->execute();
if ($stmt_check->get_result()->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "無効な delete_pdf_timing_cd です: " . $delete_pdf_timing_cd_new]);
    exit;
}

// company ユーザの delete_pdf_timing_cd を更新
$stmt_update = $conn->prepare("
    UPDATE users company
    INNER JOIN users me ON me.company_id = company.user_id
    SET company.delete_pdf_timing_cd = ?,
        company.update_user_id = ?
    WHERE me.user_id = ?
");
$stmt_update->bind_param("sii", $delete_pdf_timing_cd_new, $user_id, $user_id);
$stmt_update->execute();

echo json_encode(
    build_ai_case_list_response($conn, $user_id, $row_start, $row_end, $search_text),
    JSON_UNESCAPED_UNICODE
);
exit;
