<?php
/**
 * bulk_worker.php
 * ─────────────────────────────────────────────────────────────────────────────
 * まとめてアップロードのワーカー。bulk_cron.sh から起動する
 * （cron で毎分。承認せずのバッチは受付完了時にも起動する）。
 *
 * 使い方:
 *   php bulk_worker.php                     ← 通常
 *   php bulk_worker.php --batch=261015-02   ← 特定バッチだけ処理（動作確認用）
 *   php bulk_worker.php --dry-run           ← 判定も解析も行わず対象だけ表示
 *
 * 処理内容:
 *   承認あり（mode=B）
 *     QUEUED   → 期判定＋整合チェック → REVIEW（人の確認待ち）
 *     APPROVED → 案件ごとに解析を実行   → DONE
 *   承認せず（mode=D）
 *     QUEUED   → 1ファイルずつ getpdfinfo.php で案件を登録 → DONE（bulk_direct.php）
 *
 * 並列:
 *   同じワーカーを bulk_cron.sh が複数本起動し、ファイルを1件ずつ奪い合う
 *   （bulk_claim_file）。バッチはロックしない。解析だけは VM のメモリを
 *   大きく使うため、サーバ全体で BULK_ANALYZE_PARALLEL 件までに抑える。
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

$opts     = getopt('', ['batch::', 'dry-run']);
$onlyBatch = $opts['batch'] ?? null;
$dryRun    = array_key_exists('dry-run', $opts);

// ─────────────────────────────────────────
// 対象バッチを1つ取り出してロックする
// ─────────────────────────────────────────
/**
 * 処理待ちのバッチを古い順にすべて返す。
 *
 * 複数ワーカーが同じバッチに同時参加できるよう、バッチはロックしない。
 * 実際の排他は bulk_claim_file()（ファイル単位）で行う。
 *
 * 以前は一番古い1つだけを返していたが、それだと古いバッチの解析中
 * （解析は同時に1件まで）に、後から来たバッチ（特に承認せず）が
 * その解析が全部終わるまで待たされた。ワーカーは古い順に試し、
 * 手を付けられたところで止まる（main を参照）。
 */
