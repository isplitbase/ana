<?php
/**
 * bulk_worker.php
 * ─────────────────────────────────────────────────────────────────────────────
 * まとめてアップロードのワーカー。crontab から毎分起動する。
 *
 * 使い方:
 *   php /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_worker.php
 *   php bulk_worker.php --batch=261015-02   ← 特定バッチだけ処理（動作確認用）
 *   php bulk_worker.php --dry-run           ← 判定も解析も行わず対象だけ表示
 *
 * crontab 例（毎分）:
 *   * * * * * /usr/bin/php /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_worker.php >> /var/log/ana/bulk_worker_$(date +\%Y\%m\%d).log 2>&1
 *
 * 処理内容:
 *   QUEUED   → 期判定＋整合チェック → REVIEW（人の確認待ち）
 *   APPROVED → 案件ごとに解析を実行   → DONE
 *
 * 多重起動の防止:
 *   bulk_batches.worker_pid / locked_at でロックする。
 *   ロックが BULK_LOCK_TIMEOUT 秒より古い場合は、前回のワーカーが落ちたとみなして奪う。
 */

// Webから叩かれないようにする（cron / CLI 専用）
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("bulk_worker.php は CLI 専用です。\n");
}

chdir(__DIR__);
include __DIR__ . '/common.php';
require_once __DIR__ . '/bulk_common.php';
require_once __DIR__ . '/bulk_detect.php';

global $conn, $port;

/** 同時に処理するファイル数。pdf-converter は concurrency=1 のため上げすぎない。 */
const BULK_PARALLEL = 3;

/** ロックを奪うまでの秒数（この時間を過ぎたロックは死んだワーカーのものとみなす） */
const BULK_LOCK_TIMEOUT = 1800;

$opts     = getopt('', ['batch::', 'dry-run']);
$onlyBatch = $opts['batch'] ?? null;
$dryRun    = array_key_exists('dry-run', $opts);

// ─────────────────────────────────────────
// 対象バッチを1つ取り出してロックする
// ─────────────────────────────────────────
function bulk_claim_batch(mysqli $conn, ?string $onlyBatch): ?array {
    $pid = getmypid();

    $where = "status IN ('" . BULK_ST_QUEUED . "','" . BULK_ST_APPROVED . "')
              AND (locked_at IS NULL OR locked_at < DATE_SUB(now(), INTERVAL " . BULK_LOCK_TIMEOUT . " SECOND))";
    if ($onlyBatch !== null) {
        $where .= " AND batch_id = '" . $conn->real_escape_string($onlyBatch) . "'";
    }

    // 1行だけロックを取る。取れた行数で競合を判定する。
    $sql = "UPDATE bulk_batches
               SET worker_pid = {$pid}, locked_at = now(), update_at = now()
             WHERE {$where}
             ORDER BY create_at
             LIMIT 1";
    $conn->query($sql);
    if ($conn->affected_rows < 1) { return null; }

    $res = $conn->query("SELECT * FROM bulk_batches WHERE worker_pid = {$pid} ORDER BY locked_at DESC LIMIT 1");
    return $res ? $res->fetch_assoc() : null;
}

function bulk_release(mysqli $conn, string $batchId): void {
    bulk_update_batch($conn, $batchId, ['worker_pid' => 0]);
    $conn->query("UPDATE bulk_batches SET worker_pid = NULL, locked_at = NULL WHERE batch_id = '"
        . $conn->real_escape_string($batchId) . "'");
}

