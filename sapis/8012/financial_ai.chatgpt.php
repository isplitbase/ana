<?php
include "./common.php";
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
$ai_case_id = $data['ai_case_id'] ?? null;
$nodoai = $data['nodoai'] ?? false;

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
    WHERE ai_case_id = ? AND p0='PDF読取' LIMIT 1
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
    $user_id = intval($row['user_id']);
}

$use_point = $point2;

if ($use_point > $point) {
    http_response_code(200);
    echo json_encode([
        "loginkey" => $loginkey,
        "status" => "error",
        "message" => "ポイントが不足しているため、分析を実行できません（所持ポイント：{$point}、必要ポイント：{$use_point}）"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
///////////////////////////////////////
//ポイント利用履歴をセットする
// --- ポイント利用履歴に登録 ---
$point = $point - $use_point;
$ip     = $_SERVER['REMOTE_ADDR'] ?? '';
$device = $_SERVER['HTTP_USER_AGENT'] ?? '';
$filename = ''; // 必要に応じてファイル名をセット
$stmt = $conn->prepare("
    INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, '財務分析', ?)
");
$stmt->bind_param("iisissii", $user_id, $use_point, $filename, $totalPages, $ip, $device, $ai_case_id, $point);
$stmt->execute();
/////////////////////////////////////////////////////////
$stmt = $conn->prepare("
    UPDATE users
    SET update_at = NOW(),
        point = ?,
        update_user_id = NULL
    WHERE id = ?
");
$stmt->bind_param("ii", $point, $user_id);
$stmt->execute();
///////////////////////////////////////////////////////////

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

if(isset($response["page_sheet_map"])){
    $data["page_sheet_map"]=$response["page_sheet_map"];
}
$data2["postingPeriod"]=$request["postingPeriod"];
$data2['nodoai']=false;
$input=json_encode($data2, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$bslist = $data2['BS'] ?? [];
$pllist = $data2['PL'] ?? [];

// 財務指標を計算
// (コメント省略)

// --- ここから勘定科目一覧を作成（オブジェクト配列） ---
$account_names_objects = array_merge(
    extractAccountNames($bslist),
    extractAccountNames($pllist)
);
// 全体でユニーク化（念のため）
$seen = [];
$unique_objs = [];
foreach ($account_names_objects as $obj) {
    $k = ($obj['勘定科目'] ?? '') . '|' . ($obj['分類'] ?? '');
    if (!isset($seen[$k])) {
        $seen[$k] = true;
        $unique_objs[] = $obj;
    }
}
$account_names_objects = array_values($unique_objs);

// ---------- ここから追加/差し替えコード ----------

/**
 * 各期ごと（今期/前期/前々期）に金額が存在する勘定科目だけを抽出して
 * 指定のJSON構造で返す（各エントリに "勘定科目" と "分類" を含む）。
 */
function buildAccountNamesForAI($bslist, $pllist) {
    $periods = ['今期', '前期', '前々期'];
    $out = ['BS' => [], 'PL' => []];

    // 初期化
    foreach (['BS','PL'] as $t) {
        foreach ($periods as $p) {
            $out[$t][$p] = [];
        }
    }

    // 重複チェック用セット（type => period => key(勘定科目|分類) => true）
    $seen = [
        'BS' => ['今期'=>[], '前期'=>[], '前々期'=>[]],
        'PL' => ['今期'=>[], '前期'=>[], '前々期'=>[]]
    ];

    foreach (['BS' => $bslist, 'PL' => $pllist] as $type => $list) {
        foreach ($list as $row) {
            $name = isset($row['勘定科目']) ? trim($row['勘定科目']) : '';
            if ($name === '') continue;
            $bunrui = isset($row['分類']) ? trim($row['分類']) : '';

            foreach ($periods as $p) {
                if (isset($row[$p]['金額']) && $row[$p]['金額'] !== '' && $row[$p]['金額'] !== null) {
                    $key = $name . '|' . $bunrui;
                    if (!isset($seen[$type][$p][$key])) {
                        $seen[$type][$p][$key] = true;
                        $out[$type][$p][] = [
                            '勘定科目' => $name,
                            '分類' => $bunrui
                        ];
                    }
                }
            }
        }
    }

    return $out;
}
/**
 * AI に渡して勘定科目マッピングを取得（curl_multiで N 本並列 + 多数決マージ）
 * @param array $account_names_for_ai buildAccountNamesForAI() の戻り値
 * @param int   $num_requests         並列実行する本数（デフォルト3）
 * @return array|mixed                従来スキーマのマージ結果 or エラー配列
 */
function fetchAccountMappingFromAI_v2($account_names_for_ai) {
    global $openai_api_key, $openai_api_keys, $model_str, $aitext2, $superout;
	$num_requests = 5;
    // ---- system prompt 読み込み ----
    if (!file_exists($aitext2)) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "system_promptファイルが見つかりません: $aitext2"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $system_prompt = file_get_contents($aitext2);
    if ($system_prompt === false) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "system_promptファイルの読み込みに失敗しました: $aitext2"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $superout = $system_prompt;

    // ---- ユーザーコンテンツ ----
    $user_content = <<<EOD
以下は各期ごとの勘定科目リストです。必ず厳密なJSONのみを返してください（余分な説明文を付けないこと）。

【必須スキーマ（出力）】
{
  "BS": {
    "今期": { "<分類名1>": [ {"勘定科目":"...","分類":"..."}, ... ], "<分類名2>": [ ... ], ... },
    "前期": { ... },
    "前々期": { ... }
  },
  "PL": {
    "今期": { "<分類名1>": [ {"勘定科目":"...","分類":"..."}, ... ], ... },
    "前期": { ... },
    "前々期": { ... }
  }
}

【重要ルール】
1. 各分類の配列要素は必ずオブジェクトで、キー '勘定科目' と '分類' を含めてください。
2. 重複判定は 勘定科目 + 分類 の組合せで行い、同一の組合せは1つだけ返してください。
3. 出力には必ず全ての分類キー（要求されている分類一覧）を含め、項目がない場合は空配列 [] としてください。
4. 合計項目（「○○合計」「○○計」等）が存在する場合は合計項目を優先してください（子項目は除外）。
5. 出力はJSONオブジェクトのみ。自然言語や注釈を一切付けないでください。

【入力データ】
EOD;
    $user_content .= "\n\n" . json_encode($account_names_for_ai, JSON_UNESCAPED_UNICODE);

    $payload = json_encode([
        "model" => $model_str,
        "messages" => [
            ["role" => "system", "content" => $system_prompt],
            ["role" => "user", "content" => $user_content]
        ],
        "temperature" => 0
    ], JSON_UNESCAPED_UNICODE);

    // ---- 並列実行本数を安全化 ----
    $num_requests = max(1, (int)$num_requests);

    // ---- 並列リクエスト準備 ----
    $url = "https://api.openai.com/v1/chat/completions";
    $mh  = curl_multi_init();
    $handles = [];

    // キー配列：$openai_api_keys が配列であればラウンドロビン、なければ単一キーを使い回し
    $candidate_keys = [];
    if (is_array($openai_api_keys) && count($openai_api_keys) > 0) {
        $candidate_keys = array_values($openai_api_keys);
    } else {
        $candidate_keys = [$openai_api_key];
    }

    for ($i = 0; $i < $num_requests; $i++) {
        $use_key = $candidate_keys[$i % count($candidate_keys)];
        $headers = [
            "Content-Type: application/json",
            "Authorization: Bearer {$use_key}"
        ];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 60,
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$i] = $ch;
    }

    // 実行
    $active = null;
    do {
        $mrc = curl_multi_exec($mh, $active);
        if ($mrc > CURLM_OK) break;
        curl_multi_select($mh, 1.0);
    } while ($active && $mrc == CURLM_OK);

    // 回収
    $answers = [];
    foreach ($handles as $ch) {
        $res = curl_multi_getcontent($ch);
        $err = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($res === false || !empty($err)) {
            $answers[] = null;
            continue;
        }
        $decoded = json_decode($res, true);
        $reply = $decoded["choices"][0]["message"]["content"] ?? null;
        if ($reply === null) {
            $answers[] = null;
            continue;
        }
        $json = json_decode($reply, true);
        $answers[] = is_array($json) ? $json : null;
    }
    curl_multi_close($mh);

    // 有効応答のみ
    $valid = array_values(array_filter($answers, fn($x) => is_array($x)));
    if (count($valid) === 0) {
        return [
            "error" => "AI returned invalid JSON in all attempts",
            "raw"   => $answers
        ];
    }
    if (count($valid) === 1) {
        return $valid[0];
    }

    // ---- 多数決マージ（閾値 = floor(n/2)+1）----
    $vote_threshold = intdiv(count($valid), 2) + 1; // 例: 4本なら 3、3本なら 2
    $periods  = ['今期','前期','前々期'];
    $sections = ['BS','PL'];

    // 出現カテゴリ集合
    $all = [];
    foreach ($sections as $sec) {
        foreach ($periods as $p) {
            $all[$sec][$p] = [];
        }
    }
    foreach ($valid as $ans) {
        foreach ($sections as $sec) {
            if (!isset($ans[$sec]) || !is_array($ans[$sec])) continue;
            foreach ($periods as $p) {
                $mapP = $ans[$sec][$p] ?? [];
                if (!is_array($mapP)) continue;
                foreach ($mapP as $cat => $_) {
                    $all[$sec][$p][$cat] = true;
                }
            }
        }
    }

    // 投票関数
    $vote = function(array $answers, string $sec, string $p, string $cat, int $threshold) {
        $counter = [];
        $first_union = [];
        $first_seen = false;

        foreach ($answers as $ans) {
            $items = $ans[$sec][$p][$cat] ?? [];
            if (!is_array($items)) $items = [];
            if ($first_seen === false) {
                foreach ($items as $it) {
                    $name = is_array($it) ? ($it['勘定科目'] ?? '') : (string)$it;
                    $cls  = is_array($it) ? ($it['分類'] ?? '')     : '';
                    if ($name !== '') $first_union[$name.'|'.$cls] = ['勘定科目'=>$name,'分類'=>$cls];
                }
                $first_seen = true;
            }
            $seen_this = [];
            foreach ($items as $it) {
                $name = is_array($it) ? ($it['勘定科目'] ?? '') : (string)$it;
                $cls  = is_array($it) ? ($it['分類'] ?? '')     : '';
                if ($name === '') continue;
                $key = $name.'|'.$cls;
                if (isset($seen_this[$key])) continue;
                $seen_this[$key] = true;
                $counter[$key] = ($counter[$key] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($counter as $k => $cnt) {
            if ($cnt >= $threshold) {
                [$n,$c] = explode('|', $k, 2);
                $out[] = ['勘定科目'=>$n, '分類'=>$c];
            }
        }
        if (count($out) === 0 && count($first_union) > 0) {
            $out = array_values($first_union); // フォールバック
        }
        return $out;
    };

    $merged = ['BS'=>['今期'=>[],'前期'=>[],'前々期'=>[]], 'PL'=>['今期'=>[],'前期'=>[],'前々期'=>[]]];
    foreach ($sections as $sec) {
        foreach ($periods as $p) {
            foreach (array_keys($all[$sec][$p]) as $cat) {
                $merged[$sec][$p][$cat] = $vote($valid, $sec, $p, $cat, $vote_threshold);
            }
        }
    }

    return $merged;
}


/**
 * AIの出力を期別かつ分類ごとに {勘定科目, 分類} オブジェクト配列へ正規化する
 */
function normalizeAiMapIncludeClassification($ai_map_by_period, $account_names_for_ai) {
    // helper: 勘定科目名 -> 配列 of 分類候補 を作る（BS/PL全期合算）
    $nameToClass = [];
    foreach (['BS','PL'] as $t) {
        if (!isset($account_names_for_ai[$t])) continue;
        foreach ($account_names_for_ai[$t] as $period => $list) {
            foreach ($list as $entry) {
                $n = isset($entry['勘定科目']) ? $entry['勘定科目'] : '';
                $c = isset($entry['分類']) ? $entry['分類'] : '';
                if ($n === '') continue;
                if (!isset($nameToClass[$n])) $nameToClass[$n] = [];
                if ($c === '') {
                    if (!in_array('', $nameToClass[$n], true)) $nameToClass[$n][] = '';
                } else {
                    if (!in_array($c, $nameToClass[$n], true)) $nameToClass[$n][] = $c;
                }
            }
        }
    }

    $normalized = ['BS'=>[], 'PL'=>[]];

    foreach (['BS','PL'] as $t) {
        if (!isset($ai_map_by_period[$t]) || !is_array($ai_map_by_period[$t])) {
            $normalized[$t] = $ai_map_by_period[$t] ?? [];
            continue;
        }
        foreach ($ai_map_by_period[$t] as $period => $mapForPeriod) {
            if (!is_array($mapForPeriod)) {
                $normalized[$t][$period] = [];
                continue;
            }
            $normalized[$t][$period] = [];
            foreach ($mapForPeriod as $category => $arr) {
                $outList = [];
                if (!is_array($arr)) {
                    $normalized[$t][$period][$category] = [];
                    continue;
                }
                foreach ($arr as $item) {
                    if (is_array($item)) {
                        $name = $item['勘定科目'] ?? ($item[0] ?? '');
                        $class = $item['分類'] ?? ($item[1] ?? '');
                        $obj = ['勘定科目' => $name, '分類' => $class];
                    } else {
                        $name = (string)$item;
                        $class = '';
                        if (isset($nameToClass[$name]) && count($nameToClass[$name]) > 0) {
                            foreach ($nameToClass[$name] as $cc) {
                                if ($cc !== '') { $class = $cc; break; }
                            }
                            if ($class === '') $class = $nameToClass[$name][0];
                        }
                        $obj = ['勘定科目' => $name, '分類' => $class];
                    }
                    // 重複チェック
                    $key = ($obj['勘定科目'] ?? '') . '|' . ($obj['分類'] ?? '');
                    $exists = false;
                    foreach ($outList as $ex) {
                        if (($ex['勘定科目'] ?? '') . '|' . ($ex['分類'] ?? '') === $key) {
                            $exists = true;
                            break;
                        }
                    }
                    if (!$exists && ($obj['勘定科目'] ?? '') !== '') {
                        $outList[] = $obj;
                    }
                }
                $normalized[$t][$period][$category] = $outList;
            }
        }
    }
    return $normalized;
}

/**
 * AI出力（BS/PL -> 今期/前期/前々期 -> カテゴリ -> [ {"勘定科目","分類"} ]）
 * を current_term/previous_term/previous_previous_term の形に変換し、
 * カテゴリ => [勘定科目名, ...] の簡易マップを返す。
 */
function buildPeriodAccountMap($ai_map_by_period) {
    $periodKeyMap = [
        '今期' => 'current_term',
        '前期' => 'previous_term',
        '前々期' => 'previous_previous_term'
    ];

    $out = [
        'current_term' => [],
        'previous_term' => [],
        'previous_previous_term' => []
    ];

    foreach (['BS','PL'] as $section) {
        if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;
        foreach ($ai_map_by_period[$section] as $japanesePeriod => $mapForPeriod) {
            if (!isset($periodKeyMap[$japanesePeriod])) continue;
            $pkey = $periodKeyMap[$japanesePeriod];
            if (!is_array($mapForPeriod)) continue;
            foreach ($mapForPeriod as $category => $arr) {
                if (!is_array($arr)) continue;
                if (!isset($out[$pkey][$category])) $out[$pkey][$category] = [];
                foreach ($arr as $entry) {
                    if (is_array($entry)) {
                        $nm = $entry['勘定科目'] ?? '';
                    } else {
                        $nm = (string)$entry;
                    }
                    if ($nm === '') continue;
                    if (!in_array($nm, $out[$pkey][$category], true)) {
                        $out[$pkey][$category][] = $nm;
                    }
                }
            }
        }
    }
    return $out;
}

/**
 * カテゴリ名（indicator）から該当する勘定科目配列を探す。
 * - まず完全一致、なければ部分一致（包含）でマッチさせる。
 */
function findAccountsForIndicator($map, $indicator) {
    if (!is_array($map)) return [];
    if (isset($map[$indicator]) && is_array($map[$indicator])) {
        return $map[$indicator];
    }
    $normalize = function($s) {
        $s2 = mb_strtolower(trim(preg_replace('/\s+/', '', (string)$s)));
        return $s2;
    };
    $ni = $normalize($indicator);
    foreach ($map as $k => $arr) {
        if (!is_array($arr)) continue;
        $nk = $normalize($k);
        if ($nk === '') continue;
        if ($nk === $ni || mb_strpos($nk, $ni) !== false || mb_strpos($ni, $nk) !== false) {
            return $arr;
        }
    }
    return [];
}

// ---------- ここまで追加/差し替えコード ----------

// 各期ごとに金額がある勘定科目のみを抽出してAI用のJSON構造を作成
$account_names_for_ai = buildAccountNamesForAI($bslist, $pllist);
function removeClassificationField($account_names_for_ai) {
    foreach (['BS','PL'] as $sect) {
        if (!isset($account_names_for_ai[$sect])) continue;
        foreach ($account_names_for_ai[$sect] as $period => &$list) {
            foreach ($list as &$entry) {
                if (isset($entry['分類'])) unset($entry['分類']);
            }
            unset($entry);
        }
        unset($list);
    }
    return $account_names_for_ai;
}
//$account_names_for_ai=removeClassificationField($account_names_for_ai);
// AIへ投げて期別マッピングを取得
$ai_map_by_period = fetchAccountMappingFromAI_v2($account_names_for_ai);
/**
 * 指定カテゴリが AI 出力に無い/空なら、account_names_for_ai を“全セクション・全期間”なめて
 * 勘定科目名の存在だけで埋める（分類は完全に無視）
 * - デフォ対象:
 *   BS: 資本金, 固定負債, 流動負債
 *   PL: 営業外費用, 営業外収益, 営業利益
 * - 既に値があれば上書きしない（空のときのみ補完）
 *
 * @param array $ai_map_by_period     normalizeAiMapIncludeClassification() 後のマップ
 * @param array $account_names_for_ai buildAccountNamesForAI() の戻り値
 * @param array|null $targetsOverride 例: ['BS'=>['資本金'], 'PL'=>['営業利益']] のように上書き可能
 * @return array 補完後の $ai_map_by_period
 */
function ensureRequiredCategories(array $ai_map_by_period, array $account_names_for_ai, ?array $targetsOverride = null): array
{
    $periods  = ['今期','前期','前々期'];
    $sections = ['BS','PL'];

    // 対象カテゴリ（必要なら第3引数で上書き可能）
    $defaultTargets = [
        'BS' => ['資本金','固定負債','流動負債'],
        'PL' => ['営業外費用','営業外収益','営業利益'],
    ];
    $targets = $targetsOverride ?? $defaultTargets;

    // “実質空”の判定：未定義 or [] or [""] など
    $isEffectivelyEmpty = function($v): bool {
        if (!is_array($v) || count($v) === 0) return true;
        foreach ($v as $e) {
            if (is_array($e)) {
                // オブジェクト/配列でも何か非空文字があれば空ではないとみなす
                foreach ($e as $vv) {
                    if ((string)$vv !== '') return false;
                }
            } else {
                if ((string)$e !== '') return false;
            }
        }
        return true;
    };

    // 勘定科目が account_names_for_ai のどこか（BS/PL全期間）に1つでもあるか？
    $existsAnywhere = function(string $account) use ($account_names_for_ai, $sections, $periods): bool {
        foreach ($sections as $sec) {
            if (!isset($account_names_for_ai[$sec])) continue;
            foreach ($periods as $p) {
                foreach (($account_names_for_ai[$sec][$p] ?? []) as $row) {
                    if (($row['勘定科目'] ?? '') === $account) {
                        return true;
                    }
                }
            }
        }
        return false;
    };

    // 本体：空なら補完（分類は無視し、勘定科目名のみを配列で入れる）
    foreach ($sections as $sec) {
        foreach ($periods as $p) {
            foreach ($targets[$sec] as $cat) {
                $cur = $ai_map_by_period[$sec][$p][$cat] ?? [];
                //if ($isEffectivelyEmpty($cur) && $existsAnywhere($cat)) {
				if ($existsAnywhere($cat)) {
                    $ai_map_by_period[$sec][$p][$cat] = [ $cat ];
                }
            }
        }
    }

    return $ai_map_by_period;
}

$ai_map_by_period = fetchAccountMappingFromAI_v2($account_names_for_ai);
$ai_map_by_period_old = $ai_map_by_period;
$ai_map_by_period = normalizeAiMapIncludeClassification($ai_map_by_period, $account_names_for_ai);
// ここで補完（分類を完全無視して存在チェック）
$ai_map_by_period = ensureRequiredCategories($ai_map_by_period, $account_names_for_ai);

//========================================
//phpオリジナル処理開始
//========================================
/**
 * $needle の全ての「文字」が $haystack に含まれているか判定する（UTF-8対応）
 *
 * @param mixed  $haystack           調べる文字列（例： $entry['勘定科目']）
 * @param mixed  $needle             含まれているか確認したい文字列（例： $moto_have_o）
 * @param bool   $case_sensitive     大文字小文字を区別するか（英数字が混在する場合に有効。日本語は影響なし）
 * @param bool   $respect_counts     true: 出現回数も考慮（needle 中の各文字の回数分 haystack に存在すること）
 *                                   false: 各文字が少なくとも1回含まれていれば OK（デフォルト）
 * @return bool
 */
function utf8_all_chars_in_haystack($haystack, $needle, $case_sensitive = true, $respect_counts = false) {
    // null や配列を避けて文字列化
    $haystack = isset($haystack) ? (string)$haystack : '';
    $needle   = isset($needle)   ? (string)$needle   : '';

    // 空の needle は false とする（仕様）。必要なら true に変えてください。
    if ($needle === '') {
        return false;
    }

    // 大文字小文字無視オプション（英数字混在を想定）
    if (!$case_sensitive) {
        if (function_exists('mb_strtolower')) {
            $haystack = mb_strtolower($haystack, 'UTF-8');
            $needle   = mb_strtolower($needle, 'UTF-8');
        } else {
            $haystack = strtolower($haystack);
            $needle   = strtolower($needle);
        }
    }

    // グラフェム単位で分解（intl の grapheme_* があれば使う）
    $split_fn = function(string $s) {
        // 空文字は空配列
        if ($s === '') return [];
        if (function_exists('grapheme_strlen') && function_exists('grapheme_substr')) {
            $len = grapheme_strlen($s);
            $arr = [];
            for ($i = 0; $i < $len; $i++) {
                $arr[] = grapheme_substr($s, $i, 1);
            }
            return $arr;
        }
        // フォールバック：コードポイント単位（多くのケースで十分）
        return preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY);
    };

    if ($respect_counts) {
        // 各文字の出現回数も比較するモード
        $needle_chars = $split_fn($needle);
        $haystack_chars = $split_fn($haystack);

        if (empty($needle_chars)) return false;

        $needle_counts = array_count_values($needle_chars);
        $hay_counts = array_count_values($haystack_chars);

        foreach ($needle_counts as $char => $need_count) {
            $have_count = isset($hay_counts[$char]) ? $hay_counts[$char] : 0;
            if ($have_count < $need_count) return false;
        }
        return true;
    } else {
        // 多重度を問わないモード（各文字が少なくとも1回含まれているか）
        // 文字ごとに mb_strpos で存在確認（mb_strpos が使えればそちらを利用）
        $needle_chars = array_unique($split_fn($needle)); // 重複チェック不要にするため一意化
        if (empty($needle_chars)) return false;

        foreach ($needle_chars as $char) {
            // mb_strpos を使えるなら使う（マルチバイト安全）
            if (function_exists('mb_strpos')) {
                if (mb_strpos($haystack, $char, 0, 'UTF-8') === false) {
                    return false;
                }
            } else {
                if (strpos($haystack, $char) === false) {
                    return false;
                }
            }
        }
        return true;
    }
}

$moto_have=array();
$moto_have_flag=array();
$set_target=array();
$moto_have[0]="売上原価";
$moto_have[1]="棚卸資産";
$moto_have[2]="支払利息";
$moto_have[3]="有形固定資産";
$moto_have[4]="その他の投資";
$moto_have[5]="資産合計";
$moto_have[6]="純資産合計";
$moto_have[7]="負債合計";
$moto_have[8]="退職給引当";
$moto_have_flag["売上原価"]=false;
$moto_have_flag["棚卸資産"]=false;
$moto_have_flag["支払利息"]=false;
$moto_have_flag["有形固定資産"]=false;
$moto_have_flag["その他の投資"]=false;
$moto_have_flag["資産合計"]=false;
$moto_have_flag["純資産合計"]=false;
$moto_have_flag["負債合計"]=false;
$moto_have_flag["退職給引当"]=false;
$set_target["売上原価"]="売上原価";
$set_target["棚卸資産"]="棚卸資産";
$set_target["支払利息"]="支払利息";
$set_target["有形固定資産"]="有形固定資産";
$set_target["その他の投資"]="その他の投資";
$set_target["資産合計"]="資産合計";
$set_target["純資産合計"]="純資産合計";
$set_target["負債合計"]="負債合計";
$set_target["退職給引当"]="退職給引当";
foreach (['BS','PL'] as $section) {
    if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;

    foreach (['今期','前期','前々期'] as $jpPeriod) {
		if (isset($account_names_for_ai[$section][$jpPeriod]) && is_array($account_names_for_ai[$section][$jpPeriod])) {
			foreach ($account_names_for_ai[$section][$jpPeriod] as $entry) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="支払利息"){
						if(isset($entry['勘定科目']) and mb_substr($entry['勘定科目'], 0, mb_strlen($moto_have_o, 'UTF-8'), 'UTF-8')==$moto_have_o){
							$moto_have_flag[$moto_have_o]=true;
							$set_target[$moto_have_o]=$entry['勘定科目'];
						}
					}elseif($moto_have_o=="有形固定資産" or $moto_have_o=="その他の投資" or $moto_have_o=="資産合計" or $moto_have_o=="純資産合計" or $moto_have_o=="負債合計" or $moto_have_o=="退職"){
						if(isset($entry['勘定科目']) and utf8_all_chars_in_haystack($entry['勘定科目'], $moto_have_o, true, false)){
							$moto_have_flag[$moto_have_o]=true;
							$set_target[$moto_have_o]=$entry['勘定科目'];
						}
					}elseif(isset($entry['勘定科目']) and $entry['勘定科目']==$moto_have_o){
						$moto_have_flag[$moto_have_o]=true;
					}
				}
			}
		}
		if (isset($ai_map_by_period[$section][$jpPeriod]) && is_array($ai_map_by_period[$section][$jpPeriod])) {
			foreach ($ai_map_by_period[$section][$jpPeriod] as $cat => $arr) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="売上原価"){
						if($cat==$moto_have_o and $moto_have_flag[$moto_have_o]){
							$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target[$cat]];
						}
					}elseif($moto_have_o=="支払利息"){
						if($cat==$moto_have_o and $moto_have_flag[$moto_have_o] and is_array($arr) and count($arr)==0){
							$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target[$cat]];
						}
					}elseif($moto_have_o=="棚卸資産" or $moto_have_o=="その他の投資" or $moto_have_o=="総資産"){
						if($cat==$moto_have_o and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target[$cat]];
							}
						}
					}elseif($moto_have_o=="有形固定資産"){
						if($cat=="有形固定資産額" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target["有形固定資産"]];
							}
						}
					}elseif($moto_have_o=="資産合計"){
						if($cat=="総資産" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target["資産合計"]];
							}
						}
					}elseif($moto_have_o=="純資産合計"){
						if($cat=="純資産" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target["純資産合計"]];
							}
						}
					}elseif($moto_have_o=="負債合計"){
						if($cat=="負債" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target["負債合計"]];
							}
						}
					}elseif($moto_have_o=="退職給引当"){
						if($cat=="退職給与引当金" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target["退職給引当"]];
							}
						}
					}
				}
			}
		}
	}
}
$moto_have=array();
$moto_have_flag=array();
$set_target=array();
$moto_have[0]="商品";
$moto_have_flag["商品"]=false;
$set_target["棚卸資産"]="商品";
foreach (['BS','PL'] as $section) {
    if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;

    foreach (['今期','前期','前々期'] as $jpPeriod) {
		if (isset($account_names_for_ai[$section][$jpPeriod]) && is_array($account_names_for_ai[$section][$jpPeriod])) {
			foreach ($account_names_for_ai[$section][$jpPeriod] as $entry) {
				foreach ($moto_have as $moto_have_o) {
					if(isset($entry['勘定科目']) and $entry['勘定科目']==$moto_have_o){
						$moto_have_flag[$moto_have_o]=true;
					}
				}
			}
		}
		if (isset($ai_map_by_period[$section][$jpPeriod]) && is_array($ai_map_by_period[$section][$jpPeriod])) {
			foreach ($ai_map_by_period[$section][$jpPeriod] as $cat => $arr) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="商品"){
						if($cat=="棚卸資産" and $moto_have_flag[$moto_have_o]){
							if(count($arr)==0){
								$ai_map_by_period[$section][$jpPeriod][$cat]=[$set_target[$cat]];
							}
						}
					}
				}
			}
		}
	}
}
/**
 * マルチバイト対応：$haystack に $needle が含まれているか判定する
 *
 * @param mixed  $haystack       検査対象（null や配列が来たら空文字扱い）
 * @param mixed  $needle         探す文字列（空文字は false を返す）
 * @param string $encoding       文字エンコーディング（通常 'UTF-8'）
 * @param bool   $case_sensitive 大文字小文字を区別するか（英数字が混ざる場合に有効）
 * @return bool
 */
