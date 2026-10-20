<?php
/**
 * bulk_upload_status.php
 * ─────────────────────────────────────────────────────────────────────────────
 * バッチの進捗を返す。画面から5秒間隔で呼ばれる想定。
 *
 * 受信 (POST のみ。application/json または form):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *   detail   : true（または 1）を指定するとファイル明細も返す（既定は件数のみ）
 *
 * 返却:
 *   { status:"success", batch_id, batch_status, no_review,
 *     total_files, done_files, error_files, progress_percent, message,
 *     files:[ {seq, name, status, ai_case_id, error_message,
 *              posting_period, detected_labels, detected_years, check_result, check_message} ] }
 *
 * 承認せず（no_review=true）のバッチでの見方:
 *   batch_status が DONE になれば全件の処理が終わっている。
 *   files[].status  DONE  → 登録済み（ai_case_id が案件ID）
 *                   ERROR → 失敗（error_message が理由）。案件は作られていない
 *                   RECEIVED / DETECTING → 待ち / 登録中
 *
 * 備考:
 *   ポーリングで頻繁に呼ばれるため、既定では明細を返さず件数だけにしている。
 *   バッチIDが分からない場合（画面を読み直した後など）の失敗の検知は bulk_upload_failures.php。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$body = bulk_read_request();   // POST 専用（GET は 405）
$loginkey = $body['loginkey'] ?? null;
$batch_id = $body['batch_id'] ?? null;
$detail   = filter_var($body['detail'] ?? false, FILTER_VALIDATE_BOOLEAN);   // true / 1 / "1" を受け付ける

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

$total = (int)$batch['total_files'];
$done  = (int)$batch['done_files'];
$percent = $total > 0 ? (int)floor($done * 100 / $total) : 0;

$out = [
    'status'           => 'success',
    'batch_id'         => $batch_id,
    'batch_status'     => $batch['status'],
    'no_review'        => ($batch['mode'] === BULK_MODE_DIRECT),
    'total_files'      => $total,
    'done_files'       => $done,
    'error_files'      => (int)$batch['error_files'],
    'progress_percent' => $percent,
    'message'          => $batch['message'],
    'update_at'        => $batch['update_at'],
];

if ($detail) {
    $stmt = $conn->prepare(
        "SELECT seq, original_name, status, posting_period, detected_labels,
                detected_years, check_result, check_code, check_message,
                ai_case_id, error_message
           FROM bulk_batch_files WHERE batch_id = ? ORDER BY seq"
    );
    $stmt->bind_param('s', $batch_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $files = [];
    while ($r = $res->fetch_assoc()) {
        $files[] = [
            'seq'             => (int)$r['seq'],
            'name'            => $r['original_name'],
            'status'          => $r['status'],
            'posting_period'  => $r['posting_period'],
            'detected_labels' => $r['detected_labels'],
            'detected_years'  => $r['detected_years'],
            'check_result'    => $r['check_result'],
            'check_code'      => $r['check_code'],
            'check_message'   => $r['check_message'],
            'ai_case_id'      => $r['ai_case_id'] !== null ? (int)$r['ai_case_id'] : null,
            'error_message'   => $r['error_message'],
        ];
    }
    $stmt->close();
    $out['files'] = $files;
}

bulk_json($out);
