<?php
include "./common.php";
require_once "./system_log_helper.php";
global $conn, $uploadDir, $backendbase, $python_base, $super_message, $openai_api_key, $model_str, $www_dir;
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

$loginkey = $data['loginkey'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;
$respons_all = $data['respons_all'] ?? null;
// ログイン必須 ＋ 案件が同じ会社のものか確認（loginkey が無い／他社の案件ならエラーで終了）
require_once __DIR__ . "/_case_access_helper.php";
$user_id = ana_require_case_access($loginkey, $ai_case_id);
//割り算
//v1÷v2
function warizan($v1, $v2) {
    // カンマを除去して数値に変換
    $v1 = str_replace(',', '', $v1);
    $v2 = str_replace(',', '', $v2);

    // 数値としてチェック
    if (!is_numeric($v1) || !is_numeric($v2)) {
        return '';
    }

    // 0除算チェック
    if ((float)$v2 == 0) {
        return '';
    }

    // 計算し100倍して小数第2位で丸めて返す
    return round(($v1 / $v2) * 100, 2)."";
}

function delete100($v1){

	// 「%」を除去
	$v1 = str_replace('%', '', $v1);

	// 数値チェックと変換
	if (is_numeric($v1)) {
		// 小数第2位で丸める
		$v1 = round((float)$v1, 2);
	} else {
		// 数値でなければ空にする（または他のエラーハンドリング）
		$v1 = '';
	}

	// 結果を出力
	return $v1.""; // → 87.13
}

//在庫回転率 ＝ 売上原価 ÷ 平均在庫金額{﻿(期首在庫金額 + 期末在庫金額) ÷ 2}
//(期首在庫金額 + 期末在庫金額) ÷ 2
function zaikokaiten($ug, $km, $ks){
    // 空文字やnullを数値に変換できるように整形
    $ug = str_replace(',', '', trim((string)$ug));
    $km = str_replace(',', '', trim((string)$km));
    $ks = str_replace(',', '', trim((string)$ks));

    // 数値に変換（失敗時はnullを返す）
    $ug_val = is_numeric($ug) ? (float)$ug : null;
    $km_val = is_numeric($km) ? (float)$km : null;
    $ks_val = is_numeric($ks) ? (float)$ks : null;

    // $ugが数値でない、または$kmと$ksの両方が数値でない場合は空文字を返す
    if ($ug_val === null || ($km_val === null && $ks_val === null)) {
        return "";
    }

    // $kmか$ksのどちらかがnullなら、もう一方だけで平均を計算
    if ($km_val === null) $avg = $ks_val;
    elseif ($ks_val === null) $avg = $km_val;
    else $avg = ($km_val + $ks_val) / 2;

    // 平均値が0の場合は割り算できないため空文字を返す
    if ($avg == 0) {
        return "";
    }

    // 計算して返す（必要ならround等で調整可）
    return round(($ug_val / $avg) * 100, 2)."";
}

// 
function doAI($report_data) {
    global $openai_api_key;
    global $model_str;

    $url = "https://api.openai.com/v1/chat/completions";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer $openai_api_key"
    ];

    $system_prompt = <<<EOT
以下の構造化された財務分析データを基に、以下のセクションごとに日本語分析コメントを生成してください。

1.1 主要KPIの概況：
・売上成長率、営業利益率、ROEの前期と今期を比較して要約を200文字以上で。

1.2 ポジティブ要因：
・収益・資産・安全性などから見える好調な点を列挙を200文字以上で。

1.3 ネガティブ要因：
・収益性や費用、財務リスクなどでの懸念点を列挙を200文字以上で。

1.4 比較的大きな変化：
・前期との比較で大きな数値変化や構造変化があれば具体的に、200文字以上で。

1.5 次期への重点施策：
・上記分析を踏まえた、次期への戦略提案を200文字以上で。

2. 財務ハイライト：
・P/L（損益計算書）とB/S（貸借対照表）の概況を200文字以上で。

3. 財務指標分析（収益性・安全性・生産性・成長性）：
・全体的な傾向と数値の注目点を400文字程度で。

4. キャピタル・ストラクチャー分析：
・借入金の動向・金利負担から財務構造の現状とリスクについて200文字以上で。

【出力ルール】
- 各項目は「●」で始める箇条書き。
- 文のまとまりごとに改行。
- 出力全体をJSON形式で返し、各値もきれいに整形してください（\\nなどのエスケープではなく実際の改行）。

【出力形式】
以下の形式のJSONオブジェクトで返答してください（文字列ではなく、実際のJSONとして構成してください）：
{
  "1.1 主要KPIの概況": "...",
  "1.2 ポジティブ要因": "...",
  "1.3 ネガティブ要因": "...",
  "1.4 比較的大きな変化": "...",
  "1.5 次期への重点施策": "...",
  "2. 財務ハイライト": "...",
  "3. 財務指標分析": "...",
  "4. キャピタル・ストラクチャー分析": "..."
}

EOT;

    $messages = [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user", "content" =>
            "以下は構造化された財務分析データです。指示に従って分析を行ってください。\n\n" .
            json_encode($report_data, JSON_UNESCAPED_UNICODE)
        ]
    ];

    $payload = json_encode([
        "model" => $model_str, // 例: gpt-4o や gpt-4-turbo
        "messages" => $messages,
        "temperature" => 0
    ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $response = curl_exec($ch);
    $result = json_decode($response, true);
    curl_close($ch);

    $reply = $result["choices"][0]["message"]["content"] ?? null;
    // JSONオブジェクトで返す（文字列ではなく）
    return json_decode($reply, true);
}



$templeturl=$www_dir."/sapis/financial_ai_templet";
if (!file_exists($templeturl)) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "financial_ai_templetが存在しません"]);
    exit;
}
$templetdata = json_decode(file_get_contents($templeturl), true);
if (!$templetdata) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "financial_ai_templetの解析に失敗しました","text" => file_get_contents($configPath)]);
    exit;
}
$i=0;
foreach ($templetdata["template"] as $row) {
	foreach ($respons_all as $re) {
		if($row['indicator'] == $re["indicator"]){
			$templetdata["template"][$i]["previous_previous_term"] = $re["previous_previous_term"] ;
			$templetdata["template"][$i]["previous_term"] = $re["previous_term"] ;
			$templetdata["template"][$i]["current_term"] = $re["current_term"] ;
			$templetdata["template"][$i]["usage"] = $re["usage"] ;
		}
	}
	$i++;
}
$financial_data=array();
$financial_data["response"]=$templetdata["template"];
$financial_response=json_encode($financial_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
// SQL構文修正：$parameters を直接埋め込まず、? を使う
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,financial_response = ?
        ,update_user_id = ?
        WHERE ai_case_id = ?
");

$stmt->bind_param("sii", $financial_response, $user_id, $ai_case_id);
$stmt->execute();

write_system_log($conn, 'info', 'API', 'AI_API_CALLED', 'AI診断が完了しました',
    user_id:    $user_id ?? null,
    ip_address: $_SERVER['REMOTE_ADDR'] ?? null,
    detail:     ['ai_case_id' => $ai_case_id]
);

// $report_templet_url=$www_dir."/sapis/report_templet";
// if (!file_exists($report_templet_url)) {
    // http_response_code(500);
    // echo json_encode(["status" => "error", "message" => "financial_ai_templetが存在しません"]);
    // exit;
// }
// $report_data = json_decode(file_get_contents($report_templet_url), true);
// if (!$report_data) {
    // http_response_code(500);
    // echo json_encode(["status" => "error", "message" => "report_templetの解析に失敗しました","text" => file_get_contents($configPath)]);
    // exit;
// }
// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT account_map,request,report_response,aitext
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
// 結果をループして出力
$account_map=array();
$request=array();
$report_response="";
$aitext="";
while ($re = $result->fetch_assoc()) {
	if($re['account_map']!=null and $re['account_map']!=""){
		$account_map = json_decode($re['account_map'], true);
		$request = json_decode($re['request'], true);
		$report_response=$re['report_response'];
		$aitext=$re['aitext'];
	}
}
if(empty($aitext)){
	$aitext=null;
}
$did_report_flag = true;
if(empty($report_response)){
	$did_report_flag = false;
}
/**
$i=0;
foreach ($report_data["response"] as $re) {
	foreach ($financial_data["response"] as $fi) {
		if($re['name'] == "売上成長率" and $fi["indicator"] == "売上高"){
			$current_term=warizan($fi["current_term"],$fi["previous_term"]);
			$previous_term=warizan($fi["previous_term"],$fi["previous_previous_term"]);
			$report_data["response"][$i]["current_term"] = $current_term;
			$report_data["response"][$i]["previous_term"] = $previous_term;
		}else if($re['name'] == "営業利益率" and $fi["indicator"] == "営業利益"){
			$current_term=warizan($fi["current_term"],$fi["previous_term"]);
			$previous_term=warizan($fi["previous_term"],$fi["previous_previous_term"]);
			$report_data["response"][$i]["current_term"] = $current_term;
			$report_data["response"][$i]["previous_term"] = $previous_term;
		}else if($re['name'] == "ROE" and $fi["indicator"] == "自己資本利益率（ROE）"){
			if($fi["usage"]){
				$current_term=delete100($fi["current_term"]);
				$previous_term=delete100($fi["previous_term"]);
				$report_data["response"][$i]["current_term"] = $current_term;
				$report_data["response"][$i]["previous_term"] = $previous_term;
			}else{
				$report_data["response"][$i]["current_term"] = "";
				$report_data["response"][$i]["previous_term"] = "";
			}
		///////////////////////////////////////////////////////////////////////////////
		}else if($re['name'] == "損益計算書分析 (P/L)" and $fi["indicator"] == "売上高"){
			$report_data["response"][$i]["items"][0]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][0]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][0]["previous_previous_term"] = $fi["previous_previous_term"];
		}else if($re['name'] == "損益計算書分析 (P/L)" and $fi["indicator"] == "総利益"){
			$report_data["response"][$i]["items"][1]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][1]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][1]["previous_previous_term"] = $fi["previous_previous_term"];
		}else if($re['name'] == "損益計算書分析 (P/L)" and $fi["indicator"] == "営業利益"){
			$report_data["response"][$i]["items"][2]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][2]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][2]["previous_previous_term"] = $fi["previous_previous_term"];
		}else if($re['name'] == "損益計算書分析 (P/L)" and $fi["indicator"] == "経常利益"){
			$report_data["response"][$i]["items"][3]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][3]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][3]["previous_previous_term"] = $fi["previous_previous_term"];
			//=============================================
			$report_data["response"][$i]["items"][4]["current_term"] = $account_map["当期純利益"]["current_term"];
			$report_data["response"][$i]["items"][4]["previous_term"] = $account_map["当期純利益"]["previous_term"];
			$report_data["response"][$i]["items"][4]["previous_previous_term"] = $account_map["当期純利益"]["previous_previous_term"];
			//=============================================
		///////////////////////////////////////////////////////////////////////////////
		}else if($re['name'] == "貸借対照表分析 (B/S)" and $fi["indicator"] == "自己資本比率"){
			//=============================================
			$report_data["response"][$i]["items"][0]["current_term"] = $account_map["流動資産"]["current_term"];
			$report_data["response"][$i]["items"][0]["previous_term"] = $account_map["流動資産"]["previous_term"];
			$report_data["response"][$i]["items"][0]["previous_previous_term"] = $account_map["流動資産"]["previous_previous_term"];
			$report_data["response"][$i]["items"][1]["current_term"] = $account_map["固定資産"]["current_term"];
			$report_data["response"][$i]["items"][1]["previous_term"] = $account_map["固定資産"]["previous_term"];
			$report_data["response"][$i]["items"][1]["previous_previous_term"] = $account_map["固定資産"]["previous_previous_term"];
			$report_data["response"][$i]["items"][2]["current_term"] = $account_map["流動負債"]["current_term"];
			$report_data["response"][$i]["items"][2]["previous_term"] = $account_map["流動負債"]["previous_term"];
			$report_data["response"][$i]["items"][2]["previous_previous_term"] = $account_map["流動負債"]["previous_previous_term"];
			$report_data["response"][$i]["items"][3]["current_term"] = $account_map["固定負債"]["current_term"];
			$report_data["response"][$i]["items"][3]["previous_term"] = $account_map["固定負債"]["previous_term"];
			$report_data["response"][$i]["items"][3]["previous_previous_term"] = $account_map["固定負債"]["previous_previous_term"];
			//=============================================
			if($fi["usage"]){
				$report_data["response"][$i]["items"][4]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][4]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][4]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][4]["current_term"] = "";
				$report_data["response"][$i]["items"][4]["previous_term"] = "";
				$report_data["response"][$i]["items"][4]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "収益性 推移" and $fi["indicator"] == "自己資本利益率（ROE）"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][0]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][0]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][0]["current_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "収益性 推移" and $fi["indicator"] == "総資産利益率（ROA）"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][1]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][1]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][1]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][1]["current_term"] = "";
				$report_data["response"][$i]["items"][1]["previous_term"] = "";
				$report_data["response"][$i]["items"][1]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "収益性 推移" and $fi["indicator"] == "営業利益率"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][2]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][2]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][2]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][2]["current_term"] = "";
				$report_data["response"][$i]["items"][2]["previous_term"] = "";
				$report_data["response"][$i]["items"][2]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "安全性 推移" and $fi["indicator"] == "自己資本比率"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][0]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][0]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][0]["current_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "安全性 推移" and $fi["indicator"] == "流動比率"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][1]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][1]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][1]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][1]["current_term"] = "";
				$report_data["response"][$i]["items"][1]["previous_term"] = "";
				$report_data["response"][$i]["items"][1]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "生産性 推移" and $fi["indicator"] == "総資本回転率"){
			if($fi["usage"]){
				$report_data["response"][$i]["items"][0]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["items"][0]["previous_term"] = delete100($fi["previous_term"]);
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = delete100($fi["previous_previous_term"]);
			}else{
				$report_data["response"][$i]["items"][0]["current_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_term"] = "";
				$report_data["response"][$i]["items"][0]["previous_previous_term"] = "";
			}
		}else if($re['name'] == "生産性 推移" and $fi["indicator"] == "売上原価"){
			$report_data["response"][$i]["items"][3]["current_term"] = $account_map["固定負債"]["current_term"];
			//在庫回転率 ＝ 売上原価 ÷ 平均在庫金額{﻿(期首在庫金額 + 期末在庫金額) ÷ 2}
			//(期首在庫金額 + 期末在庫金額) ÷ 2
			$ks = $account_map["期首棚卸"]["current_term"];
			$km = $account_map["期末棚卸"]["current_term"];
			$ug = $fi["current_term"];
			$report_data["response"][$i]["items"][1]["current_term"] = zaikokaiten($ug, $km, $ks);
			$ks = $account_map["期首棚卸"]["previous_term"];
			$km = $account_map["期末棚卸"]["previous_term"];
			$ug = $fi["previous_term"];
			$report_data["response"][$i]["items"][1]["previous_term"] = zaikokaiten($ug, $km, $ks);
			$ks = $account_map["期首棚卸"]["previous_previous_term"];
			$km = $account_map["期末棚卸"]["previous_previous_term"];
			$ug = $fi["previous_previous_term"];
			$report_data["response"][$i]["items"][1]["previous_previous_term"] = zaikokaiten($ug, $km, $ks);
		}else if($re['name'] == "成長性 推移" and $fi["indicator"] == "売上高"){
			$current_term=warizan($fi["current_term"],$fi["previous_term"]);
			$previous_term=warizan($fi["previous_term"],$fi["previous_previous_term"]);
			$report_data["response"][$i]["items"][0]["current_term"] = $current_term;
			$report_data["response"][$i]["items"][0]["previous_term"] = $previous_term;
			//===================================================================
			$report_data["response"][$i]["items"][1]["current_term"] = "";
			$report_data["response"][$i]["items"][1]["previous_term"] = "";
			$report_data["response"][$i]["items"][1]["previous_previous_term"] = "";
		}else if($re['name'] == "借入金残高推移と金利負担" and $fi["indicator"] == "借入金"){
			$report_data["response"][$i]["items"][0]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][0]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][0]["previous_previous_term"] = $fi["previous_previous_term"];
		}else if($re['name'] == "借入金残高推移と金利負担" and $fi["indicator"] == "支払利息"){
			$report_data["response"][$i]["items"][1]["current_term"] = $fi["current_term"];
			$report_data["response"][$i]["items"][1]["previous_term"] = $fi["previous_term"];
			$report_data["response"][$i]["items"][1]["previous_previous_term"] = $fi["previous_previous_term"];
		}
	}
	if($re['name'] == "財務分析データ"){
		$report_data["response"][$i]["items"] = $financial_data["response"];
	}
	$i++;
}

$aitexts=doAI($report_data);
$i=0;
foreach ($report_data["response"] as $re) {
	if($re['name'] == "主要KPIの概況"){
		$targetname = "1.1 主要KPIの概況";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "ポジティブ要因"){
		$targetname = "1.2 ポジティブ要因";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "ネガティブ要因"){
		$targetname = "1.3 ネガティブ要因";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "比較的大きな変化"){
		$targetname = "1.4 比較的大きな変化";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "次期への重点施策"){
		$targetname = "1.5 次期への重点施策";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "財務ハイライト"){
		$targetname = "2. 財務ハイライト";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "財務指標分析"){
		$targetname = "3. 財務指標分析";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}else if($re['name'] == "キャピタル・ストラクチャー分析"){
		$targetname = "4. キャピタル・ストラクチャー分析";
		if(isset($aitexts[$targetname])){
			$report_data["response"][$i]["text"] = $aitexts[$targetname];
		}
	}
	$i++;
}
$report_response=json_encode($report_data["response"], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status='6000'
		,report_response = ?
        ,update_user_id = ?
        WHERE ai_case_id = ?
");

$stmt->bind_param("sii", $report_response, $user_id, $ai_case_id);
$stmt->execute();
**/
$report_response=array();
// 出力
echo json_encode([
    "status" => "success",
    "ai_case_id" => $ai_case_id,
	"respons_all" => $respons_all,
	//"aitexts" => $aitexts,
	"financial_response" => $templetdata["template"],
	//"report_data" => $report_data["response"],
	"postingPeriod" => $request["postingPeriod"],
	"did_report_flag" => $did_report_flag,
	"aitext" => $aitext,
    "message" => "AI診断のデータを投入しました"
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);