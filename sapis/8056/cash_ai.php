<?php
// ===== デバッグ用エラー出力（本番確認後に削除すること） =====
ini_set('display_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status'  => 'error',
            'message' => 'PHP Fatal Error',
            'detail'  => $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line'],
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    // 致命的でないエラーはログに書くだけ（ここではスルー）
    return false;
});
// ===== デバッグ用エラー出力ここまで =====

/**
 * cash_ai.php
 *
 * フロントから来たリクエスト(JSON)を受け取り、
 * loginkey を common.php の方式で検証した上で、
 * 1) Cloud Run (cash-ai-01) の /v1/pipeline に転送
 * 2) DB(ai_case)から closing_date/zenki/zenzenki を取得
 * 3) 上記結果をまとめて Cloud Run (cash-ai-02) の /v1/pipeline に転送
 * 4) cash-ai-02 のレスポンスに含まれる html(GCS署名付きURL) を cash-ai-04 でパッチして
 *    patched_url で置換して返す
 *
 * 追加：各Cloud Run API呼び出しの所要時間(ms)をレスポンスに _timing として付与
 *
 * ※重要：cash-ai-02 へのリクエストに loginkey と ai_case_id を必ずセットする
 */

include "./common.php";
require_once "./system_log_helper.php";

global $damy_flag;
global $conn;
global $port;
global $cfpoint;

// composer autoload（google/auth を使う）
require_once __DIR__ . '/vendor/autoload.php';

use Google\Auth\ApplicationDefaultCredentials;

function getCloudRunIdToken(string $serviceAccountJsonPath, string $audience): string
{
    if (!file_exists($serviceAccountJsonPath)) {
        throw new Exception("Service account key not found: " . $serviceAccountJsonPath);
    }

    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $serviceAccountJsonPath);

    $creds = ApplicationDefaultCredentials::getIdTokenCredentials($audience);

    $tokenArr = $creds->fetchAuthToken();
    if (!is_array($tokenArr) || empty($tokenArr['id_token'])) {
        throw new Exception("Failed to fetch id_token. Response: " . json_encode($tokenArr, JSON_UNESCAPED_UNICODE));
    }

    return $tokenArr['id_token'];
}

function postJsonToCloudRun(
    string $url,
    string $idToken,
    array $payload,
    int $timeoutSec = 600,
    int $maxAttempts = 3,
    int $baseDelayMs = 3000
): array
{
    // NOTE:
    // - $maxAttempts は「合計試行回数」です（例: 3なら最大3回=初回+リトライ2回）
    // - 401/403/4xx(429除く)は原則リトライしません
    // - 200でもJSONの中身がエラーっぽい場合はリトライします

    $attempt = 0;
    $lastRespBody = "";
    $lastCurlErrNo = 0;
    $lastCurlErr = "";
    $lastHttpCode = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $idToken
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);

        $respBody = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastRespBody = ($respBody === false) ? "" : $respBody;
        $lastCurlErrNo = $curlErrNo;
        $lastCurlErr = $curlErr;
        $lastHttpCode = $httpCode;

        // リトライ判定
        $shouldRetry = false;

        // curlレベルの失敗
        if ($curlErrNo !== 0 || $respBody === false) {
            $shouldRetry = true;
        } else {
            // HTTPコードで判定
            if ($httpCode === 0) {
                $shouldRetry = true;
            } elseif ($httpCode === 429) {
                $shouldRetry = true;
            } elseif ($httpCode >= 500) {
                $shouldRetry = true;
            } elseif ($httpCode === 200 || $httpCode === 201) {
                // 200/201でも中身がエラーのケースを判定
                // 例: {"status":"error"} / {"ok":false} / {"error":...} / {"errors":[...]}
                $trim = ltrim($lastRespBody);
                if ($trim !== "" && ($trim[0] === '{' || $trim[0] === '[')) {
                    $j = json_decode($lastRespBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
                        $status = strtolower((string)($j["status"] ?? ""));
                        $ok = $j["ok"] ?? null;
                        $hasErrorKey = array_key_exists("error", $j) || array_key_exists("errors", $j);
                        $msg = strtolower((string)($j["message"] ?? $j["detail"] ?? ""));
                        if ($status === "error" || $ok === false || $hasErrorKey || (strpos($msg, "error") !== false)) {
                            $shouldRetry = true;
                        }
                    }
                } else {
                    // JSON以外が返る場合も、ここでは「一旦リトライ」対象にする
                    // （呼び出し元で json_decode 失敗として落ちるケースの救済）
                    $shouldRetry = true;
                }
            } else {
                // 4xx (401/403/400など) は基本リトライしない
                $shouldRetry = false;
            }
        }

        if (!$shouldRetry || $attempt >= $maxAttempts) {
            break;
        }

        // 指数バックオフ + 小さなジッター
        $delay = $baseDelayMs * (1 << ($attempt - 1));
        $jitter = random_int(0, 120);
        usleep(($delay + $jitter) * 1000);
    }

    return [$lastRespBody, $lastCurlErrNo, $lastCurlErr, $lastHttpCode];
}

function now_ms(): float
{
    return microtime(true) * 1000.0;
}

function elapsed_ms(float $start_ms): int
{
    return (int) round(now_ms() - $start_ms);
}


