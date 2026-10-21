<?php
/**
 * cash_ai_02_save.php
 *
 * フロントから来たリクエスト(JSON)を受け取り、
 * common.php の方式で loginkey を認証した上で、
 * 1) cash-ai-02(/v1/pipeline) へ転送
 * 2) cash-ai-02 のレスポンスに含まれる html を cash-ai-04 へ転送
 * 3) cash-ai-04 の patched_url で html を置換
 * 4) レスポンス直前に ai_case.cf_main_html / ai_case.cf_data を更新
 *
 * - $port === "8012" のときは "-real" 側を呼ぶ
 * - cash_ai.php の認証・IDトークン取得・Cloud Run 転送・DB更新の流儀を踏襲
 */

include "./common.php";

global $damy_flag;
global $conn;
global $port;
global $gcs_bucket;
global $google_application_credentials;

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
): array {
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
            'Content-Type: application/json',
            'Authorization: Bearer ' . $idToken,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);

        $respBody = curl_exec($ch);
        $curlErrNo = curl_errno($ch);
        $curlErr = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastRespBody = ($respBody === false) ? '' : $respBody;
        $lastCurlErrNo = $curlErrNo;
        $lastCurlErr = $curlErr;
        $lastHttpCode = $httpCode;

        $shouldRetry = false;

        if ($curlErrNo !== 0 || $respBody === false) {
            $shouldRetry = true;
        } else {
            if ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500) {
                $shouldRetry = true;
            } elseif ($httpCode === 200 || $httpCode === 201) {
                $trim = ltrim($lastRespBody);
                if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                    $j = json_decode($lastRespBody, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($j)) {
                        $status = strtolower((string) ($j['status'] ?? ''));
                        $ok = $j['ok'] ?? null;
                        $hasErrorKey = array_key_exists('error', $j) || array_key_exists('errors', $j);
                        $msg = strtolower((string) ($j['message'] ?? $j['detail'] ?? ''));
                        if ($status === 'error' || $ok === false || $hasErrorKey || (strpos($msg, 'error') !== false)) {
                            $shouldRetry = true;
                        }
                    }
                } else {
                    $shouldRetry = true;
                }
            }
        }

        if (!$shouldRetry || $attempt >= $maxAttempts) {
            break;
        }

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

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function check_url_accessible(string $url): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_RANGE, '0-10');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    curl_exec($ch);

    $errNo = curl_errno($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errNo) {
        return [false, $code, $err];
    }

    if ($code >= 200 && $code < 400) {
        return [true, $code, null];
    }

    return [false, $code, 'http_code: ' . $code];
}

function parse_gcs_bucket_key_from_url(string $url): array
{
    // gs://bucket/key 形式
    if (preg_match('#^gs://([^/]+)/(.+)$#', $url, $m)) {
        return [$m[1], $m[2], null];
    }

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['path'])) {
        return [null, null, 'invalid url'];
    }

    // https://storage.googleapis.com/bucket/key
    if ($p['host'] === 'storage.googleapis.com') {
        $path = ltrim($p['path'], '/');
        $parts = explode('/', $path, 2);
        if (count($parts) === 2 && $parts[0] !== '') {
            return [$parts[0], $parts[1], null];
        }
        return [null, null, 'bucket/key not found in path'];
    }

    return [null, null, 'not a GCS url'];
}

function delete_gcs_object_by_url(string $url): array
{
    global $gcs_bucket;
    global $google_application_credentials;

    if (!class_exists(\Google\Cloud\Storage\StorageClient::class)) {
        return [false, 'google/cloud-storage が見つかりません'];
    }

    list($bucketFromUrl, $key, $perr) = parse_gcs_bucket_key_from_url($url);
    if ($key === null || $key === '') {
        return [false, 'GCS URL解析に失敗: ' . ($perr ?? 'key empty')];
    }

    $bucket = $bucketFromUrl ?: $gcs_bucket;

    try {
        $storage = new \Google\Cloud\Storage\StorageClient(['keyFilePath' => $google_application_credentials]);
        $storage->bucket($bucket)->object($key)->delete();
        return [true, null];
    } catch (Exception $e) {
        return [false, 'GCS deleteObject 失敗: ' . $e->getMessage()];
    }
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    json_response([
        'status' => 'error',
        'message' => 'Invalid JSON: ' . json_last_error_msg(),
    ], 400);
}

$timing = [];
$totalStartMs = now_ms();

$loginkey = $data['loginkey'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? ($data['aiCaseId'] ?? null);
$postingPeriod = $data['postingPeriod'] ?? null;

if ($damy_flag != 'OK') {
    if ($loginkey !== null && $loginkey !== '') {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id === null) {
            json_response([
                'status' => 'error',
                'message' => '異常アクセス',
            ], 403);
        }
    } else {
        json_response([
            'status' => 'error',
            'message' => 'loginkey がありません',
        ], 400);
    }
}

$serviceAccountJsonPath = '/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-f50b079b0584.json';

