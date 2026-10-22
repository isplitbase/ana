<?php
// composer autoload (google/cloud-storage)
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1)) { require_once $autoload1; }
else if (file_exists($autoload2)) { require_once $autoload2; }
 
// Toggle: set true to actually call Cloud Run Job from PHP
$enable_cloud_run_job = false; // ← 今は必要な時だけ true にしてください

// Toggle: set true to call Cloud Run Service API (/convert) from PHP
$GLOBALS["enable_pdf_converter_api"] = true;
// ※ pdf_converter_base_url / pdf_converter_api_url は $port 確定後（common.php 読込後）に設定


// =====================================================
// Logs (daily rotated) - written by PHP (not Cloud Run)
//   Upload:      /var/log/ana/upload_YYYYMMDD.log
//   Cloud Run:   /var/log/ana/cloud_run_job_YYYYMMDD.log
// =====================================================

/**
 * Upload process log (daily rotated)
 */
function ana_upload_log($message) {
    $logDir = "/var/log/ana";
    $date   = date("Ymd");
    $logFile = "{$logDir}/upload_{$date}.log";

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $line = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Cloud Run Job log (daily rotated)
 */
function ana_cloud_run_job_log($message) {
    $logDir = "/var/log/ana";
    $date   = date("Ymd");
    $logFile = "{$logDir}/cloud_run_job_{$date}.log";

    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $line = "[" . date("Y-m-d H:i:s") . "] " . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND);
}

/**
 * Backward compatible logger name used in the original code.
 * Now writes to /var/log/ana/upload_YYYYMMDD.log
 */
function gcs_debug_log($msg) {
    ana_upload_log($msg);
}

include "./common.php";

// expose common.php variables to $GLOBALS for functions
$GLOBALS["cloud_run_project_id"] = $cloud_run_project_id ?? "";
$GLOBALS["cloud_run_region"]     = $cloud_run_region ?? "";
$GLOBALS["cloud_run_job_name"]   = $cloud_run_job_name ?? "";
$GLOBALS["enable_cloud_run_job"] = false; // Cloud Run Job は使用しない（pdf-converter API を使用）
$GLOBALS["google_application_credentials"] = $google_application_credentials ?? "";
if (!empty($GLOBALS["google_application_credentials"])) {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $GLOBALS["google_application_credentials"]);
}
global $conn;
// Detect current DB name for Cloud Run Job (needed for Job-side UPDATE ai_case.img_urls)
$GLOBALS["MYSQL_DB"] = $dbname ?? "";
$GLOBALS["PORT"] = $port ?? 8056;
// pdf-converter URL: port 確定後にセット（8012=本番用 -real-、それ以外=テスト用）
$_pdf_converter_base = "https://pdf-converter-512697354748.asia-northeast1.run.app";
if ($GLOBALS["PORT"] == 8012) {
    $_pdf_converter_base = "https://pdf-converter-real-512697354748.asia-northeast1.run.app";
}
$GLOBALS["pdf_converter_api_url"] = $_pdf_converter_base . "/convert";
$GLOBALS["pdf_converter_base_url"] = $_pdf_converter_base;
global $uploadDir;
global $backendbase;
global $python_base;
global $damy_flag;
$loginkey = "";
$postingPeriod = "";
$imgcommdout = "";
$read_sga = "";//販売費及び一般管理費読取
$read_mcr = "";// 製造原価報告書読取
if (isset($_FILES['meta']) && $_FILES['meta']['error'] === UPLOAD_ERR_OK) {
    $jsonString = file_get_contents($_FILES['meta']['tmp_name']);
    $metaData = json_decode($jsonString, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $loginkey = $metaData['loginkey'] ?? null;
        $postingPeriod = $metaData['postingPeriod'] ?? null;
		$read_sga = $metaData['read_sga'] ?? null;
		$read_mcr = $metaData['read_mcr'] ?? null;
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "metaのJSONデコードに失敗しました: " . json_last_error_msg()
        ]);
        exit;
    }
}
if(empty($loginkey)){
	$loginkey = $_POST['loginkey'] ?? null;
}
if($damy_flag!="OK"){
	$user_id=null;
	if (isset($loginkey)) {
		$user_id=get_user_id_for_now($loginkey);
		if($user_id==null){
			echo json_encode([
				"status" => "error",
				"message" => "異常アクセス"
			]);
			exit;
		}
	}
}
function convertPdfViaPython($pdfPath, $outputDir) {
    global $backendbase;
    global $python_base;
    global $imgcommdout;

    $escapedPdfPath = escapeshellarg($pdfPath);
    $escapedOutputDir = escapeshellarg($outputDir);
    $python = $python_base;
    //$command = "$python {$backendbase}convert_pdf_to_images.py $escapedPdfPath $escapedOutputDir > /var/log/pys/test.log 2>&1 &";
	$command = "$python {$backendbase}convert_pdf_to_images.py $escapedPdfPath $escapedOutputDir";
	$imgcommdout .= "||command :::".$command;
    $maxRetries = 4;
    $result = null;

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $output = shell_exec($command);
        $result = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $imgcommdout .= "||attempt {$attempt}: json_decode error - " . json_last_error_msg()." - ".$output;
        } elseif (
            is_array($result) &&
            isset($result['images']) &&
            is_array($result['images']) &&
            count($result['images']) > 0
        ) {
            // 成功したのでbreak
            break;
        } else {
            $imgcommdout .= "||attempt {$attempt}: empty images"." - ".$output;
        }

        // 少し待機してリトライ（任意）
        usleep(700000); // 0.3秒
    }

    // 最後まで成功しなかった場合は空配列を返す
    if (!is_array($result) || !isset($result['images'])) {
        $result = ["images" => [], "images_mini" => []];
    }

    return $result;
}

