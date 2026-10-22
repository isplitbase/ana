<?php
/**
 * get_tenant_usage_history.php
 *
 * テナント操作履歴取得API
 * - 自テナント内のユーザーの usede（ポイント利用履歴）を返す
 * - auth >= 200（Admin/MasterAdmin）のみ利用可
 *
 * POST パラメータ（JSON）:
 *   loginkey         : 必須 / ログイン中のキー
 *   date_from        : 任意 / 開始日 YYYY-MM-DD
 *   date_to          : 任意 / 終了日 YYYY-MM-DD
 *   feature          : 任意 / 機能フィルタ (cashflow_analysis / ai_report / financial_analysis / data_read / analysis_check)
 *   department_id    : 任意 / 部門ID
 *   user_id          : 任意 / ユーザーID（テナント内のみ）
 *   execution_status : 任意 / success / failed
 *   page             : 任意 / ページ番号（デフォルト1）
 *   per_page         : 任意 / 件数（デフォルト20、最大100）
 */

require_once './common.php';

header('Content-Type: application/json; charset=UTF-8');

// ----------------------------------------------------------------
// ユーティリティ
// ----------------------------------------------------------------
function respond_history($status, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['status' => $status], $data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * usede.p0（日本語）→ feature（英語キー）変換
 */
function p0_to_feature(string $p0): string {
    if (strpos($p0, 'PDF読取') !== false) {
        return 'data_read';
    }
    switch ($p0) {
        case 'CF分析':     return 'cashflow_analysis';
        case 'AI診断':     return 'ai_report';
        case '財務分析':   return 'financial_analysis';
        case '解析結果確認': return 'analysis_check';
        case 'バッチ削除':   return 'batch_delete';
        default:           return $p0;
    }
}

/**
 * feature（英語）→ p0 LIKE 条件の配列を返す
 * （SQL の WHERE 句生成に使用）
 */
function feature_to_p0_conditions(string $feature): array {
    switch ($feature) {
        case 'data_read':
            // 'PDF読取', 'PDF読取(1期分)', 'PDF読取(2期分)', 'PDF読取(3期分)' すべてマッチ
            return ['LIKE', 'PDF読取%'];
        case 'cashflow_analysis':
            return ['=', 'CF分析'];
        case 'ai_report':
            return ['=', 'AI診断'];
        case 'financial_analysis':
            return ['=', '財務分析'];
        case 'analysis_check':
            return ['=', '解析結果確認'];
        case 'batch_delete':
            return ['=', 'バッチ削除'];
        default:
            return [];
    }
}

// ----------------------------------------------------------------
// リクエスト取得
// ----------------------------------------------------------------
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$loginkey         = trim((string)($body['loginkey']         ?? $_POST['loginkey']         ?? ''));
$date_from        = trim((string)($body['date_from']        ?? $_POST['date_from']        ?? ''));
$date_to          = trim((string)($body['date_to']          ?? $_POST['date_to']          ?? ''));
$feature_filter   = trim((string)($body['feature']          ?? $_POST['feature']          ?? ''));
$app_key_filter   = trim((string)($body['app_key']           ?? $_POST['app_key']           ?? ''));
$department_id    = isset($body['department_id'])    ? (int)$body['department_id']    : null;
$user_id_filter   = isset($body['user_id'])          ? (int)$body['user_id']          : null;
$exec_status      = trim((string)($body['execution_status'] ?? $_POST['execution_status'] ?? ''));
$page             = max(1, (int)($body['page']     ?? $_POST['page']     ?? 1));
$per_page         = (int)($body['per_page'] ?? $_POST['per_page'] ?? 20);
if ($per_page < 1)   $per_page = 20;
if ($per_page > 100) $per_page = 100;

// ----------------------------------------------------------------
// セキュリティ：usede_id はリクエストパラメータとして受け付けない
// 特定レコードへの直接アクセスを防ぐため明示的に拒否する
// ----------------------------------------------------------------
if (isset($body['usede_id']) || isset($_POST['usede_id'])) {
    respond_history('error', ['message' => 'usede_id は指定できません'], 400);
}

// ----------------------------------------------------------------
// 認証
// ----------------------------------------------------------------
if ($loginkey === '') {
    respond_history('error', ['message' => 'loginkeyは必須です'], 400);
}

$user_id_me = get_user_id_for_now($loginkey);
if ($user_id_me === null) {
    respond_history('error', ['message' => '認証セッションが無効です'], 403);
}

// ログインユーザーの auth / company_id を取得
$stmt = $conn->prepare("
    SELECT u.auth, u.company_id
    FROM users u
    WHERE u.user_id = ?
      AND u.status > 0 AND u.status < 999
    LIMIT 1
");
$stmt->bind_param('i', $user_id_me);
$stmt->execute();
$me = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$me) {
    respond_history('error', ['message' => 'ユーザーが見つかりません'], 404);
}
if ((int)($me['auth'] ?? 0) < 200) {
    respond_history('error', ['message' => 'この操作には管理者権限が必要です'], 403);
}

$company_id = (int)($me['company_id'] ?? -1);
if ($company_id < 0) {
    respond_history('error', ['message' => 'company_idが未設定のため処理できません'], 400);
}

// ----------------------------------------------------------------
// 入力バリデーション
// ----------------------------------------------------------------
function is_valid_date(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $day] = explode('-', $d);
    return checkdate((int)$m, (int)$day, (int)$y);
}

