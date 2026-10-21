<?php
/**
 * ana-aiocr-photo.php
 *
 * do_ai.php からバックグラウンド実行される非同期ワーカー。
 *
 * 役割:
 *   1) ai_case.full_file_Paths から gs:// PDF URI のリストを取得
 *   2) ana-aiocr-photo Cloud Run (/v1/pipeline) に POST → Claude OCR 実行
 *   3) 返ってきた Analygent JSON を ai_case.response / response_2 / response_pdf に保存
 *   4) (副処理) OpenAI に PDF を投げて 決算日 / 金額単位 を抽出し
 *      closing_date / closing_date_zenki / closing_date_zenzenki に保存
 *   5) ai_case.status = 'AIOK' に更新
 *
 * 起動方法 (do_ai.php から):
 *   php /path/to/ana-aiocr-photo.php <ai_case_id> > /var/log/.../aiocr_<id>_<ts>.log 2>&1 &
 *
 * 参考: sapis/zlite-aiocr-photo.php (S3 + 旧 common.php) を本サービス仕様 (GCS + ana common.php) に書き直したもの。
 */

include __DIR__ . "/common.php";

global $conn;
global $www_dir;
global $port;
global $openai_api_key;
global $model_str;
global $gcs_bucket;
global $google_application_credentials;

// ---------------------------------------------------------------------------
// CLI / Web 両対応 (基本は CLI から呼ばれる)
// ---------------------------------------------------------------------------
$ai_case_id = null;
if (php_sapi_name() === 'cli') {
    if ($argc < 2) {
        fwrite(STDERR, "Usage: php ana-aiocr-photo.php <ai_case_id>\n");
        exit(2);
    }
    $ai_case_id = (int)$argv[1];
} else {
    // Web からの起動は受け付けない（do_ai.php が CLI で起動するだけ）。
    // 以前は認証なしで ai_case_id を渡すだけで、任意の案件の OCR 実行（Claude/OpenAI の課金）と
    // 読取結果の上書きができてしまっていた。
    http_response_code(403);
    echo json_encode(["status" => "error", "message" => "異常アクセス"], JSON_UNESCAPED_UNICODE);
    exit(1);
}
if ($ai_case_id <= 0) {
    fwrite(STDERR, "Invalid ai_case_id: $ai_case_id\n");
    exit(2);
}

@set_time_limit(0);
@ini_set('memory_limit', '512M');

// ---------------------------------------------------------------------------
// 進捗ファイル (フロントが polling する)
// ---------------------------------------------------------------------------
$progressFilePath = $www_dir . "/progress/" . $ai_case_id;
if (!is_dir(dirname($progressFilePath))) {
    @mkdir(dirname($progressFilePath), 0777, true);
}
function write_progress($msg, $isError = false) {
    global $progressFilePath;
    $payload = ["message" => $msg];
    if ($isError) {
        $payload["error"] = true;
    }
    @file_put_contents(
        $progressFilePath,
        json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
    @chmod($progressFilePath, 0666);
}

// ---------------------------------------------------------------------------
// composer autoload (google/cloud-storage を使うため)
// ---------------------------------------------------------------------------
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1)) { require_once $autoload1; }
elseif (file_exists($autoload2)) { require_once $autoload2; }

// GCS / Google Auth 用に GOOGLE_APPLICATION_CREDENTIALS を反映
if (!empty($google_application_credentials)) {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $google_application_credentials);
}