function getPdfPageCount($pdfPath) {
    $output = [];
    $returnVar = 0;
    exec("pdfinfo " . escapeshellarg($pdfPath), $output, $returnVar);
    if ($returnVar !== 0) return 0;

    foreach ($output as $line) {
        if (preg_match('/^Pages:\s+(\d+)/i', $line, $matches)) {
            return (int)$matches[1];
        }
    }
    return 0;
}
/**
 * 1ファイルずつページ数を判定する。
 *  仕様: 1ファイル最大10ページ以内（最大3ファイル・合計30ページ以内）
 *  → 合計ではなく、各ファイル単位で 10ページ超ならエラー終了する。
 */
function countpages($fileKey, $targetPath) {
    if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo(basename($_FILES[$fileKey]['name']), PATHINFO_EXTENSION);
        $filePath = $targetPath . $fileKey . "." . $ext;
        move_uploaded_file($_FILES[$fileKey]['tmp_name'], $filePath);

        // 1ファイルごとのページ数チェック（10ページ以内）
        $pages = getPdfPageCount($filePath);

        // ★ 1PDFあたり10ページを超えたらエラーで即終了
        if ($pages > 10) {
            echo json_encode([
                "status"  => "error",
                "message" => "{$fileKey} のPDFページ数が10ページを超えているため処理を中断しました（現在: {$pages}ページ / 上限: 10ページ）"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}
function upload_file_to_gcs_sdk($localPath, $bucketName, $objectName) {
    // common.php で $google_application_credentials を設定している場合は反映
    if (!empty($GLOBALS["google_application_credentials"])) {
        putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $GLOBALS["google_application_credentials"]);
    }

    if (!class_exists("\\Google\\Cloud\\Storage\\StorageClient")) {
        throw new RuntimeException("google/cloud-storage が読み込めません（vendor/autoload.php を確認してください）");
    }
    if (empty($bucketName)) {
        throw new RuntimeException("gcs_bucket が空です（common.php の $gcs_bucket を確認してください）");
    }

    $storage = new \Google\Cloud\Storage\StorageClient();
    $bucket = $storage->bucket($bucketName);

    // upload
    $bucket->upload(
        fopen($localPath, "r"),
        ["name" => ltrim($objectName, "/")]
    );

    return "gs://{$bucketName}/" . ltrim($objectName, "/");
}

/**
 * Get GCP access token for calling Cloud Run Jobs API.
 * Priority:
 *  1) Metadata Server (recommended when running on GCE / Cloud Run / GKE with SA)
 *  2) ADC via google/auth (if installed)
 */
function get_gcp_access_token() {
    // ★最優先：ADC（サービスアカウントキー）を使う
    // common.php に $google_application_credentials が設定されていれば、
    // 上部の putenv(GOOGLE_APPLICATION_CREDENTIALS=...) によりこのキーが使われます。
    if (class_exists("\\Google\\Auth\\ApplicationDefaultCredentials")) {
        $scopes = ["https://www.googleapis.com/auth/cloud-platform"];
        $creds = \Google\Auth\ApplicationDefaultCredentials::getCredentials($scopes);
        $tokenArr = $creds->fetchAuthToken();
        if (is_array($tokenArr) && !empty($tokenArr["access_token"])) {
            return $tokenArr["access_token"];
        }
    }

    // ★最後の手段：Metadata Server
    // GCE インスタンス作成時の OAuth scope が弱いと 403(scope insufficient) になります。
    $md = "http://metadata.google.internal/computeMetadata/v1/instance/service-accounts/default/token";
    $ch = curl_init($md);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Metadata-Flavor: Google"],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res !== false && $code === 200) {
        $j = json_decode($res, true);
        if (is_array($j) && !empty($j["access_token"])) {
            return $j["access_token"];
        }
    }

    throw new RuntimeException("GCPアクセストークンを取得できませんでした（ADC/Metadata どちらも失敗）");
}


/**
 * Run Cloud Run Job with env overrides.
 * REST: POST https://run.googleapis.com/v2/projects/{project}/locations/{region}/jobs/{job}:run
 */
function run_cloud_run_job($projectId, $region, $jobName, $envAssoc) {
    // (a) Access token
    $token = get_gcp_access_token();

    // (b) Endpoint
    $url = "https://run.googleapis.com/v2/projects/{$projectId}/locations/{$region}/jobs/{$jobName}:run";

    // (c) Convert env assoc array to API format
    $envList = [];
    foreach ($envAssoc as $k => $v) {
        $envList[] = ["name" => (string)$k, "value" => (string)$v];
    }

    // (d) Payload (containerOverrides)
    $payload = [
        "overrides" => [
            "containerOverrides" => [
                [
                    "env" => $envList
                ]
            ]
        ]
    ];

    // (e) Call API
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 20,
    ]);

    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($res === false) {
        throw new RuntimeException("Cloud Run Jobs API呼び出し失敗: {$err}");
    }

    $json = json_decode($res, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : $res;
        throw new RuntimeException("Cloud Run Jobs APIエラー HTTP {$code}: {$msg}");
    }

    // Return Operation
    return $json;
}

// ==========================================================
// Cloud Run 認証用 ID トークン取得
// ==========================================================
function get_cloud_run_id_token($key_file_path, $target_audience) {
    if (!file_exists($key_file_path)) {
        error_log("get_cloud_run_id_token: key file not found: {$key_file_path}");
        return null;
    }
    $key_json = json_decode(file_get_contents($key_file_path), true);
    if (!$key_json) {
        error_log("get_cloud_run_id_token: failed to parse key file");
        return null;
    }
    $private_key  = $key_json['private_key'];
    $client_email = $key_json['client_email'];

    $now = time();

    // JWT ヘッダー・ペイロード作成（RS256）
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

    // RS256 で署名
    $signature = '';
    if (!openssl_sign($signing_input, $signature, $private_key, OPENSSL_ALGO_SHA256)) {
        error_log("get_cloud_run_id_token: openssl_sign failed");
        return null;
    }
    $jwt = $signing_input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

    // JWT → ID トークン交換
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
        error_log("get_cloud_run_id_token: id_token not found in response: " . $response);
        return null;
    }
    return $result['id_token'];
}


