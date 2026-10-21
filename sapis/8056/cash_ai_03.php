<?php
/**
 * cash_ai_03_optimized.php
 *
 * フロントから来たリクエスト(JSON: cash_ai03リクエスト.json 形式)を受け取り、
 * common.php の方式で loginkey を検証した上で、
 *
 * 追加要件（今回）:
 *   1) cash-ai-02 -> cash-ai-04 を先に呼び出し、html(=patched_url) を取得して $data["html"] にセット
 *   2) その後、既存どおり cash-ai-03 (mode=201) を呼び、結果の url を使って cash-ai-05 を呼ぶ
 *
 * payload02 について:
 *   - フロントのサンプルと同じく、data は必ず $data["data"] をそのまま渡す（ここが最重要）
 *   - cash_ai.php と同等の互換のため、port / 決算期年月日 も追加で渡す（cash-ai-02 側が必要とする場合があるため）
 */ 

include "./common.php";

global $damy_flag;
global $conn;
global $port;

// composer autoload（google/auth, aws sdk を使う）
require_once __DIR__ . '/vendor/autoload.php';

use Google\Auth\ApplicationDefaultCredentials;

/** Cloud Run の IDトークン取得 */
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

/**
 * JSONをPOSTしてレスポンスを返す
 * return: [$respBody, $curlErrNo, $curlErr, $httpCode, $respContentType]
 */
function postJsonToCloudRun(
    string $url,
    string $idToken,
    array $payload,
    int $timeoutSec = 3600,
    int $maxAttempts = 3,
    int $baseDelayMs = 3000
): array
{
    // NOTE:
    // - $maxAttempts は「合計試行回数」です（例: 3なら最大3回=初回+リトライ2回）
    // - 401/403/4xx(429除く)は原則リトライしません
    // - 200/201でもJSONの中身がエラーっぽい場合はリトライします

    $attempt = 0;
    $lastRespBody = "";
    $lastCurlErrNo = 0;
    $lastCurlErr = "";
    $lastHttpCode = 0;
    $lastRespContentType = "";

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

        // レスポンスヘッダも取って Content-Type を拾う
        curl_setopt($ch, CURLOPT_HEADER, true);

        $resp = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($resp === false) {
            $lastRespBody = "";
            $lastCurlErrNo = $curlErrNo;
            $lastCurlErr = $curlErr;
            $lastHttpCode = $httpCode;
            $lastRespContentType = "";
        } else {
            $respHeader = substr($resp, 0, $headerSize);
            $respBody   = substr($resp, $headerSize);

            $respContentType = "";
            if (preg_match('/^Content-Type:\s*([^\r\n;]+)/mi', $respHeader, $m)) {
                $respContentType = trim($m[1]);
            }

            $lastRespBody = $respBody;
            $lastCurlErrNo = $curlErrNo;
            $lastCurlErr = $curlErr;
            $lastHttpCode = $httpCode;
            $lastRespContentType = $respContentType;
        }

        // リトライ判定
        $shouldRetry = false;

        if ($lastCurlErrNo !== 0 || $resp === false) {
            $shouldRetry = true;
        } else {
            if ($lastHttpCode === 0) {
                $shouldRetry = true;
            } elseif ($lastHttpCode === 429) {
                $shouldRetry = true;
            } elseif ($lastHttpCode >= 500) {
                $shouldRetry = true;
            } elseif ($lastHttpCode === 200 || $lastHttpCode === 201) {
                // 200/201でも中身がエラーのケースを判定
                $trim = ltrim($lastRespBody);
                $isJsonLike = ($trim !== "" && ($trim[0] === '{' || $trim[0] === '[')) ||
                              (stripos($lastRespContentType, "application/json") !== false);

                if ($isJsonLike) {
                    $j = json_decode($lastRespBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
                        $status = strtolower((string)($j["status"] ?? ""));
                        $ok = $j["ok"] ?? null;
                        $hasErrorKey = array_key_exists("error", $j) || array_key_exists("errors", $j);
                        $msg = strtolower((string)($j["message"] ?? $j["detail"] ?? ""));
                        if ($status === "error" || $ok === false || $hasErrorKey || (strpos($msg, "error") !== false)) {
                            $shouldRetry = true;
                        }
                    } else {
                        // JSONとして壊れている場合はリトライ
                        $shouldRetry = true;
                    }
                } else {
                    // HTMLなど想定外の場合は、ここでは一旦リトライ対象にする
                    $shouldRetry = true;
                }
            } else {
                $shouldRetry = false;
            }
        }

        if (!$shouldRetry || $attempt >= $maxAttempts) {
            break;
        }

        $delay = $baseDelayMs * (1 << ($attempt - 1));
        $jitter = random_int(0, 120);
        usleep(($delay + $jitter) * 1000);
    }

    return [$lastRespBody, $lastCurlErrNo, $lastCurlErr, $lastHttpCode, $lastRespContentType];
}

