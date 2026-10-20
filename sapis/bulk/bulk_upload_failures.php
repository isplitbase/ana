<?php
/**
 * bulk_upload_failures.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 最近の一括アップロードで、案件にできなかったファイルの一覧を返す。
 *
 * 承認せず（no_review）で失敗したファイルは案件が作られないため、案件一覧には出ない。
 * 画面が一覧に戻った後や、画面を読み直してバッチIDが分からなくなった後でも
 * 失敗に気づけるよう、バッチIDなしで取れるようにしている。
 * 案件一覧の画面を開いたときに呼び、件数があれば知らせる、といった使い方を想定。
 *
 * 受信 (GET または POST):
 *   loginkey : string 必須
 *   days     : int    省略時 7（1〜30）。この日数以内に作られたバッチが対象
 *   batch_id : string 省略可。指定するとそのバッチだけ
 *
 * 返却:
 *   { status:"success", days, count,
 *     files:[ { batch_id, no_review, seq, name, error_message, failed_at } ] }
 *     新しい順。同じ会社のユーザーが入れたものを返す（案件一覧と同じ範囲）。
 *
 * 備考:
 *   アップロードの時点で受け付けなかったファイル（PDF以外・サイズ超過など）は
 *   バッチに登録されないため、ここには出ない。それは bulk_upload_add.php の応答の
 *   skipped[] で、送ったその場で返している。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$loginkey = $_REQUEST['loginkey'] ?? null;
$batch_id = $_REQUEST['batch_id'] ?? null;
$days     = (int)($_REQUEST['days'] ?? 7);
$days     = max(1, min(30, $days ?: 7));

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

$sql = "SELECT b.batch_id, b.mode, f.seq, f.original_name, f.error_message, f.update_at
          FROM bulk_batch_files f
          JOIN bulk_batches b ON b.batch_id = f.batch_id
         WHERE b.company_id = ?
           AND f.status = '" . BULK_F_ERROR . "'
           AND b.create_at >= DATE_SUB(now(), INTERVAL ? DAY)";
$types = 'ii';
$args  = [$company_id, $days];
if (!empty($batch_id)) {
    $sql .= " AND b.batch_id = ?";
    $types .= 's';
    $args[] = (string)$batch_id;
}
$sql .= " ORDER BY f.update_at DESC, b.batch_id DESC, f.seq";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$args);
$stmt->execute();
$res = $stmt->get_result();

$files = [];
while ($r = $res->fetch_assoc()) {
    $files[] = [
        'batch_id'      => $r['batch_id'],
        'no_review'     => ($r['mode'] === BULK_MODE_DIRECT),
        'seq'           => (int)$r['seq'],
        'name'          => $r['original_name'],
        'error_message' => $r['error_message'],
        'failed_at'     => $r['update_at'],
    ];
}
$stmt->close();

bulk_json([
    'status' => 'success',
    'days'   => $days,
    'count'  => count($files),
    'files'  => $files,
]);
