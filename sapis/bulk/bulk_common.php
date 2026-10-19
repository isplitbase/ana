<?php
/**
 * bulk_common.php
 * ─────────────────────────────────────────────────────────────────────────────
 * まとめてアップロード（複数ファイル一括投入）の共通処理。
 *
 * 各APIは必ず先頭で common.php を読み込んでから本ファイルを読み込むこと
 * （$conn / $port / $gcs_bucket などは common.php が用意する）。
 */

// GCS / Google Auth を使うため composer の autoload を読み込む。
// common.php は読み込んでくれないので、ここで確実に読む。
$__bulk_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($__bulk_autoload)) {
    require_once $__bulk_autoload;
}

// ─────────────────────────────────────────
// 定数
// ─────────────────────────────────────────

/** 1リクエストで受け取るファイル数の上限。
 *  PHP の max_file_uploads=20 の範囲内に収め、かつサーバ負荷を抑えるため 3 本とする。 */
const BULK_FILES_PER_REQUEST = 3;

/** 1バッチの最大ファイル数（= 最大案件数）。管理者設定で変える想定だが暫定の上限。 */
const BULK_MAX_FILES = 100;

/** 1ファイルの最大サイズ（既存 upload_files.php と揃える） */
const BULK_MAX_FILE_SIZE = 50 * 1024 * 1024;

/** GCS 上の保存先プレフィックス */
const BULK_GCS_PREFIX = 'bulk/';

/** 同時に解析できる案件数。
 *
 *  期判定は Cloud Run を呼ぶだけなので何本でも並列にできるが、解析は
 *  do_ai.php がこのVM上で Python を起動するため、メモリとCPUを大きく使う。
 *  2 vCPU / 7.7GB・swap なしの構成で3本同時に走らせたところ、
 *  メモリが枯渇して MySQL が落ち、読取が5件失敗した（2026-10-19 本番）。
 *  増やす場合は free -m と load average を見ながら1ずつにすること。 */
const BULK_ANALYZE_PARALLEL = 1;

/** 掴んだまま落ちたワーカーのファイルを回収するまでの秒数。
 *  プロセスが消えていることに加えて、この時間が過ぎていることも条件にする
 *  （PIDが使い回された場合に、生きている処理を横取りしないため）。 */
const BULK_STALE_SECONDS = 180;

/** 確認画面の方式。ファイル単位の一覧で確認する B のみ。
 *  以前はページ単位で1枚ずつ確認する A も用意していたが、ページ画像は
 *  承認後の変換で初めて作られるため確認画面に出せず、使われないまま
 *  だったので削除した（2026-10-19）。bulk_batches.mode 列は残してあり、
 *  常に 'B' を入れる。 */
const BULK_MODE_FILE = 'B';

/** バッチの状態 */
const BULK_ST_OPEN      = 'OPEN';       // 受付中（ファイル送信の途中）
const BULK_ST_QUEUED    = 'QUEUED';     // 受付締め。ワーカー待ち
const BULK_ST_DETECTING = 'DETECTING';  // 期判定中
const BULK_ST_REVIEW    = 'REVIEW';     // 確認待ち（人の操作待ち）
const BULK_ST_APPROVED  = 'APPROVED';   // 承認済み。解析待ち
const BULK_ST_ANALYZING = 'ANALYZING';  // 解析中
const BULK_ST_DONE      = 'DONE';
const BULK_ST_ERROR     = 'ERROR';
const BULK_ST_CANCELED  = 'CANCELED';

/** ファイルの状態 */
const BULK_F_RECEIVED  = 'RECEIVED';
const BULK_F_DETECTING = 'DETECTING';
const BULK_F_DETECTED  = 'DETECTED';
const BULK_F_APPROVED  = 'APPROVED';
const BULK_F_ANALYZING = 'ANALYZING';
const BULK_F_DONE      = 'DONE';
const BULK_F_ERROR     = 'ERROR';
const BULK_F_SKIPPED   = 'SKIPPED';   // ユーザーが確認画面で除外した

/** 読取後の決算日の照合（人が確認した決算日 と 読取AIが書類から読んだ決算日）
 *  読取そのものの成否とは別に記録する。食い違っても解析結果は残し、要確認として示す。 */