/**
 * 署名付きURLの存在確認（HEADはメソッド署名不一致で落ちることがあるため、GETのRangeで軽量確認）
 * return: [bool ok, int http_code, ?string error]
 */
function check_url_accessible_range_get(string $url, int $timeoutSec = 15): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    // 先頭だけ取る（軽量）
    curl_setopt($ch, CURLOPT_RANGE, "0-16");

    curl_exec($ch);

    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo) {
        return [false, $code ?: 0, "curl_error: {$err}"];
    }
    if ($code >= 200 && $code < 400) {
        return [true, $code, null];
    }
    // S3はRange GET成功で 206 を返すこともある
    if ($code === 206) {
        return [true, $code, null];
    }
    return [false, $code, "http_code: {$code}"];
}

/**
 * S3署名付きURLなどから key を抽出（/bucket/key 形式や bucket.host 形式にも対応）
 * 今回の運用URL: https://zlite.s3.amazonaws.com/<key>?...
 * return: [string|null key, string|null error]
 */
function extract_s3_key_from_url(string $url): array
{
    $p = parse_url($url);
    if (!$p || empty($p['path'])) {
        return [null, "invalid url/path"];
    }
    $path = ltrim($p['path'], '/');
    if ($path === "") {
        return [null, "empty key"];
    }

    // path-style の場合: https://s3.amazonaws.com/<bucket>/<key>
    // もし path の先頭が bucket 名と一致していたら除去する（念のため）
    global $s3_bucket_name;
    if (!empty($s3_bucket_name)) {
        $prefix = $s3_bucket_name . "/";
        if (strpos($path, $prefix) === 0) {
            $path = substr($path, strlen($prefix));
        }
    }

    return [$path, null];
}

/**
 * common.php から import される S3キーで削除
 * return: [bool ok, ?string error]
 */
function delete_s3_object_by_url_using_common_keys(string $url): array
{
    global $s3_access_key;
    global $s3_secret_key;
    global $s3_region;
    global $s3_bucket_name;

    if (!class_exists(\Aws\S3\S3Client::class)) {
        return [false, "AWS SDK for PHP (aws/aws-sdk-php) が見つかりません"];
    }

    list($key, $kerr) = extract_s3_key_from_url($url);
    if (!$key) {
        return [false, "S3 key 抽出に失敗: " . ($kerr ?? "unknown")];
    }

    if (empty($s3_bucket_name) || empty($s3_region) || empty($s3_access_key) || empty($s3_secret_key)) {
        return [false, "S3接続情報（bucket/region/access/secret）が不足しています（common.php を確認）"];
    }

    try {
        $s3 = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region'  => $s3_region,
            'credentials' => [
                'key'    => $s3_access_key,
                'secret' => $s3_secret_key,
            ],
        ]);

        $s3->deleteObject([
            'Bucket' => $s3_bucket_name,
            'Key'    => $key,
        ]);

        return [true, null];
    } catch (Exception $e) {
        return [false, "S3 deleteObject 失敗: " . $e->getMessage()];
    }
}


/** =========================
 *  入力JSONの読み込み
 *  ========================= */
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** =========================
 *  ユーザ認証（common.php流儀）
 *  ========================= */
$loginkey = $data['loginkey'] ?? null;

