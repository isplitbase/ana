<?php
/**
 * bulk_upload_pending.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 自分が「確認してから登録」で送り、まだ終わっていないバッチの一覧を返す。
 *
 * 確認待ち（REVIEW）のバッチは、承認するまで案件一覧に出ない。
 * 画面を読み直したりログインし直したりするとバッチIDが分からなくなり、
 * 承認できないまま残ってしまうため、ポップアップを開いたときにこれで取り直す。
 * ファイル一覧の「まとめてアップロード」ボタンに承認待ちの件数を出すのにも使う。
 *
 * 受信 (POST のみ。application/json または form):
 *   loginkey : string 必須
 *   days     : int    省略時 30（1〜90）。この日数以内に作られたバッチが対象
 *
 * 返却:
 *   { status:"success", count, review_count,
 *     batches:[ { batch_id, batch_status, total_files, done_files, error_files,
 *                 message, create_at } ] }
 *     新しい順。review_count は確認待ち（REVIEW）の件数。
 *
 * 備考:
 *   対象は自分（loginkey のユーザー）が送ったものだけ。同じ会社の他の人の確認待ちは、
 *   その人が内容を確認して承認するものなので出さない。
 *   受付中（OPEN）は送信が途中で止まったもので、続きから承認できないため出さない。
 *   確認せずに登録（no_review）のバッチは承認が要らないため出さない。
 */

include "./common.php";
require_once __DIR__ . '/bulk_common.php';

global $conn, $damy_flag;

$body = bulk_read_request();   // POST 専用（GET は 405）
$loginkey = $body['loginkey'] ?? null;
$days     = (int)($body['days'] ?? 30);
$days     = max(1, min(90, $days ?: 30));

if ($damy_flag === "OK") {
    // ダミー運用ではユーザーが決まらないため、空で返す
    bulk_json(['status' => 'success', 'count' => 0, 'review_count' => 0, 'batches' => []]);
}

if (empty($loginkey)) { bulk_error('loginkey が指定されていません。', 401); }
$user_id = get_user_id_for_now($loginkey);
if ($user_id === null) { bulk_error('異常アクセス@' . $loginkey, 401); }

$stmt = $conn->prepare(
    "SELECT batch_id, status, total_files, done_files, error_files, message, create_at
       FROM bulk_batches
      WHERE user_id = ?
        AND mode = ?
        AND status IN (?, ?, ?, ?, ?)
        AND create_at >= DATE_SUB(now(), INTERVAL ? DAY)
      ORDER BY create_at DESC, batch_id DESC"
);
$mode = BULK_MODE_FILE;
$st = [BULK_ST_QUEUED, BULK_ST_DETECTING, BULK_ST_REVIEW, BULK_ST_APPROVED, BULK_ST_ANALYZING];
$stmt->bind_param('issssssi', $user_id, $mode, $st[0], $st[1], $st[2], $st[3], $st[4], $days);
$stmt->execute();
$res = $stmt->get_result();

$batches = [];
$review = 0;
while ($r = $res->fetch_assoc()) {
    if ($r['status'] === BULK_ST_REVIEW) { $review++; }
    $batches[] = [
        'batch_id'     => $r['batch_id'],
        'batch_status' => $r['status'],
        'total_files'  => (int)$r['total_files'],
        'done_files'   => (int)$r['done_files'],
        'error_files'  => (int)$r['error_files'],
        'message'      => $r['message'],
        'create_at'    => $r['create_at'],
    ];
}
$stmt->close();

bulk_json([
    'status'       => 'success',
    'count'        => count($batches),
    'review_count' => $review,
    'batches'      => $batches,
]);