const BULK_EDC_MATCH    = 'MATCH';     // 一致（読取の決算日を採用）
const BULK_EDC_MISMATCH = 'MISMATCH';  // 食い違い（読取の決算日を採用。要確認）
const BULK_EDC_USER     = 'USER';      // 担当者が決算日を変更した（担当者の決算日を採用）
const BULK_EDC_SKIP     = 'SKIP';      // どちらかが空で照合できない（読取の決算日を採用）

// ─────────────────────────────────────────
// 応答
// ─────────────────────────────────────────

/**
 * JSON を返して終了する。
 * 入力エラーは HTTP 400 で返す（他の sapis と揃える）。
 * Angular の HttpErrorResponse はボディを error プロパティに保持するため、
 * フロント側は err.error.message で取得できる。
 */
function bulk_json($data, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function bulk_error(string $message, int $httpCode = 400, array $extra = []): void {
    bulk_json(array_merge(['status' => 'error', 'message' => $message], $extra), $httpCode);
}

// ─────────────────────────────────────────
// ログ
// ─────────────────────────────────────────

function bulk_log(string $message): void {
    $logDir = '/var/log/ana';
    if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

    // Web(www-data) と ワーカー(cron実行ユーザー) で書き込み先を分ける。
    // 同じファイルに追記しようとすると、先に作った側の所有になって
    // もう一方が Permission denied になり、ログが無言で消える。
    // 8012(本番)/8056(開発) が同じログに混ざらないよう、ポートも入れる。
    $who  = (PHP_SAPI === 'cli') ? 'worker' : 'api';
    $env  = (string)($GLOBALS['port'] ?? 'unknown');
    $file = $logDir . '/bulk_' . $env . '_' . $who . '_' . date('Ymd') . '.log';

    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    if (@file_put_contents($file, $line, FILE_APPEND) === false) {
        // 書けない場合は標準エラーへ（cron のリダイレクト先に残る）
        @file_put_contents('php://stderr', $line);
        return;
    }
    @chmod($file, 0666);   // 別ユーザーからも追記できるようにしておく
}

// ─────────────────────────────────────────
// バッチID
// ─────────────────────────────────────────

/**
 * バッチIDを採番する。形式: YYMMDD-NN（当日の連番）
 * 例: 251015-03
 */
function bulk_new_batch_id(mysqli $conn): string {
    $prefix = date('ymd');
    $stmt = $conn->prepare(
        "SELECT batch_id FROM bulk_batches WHERE batch_id LIKE ? ORDER BY batch_id DESC LIMIT 1"
    );
    $like = $prefix . '-%';
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $next = 1;
    if ($row && preg_match('/-(\d+)$/', $row['batch_id'], $m)) {
        $next = (int)$m[1] + 1;
    }
    return sprintf('%s-%02d', $prefix, $next);
}

/**
 * 案件名を組み立てる。
 * 例: [一括 251015-03 #007] 10160114-202503_大倉電気株式会社.pdf
 * 一覧でバッチごとに固まり、投入順も分かるようにしている。
 *
 * 【1案件=1ファイル前提】1ファイルの通し番号とファイル名で名付けている。
 * 複数ファイルの案件では、組の代表（先頭のファイル）で名付けるなどの決めが要る。
 */
function bulk_case_name(string $batchId, int $seq, string $originalName): string {
    $name = sprintf('[一括 %s #%03d] %s', $batchId, $seq, $originalName);
    return mb_substr($name, 0, 400);   // ai_case.case_name は varchar(400)
}

// ─────────────────────────────────────────
// バッチ取得
// ─────────────────────────────────────────

/**
 * バッチを取得し、呼び出しユーザーの所属会社のものか検証する。
 * 他社のバッチを触れないようにするため、company_id で照合する。
 */
function bulk_fetch_batch(mysqli $conn, string $batchId, int $companyId): array {
    $stmt = $conn->prepare("SELECT * FROM bulk_batches WHERE batch_id = ?");
    $stmt->bind_param('s', $batchId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$batch) {
        bulk_error('指定されたバッチが見つかりません。', 404);
    }
    if ((int)$batch['company_id'] !== $companyId) {
        bulk_error('このバッチを操作する権限がありません。', 403);
    }
    return $batch;
}

function bulk_update_batch(mysqli $conn, string $batchId, array $fields): void {
    if (!$fields) { return; }
    $sets = [];
    $types = '';
    $vals = [];
    foreach ($fields as $col => $val) {
        $sets[] = "`{$col}` = ?";
        $types .= is_int($val) ? 'i' : 's';
        $vals[] = $val;
    }
    $sets[] = 'update_at = now()';
    $sql = 'UPDATE bulk_batches SET ' . implode(', ', $sets) . ' WHERE batch_id = ?';
    $types .= 's';
    $vals[] = $batchId;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

function bulk_update_file(mysqli $conn, int $id, array $fields): void {
    if (!$fields) { return; }
    $sets = [];
    $types = '';
    $vals = [];
    foreach ($fields as $col => $val) {
        $sets[] = "`{$col}` = ?";
        $types .= is_int($val) ? 'i' : 's';
        $vals[] = $val;
    }
    $sets[] = 'update_at = now()';
    $sql = 'UPDATE bulk_batch_files SET ' . implode(', ', $sets) . ' WHERE id = ?';
    $types .= 'i';
    $vals[] = $id;

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$vals);
    $stmt->execute();
    $stmt->close();
}

/** バッチの件数（done / error）を数え直して bulk_batches に反映する */
function bulk_refresh_counts(mysqli $conn, string $batchId): void {
    $sql = "SELECT
                COUNT(*) AS total,
                SUM(status IN ('" . BULK_F_DETECTED . "','" . BULK_F_DONE . "','" . BULK_F_SKIPPED . "')) AS done,
                SUM(status = '" . BULK_F_ERROR . "') AS err
            FROM bulk_batch_files WHERE batch_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $batchId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    bulk_update_batch($conn, $batchId, [
        'total_files' => (int)$r['total'],
        'done_files'  => (int)$r['done'],
        'error_files' => (int)$r['err'],
    ]);
}

// ─────────────────────────────────────────
// GCS
// ─────────────────────────────────────────

/**
 * ローカルファイルを GCS へ上げて gs:// URI を返す。
 * upload_files.php の upload_file_to_gcs_sdk() と同じ方式だが、
 * あちらは同ファイル内の関数で再利用できないため同等のものを持つ。
 */
function bulk_upload_to_gcs(string $localPath, string $bucketName, string $objectName): string {
    if (!empty($GLOBALS['google_application_credentials'])) {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $GLOBALS['google_application_credentials']);
    }
    if (!class_exists('\Google\Cloud\Storage\StorageClient')) {
        throw new RuntimeException('google/cloud-storage が読み込めません（vendor/autoload.php を確認してください）');
    }
    if (empty($bucketName)) {
        throw new RuntimeException('gcs_bucket が空です（common.php を確認してください）');
    }

    $storage = new \Google\Cloud\Storage\StorageClient();
    $storage->bucket($bucketName)->upload(
        fopen($localPath, 'r'),
        ['name' => ltrim($objectName, '/')]
    );
    return "gs://{$bucketName}/" . ltrim($objectName, '/');
}

