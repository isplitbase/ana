<?php
/**
 * batch_delete_expired.php
 * ─────────────────────────────────────────────────────────────────────────────
 * crontab で毎時実行し、delete_pdf_timing_cd に基づいて
 * 期限切れ ai_case の GCS ファイルと DB レコードを削除するバッチ。
 *
 * delete_pdf_timing_cd の意味（update_at 基準）:
 *   A → 24時間後に削除
 *   B → 1ヶ月後に削除
 *   C → 1年後に削除
 *   D / NULL → 削除しない
 *
 * 使い方:
 *   php /srv/www/apps/analygent_backend/internal/for_8056/batch_delete_expired.php
 *   php batch_delete_expired.php --dry-run   ← DB/GCS を変更しない確認モード
 *
 * crontab 例（毎時0分）:
 *   0 * * * * /usr/bin/php /srv/www/apps/analygent_backend/internal/for_8056/batch_delete_expired.php >> /var/log/ana/batch_delete_$(date +\%Y\%m\%d).log 2>&1
 */

// ─── 設定 ───────────────────────────────────────────────────────────────────
$BATCH_LIMIT = 500;          // 1回の実行で処理する最大件数
$DRY_RUN     = in_array('--dry-run', $argv ?? []);

// ─── 初期化 ─────────────────────────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ERROR | E_PARSE);
date_default_timezone_set('Asia/Tokyo');

// CLI のみ実行可（Web からのアクセスを拒否）
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// ─── ロギング ───────────────────────────────────────────────────────────────
function blog(string $msg): void {
    $ts = date('Y-m-d H:i:s');
    echo "[{$ts}] {$msg}" . PHP_EOL;
}

// ─── GCS SDK 読み込み ────────────────────────────────────────────────────────
$autoload1 = __DIR__ . "/vendor/autoload.php";
$autoload2 = __DIR__ . "/../vendor/autoload.php";
if (file_exists($autoload1))      { require_once $autoload1; }
elseif (file_exists($autoload2))  { require_once $autoload2; }

include __DIR__ . "/common.php";
require_once __DIR__ . "/system_log_helper.php";

// GCS クライアント初期化
global $gcs_bucket, $google_application_credentials;
if (!empty($google_application_credentials)) {
    putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $google_application_credentials);
}
$gcs_available = class_exists("\\Google\\Cloud\\Storage\\StorageClient") && !empty($gcs_bucket);

// ─── delete_pdf_timing_cd → 削除期限の計算 ──────────────────────────────────
function calc_cutoff(string $timing_cd): ?DateTime {
    $now = new DateTime();
    switch ($timing_cd) {
        case 'A': $now->modify('-24 hours');  return $now;
        case 'B': $now->modify('-1 month');   return $now;
        case 'C': $now->modify('-1 year');    return $now;
        default:  return null; // 削除しない
    }
}

