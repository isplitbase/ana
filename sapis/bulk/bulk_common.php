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

/** 確認画面の方式 */
const BULK_MODE_PAGE = 'A';   // ページ単位で1枚ずつ確認
const BULK_MODE_FILE = 'B';   // ファイル単位の一覧で確認

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
    $who  = (PHP_SAPI === 'cli') ? 'worker' : 'api';
    $file = $logDir . '/bulk_' . $who . '_' . date('Ymd') . '.log';

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
 * 受付時の bulk/<batch>/<seq>.pdf のままでは変換できない。
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