function mb_contains($haystack, $needle, $encoding = 'UTF-8', $case_sensitive = true) {
    // 型・null 対策
    $haystack = isset($haystack) ? (string)$haystack : '';
    $needle   = isset($needle)   ? (string)$needle   : '';

    // 空の needle は false（仕様による）
    if ($needle === '') {
        return false;
    }

    // mbstring の strpos 系が使えるならそれを使う（マルチバイト安全）
    if (function_exists('mb_strpos')) {
        if ($case_sensitive) {
            return mb_strpos($haystack, $needle, 0, $encoding) !== false;
        } else {
            // mb_stripos があれば使う（大文字小文字無視）
            if (function_exists('mb_stripos')) {
                return mb_stripos($haystack, $needle, 0, $encoding) !== false;
            }
            // 無ければ小文字化して比較（注意：日本語には意味がないが英数字混在で有効）
            return mb_strpos(mb_strtolower($haystack, $encoding), mb_strtolower($needle, $encoding), 0, $encoding) !== false;
        }
    }

    // mbstring が無い環境のフォールバック：正規表現（UTF-8 / u フラグ）
    // preg_match を使えば Unicode も扱える（ただし環境差に注意）
    $pattern = '/' . preg_quote($needle, '/') . '/u' . ($case_sensitive ? '' : 'i');
    return preg_match($pattern, $haystack) === 1;
}