// ─── GCS ファイル削除 ────────────────────────────────────────────────────────
function delete_gcs_files(
    int    $ai_case_id,
    string $full_file_paths_str,
    string $img_urls_str,
    bool   $dry_run,
    string $gcs_bucket_name
): array {
    $deleted = [];

    if (!class_exists("\\Google\\Cloud\\Storage\\StorageClient")) {
        blog("  [GCS] SDK 未読込のためスキップ");
        return $deleted;
    }

    $storage = new \Google\Cloud\Storage\StorageClient();
    $bucket  = $storage->bucket($gcs_bucket_name);

    // ① full_file_Paths / img_urls の gs:// URI を直接削除
    $all_paths = [];
    foreach ([$full_file_paths_str, $img_urls_str] as $s) {
        if (!empty($s)) {
            foreach (explode('|,|', $s) as $p) {
                $all_paths[] = trim($p);
            }
        }
    }
    foreach ($all_paths as $path) {
        if (!preg_match('#^gs://([^/]+)/(.+)$#', $path, $m)) continue;
        if ($m[1] !== $gcs_bucket_name) continue;
        if ($dry_run) {
            blog("  [DRY_RUN] GCS 削除スキップ: {$path}");
            $deleted[] = $path;
            continue;
        }
        try {
            $bucket->object($m[2])->delete();
            blog("  [GCS] 削除: {$path}");
            $deleted[] = $path;
        } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
            // 既に存在しない
        } catch (Exception $e) {
            blog("  [GCS] 削除失敗: {$path} ({$e->getMessage()})");
        }
    }

    // ② プレフィックスで一括削除
    $prefixes = [
        "{$ai_case_id}-",           // PDF ファイル
        "converted/{$ai_case_id}-", // 変換済み画像 + mini
        // 2026-10-14 追加: 旧リビジョンの中間ファイル converted/converted_{id}-…_mini.png
        //   は上の前方一致に一致せず残っていたため、対象に加える。
        "converted/converted_{$ai_case_id}-",
    ];
    foreach ($prefixes as $prefix) {
        try {
            $objects = $bucket->objects(['prefix' => $prefix]);
            foreach ($objects as $object) {
                $name = $object->name();
                $uri  = "gs://{$gcs_bucket_name}/{$name}";
                if ($dry_run) {
                    blog("  [DRY_RUN] GCS 削除スキップ: {$uri}");
                    $deleted[] = $uri;
                    continue;
                }
                try {
                    $object->delete();
                    blog("  [GCS] 削除: {$uri}");
                    $deleted[] = $uri;
                } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
                    // 既に削除済み
                } catch (Exception $e) {
                    blog("  [GCS] 削除失敗: {$uri} ({$e->getMessage()})");
                }
            }
        } catch (Exception $e) {
            blog("  [GCS] prefix={$prefix} 一覧取得失敗: {$e->getMessage()}");
        }
    }

    return array_unique($deleted);
}

// ─── メイン処理 ──────────────────────────────────────────────────────────────
blog("========================================");
blog("batch_delete_expired 開始");
blog("DRY_RUN    : " . ($DRY_RUN ? 'true' : 'false'));
blog("GCS_BUCKET : " . ($gcs_bucket ?? '未設定'));
blog("BATCH_LIMIT: {$BATCH_LIMIT}");
blog("========================================");

