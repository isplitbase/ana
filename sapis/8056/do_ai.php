<?php
include "./common.php";
require_once "./system_log_helper.php";
global $conn;
global $uploadDir;
global $backendbase;
global $python_base;
global $super_message;
global $damy_flag;
global $point1_1;
global $point1_2;
global $point1_3;
global $point2;
global $point3;
// JSONデータを受け取って配列に変換
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') !== false) {
	$data = json_decode(file_get_contents("php://input"), true);
} else {
	$data = $_POST;
}

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$loginkey = $data['loginkey'] ?? null;
$postingPeriod = $data['postingPeriod'] ?? null;
$images = $data['images'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;
$nodoai = $data['nodoai'] ?? false;

// nodoai==false または nodoai=="RETRY" の場合だけAI実施
if ($nodoai !== false && $nodoai !== "RETRY") {
    echo json_encode([
        "status" => "warning",
        "message" => "AI処理をスキップしました"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ログイン必須 ＋ 案件が同じ会社のものか確認（loginkey が無い／他社の案件ならエラーで終了）
require_once __DIR__ . "/_case_access_helper.php";
$user_id = ana_require_case_access($loginkey, $ai_case_id);

// 期数は P1・P2・P3 のみ。それ以外は料金が決まらず無料で読取できてしまうため、何も書き込まずに止める
if (!in_array($postingPeriod, ['P1', 'P2', 'P3'], true)) {
    echo json_encode(["status" => "error", "message" => "異常アクセス"], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$images || !is_array($images)) {
    http_response_code(200);
    echo json_encode(['error' => '画像情報が不正または存在しません']);
    exit;
}
$totalPages=0;
foreach ($images as $img) {
	if(empty($img["page_type_id"]) and !empty($img["page_type_ids"]) and count($img["page_type_ids"])>0){
		$img["page_type_id"]=$img["page_type_ids"][0];
	}
	if($img["page_type_id"]!=2){
		$totalPages++;
	}
}

if ($totalPages >= 15) {
	http_response_code(200);
	echo json_encode([
		"status" => "warning",
		"message" => "PDFのページ数が15ページを超えているため、処理を中断しました（現在: {$totalPages}ページ）"
	], JSON_UNESCAPED_UNICODE);
	exit;
}
if ($totalPages == 0) {
	http_response_code(200);
	echo json_encode([
		"status" => "warning",
		"message" => "読取対象がありません、処理を中断しました"
	], JSON_UNESCAPED_UNICODE);
	exit;
}
/////////////////////////////////////////////////////////////////
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
	$user_id= $row['user_id'];
}
$postingPeriod_str="(1期分)";
// 投稿期間ごとのポイント計算
switch ($postingPeriod) {
    case "P1":
        $use_point = $totalPages * $point1_1;
        break;
    case "P2":
        $use_point = $totalPages * $point1_2;
		$postingPeriod_str="(2期分)";
        break;
    case "P3":
        $use_point = $totalPages * $point1_3;
		$postingPeriod_str="(3期分)";
        break;
}

// ポイント残高チェックなし（point残高の概念を廃止）

/////////////////////////////////////////////////////////


// ファイルルートを取得する
$stmt = $conn->prepare("
    SELECT img_urls
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$img_urls="";
// 結果をループして出力
while ($re = $result->fetch_assoc()) {
    $img_urls= $re['img_urls'];
}
$img_urls = explode('|,|', $img_urls);
$request=json_encode($data, JSON_UNESCAPED_UNICODE);
// SQL構文修正：$parameters を直接埋め込まず、? を使う
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now(),
		request=?,
            status='AIST',
            update_user_id=?
        WHERE ai_case_id = ?
");

$stmt->bind_param("sii", $request, $user_id, $ai_case_id);
$stmt->execute();

if(count($img_urls)!=count($images)){
	// 上で status='AIST'（OCR読取中）にしているので、失敗にしてから終了する
	do_ai_mark_error($conn, $ai_case_id);
	echo json_encode([
		"status" => "error",
		"error" => "リクエストの画像枚数と案件の画像枚数と違います",
		"message" => "リクエストの画像枚数と案件の画像枚数と違います"
	], JSON_UNESCAPED_UNICODE);
	exit();
}
foreach ($img_urls as $path) {
    // gs:// パスはローカルの file_exists() でチェックできないためスキップ
    if (strpos($path, 'gs://') === 0) {
        continue;
    }
    if (!file_exists($path)) {
        do_ai_mark_error($conn, $ai_case_id);
        http_response_code(200);
        echo json_encode([
            'status' => 'error',
            'error' => "画像ファイルが存在しません: {$path}",
            'message' => "システムエラーが発生しました、アップロードからやり直してください。"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
$localImages = [];
$localTypes = [];
$localkakudos = [];
$respons = [];
$upload_file_key = [];
$page_nos = [];
$i=0;
foreach ($images as $img) {
    if (!isset($img_urls[$i], $img["page_type_id"], $img["rotation"])) {
        continue;
    }
	if($img["page_type_id"]!=1){
		//continue;
	}
	//upload_file_key
	//currentTermBalanceSheet 今期
	//previousTermBalanceSheet 前期
	//twoTermsAgoBalanceSheet 前々期
	//currentAndPreviousBalanceSheet 今期と前期
	//previousAndTwoAgoBalanceSheet 前期と前々期
	//doublePreviousAndTwoAgoBalanceSheet 今期と前期と前々期
	
    // $parsedUrl = parse_url($img["images_urls"]);
    // $filename = basename($parsedUrl['path']);
    // $localPath = $uploadDir . $filename;

    $localImages[] = $img_urls[$i];
	if($img["page_type_id"]==1){
		$localTypes[] = implode("@,@", $img["page_type_ids"]);
		//$localTypes[] = "xxxxx";
	}else if($img["page_type_id"]==3){
		$localTypes[] = implode("@,@", $img["page_type_ids"]);
		//$localTypes[] = "yyyyy";
	}else if($img["page_type_id"]==4){
		$localTypes[] = implode("@,@", $img["page_type_ids"]);
		//$localTypes[] = "zzzzz";
	}else{
		$localTypes[] = "2";
	}
    
    //$localkakudos[] = $img["kakudo"];
	$localkakudos[] = $img["rotation"];
	$upload_file_key[] = $img["upload_file_key"];
	$page_nos[] = $img["page_no"];
	
    //$respons = call_ai($localPath, $img["page_type"]["type"], $img["kakudo"], $img["upload_file_key"]);
    //break; // 1枚目だけ処理
	$i++;
}

$imageListString = implode("|,|", $localImages);
$typeListString = implode("|,|", $localTypes);
$kakudoListString = implode("|,|", $localkakudos);
$upload_file_keyString = implode("|,|", $upload_file_key);
$page_nosString = implode("|,|", $page_nos);
// echo json_encode([
    // "status" => "success",
    // "respons" => $respons,
    // "localImages" => $localImages,
	// "super_message" => $super_message,
    // "message" => "読取が終わりました"
// ], JSON_UNESCAPED_UNICODE);
// exit();
// ===============================================================
// 写真撮影PDF 分岐
//   upload_files.php で /v1/classify により is_photo_pdf=1 が立っている場合は
//   既存の call_ai()(Gemini/GPT 経由) ではなく
//   ana-aiocr-photo.php (Claude AI-OCR via Cloud Run) を非同期で起動する。
//
//   is_photo_pdf カラムが存在しない / 未追加環境では prepare() が FALSE を返す → 0扱い
// ===============================================================
$is_photo_pdf = 0;
$stmtPhoto = @$conn->prepare("SELECT is_photo_pdf FROM ai_case WHERE ai_case_id = ?");
if ($stmtPhoto) {
    @$stmtPhoto->bind_param("i", $ai_case_id);
    @$stmtPhoto->execute();
    $resPhoto = @$stmtPhoto->get_result();
    $rowPhoto = $resPhoto ? $resPhoto->fetch_assoc() : null;
    $is_photo_pdf = (int)($rowPhoto['is_photo_pdf'] ?? 0);
    @$stmtPhoto->close();
}

if ($is_photo_pdf === 1) {
    // 写真撮影PDF: ana-aiocr-photo.php を非同期起動 (Claude OCR via Cloud Run)
    $ttt = time();
    $workerPath = escapeshellarg(__DIR__ . "/ana-aiocr-photo.php");
    $logDir = "/var/log/pys";
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logPath = "{$logDir}/aiocr_{$ai_case_id}_{$ttt}.log";
    @touch($logPath);
    @chmod($logPath, 0666);
    $command = "php $workerPath " . escapeshellarg((string)$ai_case_id) . " > $logPath 2>&1 &";
    exec($command);
    $respons = ["message" => "AI-OCR 起動しました", "command" => $command];
    $super_message = $command;
} else {
    // 通常PDF: 既存の Gemini/GPT 経由
    $respons = call_ai($imageListString, $typeListString, $kakudoListString, $upload_file_keyString, $ai_case_id);
}

// call_ai がエラーを返した場合は終了（ポイント消費はget_ai_response.php側に移管）
if (isset($respons['error'])) {
    write_system_log($conn, 'error', 'API', 'OCR_FAILED', 'OCR処理（PDF読取）が失敗しました',
        user_id:    $user_id ?? null,
        ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
        detail:     ['ai_case_id' => $ai_case_id, 'error' => $respons['error']]
    );
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => $respons['error'],
        "ai_case_id" => $ai_case_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// --- 以降の処理 ---
$stmt = $conn->prepare("
    SELECT img_urls
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$img_urls = "";
while ($re = $result->fetch_assoc()) {
    $img_urls = $re['img_urls'];
}
$img_urls = explode('|,|', $img_urls);

$request = json_encode($data, JSON_UNESCAPED_UNICODE);
$stmt = $conn->prepare("
    UPDATE ai_case
    SET update_at = NOW(),
        request = ?,
        status = '1000',
        cmdtext = ?,
        update_user_id = ?
    WHERE ai_case_id = ?
");
$stmt->bind_param("ssii", $request, $respons["command"], $user_id, $ai_case_id);
$stmt->execute();

///////////////////////////////////////
// nodoai==false の場合のみ do_ai_start=1 をセット
// （ポイント消費とusede追加は get_ai_response.php で実施）
///////////////////////////////////////
if ($nodoai === false) {
    $stmtStart = $conn->prepare("
        UPDATE ai_case
            SET do_ai_start = 1, update_at = NOW(), update_user_id = ?
            WHERE ai_case_id = ?
    ");
    $stmtStart->bind_param("ii", $user_id, $ai_case_id);
    $stmtStart->execute();
    $stmtStart->close();
}



global $www_dir;
// プログレスファイルパスの作成
$progressFilePath = $www_dir . "/progress/" . $ai_case_id;

// ディレクトリがなければ作成
if (!is_dir(dirname($progressFilePath))) {
    mkdir(dirname($progressFilePath), 0777, true);
}

// JSON形式で「読取開始」と書き込む
file_put_contents($progressFilePath, json_encode(["message" => "読取開始"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
chmod($progressFilePath, 0666); // Python(isplit)からも書き込めるように

write_system_log($conn, 'info', 'API', 'OCR_COMPLETED', 'OCR処理（PDF読取）リクエストが完了しました',
    user_id:    $user_id ?? null,
    ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
    detail:     ['ai_case_id' => $ai_case_id, 'pages' => $totalPages]
);

echo json_encode([
    "status" => "success",
    "respons" => $respons,
	"ai_case_id" => $ai_case_id,
    "localImages" => $localImages,
	"img_urls" => $img_urls,
	"super_message" => $super_message,
	"localTypes" => $localTypes,
    "message" => "読取リクエストを投げました",
], JSON_UNESCAPED_UNICODE);



// ---------- 関数定義 ----------
// 読取を始められずに終了するとき、案件を 'AIERR'（画面表示「OCR読取失敗」）にする。
// そうしないと 'AIST'（「OCR読取中」）のまま残ってしまう。
function do_ai_mark_error($conn, $ai_case_id) {
    $stmt = $conn->prepare("UPDATE ai_case SET status = 'AIERR', update_at = NOW() WHERE ai_case_id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $ai_case_id);
        $stmt->execute();
        $stmt->close();
    }
}

function call_ai($imagePath, $type, $kakudo, $upload_file_key,$ai_case_id) {
    global $python_base;
    global $backendbase;
	global $super_message;
    $escapedImage = escapeshellarg($imagePath);
    $escapedType = escapeshellarg($type);
    $escapedKakudo = escapeshellarg($kakudo);
	$escapedUpload_file_key = escapeshellarg($upload_file_key);
    $scriptPath = escapeshellcmd($python_base . " {$backendbase}callgpt.py");
	$ttt = time();
	$logDir = "/var/log/pys";
	if (!is_dir($logDir)) {
	    mkdir($logDir, 0777, true);
	}
	$logFile = "{$logDir}/{$ai_case_id}_{$ttt}.log";
	// ログファイルを事前に作成して誰でも書き込めるようにする
	touch($logFile);
	chmod($logFile, 0666);
    $command = "$scriptPath $escapedImage $escapedType $escapedKakudo $escapedUpload_file_key $ai_case_id > {$logFile} 2>&1 &";
	$super_message=$command;
	exec($command);
	return ['message' => 'Pythonを動きはじめった','command' => $command];
    // プロセス実行とエラーハンドリング
    $descriptorSpec = [
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w']   // stderr
    ];
    $process = proc_open($command, $descriptorSpec, $pipes);

    if (!is_resource($process)) {
        return ['error' => 'AIスクリプトの実行に失敗しました'];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $returnCode = proc_close($process);

    if ($returnCode !== 0) {
        return [
            'error' => 'Pythonスクリプトでエラーが発生しました',
            'stderr' => $stderr,
			'command' => $command,
            'exit_code' => $returnCode
        ];
    }

    $result = json_decode($stdout, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'error' => 'Python出力のJSONデコード失敗',
            'output' => $stdout,
			'command' => $command,
            'stderr' => $stderr
        ];
    }
    $result["command"]=$command;
    return $result;
}