// ===========================================================================
// Cloud Run 認証用 ID トークン取得 (JWT 署名方式)
//   - upload_files.php と同じ実装。本ワーカは独立プロセスのため別途定義する。
// ===========================================================================
function get_cloud_run_id_token_aiocr($key_file_path, $target_audience) {
    if (!file_exists($key_file_path)) {
        error_log("[ana-aiocr-photo] key file not found: {$key_file_path}");
        return null;
    }
    $key_json = json_decode(file_get_contents($key_file_path), true);
    if (!$key_json) {
        error_log("[ana-aiocr-photo] failed to parse key file");
        return null;
    }
    $private_key  = $key_json['private_key'];
    $client_email = $key_json['client_email'];

    $now = time();
    $header  = rtrim(strtr(base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode([
        'iss'             => $client_email,
        'sub'             => $client_email,
        'aud'             => 'https://oauth2.googleapis.com/token',
        'target_audience' => $target_audience,
        'iat'             => $now,
        'exp'             => $now + 3600,
    ])), '+/', '-_'), '=');

    $signing_input = $header . '.' . $payload;
    $signature = '';
    if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
        error_log("[ana-aiocr-photo] openssl_sign failed");
        return null;
    }
    $jwt = $signing_input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT    => 10,
    ]);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    if (empty($result['id_token'])) {
        error_log("[ana-aiocr-photo] id_token not found in response: " . $response);
        return null;
    }
    return $result['id_token'];
}

// ===========================================================================
// Cloud Run /v1/pipeline 呼び出し (同期, タイムアウト長め)
// ===========================================================================
function postJsonToCloudRun_aiocr($url, $idToken, array $payload, $timeoutSec = 1800,
                                  $maxAttempts = 3, $baseDelayMs = 1000) {
    // 2026-10-13 追加: リトライを実装（他の sapis と同じ方針に統一）。
    //   Cloud Run はインスタンスが0台のとき "no available instance" で即 500 を返すことがあり、
    //   単発呼び出しだと即エラーになっていた（実測: 5秒後には起動済みで成功する）。
    //   リトライ対象: curlエラー / http=0 / 429 / 5xx。4xx は再送しない。
    $attempt = 0;
    $lastBody = ''; $lastErrno = 0; $lastErr = ''; $lastHttpCode = 0;

    while ($attempt < $maxAttempts) {
        $attempt++;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $idToken,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastBody = ($body === false) ? '' : $body;
        $lastErrno = $errno; $lastErr = $err; $lastHttpCode = $httpCode;

        $shouldRetry = false;
        if ($errno !== 0 || $body === false) {
            $shouldRetry = true;
        } elseif ($httpCode === 0 || $httpCode === 429 || $httpCode >= 500) {
            $shouldRetry = true;
        }

        if (!$shouldRetry || $attempt >= $maxAttempts) {
            break;
        }
        error_log("[ana-aiocr-photo] Cloud Run リトライ attempt={$attempt}/{$maxAttempts} http={$httpCode} errno={$errno}");

        // 指数バックオフ + ジッター（コールドスタート実測 約11秒を吸収）
        $delay = $baseDelayMs * (1 << ($attempt - 1));
        $jitter = random_int(0, 120);
        usleep(($delay + $jitter) * 1000);
    }

    return [$lastBody, $lastErrno, $lastErr, $lastHttpCode];
}

// ===========================================================================
// 和暦 → 西暦 / 単位 正規化 (ZLite版と同じロジック)
// ===========================================================================
function convertJapaneseDateToWestern_aiocr($text) {
    if ($text === '') return '';
    $warekiMap = ['令和'=>2018, '平成'=>1988, '昭和'=>1925, '大正'=>1911, '明治'=>1867];
    foreach ($warekiMap as $era => $baseYear) {
        if (mb_strpos($text, $era) !== false) {
            $text = str_replace('元年', '1年', $text);
            if (preg_match('/' . $era . '(\d+)年(\d+)月(\d+)日/u', $text, $m)) {
                $year = $baseYear + (int)$m[1];
                $month = sprintf('%02d', (int)$m[2]);
                $day = sprintf('%02d', (int)$m[3]);
                return "{$year}年{$month}月{$day}日";
            }
        }
    }
    return $text;
}

