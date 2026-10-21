<?php
/**
 * _case_access_helper.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 案件（ai_case）を扱う API の共通の認証・権限チェック。
 *
 * - loginkey が無い／無効なら、エラーを返して終了する。
 *   （以前は `if (isset($loginkey)) { ... }` の形で、loginkey を付けずに呼ぶと
 *     ログイン確認そのものを飛ばしていた）
 * - ai_case_id を受け取る場合は、案件の作成者（ai_case.user_id）の会社（users.company_id）が
 *   ログイン中ユーザーの会社と同じでなければ、エラーを返して終了する。
 *   閲覧・実行は同じ会社なら可（delete_cases.php / set_case_name.php の同じ会社チェック、
 *   一覧 _ai_case_list_helper.php の表示範囲＝同じ会社の案件 に合わせる）。
 * - damy_flag="OK"（テスト用）の場合だけ、従来どおり確認せずに通す。
 *
 * 使い方（common.php を読み込んだ後で）:
 *   require_once __DIR__ . "/_case_access_helper.php";
 *   $user_id = ana_require_case_access($loginkey, $ai_case_id);         // ai_case_id 必須
 *   $user_id = ana_require_case_access($loginkey, $ai_case_id, false);  // ai_case_id は指定時のみ確認
 *   ana_require_case_in_company($user_id, $ai_case_id);                 // ログイン確認は済んでいる場合
 *
 * エラー時のレスポンスは既存 API と同じ {"status":"error","message":"異常アクセス"}。
 * HTTP ステータスは呼び出し側に合わせて指定する（既定 200）。
 */

/**
 * エラーを返して終了する。
 */
function ana_case_access_deny(int $http_code, string $message = "異常アクセス"): void
{
    http_response_code($http_code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        "status"  => "error",
        "message" => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * ai_case_id を正の整数に直す。数字以外（配列・"1 OR 1=1" 等）や 0 以下は null。
 */
function ana_normalize_case_id($ai_case_id): ?int
{
    if (is_int($ai_case_id)) {
        return $ai_case_id > 0 ? $ai_case_id : null;
    }
    if (is_string($ai_case_id) && ctype_digit($ai_case_id)) {
        $id = (int)$ai_case_id;
        return $id > 0 ? $id : null;
    }
    return null;
}

/**
 * ログイン必須。loginkey が無い／無効ならエラーで終了する。
 *
 * @return int|null  ログイン中の user_id（damy_flag="OK" のときは null）
 */
function ana_require_login($loginkey, int $http_code = 200): ?int
{
    global $damy_flag;
    if ($damy_flag == "OK") {
        return null;
    }

    if (!is_string($loginkey) || trim($loginkey) === '') {
        ana_case_access_deny($http_code);
    }

    // get_user_id_for_now() は common.php。billing_status が有効でなければ 402 で終了する。
    $user_id = get_user_id_for_now($loginkey);
    if ($user_id === null) {
        ana_case_access_deny($http_code);
    }
    return (int)$user_id;
}

/**
 * 案件の作成者がログイン中ユーザーと同じ会社か確認する。違えばエラーで終了する。
 *
 * @param int|null $user_id      ana_require_login() 等で確認済みの user_id
 * @param mixed    $ai_case_id   リクエストで受け取った値
 * @param bool     $required     true: ai_case_id が無ければエラー / false: 無ければ確認しない
 */
function ana_require_case_in_company($user_id, $ai_case_id, bool $required = true, int $http_code = 200): void
{
    global $conn, $damy_flag;
    if ($damy_flag == "OK") {
        return;
    }

    if ($ai_case_id === null || $ai_case_id === '') {
        if ($required) {
            ana_case_access_deny($http_code);
        }
        return;
    }

    $case_id = ana_normalize_case_id($ai_case_id);
    if ($case_id === null || $user_id === null) {
        ana_case_access_deny($http_code);
    }

    $stmt = $conn->prepare("
        SELECT ac.user_id AS case_user_id,
               cu.company_id AS case_company_id,
               me.company_id AS my_company_id
        FROM ai_case ac
        INNER JOIN users cu ON cu.user_id = ac.user_id
        INNER JOIN users me ON me.user_id = ?
        WHERE ac.ai_case_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        error_log("[case_access] prepare failed: " . $conn->error);
        ana_case_access_deny(500, "サーバーエラー");
    }
    $uid = (int)$user_id;
    $stmt->bind_param("ii", $uid, $case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // 案件が無い場合も、権限が無い場合と同じ応答にする（他社の案件番号の有無を推測させない）
    $allowed = false;
    if ($row) {
        if ((int)$row['case_user_id'] === $uid) {
            $allowed = true;
        } elseif ($row['case_company_id'] !== null && $row['my_company_id'] !== null
            && (int)$row['case_company_id'] === (int)$row['my_company_id']) {
            $allowed = true;
        }
    }

    if (!$allowed) {
        $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
        error_log("[case_access] deny script={$script} user_id={$uid} ai_case_id={$case_id}"
            . ($row ? '' : ' (case not found)'));
        ana_case_access_deny($http_code);
    }
}

/**
 * ログイン必須 ＋ 案件が同じ会社のものか確認する。
 *
 * @return int|null  ログイン中の user_id（damy_flag="OK" のときは null）
 */
function ana_require_case_access($loginkey, $ai_case_id, bool $required = true, int $http_code = 200): ?int
{
    $user_id = ana_require_login($loginkey, $http_code);
    ana_require_case_in_company($user_id, $ai_case_id, $required, $http_code);
    return $user_id;
}
