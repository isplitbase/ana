<?php
/**
 * cash_ai_02_excel.php
 *
 * HTML からのリクエスト(JSON)を受け取り、
 * common.php の方式で loginkey を検証した上で、
 * cash-ai-02-excel Cloud Run API へそのまま転送し、
 * レスポンス(JSON)をそのまま返却する。
 */

include "./common.php";

global $damy_flag;
global $conn;
global $port;

require_once __DIR__ . '/vendor/autoload.php';

use Google\Auth\ApplicationDefaultCredentials;

$cash_ai_02_excel_url = 'https://ana-cash-ai-02-excel-512697354748.asia-northeast1.run.app/v1/pipeline';
$cash_ai_02_excel_audience = 'https://ana-cash-ai-02-excel-512697354748.asia-northeast1.run.app';
if($port=="8012"){
	$cash_ai_02_excel_url = 'https://ana-cash-ai-02-excel-real-512697354748.asia-northeast1.run.app/v1/pipeline';
	$cash_ai_02_excel_audience = 'https://ana-cash-ai-02-excel-real-512697354748.asia-northeast1.run.app';
}
const CASH_AI_02_EXCEL_TIMEOUT_SEC = 600;

function excel_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function now_ms(): float
{
    return microtime(true) * 1000.0;
}

function elapsed_ms(float $start_ms): int
{
    return (int) round(now_ms() - $start_ms);
}

function getCloudRunIdToken(string $serviceAccountJsonPath, string $audience): string
{
    if (!file_exists($serviceAccountJsonPath)) {
        throw new Exception('Service account key not found: ' . $serviceAccountJsonPath);
    }

    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $serviceAccountJsonPath);

    $creds = ApplicationDefaultCredentials::getIdTokenCredentials($audience);
    $tokenArr = $creds->fetchAuthToken();

    if (!is_array($tokenArr) || empty($tokenArr['id_token'])) {
        throw new Exception('Failed to fetch id_token. Response: ' . json_encode($tokenArr, JSON_UNESCAPED_UNICODE));
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
    $lastRespBody = '';
    $lastCurlErrNo = 0;
    $lastCurlErr = '';
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

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    excel_json_response([
        'status' => 'error',
        'message' => 'Invalid JSON: ' . json_last_error_msg(),
    ], 400);
}

$loginkey = $data['loginkey'] ?? null;

if ($damy_flag != 'OK') {
    if ($loginkey) {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id === null) {
            excel_json_response([
                'status' => 'error',
                'message' => '異常アクセス',
            ], 403);
        }
    } else {
        excel_json_response([
            'status' => 'error',
            'message' => 'loginkey がありません',
        ], 400);
    }
}

// ai_case_id が camelCase でだけ来る場合に備えて補完
if (!isset($data['ai_case_id']) && isset($data['aiCaseId'])) {
    $data['ai_case_id'] = $data['aiCaseId'];
}
if (!isset($data['aiCaseId']) && isset($data['ai_case_id'])) {
    $data['aiCaseId'] = $data['ai_case_id'];
}

// 案件が同じ会社のものか確認（他社の案件ならエラーで終了。_case_access_helper.php）
require_once __DIR__ . "/_case_access_helper.php";
ana_require_case_in_company($user_id ?? null, $data['ai_case_id'] ?? null, false, 403);

// postingPeriod が無い場合でも既存連携に合わせてキーは保持
if (!array_key_exists('postingPeriod', $data)) {
    $data['postingPeriod'] = null;
}
$serviceAccountJsonPath = '/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-f50b079b0584.json';

try {
    $idToken = getCloudRunIdToken($serviceAccountJsonPath, $cash_ai_02_excel_audience);
} catch (Exception $e) {
    excel_json_response([
        'status' => 'error',
        'message' => 'Cloud Run 認証トークン取得失敗',
        'detail' => $e->getMessage(),
    ], 500);
}

$startMs = now_ms();
list($respBody, $curlErrNo, $curlErr, $httpCode) = postJsonToCloudRun(
    $cash_ai_02_excel_url,
    $idToken,
    $data,
    CASH_AI_02_EXCEL_TIMEOUT_SEC
);
$elapsed = elapsed_ms($startMs);

if ($curlErrNo !== 0) {
    excel_json_response([
        'status' => 'error',
        'message' => 'cash-ai-02-excel 呼び出し失敗',
        'curl_errno' => $curlErrNo,
        'curl_error' => $curlErr,
        '_timing' => [
            'cash_ai_02_excel_ms' => $elapsed,
        ],
    ], 502);
}

if ($httpCode < 200 || $httpCode >= 300) {
    excel_json_response([
        'status' => 'error',
        'message' => 'cash-ai-02-excel がエラーを返しました',
        'http_code' => $httpCode,
        'body' => $respBody,
        '_timing' => [
            'cash_ai_02_excel_ms' => $elapsed,
        ],
    ], 502);
}

$respJson = json_decode($respBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respJson)) {
    excel_json_response([
        'status' => 'error',
        'message' => 'cash-ai-02-excel のレスポンスJSON解析に失敗しました',
        'http_code' => $httpCode,
        'body' => $respBody,
        '_timing' => [
            'cash_ai_02_excel_ms' => $elapsed,
        ],
    ], 502);
}

$respJson['_timing'] = array_merge(
    is_array($respJson['_timing'] ?? null) ? $respJson['_timing'] : [],
    [
        'cash_ai_02_excel_ms' => $elapsed,
    ]
);

excel_json_response($respJson, 200);