if ($damy_flag != "OK") {
    if (!isset($loginkey) || $loginkey === "") {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "error",
            "message" => "loginkey がありません"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user_id = get_user_id_for_now($loginkey);
    if ($user_id === null) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            "status" => "error",
            "message" => "異常アクセス"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$ai_case_id = $data['ai_case_id'] ?? null;
$postingPeriod = $data['postingPeriod'] ?? null;

/** =========================
 *  DBから financial_response を取得して data にセット（既存仕様維持）
 *  ========================= */
$data['financial_response'] = null;

if (!empty($ai_case_id)) {
    $stmt = $conn->prepare("
        SELECT financial_response
        FROM ai_case
        WHERE ai_case_id = ?
        LIMIT 1
    ");

    if ($stmt) {
        $stmt->bind_param("i", $ai_case_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($row = $result->fetch_assoc()) {
            $financial_response_raw = $row['financial_response'];

            if (!empty($financial_response_raw)) {
                $decodedFinancial = json_decode($financial_response_raw, true);
                if (
                    json_last_error() === JSON_ERROR_NONE &&
                    is_array($decodedFinancial) &&
                    isset($decodedFinancial['response']) &&
                    is_array($decodedFinancial['response'])
                ) {
                    $data['financial_response'] = $decodedFinancial['response'];
                }
            }
        }
        $stmt->close();
    }
}

/** =========================
 *  (追加) ai_case から決算期年月日を取得（cash-ai-02 互換用）
 *  ========================= */
$closing_dates = [
    "今期"   => "",
    "前期"   => "",
    "前々期" => ""
];

if (!empty($ai_case_id)) {
    $stmt2 = $conn->prepare("
        SELECT closing_date, closing_date_zenki, closing_date_zenzenki
        FROM ai_case
        WHERE ai_case_id = ?
        LIMIT 1
    ");
    if ($stmt2) {
        $stmt2->bind_param("i", $ai_case_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result();
        if ($row2 = $res2->fetch_assoc()) {
            $closing_dates["今期"]   = $row2["closing_date"] ?? "";
            $closing_dates["前期"]   = $row2["closing_date_zenki"] ?? "";
            $closing_dates["前々期"] = $row2["closing_date_zenzenki"] ?? "";
        }
        $stmt2->close();
    }
}

/** サービスアカウント鍵（cash_ai.php と同じ想定） */
$serviceAccountJsonPath = "/srv/www/z-lite_backend/internal/gen-lang-client-0018414550-f50b079b0584.json";

/** =========================
 *  1) cash-ai-02 を呼ぶ（html を取得）
 *  ========================= */
if (empty($ai_case_id)) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "ai_case_id がありません"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($data['data']) || !is_array($data['data'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "data がありません（cash_ai03リクエスト.json と同じ形式で送ってください）"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$service_url_02 = "https://ana-cash-ai-02-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_02 = "https://ana-cash-ai-02-real-512697354748.asia-northeast1.run.app";
}
$url_02 = rtrim($service_url_02, "/") . "/v1/pipeline";
$audience_02 = $service_url_02;

try {
    $idToken02 = getCloudRunIdToken($serviceAccountJsonPath, $audience_02);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(02) 用IDトークン生成に失敗しました",
        "detail" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ★重要: data は必ず $data["data"] をそのまま渡す
$payload02 = [
    "ai_case_id" => $ai_case_id,
    "loginkey" => $loginkey,
    "postingPeriod" => $postingPeriod,
    "data" => $data["data"],
    // 互換用（cash_ai.php と揃える。cash-ai-02 側が見ていなければ無視されます）
    "port" => $port,
    "決算期年月日" => $closing_dates
];

list($respBody02, $curlErrNo02, $curlErr02, $httpCode02, $respContentType02) = postJsonToCloudRun($url_02, $idToken02, $payload02);

if ($curlErrNo02) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(02) への接続に失敗しました",
        "curl_errno" => $curlErrNo02,
        "curl_error" => $curlErr02
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode02 >= 400) {
    http_response_code($httpCode02);
    header('Content-Type: application/json; charset=utf-8');
    echo $respBody02;
    exit;
}

$respJson02 = json_decode($respBody02, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respJson02)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "cash-ai-02 のレスポンスがJSONではありません",
        "raw" => $respBody02
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$htmlUrl02 = $respJson02["html"] ?? null;
if (empty($htmlUrl02) || !is_string($htmlUrl02)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "cash-ai-02 のレスポンスに html がありません",
        "cash_ai_02" => $respJson02
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** =========================
 *  2) cash-ai-04 を呼ぶ（html を patched_url に置換）
 *  ========================= */
$service_url_04 = "https://ana-cash-ai-04-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_04 = "https://ana-cash-ai-04-real-512697354748.asia-northeast1.run.app";
}
$url_04 = rtrim($service_url_04, "/");
$audience_04 = $service_url_04;

try {
    $idToken04 = getCloudRunIdToken($serviceAccountJsonPath, $audience_04);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(04) 用IDトークン生成に失敗しました",
        "detail" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload04 = [
    "ai_case_id" => $ai_case_id,
    "loginkey" => $loginkey,
    "postingPeriod" => $postingPeriod,
    "html" => $htmlUrl02
];

list($respBody04, $curlErrNo04, $curlErr04, $httpCode04, $respContentType04) = postJsonToCloudRun($url_04, $idToken04, $payload04, 300);

if ($curlErrNo04) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(04) への接続に失敗しました",
        "curl_errno" => $curlErrNo04,
        "curl_error" => $curlErr04
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode04 >= 400) {
    http_response_code($httpCode04);
    header('Content-Type: application/json; charset=utf-8');
    echo $respBody04;
    exit;
}

$respJson04 = json_decode($respBody04, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respJson04)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "cash-ai-04 のレスポンスがJSONではありません",
        "raw" => $respBody04
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$patchedUrl = $respJson04["patched_url"] ?? null;
if (empty($patchedUrl) || !is_string($patchedUrl)) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "cash-ai-04 のレスポンスに patched_url がありません",
        "cash_ai_04" => $respJson04
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// (任意) 置換後URLのアクセス確認（HEADではなくGET Range）
list($okAccess, $accessCode, $accessErr) = check_url_accessible_range_get($patchedUrl, 15);
if (!$okAccess) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "HTMLのURLにアクセス確認が取れませんでした。再度実行してください。",
        "detail" => $accessErr,
        "http_code" => $accessCode,
        "html" => $patchedUrl
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// cash-ai-03 に渡す入力へ html をセット
$data["html"] = $patchedUrl;

/** =========================
 *  3) cash-ai-03（既存仕様維持: mode=201）
 *  ========================= */
$data['mode'] = "201";
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
$user_mail = null;
if ($damy_flag != "OK") {
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
} else {
    // ダミーモード時は isplit.jp 扱い（必要なら変更）
    $user_mail = "dummy@isplit.jp";
}

$kousya_value = ""; // デフォルト

if (!empty($user_mail)) {
    $domain = substr(strrchr($user_mail, "@"), 1);

    if ($domain === "isplit.jp") {
        $kousya_value = "kousya";
    }
}
$data['kousya'] = $kousya_value;
$service_url_03 = "https://ana-cash-ai-03-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_03 = "https://ana-cash-ai-03-real-512697354748.asia-northeast1.run.app";
}
$url_03 = rtrim($service_url_03, "/") . "/v1/pipeline";
$audience_03 = $service_url_03;

try {
    $idToken03 = getCloudRunIdToken($serviceAccountJsonPath, $audience_03);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "error",
        "message" => "Cloud Run(03) 用IDトークン生成に失敗しました",
        "detail" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

list($respBody03, $curlErrNo03, $curlErr03, $httpCode03, $respContentType03) = postJsonToCloudRun($url_03, $idToken03, $data);

if ($curlErrNo03) {
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run(03) への接続に失敗しました",
        "curl_errno" => $curlErrNo03,
        "curl_error" => $curlErr03
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ここから: cash-ai-03 のレスポンスを解析し、cash-ai-05 も呼び出して統合レスポンスを返す（既存仕様維持）
 */
$respJson03 = json_decode($respBody03, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respJson03)) {
    // cash-ai-03 が JSON を返さなかった場合は、そのまま返す（互換維持）
    http_response_code($httpCode03);
    if (!empty($respContentType03)) {
        header('Content-Type: ' . $respContentType03 . '; charset=utf-8');
    } else {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo $respBody03;
    exit;
}

/**
 * cash-ai-03 の戻りは多くの場合 { "result": { ... } } 形式なので両対応する
 */
$result03 = $respJson03['result'] ?? $respJson03;

$xlsxUrlFrom03 = $result03['url'] ?? null;

// cash-ai-03 追加出力 (excel1_5) のURL
$xlsxUrlFrom03_15 = $result03['excel1_5']['url'] ?? null;

// ai_case_id は (1) 入力 (2) cash-ai-03 result の順で継承
$aiCaseId = $data['ai_case_id'] ?? ($result03['ai_case_id'] ?? null);

if (empty($xlsxUrlFrom03) || empty($aiCaseId)) {
    http_response_code($httpCode03);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "cash_ai_02" => $respJson02,
        "cash_ai_04" => $respJson04,
        "cash_ai_03" => $respJson03
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** =========================
 *  4) cash-ai-05（既存仕様維持）
 *  ========================= */
$service_url_05 = "https://ana-cash-ai-05-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url_05 = "https://ana-cash-ai-05-real-512697354748.asia-northeast1.run.app";
}
$url_05 = rtrim($service_url_05, "/") . "/v1/pipeline";
$audience_05 = $service_url_05;

try {
    $idToken05 = getCloudRunIdToken($serviceAccountJsonPath, $audience_05);
} catch (Exception $e) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "partial_error",
        "message" => "cash-ai-05 用IDトークン生成に失敗しました",
        "ai_case_id" => $aiCaseId,
        "cash_ai_02" => $respJson02,
        "cash_ai_04" => $respJson04,
        "cash_ai_03" => $respJson03,
        "cash_ai_05_error" => [
            "detail" => $e->getMessage()
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// cash-ai-05 へのpayloadを作る（元の入力を基本引き継ぎ、mode/url/ai_case_id を上書き）
$payload05 = $data;
$payload05['mode'] = "both";
$payload05['ai_case_id'] = $aiCaseId;

$payload05['url'] = $xlsxUrlFrom03;
if($kousya_value === "kousya"){
	$payload05['url'] = $xlsxUrlFrom03_15;
}
list($respBody05, $curlErrNo05, $curlErr05, $httpCode05, $respContentType05) = postJsonToCloudRun($url_05, $idToken05, $payload05);

if ($curlErrNo05) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "partial_error",
        "message" => "cash-ai-05 への接続に失敗しました",
        "ai_case_id" => $aiCaseId,
        "cash_ai_02" => $respJson02,
        "cash_ai_04" => $respJson04,
        "cash_ai_03" => $respJson03,
        "cash_ai_05_error" => [
            "curl_errno" => $curlErrNo05,
            "curl_error" => $curlErr05
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$respJson05 = json_decode($respBody05, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respJson05)) {
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "partial_error",
        "message" => "cash-ai-05 のレスポンスがJSONではありません",
        "ai_case_id" => $aiCaseId,
        "cash_ai_02" => $respJson02,
        "cash_ai_04" => $respJson04,
        "cash_ai_03" => $respJson03,
        "cash_ai_05_raw" => $respBody05
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// cash-ai-05 から返ってくる2つのURL（想定: results[].html_url）を抽出
$cashAi05Urls = [];
if (isset($respJson05['results']) && is_array($respJson05['results'])) {
    foreach ($respJson05['results'] as $item) {
        if (is_array($item) && !empty($item['html_url'])) {
            $cashAi05Urls[] = $item['html_url'];
        }
    }
}
// 互換のため、キー名揺れにも対応
foreach (['url_141', 'url_142', 'html_url_141', 'html_url_142'] as $k) {
    if (!empty($respJson05[$k])) {
        $cashAi05Urls[] = $respJson05[$k];
    }
}
$cashAi05Urls = array_values(array_unique($cashAi05Urls));

// ===================================================
// 5) URLアクセス軽量チェック → 旧ファイル削除 → DB更新
// ===================================================

// 新URL群
$new_main  = $patchedUrl ?? "";
$new_left  = $cashAi05Urls[0] ?? "";
$new_right = $cashAi05Urls[1] ?? "";
$new_excel = $xlsxUrlFrom03 ?? "";
$new_excel2 = $xlsxUrlFrom03_15 ?? "";

// 1) まず新URLがアクセス可能か軽量チェック（署名付きURLはHEADで落ちることがあるのでRange GET）
$checkTargets = [
    "cf_main_html"  => $new_main,
    "cf_left_html"  => $new_left,
    "cf_right_html" => $new_right,
    "cf_exel01"     => $new_excel,
    "cf_exel02"     => $new_excel2,
];

$checkResult = [];
foreach ($checkTargets as $name => $u) {
    if (is_string($u) && $u !== "") {
        list($ok, $code, $err) = check_url_accessible_range_get($u, 15);
        $checkResult[$name] = ["ok" => $ok, "http_code" => $code, "error" => $err];
        if (!$ok) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "ok" => false,
                "status" => "error",
                "message" => "生成されたURLにアクセス確認が取れませんでした。再度実行してください。",
                "failed" => $name,
                "detail" => $checkResult[$name],
                "urls" => [
                    "cf_main_html" => $new_main,
                    "cf_left_html" => $new_left,
                    "cf_right_html" => $new_right,
                    "cf_exel01" => $new_excel,
                    "cf_exel02" => $new_excel2
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        // 空は空として記録（05が片側しか返さないなど）
        $checkResult[$name] = ["ok" => true, "http_code" => null, "error" => null, "note" => "empty"];
    }
}

// 2) 旧URLを取得（空/NULLなら削除しない）
$old = [
    "cf_main_html"  => "",
    "cf_left_html"  => "",
    "cf_right_html" => "",
    "cf_exel01"     => "",
    "cf_exel02"     => "",
];

$stmtOld = $conn->prepare("
    SELECT cf_main_html, cf_left_html, cf_right_html, cf_exel01, cf_exel02
    FROM ai_case
    WHERE ai_case_id = ?
    LIMIT 1
");
if (!$stmtOld) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "error",
        "message" => "DBのSELECT準備に失敗しました",
        "detail" => $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmtOld->bind_param("i", $aiCaseId);
$stmtOld->execute();
$resOld = $stmtOld->get_result();
if ($rowOld = $resOld->fetch_assoc()) {
    $old["cf_main_html"]  = $rowOld["cf_main_html"] ?? "";
    $old["cf_left_html"]  = $rowOld["cf_left_html"] ?? "";
    $old["cf_right_html"] = $rowOld["cf_right_html"] ?? "";
    $old["cf_exel01"]     = $rowOld["cf_exel01"] ?? "";
    $old["cf_exel02"]     = $rowOld["cf_exel02"] ?? "";
}
$stmtOld->close();

// 3) 旧ファイル削除（同じURLなら削除しない）
$deleteLog = [];
foreach ([
    "cf_main_html"  => [$old["cf_main_html"],  $new_main],
    "cf_left_html"  => [$old["cf_left_html"],  $new_left],
    "cf_right_html" => [$old["cf_right_html"], $new_right],
    "cf_exel01"     => [$old["cf_exel01"],     $new_excel],
    "cf_exel02"     => [$old["cf_exel02"],     $new_excel2],
] as $field => $pair) {

    $oldUrl = $pair[0];
    $newUrl = $pair[1];

    if (is_string($oldUrl) && $oldUrl !== "" && $oldUrl !== $newUrl) {
        list($delOk, $delErr) = delete_s3_object_by_url_using_common_keys($oldUrl);
        $deleteLog[$field] = ["ok" => $delOk, "error" => $delErr];
        if (!$delOk) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                "ok" => false,
                "status" => "error",
                "message" => "旧ファイルの削除に失敗しました。再度実行してください。",
                "failed" => $field,
                "old_url" => $oldUrl,
                "detail" => $delErr,
                "urls" => [
                    "cf_main_html" => $new_main,
                    "cf_left_html" => $new_left,
                    "cf_right_html" => $new_right,
                    "cf_exel01" => $new_excel,
                    "cf_exel02" => $new_excel2
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $deleteLog[$field] = ["ok" => true, "note" => "empty_or_same"];
    }
}

// 4) DB更新
$stmtUpd = $conn->prepare("
    UPDATE ai_case
    SET cf_main_html = ?, cf_left_html = ?, cf_right_html = ?, cf_exel01 = ?, cf_exel02 = ?
    WHERE ai_case_id = ?
    LIMIT 1
");
if (!$stmtUpd) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "error",
        "message" => "DBのUPDATE準備に失敗しました",
        "detail" => $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$stmtUpd->bind_param("sssssi", $new_main, $new_left, $new_right, $new_excel, $new_excel2, $aiCaseId);
$okUpd = $stmtUpd->execute();
$stmtUpd->close();

if (!$okUpd) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "ok" => false,
        "status" => "error",
        "message" => "ai_case の更新に失敗しました",
        "detail" => $conn->error
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5) 返却（軽量）
http_response_code(200);
header('Content-Type: application/json; charset=utf-8');
sleep(25);
echo json_encode([
    "ok" => true,
    "ai_case_id" => $aiCaseId,
    "html" => $new_main,
    "cash_ai_05_urls" => [$new_left, $new_right],
    "cash_ai_03_url" => $new_excel,
    "cash_ai_03_excel1_5_url" => $new_excel2,
    "cash_ai_03_excel1_5" => ($respJson03["result"]["excel1_5"] ?? ($respJson03["excel1_5"] ?? null)),
    "_url_check" => $checkResult,
    "_delete_old" => $deleteLog,
	"respJson03" => $respJson03,
	"kousya" => $data['kousya'],
    "_ai_case_updated" => true
], JSON_UNESCAPED_UNICODE);
exit;
