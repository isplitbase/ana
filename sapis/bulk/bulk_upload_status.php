<?php
/**
 * bulk_upload_status.php
 * ─────────────────────────────────────────────────────────────────────────────
 * バッチの進捗を返す。画面から5秒間隔で呼ばれる想定。
 *
 * 受信 (GET または POST):
 *   loginkey : string 必須
 *   batch_id : string 必須
 *   detail   : "1" を指定するとファイル明細も返す（既定は件数のみ）
 *
 * 返却:
 *   { status:"success", batch_id, batch_status, mode,
 *     total_files, done_files, error_files, progress_percent, message,
 *     files:[ {seq, name, status, posting_period, detected_labels,
 *              detected_years, check_result, check_message, ai_case_id} ] }
 *
 * 備考:
 *   ポーリングで頻繁に呼ばれるため、既定では明細を返さず件数だけにしている。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$loginkey = $_REQUEST['loginkey'] ?? null;
$batch_id = $_REQUEST['batch_id'] ?? null;
$detail   = (string)($_REQUEST['detail'] ?? '0') === '1';

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
