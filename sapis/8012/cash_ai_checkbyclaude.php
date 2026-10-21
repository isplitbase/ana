<?php
/**
 * cash_ai_checkbyclaude.php
 *
 * フロントから来たリクエスト(JSON)を受け取り、
 * cash_ai.php と同じ common.php 流儀で loginkey を検証した上で、
 * Cloud Run (cash-ai-1-1-checkbyclaude) の /v1/pipeline に転送して結果を返す。
 *
 * - IDトークン生成は cash_ai.php と同じ getIdTokenCredentials($audience) を使用
 * - post は cash_ai.php と同じ postJsonToCloudRun(リトライ付き) を使用
 */

include "./common.php";

global $damy_flag;
global $conn;
global $port;
global $point_check;
// composer autoload（google/auth を使う）
require_once __DIR__ . '/vendor/autoload.php';

use Google\Auth\ApplicationDefaultCredentials;

function getCloudRunIdToken(string $serviceAccountJsonPath, string $audience): string
{
    if (!file_exists($serviceAccountJsonPath)) {
        throw new Exception("Service account key not found: " . $serviceAccountJsonPath);
    }

    // cash_ai.php と同じ
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
    // cash_ai.php と同じ
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

        // リトライ判定（cash_ai.php と同じ）
        $shouldRetry = false;

        if ($curlErrNo !== 0 || $respBody === false) {
            $shouldRetry = true;
        } else {
            if ($httpCode === 0) {
                $shouldRetry = true;
            } elseif ($httpCode === 429) {
                $shouldRetry = true;
            } elseif ($httpCode >= 500) {
                $shouldRetry = true;
            } elseif ($httpCode === 200 || $httpCode === 201) {
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
                    $shouldRetry = true;
                }
            } else {
                $shouldRetry = false;
            }
        }

        if (!$shouldRetry || $attempt >= $maxAttempts) {
            break;
        }

        // 指数バックオフ + ジッター（cash_ai.php と同じ）
        $delay = $baseDelayMs * (1 << ($attempt - 1));
        $jitter = random_int(0, 120);
        usleep(($delay + $jitter) * 1000);
    }

    return [$lastRespBody, $lastCurlErrNo, $lastCurlErr, $lastHttpCode];
}

/** JSON読み込み */
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

$origData = $data; // 元の入力データを保持（ページ数・ファイル名などに使う）
/** 認証（cash_ai.php と同じ common.php 流儀） */
$loginkey = $data['loginkey'] ?? null;