// ==========================================================
// Cloud Run Service API call (/convert) - 非同期（fire-and-forget）
// ==========================================================
// Cloud Run の変換APIへ投げてから応答を待つ最大ミリ秒。
//   - 正常時の変換は数分かかるので、ここで切断して画面へ先に応答を返す
//     （切断しても Cloud Run 側の処理は継続する）
//   - 一方「変換が始まる前に落ちる異常」は実測 0.5〜1.5 秒で HTTP 500 が返るため、
//     その範囲を拾えるだけ待って失敗を検知する
//   - 500 に戻せば従来どおりの完全な投げっぱなし動作になる
define('PDF_CONVERTER_FASTFAIL_WAIT_MS', 3000);

/**
 * pdf-converter のインスタンスを事前に起こす（コールドスタート対策・2026-10-13 追加）
 *
 * インスタンスが0台のとき /convert が "no available instance" で即 500 になり、
 * しかもその 500 が PHP まで届かず http=0 となるため失敗検知が働かず、
 * status='UPST'（画面表示「帳票識別中」）のまま固まる事象があった。
 * 本番リクエストの前に軽いリクエストで起動させておく。
 *   - ウォーム時  : 数十ms で 404 が返るため通常時の遅延はほぼ無い
 *   - コールド時  : 起動完了まで待たされる（実測 約11秒）
 * ここでの失敗は致命的ではないため、結果に関わらず本処理は続行する。
 */