// delete_pdf_timing_cd を持つ会社を取得
$stmt_companies = $conn->prepare("
    SELECT user_id AS company_user_id, delete_pdf_timing_cd
    FROM users
    WHERE delete_pdf_timing_cd IS NOT NULL
      AND delete_pdf_timing_cd != ''
      AND delete_pdf_timing_cd != 'D'
");
$stmt_companies->execute();
$result_companies = $stmt_companies->get_result();
$companies = [];
while ($row = $result_companies->fetch_assoc()) {
    $companies[] = $row;
}
blog("削除設定あり会社数: " . count($companies));

$total_target  = 0;
$total_deleted = 0;
$total_gcs     = 0;
$total_errors  = 0;

foreach ($companies as $company) {
    $company_user_id = (int)$company['company_user_id'];
    $timing_cd       = $company['delete_pdf_timing_cd'];
    $cutoff          = calc_cutoff($timing_cd);

    if ($cutoff === null) continue;

    $cutoff_str = $cutoff->format('Y-m-d H:i:s');
    blog("  company_user_id={$company_user_id} timing_cd={$timing_cd} cutoff={$cutoff_str}");

    // 削除対象の ai_case を取得
    $stmt_cases = $conn->prepare("
        SELECT ac.ai_case_id,
               ac.user_id,
               ac.upload_file_name,
               ac.full_file_Paths,
               ac.img_urls,
               ac.update_at
        FROM ai_case ac
        INNER JOIN users u ON ac.user_id = u.user_id
        WHERE u.company_id = ?
          AND ac.status != 'DELETED'
          AND ac.update_at < ?
        LIMIT ?
    ");
    $stmt_cases->bind_param("isi", $company_user_id, $cutoff_str, $BATCH_LIMIT);
    $stmt_cases->execute();
    $result_cases = $stmt_cases->get_result();
    $cases = [];
    while ($row = $result_cases->fetch_assoc()) {
        $cases[] = $row;
    }

    blog("    → 削除対象 ai_case: " . count($cases) . " 件");
    $total_target += count($cases);

    foreach ($cases as $case) {
        $ai_case_id       = (int)$case['ai_case_id'];
        $case_user_id     = (int)$case['user_id'];
        $upload_file_name = $case['upload_file_name'] ?? '';
        $full_file_paths  = $case['full_file_Paths']  ?? '';
        $img_urls_val     = $case['img_urls']          ?? '';

        blog("--- ai_case_id={$ai_case_id} file={$upload_file_name} update_at={$case['update_at']} ---");

        // GCS 物理削除
        if ($gcs_available) {
            $gcs_deleted = delete_gcs_files(
                $ai_case_id,
                $full_file_paths,
                $img_urls_val,
                $DRY_RUN,
                $gcs_bucket
            );
            $total_gcs += count($gcs_deleted);
        } else {
            blog("  [GCS] 設定なしのためスキップ");
        }

        // DB 論理削除 + 操作履歴
        if (!$DRY_RUN) {
            try {
                // ai_case 論理削除
                $stmt_del = $conn->prepare("
                    UPDATE ai_case
                    SET status = 'DELETED', update_at = NOW(), update_user_id = -1
                    WHERE ai_case_id = ?
                ");
                $stmt_del->bind_param("i", $ai_case_id);
                $stmt_del->execute();

                // usede 操作履歴
                $ip     = 'batch_cron';
                $device = 'crontab';
                $use_point = 0;
                $pages_number = 0;
                $p1 = 0;
                $stmt_usede = $conn->prepare("
                    INSERT INTO usede
                        (user_id, use_point, create_at, filename, pages_number,
                         ip, device, ai_case_id, p0, p1)
                    VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, 'バッチ削除', ?)
                ");
                $stmt_usede->bind_param(
                    "iisissii",
                    $case_user_id, $use_point,
                    $upload_file_name, $pages_number,
                    $ip, $device,
                    $ai_case_id, $p1
                );
                $stmt_usede->execute();

                blog("  [DB] 論理削除 & 操作履歴記録 完了");
                $total_deleted++;

                write_system_log($conn, 'info', 'FILE', 'FILE_DELETED_AUTO', 'バッチにより期限切れファイルを削除しました',
                    ip_address: 'batch_cron',
                    detail:     [
                        'ai_case_id'   => $ai_case_id,
                        'user_id'      => $case_user_id,
                        'timing_cd'    => $timing_cd,
                        'filename'     => $upload_file_name,
                        'dry_run'      => $DRY_RUN,
                    ]
                );

            } catch (Exception $e) {
                blog("  [DB] 更新失敗: {$e->getMessage()}");
                $total_errors++;
            }
        } else {
            blog("  [DRY_RUN] DB更新スキップ");
            $total_deleted++;
        }
    }
}

// ─── サマリ ──────────────────────────────────────────────────────────────────
blog("========================================");
blog("batch_delete_expired 完了サマリ");
blog("  処理対象ケース数  : {$total_target}");
blog("  DB論理削除完了数  : {$total_deleted}");
blog("  GCS削除ファイル数 : {$total_gcs}");
blog("  エラー数          : {$total_errors}");
blog("  DRY_RUN           : " . ($DRY_RUN ? 'true' : 'false'));
blog("========================================");

write_system_log($conn, 'info', 'SYSTEM', 'BATCH_COMPLETED', 'バッチ処理（期限切れ削除）が完了しました',
    ip_address: 'batch_cron',
    detail:     [
        'total_target'  => $total_target,
        'total_deleted' => $total_deleted,
        'total_gcs'     => $total_gcs,
        'total_errors'  => $total_errors,
        'dry_run'       => $DRY_RUN,
    ]
);
