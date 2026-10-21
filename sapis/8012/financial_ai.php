<?php
include "./common.php";
require_once "./system_log_helper.php";
global $conn, $uploadDir, $backendbase, $python_base, $super_message, $openai_api_key, $model_str, $www_dir, $aitext2, $aitext3, $openai_api_keys;
global $point1_1;
global $point1_2;
global $point1_3;
global $point2;
global $point3;

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}
$superout="";
$loginkey = $data['loginkey'] ?? null;
$bslist = $data['BS'] ?? [];
$pllist = $data['PL'] ?? [];
$sgalist = $data['SGA'] ?? [];
$mfglist = $data['MFG'] ?? [];
$ai_case_id = $data['ai_case_id'] ?? null;
$nodoai = $data['nodoai'] ?? false;
$csvdownloadfilename = $data['csvdownloadfilename'] ?? false;
// ログイン必須 ＋ 案件が同じ会社のものか確認（loginkey が無い／他社の案件ならエラーで終了）
require_once __DIR__ . "/_case_access_helper.php";
$user_id = ana_require_case_access($loginkey, $ai_case_id);

// 勘定科目名一覧（重複なし）
// 修正: 戻り値を文字列配列からオブジェクト配列に変更。
// 返す形: [ {"勘定科目":"流動資産","分類":"○○○○○○"}, ... ]
function extractAccountNames($list) {
    $seen = [];
    $result = [];
    foreach ($list as $row) {
        // 今期/前期/前々期のいずれかに金額があるものだけ対象
        $hasAmount = !empty($row['今期']['金額']) || !empty($row['前期']['金額']) || !empty($row['前々期']['金額']);
        if (!$hasAmount) continue;

        $name = isset($row['勘定科目']) ? $row['勘定科目'] : "";
        $type = isset($row['分類']) ? $row['分類'] : "";

        // 一意性は勘定科目+分類の組み合わせで判断
        $key = $name . '|' . $type;
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = [
                '勘定科目' => $name,
                '分類' => $type
            ];
        }
    }
    return array_values($result);
}

// BS/PLを key => 金額 の形式に変換（今期/前期/前々期）
function extractValues($list, $period) {
    $map = [];
    foreach ($list as $row) {
        $name = $row['勘定科目'];
        $amount = $row[$period]['金額'] ?? "";
        if ($amount !== "") {
            $map[$name] = floatval($amount);
        }
    }
    return $map;
}
function sumAccounts($key, $values, $map) {
    $sum = 0;
    $used = [];
    $count = 0;
    foreach ($map[$key] ?? [] as $name) {
        if (isset($values[$name])) {
            $sum += $values[$name];
            $used[] = $name;
            $count++;
        }
    }
    if ($count==0){
        return ["", $used];
    }
    return [$sum, $used];
}
function addcal($name, $eq, $value, $used,&$result) {
    $result[] = [
        "indicator" => $name,
        "equation" => $eq,
        "value" => isset($value) ? number_format((float)$value, 2) : null,
        "used_items" => $used
    ];
}

/////////////////////////////////////////////////////////////////
// ポイントチェック
if($nodoai === true){
    // 全てのログイン履歴を取得
    $stmt = $conn->prepare("
        SELECT financial_response,account_map,request,f_recsv
        FROM ai_case
        WHERE ai_case_id = ?
    ");
    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $financial_response="";
    $account_map="";
    $request="";
	$recsv=array();
    while ($re = $result->fetch_assoc()) {
        $financial_response= $re['financial_response'];
        $account_map= $re['account_map'];
		$recsv= $re['f_recsv'];
        $request= $re['request'];
		$recsv = json_decode($recsv, true);
        $financial_response = json_decode($financial_response, true);
        $account_map = json_decode($account_map, true);
        $request = json_decode($request, true);
    }
    // 出力
    echo json_encode([
        "status" => "success",
        "ai_case_id" => $ai_case_id,
        "account_map" => $account_map,
		"recsv" => $recsv,
        "postingPeriod" => $request["postingPeriod"],
        "respons" => $financial_response["response"],
        "message" => "財務分析結果取得済"
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}
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

$use_point = $point2;

// ポイント残高チェックなし（point残高の概念を廃止）
// ポイント消費はAI処理成功後に行うため、ここでは消費しない

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT response,request
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$response="";
$request="";
while ($re = $result->fetch_assoc()) {
    $response= $re['response'];
    $request= $re['request'];
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
$bslist = $data2['BS'] ?? [];
$pllist = $data2['PL'] ?? [];

$templeturl=$www_dir."/sapis/financial_ai_templet";
if (!file_exists($templeturl)) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "financial_ai_templetが存在しません"]);
    exit;
}
date_default_timezone_set('Asia/Tokyo');
$templetdata = json_decode(file_get_contents($templeturl), true);
if (!$templetdata) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "financial_ai_templetの解析に失敗しました","text" => file_get_contents($templeturl)]);
    exit;
}