/**
 * URLへアクセスできるか（HEAD）確認
 * - 署名付きURLの期限切れ等を早期検出したい用途
 * - 200-399 を「アクセス可能」とみなす
 */
function check_url_accessible($url) {

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RANGE, "0-10"); // 先頭10バイトだけ取得
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    curl_exec($ch);

    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errNo) {
        return [false, $code, $err];
    }

    if ($code >= 200 && $code < 400) {
        return [true, $code, null];
    }

    return [false, $code, "http_code: $code"];
}

/**
 * GCS URL / gs:// URI から bucket / key を抽出
 * 対応形式:
 *   gs://bucket/key
 *   https://storage.googleapis.com/bucket/key?...
 */
function parse_gcs_bucket_key_from_url(string $url): array
{
    // gs:// URI
    if (preg_match('#^gs://([^/]+)/(.+)$#', $url, $m)) {
        return [$m[1], $m[2], null];
    }

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['path'])) {
        return [null, null, "invalid url"];
    }

    // https://storage.googleapis.com/bucket/key
    if ($p['host'] === 'storage.googleapis.com') {
        $path = ltrim($p['path'], '/');
        $parts = explode('/', $path, 2);
        if (count($parts) === 2 && $parts[0] !== '') {
            return [$parts[0], $parts[1], null];
        }
        return [null, null, "bucket/key not found in path"];
    }

    return [null, null, "not a GCS url"];
}

/**
 * GCS URL / gs:// URI からHTML本文を取得
 * - 署名付きURLの有効期限に依存しない
 * - common.php の $google_application_credentials / $gcs_bucket を利用
 */
function download_text_from_gcs(string $url): array
{
    global $gcs_bucket;
    global $google_application_credentials;

    if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
        return [false, null, "google/cloud-storage が見つかりません"];
    }

    list($bucketFromUrl, $key, $perr) = parse_gcs_bucket_key_from_url($url);
    if ($key === null || $key === '') {
        return [false, null, "GCS URL解析に失敗: " . ($perr ?? "key empty")];
    }

    $bucket = $bucketFromUrl ?: $gcs_bucket;

    try {
        $storage = new \Google\Cloud\Storage\StorageClient(['keyFilePath' => $google_application_credentials]);
        $body = $storage->bucket($bucket)->object($key)->downloadAsString();
        return [true, $body, null];
    } catch (Exception $e) {
        return [false, null, "GCS getObject 失敗: " . $e->getMessage()];
    }
}

/**
 * HTML内の window.__CASH_AI_CTX__ の loginkey を置換
 */
function replace_cash_ai_ctx_loginkey(string $html, string $newLoginkey): array
{
    $ctxPattern = '/window\.__CASH_AI_CTX__\s*=\s*\{.*?\}\s*;/su';
    if (!preg_match($ctxPattern, $html, $m)) {
        return [false, $html, "window.__CASH_AI_CTX__ が見つかりません"];
    }

    $ctxBlock = $m[0];
    $replaceCount = 0;

    $replacedBlock = preg_replace_callback(
        '/([\{,]\s*)(["\']?loginkey["\']?\s*:\s*)(["\'])(.*?)(\3)/su',
        function ($mm) use ($newLoginkey, &$replaceCount) {
            $replaceCount++;
            return $mm[1] . $mm[2] . $mm[3] . addcslashes($newLoginkey, "\\\"'") . $mm[3];
        },
        $ctxBlock,
        1
    );

    if ($replaceCount < 1 || $replacedBlock === null) {
        return [false, $html, "window.__CASH_AI_CTX__ 内の loginkey を置換できませんでした"];
    }

    $newHtml = preg_replace($ctxPattern, $replacedBlock, $html, 1, $wholeReplaceCount);
    if ($wholeReplaceCount < 1 || $newHtml === null) {
        return [false, $html, "HTML全体への反映に失敗しました"];
    }

    return [true, $newHtml, null];
}

/**
 * GCSへHTMLを保存し、署名付きURLを返す
 * - common.php の $google_application_credentials / $gcs_bucket を利用
 */
function upload_html_and_create_presigned_url(string $key, string $html, string $expires = '+7 hours'): array
{
    global $gcs_bucket;
    global $google_application_credentials;

    if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
        return [false, null, null, "google/cloud-storage が見つかりません"];
    }

    try {
        $storage = new \Google\Cloud\Storage\StorageClient(['keyFilePath' => $google_application_credentials]);
        $bucket  = $storage->bucket($gcs_bucket);

        $bucket->upload($html, [
            'name'          => $key,
            'metadata'      => ['contentType' => 'text/html; charset=utf-8'],
        ]);

        $expiresAt  = new \DateTime($expires);
        $signedUrl  = $bucket->object($key)->signedUrl($expiresAt, ['version' => 'v4']);

        return [true, $signedUrl, $gcs_bucket, null];
    } catch (Exception $e) {
        return [false, null, null, "GCS upload/signedUrl 失敗: " . $e->getMessage()];
    }
}

function delete_gcs_object_by_url(string $url): array
{
    global $gcs_bucket;
    global $google_application_credentials;

    if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
        return [false, "google/cloud-storage が見つかりません"];
    }

    list($bucketFromUrl, $key, $perr) = parse_gcs_bucket_key_from_url($url);
    if ($key === null || $key === '') {
        return [false, "GCS URL解析に失敗: " . ($perr ?? "key empty")];
    }

    $bucket = $bucketFromUrl ?: $gcs_bucket;

    try {
        $storage = new \Google\Cloud\Storage\StorageClient(['keyFilePath' => $google_application_credentials]);
        $storage->bucket($bucket)->object($key)->delete();
        return [true, null];
    } catch (Exception $e) {
        return [false, "GCS deleteObject 失敗: " . $e->getMessage()];
    }
}


