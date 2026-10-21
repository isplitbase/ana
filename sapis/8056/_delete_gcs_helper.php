<?php
/**
 * _delete_gcs_helper.php
 * 案件のGCSファイルを物理削除する関数。delete_cases.php（画面からの削除）と
 * delete_cases_gcs.php（その裏で実際に消すCLI）から使う。
 * 2026-10-21 delete_cases.php から切り出した（中身は変えていない）。
 */

// =========================================================
// GCSファイル物理削除関数
// =========================================================
/**
 * 1案件のGCSファイルを物理削除する
 *
 * 削除対象:
 *   1. full_file_Paths / img_urls に含まれる gs:// URI
 *   2. バケット内プレフィックス "{ai_case_id}-" （PDFファイル）
 *   3. バケット内プレフィックス "converted/{ai_case_id}-" （変換済み画像）
 *
 * @param int    $ai_case_id
 * @param string $full_file_Paths_str  DB の full_file_Paths（|,| 区切り）
 * @param string $img_urls_str         DB の img_urls（|,| 区切り）
 * @return array ["deleted" => [...], "errors" => [...]]
 */
function delete_gcs_files_for_case($ai_case_id, $full_file_Paths_str, $img_urls_str) {
    global $gcs_bucket;
    global $google_application_credentials;

    $deleted = [];
    $errors  = [];

    if (empty($gcs_bucket)) {
        return ["deleted" => $deleted, "errors" => ["gcs_bucket未設定のためGCS削除をスキップ"]];
    }

    if (!class_exists("\\Google\\Cloud\\Storage\\StorageClient")) {
        return ["deleted" => $deleted, "errors" => ["google/cloud-storage未読込のためGCS削除をスキップ"]];
    }

    if (!empty($google_application_credentials)) {
        putenv("GOOGLE_APPLICATION_CREDENTIALS=" . $google_application_credentials);
    }

    try {
        $storage = new \Google\Cloud\Storage\StorageClient();
        $bucket  = $storage->bucket($gcs_bucket);
    } catch (Exception $e) {
        return ["deleted" => $deleted, "errors" => ["GCSクライアント初期化失敗: " . $e->getMessage()]];
    }

    // ── 1. gs:// URI を直接削除 ──
    $all_paths = [];
    if (!empty($full_file_Paths_str)) {
        foreach (explode('|,|', $full_file_Paths_str) as $p) {
            $all_paths[] = trim($p);
        }
    }
    if (!empty($img_urls_str)) {
        foreach (explode('|,|', $img_urls_str) as $p) {
            $all_paths[] = trim($p);
        }
    }

    foreach ($all_paths as $path) {
        if (!preg_match('#^gs://([^/]+)/(.+)$#', $path, $m)) {
            continue; // ローカルパスやS3パスはスキップ
        }
        $obj_bucket = $m[1];
        $obj_name   = $m[2];
        if ($obj_bucket !== $gcs_bucket) {
            continue; // 別バケットはスキップ
        }
        try {
            $bucket->object($obj_name)->delete();
            $deleted[] = $path;
        } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
            // 既に存在しない場合は無視
        } catch (Exception $e) {
            $errors[] = $path . ": " . $e->getMessage();
        }
    }

    // ── 2. プレフィックスによる一括削除 ──
    // PDFファイル: {ai_case_id}-{fileKey}.pdf
    // 変換済み画像: converted/{ai_case_id}-{fileKey}-page*.png
    $prefixes = [
        (string)$ai_case_id . "-",
        "converted/" . $ai_case_id . "-",
        // 2026-10-14 追加: pdf-converter の旧リビジョンが作っていた中間ファイル
        //   converted/converted_{ai_case_id}-{fileKey}-NNN_mini.png
        //   は上の前方一致（converted/{id}-）に一致せず削除されずに残っていた。
        //   2026-08-27 以降は生成されないが、過去分が GCS に残存するため対象に加える。
        "converted/converted_" . $ai_case_id . "-",
    ];

    foreach ($prefixes as $prefix) {
        try {
            $objects = $bucket->objects(['prefix' => $prefix]);
            foreach ($objects as $object) {
                $name = $object->name();
                try {
                    $object->delete();
                    $deleted[] = "gs://{$gcs_bucket}/{$name}";
                } catch (\Google\Cloud\Core\Exception\NotFoundException $e) {
                    // 既に削除済み
                } catch (Exception $e) {
                    $errors[] = "gs://{$gcs_bucket}/{$name}: " . $e->getMessage();
                }
            }
        } catch (Exception $e) {
            $errors[] = "prefix={$prefix}: " . $e->getMessage();
        }
    }

    return ["deleted" => $deleted, "errors" => $errors];
}