$moto_have=array();
$moto_have_flag=array();
$set_target=array();
$moto_have[0]="借入金";
$moto_have_flag["借入金"]=false;
$set_target["借入金"]=array();
foreach (['BS','PL'] as $section) {
    if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;

    foreach (['今期','前期','前々期'] as $jpPeriod) {
		if (isset($account_names_for_ai[$section][$jpPeriod]) && is_array($account_names_for_ai[$section][$jpPeriod])) {
			foreach ($account_names_for_ai[$section][$jpPeriod] as $entry) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="借入金"){
						if(isset($entry['勘定科目']) and mb_contains($entry['勘定科目'], $moto_have_o)){
							$moto_have_flag[$moto_have_o]=true;
							array_push($set_target[$moto_have_o], $entry['勘定科目']);
						}
					}
					
				}
			}
		}
		if (isset($ai_map_by_period[$section][$jpPeriod]) && is_array($ai_map_by_period[$section][$jpPeriod])) {
			foreach ($ai_map_by_period[$section][$jpPeriod] as $cat => $arr) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="借入金"){
						if($cat==$moto_have_o and $moto_have_flag[$moto_have_o] and is_array($arr) and count($arr)==0){
							$ai_map_by_period[$section][$jpPeriod][$cat]=$set_target[$moto_have_o];
						}
					}
				}
			}
		}
	}
}