$service_url_02 = 'https://ana-cash-ai-02-512697354748.asia-northeast1.run.app';
$service_url_04 = 'https://ana-cash-ai-04-512697354748.asia-northeast1.run.app';
if ($port == '8012') {
    $service_url_02 = 'https://ana-cash-ai-02-real-512697354748.asia-northeast1.run.app';
    $service_url_04 = 'https://ana-cash-ai-04-real-512697354748.asia-northeast1.run.app';
}

$url_02 = rtrim($service_url_02, '/') . '/v1/pipeline';
$url_04 = rtrim($service_url_04, '/');

$payload02 = $data;
$payload02['loginkey'] = $loginkey;
if ($ai_case_id !== null && $ai_case_id !== '') {
    $payload02['ai_case_id'] = $ai_case_id;
    $payload02['aiCaseId'] = $ai_case_id;
}
$payload02['port'] = $port;

try {
    $t0 = now_ms();
    $idToken02 = getCloudRunIdToken($serviceAccountJsonPath, $service_url_02);
    $timing['cash_ai_02_id_token_ms'] = elapsed_ms($t0);
} catch (Exception $e) {
    json_response([
        'ok' => false,
        'status' => 'error',
        'message' => 'Cloud Run(02) 用IDトークン生成に失敗しました',
        'detail' => $e->getMessage(),
        '_timing' => $timing,
    ], 500);
}

$start02 = now_ms();
list($respBody02, $curlErrNo02, $curlErr02, $httpCode02) = postJsonToCloudRun($url_02, $idToken02, $payload02);
$timing['cash_ai_02_ms'] = elapsed_ms($start02);
$timing['cash_ai_02_http_code'] = $httpCode02;

if ($curlErrNo02) {
    json_response([
        'status' => 'error',
        'message' => 'Cloud Run(02) への接続に失敗しました',
        'curl_errno' => $curlErrNo02,
        'curl_error' => $curlErr02,
        '_timing' => $timing,
    ], 502);
}

if ($httpCode02 === 401 || $httpCode02 === 403) {
    json_response([
        'status' => 'error',
        'message' => 'Cloud Run(02) に拒否されました（認証/権限を確認してください）',
        'cloud_run_http_code' => $httpCode02,
        'cloud_run_response' => $respBody02,
        '_timing' => $timing,
    ], 502);
}

if ($httpCode02 >= 400) {
    http_response_code($httpCode02);
    header('Content-Type: application/json; charset=utf-8');
    echo $respBody02;
    exit;
}

$resp02Decoded = json_decode($respBody02, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($resp02Decoded)) {
    json_response([
        'status' => 'error',
        'message' => 'Cloud Run(02) がJSON以外を返しました',
        'cloud_run_response' => $respBody02,
        '_timing' => $timing,
    ], 502);
}