/**
 * Cloud Run を叩くための ID トークンを取得する。
 * サービスアカウント鍵（GCS署名にも使っているもの）から発行する。
 */
function bulk_get_id_token(string $audience): ?string {
    $keyFile = '/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-691c42569236.json';
    if (!file_exists($keyFile)) { return null; }
    try {
        $cred = new \Google\Auth\Credentials\ServiceAccountCredentials(
            null, json_decode(file_get_contents($keyFile), true), null, $audience
        );
        $tok = $cred->fetchAuthToken();
        return $tok['id_token'] ?? null;
    } catch (Throwable $e) {
        bulk_log('ID_TOKEN_FAIL audience=' . $audience . ' err=' . $e->getMessage());
        return null;
    }
}

/**
 * GCS 内でオブジェクトをコピーする（ダウンロードせずサーバ側で複製する）。
 *
 * pdf-converter は入力ファイル名の先頭から ai_case_id を抽出する仕様
 * （derive_ai_case_id_from_input_obj / 正規表現 ^(\d+)[-_] ）のため、
 * 受付時の bulk/<port>/<batch>/<seq>.pdf のままでは変換できない。
 * 承認で ai_case_id が決まった時点で <案件ID>-<キー>.pdf へ複製する。
 */
function bulk_copy_gcs(string $srcUri, string $dstObject, string $bucketName): string {
    if (!empty($GLOBALS['google_application_credentials'])) {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $GLOBALS['google_application_credentials']);
    }
    if (!preg_match('#^gs://([^/]+)/(.+)$#', $srcUri, $m)) {
        throw new RuntimeException('コピー元のURIが不正です: ' . $srcUri);
    }
    $storage = new \Google\Cloud\Storage\StorageClient();
    $srcBucket = $storage->bucket($m[1]);
    $srcBucket->object($m[2])->copy($storage->bucket($bucketName), ['name' => ltrim($dstObject, '/')]);
    return "gs://{$bucketName}/" . ltrim($dstObject, '/');
}