//（自己資本の取得）
//自己資本＝純資産の部＝負債・純資産の部合計（資産の部）-負債の合計
//①純資産の部合計の値があれば採用（表記ゆれを考慮）
//②①が無い場合、株主資本を代用
$moto_have=array();
$moto_have_flag=array();
$set_target=array();
$moto_have[0]="株主資本";
$moto_have[1]="純資産";
$moto_have_flag["株主資本"]=false;
$moto_have_flag["純資産"]=false;
$set_target["株主資本"]=array();
$set_target["純資産"]=array();
$phpjikoshihon=false;
foreach (['BS','PL'] as $section) {
    if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;

    foreach (['今期','前期','前々期'] as $jpPeriod) {
		if (isset($account_names_for_ai[$section][$jpPeriod]) && is_array($account_names_for_ai[$section][$jpPeriod])) {
			foreach ($account_names_for_ai[$section][$jpPeriod] as $entry) {
				foreach ($moto_have as $moto_have_o) {
					if(isset($entry['勘定科目']) and $entry['勘定科目']==$moto_have_o){
						$moto_have_flag[$moto_have_o]=true;
						array_push($set_target[$moto_have_o], $entry['勘定科目']);
					}
				}
			}
		}
		if (isset($ai_map_by_period[$section][$jpPeriod]) && is_array($ai_map_by_period[$section][$jpPeriod])) {
			foreach ($ai_map_by_period[$section][$jpPeriod] as $cat => $arr) {
				foreach ($moto_have as $moto_have_o) {
					if($moto_have_o=="株主資本" or $moto_have_o=="純資産"){
						if($cat=="自己資本額" and $moto_have_flag[$moto_have_o]){
							$phpjikoshihon=true;
							$ai_map_by_period[$section][$jpPeriod][$cat]=$set_target[$moto_have_o];
						}
					}
				}
			}
		}
	}
}

