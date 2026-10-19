<?php
/**
 * bulk_upload_review.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 確認画面に出すデータを返す。1ファイル1行の一覧で確認する。
 *
 * 受信 (GET または POST):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *
 * 返却:
 *   { status:"success", batch_id, batch_status,
 *     summary:{ total, ok, warn, ng, error },
 *     files:[ ... ] }
 *
 * 備考:
 *   件数が多いときは check_result で絞り込める。
 *   例: only=warn → 要確認のものだけ返す（C案的な使い方ができる）
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$loginkey = $_REQUEST['loginkey'] ?? null;
$batch_id = $_REQUEST['batch_id'] ?? null;
$only     = strtolower((string)($_REQUEST['only'] ?? ''));   // '', 'warn', 'ng'

if (empty($batch_id)) { bulk_error('batch_id が指定されていません。'); }

$user_id = null;
if ($damy_flag !== "OK") {
    if (empty($loginkey)) { bulk_error('loginkey が指定されていません。', 401); }
    $user_id = get_user_id_for_now($loginkey);
    if ($user_id === null) { bulk_error('異常アクセス@' . $loginkey, 401); }
}

$company_id = 0;
if ($user_id !== null) {
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE user_id = ?");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $company_id = (int)($stmt->get_result()->fetch_assoc()['company_id'] ?? 0);
    $stmt->close();
}

$batch = bulk_fetch_batch($conn, $batch_id, $company_id);

// ── 明細 ──
$sql = "SELECT seq, original_name, gcs_path, size_bytes, status, posting_period,
               detected_labels, detected_years, detect_reason,
               detected_end_date, confirmed_end_date, read_end_date, end_date_check,
               check_result, check_code, check_message, ai_case_id, error_message
          FROM bulk_batch_files WHERE batch_id = ?";
if ($only === 'warn')     { $sql .= " AND (check_result <> 'OK' OR status = '" . BULK_F_ERROR . "'"
                                   . " OR end_date_check = '" . BULK_EDC_MISMATCH . "')"; }
elseif ($only === 'ng')   { $sql .= " AND (check_result = 'NG'  OR status = '" . BULK_F_ERROR . "')"; }
$sql .= " ORDER BY seq";

$stmt = $conn->prepare($sql);
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$res = $stmt->get_result();

$files = [];
while ($r = $res->fetch_assoc()) {
    $row = [
        'seq'             => (int)$r['seq'],
        'name'            => $r['original_name'],
        'size'            => $r['size_bytes'] !== null ? (int)$r['size_bytes'] : null,
        'status'          => $r['status'],
        'posting_period'  => $r['posting_period'] !== '' ? $r['posting_period'] : null,
        'detected_labels' => $r['detected_labels'] ? explode(',', $r['detected_labels']) : [],
        'detected_years'  => $r['detected_years']  ? explode(',', $r['detected_years'])  : [],
        'detect_reason'   => $r['detect_reason'],
        // 決算日（Y-m-d）。detected=期判定AI / confirmed=担当者が承認時に確定 / read=読取AI
        'detected_end_date'  => $r['detected_end_date'],
        'confirmed_end_date' => $r['confirmed_end_date'],
        'read_end_date'      => $r['read_end_date'],
        // 読取後のみ。USER=担当者が変更し、その決算日を案件に採用
        //            MATCH / MISMATCH / SKIP=読取の決算日を採用（期判定との一致・食い違い・比較不可）
        'end_date_check'     => $r['end_date_check'],
        'check_result'    => $r['check_result'],
        'check_code'      => $r['check_code'] !== '' ? $r['check_code'] : null,
        'check_message'   => $r['check_message'] !== '' ? $r['check_message'] : null,
        'ai_case_id'      => $r['ai_case_id'] !== null ? (int)$r['ai_case_id'] : null,
        'error_message'   => $r['error_message'],
        // 承認時にそのまま送り返してもらう既定値。画面で変更可能。
        'suggested'       => [
            'posting_period' => $r['posting_period'] !== '' ? $r['posting_period'] : null,
            'end_date'       => $r['confirmed_end_date'] ?? $r['detected_end_date'],
            'include'        => ($r['status'] !== BULK_F_ERROR),
        ],
    ];
    $files[] = $row;
}
$stmt->close();

// ── サマリ（件数が多いときに全件見なくて済むようにする）──
$stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        SUM(check_result = 'OK')   AS ok,
        SUM(check_result = 'WARN') AS warn,
        SUM(check_result = 'NG')   AS ng,
        SUM(status = '" . BULK_F_ERROR . "') AS err,
        SUM(end_date_check = '" . BULK_EDC_MISMATCH . "') AS edc_ng
     FROM bulk_batch_files WHERE batch_id = ?"
);
$stmt->bind_param('s', $batch_id);
$stmt->execute();
$s = $stmt->get_result()->fetch_assoc();
$stmt->close();

bulk_json([
    'status'       => 'success',
    'batch_id'     => $batch_id,
    'batch_status' => $batch['status'],
    'summary'      => [
        'total' => (int)$s['total'],
        'ok'    => (int)$s['ok'],
        'warn'  => (int)$s['warn'],
        'ng'    => (int)$s['ng'],
        'error' => (int)$s['err'],
        'end_date_mismatch' => (int)$s['edc_ng'],   // 読取後、決算日が食い違った件数
    ],
    'files' => $files,
]);
