<?php
include "./common.php";
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
$user_input = $data['instruction'] ?? null;
$nodoai = $data['nodoai'] ?? false;
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
//割り算
//v1÷v2
function warizan2($v1, $v2) {
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
    return (round(($v1 / $v2) * 100, 2)-100)."";
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

/**
 * ユーザ入力のセキュリティチェックとサニタイズ
 *
 * @param string $input ユーザー入力
 * @return array ['sanitized_input' => string, 'is_valid' => bool, 'error' => string|null]
 */
function sanitizeAndValidateUserInput(string $input): array {
    $input = trim($input);

    // 長さ制限
    // 2026-08-27: ai_case.aitext が varchar(500) のため、上限を 500 に是正。
    //   従来は 1000 を許容しており、501文字以上で
    //   「Data too long for column 'aitext'」により HTTP 500 になっていた。
    //   MySQL の VARCHAR は文字数基準のため、全角/半角を問わず 500 文字が上限。
    if (mb_strlen($input, 'UTF-8') > 500) {
        return ['sanitized_input' => '', 'is_valid' => false, 'error' => '入力は500文字以内にしてください。'];
    }

    // 制御文字や不可視文字を削除（UTF-8で不正な制御文字など）
    $input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);

    // スクリプトタグやイベント属性を排除
    $input = preg_replace('/<script.*?>.*?<\/script>/is', '', $input);
    $input = preg_replace('/on\w+=".*?"/i', '', $input);
    $input = strip_tags($input); // HTMLタグ除去

    // OpenAI用のプロンプト汚染を防止する基本的なワードフィルタ（必要に応じて強化）
    $blacklist = ['system:', 'user:', 'assistant:', 'あなたは', '以下の命令に従って', 'ignore previous'];
    foreach ($blacklist as $word) {
        if (stripos($input, $word) !== false) {
            return ['sanitized_input' => '', 'is_valid' => false, 'error' => '不適切な入力が含まれています。'];
        }
    }

    return ['sanitized_input' => $input, 'is_valid' => true, 'error' => null];
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
function doAI_after($report_data, $max_tokens = 1200, $char_limit = 900) {
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
・売上成長率、営業利益率、ROEの前期と今期を比較して要約を80〜100文字程度で。

1.2 ポジティブ要因：
・収益・資産・安全性などから見える好調な点を列挙、80〜100文字程度で。

1.3 ネガティブ要因：
・収益性や費用、財務リスクなどでの懸念点を列挙、80〜100文字程度で。

1.4 比較的大きな変化：
・前期との比較で大きな数値変化や構造変化を具体的に、80〜100文字程度で。

1.5 次期への重点施策：
・上記分析を踏まえた、次期への戦略提案を80〜100文字程度で。

2. 財務ハイライト：
・P/L（損益計算書）とB/S（貸借対照表）の概況を80〜100文字程度で。

3. 財務指標分析（収益性・安全性・生産性・成長性）：
・全体的な傾向と数値の注目点を150〜200文字程度で。

4. キャピタル・ストラクチャー分析：
・借入金の動向・金利負担から財務構造の現状とリスクを80〜100文字程度で。

【重要・文字数制限】
- 全セクションを合計した出力（JSON内の全テキスト合計）は必ず {$char_limit} 文字以内にまとめてください。
- 各セクションは指定の文字数を超えないようにし、簡潔に要点のみ記述してください。
- {$char_limit}文字を超えそうな場合は、優先度の低い情報を省略してでも {$char_limit} 文字以内に必ず収めてください。

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
        "temperature" => 0,
        "max_tokens" => $max_tokens  // ← 呼び出し側から指定（リトライで縮小）
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
function doAI($report_data, $user_input, $max_tokens = 1200, $char_limit = 900) {
    global $openai_api_key;
    global $model_str;

    $url = "https://api.openai.com/v1/chat/completions";
    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer $openai_api_key"
    ];

    // ユーザ入力も含めた指示文を system プロンプトに含める
    $system_prompt = <<<EOT
	以下の構造化された財務分析データを基に、次のセクションごとに日本語分析コメントを生成してください。

	【ユーザ指示】
		{$user_input}

	【出力ルール】
	- 出力はすべて「●」で始まる箇条書き形式にしてください。
	- 文のまとまりごとに改行。
	- **ただし、空白行（改行のみの行）は入れないでください。**

	【重要・文字数制限】
	- エグゼクティブサマリーの本文（JSON内 "エグゼクティブサマリー" の値）は必ず {$char_limit} 文字以内に収めてください。
	- ユーザ指示でこれより多い文字数が指定されていたとしても、{$char_limit} 文字以内を最優先してください。
	- {$char_limit}文字を超えそうな場合は、優先度の低い情報を省略してでも {$char_limit} 文字以内に必ず収めてください。

	【出力形式】
		以下の形式のJSONオブジェクトで返答してください（文字列ではなく、実際のJSONとして構成してください）：
		{
		  "エグゼクティブサマリー": "● ○○○○○○○○○○\n● ○○○○○○○○○○\n..."
		}

	【出典関係の文言の追加】
		出典・根拠の明示が必要な箇所は、**必ず限り引用元の名称とサイトURL等を記載して下さい**。
	EOT;

    $messages = [
        ["role" => "system", "content" => $system_prompt],
        ["role" => "user", "content" =>
            "以下は構造化された財務分析データです。指示に従って分析を行ってください。\n\n" .
            json_encode($report_data, JSON_UNESCAPED_UNICODE)
        ]
    ];

    $payload = json_encode([
        "model" => $model_str,
        "messages" => $messages,
        "temperature" => 0,
		"max_tokens" => $max_tokens  // ← 呼び出し側から指定（リトライで縮小）
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

    // JSONとしてパースできない場合の保険
    $parsed = json_decode($reply, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("OpenAIからの返信がJSON形式ではありません: " . $reply);
        return [
            "エグゼクティブサマリー" => "AIからの出力を解析できませんでした。"
        ];
    }

    return $parsed;
}

/**
 * 文字数（mb_strlen）を計算するヘルパー
 */
function _ai_mb_strlen($s) {
    return is_string($s) ? mb_strlen($s, 'UTF-8') : 0;
}

/**
 * 1000文字超のテキストを 980文字 + '...' で強制切り詰め
 */
function _ai_force_truncate_text($s) {
    if (is_string($s) && mb_strlen($s, 'UTF-8') > 1000) {
        return mb_substr($s, 0, 980, 'UTF-8') . '...';
    }
    return $s;
}

/**
 * doAI_after をリトライ＋強制切り詰めでラップ
 *   1回目: max_tokens=1200, char_limit=900
 *   2-3回目: max_tokens=600, char_limit=700
 *   3回後も1000文字超: 980文字でカットし'...'を付与
 */
function doAI_after_with_retry($report_data) {
    $configs = [
        ['max_tokens' => 1200, 'char_limit' => 900],
        ['max_tokens' => 600,  'char_limit' => 700],
        ['max_tokens' => 600,  'char_limit' => 700],
    ];
    $result = null;
    foreach ($configs as $i => $cfg) {
        $result = doAI_after($report_data, $cfg['max_tokens'], $cfg['char_limit']);
        // 各セクションが1000文字以内かチェック
        $ok = true;
        if (is_array($result)) {
            foreach ($result as $v) {
                if (_ai_mb_strlen($v) > 1000) { $ok = false; break; }
            }
        }
        if ($ok) {
            error_log(sprintf('[doAI_after_with_retry] success on attempt %d', $i + 1));
            return $result;
        }
        error_log(sprintf('[doAI_after_with_retry] attempt %d exceeded 1000 chars, retrying', $i + 1));
    }
    // 3回試しても超えていた場合は強制切り詰め
    if (is_array($result)) {
        foreach ($result as $k => $v) {
            $result[$k] = _ai_force_truncate_text($v);
        }
        error_log('[doAI_after_with_retry] force-truncated after 3 attempts');
    }
    return $result;
}

/**
 * doAI をリトライ＋強制切り詰めでラップ
 *   1回目: max_tokens=1200, char_limit=900
 *   2-3回目: max_tokens=600, char_limit=700
 *   3回後も1000文字超: 980文字でカットし'...'を付与
 */
function doAI_with_retry($report_data, $user_input) {
    $configs = [
        ['max_tokens' => 1200, 'char_limit' => 900],
        ['max_tokens' => 600,  'char_limit' => 700],
        ['max_tokens' => 600,  'char_limit' => 700],
    ];
    $result = null;
    foreach ($configs as $i => $cfg) {
        $result = doAI($report_data, $user_input, $cfg['max_tokens'], $cfg['char_limit']);
        $text = isset($result['エグゼクティブサマリー']) ? $result['エグゼクティブサマリー'] : '';
        if (_ai_mb_strlen($text) <= 1000) {
            error_log(sprintf('[doAI_with_retry] success on attempt %d', $i + 1));
            return $result;
        }
        error_log(sprintf('[doAI_with_retry] attempt %d exceeded 1000 chars (len=%d), retrying', $i + 1, _ai_mb_strlen($text)));
    }
    // 3回試しても超えていた場合は強制切り詰め
    if (isset($result['エグゼクティブサマリー'])) {
        $result['エグゼクティブサマリー'] = _ai_force_truncate_text($result['エグゼクティブサマリー']);
        error_log('[doAI_with_retry] force-truncated after 3 attempts');
    }
    return $result;
}
/**
 * 入力文字列を最大文字数に制限し、必要ならトリミングして警告フラグを返す
 *
 * @param string $input ユーザ入力（自由記述）
 * @param int $maxLength 最大文字数（デフォルト: 1000）
 * @return array [ 'trimmed_input' => string, 'was_trimmed' => bool ]
 */
function validateUserInputLength(string $input, int $maxLength = 1000): array {
    $input = trim($input);
    $was_trimmed = false;

    if (mb_strlen($input, 'UTF-8') > $maxLength) {
        $input = mb_substr($input, 0, $maxLength, 'UTF-8');
        $was_trimmed = true;
    }

    return [
        'trimmed_input' => $input,
        'was_trimmed' => $was_trimmed
    ];
}

if($nodoai === true){
	// 2026-08-27: この分岐は「前回の分析を使用」＝AIを実行しない経路。
	//   送られてきた指示文はAIにも渡されず何にも使われないため、
	//   aitext への保存は意味がない（むしろ、実際には使われなかった指示文が
	//   次回の画面表示で「前回の指示文」として復元されてしまい紛らわしい）。
	//   そのため下記の UPDATE 文ごと削除し、この経路ではDBを更新しない。
	//   併せて aitext の桁あふれ（Data too long）もこの経路では発生しなくなる。

	// 全てのログイン履歴を取得
	$stmt = $conn->prepare("
		SELECT report_response,account_map,request
		FROM ai_case
		WHERE ai_case_id = ?
	");
	$stmt->bind_param("i", $ai_case_id);
	$stmt->execute();
	$result = $stmt->get_result();
	$report_response="";
	$account_map="";
	$request="";
	$report_data=array();
	while ($re = $result->fetch_assoc()) {
		$report_response= $re['report_response'];
		$account_map= $re['account_map'];
		$request= $re['request'];
		$report_data = json_decode($report_response, true);
		$account_map = json_decode($account_map, true);
		$request = json_decode($request, true);
	}
	// 出力
	echo json_encode([
		"status" => "success",
		"ai_case_id" => $ai_case_id,
		"report_data" => $report_data,
		"postingPeriod" => $request["postingPeriod"],
		"message" => "AI診断のデータを投入しました"
	], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
	exit();
}





$check = sanitizeAndValidateUserInput($user_input);

if (!$check['is_valid']) {
    // 2026-08-27: 入力エラーは HTTP 400 で返す（他 sapis と統一。監視・ログでも検知可能）。
    //   Angular の HttpErrorResponse はレスポンスボディを error プロパティに保持するため、
    //   フロント側は err.error.message でメッセージを取得できる
    //   （同ファイル内の onReportSend と同じ方式）。
    //   JSON_UNESCAPED_UNICODE を付与し、ログ等で日本語が読める形にしている。
    http_response_code(400);
    echo json_encode([
        "status"  => "error",
        "message" => $check['error'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$trimmed_input = $check['sanitized_input'];

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT report_response,request,financial_response,account_map
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
// 結果をループして出力
$request=array();
$report_data=array();
$financial_response=array();
$account_map=array();
while ($re = $result->fetch_assoc()) {
	$request = json_decode($re['request'], true);
	$financial_data = json_decode($re['financial_response'], true);
	$account_map = json_decode($re['account_map'], true);
}


/////////////////////////////////////////////////////////////////
// ポイントチェック

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

$use_point = $point3;

// ポイント残高チェックなし（point残高の概念を廃止）
// ポイント消費はAI処理成功後に行うため、ここでは消費しない


$report_templet_url=$www_dir."/sapis/report_templet";
if (!file_exists($report_templet_url)) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "financial_ai_templetが存在しません"]);
    exit;
}
$report_data = json_decode(file_get_contents($report_templet_url), true);
if (!$report_data) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "report_templetの解析に失敗しました","text" => file_get_contents($configPath)]);
    exit;
}
$i=0;
foreach ($report_data["response"] as $re) {
	foreach ($financial_data["response"] as $fi) {
		if($re['name'] == "売上成長率" and $fi["indicator"] == "売上高"){
			// 売上成長率 ＝ (今期 − 前期) ÷ 前期 × 100（グラフ「成長性 推移」と同じ計算）
			$current_term=warizan2($fi["current_term"],$fi["previous_term"]);
			$previous_term=warizan2($fi["previous_term"],$fi["previous_previous_term"]);
			$report_data["response"][$i]["current_term"] = $current_term;
			$report_data["response"][$i]["previous_term"] = $previous_term;
		}else if($re['name'] == "営業利益率" and $fi["indicator"] == "営業利益率"){
			// 営業利益率 ＝ 営業利益 ÷ 売上高 × 100（グラフ「収益性 推移」と同じく、財務分析の「営業利益率」を使う）
			if($fi["usage"]){
				$report_data["response"][$i]["current_term"] = delete100($fi["current_term"]);
				$report_data["response"][$i]["previous_term"] = delete100($fi["previous_term"]);
			}else{
				$report_data["response"][$i]["current_term"] = "";
				$report_data["response"][$i]["previous_term"] = "";
			}
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
			$current_term=warizan2($fi["current_term"],$fi["previous_term"]);
			$previous_term=warizan2($fi["previous_term"],$fi["previous_previous_term"]);
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

/** =========================
 *  AI呼び出し前：ステータスを「AI診断中」に更新
 *  CF分析済/進行中(7098/7099/7100/7200)なら 5001、それ以外は 5000
 *  ========================= */
$stmtPre = $conn->prepare("
    UPDATE ai_case
        SET status = IF(status IN ('7098','7099','7100','7200'), '5001', '5000'),
            update_user_id = ?
        WHERE ai_case_id = ?
        LIMIT 1
");
if ($stmtPre) {
    $stmtPre->bind_param("ii", $user_id, $ai_case_id);
    $stmtPre->execute();
    $stmtPre->close();
}

// 処理中(5000/5001)のまま終了した場合に自動でエラーステータスをセット
$_report_completed = false;
register_shutdown_function(function() use ($conn, $ai_case_id, $user_id, &$_report_completed) {
    $stmtErr = $conn->prepare("
        UPDATE ai_case SET status = '5006', update_at = now(), update_user_id = ?
        WHERE ai_case_id = ? AND status IN ('5000', '5001') LIMIT 1
    ");
    if ($stmtErr) {
        $stmtErr->bind_param("ii", $user_id, $ai_case_id);
        $stmtErr->execute();
        $stmtErr->close();
    }
    // 失敗履歴のusedeへの記録は report_show.php 側に移管したためここでは行わない
});

$aitexts=doAI_after_with_retry($report_data);
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
$report_data = $report_data["response"];














$aitexts=doAI_with_retry($report_data,$trimmed_input);
$i=0;
foreach ($report_data as $re) {
	if(isset($re['name']) and $re['name'] == "エグゼクティブサマリー"){
		$targetname = "エグゼクティブサマリー";
		if(isset($aitexts[$targetname])){
			$report_data[$i]["text"] = $aitexts[$targetname];
		}
	}
	$i++;
}
$report_response=json_encode($report_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status=IF(status IN ('5001','7100','7200'), '7200', '7000')
		,aitext=?
		,report_response = ?
		,update_user_id = ?
        WHERE ai_case_id = ?
");

// 2026-08-27: サニタイズ済みの $trimmed_input を保存する（従来は生入力 $user_input）
$stmt->bind_param("ssii", $trimmed_input, $report_response, $user_id, $ai_case_id);
$stmt->execute();

/** =========================
 *  nodoai==false の場合のみ report_generation_start=1 をセット
 *  （ポイント消費とusede追加は report_show.php で実施）
 *  ========================= */
if ($nodoai === false) {
    $stmtStart = $conn->prepare("
        UPDATE ai_case
            SET report_generation_start = 1, update_at = NOW()
            WHERE ai_case_id = ?
    ");
    $stmtStart->bind_param("i", $ai_case_id);
    $stmtStart->execute();
    $stmtStart->close();
}

// 出力
$_report_completed = true;
echo json_encode([
    "status" => "success",
    "ai_case_id" => $ai_case_id,
	"aitexts" => $aitexts,
	"report_data" => $report_data,
	"postingPeriod" => $request["postingPeriod"],
    "message" => "AI診断のデータを投入しました",
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);