function normalizeUnit_aiocr($unit) {
    $unit = trim($unit);
    if ($unit === '千円')   return '単位：千円';
    if ($unit === '万円')   return '単位：万円';
    if ($unit === '百万円') return '単位：百万円';
    if ($unit === '単位：千円')   return '単位：千円';
    if ($unit === '単位：万円')   return '単位：万円';
    if ($unit === '単位：百万円') return '単位：百万円';
    if ($unit === '単位:千円')   return '単位：千円';
    if ($unit === '単位:万円')   return '単位：万円';
    if ($unit === '単位:百万円') return '単位：百万円';
    return '円';
}

// ===========================================================================
// 決算年月日 / 金額単位 抽出 (GCS から PDF を取って OpenAI に投げる)
// ===========================================================================
function extractClosingDatesAndUnit_aiocr_gcs(array $gsUrls, $apiKey, $model) {
    $closing_date = '';
    $closing_date_zenki = '';
    $closing_date_zenzenki = '';
    $unit_str = '';

    if (empty($gsUrls) || empty($apiKey)) {
        return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
    }
    if (!class_exists('\\Google\\Cloud\\Storage\\StorageClient')) {
        error_log('[ana-aiocr-photo] google/cloud-storage 不在のため closing_date 抽出を中止');
        return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
    }

    $storage = new \Google\Cloud\Storage\StorageClient();

    $pdf_parts = [];
    foreach ($gsUrls as $gsUri) {
        if (!preg_match('#^gs://([^/]+)/(.+)$#', $gsUri, $m)) continue;
        $bucket = $m[1]; $key = $m[2];
        try {
            $obj = $storage->bucket($bucket)->object($key);
            if (!$obj->exists()) {
                error_log("[ana-aiocr-photo] GCS object 不在 ({$gsUri})");
                continue;
            }
            $body = (string)$obj->downloadAsString();
            if ($body === '') continue;
            $pdf_parts[] = [
                'type' => 'file',
                'file' => [
                    'filename' => basename($key) ?: 'input.pdf',
                    'file_data' => 'data:application/pdf;base64,' . base64_encode($body),
                ],
            ];
        } catch (Throwable $e) {
            error_log("[ana-aiocr-photo] GCS download 失敗 ({$gsUri}): " . $e->getMessage());
        }
    }
    if (empty($pdf_parts)) {
        return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
    }

    $prompt_text = "あなたは日本の財務諸表に詳しいAIです。\n"
        . "以下のPDFから、記載されている今期・前期・前々期の「決算年月日」と、帳票における「金額単位」(例:1円、千円、万円、百万円など)を抽出してください。\n\n"
        . "出力は以下のJSON形式にしてください。日付が無い場合や金額単位が明記されていない場合は空欄で構いません:\n\n"
        . "{\n  \"今期\": \"2025年03月31日\",\n  \"前期\": \"2024年03月31日\",\n  \"前々期\": \"\",\n  \"金額単位\": \"千円\"\n}\n\n"
        . "注意点:\n"
        . "- 日付が和暦で記載されている場合は、可能な限り西暦に変換してください。\n"
        . "- 1つのPDFに1つしか決算日が記載されていない場合、その日付が「今期」である可能性が高いです。\n"
        . "- 期間(例:「2023年4月1日〜2024年3月31日」)が書かれていた場合、終了日を「決算日」として扱ってください。\n"
        . "- 金額単位は「単位」「金額単位」「単位:千円」「単位:万円」「単位 百万円」などの表記を参考に判断してください。\n"
        . "- 複数のPDFをまとめて見て、総合的に判断してください。";

    $messages = [
        ['role' => 'system', 'content' => 'あなたは財務諸表を正確に読み取るAIです。'],
        ['role' => 'user', 'content' => array_merge([['type' => 'text', 'text' => $prompt_text]], $pdf_parts)],
    ];

    $payload = [
        'model' => $model ?: 'gpt-4o-mini',
        'messages' => $messages,
        'temperature' => 0.0,
        'response_format' => ['type' => 'json_object'],
    ];

    $MAX_ATTEMPTS = 3;
    $dateResult = null;
    for ($attempt = 1; $attempt <= $MAX_ATTEMPTS; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        $respBody = curl_exec($ch);
        $errno    = curl_errno($ch);
        $errmsg   = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $respBody === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("[ana-aiocr-photo] OpenAI 呼出失敗 attempt={$attempt}/{$MAX_ATTEMPTS} http={$httpCode} errno={$errno} err={$errmsg} body=" . substr((string)$respBody, 0, 500));
            if ($attempt < $MAX_ATTEMPTS) { sleep($attempt); continue; }
            return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
        }
        $decoded = json_decode($respBody, true);
        $content = $decoded['choices'][0]['message']['content'] ?? '';
        if (!is_string($content) || $content === '') {
            if ($attempt < $MAX_ATTEMPTS) { sleep($attempt); continue; }
            return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
        }
        $parsed = json_decode($content, true);
        if (!is_array($parsed)) {
            if ($attempt < $MAX_ATTEMPTS) { sleep($attempt); continue; }
            return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
        }
        $dateResult = $parsed;
        break;
    }

    if (!is_array($dateResult)) {
        return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
    }

    $closing_date         = convertJapaneseDateToWestern_aiocr(trim((string)($dateResult['今期']   ?? '')));
    $closing_date_zenki   = convertJapaneseDateToWestern_aiocr(trim((string)($dateResult['前期']   ?? '')));
    $closing_date_zenzenki = convertJapaneseDateToWestern_aiocr(trim((string)($dateResult['前々期'] ?? '')));
    $unit_str             = normalizeUnit_aiocr((string)($dateResult['金額単位'] ?? ''));

    return [$closing_date, $closing_date_zenki, $closing_date_zenzenki, $unit_str];
}

