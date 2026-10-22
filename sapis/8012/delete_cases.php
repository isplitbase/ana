<?php
// composer autoload (google/cloud-storage)
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1)) { require_once $autoload1; }
else if (file_exists($autoload2)) { require_once $autoload2; }

include "./common.php";
include "./_ai_case_list_helper.php";
include "./_delete_gcs_helper.php";
global $conn;
global $damy_flag;
global $gcs_bucket;
global $google_application_credentials;

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

$loginkey        = $data['loginkey'] ?? null;
$row_start_given = isset($data['row_start']);
$row_end_given   = isset($data['row_end']);
$row_start       = $row_start_given ? (int)$data['row_start'] : 1;
$row_end         = $row_end_given   ? (int)$data['row_end']   : null;
$search_text     = (isset($data['search_text']) && $data['search_text'] !== '') ? $data['search_text'] : null;

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

// delete_case_list チェック
if (!isset($data['delete_case_list']) || !is_array($data['delete_case_list']) || count($data['delete_case_list']) === 0) {
    echo json_encode(["status" => "error", "message" => "異常アクセス"]);
    exit;
}

$delete_case_list = $data['delete_case_list'];

// ログインユーザの情報を取得
$stmt_me = $conn->prepare("SELECT auth, company_id FROM users WHERE user_id = ?");
$stmt_me->bind_param("i", $user_id);
$stmt_me->execute();
$me = $stmt_me->get_result()->fetch_assoc();

if (!$me) {
    echo json_encode(["status" => "error", "message" => "ユーザ情報が取得できませんでした"]);
    exit;
}

$my_auth       = (int)$me['auth'];
$my_company_id = $me['company_id'];

// ---- フェーズ1: 全件の削除権限チェック＆GCS削除用データ収集 ----
$stmt_check = $conn->prepare("
    SELECT ac.ai_case_id, ac.user_id, ac.upload_file_name, ac.full_file_Paths, ac.img_urls,
           u.company_id AS case_company_id
    FROM ai_case ac
    INNER JOIN users u ON ac.user_id = u.user_id
    WHERE ac.ai_case_id = ?
");

// ケースデータを保持（GCS削除 + 操作履歴用）
$case_data_map = [];

foreach ($delete_case_list as $ai_case_id) {
    $ai_case_id = (int)$ai_case_id;

    $stmt_check->bind_param("i", $ai_case_id);
    $stmt_check->execute();
    $case_row = $stmt_check->get_result()->fetch_assoc();

    if (!$case_row) {
        echo json_encode(["status" => "error", "message" => "ai_case_id: {$ai_case_id} が存在しません"]);
        exit;
    }

    $is_own_case  = ((int)$case_row['user_id'] === (int)$user_id);
    $same_company = ($case_row['case_company_id'] == $my_company_id);
    $is_permitted = ($my_auth >= 200 && $same_company);

    if (!$is_own_case && !$is_permitted) {
        $file_name = $case_row['upload_file_name'] ?? "ai_case_id: {$ai_case_id}";
        echo json_encode(
            ["status" => "error", "message" => "「{$file_name}」の削除権限がありません"],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    // GCS削除 + 操作履歴用データを保持
    $case_data_map[$ai_case_id] = [
        "full_file_Paths"  => $case_row['full_file_Paths'] ?? '',
        "img_urls"         => $case_row['img_urls'] ?? '',
        "upload_file_name" => $case_row['upload_file_name'] ?? '',
    ];
}

// ---- フェーズ2: 全件 status を DELETED に更新 ----
$ip     = $_SERVER['REMOTE_ADDR']     ?? '';
$device = $_SERVER['HTTP_USER_AGENT'] ?? '';

$stmt_delete = $conn->prepare("
    UPDATE ai_case
    SET status = 'DELETED', update_at = now(), update_user_id = ?
    WHERE ai_case_id = ?
");

$stmt_usede = $conn->prepare("
    INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'ケース削除', ?)
");

foreach ($delete_case_list as $ai_case_id) {
    $ai_case_id = (int)$ai_case_id;

    // 論理削除
    $stmt_delete->bind_param("ii", $user_id, $ai_case_id);
    $stmt_delete->execute();

    // 操作履歴
    $filename      = $case_data_map[$ai_case_id]['upload_file_name'] ?? '';
    $use_point     = 0;
    $pages_number  = 0;
    $p1            = 0;
    $stmt_usede->bind_param("iisissii", $user_id, $use_point, $filename, $pages_number, $ip, $device, $ai_case_id, $p1);
    $stmt_usede->execute();
}

// ---- フェーズ3: GCS物理削除 ----
// 2026-10-21 裏で行うように変更。
//   1案件に PDF・ページ画像・作業画像が数十〜数百あり、1つずつ消すため、
//   ここで消すと20件以上の一括削除で1分以上応答が返らず、画面がずっと処理中になっていた。
//   DB は上で論理削除済みなので、画面にはもう出ない。GCS は delete_cases_gcs.php が裏で消す。
//   裏で起動できないときだけ、これまでどおりここで消す。
$gcs_delete_results = [];
$gcs_worker = __DIR__ . '/delete_cases_gcs.php';
$gcs_ids    = array_map('intval', $delete_case_list);
$gcs_php    = is_executable('/usr/bin/php') ? '/usr/bin/php' : 'php';
$gcs_kicked = false;
if (is_file($gcs_worker) && function_exists('exec')) {
    exec('nohup ' . escapeshellarg($gcs_php) . ' ' . escapeshellarg($gcs_worker) . ' '
         . implode(' ', $gcs_ids) . ' > /dev/null 2>&1 &', $gcs_out, $gcs_rc);
    $gcs_kicked = ($gcs_rc === 0);
}
if (!$gcs_kicked) {
    foreach ($gcs_ids as $ai_case_id) {
        $gcs_data   = $case_data_map[$ai_case_id] ?? [];
        $full_paths = $gcs_data['full_file_Paths'] ?? '';
        $img_urls   = $gcs_data['img_urls'] ?? '';

        $result = delete_gcs_files_for_case($ai_case_id, $full_paths, $img_urls);
        $gcs_delete_results[$ai_case_id] = $result;
    }
}

// ---- フェーズ4: 削除後のリストをレスポンス ----
$response = build_ai_case_list_response($conn, $user_id, $row_start, $row_end, $search_text);
$response['deleted_count']      = count($delete_case_list);
$response['gcs_delete_results'] = $gcs_delete_results;   // 裏で消すときは空
$response['gcs_delete_mode']    = $gcs_kicked ? 'background' : 'inline';

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