$respons=array();
$randomstr=generateRandomString();
$input_json_path="/var/log/pys/".$ai_case_id."_".$user_id."_finput_".$randomstr.".json";
$output_csv_path = "/var/log/pys/".$ai_case_id."_".$user_id."_foutput_".$randomstr.".csv";
$output_json_path = "/var/log/pys/".$ai_case_id."_".$user_id."_foutput_".$randomstr.".json";
file_put_contents(
    $input_json_path,
    json_encode($data2, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

//$backendbase."financial.py"呼び出し
$scriptPath = escapeshellcmd($python_base . " {$backendbase}financial.py");
$ttt = time();
$command = "$scriptPath $input_json_path $output_csv_path $output_json_path > /var/log/pys/{$ai_case_id}_f_{$ttt}.log 2>&1 &";
$superout=$command;
exec($command);

$margemap=array();
$margemap[]=["指標" => "売上高", "indicator" => "売上高"];
$margemap[]=["指標" => "売上原価", "indicator" => "売上原価"];
$margemap[]=["指標" => "売上総利益", "indicator" => "売上総利益"];
$margemap[]=["指標" => "売上総利益率", "indicator" => "売上総利益率"];
$margemap[]=["指標" => "売上原価率", "indicator" => "売上原価率"];
$margemap[]=["指標" => "一般管理販売費", "indicator" => "一般管理販売費"];
$margemap[]=["指標" => "売上高経費比率", "indicator" => "売上高経費比率"];
$margemap[]=["指標" => "粗利経費率", "indicator" => "粗利経費率"];
$margemap[]=["指標" => "営業利益", "indicator" => "営業利益"];
$margemap[]=["指標" => "営業外収益", "indicator" => "営業外収益"];
$margemap[]=["指標" => "営業外費用", "indicator" => "営業外費用"];
$margemap[]=["指標" => "経常利益", "indicator" => "経常利益"];
$margemap[]=["指標" => "経常利益率", "indicator" => "経常利益率"];
$margemap[]=["指標" => "税引前利益", "indicator" => "税引前利益"];
$margemap[]=["指標" => "当期利益", "indicator" => "当期利益"];
$margemap[]=["指標" => "現預金", "indicator" => "現預金"];
$margemap[]=["指標" => "棚卸資産", "indicator" => "棚卸資産"];
$margemap[]=["指標" => "棚卸回転率（回転/年）", "indicator" => "棚卸回転率（回転/年）"];
$margemap[]=["指標" => "流動比率", "indicator" => "流動比率"];
$margemap[]=["指標" => "当座比率", "indicator" => "当座比率"];
$margemap[]=["指標" => "有形固定資産額", "indicator" => "有形固定資産額"];
$margemap[]=["指標" => "その他の投資", "indicator" => "その他の投資"];
$margemap[]=["指標" => "（投資有価証券）", "indicator" => "（投資有価証券）"];
$margemap[]=["指標" => "固定比率", "indicator" => "固定比率"];
$margemap[]=["指標" => "固定長期適合率", "indicator" => "固定長期適合率"];
$margemap[]=["指標" => "借入金", "indicator" => "借入金"];
$margemap[]=["指標" => "支払利息", "indicator" => "支払利息"];
$margemap[]=["指標" => "賞与引当金", "indicator" => "賞与引当金"];
$margemap[]=["指標" => "退職給与引当金", "indicator" => "退職給与引当金"];
$margemap[]=["指標" => "資本金", "indicator" => "資本金"];
$margemap[]=["指標" => "自己資本額", "indicator" => "自己資本額"];
$margemap[]=["指標" => "自己資本比率", "indicator" => "自己資本比率"];
$margemap[]=["指標" => "負債比率", "indicator" => "負債比率"];
$margemap[]=["指標" => "財務レバレッジ比率", "indicator" => "財務レバレッジ比率"];
$margemap[]=["指標" => "自己資本利益率（ROE）", "indicator" => "自己資本利益率（ROE）"];
$margemap[]=["指標" => "総資本金額", "indicator" => "総資本金額"];
$margemap[]=["指標" => "総資本回転率", "indicator" => "総資本回転率"];
$margemap[]=["指標" => "総資産利益率（ROA）", "indicator" => "総資産利益率（ROA）"];
// ---- 先頭のどこか(一度だけ定義)に置く：ファイル出力の安定を待つ ----
if (!function_exists('wait_for_file_ready')) {
    function wait_for_file_ready(string $path, int $timeout_sec = 30, int $poll_ms = 150): void {
        $deadline = microtime(true) + $timeout_sec;
        $lastSize = -1;
        $stableCount = 0;

        while (microtime(true) < $deadline) {
            clearstatcache(true, $path);
            if (is_file($path)) {
                $size = filesize($path);
                if ($size > 0) {
                    if ($size === $lastSize) {
                        $stableCount++;
                        // 2回連続サイズが変わらなければ安定とみなす
                        if ($stableCount >= 2) return;
                    } else {
                        $stableCount = 0;
                        $lastSize = $size;
                    }
                }
            }
            usleep($poll_ms * 1000);
        }
        throw new RuntimeException("timeout waiting file ready: {$path}");
    }
}

// ---- JSONを厳密に読む（エラー内容も出す）----
if (!function_exists('read_json_strict')) {
    function read_json_strict(string $path): array {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("failed to read file: {$path}");
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $msg = json_last_error_msg();
            $peek = mb_substr($raw, 0, 200);
            throw new RuntimeException("json decode failed: {$msg} in {$path}. peek: " . $peek);
        }
        return $data;
    }
}
/**
 * 前提:
 * - $output_json_path: financial.py が出力した JSON のパス
 * - $templetdata: テンプレ（['template'] 配下 or 直配列）
 * - $margemap: 文字列マッピング配列（片方向/両方向どちらでもOK）
 *      例1) ['売上総利益率' => '売上総利益率（％）']   // rows → template
 *      例2) ['売上総利益率（％）' => '売上総利益率'] // template → rows
 *      例3) 上記を混在させてもOK
 */

// ---------- 1) 出力JSONの読込 ----------
try {
    wait_for_file_ready($output_json_path, 30, 150);
    $result_json = read_json_strict($output_json_path);
    if (!isset($result_json['rows']) || !is_array($result_json['rows'])) {
        throw new RuntimeException("result json missing rows array: {$output_json_path}");
    }
} catch (RuntimeException $e) {
    // 失敗履歴のusede記録は financial_ai_usepoint.php 側に移管したためここでは行わない
    write_system_log($conn, 'error', 'API', 'AI_API_ERROR', '財務分析スクリプトが失敗しました',
        user_id:    $user_id ?? null,
        ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
        detail:     ['ai_case_id' => $ai_case_id, 'error' => $e->getMessage()]
    );
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "財務分析スクリプトのタイムアウトまたは出力エラーが発生しました",
        "detail"  => $e->getMessage(),
        "ai_case_id" => $ai_case_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------- 2) rows を「指標」→row で引けるように構築（正規化インデックスも作成） ----------
if (!function_exists('norm_indicator')) {
    function norm_indicator($s) {
        if (!is_string($s)) return '';
        $s = trim($s);
        if (function_exists('mb_convert_kana')) {
            // 英数/スペースの全角半角統一
            $s = mb_convert_kana($s, 'asKV', 'UTF-8');
        }
        // 空白全除去
        $s = preg_replace('/\s+/u', '', $s);
        // よくズレる記号の統一（丸括弧やスラッシュなど）
        $pairs = [
            '（' => '(', '）' => ')', '／' => '/', '−' => '-', '–' => '-', '—' => '-',
            '＋' => '+', '％' => '%', '・' => '', '､' => ',', '，' => ',',
        ];
        $s = strtr($s, $pairs);
        return $s;
    }
}

$rows_by_indicator = [];
$rows_by_indicator_norm = [];
foreach ($result_json['rows'] as $row) {
    if (!isset($row['指標'])) continue;
    $ind = (string)$row['指標'];
    $rows_by_indicator[$ind] = $row;
    $rows_by_indicator_norm[norm_indicator($ind)] = $row;
}

// ---------- 3) $margemap を両方向に展開（正規化キーも保持） ----------
$map_rows_to_tpl = [];
$map_tpl_to_rows = [];
if (!is_array($margemap)) $margemap = [];

foreach ($margemap as $k => $v) {
    if (!is_string($k) || !is_string($v)) continue;
    // 片方向定義でも両方向に登録
    $map_rows_to_tpl[$k] = $v;   // rows名 → template名
    $map_tpl_to_rows[$v] = $k;   // template名 → rows名
}

// 正規化辞書も作っておく（ゆるい一致用）
$map_rows_to_tpl_norm = [];
$map_tpl_to_rows_norm = [];
foreach ($map_rows_to_tpl as $rowsName => $tplName) {
    $map_rows_to_tpl_norm[norm_indicator($rowsName)] = $tplName;
}
foreach ($map_tpl_to_rows as $tplName => $rowsName) {
    $map_tpl_to_rows_norm[norm_indicator($tplName)] = $rowsName;
}

// ---------- 4) テンプレ配列の抽出 ----------
$template_items = $templetdata['template'] ?? $templetdata;
if (!is_array($template_items)) {
    throw new RuntimeException("invalid templetdata structure");
}

// ---------- 5) 解決関数: テンプレindicator → rowsのrowを取得 ----------
$resolveRow = function (string $tplIndicator) use (
    $rows_by_indicator, $rows_by_indicator_norm,
    $map_tpl_to_rows, $map_tpl_to_rows_norm
) {
    // 1) まず厳密一致
    if (isset($rows_by_indicator[$tplIndicator])) {
        return $rows_by_indicator[$tplIndicator];
    }
    // 2) margemap（template→rows）で対応表があれば、そのrows名で探す
    if (isset($map_tpl_to_rows[$tplIndicator])) {
        $rowsName = $map_tpl_to_rows[$tplIndicator];
        if (isset($rows_by_indicator[$rowsName])) return $rows_by_indicator[$rowsName];
        $n = norm_indicator($rowsName);
        if (isset($rows_by_indicator_norm[$n])) return $rows_by_indicator_norm[$n];
    }
    // 3) 正規化での直接一致
    $nTpl = norm_indicator($tplIndicator);
    if (isset($rows_by_indicator_norm[$nTpl])) {
        return $rows_by_indicator_norm[$nTpl];
    }
    // 4) margemap（template→rows）の正規化キーで一致
    if (isset($map_tpl_to_rows_norm[$nTpl])) {
        $rowsName = $map_tpl_to_rows_norm[$nTpl];
        if (isset($rows_by_indicator[$rowsName])) return $rows_by_indicator[$rowsName];
        $nRows = norm_indicator($rowsName);
        if (isset($rows_by_indicator_norm[$nRows])) return $rows_by_indicator_norm[$nRows];
    }
    // 見つからなければ null
    return null;
};

// ---------- 6) $respons 構築 ----------
$respons = [];
foreach ($template_items as $tpl) {
    $type      = $tpl['type']      ?? '';
    $indicator = $tpl['indicator'] ?? '';
    $equation  = $tpl['equation']  ?? '';

    // タイトル行は値空欄のまま
    if ($type === 'タイトル') {
        $respons[] = [
            "type"                   => $type,
            "indicator"              => $indicator,
            "equation"               => $equation,
            "previous_previous_term" => "",
            "previous_term"          => "",
            "current_term"           => "",
        ];
        continue;
    }

    // rows から対応する行を解決
    $row = $resolveRow($indicator);

    // 値セット
    $pp = $p = $c = "";
    if ($row) {
        $pp = (string)($row['前々期値'] ?? "");
        $p  = (string)($row['前期値']   ?? "");
        $c  = (string)($row['今期値']   ?? "");
        if ($equation === "" && isset($row['計算式'])) {
            $equation = (string)$row['計算式'];
        }
    }

    $respons[] = [
        "type"                   => $type,
        "indicator"              => $indicator,
        "equation"               => $equation,
        "previous_previous_term" => $pp,
        "previous_term"          => $p,
        "current_term"           => $c,
    ];
}

// ---------- 7)（任意）診断レポート：マージできなかったテンプレ指標、margemap未使用など ----------
$diagnostics = [];
$unmerged_template = [];
foreach ($template_items as $tpl) {
    if (($tpl['type'] ?? '') === 'タイトル') continue;
    $ind = (string)($tpl['indicator'] ?? '');
    if ($ind === '') continue;
    if (!$resolveRow($ind)) {
        $unmerged_template[] = $ind;
    }
}
$diagnostics['unmerged_template_indicators'] = $unmerged_template;

// 必要ならログ/返却
// error_log(json_encode($diagnostics, JSON_UNESCAPED_UNICODE));
// echo json_encode($respons, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

$financial_data=array();
$financial_data["response"]=$respons;
$financial_response=json_encode($financial_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$account=array();
try {
    wait_for_file_ready($output_json_path."2", 30, 150);
    $account = read_json_strict($output_json_path."2");
} catch (RuntimeException $e) {
    // 失敗履歴のusede記録は financial_ai_usepoint.php 側に移管したためここでは行わない
    write_system_log($conn, 'error', 'API', 'AI_API_ERROR', '財務分析（account_map）スクリプトが失敗しました',
        user_id:    $user_id ?? null,
        ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
        detail:     ['ai_case_id' => $ai_case_id, 'error' => $e->getMessage()]
    );
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "財務分析（account_map）のタイムアウトまたは出力エラーが発生しました",
        "detail"  => $e->getMessage(),
        "ai_case_id" => $ai_case_id,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$account=json_encode($account, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$recsv=array();
$recsv["csvdownloadfilename"]=$csvdownloadfilename;
$f_recsv=json_encode($recsv, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// SQL構文修正：$parameters を直接埋めず、? を使う
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status='2000'
        ,response = ?
        ,account_map = ?
        ,financial_response = ?
        ,f_recsv = ?
        ,update_user_id = ?
        WHERE ai_case_id = ?
");

$stmt->bind_param("ssssii", $input, $account, $financial_response, $f_recsv, $user_id, $ai_case_id);
$stmt->execute();

write_system_log($conn, 'info', 'API', 'AI_API_CALLED', '財務分析AIが完了しました',
    user_id:    $user_id ?? null,
    ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
    detail:     ['ai_case_id' => $ai_case_id]
);

/** =========================
 *  nodoai==false の場合のみ financial_ai_start=1 をセット
 *  （ポイント消費とusede追加は financial_ai_usepoint.php で実施）
 *  ========================= */
if ($nodoai === false) {
    $stmtStart = $conn->prepare("
        UPDATE ai_case
            SET financial_ai_start = 1, update_at = NOW()
            WHERE ai_case_id = ?
    ");
    $stmtStart->bind_param("i", $ai_case_id);
    $stmtStart->execute();
    $stmtStart->close();
}

// 出力
echo json_encode([
    "status" => "success",
    "ai_case_id" => $ai_case_id,
    "account" => $account,
    "data2" => $data2,
    "superout" => $superout,
	"ip" => $ip,
    "postingPeriod" => $request["postingPeriod"],
	"recsv" => $recsv,
	"diagnostics" => $diagnostics,
    "respons" => $respons,
    "message" => "財務分析完了",
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
