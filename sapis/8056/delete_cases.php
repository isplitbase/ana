<?php
// composer autoload (google/cloud-storage)
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1)) { require_once $autoload1; }
else if (file_exists($autoload2)) { require_once $autoload2; }

include "./common.php";
include "./_ai_case_list_helper.php";
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
$gcs_delete_results = [];
foreach ($delete_case_list as $ai_case_id) {
    $ai_case_id   = (int)$ai_case_id;
    $gcs_data     = $case_data_map[$ai_case_id] ?? [];
    $full_paths   = $gcs_data['full_file_Paths'] ?? '';
    $img_urls     = $gcs_data['img_urls'] ?? '';

    $result = delete_gcs_files_for_case($ai_case_id, $full_paths, $img_urls);
    $gcs_delete_results[$ai_case_id] = $result;
}

// ---- フェーズ4: 削除後のリストをレスポンス ----
$response = build_ai_case_list_response($conn, $user_id, $row_start, $row_end, $search_text);
$response['deleted_count']      = count($delete_case_list);
$response['gcs_delete_results'] = $gcs_delete_results;

echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;


// =========================================================
// GCSファイル物理削除関数
// =========================================================
/**
 * 1案件のGCSファイルを物理削除する
 *
 * 削除対象:
 *   1. full_file_Paths / img_urls に含まれる gs:// URI
 *   2. バケット内プレフィックス "{ai_case_id}-" （PDFファイル）
 *   3. バケット内プレフィックス "converted/{ai_case_id}-" （変換済み画像）
 *
 * @param int    $ai_case_id
 * @param string $full_file_Paths_str  DB の full_file_Paths（|,| 区切り）
 * @param string $img_urls_str         DB の img_urls（|,| 区切り）
 * @return array ["deleted" => [...], "errors" => [...]]
 */
function delete_gcs_files_for_case($ai_case_id, $full_file_Paths_str, $img_urls_str) {
    global $gcs_bucket;
    global $google_application_credentials;

    $deleted = [];
    $errors  = [];

    if (empty($gcs_bucket)) {
        return ["deleted" => $deleted, "errors" => ["gcs_bucket未設定のためGCS削除をスキップ"]];
    }

    if (!class_exists("\\Google\\Cloud\\Storage\\StorageClient")) {
        return ["deleted" => $deleted, "errors" => ["google/cloud-storage未読込のためGCS削除をスキップ"]];
    }

    if (!empty($google_application_credentials)) {
        putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $google_application_credentials);
    }

    try {
        $storage = new \Google\Cloud\Storage\StorageClient();
        $bucket  = $storage->bucket($gcs_bucket);
    } catch (Exception $e) {
        return ["deleted" => $deleted, "errors" => ["GCSクライアント初期化失敗: " . $e->getMessage()]];
    }

    // ── 1. gs:// URI を直接削除 ──
    $all_paths = [];
    if (!empty($full_file_Paths_str)) {
        foreach (explode('|,|', $full_file_Paths_str) as $p) {
            $all_paths[] = trim($p);
        }
    }
    if (!empty($img_urls_str)) {
        foreach (explode('|,|', $img_urls_str) as $p) {
            $all_paths[] = trim($p);
        }
    }

    foreach ($all_paths as $path) {
        if (!preg_match('#^gs://([^/]+)/(.+)$#', $path, $m)) {
            continue; // ローカルパスやS3パスはスキップ
        }
        $obj_bucket = $m[1];
        $obj_name   = $m[2];
        if ($obj_bucket !== $gcs_bucket) {
            continue; // 別バケットはスキップ
        }
        try {
            $bucket->object($obj_name)->delete();
            $deleted[] = $path;
        } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
            // 既に存在しない場合は無視
        } catch (Exception $e) {
            $errors[] = $path . ": " . $e->getMessage();
        }
    }

    // ── 2. プレフィックスによる一括削除 ──
    // PDFファイル: {ai_case_id}-{fileKey}.pdf
    // 変換済み画像: converted/{ai_case_id}-{fileKey}-page*.png
    $prefixes = [
        (string)$ai_case_id . "-",
        "converted/" . $ai_case_id . "-",
        // 2026-10-14 追加: pdf-converter の旧リビジョンが作っていた中間ファイル
        //   converted/converted_{ai_case_id}-{fileKey}-NNN_mini.png
        //   は上の前方一致（converted/{id}-）に一致せず削除されずに残っていた。
        //   2026-08-27 以降は生成されないが、過去分が GCS に残存するため対象に加える。
        "converted/converted_" . $ai_case_id . "-",
    ];

    foreach ($prefixes as $prefix) {
        try {
            $objects = $bucket->objects(['prefix' => $prefix]);
            foreach ($objects as $object) {
                $name = $object->name();
                try {
                    $object->delete();
                    $deleted[] = "gs://{$gcs_bucket}/{$name}";
                } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
                    // 既に削除済み
                } catch (Exception $e) {
                    $errors[] = "gs://{$gcs_bucket}/{$name}: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $errors[] = "prefix={$prefix}: " . $e->getMessage();
        }
    }

    return ["deleted" => $deleted, "errors" => $errors];
}
