<?php
include "./common.php";
global $conn, $uploadDir, $backendbase, $python_base, $super_message, $openai_api_key, $model_str, $www_dir;
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
$summarytext = $data['summarytext'] ?? null;
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

/**
 * ユーザ入力のセキュリティチェックとサニタイズ
 *
 * @param string $input ユーザー入力
 * @return array ['sanitized_input' => string, 'is_valid' => bool, 'error' => string|null]
 */
function sanitizeAndValidateUserInput(string $input): array {
    $input = trim($input);

    // 長さ制限
    if (mb_strlen($input, 'UTF-8') > 1000) {
        return ['sanitized_input' => '', 'is_valid' => false, 'error' => '入力は1000文字以内にしてください。'];
    }

    // 制御文字や不可視文字を削除（UTF-8で不正な制御文字など）
    //$input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
	$input = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', '', $input);
	
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
$check = sanitizeAndValidateUserInput($summarytext);

if (!$check['is_valid']) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => $check['error']]);
    exit;
}

$summarytext = $check['sanitized_input'];

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT report_response, report_generation_start
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
// 結果をループして出力
$report_data=array();
$report_generation_start=0;
while ($re = $result->fetch_assoc()) {
	$report_generation_start = (int)$re['report_generation_start'];
	if($re['report_response']!=null and $re['report_response']!=""){
		$report_data = json_decode($re['report_response'], true);
	}else{
		http_response_code(400);
		echo json_encode(["status" => "error", "message" => "先に使用する指標を選んでください"]);
		exit;
	}
}

$i=0;
foreach ($report_data as $re) {
	if(isset($re['name']) and $re['name'] == "エグゼクティブサマリー"){
		$targetname = "エグゼクティブサマリー";
		if(isset($summarytext)){
			$report_data[$i]["text"] = $summarytext;
		}
	}
	$i++;
}
$report_response=json_encode($report_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,report_response = ?
        WHERE ai_case_id = ?
");

$stmt->bind_param("si", $report_response, $ai_case_id);
$stmt->execute();

/////////////////////////////////////////////////////////////////
// report_generation_start==1 の場合のみポイント削減とusedeへの追加（report_generation.phpから移管）
/////////////////////////////////////////////////////////////////
if ($report_generation_start === 1) {
    // user情報を loginkey から取得
    $point_for_use = null;
    $user_id_for_use = null;
    $stmtU0 = $conn->prepare("
        SELECT u.point, u.user_id
        FROM users u
        JOIN login_history l ON u.user_id = l.user_id
        WHERE l.loginkey = ? AND u.status > 0 AND u.status < 999
        ORDER BY l.create_at DESC
        LIMIT 1
    ");
    $stmtU0->bind_param("s", $loginkey);
    $stmtU0->execute();
    $resU0 = $stmtU0->get_result();
    if ($rowU0 = $resU0->fetch_assoc()) {
        $point_for_use = $rowU0['point'];
        $user_id_for_use = $rowU0['user_id'];
    }
    $stmtU0->close();

    // PDF読取のページ数を usede から取得
    $pages_for_insert = 0;
    $stmtPg = $conn->prepare("
        SELECT pages_number FROM usede
        WHERE ai_case_id = ? AND p0 LIKE 'PDF読取%'
        ORDER BY usede_id DESC LIMIT 1
    ");
    $stmtPg->bind_param("i", $ai_case_id);
    $stmtPg->execute();
    $resPg = $stmtPg->get_result();
    if ($rowPg = $resPg->fetch_assoc()) {
        $pages_for_insert = (int)$rowPg['pages_number'];
    }
    $stmtPg->close();

    $use_point_local = $point3;

    // usede に追加
    if ($use_point_local > 0 && $user_id_for_use) {
        $ip_p   = $_SERVER['REMOTE_ADDR'] ?? '';
        $dev_p  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $file_p = '';
        $stmtU = $conn->prepare("
            INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'AI診断', ?)
        ");
        $stmtU->bind_param("iisissii", $user_id_for_use, $use_point_local, $file_p, $pages_for_insert, $ip_p, $dev_p, $ai_case_id, $point_for_use);
        $stmtU->execute();
        $stmtU->close();
    }

    // report_generation_start を 0 に戻す（重複課金を防止）
    $stmtR = $conn->prepare("UPDATE ai_case SET report_generation_start = 0 WHERE ai_case_id = ?");
    $stmtR->bind_param("i", $ai_case_id);
    $stmtR->execute();
    $stmtR->close();
}

// 出力
echo json_encode([
    "status" => "success",
    "ai_case_id" => $ai_case_id,
	"report_data" => $report_data,
    "message" => "AI診断のデータを投入しました"
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);