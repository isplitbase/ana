<?php
/**
 * bulk_direct.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 承認せず（mode=D）のバッチを処理する。bulk_worker.php から読み込んで使う。
 * Webから直接叩くものではない。
 *
 * 1ファイルずつ、通常画面のアップロードと同じ getpdfinfo.php をサーバから呼び、
 * 1ファイル1案件として登録する。期判定・案件作成・画像変換の起動はすべて
 * getpdfinfo.php（→ upload_files.php）が行うので、案件は通常画面から
 * アップロードしたものと全く同じになる（案件名も元のファイル名）。
 * 案件は「帳票識別中」になり、以後は通常の流れ。
 *
 * ファイルの状態:
 *   RECEIVED → DETECTING（登録中）→ DONE（ai_case_id あり）/ ERROR（error_message あり）
 *
 * 失敗したファイルは案件が作られないため、案件一覧には出ない。
 * 画面は bulk_upload_status.php（detail: true） か bulk_upload_failures.php で検知する。
 */

/**
 * getpdfinfo.php を、通常画面と同じ形（files[] と loginkey の multipart）で呼ぶ。
 *
 * 成否は ai_case_id が返ったかで判定する。getpdfinfo.php は、upload_files.php への
 * 転送に失敗しても HTTP 200 のまま upload_files_error を入れて返すことがあるため、
 * HTTP コードだけでは判定できない。
 *
 * 自動の再送はしない。送った後に応答だけが失われた場合、再送すると同じ案件が
 * 2件できてしまうため（通常画面のアップロードも再送はしない）。
 *
 * @return array{ok:bool, ai_case_id:?int, error:?string}
 */
function bulk_call_getpdfinfo(string $loginkey, string $localPdf, string $originalName, int $port): array {
    // ブラウザと同じ公開名で呼ぶ（BULK_PUBLIC_HOST の説明を参照）
    $url = 'https://' . BULK_PUBLIC_HOST . ':' . $port . '/sapis/getpdfinfo.php';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'loginkey' => $loginkey,
            'files[]'  => new CURLFile($localPdf, 'application/pdf', $originalName),
        ],
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT        => 900,   // Apache Timeout / max_execution_time と同じ
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'ai_case_id' => null, 'error' => "登録APIの呼び出しに失敗しました（errno={$errno}）"];
    }
    $j = json_decode((string)$body, true);
    $caseId = is_array($j) ? (int)($j['ai_case_id'] ?? 0) : 0;
    if ($caseId > 0) {
        return ['ok' => true, 'ai_case_id' => $caseId, 'error' => null];
    }

    $msg = '';
    if (is_array($j)) {
        $msg = (string)($j['message'] ?? $j['upload_files_error'] ?? $j['detail'] ?? '');
    }
    if ($msg === '') { $msg = mb_substr((string)$body, 0, 200); }
    return ['ok' => false, 'ai_case_id' => null, 'error' => "登録に失敗しました（http={$code}）: {$msg}"];
}

/** GCS のオブジェクトをローカルの一時ファイルに落とす。呼び出し側で消すこと。 */
function bulk_download_gcs(string $gcsUri): string {
    if (!preg_match('#^gs://([^/]+)/(.+)$#', $gcsUri, $m)) {
        throw new RuntimeException('GCS のURIが不正です: ' . $gcsUri);
    }
    if (!empty($GLOBALS['google_application_credentials'])) {
        putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $GLOBALS['google_application_credentials']);
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bulk_direct_');
    $storage = new \Google\Cloud\Storage\StorageClient();
    $storage->bucket($m[1])->object($m[2])->downloadToFile($tmp);
    return $tmp;
}

/** GCS のオブジェクトを消す。失敗しても処理は止めない（ログだけ残す）。 */
function bulk_delete_gcs(string $gcsUri): void {
    if (!preg_match('#^gs://([^/]+)/(.+)$#', $gcsUri, $m)) { return; }
    try {
        if (!empty($GLOBALS['google_application_credentials'])) {
            putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $GLOBALS['google_application_credentials']);
        }
        $storage = new \Google\Cloud\Storage\StorageClient();
        $storage->bucket($m[1])->object($m[2])->delete();
    } catch (Throwable $e) {
        bulk_log("GCS_DELETE_NG {$gcsUri} " . $e->getMessage());
    }
}

/**
 * 処理中のまま止まったファイルを失敗にする（承認せず専用）。
 *
 * 承認ありの方式は取り残しを元の状態に戻して拾い直すが、こちらは戻さない。
 * getpdfinfo.php を呼んだ後にワーカーが落ちた場合、案件が既にできている
 * 可能性があり、拾い直すと同じ案件が2件できてしまうため。
 * 失敗として残し、案件一覧を確かめたうえで入れ直してもらう。
 */