if ($damy_flag != "OK") {
    if (isset($loginkey)) {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id === null) {
            http_response_code(403);
            header("Content-Type: application/json; charset=utf-8");
            echo json_encode([
                "status" => "error",
                "message" => "異常アクセス"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        http_response_code(400);
        header("Content-Type: application/json; charset=utf-8");
        echo json_encode([
            "status" => "error",
            "message" => "loginkey がありません"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$ai_case_id = $data['ai_case_id'] ?? null;

$stmt = $conn->prepare("
    SELECT response,request,cf_main_html,cbc_cout,full_file_Paths
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$response="";
$request="";
$cf_main_html="";
$cbc_cout=0;
$full_file_Paths="";
while ($re = $result->fetch_assoc()) {
    $response= $re['response'];
    $request= $re['request'];
    $cf_main_html= $re['cf_main_html'];
	$cbc_cout=$re['cbc_cout'];
	$full_file_Paths=$re['full_file_Paths'];
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
        $tmpobj["前期"]=array();
        $tmpobj["前期"]["金額"]=$da["前期"]["金額"];
        $tmpobj["前々期"]=array();
        $tmpobj["前々期"]["金額"]=$da["前々期"]["金額"];
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
        $tmpobj["前期"]=array();
        $tmpobj["前期"]["金額"]=$da["前期"]["金額"];
        $tmpobj["前々期"]=array();
        $tmpobj["前々期"]["金額"]=$da["前々期"]["金額"];
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
    $data2["製造原価"] = $data['MFG'];
}
if(isset($response["page_sheet_map"])){
    $data["page_sheet_map"]=$response["page_sheet_map"];
}
$data2["postingPeriod"]=$request["postingPeriod"];
$data2['nodoai']=false;
$data2['ai_case_id']=$ai_case_id;
$data2['csvdownloadfilename']=$data["csvdownloadfilename"];

// ===== PDF（GCS gs://パス）を Cloud Run に転送 =====
// upload_files.php が ai_case.full_file_Paths に "gs://...pdf|,|gs://...pdf" 形式で保存している。
// これを pdfurls 配列にして送ると、Cloud Run 側 Agent1（PDF読取品質チェック）がPDFを取得できる。
$data2['pdfurls'] = array();
if (isset($full_file_Paths) && $full_file_Paths !== "" && $full_file_Paths !== null) {
    foreach (explode("|,|", $full_file_Paths) as $pdfUri) {
        $pdfUri = trim($pdfUri);
        if ($pdfUri !== "") {
            $data2['pdfurls'][] = $pdfUri;
        }
    }
}
$input=json_encode($data2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$data = $data2;

/////////////////////////////////////////////////////////////////
// 実行情報（ポイント履歴用）
$filename = (string)($origData['filename'] ?? '');
$totalPages = (int)($origData['totalPages'] ?? $origData['pages_number'] ?? 0);
$postingPeriod_str = '';
if (isset($request['postingPeriod'])) {
    if (is_string($request['postingPeriod']) || is_numeric($request['postingPeriod'])) {
        $postingPeriod_str = (string)$request['postingPeriod'];
    } else {
        // 配列/オブジェクトの場合は簡易的にJSON化
        $postingPeriod_str = json_encode($request['postingPeriod'], JSON_UNESCAPED_UNICODE);
    }
}

// ポイントチェック
$point = null;
$use_point = 0;
$user_id=null;
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
    $user_id= $row['id'];
}

$use_point = $point_check;

// ポイント残高チェックなし（point残高の概念を廃止）

/////////////////////////////////////////////////////////

/**
 * サービスアカウント鍵（cash_ai.php と同じパス）
 * ※cash_ai.php が動いているなら、このパスでOKのはず
 */
$serviceAccountJsonPath = "/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-f50b079b0584.json";

/** Cloud Run (checkbyclaude) */
$service_url = "https://ana-cash-ai-1-1-checkbyclaude-512697354748.asia-northeast1.run.app";
if($port=="8012"){
	$service_url = "https://ana-cash-ai-1-1-checkbyclaude-real-512697354748.asia-northeast1.run.app";
}
$audience = $service_url; // ★パス無し（cash_ai.php と同じ考え方）
$url = rtrim($service_url, "/") . "/v1/pipeline";

// 失敗時にusedeへ記録するヘルパー（インライン）
$_insert_cbc_failure = function() use ($conn, $user_id, $point, $ai_case_id) {
    if (!$user_id || !$ai_case_id) return;
    $ip_p  = $_SERVER['REMOTE_ADDR'] ?? '';
    $dev_p = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $nowPoint = (int)($point ?? 0);
    // ai_case_idに紐づくPDF読取のページ数をusedeから取得
    $pagesForInsert = 0;
    $stmtPg = $conn->prepare("SELECT pages_number FROM usede WHERE ai_case_id = ? AND p0 LIKE 'PDF読取%' ORDER BY usede_id DESC LIMIT 1");
    if ($stmtPg) {
        $stmtPg->bind_param("i", $ai_case_id);
        $stmtPg->execute();
        $rowPg = $stmtPg->get_result()->fetch_assoc();
        $stmtPg->close();
        $pagesForInsert = (int)($rowPg['pages_number'] ?? 0);
    }
    $stmtF = $conn->prepare("
        INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1, execution_status)
        VALUES (?, 0, NOW(), '', ?, ?, ?, ?, '解析結果確認失敗', ?, 'failure')
    ");
    if ($stmtF) {
        $stmtF->bind_param("iissii", $user_id, $pagesForInsert, $ip_p, $dev_p, $ai_case_id, $nowPoint);
        $stmtF->execute();
        $stmtF->close();
    }
};

try {
    $idToken = getCloudRunIdToken($serviceAccountJsonPath, $audience);
} catch (Exception $e) {
    $_insert_cbc_failure();
    http_response_code(500);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run 用 IDトークン生成に失敗しました",
        "detail" => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// そのまま転送（加工しない）
list($respBody, $curlErrNo, $curlErr, $httpCode) = postJsonToCloudRun($url, $idToken, $data, 600, 3, 3000);

if ($curlErrNo !== 0) {
    $_insert_cbc_failure();
    http_response_code(502);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run への転送に失敗しました",
        "detail" => $curlErr
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($httpCode <= 0) {
    $_insert_cbc_failure();
    http_response_code(502);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run からのHTTPコードが取得できませんでした",
        "detail" => $respBody
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$respBody = json_decode($respBody, true);
if (json_last_error() !== JSON_ERROR_NONE || !is_array($respBody)) {
    $_insert_cbc_failure();
    // Cloud RunがJSON以外を返した場合はそのまま返す
    http_response_code($httpCode);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode([
        "status" => "error",
        "message" => "Cloud Run の応答JSONの解析に失敗しました",
        "detail" => json_last_error_msg(),
        "raw" => $respBody
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- 実行成功時のみ：ai_case.cbc_cout++ / ポイント減算 / 履歴追加 ----
$shouldUpdate = false;
$statusLower = strtolower((string)($respBody["status"] ?? ""));
$okVal = $respBody["ok"] ?? null;
$hasErrorKey = array_key_exists("error", $respBody) || array_key_exists("errors", $respBody);

if (($httpCode === 200 || $httpCode === 201)) {
    $shouldUpdate = true;
}

// ポイント利用履歴に登録（users.point の更新・残高チェックは廃止）
$pointUpdateInfo = ["ok" => false, "message" => "skipped"];

if ($shouldUpdate) {
    try {
        $conn->begin_transaction();
        if ($cbc_cout > 0) {
            $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
            $device = $_SERVER['HTTP_USER_AGENT'] ?? '';

            // --- ポイント利用履歴に登録（p1 = users.point そのまま）---
            $stmt = $conn->prepare("
                INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
                VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
            ");
            $p0 = "解析結果確認";
            $stmt->bind_param(
                "iisissisi",
                $user_id,
                $use_point,
                $filename,
                $totalPages,
                $ip,
                $device,
                $ai_case_id,
                $p0,
                $point
            );
            $stmt->execute();
            $pointUpdateInfo = ["ok" => true, "point" => $point];
        }
        // --- ai_case.cbc_cout を +1 ---
        $stmt = $conn->prepare("
            UPDATE ai_case
            SET cbc_cout = IFNULL(cbc_cout, 0) + 1
            WHERE ai_case_id = ?
        ");
        $stmt->bind_param("i", $ai_case_id);
        $stmt->execute();
        $conn->commit();
    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $e2) {}
        $pointUpdateInfo = [
            "ok" => false,
            "message" => "ポイント更新処理で例外が発生しました",
            "error_detail" => $e->getMessage(),
            "sql_error" => $conn->error
        ];
    }
}

$respBody["point_update"] = $pointUpdateInfo;

$respBody["requestdata"] = $data;
// Cloud Run 側のHTTPコードをできるだけ維持
http_response_code($httpCode);
header("Content-Type: application/json; charset=utf-8");
echo json_encode($respBody, JSON_UNESCAPED_UNICODE);
exit;