// ─────────────────────────────────────────
// 期判定フェーズ（QUEUED → REVIEW）
// ─────────────────────────────────────────
function bulk_phase_detect(mysqli $conn, array $batch, int $port, bool $dryRun): void {
    $batchId = $batch['batch_id'];
    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_DETECTING,
        'message' => '帳票の期を判定しています。',
    ]);

    $res = $conn->query("SELECT * FROM bulk_batch_files
                          WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                            AND status IN ('" . BULK_F_RECEIVED . "','" . BULK_F_DETECTING . "')
                          ORDER BY seq");
    $targets = $res->fetch_all(MYSQLI_ASSOC);
    bulk_log("DETECT START batch_id={$batchId} files=" . count($targets));

    if ($dryRun) {
        foreach ($targets as $t) { echo "  [dry-run] #{$t['seq']} {$t['original_name']}\n"; }
        return;
    }

    // 重複チェック用。既に判定済みのものも対象に含める。
    $seen = [];
    $done = $conn->query("SELECT seq, original_name, detected_years FROM bulk_batch_files
                           WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                             AND detected_years IS NOT NULL");
    while ($d = $done->fetch_assoc()) {
        $seen[$d['original_name'] . '|' . $d['detected_years']] = (int)$d['seq'];
    }

    foreach ($targets as $t) {
        $id = (int)$t['id'];
        bulk_update_file($conn, $id, ['status' => BULK_F_DETECTING]);

        $r = bulk_detect_period($t['gcs_path'], $t['original_name'], $port);

        if (!$r['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$r['error'], 0, 1000),
            ]);
            bulk_log("DETECT NG batch_id={$batchId} seq={$t['seq']} err={$r['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        $check = bulk_check_consistency($r['labels'], $r['years'], $t['original_name'], $seen);
        $years = implode(',', $r['years']);
        $seen[$t['original_name'] . '|' . $years] = (int)$t['seq'];

        bulk_update_file($conn, $id, [
            'status'          => BULK_F_DETECTED,
            'posting_period'  => (string)(bulk_posting_period($r['labels']) ?? ''),
            'detected_labels' => implode(',', $r['labels']),
            'detected_years'  => $years,
            'detect_reason'   => mb_substr($r['reason'], 0, 2000),
            'check_result'    => $check['result'],
            'check_code'      => (string)($check['code'] ?? ''),
            'check_message'   => (string)($check['message'] ?? ''),
        ]);
        bulk_log("DETECT OK batch_id={$batchId} seq={$t['seq']} labels=" . implode('/', $r['labels'])
                 . " years={$years} check={$check['result']}");
        bulk_refresh_counts($conn, $batchId);
    }

    bulk_refresh_counts($conn, $batchId);
    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_REVIEW,
        'message' => '判定が終わりました。内容をご確認ください。',
    ]);
    bulk_log("DETECT END batch_id={$batchId}");
}

// ─────────────────────────────────────────
// main
// ─────────────────────────────────────────
$batch = bulk_claim_batch($conn, $onlyBatch);
if (!$batch) {
    // 対象なし。cron で毎分動くため、ここでは何も出力しない。
    exit(0);
}

$batchId = $batch['batch_id'];
bulk_log("CLAIM batch_id={$batchId} status={$batch['status']} pid=" . getmypid());

try {
    if ($batch['status'] === BULK_ST_QUEUED || $batch['status'] === BULK_ST_DETECTING) {
        bulk_phase_detect($conn, $batch, (int)$port, $dryRun);
    } elseif ($batch['status'] === BULK_ST_APPROVED) {
        // 解析フェーズは bulk_analyze.php に分ける（次段で実装）
        if (file_exists(__DIR__ . '/bulk_analyze.php')) {
            require_once __DIR__ . '/bulk_analyze.php';
            bulk_phase_analyze($conn, $batch, (int)$port, $dryRun);
        } else {
            bulk_log("ANALYZE SKIP batch_id={$batchId} bulk_analyze.php が未配置です");
        }
    }
} catch (Throwable $e) {
    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_ERROR,
        'message' => mb_substr('処理中にエラーが発生しました: ' . $e->getMessage(), 0, 500),
    ]);
    bulk_log("ERROR batch_id={$batchId} " . $e->getMessage());
} finally {
    bulk_release($conn, $batchId);
}