/**
 * 決算日の文字列を Y-m-d にそろえる。読めなければ null。
 *
 * 期判定AI・読取AI・人の入力で表記がばらつくため、照合の前にそろえる。
 * 実際に読取AIの closing_date には「2023年5月31日」と「2023年05月31日」が混在している。
 * 受け付ける形: 2025-09-30 / 2025/9/30 / 2025.9.30 / 2025年9月30日 / 令和7年9月30日（平成・昭和・元年も可）
 */
function bulk_parse_date(?string $text): ?string {
    $s = trim((string)$text);
    if ($s === '') { return null; }
    $s = mb_convert_kana($s, 'n');                 // 全角数字を半角に
    $s = str_replace('元年', '1年', $s);

    $y = $m = $d = null;
    if (preg_match('/^(\d{4})\s*[-\/.年]\s*(\d{1,2})\s*[-\/.月]\s*(\d{1,2})/u', $s, $x)) {
        [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]];
    } else {
        $eras = ['令和' => 2018, '平成' => 1988, '昭和' => 1925];
        foreach ($eras as $era => $base) {
            if (preg_match('/' . $era . '\s*(\d{1,2})\s*年\s*(\d{1,2})\s*月\s*(\d{1,2})/u', $s, $x)) {
                [$y, $m, $d] = [$base + (int)$x[1], (int)$x[2], (int)$x[3]];
                break;
            }
        }
    }
    if ($y === null || !checkdate($m, $d, $y)) { return null; }
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Y-m-d を、読取AIが ai_case.closing_date に書く形（2025年05月31日）にする。
 * 画面や CF 分析（cash_ai.php）はこの形の文字列を受け取っているため、書き換えるときもそろえる。
 */
function bulk_jp_date(string $ymd): string {
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    return sprintf('%04d年%02d月%02d日', $y, $m, $d);
}

/**
 * Y-m-d を n 年ずらす。月末の日付は、ずらした先でも月末にする。
 * （2024-02-29 の1年前は 2023-02-28。DateTime の modify だと 2023-03-01 になってしまう）
 */
function bulk_shift_years(string $ymd, int $years): string {
    [$y, $m, $d] = array_map('intval', explode('-', $ymd));
    $daysIn = function (int $yy, int $mm): int { return (int)date('t', mktime(0, 0, 0, $mm, 1, $yy)); };
    $isMonthEnd = ($d === $daysIn($y, $m));
    $ny = $y + $years;
    $last = $daysIn($ny, $m);
    $nd = $isMonthEnd ? $last : min($d, $last);
    return sprintf('%04d-%02d-%02d', $ny, $m, $nd);
}