$htmlUrl = $resp02Decoded['html'] ?? null;
if (!is_string($htmlUrl) || $htmlUrl === '') {
    $timing['total_ms'] = elapsed_ms($totalStartMs);
    $resp02Decoded['_timing'] = $timing;
    $resp02Decoded['_cash_ai_04'] = [
        'ok' => false,
        'error' => 'html not found in cash-ai-02 response',
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp02Decoded, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $t0 = now_ms();
    $idToken04 = getCloudRunIdToken($serviceAccountJsonPath, $service_url_04);
    $timing['cash_ai_04_id_token_ms'] = elapsed_ms($t0);
} catch (Exception $e) {
    $timing['total_ms'] = elapsed_ms($totalStartMs);
    $resp02Decoded['_timing'] = $timing;
    $resp02Decoded['_cash_ai_04'] = [
        'ok' => false,
        'error' => 'cash-ai-04 id_token failed',
        'detail' => $e->getMessage(),
    ];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($resp02Decoded, JSON_UNESCAPED_UNICODE);
    exit;
}

$payload04 = [
    'ai_case_id' => $ai_case_id,
    'loginkey' => $loginkey,
    'postingPeriod' => $postingPeriod,
    'port' => $port,
    '決算期年月日' => $data['決算期年月日'] ?? null,
    'html' => $htmlUrl,
];

$start04 = now_ms();
list($respBody04, $curlErrNo04, $curlErr04, $httpCode04) = postJsonToCloudRun($url_04, $idToken04, $payload04, 300);
$timing['cash_ai_04_ms'] = elapsed_ms($start04);
$timing['cash_ai_04_http_code'] = $httpCode04;

if (!$curlErrNo04 && $httpCode04 < 400) {
    $resp04Decoded = json_decode($respBody04, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($resp04Decoded)) {
        $patchedUrl = $resp04Decoded['patched_url'] ?? null;
        if (is_string($patchedUrl) && $patchedUrl !== '') {
            $resp02Decoded['html'] = $patchedUrl;
            $resp02Decoded['_cash_ai_04'] = [
                'ok' => true,
                'patched_gcs_bucket' => $resp04Decoded['patched_gcs_bucket'] ?? null,
                'patched_gcs_key' => $resp04Decoded['patched_gcs_key'] ?? null,
            ];
        } else {
            $resp02Decoded['_cash_ai_04'] = [
                'ok' => false,
                'error' => 'patched_url not found',
                'cloud_run_http_code' => $httpCode04,
                'cloud_run_response' => $respBody04,
            ];
        }
    } else {
        $resp02Decoded['_cash_ai_04'] = [
            'ok' => false,
            'error' => 'cash-ai-04 returned non-JSON',
            'cloud_run_http_code' => $httpCode04,
            'cloud_run_response' => $respBody04,
        ];
    }
} else {
    $resp02Decoded['_cash_ai_04'] = [
        'ok' => false,
        'error' => 'cash-ai-04 request failed',
        'curl_errno' => $curlErrNo04,
        'curl_error' => $curlErr04,
        'cloud_run_http_code' => $httpCode04,
        'cloud_run_response' => $respBody04,
    ];
}

if (is_array($resp02Decoded) && $ai_case_id !== null && $ai_case_id !== '') {
    $newHtml = $resp02Decoded['html'] ?? null;

    if (is_string($newHtml) && $newHtml !== '') {
        $tCheck = now_ms();
        list($canAccess, $accessCode, $accessErr) = check_url_accessible($newHtml);
        $timing['cf_main_html_check_ms'] = elapsed_ms($tCheck);
        $timing['cf_main_html_check_http_code'] = $accessCode;

        if (!$canAccess) {
            json_response([
                'status' => 'error',
                'message' => 'HTMLのURLにアクセス確認が取れませんでした。再度実行してください。',
                'detail' => $accessErr,
                'html' => $newHtml,
                '_timing' => $timing,
            ], 500);
        }

        $oldHtml = '';
        $tDbGet = now_ms();
        $stmtGet = $conn->prepare("\n            SELECT cf_main_html\n            FROM ai_case\n            WHERE ai_case_id = ?\n            LIMIT 1\n        ");
        if ($stmtGet) {
            $stmtGet->bind_param('i', $ai_case_id);
            $stmtGet->execute();
            $res = $stmtGet->get_result();
            if ($row = $res->fetch_assoc()) {
                $oldHtml = $row['cf_main_html'] ?? '';
            }
            $stmtGet->close();
        }
        $timing['cf_main_html_db_get_ms'] = elapsed_ms($tDbGet);

        if (is_string($oldHtml) && $oldHtml !== '' && $oldHtml !== $newHtml) {
            $host = parse_url($oldHtml, PHP_URL_HOST);
            $isGcsUrl = ($host === 'storage.googleapis.com') || (strpos($oldHtml, 'gs://') === 0);
            if (!$isGcsUrl) {
                // GCS形式でない場合（旧S3 URLなど）は削除をスキップしてログのみ
                error_log('[cash_ai_02_save] 古いHTMLのURLがGCS形式ではないためスキップ: ' . $oldHtml);
            } else {
                $tDel = now_ms();
                list($delOk, $delErr) = delete_gcs_object_by_url($oldHtml);
                $timing['cf_main_html_gcs_delete_ms'] = elapsed_ms($tDel);

                if (!$delOk) {
                    json_response([
                        'status' => 'error',
                        'message' => '古いHTMLファイルの削除に失敗しました。再度実行してください。',
                        'detail' => $delErr,
                        'old_html' => $oldHtml,
                        '_timing' => $timing,
                    ], 500);
                }
            }
        }

        $cfDataObj = [
            'data' => $payload02['data'] ?? [],
        ];
        $cfDataJson = json_encode($cfDataObj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($cfDataJson === false) {
            json_response([
                'status' => 'error',
                'message' => 'cf_data 用JSONの生成に失敗しました',
                'detail' => json_last_error_msg(),
                '_timing' => $timing,
            ], 500);
        }

        $tDbUpd = now_ms();
        $stmtUpd = $conn->prepare("\n            UPDATE ai_case\n            SET cf_main_html = ?, cf_data = ?, update_user_id = ?\n            WHERE ai_case_id = ?\n            LIMIT 1\n        ");
        if (!$stmtUpd) {
            json_response([
                'status' => 'error',
                'message' => 'DB更新用SQLの準備に失敗しました',
                'detail' => $conn->error,
                '_timing' => $timing,
            ], 500);
        }

        $stmtUpd->bind_param('ssii', $newHtml, $cfDataJson, $user_id, $ai_case_id);
        $okUpd = $stmtUpd->execute();
        $stmtUpd->close();
        $timing['cf_main_html_db_update_ms'] = elapsed_ms($tDbUpd);

        if (!$okUpd) {
            json_response([
                'status' => 'error',
                'message' => 'cf_main_html / cf_data の更新に失敗しました',
                'detail' => $conn->error,
                '_timing' => $timing,
            ], 500);
        }

        $resp02Decoded['_cf_main_html_saved'] = true;
        $resp02Decoded['_cf_data_saved'] = true;
    }
}

$timing['total_ms'] = elapsed_ms($totalStartMs);
$resp02Decoded['_timing'] = $timing;
$resp02Decoded['ok'] = true;
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resp02Decoded, JSON_UNESCAPED_UNICODE);
exit;