if ($date_from !== '' && !is_valid_date($date_from)) {
    respond_history('error', ['message' => 'date_from の形式が不正です（YYYY-MM-DD）'], 400);
}
if ($date_to !== '' && !is_valid_date($date_to)) {
    respond_history('error', ['message' => 'date_to の形式が不正です（YYYY-MM-DD）'], 400);
}
if ($date_from !== '' && $date_to !== '' && $date_from > $date_to) {
    respond_history('error', ['message' => 'date_from は date_to 以前の日付を指定してください'], 400);
}

$valid_features = ['cashflow_analysis', 'ai_report', 'financial_analysis', 'data_read', 'analysis_check'];
if ($feature_filter !== '' && !in_array($feature_filter, $valid_features, true)) {
    respond_history('error', ['message' => 'feature の値が不正です'], 400);
}

$valid_exec_statuses = ['success', 'failed'];
if ($exec_status !== '' && !in_array($exec_status, $valid_exec_statuses, true)) {
    respond_history('error', ['message' => 'execution_status は success または failed を指定してください'], 400);
}

$valid_app_keys = ['analygent'];
if ($app_key_filter !== '' && !in_array($app_key_filter, $valid_app_keys, true)) {
    respond_history('error', ['message' => 'app_key の値が不正です'], 400);
}

// user_id_filter がテナント内かチェック（指定があれば）
if ($user_id_filter !== null && $user_id_filter > 0) {
    $stmtChk = $conn->prepare("SELECT 1 FROM users WHERE user_id = ? AND company_id = ? LIMIT 1");
    $stmtChk->bind_param('ii', $user_id_filter, $company_id);
    $stmtChk->execute();
    $chkResult = $stmtChk->get_result()->fetch_row();
    $stmtChk->close();
    if (!$chkResult) {
        respond_history('error', ['message' => '指定された user_id はテナント内に存在しません'], 400);
    }
}

// ----------------------------------------------------------------
// クエリ組み立て
// ----------------------------------------------------------------
// 基底 WHERE 条件（テナントスコープ）
$where  = " WHERE u.company_id = ?";
$params = [$company_id];
$types  = "i";

// 日付フィルタ（DATE()禁止 → 文字列範囲比較）
if ($date_from !== '') {
    $where   .= " AND ud.create_at >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= "s";
}
if ($date_to !== '') {
    // date_to の翌日 00:00:00 未満 = date_to 23:59:59 まで
    $dt_end   = date('Y-m-d', strtotime($date_to . ' +1 day'));
    $where   .= " AND ud.create_at < ?";
    $params[] = $dt_end . ' 00:00:00';
    $types   .= "s";
}

// feature フィルタ
if ($feature_filter !== '') {
    $cond = feature_to_p0_conditions($feature_filter);
    if (!empty($cond)) {
        if ($cond[0] === 'LIKE') {
            $where   .= " AND ud.p0 LIKE ?";
            $params[] = $cond[1];
            $types   .= "s";
        } else {
            $where   .= " AND ud.p0 = ?";
            $params[] = $cond[1];
            $types   .= "s";
        }
    }
}

// 部門フィルタ
if ($department_id !== null && $department_id > 0) {
    $where   .= " AND u.bumon_id = ?";
    $params[] = $department_id;
    $types   .= "i";
}

// ユーザーフィルタ
if ($user_id_filter !== null && $user_id_filter > 0) {
    $where   .= " AND ud.user_id = ?";
    $params[] = $user_id_filter;
    $types   .= "i";
}