function warmup_pdf_converter($baseUrl, $timeoutSec = 20) {
    $key_file = '/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-691c42569236.json';
    $id_token = get_cloud_run_id_token($key_file, $baseUrl);
    $headers  = $id_token ? ["Authorization: Bearer {$id_token}"] : [];

    $ch = curl_init(rtrim($baseUrl, '/') . '/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $t0 = microtime(true);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    ana_cloud_run_job_log(sprintf("PDF_CONVERTER_WARMUP http=%d %.2fs", $code, microtime(true) - $t0));
    return $code;
}

function call_pdf_converter_api_async($url, $payload) {
    // Cloud Run 認証用 ID トークン取得
    $key_file       = '/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-691c42569236.json';
    $target_audience = $GLOBALS["pdf_converter_base_url"] ?? 'https://pdf-converter-512697354748.asia-northeast1.run.app';
    $id_token = get_cloud_run_id_token($key_file, $target_audience);

    $headers = ['Content-Type: application/json'];
    if ($id_token) {
        $headers[] = "Authorization: Bearer {$id_token}";
    } else {
        error_log("call_pdf_converter_api_async: ID token 取得失敗、認証なしで送信します");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT_MS     => PDF_CONVERTER_FASTFAIL_WAIT_MS,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $body     = curl_exec($ch);   // タイムアウトで切断されるが Cloud Run 側の処理は継続する
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // http_code = 0 は「待ち時間内に応答が無かった」＝正常に処理継続中。失敗扱いしない。
    return [
        'http_code' => $httpCode,
        'body'      => is_string($body) ? mb_substr($body, 0, 1000) : '',
    ];
}




/**
 * gs://bucket/path/to/name.ext から "name" を返す（拡張子なし）
 */
function gcs_object_base_no_ext($gsUri) {
    $path = preg_replace('#^gs://[^/]+/#', '', $gsUri);
    $bn = basename($path);
    return preg_replace('/\.[^.]+$/', '', $bn);
}



/**
 * 旧: PDF→画像変換してローカルパスを貯める
 * 新: PDFをGCSへアップロードし、GCS(gs://)パスを full_file_Paths に貯める
 */
function appendPdfUploadAndImages(&$img_cont, &$pdf_cont, &$upload_file_key, &$image_txt, &$images_urls, &$files, &$image_files, &$image_file_Paths, &$image_file_Paths_mini, $fileKey, $targetPath, $uploadDir) {
    global $progressFilePath;
    global $gcs_bucket;
    global $insertedId;

    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        gcs_debug_log("NOSET fileKey={$fileKey}");
        return "NOSET";
    }

    // 進捗（ファイルが存在する場合のみ書き込む）
    @file_put_contents($progressFilePath, json_encode(["message" => $pdf_cont."番目PDFのGCSアップロード中"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    $pdf_cont++;
    $ext = pathinfo(basename($_FILES[$fileKey]['name']), PATHINFO_EXTENSION);
    if (empty($ext)) $ext = "pdf";

    // countpages() 側で move_uploaded_file 済みの想定（無ければここで保存）
    $localPath = $targetPath . $fileKey . "." . $ext;
    if (!file_exists($localPath)) {
        if (!move_uploaded_file($_FILES[$fileKey]['tmp_name'], $localPath)) {
            gcs_debug_log("FAILED move_uploaded_file fileKey={$fileKey}");
            throw new RuntimeException("アップロードファイルをローカルへ保存できませんでした: {$fileKey}");
        }
    }

    // 保存先: gs://<bucket>/<ai_case_id>-<fileKey>.pdf
    $objectName = $insertedId . "-" . $fileKey . "." . $ext;

    // debug
    gcs_debug_log("UPLOAD START fileKey={$fileKey} localPath={$localPath} bucket={$gcs_bucket} object={$objectName} size=".(file_exists($localPath)?filesize($localPath):-1));
    gcs_debug_log("StorageClient_exists=".(class_exists("\\Google\\Cloud\\Storage\\StorageClient")?"YES":"NO"));
    gcs_debug_log("GOOGLE_APPLICATION_CREDENTIALS=" . (!empty($GLOBALS["google_application_credentials"]) ? $GLOBALS["google_application_credentials"] : "NOT_SET"));

    $gcsUri = upload_file_to_gcs_sdk($localPath, $gcs_bucket, $objectName);


    // ★ collect outputs for ONE-SHOT Job
    // - PDF gs:// paths for INPUT_GS (Cloud Run Job)
    // - upload_file_key for future use
    // - uploaded filenames for response/debug
    $image_file_Paths[] = $gcsUri;
    $upload_file_key[]  = $fileKey;
    $files[]            = $_FILES[$fileKey]['name'];
    // (Cloud Run Job is triggered once after all uploads)

    gcs_debug_log("UPLOAD DONE fileKey={$fileKey} gcsUri={$gcsUri}");
    return "SETED";
}
function isKeywordMatch($text, $keyword, $threshold = 1.00) {
    $text = mb_convert_kana($text, "KVAS"); // 全角⇔半角の統一
    $keyword = mb_convert_kana($keyword, "KVAS");
    $text = preg_replace('/[ 　]/u', '', $text);
    $keyword = preg_replace('/[ 　]/u', '', $keyword);
    $textChars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    $keywordChars = preg_split('//u', $keyword, -1, PREG_SPLIT_NO_EMPTY);

    $matchCount = 0;
    $textIndex = 0;

    foreach ($keywordChars as $char) {
        $found = false;
        while ($textIndex < count($textChars)) {
            if ($textChars[$textIndex] === $char) {
                $matchCount++;
                $textIndex++;
                $found = true;
                break;
            }
            $textIndex++;
        }
        if (!$found) break; // この文字が見つからなければそれ以降無理
    }

    $ratio = $matchCount / count($keywordChars);
    return $ratio >= $threshold;
}

function containsKeyword($text, $keywords) {
    foreach ($keywords as $keyword) {
        if (isKeywordMatch($text, $keyword)) {
            return true;
        }
    }
    return false;
}
function containsKeywordWithMatch($text, $keywords, &$matchedKeyword) {
    foreach ($keywords as $keyword) {
        if (isKeywordMatch($text, $keyword)) {
            $matchedKeyword = $keyword;
            return true;
        }
    }
    $matchedKeyword = null;
    return false;
}
// ★ 追加：1キーワードでも70%一致すれば除外
function isCapitalChangeLike($text, $keywords, $threshold = 0.70) {
	$text = preg_replace('/[ 　]/u', '', mb_convert_kana($text, "KVAS"));
    foreach ($keywords as $keyword) {
        if (isKeywordMatch($text, $keyword, $threshold)) {
            return true;
        }
    }
    return false;
}
function classifyPage($text) {
	//貸借対照表
    $bsKeywords = [
          "流動資産"
		, "現金及び預金"
		, "売掛金"
		, "繰延資産"
		, "資産の部"
		, "流動負債"
		, "買掛金"
		, "短期借入金"
		, "預り金"
		, "固定負債"
		, "負債の部"
		, "純資産の部"
		, "株主資本"
		, "資本金"
		, "資本剰余金"
		, "利益剰余金"
		, "その他利益剰余金"
		, "評価換算差額等"
		, "新株予約権"
		, "純資産の部合計"
		, "純資産合計"
		, "負債純資産の部合計"
    ];
	//損益計算書
    $plKeywords = [
          "売上高"
		, "売上原価"
		, "期首棚卸高"
		, "仕入高"
		, "期末棚卸高"
		, "売上総利益"
		, "営業利益"
		, "営業損失"
		, "営業外収益"
		, "受取利息"
		, "受取配当金"
		, "営業外費用"
		, "支払利息"
		, "特別利益"
		, "特別損失"
		, "税引前当期利益"
		, "税引前当期損失"
		, "法人税住民税及び事業税"
		, "当期純利益"
		, "当期純損失"
		, "その他収益"
		, "当期収益"
		, "当期損失"
    ];

    $cfKeywords = [
		  "資本等変動計算書"
		, "株主資本等変動計算書"
		, "連結株主資本等変動計算書"
		, "連結持分変動計算書"
    ];
	// ★ 類似70%以上のキーワードがあれば除外
    if (isCapitalChangeLike($text, $cfKeywords, 0.70)) {
        return [
            'type' => '対象外',
            'firstHalfMatch' => [],
			'secondHalfMatch' => [],
            'cfKeywords' => "NG"
        ];
    }
    $lines = preg_split("/\r\n|\n|\r/u", $text);
    $half = ceil(count($lines) / 2);
    $firstHalf = implode("\n", array_slice($lines, 0, $half));
    $secondHalf = implode("\n", array_slice($lines, $half));

    $matchInfo = [
        'type' => '',
        'firstHalfMatch' => [],
        'secondHalfMatch' => []
    ];

    $matched = [];

    // 前半
    $firstBS = containsKeywordWithMatch($firstHalf, $bsKeywords, $matched['firstBS']);
    $firstPL = containsKeywordWithMatch($firstHalf, $plKeywords, $matched['firstPL']);

    if ($firstBS) $matchInfo['firstHalfMatch'][] = $matched['firstBS'];
    if ($firstPL) $matchInfo['firstHalfMatch'][] = $matched['firstPL'];

    // 後半
    $secondBS = containsKeywordWithMatch($secondHalf, $bsKeywords, $matched['secondBS']);
    $secondPL = containsKeywordWithMatch($secondHalf, $plKeywords, $matched['secondPL']);

    if ($secondBS) $matchInfo['secondHalfMatch'][] = $matched['secondBS'];
    if ($secondPL) $matchInfo['secondHalfMatch'][] = $matched['secondPL'];

    // 判定ロジック
    if ($firstBS && $secondPL) $matchInfo['type'] = "BS=>PL";
    elseif ($firstPL && $secondBS) $matchInfo['type'] = "PL=>BS";
    elseif ($firstBS || $secondBS) $matchInfo['type'] = "BS";
    elseif ($firstPL || $secondPL) $matchInfo['type'] = "PL";
    else $matchInfo['type'] = "対象外";

    return $matchInfo;
}
$user_id=get_user_id_for_now($loginkey);
if(empty($user_id)){
	$user_id=99;
}
//案件情報初期化
$insertedId = null;
$stmt = $conn->prepare("INSERT INTO ai_case(
      user_id
    , update_user_id
    , create_at
    , update_at
    , queue_id
    , status) VALUES (
      ".$user_id."
    , ".$user_id."
    , now()
    , now()
    , NULL
    , 'INIT')");
if ($stmt->execute()) {
    $insertedId = $conn->insert_id;  // ← ここで取得できます

    // case_name: "YYYYMMDD-HH:mm:ss-新しいファイルのアップロード"
    $caseName = date("Ymd") . "-" . date("H:i:s") . "-新しいファイルのアップロード";
    $stmtCaseName = $conn->prepare("UPDATE ai_case SET case_name = ? WHERE ai_case_id = ?");
    $stmtCaseName->bind_param("si", $caseName, $insertedId);
    $stmtCaseName->execute();
} else {
    echo json_encode([
        "status" => "error",
        "message" => "案件保存失敗した: " . $stmt->error
    ]);
}

global $www_dir;
// プログレスファイルパスの作成
$progressFilePath = $www_dir . "/progress/" . $insertedId;
global $progressFilePath;
// ディレクトリがなければ作成
if (!is_dir(dirname($progressFilePath))) {
    mkdir(dirname($progressFilePath), 0777, true);
}



$randomName = time() . generateRandomString(10);
$targetPath = $uploadDir . $randomName;
// PDFページ数チェック（仕様: 最大3ファイル / 1ファイル50MB・10ページ以内 / 合計30ページ以内）
//   → 合計ページ数ではなく「1ファイルずつ」10ページ以内かを判定する。
//   countpages() 内で10ページ超のファイルがあれば即エラー終了。
if ($postingPeriod === "P1") {
    countpages('currentTermBalanceSheet', $targetPath);
    countpages('previousTermBalanceSheet', $targetPath);
    countpages('twoTermsAgoBalanceSheet', $targetPath);
} elseif ($postingPeriod === "P2") {
    countpages('currentAndPreviousBalanceSheet', $targetPath);
    countpages('previousAndTwoAgoBalanceSheet', $targetPath);
	countpages('twoAgoAndTwoPeriodsAgoBalanceSheet', $targetPath);
} elseif ($postingPeriod === "P3") {
    countpages('doublePreviousAndTwoAgoBalanceSheet', $targetPath);
}
// 50MB 制限（バイト）
$MAX_FILE_SIZE = 50 * 1024 * 1024;

foreach ($_FILES as $fileKey => $fileInfo) {

    // 複数ファイル対応
    if (is_array($fileInfo['name'])) {
        foreach ($fileInfo['size'] as $idx => $size) {
            if ($size > $MAX_FILE_SIZE) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'アップロードできるファイルサイズは1ファイル50MBまでです。',
                    'file'    => $fileInfo['name'][$idx]
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }
        }
    } else {
        // 単一ファイル
        if ($fileInfo['size'] > $MAX_FILE_SIZE) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'status'  => 'error',
                'message' => 'アップロードできるファイルサイズは1ファイル50MBまでです。',
                'file'    => $fileInfo['name']
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// ★ 1日前より古いファイルを削除（progressディレクトリ内）
$progressDir = $www_dir . "/progress/";
if (is_dir($progressDir)) {
    $files = scandir($progressDir);
    $now = time();
    foreach ($files as $file) {
        if ($file === '.htaccess') continue; // .htaccessは削除しない
        $filePath = $progressDir . $file;
        if (is_file($filePath)) {
            $lastModified = filemtime($filePath);
            if ($now - $lastModified > 86400) {
                @unlink($filePath);
            }
        }
    }
}

// JSON形式で「分析開始」と書き込む
$old_umask = umask(0);
file_put_contents($progressFilePath, json_encode(["message" => "画像生成中"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
chmod($progressFilePath, 0666); // 誰でも読み書き可能
umask($old_umask);

$uploadedFilenames = [];
$image_files = [];
$image_file_Paths = [];
$image_file_Paths_mini = [];
$image_txt = [];
$images_urls = [];
$upload_file_key = [];//postした時のファイルのキーワード
$not_set_object = array();
$setting=array();
$pdf_cont = 1;
$img_cont = 1;
if ($postingPeriod === "P1") {
    $set_currentTermBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'currentTermBalanceSheet', $targetPath, $uploadDir);
    if($set_currentTermBalanceSheet == "NOSET"){
		array_push($not_set_object,"currentTermBalanceSheet");
		$ufkey="currentTermBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
    $set_previousTermBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'previousTermBalanceSheet', $targetPath, $uploadDir);
    if($set_previousTermBalanceSheet == "NOSET"){
		array_push($not_set_object,"previousTermBalanceSheet");
		$ufkey="previousTermBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
    $set_twoTermsAgoBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'twoTermsAgoBalanceSheet', $targetPath, $uploadDir);
    if($set_twoTermsAgoBalanceSheet == "NOSET"){
		array_push($not_set_object,"twoTermsAgoBalanceSheet");
		$ufkey="twoTermsAgoBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
} elseif ($postingPeriod === "P2") {
    $set_currentAndPreviousBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'currentAndPreviousBalanceSheet', $targetPath, $uploadDir);
    if($set_currentAndPreviousBalanceSheet == "NOSET"){
		array_push($not_set_object,"currentAndPreviousBalanceSheet");
		$ufkey="currentAndPreviousBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
    $set_previousAndTwoAgoBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'previousAndTwoAgoBalanceSheet', $targetPath, $uploadDir);
    if($set_previousAndTwoAgoBalanceSheet == "NOSET"){
		array_push($not_set_object,"previousAndTwoAgoBalanceSheet");
		$ufkey="previousAndTwoAgoBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
	$set_twoAgoAndTwoPeriodsAgoBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'twoAgoAndTwoPeriodsAgoBalanceSheet', $targetPath, $uploadDir);
    if($set_twoAgoAndTwoPeriodsAgoBalanceSheet == "NOSET"){
		array_push($not_set_object,"twoAgoAndTwoPeriodsAgoBalanceSheet");
		$ufkey="twoAgoAndTwoPeriodsAgoBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
} elseif ($postingPeriod === "P3") {
    $set_doublePreviousAndTwoAgoBalanceSheet = appendPdfUploadAndImages($img_cont, $pdf_cont, $upload_file_key, $image_txt, $images_urls, $uploadedFilenames, $image_files, $image_file_Paths, $image_file_Paths_mini, 'doublePreviousAndTwoAgoBalanceSheet', $targetPath, $uploadDir);
	if($set_doublePreviousAndTwoAgoBalanceSheet == "NOSET"){
		array_push($not_set_object,"doublePreviousAndTwoAgoBalanceSheet");
		$ufkey="doublePreviousAndTwoAgoBalanceSheet";
		$setting[$ufkey]=array();
		$setting[$ufkey]["seted_all"]=0;
		$setting[$ufkey]["seted_pl"]=0;
		$setting[$ufkey]["seted_bs"]=0;
	}
}
$files_str = implode("|,|", $uploadedFilenames);
$urls_str = ""; // 画像URLはCloud Runで生成する
$upload_file_keys = implode("|,|", $upload_file_key);
$full_file_Paths = implode("|,|", $image_file_Paths);

// ==========================================================
// 写真撮影PDF判定 (ana-aiocr-photo /v1/classify を呼ぶ)
//   - この時点ではユーザはまだ「データ読取」ボタンを押していないため
//     Claude API は呼ばない (classify は判定のみ・APIコスト0)
//   - 1ファイルでも photo_pdf 判定が出れば is_photo_pdf=1 とし、
//     do_ai.php 側で ana-aiocr-photo.php (Claude AI-OCR) に振り分ける
//   - 失敗時は is_photo_pdf=0 のまま（既存ロジックにフォールバック）
// ==========================================================
$is_photo_pdf = 0;
if (!empty($image_file_Paths)) {
    try {
        // dev / prod 切替 (port 8012 = prod = -real-)
        $service_url_aiocr = "https://ana-aiocr-photo-512697354748.asia-northeast1.run.app";
        if (($GLOBALS["PORT"] ?? 0) == 8012) {
            $service_url_aiocr = "https://ana-aiocr-photo-real-512697354748.asia-northeast1.run.app";
        }
        $endpoint_classify_aiocr = rtrim($service_url_aiocr, "/") . "/v1/classify";

        // get_cloud_run_id_token() は本ファイル内で定義済み (JWT署名方式)
        $sa_key_file_aiocr = "/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-691c42569236.json";
        $idToken_aiocr = get_cloud_run_id_token($sa_key_file_aiocr, $service_url_aiocr);

        if (!empty($idToken_aiocr)) {
            $payload_classify = [
                "ai_case_id" => $insertedId,
                "pdfurls"    => $image_file_Paths,  // gs:// URI のリスト
            ];
            $ch = curl_init($endpoint_classify_aiocr);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $idToken_aiocr,
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS,
                json_encode($payload_classify, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            $classifyBody = curl_exec($ch);
            $classifyCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($classifyBody !== false && $classifyCode >= 200 && $classifyCode < 300) {
                $decodedClassify = json_decode($classifyBody, true);
                if (is_array($decodedClassify) && !empty($decodedClassify['results'])) {
                    foreach ($decodedClassify['results'] as $rcls) {
                        if (($rcls['routing'] ?? '') === 'claude_ai_ocr') {
                            $is_photo_pdf = 1;
                            break;
                        }
                    }
                }
                ana_upload_log("AIOCR_CLASSIFY ai_case_id={$insertedId} is_photo_pdf={$is_photo_pdf} http={$classifyCode}");
            } else {
                ana_upload_log("AIOCR_CLASSIFY ai_case_id={$insertedId} HTTP {$classifyCode} body=" . substr((string)$classifyBody, 0, 500));
            }
        } else {
            ana_upload_log("AIOCR_CLASSIFY ai_case_id={$insertedId} ID Token 取得失敗 -> is_photo_pdf=0");
        }
    } catch (Throwable $e) {
        // 判定失敗時は is_photo_pdf=0 (既存ロジックにフォールバック) のままにする
        ana_upload_log("AIOCR_CLASSIFY ai_case_id={$insertedId} EXCEPTION: " . $e->getMessage());
    }
}

// ==========================================================
// Cloud Run Job call (ONE SHOT)
//  - 複数PDFアップロード時に「PDFごとにジョブ起動」にならないように、ここでまとめて1回だけ起動する。
//  - INPUT_GS  : 複数なら JSON配列文字列、単数なら gs://... の文字列
//  - OUTPUT_GS : 複数なら gs://<bucket>/converted/（末尾/必須）, 単数なら従来どおり prefix-
//  - UPLOAD_FILE_KEYS : 追加でまとめて渡す（将来Job側で利用）
// ==========================================================
if (!empty($GLOBALS["enable_cloud_run_job"]) && $GLOBALS["enable_cloud_run_job"] === true) {

    $projectId = !empty($GLOBALS["cloud_run_project_id"]) ? $GLOBALS["cloud_run_project_id"] : (getenv("GOOGLE_CLOUD_PROJECT") ?: "gen-lang-client-0018414550");
    $region    = !empty($GLOBALS["cloud_run_region"]) ? $GLOBALS["cloud_run_region"] : (getenv("CLOUD_RUN_REGION") ?: "asia-northeast1");
    $jobName   = !empty($GLOBALS["cloud_run_job_name"]) ? $GLOBALS["cloud_run_job_name"] : "";

    if (empty($jobName)) {
        ana_cloud_run_job_log("SKIP jobName is empty. ai_case_id={$insertedId}");
    } else {
        try {
            $inputGs = "";
            $outputGs = "";

            if (count($image_file_Paths) === 0) {
                throw new RuntimeException("Cloud Run Jobに渡すINPUT_GSが空です（PDFのGCSアップロードに失敗している可能性があります）");
            }
            $uploadFileKeysJson = json_encode($upload_file_key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (count($image_file_Paths) > 1) {
                // 複数PDF: Job側の parse_input_gs_list が読めるように JSON配列で渡す
                $inputGs = json_encode($image_file_Paths, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                // Job側は複数入力 + OUTPUT_GS が末尾/でないとエラーにしているため、dir指定にする
                $outputGs = "gs://{$gcs_bucket}/converted/";
            } else {
                // 単数PDF: 従来互換（prefix-）
                $inputGs = $image_file_Paths[0];
                $base = gcs_object_base_no_ext($inputGs);
                $outputGs = "gs://{$gcs_bucket}/converted/{$base}-";
            }

            ana_cloud_run_job_log("JOB START(ONE) job={$jobName} input={$inputGs} output={$outputGs} upload_file_keys={$uploadFileKeysJson}");

            $op = run_cloud_run_job($projectId, $region, $jobName, [
                "INPUT_GS"          => $inputGs,
                "OUTPUT_GS"         => $outputGs,
                "UPLOAD_FILE_KEYS"  => $uploadFileKeysJson,
                "MYSQL_DB"          => $GLOBALS["MYSQL_DB"],
            ]);

            $GLOBALS["cloud_run_job_runs"] = [[
                "job"       => $jobName,
                "inputs"    => $image_file_Paths,
                "output"    => $outputGs,
                "operation" => $op,
                "upload_file_keys" => $upload_file_key,
            ]];

            $opName = (is_array($op) && isset($op["name"])) ? $op["name"] : "(no-name)";
            ana_cloud_run_job_log("JOB OK(ONE) op={$opName}");

        } catch (Exception $e) {
            $GLOBALS["cloud_run_job_runs"] = [[
                "job"     => $jobName,
                "inputs"  => $image_file_Paths,
                "error"   => $e->getMessage(),
                "upload_file_keys" => $upload_file_key,
            ]];
            ana_cloud_run_job_log("JOB ERROR(ONE) " . $e->getMessage());
        }
    }
}





// ==========================================================
// Cloud Run Service API call (ONE SHOT) - /convert
//  - ここでまとめて1回だけ呼び出してPDF→画像変換させる
// ==========================================================
if (!empty($GLOBALS["enable_pdf_converter_api"]) && $GLOBALS["enable_pdf_converter_api"] === true) {
    try {
        if (count($image_file_Paths) === 0) {
            throw new RuntimeException("pdf-converter APIに渡すinput_gsが空です（PDFのGCSアップロードに失敗している可能性があります）");
        }

        // input_gs: list of gs://...pdf
        $inputGsList = $image_file_Paths;

        // output_gs:
        //  - 複数PDF: dir指定（末尾/）
        //  - 単数PDF: prefix-（従来互換）
        if (count($inputGsList) > 1) {
            $outputGs = "gs://{$gcs_bucket}/converted/";
        } else {
            $base = gcs_object_base_no_ext($inputGsList[0]);
            $outputGs = "gs://{$gcs_bucket}/converted/{$base}-";
        }

        $payload = [
            "input_gs" => $inputGsList,
            "output_gs" => $outputGs,
            "upload_file_keys" => json_encode($upload_file_key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            "ai_case_id" => (string)$insertedId,
            // mysql は Cloud Run 側で不要なら無視される想定
            "mysql_host" => (!empty($GLOBALS["MYSQL_HOST"]) ? $GLOBALS["MYSQL_HOST"] : ""),
            "mysql_user" => (!empty($GLOBALS["MYSQL_USER"]) ? $GLOBALS["MYSQL_USER"] : ""),
            "mysql_password" => (!empty($GLOBALS["MYSQL_PASSWORD"]) ? $GLOBALS["MYSQL_PASSWORD"] : ""),
            "mysql_db" => (!empty($GLOBALS["MYSQL_DB"]) ? $GLOBALS["MYSQL_DB"] : ""),
            "port" => (int)($GLOBALS["PORT"] ?? 8056),
            "read_sga" => true,
            "read_mcr" => true,
        ];

        $GLOBALS["pdf_converter_api_request"] = $payload;

        ana_cloud_run_job_log("PDF_CONVERTER_API ASYNC START url={$GLOBALS["pdf_converter_api_url"]} output={$outputGs} inputs=" . json_encode($inputGsList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // 2026-10-13 追加: コールドスタート対策。先にインスタンスを起こしてから本番リクエストを送る。
        warmup_pdf_converter($GLOBALS["pdf_converter_base_url"]);

        $convRes  = call_pdf_converter_api_async($GLOBALS["pdf_converter_api_url"], $payload);
        $convCode = (int)($convRes['http_code'] ?? 0);

        // 2026-10-13 追加: 明確な失敗(>=400)は変換が始まっていないので、起動を待って1回だけ再送する。
        //   ※ http=0 は「応答待ちの間に切断した」だけで正常に変換中の場合も該当するため再送しない。
        //     ここで再送すると同じ案件を二重に変換してしまう。
        if ($convCode >= 400) {
            ana_cloud_run_job_log("PDF_CONVERTER_API RETRY ai_case_id={$insertedId} first_http={$convCode}");
            sleep(12);
            $convRes  = call_pdf_converter_api_async($GLOBALS["pdf_converter_api_url"], $payload);
            $convCode = (int)($convRes['http_code'] ?? 0);
        }

        if ($convCode >= 400) {
            // 再送しても失敗している。放置すると status='UPST'（画面表示「帳票識別中」）の
            // まま永久に残ってしまうため、後段の UPDATE で 'AIERR' を立てる。
            $GLOBALS["pdf_converter_failed"] = true;
            ana_cloud_run_job_log("PDF_CONVERTER_API FAILED ai_case_id={$insertedId} http={$convCode} body=" . ($convRes['body'] ?? ''));
        } else {
            ana_cloud_run_job_log("PDF_CONVERTER_API ASYNC FIRED ai_case_id={$insertedId} http={$convCode}");
        }

    } catch (Exception $e) {
        ana_cloud_run_job_log("PDF_CONVERTER_API ERROR " . $e->getMessage());
        // 変換を依頼できていないので、後段の UPDATE で 'AIERR' にする（'UPST'「帳票識別中」のまま残さない）
        $GLOBALS["pdf_converter_failed"] = true;
    }
}


$ttt = time();
$baseDir = __DIR__; // このPHPファイルのあるディレクトリ
$scriptPath = $baseDir . "/upload_files_sub_8dj4.php";

$cmd = ""; // Cloud Run側で処理するためサブプロセス起動しない
//$imgcommdout=$cmd;

// SQL構文修正：$parameters を直接埋め込まず、? を使う
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now(),
            update_user_id=?,
		upload_file_name=?,
		img_urls=?,
		full_file_Paths=?,
		upload_file_keys=?,
		case_name=?,
        status='UPLD'
        WHERE ai_case_id = ?
");

// 案件名: アップロードされた先頭ファイル名を使う。
// $uploadedFilenames は appendPdfUploadAndImages() の呼び出し順で積まれるため、
// [0] は getpdfinfo.php の期判定で currentTermBalanceSheet に割り当てられたファイル。
// ファイル名が40文字を超える場合は40文字で切って「...」を付ける。
// 複数ファイルの場合はさらに末尾に「（+N）」で残り件数を添える。
//   例) 3ファイル                → "xxxxx.pdf（+2）"
//   例) 長いファイル名＋3ファイル → "aaaa……（40文字）...（+2）"
// ファイル名が取得できない場合は従来の日時形式にフォールバックする。
$case_name_new = date("Ymd") . "-" . date("H:i:s") . "-新しいファイルのアップロード";
$case_name_files = array_values(array_filter(
    array_map('trim', (array)$uploadedFilenames),
    static function ($v) { return $v !== ''; }
));
if (!empty($case_name_files)) {
    $case_name_base = (string)$case_name_files[0];
    if (mb_strlen($case_name_base, "UTF-8") > 40) {
        $case_name_base = mb_substr($case_name_base, 0, 40, "UTF-8") . "...";
    }
    $case_name_rest   = count($case_name_files) - 1;
    $case_name_suffix = ($case_name_rest > 0) ? ('（+' . $case_name_rest . '）') : '';
    // ai_case.case_name は varchar(400)。念のため桁あふれも防いでおく。
    $case_name_limit  = 400 - mb_strlen($case_name_suffix, "UTF-8");
    $case_name_new    = mb_substr($case_name_base, 0, $case_name_limit, "UTF-8")
                        . $case_name_suffix;
}

$stmt->bind_param("isssssi", $user_id, $files_str, $urls_str, $full_file_Paths, $upload_file_keys, $case_name_new, $insertedId);
$stmt->execute();

// is_photo_pdf は別 UPDATE で保存する。
//   - DBに is_photo_pdf カラム未追加の環境では prepare() が FALSE を返す → 静かにスキップ
//   - これにより既存環境 (カラム未追加) でも アップロード全体が壊れない
$stmtPhoto = @$conn->prepare("UPDATE ai_case SET is_photo_pdf=? WHERE ai_case_id = ?");
if ($stmtPhoto) {
    @$stmtPhoto->bind_param("ii", $is_photo_pdf, $insertedId);
    @$stmtPhoto->execute();
    @$stmtPhoto->close();
}

// レスポンス直前：ai_case.status を 'MakeImageStart' に更新
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at = NOW(),
            update_user_id = ?,
            status = 'MakeImageStart'
        WHERE ai_case_id = ?
");
$stmt->bind_param("ii", $user_id, $insertedId);
$stmt->execute();

$request_up=[
    "status" => "success",
	"postingPeriod" => $postingPeriod,
	"ai_case_id" => $insertedId,
	"not_set_object" => $not_set_object,
	"setting" => $setting,
	"imgcommdout" => $imgcommdout,
	"gcs_paths" => $image_file_Paths,
	"read_sga" => $read_sga,
	"read_mcr" => $read_mcr,
	"is_photo_pdf" => $is_photo_pdf,
    "api_request" => ($GLOBALS["pdf_converter_api_request"] ?? null),
    "message" => "アップロードが終わりました、帳票種類の判断結果を取得してください"
];
$json = json_encode($request_up, JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now(),
            update_user_id=?,
		imgtype_request=?,
            status=?
        WHERE ai_case_id = ?
");

// 変換APIが即エラーを返していた場合は 'AIERR'（画面表示「OCR読取失敗」）にする。
// そうしないと 'UPST'（「帳票識別中」）のまま永久に残ってしまう。
$final_status = !empty($GLOBALS["pdf_converter_failed"]) ? 'AIERR' : 'UPST';
$stmt->bind_param("issi", $user_id, $json, $final_status, $insertedId);
$stmt->execute();



// exec($cmd); // Cloud Run側で処理するため実行しない
// 第一レスポンス（画像のbase64とファイル名）
echo json_encode($request_up, JSON_UNESCAPED_UNICODE);