// ---------------------------------------------------------------------------
// メイン処理
// ---------------------------------------------------------------------------
try {
    write_progress("AI-OCR 読取準備中...");

    // 1) ai_case から PDF gs:// URI と closing_date を取得
    $stmt = $conn->prepare("SELECT full_file_Paths, closing_date, upload_file_keys FROM ai_case WHERE ai_case_id = ? LIMIT 1");
    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if (!$row) {
        throw new Exception("ai_case_id={$ai_case_id} が見つかりません");
    }

    $full_file_Paths_str = $row['full_file_Paths'] ?? '';
    if (empty($full_file_Paths_str)) {
        throw new Exception("full_file_Paths が空です (PDFのGCSアップロード未完了の可能性)");
    }

    $gsUrls = [];
    foreach (preg_split('/\|,\||,/', $full_file_Paths_str) as $u) {
        $u = trim($u);
        if (strpos($u, 'gs://') === 0) {
            $gsUrls[] = $u;
        }
    }
    if (empty($gsUrls)) {
        throw new Exception("full_file_Paths に有効な gs:// URI が含まれていません");
    }

    // 2) dev / prod 切替 (port 8012 = prod = -real-)
    $service_url = "https://ana-aiocr-photo-512697354748.asia-northeast1.run.app";
    if (($port ?? 8056) == 8012) {
        $service_url = "https://ana-aiocr-photo-real-512697354748.asia-northeast1.run.app";
    }
    $endpoint = rtrim($service_url, "/") . "/v1/pipeline";
    $sa_key_file = "/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-691c42569236.json";

    write_progress("AI-OCR 認証中...");
    $idToken = get_cloud_run_id_token_aiocr($sa_key_file, $service_url);
    if (empty($idToken)) {
        throw new Exception("Cloud Run ID Token の取得に失敗しました");
    }

    // 3) 既知の closing_date があれば file_date として渡す
    $file_date = null;
    if (!empty($row['closing_date']) && $row['closing_date'] !== '0') {
        $d = $row['closing_date'];
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $d, $m)) {
            $file_date = "{$m[1]}-{$m[2]}-{$m[3]}";
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
            $file_date = "{$m[1]}-{$m[2]}-{$m[3]}";
        } else {
            $file_date = $d;
        }
    }

    $payload = [
        "ai_case_id" => $ai_case_id,
        "pdfurls"    => $gsUrls,
        "file_date"  => $file_date,
        "max_side"   => 1400,
    ];

    // 4) /v1/pipeline 同期呼び出し
    write_progress("AI-OCR 読取中... (1〜2分かかる場合があります)");
    list($respBody, $errno, $err, $httpCode) = postJsonToCloudRun_aiocr($endpoint, $idToken, $payload, 1800);

    if ($errno !== 0) throw new Exception("Cloud Run 呼出失敗 (curl errno={$errno}): {$err}");
    if ($httpCode < 200 || $httpCode >= 300) throw new Exception("Cloud Run HTTP {$httpCode}: " . substr($respBody, 0, 1000));

    $decoded = json_decode($respBody, true);
    if (!is_array($decoded)) throw new Exception("Cloud Run 応答がJSONではない: " . substr($respBody, 0, 500));
    if (($decoded['status'] ?? '') !== 'success') throw new Exception("Cloud Run status != success: " . substr($respBody, 0, 1000));

    // 5) 結果の整形 + Cloud Run 内エラー詳細を取得
    $merged_list = [];
    $cost_total = 0.0;
    $records_total = 0;
    $routing_counts = ["claude_ai_ocr" => 0, "existing_analygent_engine" => 0, "error" => 0];
    $error_details = [];
    foreach (($decoded['results'] ?? []) as $r) {
        $routing = $r['routing'] ?? 'error';
        $routing_counts[$routing] = ($routing_counts[$routing] ?? 0) + 1;
        if (!empty($r['analygent']['list']) && is_array($r['analygent']['list'])) {
            $merged_list = array_merge($merged_list, $r['analygent']['list']);
            $records_total += count($r['analygent']['list']);
        }
        $cost_total += (float)($r['cost_usd'] ?? 0);

        // Cloud Run 側で各PDFの処理が失敗した場合は results[i] に
        //   {"status":"error","error":"...","pdf":"...","gs_uri":"..."} が返る。
        $is_err = ($routing === 'error') || (($r['status'] ?? '') === 'error') || !empty($r['error']);
        if ($is_err) {
            $detail = [
                "pdf"    => $r['pdf']    ?? null,
                "gs_uri" => $r['gs_uri'] ?? null,
                "kind"   => $r['kind']   ?? null,
                "error"  => $r['error']  ?? '(no error field)',
                "note"   => $r['note']   ?? null,
            ];
            $error_details[] = $detail;
            $detail_json = json_encode($detail, JSON_UNESCAPED_UNICODE);
            error_log("[ana-aiocr-photo] Cloud Run per-PDF error: " . $detail_json);
            if (php_sapi_name() === 'cli') {
                fwrite(STDERR, "[ana-aiocr-photo] per-PDF error: " . $detail_json . "\n");
            }
        }
    }

    // BS / PL / 販売費 / 製造原価 にソート
    $response_sorted = ["BS" => [], "PL" => [], "販売費" => [], "製造原価" => []];
    foreach ($merged_list as $rowItem) {
        if (!is_array($rowItem)) continue;
        $t = $rowItem['type'] ?? '';
        if (isset($response_sorted[$t])) {
            $response_sorted[$t][] = $rowItem;
        }
    }
    $normalized_json_str = json_encode($response_sorted, JSON_UNESCAPED_UNICODE);
    $response_pdf_json = json_encode($decoded['results'] ?? [], JSON_UNESCAPED_UNICODE);
    if ($response_pdf_json === false) $response_pdf_json = "[]";

    // 6) 決算年月日 / 単位 抽出 (OpenAI 経由, 失敗しても続行)
    write_progress("AI-OCR 決算年月日/単位 抽出中...");
    $closing_date_gpt = '';
    $closing_date_zenki_gpt = '';
    $closing_date_zenzenki_gpt = '';
    $tani_str = '';
    try {
        list($closing_date_gpt, $closing_date_zenki_gpt, $closing_date_zenzenki_gpt, $tani_str) =
            extractClosingDatesAndUnit_aiocr_gcs($gsUrls, $openai_api_key, $model_str);
        error_log("[ana-aiocr-photo] GPT: closing={$closing_date_gpt} zenki={$closing_date_zenki_gpt} zenzenki={$closing_date_zenzenki_gpt} tani={$tani_str}");
    } catch (Throwable $_e_gpt) {
        error_log("[ana-aiocr-photo] GPT 抽出失敗 (続行): " . $_e_gpt->getMessage());
    }

    $response_2_obj = [
        "ai_response" => [["kanjyokamoku" => $merged_list]],
        "tani" => $tani_str,
    ];
    $response_2_json = json_encode($response_2_obj, JSON_UNESCAPED_UNICODE);

    $closing_date_db    = $closing_date_gpt         !== '' ? $closing_date_gpt         : null;
    $closing_zenki_db   = $closing_date_zenki_gpt   !== '' ? $closing_date_zenki_gpt   : null;
    $closing_zenzenki_db = $closing_date_zenzenki_gpt !== '' ? $closing_date_zenzenki_gpt : null;

    // GPT が取れなかった場合、ai_case.closing_date を基準に前期/前々期を逆算してフォールバック
    if ($closing_date_db === null) {
        $cs = $row['closing_date'] ?? '';
        if (!empty($cs) && $cs !== '0') {
            $base_ymd = null;
            if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $cs, $m)) {
                $base_ymd = "{$m[1]}-{$m[2]}-{$m[3]}";
            } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $cs, $m)) {
                $base_ymd = "{$m[1]}-{$m[2]}-{$m[3]}";
            }
            if ($base_ymd) {
                $ts = strtotime($base_ymd);
                if ($ts !== false) {
                    $closing_date_db = date('Y年m月d日', $ts);
                    if ($closing_zenki_db === null)   $closing_zenki_db   = date('Y年m月d日', strtotime("-1 year", $ts));
                    if ($closing_zenzenki_db === null) $closing_zenzenki_db = date('Y年m月d日', strtotime("-2 year", $ts));
                }
            }
        }
    }

    // 7) DB 更新 (response / response_2 / response_pdf / 決算日3種 / status='AIOK')
    $stmt = $conn->prepare(
        "UPDATE ai_case SET update_at=NOW(), response=?, response_2=?, response_pdf=?, "
        . "closing_date=COALESCE(?, closing_date), "
        . "closing_date_zenki=COALESCE(?, closing_date_zenki), "
        . "closing_date_zenzenki=COALESCE(?, closing_date_zenzenki), "
        . "status='AIOK' WHERE ai_case_id = ?"
    );
    $stmt->bind_param("ssssssi",
        $normalized_json_str, $response_2_json, $response_pdf_json,
        $closing_date_db, $closing_zenki_db, $closing_zenzenki_db,
        $ai_case_id
    );
    if (!$stmt->execute()) {
        throw new Exception("ai_case.response 保存失敗: " . $stmt->error);
    }

    write_progress("読取完了まで");

    if (php_sapi_name() === 'cli') {
        echo json_encode([
            "status" => "success",
            "ai_case_id" => $ai_case_id,
            "records" => $records_total,
            "cost_usd" => round($cost_total, 4),
            "routing" => $routing_counts,
            "errors" => $error_details,
            "closing_date" => $closing_date_db,
            "tani" => $tani_str,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
    exit(0);

} catch (Throwable $e) {
    $err_msg = "AI-OCR読取エラー: " . $e->getMessage();
    error_log("[ana-aiocr-photo.php] ai_case_id={$ai_case_id} {$err_msg}");
    write_progress($err_msg, true);

    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $err_msg . "\n");
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => $err_msg], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
}