/** JSON読み込み */
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** タイミング計測用 */
$timing = [];
$totalStartMs = now_ms();

/** 認証（common.php流儀） */
$loginkey   = $data['loginkey'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;
$postingPeriod = $data['postingPeriod'] ?? null; // ★フロントから来る想定（無くてもOK）
$nodoai_orig = $data['nodoai'] ?? false; // ★ $data が後で $data2 で上書きされる前に nodoai を保持

if ($damy_flag != "OK") {
    if (isset($loginkey)) {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id === null) {
            http_response_code(403);
            echo json_encode([
                "status" => "error",
                "message" => "異常アクセス"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "loginkey がありません"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 案件が同じ会社のものか確認（他社の案件ならエラーで終了。_case_access_helper.php）
require_once __DIR__ . "/_case_access_helper.php";
ana_require_case_in_company($user_id ?? null, $ai_case_id, true, 403);


// ========================================
// nodoai モード
// - 既存HTMLを取得
// - window.__CASH_AI_CTX__.loginkey を現在の $loginkey に差し替え
// - 新しいGCSファイルを作成
// - 署名付きURLを ai_case.cf_main_html に保存
// - 旧HTMLファイルを削除
// ========================================
if (!empty($data['nodoai']) && $data['nodoai'] == true) {

    $ai_case_id = $data['ai_case_id'] ?? null;

    if (!$ai_case_id) {
        http_response_code(400);
        echo json_encode([
            "status" => "error",
            "message" => "ai_case_id がありません"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $conn->prepare("
        SELECT cf_main_html
        FROM ai_case
        WHERE ai_case_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "DB取得用SQLの準備に失敗しました",
            "detail" => $conn->error
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if (!$row = $result->fetch_assoc()) {
        $stmt->close();
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "ai_caseが見つかりません"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt->close();

    $oldUrl = $row['cf_main_html'] ?? '';
    if ($oldUrl === '') {
        http_response_code(404);
        echo json_encode([
            "status" => "error",
            "message" => "cf_main_html が空です"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 1) 既存HTML取得
    $tNodoaiDownload = now_ms();
    list($downloadOk, $htmlBody, $downloadErr) = download_text_from_gcs($oldUrl);
    $timing['nodoai_get_gcs_html_ms'] = elapsed_ms($tNodoaiDownload);

    if (!$downloadOk || !is_string($htmlBody) || $htmlBody === '') {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "既存の cf_main_html のGCS取得に失敗しました",
            "detail" => $downloadErr,
            "old_html" => $oldUrl,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 2) loginkey 差し替え
    $tNodoaiReplace = now_ms();
    list($replaceOk, $patchedHtml, $replaceErr) = replace_cash_ai_ctx_loginkey($htmlBody, (string)$loginkey);
    $timing['nodoai_replace_loginkey_ms'] = elapsed_ms($tNodoaiReplace);

    if (!$replaceOk || !is_string($patchedHtml) || $patchedHtml === '') {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "HTML内の loginkey 置換に失敗しました",
            "detail" => $replaceErr,
            "old_html" => $oldUrl,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 3) 新しいGCSキーを作成
    list($bucketFromUrl, $oldKey, $parseErr) = parse_gcs_bucket_key_from_url($oldUrl);
    if ($oldKey === null || $oldKey === '') {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "古い cf_main_html の GCSキー解析に失敗しました",
            "detail" => $parseErr,
            "old_html" => $oldUrl,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pathInfo = pathinfo($oldKey);
    $dir = ($pathInfo['dirname'] ?? '.') !== '.' ? trim($pathInfo['dirname'], '/') : '';
    $filename = $pathInfo['filename'] ?? ('ai_case_' . $ai_case_id);
    $ext = $pathInfo['extension'] ?? 'html';
    $newKey = ($dir !== '' ? $dir . '/' : '')
        . $filename . '_nodoai_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    // 4) 新HTMLをGCS保存し、署名付きURLを生成
    $tNodoaiUpload = now_ms();
    list($uploadOk, $newUrl, $savedBucket, $uploadErr) = upload_html_and_create_presigned_url($newKey, $patchedHtml, '+7 hours');
    $timing['nodoai_upload_html_ms'] = elapsed_ms($tNodoaiUpload);

    if (!$uploadOk || !is_string($newUrl) || $newUrl === '') {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "新しいHTMLファイルのGCS保存に失敗しました",
            "detail" => $uploadErr,
            "new_gcs_key" => $newKey,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 5) 新URLにアクセスできるか確認
    $tNodoaiCheck = now_ms();
    list($canAccess, $accessCode, $accessErr) = check_url_accessible($newUrl);
    $timing['nodoai_check_new_html_ms'] = elapsed_ms($tNodoaiCheck);
    $timing['nodoai_check_new_html_http_code'] = $accessCode;

    if (!$canAccess) {
        // 作成した新ファイルは不要なので削除を試みる
        delete_gcs_object_by_url($newUrl);

        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "新しいHTMLのURLにアクセス確認が取れませんでした",
            "detail" => $accessErr,
            "html" => $newUrl,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 6) DB更新
    $tNodoaiDbUpdate = now_ms();
    $stmtUpd = $conn->prepare("
        UPDATE ai_case
        SET cf_main_html = ?,
            update_user_id = ?
        WHERE ai_case_id = ?
        LIMIT 1
    ");

    if (!$stmtUpd) {
        delete_gcs_object_by_url($newUrl);
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "DB更新用SQLの準備に失敗しました",
            "detail" => $conn->error,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmtUpd->bind_param("sii", $newUrl, $user_id, $ai_case_id);
    $okUpd = $stmtUpd->execute();
    $stmtUpd->close();
    $timing['nodoai_db_update_ms'] = elapsed_ms($tNodoaiDbUpdate);

    if (!$okUpd) {
        delete_gcs_object_by_url($newUrl);
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "ai_case.cf_main_html の更新に失敗しました",
            "detail" => $conn->error,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 7) 古いHTML削除（新URLと異なる場合のみ）
    $deleteOldOk = true;
    $deleteOldErr = null;
    if ($oldUrl !== $newUrl) {
        $tNodoaiDeleteOld = now_ms();
        list($deleteOldOk, $deleteOldErr) = delete_gcs_object_by_url($oldUrl);
        $timing['nodoai_delete_old_html_ms'] = elapsed_ms($tNodoaiDeleteOld);
    }

    if (!$deleteOldOk) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "DB更新は成功しましたが、古いHTMLファイルの削除に失敗しました。手動確認してください。",
            "detail" => $deleteOldErr,
            "html" => $newUrl,
            "old_html" => $oldUrl,
            "_timing" => $timing
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
	sleep(25);
    http_response_code(200);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "success",
        "html" => $newUrl,
        "_cf_main_html_saved" => true,
        "_nodoai_mode" => true,
        "_old_html_deleted" => true,
        "_gcs_bucket" => $savedBucket,
        "_gcs_key" => $newKey,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


//ポイント操作======================

$stmt = $conn->prepare("
    SELECT pages_number
    FROM usede
    WHERE ai_case_id = ? AND p0 LIKE 'PDF読取%' ORDER BY usede_id DESC LIMIT 1
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$totalPages = 0;
while ($re = $result->fetch_assoc()) {
    $totalPages = $re['pages_number'];
}

$point = null;
$use_point = 0;
$user_id = null;
// loginkeyに対応するユーザー情報を取得
$stmt = $conn->prepare("
    SELECT u.username, u.email, u.create_at, u.point, u.user_id
    FROM users u
    JOIN login_history l ON u.user_id = l.user_id
    WHERE l.loginkey = ? AND u.status > 0 AND u.status < 999
    ORDER BY l.create_at DESC
    LIMIT 1
");
$stmt->bind_param("s", $loginkey);
$stmt->execute();
$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $point = $row['point'];
    $user_id = $row['user_id'];
}

$use_point = $cfpoint;

// ポイント残高チェックなし（point残高の概念を廃止）
// ポイント消費はCloud Run処理成功後に行うため、ここでは消費しない

$stmt = $conn->prepare("
    SELECT response,request,cf_main_html
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$response="";
$request="";
$cf_main_html="";
while ($re = $result->fetch_assoc()) {
    $response= $re['response'];
    $request= $re['request'];
	$cf_main_html= $re['cf_main_html'];
}
$response = json_decode($response, true);
$request = json_decode($request, true);
$i=0;
$data2=array();
$data2["BS"]=array();
$data2["PL"]=array();
if(isset($response["BS"])){
    foreach ($data['BS'] as $da) {
        $tmpobj=array();
        $tmpobj["勘定科目"]=$da["勘定科目"];
        $tmpobj["今期"]=array();
        $tmpobj["今期"]["金額"]=$da["今期"]["金額"];
        //$tmpobj["今期"]["page_no"]=$da["今期"]["page_no"];
        $tmpobj["前期"]=array();
        $tmpobj["前期"]["金額"]=$da["前期"]["金額"];
        //$tmpobj["前期"]["page_no"]=$da["前期"]["page_no"];
        $tmpobj["前々期"]=array();
        $tmpobj["前々期"]["金額"]=$da["前々期"]["金額"];
        //$tmpobj["前々期"]["page_no"]=$da["前々期"]["page_no"];
        foreach ($response["BS"] as $re) {    
            if($re["勘定科目"]==$da["勘定科目"]){
                if(isset($re["今期"]["page_no"])){
                    $tmpobj["今期"]["page_no"]=$re["今期"]["page_no"];
                }
                if(isset($re["前期"]["page_no"])){
                    $tmpobj["前期"]["page_no"]=$re["前期"]["page_no"];
                }
                if(isset($re["前々期"]["page_no"])){
                    $tmpobj["前々期"]["page_no"]=$re["前々期"]["page_no"];
                }
                $tmpobj["分類"]=$re["分類"];
            }
        }
        $data2["BS"][]=$tmpobj;
        $i++;
    }
}

$i=0;
if(isset($response["PL"])){
    foreach ($data['PL'] as $da) {
        $tmpobj=array();
        $tmpobj["勘定科目"]=$da["勘定科目"];
        $tmpobj["今期"]=array();
        $tmpobj["今期"]["金額"]=$da["今期"]["金額"];
        //$tmpobj["今期"]["page_no"]=$da["今期"]["page_no"];
        $tmpobj["前期"]=array();
        $tmpobj["前期"]["金額"]=$da["前期"]["金額"];
        //$tmpobj["前期"]["page_no"]=$da["前期"]["page_no"];
        $tmpobj["前々期"]=array();
        $tmpobj["前々期"]["金額"]=$da["前々期"]["金額"];
        //$tmpobj["前々期"]["page_no"]=$da["前々期"]["page_no"];
        foreach ($response["PL"] as $re) {    
            if($re["勘定科目"]==$da["勘定科目"]){
                if(isset($re["今期"]["page_no"])){
                    $tmpobj["今期"]["page_no"]=$re["今期"]["page_no"];
                }
                if(isset($re["前期"]["page_no"])){
                    $tmpobj["前期"]["page_no"]=$re["前期"]["page_no"];
                }
                if(isset($re["前々期"]["page_no"])){
                    $tmpobj["前々期"]["page_no"]=$re["前々期"]["page_no"];
                }
                $tmpobj["分類"]=$re["分類"];
            }
        }
        $data2["PL"][]=$tmpobj;
        $i++;
    }
}



// ===== 販売費（SGA）をマージ =====
$data2["販売費"] = array();
if (isset($response["販売費"])) {
    foreach ($data['SGA'] as $da) {
        $tmpobj = array();
        $tmpobj["勘定科目"] = $da["勘定科目"];
        $tmpobj["今期"] = array("金額" => $da["今期"]["金額"]);
        $tmpobj["前期"] = array("金額" => $da["前期"]["金額"]);
        $tmpobj["前々期"] = array("金額" => $da["前々期"]["金額"]);

        foreach ($response["販売費"] as $re) {
            if ($re["勘定科目"] === $da["勘定科目"]) {
                if (isset($re["今期"]["page_no"]))     $tmpobj["今期"]["page_no"]     = $re["今期"]["page_no"];
                if (isset($re["前期"]["page_no"]))     $tmpobj["前期"]["page_no"]     = $re["前期"]["page_no"];
                if (isset($re["前々期"]["page_no"]))  $tmpobj["前々期"]["page_no"]  = $re["前々期"]["page_no"];
                if (isset($re["分類"]))                $tmpobj["分類"]                = $re["分類"];
                break;
            }
        }
        $data2["販売費"][] = $tmpobj;
    }
} else {
    // response側に「販売費」が無ければ、入力そのままを保持
    $data2["販売費"] = $data['SGA'];
}

// ===== 製造原価（MFG）をマージ =====
$data2["製造原価"] = array();
if (isset($response["製造原価"])) {
    foreach ($data['MFG'] as $da) {
        $tmpobj = array();
        $tmpobj["勘定科目"] = $da["勘定科目"];
        $tmpobj["今期"] = array("金額" => $da["今期"]["金額"]);
        $tmpobj["前期"] = array("金額" => $da["前期"]["金額"]);
        $tmpobj["前々期"] = array("金額" => $da["前々期"]["金額"]);

        foreach ($response["製造原価"] as $re) {
            if ($re["勘定科目"] === $da["勘定科目"]) {
                if (isset($re["今期"]["page_no"]))     $tmpobj["今期"]["page_no"]     = $re["今期"]["page_no"];
                if (isset($re["前期"]["page_no"]))     $tmpobj["前期"]["page_no"]     = $re["前期"]["page_no"];
                if (isset($re["前々期"]["page_no"]))  $tmpobj["前々期"]["page_no"]  = $re["前々期"]["page_no"];
                if (isset($re["分類"]))                $tmpobj["分類"]                = $re["分類"];
                break;
            }
        }
        $data2["製造原価"][] = $tmpobj;
    }
} else {
    // response側に「製造原価」が無ければ、入力そのままを保持
    $data2["製造原価"] = $data['MFG'];
}
if(isset($response["page_sheet_map"])){
    $data["page_sheet_map"]=$response["page_sheet_map"];
}
$data2["postingPeriod"]=$request["postingPeriod"];
$data2['nodoai']=false;
$input=json_encode($data2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$data2['ai_case_id']=$ai_case_id;
$data = $data2;
/** サービスアカウント鍵 */
$serviceAccountJsonPath = "/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-f50b079b0584.json";

/** =========================
 *  0) Cloud Run呼び出し前：ステータスを「CF分析中」に更新
 *  AI診断済/進行中(5001/5000/7000/7200)なら 7099、それ以外は 7098
 *  ========================= */
$stmtPre = $conn->prepare("
    UPDATE ai_case
        SET status = IF(status IN ('5001','5000','7000','7200'), '7099', '7098'),
            update_user_id = ?
        WHERE ai_case_id = ?
        LIMIT 1
");
if ($stmtPre) {
    $stmtPre->bind_param("ii", $user_id, $ai_case_id);
    $stmtPre->execute();
    $stmtPre->close();
}

// 処理中(7098/7099)のまま終了した場合に自動でエラーステータスをセット
$_cashai_completed = false;
register_shutdown_function(function() use ($conn, $ai_case_id, $user_id, &$_cashai_completed) {
    $stmtErr = $conn->prepare("
        UPDATE ai_case SET status = '7006', update_at = now(), update_user_id = ?
        WHERE ai_case_id = ? AND status IN ('7098', '7099') LIMIT 1
    ");
    if ($stmtErr) {
        $stmtErr->bind_param("ii", $user_id, $ai_case_id);
        $stmtErr->execute();
        $stmtErr->close();
    }
    // 失敗履歴のusede記録は cash_ai_usepoint.php 側に移管したためここでは行わない
});

/** =========================
 *  1) cash-ai-01 を呼ぶ
 *  ========================= */
$service_url_01 = "https://ana-cash-ai-01-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_01 = "https://ana-cash-ai-01-real-512697354748.asia-northeast1.run.app";
}
$url_01 = rtrim($service_url_01, "/") . "/v1/pipeline";
$audience_01 = $service_url_01;

try {
    $t0 = now_ms();
    $idToken01 = getCloudRunIdToken($serviceAccountJsonPath, $audience_01);
    $timing["cash_ai_01_id_token_ms"] = elapsed_ms($t0);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "status" => "error",
        "message" => "Cloud Run(01) 用IDトークン生成に失敗しました",
        "detail" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$start01 = now_ms();
list($respBody01, $curlErrNo01, $curlErr01, $httpCode01) = postJsonToCloudRun($url_01, $idToken01, $data);
$timing["cash_ai_01_ms"] = elapsed_ms($start01);
$timing["cash_ai_01_http_code"] = $httpCode01;

if ($curlErrNo01) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(01) への接続に失敗しました",
        "curl_errno" => $curlErrNo01,
        "curl_error" => $curlErr01,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode01 === 401 || $httpCode01 === 403) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(01) に拒否されました（認証/権限を確認してください）",
        "cloud_run_http_code" => $httpCode01,
        "cloud_run_response" => $respBody01,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode01 >= 400) {
    http_response_code($httpCode01);
    // 可能なら timing を付けたいが、ここは元レスポンスを尊重してそのまま返す
    echo $respBody01;
    exit;
}

/** cash-ai-01 のJSONをdecode（result/dataを拾う） */
$cloud01 = json_decode($respBody01, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(01) がJSON以外を返しました",
        "cloud_run_response" => $respBody01,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$cashai01response = json_decode($respBody01, true);
$outData = [];
if (isset($cloud01["result"]) && is_array($cloud01["result"])) {
    $outData = $cloud01["result"];
} elseif (isset($cloud01["data"]) && is_array($cloud01["data"])) {
    $outData = $cloud01["data"];
} else {
    // 想定外でも落とさない
    $outData = $cloud01;
}

/** =========================
 *  2) ai_case から決算期年月日を取得
 *  ========================= */
$closing_dates = [
    "今期"   => "",
    "前期"   => "",
    "前々期" => ""
];

$dbStart = now_ms();
if (isset($ai_case_id) && $ai_case_id !== "") {
    $stmt = $conn->prepare("
        SELECT closing_date, closing_date_zenki, closing_date_zenzenki
        FROM ai_case
        WHERE ai_case_id = ?
        LIMIT 1
    ");
    if ($stmt) {
        $stmt->bind_param("i", $ai_case_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $closing_dates["今期"]   = $row["closing_date"] ?? "";
            $closing_dates["前期"]   = $row["closing_date_zenki"] ?? "";
            $closing_dates["前々期"] = $row["closing_date_zenzenki"] ?? "";
        }
        $stmt->close();
    }
}
$timing["db_ai_case_ms"] = elapsed_ms($dbStart);

/** =========================
 *  3) cash-ai-02 を呼ぶ（ここで loginkey と ai_case_id を必ずセット）
 *  ========================= */
$service_url_02 = "https://ana-cash-ai-02-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_02 = "https://ana-cash-ai-02-real-512697354748.asia-northeast1.run.app";
}
$url_02 = rtrim($service_url_02, "/") . "/v1/pipeline";
$audience_02 = $service_url_02;

try {
    $t0 = now_ms();
    $idToken02 = getCloudRunIdToken($serviceAccountJsonPath, $audience_02);
    $timing["cash_ai_02_id_token_ms"] = elapsed_ms($t0);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(02) 用IDトークン生成に失敗しました",
        "detail" => $e->getMessage(),
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * cash-ai-02 へのpayload
 * - loginkey と ai_case_id を必ず入れる（要件）
 * - 決算期年月日 と data は cash-ai-01 + DB から作る
 */

$payload02 = [
    "loginkey" => $loginkey,
    "ai_case_id" => $ai_case_id,
    "port" => $port, // common.php で定義済みの $port を送る
    "決算期年月日" => $closing_dates,
    "data" => $outData
];

$start02 = now_ms();
list($respBody02, $curlErrNo02, $curlErr02, $httpCode02) = postJsonToCloudRun($url_02, $idToken02, $payload02);
$timing["cash_ai_02_ms"] = elapsed_ms($start02);
$timing["cash_ai_02_http_code"] = $httpCode02;

if ($curlErrNo02) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(02) への接続に失敗しました",
        "curl_errno" => $curlErrNo02,
        "curl_error" => $curlErr02,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode02 === 401 || $httpCode02 === 403) {
    http_response_code(502);
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(02) に拒否されました（認証/権限を確認してください）",
        "cloud_run_http_code" => $httpCode02,
        "cloud_run_response" => $respBody02,
        "_timing" => $timing
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode02 >= 400) {
    http_response_code($httpCode02);
    echo $respBody02;
    exit;
}

/** =========================
 *  3.5) cash-ai-04 を呼んで html を patched_url に置換する
 *  ========================= */
$resp02Decoded = json_decode($respBody02, true);
$resp02Decoded["_debug_payload02_json"] = $payload02;
$resp02Decoded["cashai01response"] = $cashai01response;
$resp02Decoded["url_01"] = $url_01;
$resp02Decoded["inputdata"] = $data;
if (json_last_error() === JSON_ERROR_NONE && is_array($resp02Decoded)) {

    $htmlUrl = $resp02Decoded["html"] ?? null;

    if (is_string($htmlUrl) && $htmlUrl !== "") {

        $service_url_04 = "https://ana-cash-ai-04-512697354748.asia-northeast1.run.app";
		if($port=="8012"){
			$service_url_04 = "https://ana-cash-ai-04-real-512697354748.asia-northeast1.run.app";
		}
        $url_04 = rtrim($service_url_04, "/"); // cash-ai-04 はルートで受ける想定
        $audience_04 = $service_url_04;

        try {
            $t0 = now_ms();
            $idToken04 = getCloudRunIdToken($serviceAccountJsonPath, $audience_04);
            $timing["cash_ai_04_id_token_ms"] = elapsed_ms($t0);

            $payload04 = [
                "ai_case_id" => $ai_case_id,
                "loginkey" => $loginkey,
                "postingPeriod" => $postingPeriod,
				"port" => $port, // common.php で定義済みの $port を送る
				"決算期年月日" => $closing_dates,
                "html" => $htmlUrl
            ];

            $start04 = now_ms();
            list($respBody04, $curlErrNo04, $curlErr04, $httpCode04) = postJsonToCloudRun($url_04, $idToken04, $payload04, 300);
            $timing["cash_ai_04_ms"] = elapsed_ms($start04);
            $timing["cash_ai_04_http_code"] = $httpCode04;

            if (!$curlErrNo04 && $httpCode04 < 400) {
                $resp04Decoded = json_decode($respBody04, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($resp04Decoded)) {
                    $patchedUrl = $resp04Decoded["patched_url"] ?? null;
                    if (is_string($patchedUrl) && $patchedUrl !== "") {
                        // ★置換
                        $resp02Decoded["html"] = $patchedUrl;

                        // 任意：デバッグ情報
                        $resp02Decoded["_cash_ai_04"] = [
                            "ok" => true,
                            "patched_gcs_bucket" => $resp04Decoded["patched_gcs_bucket"] ?? null,
                            "patched_gcs_key" => $resp04Decoded["patched_gcs_key"] ?? null
                        ];
                    } else {
                        $resp02Decoded["_cash_ai_04"] = [
                            "ok" => false,
                            "error" => "patched_url not found",
                            "cloud_run_http_code" => $httpCode04,
                            "cloud_run_response" => $respBody04
                        ];
                    }
                } else {
                    $resp02Decoded["_cash_ai_04"] = [
                        "ok" => false,
                        "error" => "cash-ai-04 returned non-JSON",
                        "cloud_run_http_code" => $httpCode04,
                        "cloud_run_response" => $respBody04
                    ];
                }
            } else {
                $resp02Decoded["_cash_ai_04"] = [
                    "ok" => false,
                    "error" => "cash-ai-04 request failed",
                    "curl_errno" => $curlErrNo04,
                    "curl_error" => $curlErr04,
                    "cloud_run_http_code" => $httpCode04,
                    "cloud_run_response" => $respBody04
                ];
            }

        } catch (Exception $e) {
            $resp02Decoded["_cash_ai_04"] = [
                "ok" => false,
                "error" => "cash-ai-04 id_token failed",
                "detail" => $e->getMessage()
            ];
        }
    }
}


/** =========================
 *  3.6) html を ai_case.cf_main_html に保存（要件）
 *  - 保存前に新URLへアクセスできるか確認（できなければエラー）
 *  - 更新前に古い cf_main_html があれば、そのURLのGCSファイルを削除（削除失敗ならエラー）
 *  - 削除成功後、新URLで cf_main_html を更新
 *  ========================= */
if (is_array($resp02Decoded) && isset($ai_case_id) && $ai_case_id !== "") {

    $newHtml = $resp02Decoded["html"] ?? null;

    if (is_string($newHtml) && $newHtml !== "") {

        // 1) 新URLにアクセスできるか確認
        $tCheck = now_ms();
        list($canAccess, $accessCode, $accessErr) = check_url_accessible($newHtml, 15);
        $timing["cf_main_html_check_ms"] = elapsed_ms($tCheck);
        $timing["cf_main_html_check_http_code"] = $accessCode;

        if (!$canAccess) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "HTMLのURLにアクセス確認が取れませんでした。再度実行してください。",
                "detail" => $accessErr,
                "html" => $newHtml,
                "_timing" => $timing
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        // 2) 古い cf_main_html を取得
        $oldHtml = "";
        $tDbGet = now_ms();
        $stmtGet = $conn->prepare("
            SELECT cf_main_html
            FROM ai_case
            WHERE ai_case_id = ?
            LIMIT 1
        ");
        if ($stmtGet) {
            $stmtGet->bind_param("i", $ai_case_id);
            $stmtGet->execute();
            $res = $stmtGet->get_result();
            if ($row = $res->fetch_assoc()) {
                $oldHtml = $row["cf_main_html"] ?? "";
            }
            $stmtGet->close();
        }
        $timing["cf_main_html_db_get_ms"] = elapsed_ms($tDbGet);

        // 3) 古いURLがあれば削除（同じURLなら削除しない）
        if (is_string($oldHtml) && $oldHtml !== "" && $oldHtml !== $newHtml) {

            // 念のため：GCS URL 以外は削除しない（事故防止）
            $host = parse_url($oldHtml, PHP_URL_HOST);
            $isGcs = (is_string($host) && $host === 'storage.googleapis.com')
                  || (strpos($oldHtml, 'gs://') === 0);
            if (!$isGcs) {
                http_response_code(500);
                echo json_encode([
                    "status" => "error",
                    "message" => "古いHTMLのURLがGCS形式ではないため削除できません。手動で確認してください。",
                    "old_html" => $oldHtml,
                    "_timing" => $timing
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $tDel = now_ms();
            list($delOk, $delErr) = delete_gcs_object_by_url($oldHtml);
            $timing["cf_main_html_gcs_delete_ms"] = elapsed_ms($tDel);

            if (!$delOk) {
                http_response_code(500);
                echo json_encode([
                    "status" => "error",
                    "message" => "古いHTMLファイルの削除に失敗しました。再度実行してください。",
                    "detail" => $delErr,
                    "old_html" => $oldHtml,
                    "_timing" => $timing
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }

        // 4) 新URLでUPDATE
        $tDbUpd = now_ms();

        // payload02.data を {"data":[...]} の形で ai_case.cf_data に保存
        $cfDataObj = [
            "data" => $payload02["data"] ?? []
        ];

        $cfDataJson = json_encode(
            $cfDataObj,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($cfDataJson === false) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "cf_data 用JSONの生成に失敗しました",
                "detail" => json_last_error_msg(),
                "_timing" => $timing
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtUpd = $conn->prepare("
            UPDATE ai_case
            SET cf_main_html = ?,
                cf_data = ?,
                status = IF(status IN ('7099','7000','7200'), '7200', '7100'),
                update_at = now(),
                update_user_id = ?
            WHERE ai_case_id = ?
            LIMIT 1
        ");
        if (!$stmtUpd) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "DB更新用SQLの準備に失敗しました",
                "detail" => $conn->error,
                "_timing" => $timing
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $stmtUpd->bind_param("ssii", $newHtml, $cfDataJson, $user_id, $ai_case_id);
        $okUpd = $stmtUpd->execute();
        $stmtUpd->close();
        $timing["cf_main_html_db_update_ms"] = elapsed_ms($tDbUpd);

        if (!$okUpd) {
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "cf_main_html / cf_data の更新に失敗しました",
                "detail" => $conn->error,
                "_timing" => $timing
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $resp02Decoded["_cf_main_html_saved"] = true;
        $resp02Decoded["_cf_data_saved"] = true;
    }
}


/** total time */
$timing["total_ms"] = elapsed_ms($totalStartMs);

/** =========================
 *  3.7) 処理完了：ステータスを確実に 7099→7100 or 7000→7200 に更新
 *  （HTMLのURL確認失敗など 3.6) がスキップされた場合もここで必ず更新する）
 *  ========================= */
$stmtFinal = $conn->prepare("
    UPDATE ai_case
        SET status = IF(status IN ('7099','7000','7200'), '7200', '7100'),
            update_at = now(),
            update_user_id = ?
        WHERE ai_case_id = ?
          AND status NOT IN ('7100', '7200')
        LIMIT 1
");
if ($stmtFinal) {
    $stmtFinal->bind_param("ii", $user_id, $ai_case_id);
    $stmtFinal->execute();
    $stmtFinal->close();
}

/** =========================
 *  3.8) nodoai==false の場合のみ cash_ai_start=1 をセット
 *  （ポイント消費とusede追加は cash_ai_usepoint.php で実施）
 *  ========================= */
if ($nodoai_orig === false) {
    $stmtStart = $conn->prepare("
        UPDATE ai_case
            SET cash_ai_start = 1, update_at = NOW()
            WHERE ai_case_id = ?
    ");
    $stmtStart->bind_param("i", $ai_case_id);
    $stmtStart->execute();
    $stmtStart->close();
}

/** レスポンスに timing を埋め込む */
if (json_last_error() === JSON_ERROR_NONE && is_array($resp02Decoded)) {
    $resp02Decoded["_timing"] = $timing;
	$resp02Decoded["status"]="success";
    $respBody02 = json_encode($resp02Decoded, JSON_UNESCAPED_UNICODE);
} else {
    // cash-ai-02 のレスポンスがJSONじゃない場合は timing を付けられないので、そのまま返す
}

/** =========================
 *  4) 返却：html を patched_url に置換済み + _timing 追加済みのレスポンス
 *  ========================= */
http_response_code(200);
header("Content-Type: application/json; charset=utf-8");
$_cashai_completed = true;

write_system_log($conn, 'info', 'API', 'AI_API_CALLED', 'CF分析AIが完了しました',
    user_id:    $user_id ?? null,
    ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
    detail:     ['ai_case_id' => $ai_case_id, 'total_ms' => $timing['total_ms'] ?? null]
);

sleep(25);
echo $respBody02;