/**
 * 案件（ai_case）を1件作り、受付ファイルを変換用の名前に複製する。
 *
 * ai_case はもともと1案件に複数ファイルを持てる作り
 * （upload_file_name / upload_file_keys / full_file_Paths を「|,|」で連結）で、
 * 通常のアップロード画面でも2ファイルの案件が作られている。
 * そのため、この関数もファイルの配列を受け取る形にしてある。
 *
 * 【1案件=1ファイル前提】現在の呼び出し元（bulk_upload_approve.php）は
 * 常に1件だけ渡す。複数ファイルを1案件にまとめる場合は、
 * 同じ case_group のファイルをまとめて渡せばよい（README 参照）。
 *
 * @param array $members  [ ['original_name'=>..., 'gcs_path'=>..., 'file_key'=>...], ... ]
 *                        file_key は upload_file_keys に入る帳票キー
 *                        （currentTermBalanceSheet 等）。案件内で重複不可。
 * @return int  作成した ai_case_id
 * @throws RuntimeException  複製に失敗した場合。案件の行は作成済みのまま残る
 *                           （メッセージに案件IDを含める）。
 */
function bulk_create_case(mysqli $conn, ?int $userId, string $caseName, array $members): int {
    if (!$members) {
        throw new InvalidArgumentException('案件にするファイルがありません');
    }
    $keys = array_column($members, 'file_key');
    if (count($keys) !== count(array_unique($keys))) {
        // 複製先が <案件ID>-<キー>.pdf のため、キーが重なると上書きしてしまう
        throw new InvalidArgumentException('同じ案件内で帳票キーが重複しています: ' . implode(',', $keys));
    }

    $names = implode('|,|', array_column($members, 'original_name'));
    $keyStr = implode('|,|', $keys);
    $paths = implode('|,|', array_column($members, 'gcs_path'));
    $status = 'UPST';   // 既存フローと同じ「アップロード済み・変換待ち」

    $stmt = $conn->prepare(
        "INSERT INTO ai_case
           (user_id, update_user_id, create_at, update_at, status, case_name,
            upload_file_name, upload_file_keys, full_file_Paths)
         VALUES (?, ?, now(), now(), ?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisssss', $userId, $userId, $status, $caseName, $names, $keyStr, $paths);
    $stmt->execute();
    $aiCaseId = (int)$conn->insert_id;
    $stmt->close();

    // GCS 上で <案件ID>-<キー>.pdf へ複製する。
    // pdf-converter がファイル名から ai_case_id を抽出する仕様のため
    // （^(\d+)[-_] に一致しないと 500 になる）、受付時の
    // bulk/<port>/<batch>/<seq>.pdf のままでは変換できない。
    $bucket = $GLOBALS['gcs_bucket'] ?? 'analygent';
    $newUris = [];
    foreach ($members as $m) {
        try {
            $newUris[] = bulk_copy_gcs($m['gcs_path'], $aiCaseId . '-' . $m['file_key'] . '.pdf', $bucket);
        } catch (Throwable $e) {
            throw new RuntimeException("case={$aiCaseId} 複製に失敗: " . $e->getMessage(), 0, $e);
        }
    }

    $joined = implode('|,|', $newUris);
    $stmt = $conn->prepare("UPDATE ai_case SET full_file_Paths = ?, update_at = now() WHERE ai_case_id = ?");
    $stmt->bind_param('si', $joined, $aiCaseId);
    $stmt->execute();
    $stmt->close();

    return $aiCaseId;
}

/**
 * 未処理のファイルを1件だけロックして取り出す（並列ワーカー用）。
 *
 * PHP には並行処理が無いため、同じワーカーを複数本起動して
 * 1件ずつ奪い合う形で並列化する。UPDATE の affected_rows で
 * 取得できたかを判定するので、同じファイルを2本が処理することはない。
 *
 * @param string $from   取り出す対象の状態（RECEIVED / APPROVED）
 * @param string $to     取得後に立てる状態（DETECTING / ANALYZING）
 * @return array|null    取得できた1行。無ければ null
 */
function bulk_claim_file(mysqli $conn, string $batchId, string $from, string $to): ?array {
    $pid = getmypid();
    $bid = $conn->real_escape_string($batchId);

    // worker_pid 列で「誰が掴んだか」を記録する。
    $conn->query(
        "UPDATE bulk_batch_files
            SET status = '" . $conn->real_escape_string($to) . "',
                worker_pid = {$pid},
                update_at = now()
          WHERE batch_id = '{$bid}'
            AND status = '" . $conn->real_escape_string($from) . "'
          ORDER BY seq
          LIMIT 1"
    );
    if ($conn->affected_rows < 1) { return null; }

    $res = $conn->query(
        "SELECT * FROM bulk_batch_files
          WHERE batch_id = '{$bid}' AND worker_pid = {$pid}
            AND status = '" . $conn->real_escape_string($to) . "'
          ORDER BY update_at DESC LIMIT 1"
    );
    return $res ? $res->fetch_assoc() : null;
}

/**
 * PID のプロセスが生きているかを見る。
 * posix 拡張が無い環境でも動くよう /proc を直接見る。
 */
function bulk_pid_alive(?int $pid): bool {
    if (!$pid || $pid <= 0) { return false; }
    return is_dir('/proc/' . $pid);
}

/**
 * 掴んだまま落ちたワーカーのファイルを元の状態に戻す。
 *
 * ワーカーが異常終了すると、そのファイルは DETECTING / ANALYZING のまま残る。
 * bulk_claim_file は「元の状態」のものしか拾わないため、放置すると
 * 誰も処理せず、バッチが永久に完了しない（2026-10-19 本番で発生）。
 *
 * @param string $stuck  取り残されている状態（例: ANALYZING）
 * @param string $back   戻す先の状態（例: APPROVED）
 * @return int 戻した件数
 */
function bulk_reclaim_stale(mysqli $conn, string $batchId, string $stuck, string $back): int {
    $bid = $conn->real_escape_string($batchId);
    $res = $conn->query(
        "SELECT id, seq, worker_pid FROM bulk_batch_files
          WHERE batch_id = '{$bid}'
            AND status = '" . $conn->real_escape_string($stuck) . "'
            AND update_at < DATE_SUB(now(), INTERVAL " . BULK_STALE_SECONDS . " SECOND)"
    );
    if (!$res) { return 0; }

    $n = 0;
    while ($row = $res->fetch_assoc()) {
        if (bulk_pid_alive(isset($row['worker_pid']) ? (int)$row['worker_pid'] : null)) {
            continue;   // まだ生きている。時間がかかっているだけ
        }
        $conn->query(
            "UPDATE bulk_batch_files
                SET status = '" . $conn->real_escape_string($back) . "',
                    worker_pid = NULL,
                    update_at = now()
              WHERE id = " . (int)$row['id'] . "
                AND status = '" . $conn->real_escape_string($stuck) . "'"
        );
        if ($conn->affected_rows > 0) {
            $n++;
            bulk_log("RECLAIM batch_id={$batchId} seq={$row['seq']} {$stuck} → {$back}"
                   . " (pid={$row['worker_pid']} は終了済み)");
        }
    }
    return $n;
}

/**
 * 他のワーカーが実際に解析中のファイル数を数える。
 *
 * 件数だけでは、落ちたワーカーの取り残しも「解析中」に見えてしまうため、
 * PID が生きているものだけを数える。
 */
function bulk_count_live_analyzing(mysqli $conn, string $batchId, int $selfPid): int {
    $bid = $conn->real_escape_string($batchId);
    $res = $conn->query(
        "SELECT worker_pid FROM bulk_batch_files
          WHERE batch_id = '{$bid}'
            AND status = '" . BULK_F_ANALYZING . "'
            AND (worker_pid IS NULL OR worker_pid <> {$selfPid})"
    );
    if (!$res) { return 0; }

    $n = 0;
    while ($row = $res->fetch_assoc()) {
        if (bulk_pid_alive(isset($row['worker_pid']) ? (int)$row['worker_pid'] : null)) { $n++; }
    }
    return $n;
}

/** 指定状態のファイルが残っているかを数える */
function bulk_count_files(mysqli $conn, string $batchId, array $statuses): int {
    $bid = $conn->real_escape_string($batchId);
    $in  = implode("','", array_map(fn($s) => $conn->real_escape_string($s), $statuses));
    $r = $conn->query("SELECT COUNT(*) AS c FROM bulk_batch_files
                        WHERE batch_id = '{$bid}' AND status IN ('{$in}')");
    return (int)($r ? $r->fetch_assoc()['c'] : 0);
}