// execution_status フィルタ
if ($exec_status !== '') {
    $where   .= " AND ud.execution_status = ?";
    $params[] = $exec_status;
    $types   .= "s";
}

// app_key フィルタ（現状 analygent 固定のため実質スルー。将来の拡張用）
// app_key = 'analygent' 以外はバリデーションで弾いているため追加条件不要

// ----------------------------------------------------------------
// 件数 & サマリー取得
// ----------------------------------------------------------------
$sqlCount = "
    SELECT
        COUNT(*)                                                            AS total,
        SUM(CASE WHEN ud.execution_status = 'success' THEN ud.use_point ELSE 0 END) AS total_charge_amount,
        SUM(CASE WHEN ud.execution_status = 'success' THEN 1 ELSE 0 END)  AS charged_count,
        SUM(CASE WHEN ud.execution_status != 'success' THEN 1 ELSE 0 END) AS uncharged_count
    FROM usede ud
    INNER JOIN users u ON u.user_id = ud.user_id
    {$where}
";

$stmtCount = $conn->prepare($sqlCount);
$stmtCount->bind_param($types, ...$params);
$stmtCount->execute();
$rowCount = $stmtCount->get_result()->fetch_assoc();
$stmtCount->close();

$total              = (int)($rowCount['total']              ?? 0);
$total_charge_amount = (int)($rowCount['total_charge_amount'] ?? 0);
$charged_count      = (int)($rowCount['charged_count']      ?? 0);
$uncharged_count    = (int)($rowCount['uncharged_count']    ?? 0);
$total_pages        = $total > 0 ? (int)ceil($total / $per_page) : 1;
$offset             = ($page - 1) * $per_page;

// ----------------------------------------------------------------
// データ取得
// ----------------------------------------------------------------
$sqlData = "
    SELECT
        ud.usede_id,
        ud.user_id,
        u.username,
        u.bumon_id,
        b.bumon_name,
        ud.ai_case_id,
        ud.use_point,
        ud.pages_number,
        ud.p0,
        ud.execution_status,
        ud.create_at
    FROM usede ud
    INNER JOIN users u ON u.user_id = ud.user_id
    LEFT JOIN bumon b
        ON b.bumon_id   = u.bumon_id
       AND b.company_id = u.company_id
    {$where}
    ORDER BY ud.create_at DESC, ud.usede_id DESC
    LIMIT ? OFFSET ?
";

$paramsData   = array_merge($params, [$per_page, $offset]);
$typesData    = $types . "ii";

$stmtData = $conn->prepare($sqlData);
$stmtData->bind_param($typesData, ...$paramsData);
$stmtData->execute();
$result   = $stmtData->get_result();
$stmtData->close();

// ----------------------------------------------------------------
// レスポンス組み立て
// ----------------------------------------------------------------
$items = [];
while ($row = $result->fetch_assoc()) {
    $feat    = p0_to_feature((string)($row['p0'] ?? ''));
    $is_ok   = ((string)($row['execution_status'] ?? 'success')) === 'success';

    $items[] = [
        'usede_id'         => (int)$row['usede_id'],
        'user_id'          => (int)$row['user_id'],
        'username'         => (string)($row['username']    ?? ''),
        'department_id'    => $row['bumon_id'] !== null ? (int)$row['bumon_id'] : null,
        'department_name'  => $row['bumon_name'] !== null ? (string)$row['bumon_name'] : null,
        'ai_case_id'       => $row['ai_case_id'] !== null ? (int)$row['ai_case_id'] : null,
        'app_key'          => 'analygent',
        'app_label'        => 'Analygent',
        'feature'          => $feat,
        'feature_label'    => (string)($row['p0'] ?? ''),
        'pages_number'     => $row['pages_number'] !== null ? (int)$row['pages_number'] : null,
        'use_point'        => (int)($row['use_point'] ?? 0),
        'charge_amount'    => $is_ok ? (int)($row['use_point'] ?? 0) : 0,
        'execution_status' => (string)($row['execution_status'] ?? 'success'),
        'used_at'          => (string)($row['create_at'] ?? ''),
    ];
}

respond_history('success', [
    'pagination' => [
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => $total_pages,
    ],
    'summary' => [
        'total_charge_amount' => $total_charge_amount,
        'charged_count'       => $charged_count,
        'uncharged_count'     => $uncharged_count,
    ],
    'items' => $items,
]);