// --- END: 支払利息系のキーを条件付きで追加 ---
// --- START: 条件付きで "当期利益" を追加（AI出力に当期利益系カテゴリが存在し、入力にも当期利益がある場合のみ） ---
$primaryTerm = '当期利益';
$aliases = ['当期利益', '当期純利益']; // 必要ならここに別名を追加

foreach (['BS','PL'] as $section) {
    if (!isset($ai_map_by_period[$section]) || !is_array($ai_map_by_period[$section])) continue;

    foreach (['今期','前期','前々期'] as $jpPeriod) {
        // 入力側に当期利益（または別名）があるか確認
        $foundInInput = false;
        if (isset($account_names_for_ai[$section][$jpPeriod]) && is_array($account_names_for_ai[$section][$jpPeriod])) {
            foreach ($account_names_for_ai[$section][$jpPeriod] as $entry) {
                $nm = isset($entry['勘定科目']) ? trim($entry['勘定科目']) : '';
                if ($nm === '') continue;
                foreach ($aliases as $alias) {
                    if ($nm === $alias) { $foundInInput = true; break 2; }
                }
            }
        }
        if (!$foundInInput) continue;

        // AI出力側に当期利益（または別名）のカテゴリがあるか確認（カテゴリ名の一致で検出）
        $ai_has_alias = false;
        $alias_key = null;
        if (isset($ai_map_by_period[$section][$jpPeriod]) && is_array($ai_map_by_period[$section][$jpPeriod])) {
            foreach ($ai_map_by_period[$section][$jpPeriod] as $cat => $arr) {
                $catNorm = trim(mb_strtolower(preg_replace('/\s+/', '', (string)$cat)));
                foreach ($aliases as $alias) {
                    $aliasNorm = trim(mb_strtolower(preg_replace('/\s+/', '', (string)$alias)));
                    if ($catNorm === $aliasNorm) {
                        $ai_has_alias = true;
                        $alias_key = $cat;
                        break 2;
                    }
                }
            }
        }

        // AI出力に当期利益系があり、かつ "当期利益" キーが未セットならコピーして追加
        if ($ai_has_alias) {
            if (!isset($ai_map_by_period[$section][$jpPeriod][$primaryTerm])) {
                // 既存の alias の配列をそのままコピーして当期利益キーとしてセット
                $ai_map_by_period[$section][$jpPeriod][$primaryTerm] = $ai_map_by_period[$section][$jpPeriod][$alias_key];
            }
        }
    }
}
// --- END ---
if (!$ai_map_by_period || !is_array($ai_map_by_period)) {
    echo json_encode([
        "status" => "error",
        "message" => "AIマッピング失敗: 無効な形式",
        "ai_raw" => $ai_map_by_period ?? "no reply"
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// 期別の簡易マップ（period => category => [勘定科目名,...]）
$account_map_period_simple = buildPeriodAccountMap($ai_map_by_period);

// 3期分分析
$account_names = $account_names_objects; // 既存コード互換用の変数名

// ====== 自己資本額のAI算定（BSの純資産の部を金額付きで渡す）=======
$jikoshihon = [];

$period_map = [
    '今期' => '今期',
    '前期' => '前期',
    '前々期' => '前々期'
];

/**
 * 自己資本額を N 回並列で問い合わせ、クラスタリング多数採用で確定する完全版
 * - $openai_api_keys：複数キーをランダム使用（各試行ごとにランダム）
 * - $attempts：並列試行回数
 * - $clusterTolerance：同一金額とみなす絶対誤差（円）
 * - $timeoutSec：各 API 呼び出しのタイムアウト
 *
 * 事前に用意されている前提の変数例：
 *   $model_str, $aitext3, $period_map, $data
 *   $openai_api_keys = ["sk-xxxx1", "sk-xxxx2", ...];
 */

/* ====== ユーティリティ ====== */

/** 複数キーから 1 つランダム取得 */
function pickRandomKey(array $keys): string {
    if (empty($keys)) {
        throw new RuntimeException("openai_api_keys が空です。");
    }
    return $keys[array_rand($keys)];
}

/** 応答から JSON を抽出・パース（素直に JSON → 波括弧抽出） */
function extractJson(string $text): ?array {
    $decoded = json_decode($text, true);
    if (is_array($decoded)) return $decoded;

    if (preg_match('/\{.*\}/sU', $text, $m)) {
        $decoded2 = json_decode($m[0], true);
        if (is_array($decoded2)) return $decoded2;
    }
    return null;
}

/** 金額の正規化（カンマ/全角/円/括弧マイナス対応） */
function normalizeAmount($val): ?float {
    if ($val === null) return null;
    if (is_numeric($val)) return (float)$val;

    $s = preg_replace('/[,\s]/', '', (string)$val);
    $s = mb_convert_kana($s, 'n');           // 全角数字→半角
    $s = preg_replace('/[¥円]/u', '', $s);   // 円・¥ を除去

    if (preg_match('/^\(([\d\.]+)\)$/', $s, $m)) { // (123) → -123
        $s = '-' . $m[1];
    }
    return is_numeric($s) ? (float)$s : null;
}

/**
 * 値配列を絶対誤差 tolAbs でまとめる単純クラスタリング → 最多数クラスタ
 * @return array{center: float, members: float[]}|null
 */
function majorityCluster(array $values, float $tolAbs): ?array {
    if (!$values) return null;
    sort($values);
    $clusters = [];
    $current = [$values[0]];
    for ($i=1; $i<count($values); $i++) {
        if (abs($values[$i] - end($current)) <= $tolAbs) {
            $current[] = $values[$i];
        } else {
            $clusters[] = $current;
            $current = [$values[$i]];
        }
    }
    $clusters[] = $current;

    usort($clusters, fn($a,$b) => count($b) <=> count($a));
    $best = $clusters[0];
    $mid = (int)floor(count($best)/2);
    $center = $best[$mid];
    return ['center'=>$center, 'members'=>$best];
}

/* ====== コア処理：1期分を並列 N 回 & 多数採用 ====== */
/**
 * @param array  $rows              その期の BS 行（['勘定科目'=>..., '金額'=>...] の配列）
 * @param string $equity_prompt     system プロンプト文字列
 * @param string $model_str
 * @param array  $apiKeys           OpenAI API キー配列
 * @param int    $attempts          並列試行回数
 * @param float  $clusterTolerance  同一金額とみなす絶対誤差（円）
 * @param int    $timeoutSec        タイムアウト秒
 * @return array{amount:?float, items:array, raw:array}
 */
function computeEquityForPeriod(
    array $rows,
    string $equity_prompt,
    string $model_str,
    array $apiKeys,
    int $attempts = 5,
    float $clusterTolerance = 100.0,
    int $timeoutSec = 25
): array {
    $payload = json_encode([
        "model" => $model_str,
        "messages" => [
            ["role" => "system", "content" => $equity_prompt],
            ["role" => "user",   "content" => json_encode($rows, JSON_UNESCAPED_UNICODE)]
        ],
        "temperature" => 0
    ], JSON_UNESCAPED_UNICODE);

    $mh  = curl_multi_init();
    $chs = [];

    for ($i=0; $i<$attempts; $i++) {
        $apiKey = pickRandomKey($apiKeys);   // ★ 各試行でランダムキー選択
        $ch = curl_init("https://api.openai.com/v1/chat/completions");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: application/json",
                "Authorization: " . "Bearer {$apiKey}",
            ],
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => $timeoutSec,
        ]);
        curl_multi_add_handle($mh, $ch);
        $chs[] = $ch;
    }

    // 並列実行ループ
    do {
        $mrc = curl_multi_exec($mh, $running);
        if ($running) curl_multi_select($mh, 0.5);
    } while ($running && $mrc === CURLM_OK);

    // 回収
    $results = [];
    foreach ($chs as $ch) {
        $body = curl_multi_getcontent($ch);
        $err  = curl_error($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($err) {
            $results[] = ["ok"=>false, "error"=>$err];
            continue;
        }
        $json  = json_decode($body, true);
        $reply = $json["choices"][0]["message"]["content"] ?? null;
        if (!$reply) {
            $results[] = ["ok"=>false, "error"=>"empty_reply"];
            continue;
        }
        $parsed = extractJson($reply);
        if (!$parsed) {
            $results[] = ["ok"=>false, "error"=>"parse_failed", "raw"=>$reply];
            continue;
        }
        $amount = normalizeAmount($parsed["自己資本額"] ?? null);
        $items  = $parsed["使用項目"] ?? [];
        if (!is_array($items)) $items = [];

        $results[] = ["ok"=>true, "amount"=>$amount, "items"=>$items, "raw"=>$parsed];
    }
    curl_multi_close($mh);

    // 正常応答の金額のみ抽出
    $amounts = [];
    foreach ($results as $r) {
        if (!empty($r["ok"]) && $r["amount"] !== null) {
            $amounts[] = (float)$r["amount"];
        }
    }

    if (!$amounts) {
        return ["amount"=>null, "items"=>[], "raw"=>$results];
    }

    // 多数派クラスタを選定
    $cluster = majorityCluster($amounts, $clusterTolerance);
    if (!$cluster) {
        // フォールバック：先頭採用
        $pick = $amounts[0];
        foreach ($results as $r) {
            if (!empty($r["ok"]) && $r["amount"] === $pick) {
                return ["amount"=>$pick, "items"=>$r["items"], "raw"=>$results];
            }
        }
        return ["amount"=>$pick, "items"=>[], "raw"=>$results];
    }

    $center = $cluster['center'];

    // center 近傍の応答から items を採用（最初の一致）
    $pickedItems = [];
    foreach ($results as $r) {
        if (!empty($r["ok"]) && $r["amount"] !== null && abs($r["amount"] - $center) <= $clusterTolerance) {
            $pickedItems = $r["items"];
            break;
        }
    }

    return ["amount"=>$center, "items"=>$pickedItems, "raw"=>$results];
}

/* ====== ここから呼び出し側（置き換え対象） ====== */

// プロンプトファイル読み込み（1 回だけ）
if (!file_exists($aitext3)) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "aitext3が見つかりません: $aitext3"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$equity_prompt = file_get_contents($aitext3);
if ($equity_prompt === false) {
    http_response_code(500);
    echo json_encode([
        "status"   => "error",
        "message"  => "aitext3の読み込みに失敗しました: $aitext3"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ★ 複数 API キー：設定ファイルや環境変数から取得して配列にしてください */
if (empty($openai_api_keys) || !is_array($openai_api_keys)) {
    // 例：環境変数からカンマ区切りで取得
    $env = getenv('OPENAI_API_KEYS') ?: '';
    $openai_api_keys = array_values(array_filter(array_map('trim', explode(',', $env))));
}
if (empty($openai_api_keys)) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "OpenAI API keys が設定されていません。"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* パラメータ（必要に応じて調整） */
$attempts         = 5;      // 並列試行回数
$clusterTolerance = 100.0;  // 同一値とみなす許容誤差（円）
$timeoutSec       = 25;     // タイムアウト秒

$jikoshihon = [];  // 出力マップ

foreach ($period_map as $label => $key) {
    // その期の BS 行を抽出
    $rows = [];
    foreach ($data['BS'] as $row) {
        if (!empty($row[$key]['金額'])) {
            $rows[] = [
                '勘定科目' => $row['勘定科目'],
                '金額'     => $row[$key]['金額'],
            ];
        }
    }

    // 並列 N 回 & 多数採用で自己資本額を決定
    $res = computeEquityForPeriod(
        rows: $rows,
        equity_prompt: $equity_prompt,
        model_str: $model_str,
        apiKeys: $openai_api_keys,        // ★ 複数キー配列
        attempts: $attempts,
        clusterTolerance: $clusterTolerance,
        timeoutSec: $timeoutSec
    );

    $jikoshihon[$label]          = $res["amount"];        // 多数派の金額
    $jikoshihon[$label . "項目"] = $res["items"] ?? [];   // 代表の使用項目
}

// ここで $jikoshihon が完成（例：$jikoshihon["今期"], $jikoshihon["今期項目"] など）


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

function format_number($value, $percent = false) {
    if (!isset($value)) return "";
    $value = round($value, 2);
    return $percent ? number_format($value, 2) . "%" : number_format($value, 2);
}

// 各期ごとの値をマッピング
$periods = [
    'previous_previous_term' => extractValues($bslist, '前々期') + extractValues($pllist, '前々期'),
    'previous_term' => extractValues($bslist, '前期') + extractValues($pllist, '前期'),
    'current_term' => extractValues($bslist, '今期') + extractValues($pllist, '今期')
];

// --- item_values の作成（項目タイプ -> 各期の値をマッピング） ---
$item_values = []; // e.g. ['売上高']['current_term'] = 123456
$jikoahihonbox=array();
foreach ($templetdata["template"] as $row) {
    $type = $row['type'];
    $indicator = $row['indicator'];

    if ($type === "項目") {
        foreach ($periods as $key => $amounts) {
            // その期のマップ（カテゴリ => [勘定科目名...]）
            $map_for_period = $account_map_period_simple[$key] ?? [];
			if ($indicator === "自己資本額") {
				$jikoahihonbox["map_for_period"]=$map_for_period;
				$jikoahihonbox["indicator"]=$indicator;
			}
            // 該当インジケータに対応する勘定科目名リストを探す
            $accounts_for_indicator = findAccountsForIndicator($map_for_period, $indicator);
            $value = null;
            // ---------- ここを「複数勘定合算」ロジックに変更 ----------
            $sum = 0.0;
            $found = false;
            if (is_array($accounts_for_indicator)) {
                foreach ($accounts_for_indicator as $name) {
                    if (isset($amounts[$name])) {
                        $sum += floatval($amounts[$name]);
                        $found = true;
                    }
                }
            }
            if ($found) {
                $value = $sum;
            } else {
                $value = null;
            }
            // -------------------------------------------------------
            if ($indicator === "自己資本額" and $phpjikoshihon==false) {
                // 自己資本額はAI算定値を優先
                $equity = get_equity($jikoshihon, $key);
                if (!empty($equity) || $equity === 0) {
                    $value = $equity;
                }
            }
            if ($value !== null) {
                $item_values[$indicator][$key] = $value;
            }
        }
    }
}

function get_equity($jikoshihon,$key){
    $equity=0;
    if($key == "current_term"){
        $equity=$jikoshihon["今期"];
    }elseif($key == "previous_term"){
        $equity=$jikoshihon["前期"];
    }elseif($key == "previous_previous_term"){
        $equity=$jikoshihon["前々期"];
    }
    return $equity;
}

// 指標%計算のための項目保持
$respons = [];
$account = array();
$csv_map = array();
foreach ($templetdata["template"] as $row) {
    $type = $row['type'];
    $indicator = $row['indicator'];
    $equation = $row['equation'] ?? "";

    $new_row = $row;

    if ($type === "項目") {
        foreach ($periods as $key => $amounts) {
            $map_for_period = $account_map_period_simple[$key] ?? [];
			$accounts_for_indicator = findAccountsForIndicator($map_for_period, $indicator);
			if($indicator=="（投資有価証券）"){
				$accounts_for_indicator = findAccountsForIndicator($map_for_period, "投資有価証券");
			}
			if ($indicator === "総資本金額") {
                $accounts_for_indicator = findAccountsForIndicator($map_for_period, "総資産");
            }
            $value = null;
            // ---------- ここを「複数勘定合算」ロジックに変更 ----------
            $sum = 0.0;
            $found = false;
            if (is_array($accounts_for_indicator)) {
                foreach ($accounts_for_indicator as $name) {
                    if (isset($amounts[$name])) {
                        $sum += floatval($amounts[$name]);
                        $found = true;
                    }
                }
            }
            if ($found) {
                $value = $sum;
            } else {
                $value = null;
            }
            // -------------------------------------------------------
            if ($indicator === "自己資本額" and $phpjikoshihon===false) {
                $value = get_equity($jikoshihon,$key);
            }
            if ($value !== null) {
                $new_row[$key] = number_format($value, 0);
            }
            $csv_map[$indicator."_".$key] = $new_row[$key];
        }
    } elseif ($type === "指標%") {
        foreach ($periods as $key => $values) {
            $value = null;
            $map_for_period = $account_map_period_simple[$key] ?? [];

            if ($indicator === "売上総利益率") {
                [$gp, ] = sumAccounts("売上総利益", $values, $map_for_period);
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                if (!empty($revenue) and (!empty($gp) or $gp==0)) $value = $gp / $revenue * 100;

            } elseif ($indicator === "売上原価率") {
                [$cogs, ] = sumAccounts("売上原価", $values, $map_for_period);
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                if (!empty($revenue) and (!empty($cogs) or $cogs==0)) $value = $cogs / $revenue * 100;

            } elseif ($indicator === "売上高経費比率") {
                [$exp, ] = sumAccounts("一般管理販売費", $values, $map_for_period);
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                if (!empty($revenue) and (!empty($exp) or $exp==0)) $value = $exp / $revenue * 100;

            } elseif ($indicator === "粗利経費率") {
                [$exp, ] = sumAccounts("一般管理販売費", $values, $map_for_period);
                $csv_map["販売費及び一般管理費"."_".$key] = $exp;
				[$gp, ] = sumAccounts("売上総利益", $values, $map_for_period);
                if (!empty($gp) and (!empty($exp) or $exp==0)) $value = $exp / $gp * 100;

            } elseif ($indicator === "営業利益率") {
                [$op, ] = sumAccounts("営業利益", $values, $map_for_period);
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                if (!empty($revenue) and (!empty($op) or $op==0)) $value = $op / $revenue * 100;

            } elseif ($indicator === "経常利益率") {
                [$ordinary, ] = sumAccounts("経常利益", $values, $map_for_period);
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                if (!empty($revenue) and (!empty($ordinary) or $ordinary==0)) $value = $ordinary / $revenue * 100;

            } elseif ($indicator === "流動比率") {
                [$ca, ] = sumAccounts("流動資産", $values, $map_for_period);
                $account["流動資産"][$key]=$ca;
                [$cl, ] = sumAccounts("流動負債", $values, $map_for_period);
                $account["流動負債"][$key]=$cl;
                [$ks, ] = sumAccounts("期首棚卸", $values, $map_for_period);
                [$km, ] = sumAccounts("期末棚卸", $values, $map_for_period);
                $account["期首棚卸"][$key]=$ks;
                $account["期末棚卸"][$key]=$km;
                
                $csv_map["流動資産"."_".$key] = $ca;
				$csv_map["流動負債"."_".$key] = $cl;
				$csv_map["期首棚卸"."_".$key] = $ks;
				$csv_map["期末棚卸"."_".$key] = $km;
				
				[$kes, ] = sumAccounts("繰延資産", $values, $map_for_period);
				[$ts, ] = sumAccounts("特別損失", $values, $map_for_period);
				[$fjg, ] = sumAccounts("負債・純資産の部合計", $values, $map_for_period);
				[$hjzn, ] = sumAccounts("法人税等", $values, $map_for_period);
				$csv_map["繰延資産"."_".$key] = $kes;
				$csv_map["特別損失"."_".$key] = $ts;
				$csv_map["負債・純資産の部合計"."_".$key] = $fjg;
				$csv_map["法人税等"."_".$key] = $hjzn;
                if (!empty($cl) and (!empty($ca) or $ca==0)) $value = $ca / $cl * 100;

            } elseif ($indicator === "当座比率") {
                [$ca, ] = sumAccounts("流動資産", $values, $map_for_period);
                [$inventory, ] = sumAccounts("棚卸資産", $values, $map_for_period);
                [$cl, ] = sumAccounts("流動負債", $values, $map_for_period);
                $ci="";
				$csv_map["棚卸資産"."_".$key] = $inventory;
                if (!empty($ca) or !empty($inventory)){
                    if(empty($ca)){$ca=0;}
                    if(empty($inventory)){$inventory=0;}
                    $ci=$ca - $inventory;
                }
                if (!empty($cl) and (!empty($ci) or $ci==0)) $value = ($ci) / $cl * 100;

            } elseif ($indicator === "固定比率") {
                [$fixed, ] = sumAccounts("固定資産", $values, $map_for_period);
                [$net_assets, ] = sumAccounts("純資産", $values, $map_for_period);
                $csv_map["固定資産"."_".$key] = $fixed;
				$csv_map["純資産"."_".$key] = $net_assets;
				if (!empty($net_assets) and (!empty($fixed) or $fixed==0)) $value = $fixed / $net_assets * 100;

            } elseif ($indicator === "固定長期適合率") {
                [$fixed, ] = sumAccounts("固定資産", $values, $map_for_period);
                [$net_assets, ] = sumAccounts("純資産", $values, $map_for_period);
                [$long_liab, ] = sumAccounts("固定負債", $values, $map_for_period);
                $account["固定資産"][$key]=$fixed;
                $account["固定負債"][$key]=$long_liab;
				
				$csv_map["固定資産"."_".$key] = $fixed;
				$csv_map["純資産"."_".$key] = $net_assets;
				$csv_map["固定負債"."_".$key] = $long_liab;
                $nl="";
                if (!empty($net_assets) or !empty($long_liab)){
                    if(empty($net_assets)){$net_assets=0;}
                    if(empty($long_liab)){$long_liab=0;}
                    $nl=$net_assets + $long_liab;
                }
                if (!empty($nl) and (!empty($fixed) or $fixed==0)) $value = $fixed / $nl * 100;

            } elseif ($indicator === "自己資本比率") {
				$equity=get_equity($jikoshihon,$key);
				if($phpjikoshihon){
					[$equity, ]=sumAccounts("自己資本額", $values, $map_for_period);
				}
                
                [$total_assets, ] = sumAccounts("総資産", $values, $map_for_period);
                $csv_map["自己資本額"."_".$key] = $equity;
				$csv_map["総資産"."_".$key] = $total_assets;
				if (!empty($total_assets) and (!empty($equity) or $equity==0)) $value = $equity / $total_assets * 100;

            } elseif ($indicator === "負債比率") {
                [$liabilities, ] = sumAccounts("負債", $values, $map_for_period);
                [$net_assets, ] = sumAccounts("純資産", $values, $map_for_period);
                $csv_map["負債"."_".$key] = $liabilities;
				if (!empty($net_assets) and (!empty($liabilities) or $liabilities==0)) $value = $liabilities / $net_assets * 100;

            } elseif ($indicator === "財務レバレッジ比率") {
                [$total_assets, ] = sumAccounts("総資産", $values, $map_for_period);
                $equity=get_equity($jikoshihon,$key);
				if($phpjikoshihon){
					[$equity, ]=sumAccounts("自己資本額", $values, $map_for_period);
				}
                if (!empty($equity) and (!empty($total_assets) or $total_assets==0)) $value = $total_assets / $equity * 100;

            } elseif ($indicator === "自己資本利益率（ROE）") {
                [$profit, ] = sumAccounts("当期純利益", $values, $map_for_period);
				$equity=get_equity($jikoshihon,$key);
				if($phpjikoshihon){
					[$equity, ]=sumAccounts("自己資本額", $values, $map_for_period);
				}
				
				$csv_map["当期純利益"."_".$key] = $profit;
				
				if($equity<=0){
					//ROEの算出時に、自己資本が－値の場合は
					$value="N/A（債務超過）";
				}else{
					if (!empty($equity) and (!empty($profit) or $profit==0)) $value = $profit / $equity * 100;
				}
                

            } elseif ($indicator === "総資産利益率（ROA）") {
                [$profit, ] = sumAccounts("当期純利益", $values, $map_for_period);
                $account["当期純利益"][$key]=$profit;
                [$total_assets, ] = sumAccounts("総資産", $values, $map_for_period);
                if (!empty($total_assets) and (!empty($profit) or $profit==0)) $value = $profit / $total_assets * 100;
            }
			if($value=="N/A（債務超過）"){
				$new_row[$key] = $value;
			}else{
				$new_row[$key] = isset($value) ? format_number($value, true) : "";
			}
            
        }
    } elseif ($type === "指標") {
        foreach ($periods as $key => $values) {
            $value = null;
            $map_for_period = $account_map_period_simple[$key] ?? [];

            if ($indicator === "棚卸回転率（回転/年）") {
                if($key != "previous_previous_term"){
                    if($key == "current_term"){
                        [$inventory_first, ] = sumAccounts("棚卸資産", $periods["previous_term"], $map_for_period);
                    }elseif($key == "previous_term"){
                        [$inventory_first, ] = sumAccounts("棚卸資産", $periods["previous_previous_term"], $map_for_period);
                    }

                    [$cogs, ] = sumAccounts("売上原価", $values, $map_for_period);
                    [$inventory, ] = sumAccounts("棚卸資産", $values, $map_for_period);
                    
					$csv_map["棚卸資産"."_".$key] = $inventory_first;
					
					$denominator="";
                    if (!empty($inventory) or !empty($inventory_first)){
                        if(empty($inventory)){$inventory=0;}
                        if(empty($inventory_first)){$inventory_first=0;}
                        $denominator=($inventory + $inventory_first) / 2;
                    }
                    if (!empty($denominator) and (!empty($cogs) or $cogs==0)) {
                        $value = $cogs / $denominator;
                    }
                }

            } elseif ($indicator === "総資本回転率") {
                [$revenue, ] = sumAccounts("売上高", $values, $map_for_period);
                [$total_assets, ] = sumAccounts("総資産", $values, $map_for_period);
                if (!empty($total_assets) and (!empty($revenue) or $revenue==0)) $value = $revenue / $total_assets;
            }

            $new_row[$key] = isset($value) ? format_number($value, false) : "";
        }
    }

    $respons[] = $new_row;
}
/**
 * 金額を「文字列金額」に正規化する
 * - 例: 1234 -> "1,234"
 * - 例: "￥１，２３４" -> "1,234"
 * - 例: "(1,234)" / "▲1,234" / "−1234" -> "-1,234"
 * - 小数点がある場合は桁数を保持（最大6桁まで）
 * - 数値に解釈不能 or 空/NULL は空文字("")を返す
 */
function normalize_amount_str($val): string {
    // 未定義・NULL・空文字は空で返す
    if (!isset($val)) return "";
    if (is_array($val) || is_object($val)) return ""; // 予防

    $s = (string)$val;
    $s = trim($s);
    if ($s === "") return "";

    // 全角→半角（数字・英字・記号とスペースを含め広めに）
    if (function_exists('mb_convert_kana')) {
        // 'as' : 全角英数を半角に, スペースの正規化
        $s = mb_convert_kana($s, 'as', 'UTF-8');
    }

    // よくある記号の正規化
    $s = str_replace(["￥", "¥", "円"], "", $s);   // 通貨記号/単位を除去
    $s = str_replace(["，"], ",", $s);             // 全角カンマ
    $s = str_replace(["．"], ".", $s);             // 全角ドット
    // Unicodeの各種マイナスを通常のハイフンに
    $s = str_replace(["−", "—", "―", "ｰ"], "-", $s);

    // 括弧や▲での負数表現
    $isNegative = false;
    if (preg_match('/^\(.*\)$/', $s)) {
        $isNegative = true;
        $s = trim($s, "()");
    }
    if (mb_strpos($s, "▲") !== false) {
        $isNegative = true;
        $s = str_replace("▲", "", $s);
    }

    // カンマ・スペース等を除去（小数点とハイフン以外の記号を排除）
    $s = str_replace([",", " "], "", $s);
    // ここで数字・小数点・ハイフン以外を除去
    $s = preg_replace('/[^0-9\.\-]/', '', $s);

    // ハイフンは先頭に1個だけ許可（中間のハイフンは除去）
    if (substr_count($s, '-') > 1) {
        // 例: "--123" など異常は正しく判定できないので空扱い
        return "";
    }
    // 中間にハイフンがある場合は除去して先頭に集約
    if (strlen($s) > 1 && strpos($s, '-', 1) !== false) {
        $s = str_replace('-', '', $s);
        $s = '-'.$s;
    }

    // 数値形式チェック
    if (!preg_match('/^-?\d+(\.\d+)?$/', $s)) {
        return "";
    }

    // 符号の合成（括弧/▲優先）
    if ($isNegative && $s[0] !== '-') {
        $s = '-'.$s;
    }

    // 小数桁数の判定（最大6桁まで保持）
    $decimals = 0;
    $pos = strpos($s, '.');
    if ($pos !== false) {
        $decimals = strlen($s) - $pos - 1;
        $decimals = max(1, min($decimals, 6)); // 少なくとも1桁、最大6桁
    }

    // number_formatでカンマ付与（小数は元の桁数を維持）
    $num = (float)$s;
    // 極小値 -0.0 → 0.0 に丸め（表示で "-0" 回避）
    if (abs($num) < 1e-12) $num = 0.0;

    return number_format($num, $decimals, '.', ',');
}

/**
 * $csv_map から指定キーの値を安全に取得して文字列金額化
 */
function get_amount_from_map(array $csv_map, string $key): string {
    $raw = $csv_map[$key] ?? null;
    return normalize_amount_str($raw);
}

/**
 * 要求仕様を満たした完全版 getCSVObj
 * - 指定3項目を「文字列金額」に正規化
 * - キー未定義/NULL/空/解釈不能は空文字で返す
 * - 指標名の特例（棚卸資産・資本金）は全角スペース付与
 */
function getCSVObj($name, array $csv_map) {
    $idn = $name;
    if ($name === "棚卸資産" || $name === "資本金") {
        $idn = "　".$name; // 先頭に全角スペース
    }

    $currentKey  = $name."_current_term";
    $previousKey = $name."_previous_term";
    $pprevKey    = $name."_previous_previous_term";

    $current  = get_amount_from_map($csv_map, $currentKey);
    $previous = get_amount_from_map($csv_map, $previousKey);
    $pprev    = get_amount_from_map($csv_map, $pprevKey);

    return [
        "indicator" => $idn,
        "equation"  => null,
        "current_term" => $current,                 // 文字列金額（例 "1,234" or "-1,234.56"）
        "previous_term" => $previous,               // 文字列金額（解釈不能は ""）
        "previous_previous_term" => $pprev,         // 文字列金額（解釈不能は ""）
        "type" => "hidden",
    ];
}

$recsv = [];
$recsv[] = getCSVObj("",$csv_map);
$recsv[] = getCSVObj("PL",$csv_map);
$recsv[] = getCSVObj("売上高",$csv_map);
$recsv[] = getCSVObj("売上原価",$csv_map);
$recsv[] = getCSVObj("売上総利益",$csv_map);
$recsv[] = getCSVObj("販売費及び一般管理費",$csv_map);
$recsv[] = getCSVObj("営業外収益",$csv_map);
$recsv[] = getCSVObj("営業外費用",$csv_map);
$recsv[] = getCSVObj("経常利益",$csv_map);
$recsv[] = getCSVObj("特別損失",$csv_map);
$recsv[] = getCSVObj("税引前利益",$csv_map);
$recsv[] = getCSVObj("法人税等",$csv_map);
$recsv[] = getCSVObj("当期利益",$csv_map);
$recsv[] = getCSVObj("",$csv_map);
$recsv[] = getCSVObj("BS",$csv_map);
$recsv[] = getCSVObj("流動資産",$csv_map);
$recsv[] = getCSVObj("棚卸資産",$csv_map);
$recsv[] = getCSVObj("固定資産",$csv_map);
$recsv[] = getCSVObj("繰延資産",$csv_map);
$recsv[] = getCSVObj("総資産",$csv_map);
$recsv[] = getCSVObj("流動負債",$csv_map);
$recsv[] = getCSVObj("固定負債",$csv_map);
$recsv[] = getCSVObj("純資産",$csv_map);
$recsv[] = getCSVObj("資本金",$csv_map);
$recsv[] = getCSVObj("負債・純資産の部合計",$csv_map);

$account=json_encode($account, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$financial_data=array();
$financial_data["response"]=$respons;
$financial_response=json_encode($financial_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        WHERE ai_case_id = ?
");

$stmt->bind_param("ssssi", $input, $account, $financial_response, $f_recsv, $ai_case_id);
$stmt->execute();
// 出力
echo json_encode([
    "status" => "success",
    "ai_case_id" => $ai_case_id,
    "account" => $account,
    "account_map_periods" => $ai_map_by_period,
	"ai_map_by_period_old" => $ai_map_by_period_old,
    "account_map_period_simple" => $account_map_period_simple,
    "account_names" => $account_names,
    "account_names_for_ai" => $account_names_for_ai,
    "data2" => $data2,
	"parsed" => $parsed,
    "jikoshihon" => $jikoshihon,
    "superout" => $superout,
	"jikoahihonbox" => $jikoahihonbox,
    "postingPeriod" => $request["postingPeriod"],
	"csv_map" => $csv_map,
	"recsv" => $recsv,
    "respons" => $respons,
    "message" => "財務分析完了"
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