function bulk_candidate_batches(mysqli $conn, ?string $onlyBatch): array {
    $where = "status IN ('" . BULK_ST_QUEUED . "','" . BULK_ST_DETECTING . "','"
             . BULK_ST_APPROVED . "','" . BULK_ST_ANALYZING . "')";
    if ($onlyBatch !== null) {
        $where .= " AND batch_id = '" . $conn->real_escape_string($onlyBatch) . "'";
    }
    $res = $conn->query("SELECT * FROM bulk_batches WHERE {$where} ORDER BY create_at");
    return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/**
 * 1つのバッチを、方式と状態に応じた処理に回す。
 * @return bool このワーカーが1件以上処理したか
 */
function bulk_run_batch(mysqli $conn, array $batch, int $port, bool $dryRun): bool {
    $batchId = $batch['batch_id'];

    // 最後に触ったワーカーを記録する（進捗確認用。排他には使わない）
    $conn->query("UPDATE bulk_batches SET worker_pid = " . getmypid() . ", locked_at = now()
                   WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'");

    try {
        if ($batch['mode'] === BULK_MODE_DIRECT) {
            // 承認せず：通常画面のアップロードと同じ getpdfinfo.php で1件ずつ登録する
            require_once __DIR__ . '/bulk_direct.php';
            return bulk_phase_direct($conn, $batch, $port, $dryRun);
        }
        if ($batch['status'] === BULK_ST_QUEUED || $batch['status'] === BULK_ST_DETECTING) {
            return bulk_phase_detect($conn, $batch, $port, $dryRun);
        }
        if ($batch['status'] === BULK_ST_APPROVED || $batch['status'] === BULK_ST_ANALYZING) {
            // ANALYZING も受け入れる。1本目が APPROVED → ANALYZING に変えた後、
            // 2本目以降はここに来るため、APPROVED だけを見ていると何もせず終了し、
            // 解析が並列にならない（期判定が QUEUED と DETECTING の両方を見るのと同じ理由）。
            require_once __DIR__ . '/bulk_analyze.php';
            return bulk_phase_analyze($conn, $batch, $port, $dryRun);
        }
        return false;
    } catch (Throwable $e) {
        bulk_update_batch($conn, $batchId, [
            'status'  => BULK_ST_ERROR,
            'message' => mb_substr('処理中にエラーが発生しました: ' . $e->getMessage(), 0, 500),
        ]);
        bulk_log("ERROR batch_id={$batchId} " . $e->getMessage());
        return true;
    } finally {
        bulk_release($conn, $batchId);
    }
}

function bulk_release(mysqli $conn, string $batchId): void {
    bulk_update_batch($conn, $batchId, ['worker_pid' => 0]);
    $conn->query("UPDATE bulk_batches SET worker_pid = NULL, locked_at = NULL WHERE batch_id = '"
        . $conn->real_escape_string($batchId) . "'");
}

// ─────────────────────────────────────────
// 期判定フェーズ（QUEUED → REVIEW）
// ─────────────────────────────────────────
/** @return bool このワーカーが1件以上処理したか（false なら別のバッチを探しに行く） */
function bulk_phase_detect(mysqli $conn, array $batch, int $port, bool $dryRun): bool {
    $batchId = $batch['batch_id'];
    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_DETECTING,
        'message' => '帳票の期を判定しています。',
    ]);

    // 落ちたワーカーが掴んだままのファイルを戻してから始める
    bulk_reclaim_stale($conn, $batchId, BULK_F_DETECTING, BULK_F_RECEIVED);

    $remain = bulk_count_files($conn, $batchId, [BULK_F_RECEIVED, BULK_F_DETECTING]);
    bulk_log("DETECT START batch_id={$batchId} files={$remain}");

    if ($dryRun) {
        $res = $conn->query("SELECT seq, original_name FROM bulk_batch_files
                              WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                                AND status IN ('" . BULK_F_RECEIVED . "','" . BULK_F_DETECTING . "')
                              ORDER BY seq");
        while ($t = $res->fetch_assoc()) { echo "  [dry-run] #{$t['seq']} {$t['original_name']}\n"; }
        return false;
    }

    // 重複チェック用。既に判定済みのものも対象に含める。
    $seen = [];
    $done = $conn->query("SELECT seq, original_name, detected_years FROM bulk_batch_files
                           WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                             AND detected_years IS NOT NULL");
    while ($d = $done->fetch_assoc()) {
        $seen[$d['original_name'] . '|' . $d['detected_years']] = (int)$d['seq'];
    }

    // 1件ずつロックして取り出す（複数ワーカーで並列実行できるようにするため）
    $did = 0;
    while (($t = bulk_claim_file($conn, $batchId, BULK_F_RECEIVED, BULK_F_DETECTING)) !== null) {
        $did++;
        $id = (int)$t['id'];

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
            'detected_end_date' => $r['end_date'] ?? null,
            'detect_reason'   => mb_substr($r['reason'], 0, 2000),
            'check_result'    => $check['result'],
            'check_code'      => (string)($check['code'] ?? ''),
            'check_message'   => (string)($check['message'] ?? ''),
        ]);
        bulk_log("DETECT OK batch_id={$batchId} seq={$t['seq']} labels=" . implode('/', $r['labels'])
                 . " years={$years} end=" . ($r['end_date'] ?? '-') . " check={$check['result']}");
        bulk_refresh_counts($conn, $batchId);
    }

    bulk_refresh_counts($conn, $batchId);

    // 未処理が残っていれば、他のワーカーがまだ処理中。状態は変えない。
    if (bulk_count_files($conn, $batchId, [BULK_F_RECEIVED, BULK_F_DETECTING]) > 0) {
        bulk_log("DETECT PARTIAL batch_id={$batchId}（他のワーカーが処理中）");
        return $did > 0;
    }

    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_REVIEW,
        'message' => '判定が終わりました。内容をご確認ください。',
    ]);
    bulk_log("DETECT END batch_id={$batchId}");
    return $did > 0;
}

// ─────────────────────────────────────────
// main
// ─────────────────────────────────────────
// 古いバッチから順に試し、手を付けられたところで止める。
// 何もできなかったバッチ（解析の上限に達している・ファイルが他のワーカーに取られている等）は
// 飛ばして次へ進むので、後から来たバッチが待たされない。
foreach (bulk_candidate_batches($conn, $onlyBatch) as $batch) {
    bulk_log("CLAIM batch_id={$batch['batch_id']} mode={$batch['mode']} status={$batch['status']} pid=" . getmypid());
    if (bulk_run_batch($conn, $batch, (int)$port, $dryRun)) {
        break;
    }
}
// 対象なしなら何も出力しない（cron で毎分動くため）
