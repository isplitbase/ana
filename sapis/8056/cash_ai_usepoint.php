<?php
/**
 * CF分析（cash_ai.php）の AI 実行が成功した後に、
 * 利用履歴登録（usede INSERT）のみを行う専用エンドポイント。
 * （users.point の減算は廃止。usede 追加だけ実施する）
 *
 * リクエスト（POST, JSON）:
 *   {
 *     "loginkey":   "...",
 *     "ai_case_id": 12345
 *   }
 *
 * 動作:
 *   1) ログインチェック（cash_ai.php と同じパターン）
 *   2) users から現在のポイント残高を取得（usede.p1 に記録するため）
 *   3) ai_case.cash_ai_start フラグを行ロック付きで確認
 *      - 1 でなければ「ポイントを消費できませんでした」と返してロールバック
 *   4) フラグを 0 に降ろし、usede に登録（users.point の更新は行わない）
 */

include "./common.php";
global $conn;
global $damy_flag;
global $cfpoint;

$input = file_get_contents('php://input');
$data  = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON: ' . json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

$loginkey   = $data['loginkey']   ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;

// ----------------------------------------------------
// ログインチェック（cash_ai.php と同じパターン）
// ----------------------------------------------------
if ($damy_flag != "OK") {
    $user_id = null;
    if (isset($loginkey)) {
        $user_id = get_user_id_for_now($loginkey);
        if ($user_id == null) {
            echo json_encode([
                "status"  => "error",
                "message" => "異常アクセス"
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!isset($ai_case_id) || $ai_case_id == null) {
    echo json_encode([
        "status"  => "error",
        "message" => "ai_case_id がありません"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------
// users.point と users.user_id を最新取得（プロジェクトの user 用カラム = user_id）
// ----------------------------------------------------
$point   = null;
$user_id = null;
$stmt = $conn->prepare("
    SELECT u.point, u.user_id
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
    $point   = intval($row['point']);
    $user_id = intval($row['user_id']);
}

if ($user_id === null) {
    echo json_encode([
        "status"  => "error",
        "message" => "ユーザー情報が取得できません"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ----------------------------------------------------
// 消費ポイント計算（CF分析は定数 $cfpoint）
// ※ users.point は更新しない（残高チェックは廃止）
// ----------------------------------------------------
$use_point  = intval($cfpoint);
$ip         = $_SERVER['REMOTE_ADDR']     ?? '';
$device     = $_SERVER['HTTP_USER_AGENT'] ?? '';
$filename   = '';
$totalPages = 0;

// ----------------------------------------------------
// 1. ai_case.cash_ai_start フラグ判定（FOR UPDATE で行ロック）
//    - 0/null の場合: 課金対象外（既に課金済 or RETRY モード or 未実行）→ 特別レスポンス
//    - 1   の場合: フラグを 0 に落としつつ、usede 登録を行う
// すべて 1 トランザクションで処理する
// ----------------------------------------------------
$conn->begin_transaction();
try {
    // フラグを行ロック付きで取得
    $stmt = $conn->prepare("SELECT cash_ai_start FROM ai_case WHERE ai_case_id = ? FOR UPDATE");
    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();
    $rowFlag = $stmt->get_result()->fetch_assoc();

    if (empty($rowFlag) || intval($rowFlag['cash_ai_start']) !== 1) {
        // フラグ false（または該当 ai_case 無し）→ 課金しない・履歴登録しない
        $conn->rollback();
        echo json_encode([
            "status"          => "success",
            "message"         => "ポイントを消費できませんでした",
            "ai_case_id"      => $ai_case_id,
            "used_point"      => $use_point,
            "remaining_point" => $point
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // フラグ true → まずフラグを 0 に降ろす（同一トランザクション内）
    $stmt = $conn->prepare("UPDATE ai_case SET cash_ai_start = 0 WHERE ai_case_id = ?");
    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();

    // usede 登録（users.point の更新は行わない）
    $stmt = $conn->prepare("
        INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
        VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'CF分析', ?)
    ");
    $stmt->bind_param("iisissii", $user_id, $use_point, $filename, $totalPages, $ip, $device, $ai_case_id, $point);
    $stmt->execute();

    $conn->commit();

    echo json_encode([
        "status"          => "success",
        "message"         => "ポイント利用履歴を記録しました",
        "ai_case_id"      => $ai_case_id,
        "used_point"      => $use_point,
        "remaining_point" => $point
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $conn->rollback();
    error_log("cash_ai_usepoint.php failed: " . $e->getMessage());
    echo json_encode([
        "status"  => "error",
        "message" => "ポイント消費処理に失敗しました",
        "detail"  => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