function bulk_fail_stale_direct(mysqli $conn, string $batchId): void {
    $bid = $conn->real_escape_string($batchId);
    $res = $conn->query(
        "SELECT id, seq, worker_pid FROM bulk_batch_files
          WHERE batch_id = '{$bid}' AND status = '" . BULK_F_DETECTING . "'
            AND update_at < DATE_SUB(now(), INTERVAL " . BULK_STALE_SECONDS . " SECOND)"
    );
    while ($res && ($row = $res->fetch_assoc())) {
        if (bulk_pid_alive(isset($row['worker_pid']) ? (int)$row['worker_pid'] : null)) { continue; }
        bulk_update_file($conn, (int)$row['id'], [
            'status'        => BULK_F_ERROR,
            'error_message' => '登録の途中で処理が中断しました。案件一覧に登録されていないか確認し、無ければ入れ直してください。',
        ]);
        bulk_log("DIRECT STALE batch_id={$batchId} seq={$row['seq']} pid={$row['worker_pid']} を失敗にしました");
    }
}

/**
 * 承認せずのバッチを処理する。
 *
 * @return bool このワーカーが1件以上処理したか（false なら別のバッチを探しに行く）
 */
function bulk_phase_direct(mysqli $conn, array $batch, int $port, bool $dryRun): bool {
    $batchId = $batch['batch_id'];

    if ($dryRun) {
        $res = $conn->query("SELECT seq, original_name FROM bulk_batch_files
                              WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                                AND status = '" . BULK_F_RECEIVED . "' ORDER BY seq");
        while ($t = $res->fetch_assoc()) { echo "  [dry-run] 承認せず #{$t['seq']} {$t['original_name']}\n"; }
        return false;
    }

    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_DETECTING,
        'message' => '案件を登録しています。',
    ]);
    bulk_fail_stale_direct($conn, $batchId);

    $loginkey = bulk_find_loginkey($conn, (int)$batch['user_id']);
    if ($loginkey === null) {
        // ログインが切れていると getpdfinfo.php を呼べない。残りを全部失敗にする。
        $conn->query("UPDATE bulk_batch_files
                         SET status = '" . BULK_F_ERROR . "',
                             error_message = 'ログイン情報が見つかりませんでした。再度ログインしてから入れ直してください。',
                             update_at = now()
                       WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                         AND status = '" . BULK_F_RECEIVED . "'");
        bulk_log("DIRECT NO_LOGINKEY batch_id={$batchId} user_id={$batch['user_id']}");
    }

    $did = 0;
    while ($loginkey !== null
           && ($t = bulk_claim_file($conn, $batchId, BULK_F_RECEIVED, BULK_F_DETECTING)) !== null) {
        $did++;
        $id = (int)$t['id'];

        $tmp = null;
        try {
            $tmp = bulk_download_gcs($t['gcs_path']);
            $r = bulk_call_getpdfinfo($loginkey, $tmp, $t['original_name'], $port);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'ai_case_id' => null, 'error' => '受付ファイルを読めませんでした: ' . $e->getMessage()];
        } finally {
            if ($tmp !== null) { @unlink($tmp); }
        }

        if (!$r['ok']) {
            // 受付ファイルは残す（入れ直しや原因調査に使うため）
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$r['error'], 0, 1000),
            ]);
            bulk_log("DIRECT NG batch_id={$batchId} seq={$t['seq']} name={$t['original_name']} {$r['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        bulk_update_file($conn, $id, [
            'status'        => BULK_F_DONE,
            'ai_case_id'    => (int)$r['ai_case_id'],
            'error_message' => '',
        ]);
        // 案件は upload_files.php が自前の複製（<案件ID>-<キー>.pdf）を持つので、受付ファイルは不要
        bulk_delete_gcs($t['gcs_path']);
        bulk_log("DIRECT OK batch_id={$batchId} seq={$t['seq']} case={$r['ai_case_id']} name={$t['original_name']}");
        bulk_refresh_counts($conn, $batchId);
    }

    // ── 締め ──
    bulk_refresh_counts($conn, $batchId);
    if (bulk_count_files($conn, $batchId, [BULK_F_RECEIVED, BULK_F_DETECTING]) > 0) {
        bulk_log("DIRECT PARTIAL batch_id={$batchId}（他のワーカーが処理中）");
        return $did > 0;
    }

    $r = $conn->query("SELECT SUM(status = '" . BULK_F_DONE . "') AS ok, SUM(status = '" . BULK_F_ERROR . "') AS ng
                         FROM bulk_batch_files WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'");
    $cnt = $r->fetch_assoc();
    $ok = (int)($cnt['ok'] ?? 0);
    $ng = (int)($cnt['ng'] ?? 0);

    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_DONE,
        'message' => $ng > 0
            ? "案件の登録が終わりました（{$ok} 件登録、{$ng} 件失敗）。"
            : "案件の登録が終わりました（{$ok} 件）。",
    ]);
    bulk_log("DIRECT END batch_id={$batchId} ok={$ok} ng={$ng}");
    return $did > 0;
}
