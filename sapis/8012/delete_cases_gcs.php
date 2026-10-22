<?php
/**
 * delete_cases_gcs.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 削除した案件の GCS ファイルを裏で物理削除する（CLI 専用。Web からは呼べない）。
 *
 * delete_cases.php が DB を論理削除して画面に応答を返した後、nohup で起動する。
 *   php delete_cases_gcs.php <ai_case_id> [<ai_case_id> ...]
 *
 * 1案件に PDF・ページ画像・作業画像が数十〜数百あり、1つずつ消すため時間がかかる。
 * 画面からの削除の中でやると、20件以上の一括削除で1分以上応答が返らなかった（2026-10-21）。
 *
 * 安全のため、status='DELETED' の案件だけを対象にする（生きている案件のファイルは消さない）。
 * 結果は /var/log/ana/delete_gcs_<port>_YYYYMMDD.log に残す。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

chdir(__DIR__);

// composer autoload (google/cloud-storage)
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1)) { require_once $autoload1; }
else if (file_exists($autoload2)) { require_once $autoload2; }

include __DIR__ . "/common.php";
include __DIR__ . "/_delete_gcs_helper.php";
global $conn;
global $port;

set_time_limit(0);

function delete_gcs_log(string $message): void {
    $logDir = '/var/log/ana';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
    $file = $logDir . '/delete_gcs_' . ($GLOBALS['port'] ?? 'unknown') . '_' . date('Ymd') . '.log';
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    if (@file_put_contents($file, $line, FILE_APPEND) === false) {
        @file_put_contents('php://stderr', $line);
    }
}

$ids = array_values(array_filter(array_map('intval', array_slice($argv, 1)), function ($v) { return $v > 0; }));
if (!$ids) {
    delete_gcs_log('SKIP 案件IDの指定がありません');
    exit(1);
}

$stmt = $conn->prepare("SELECT full_file_Paths, img_urls, status FROM ai_case WHERE ai_case_id = ?");
$started = microtime(true);
$total_deleted = 0;
$total_errors  = 0;

foreach ($ids as $ai_case_id) {
    $stmt->bind_param("i", $ai_case_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();

    if (!$row) {
        delete_gcs_log("SKIP ai_case_id={$ai_case_id} 案件がありません");
        continue;
    }
    if ($row['status'] !== 'DELETED') {
        delete_gcs_log("SKIP ai_case_id={$ai_case_id} 削除されていません（status={$row['status']}）");
        continue;
    }

    $t0 = microtime(true);
    $result = delete_gcs_files_for_case($ai_case_id, $row['full_file_Paths'] ?? '', $row['img_urls'] ?? '');
    $n_del = count($result['deleted']);
    $n_err = count($result['errors']);
    $total_deleted += $n_del;
    $total_errors  += $n_err;

    delete_gcs_log(sprintf('DONE ai_case_id=%d deleted=%d errors=%d %.1fs', $ai_case_id, $n_del, $n_err, microtime(true) - $t0));
    foreach ($result['errors'] as $e) {
        delete_gcs_log("  ERROR ai_case_id={$ai_case_id} {$e}");
    }
}
$stmt->close();

delete_gcs_log(sprintf('END cases=%d deleted=%d errors=%d %.1fs', count($ids), $total_deleted, $total_errors, microtime(true) - $started));
