<?php
/**
 * bulk_direct.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 承認せず（mode=D）のバッチを処理する。bulk_worker.php から読み込んで使う。
 * Webから直接叩くものではない。
 *
 * 1案件分ずつ、通常画面のアップロードと同じ getpdfinfo.php をサーバから呼んで登録する。
 * 期判定・案件作成・画像変換の起動はすべて getpdfinfo.php（→ upload_files.php）が
 * 行うので、案件は通常画面からアップロードしたものと全く同じになる（案件名も元のファイル名）。
 * 案件は「帳票識別中」になり、以後は通常の流れ。
 *
 * 1案件の単位:
 *   case_group なし … 1ファイル＝1案件（一覧からまとめて選んだ場合）
 *   case_group あり … 同じ組のファイル（最大3本）＝1案件（ドラッグ＆ドロップ1回）。
 *                     getpdfinfo.php が今期・前期・前々期を振り分ける（通常画面で
 *                     複数ファイルを入れたときと同じ）
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
function bulk_call_getpdfinfo(string $loginkey, array $pdfs, int $port): array {
    // ブラウザと同じ公開名で呼ぶ（BULK_PUBLIC_HOST の説明を参照）
    $url = 'https://' . BULK_PUBLIC_HOST . ':' . $port . '/sapis/getpdfinfo.php';

    // $pdfs = [ ['path' => ローカルの一時ファイル, 'name' => 元のファイル名], ... ]（最大3本＝1案件）
    // 通常画面と同じく files[] で送る。添字を付けないと PHP の配列キーが重なるため files[0] 形式にする。
    $fields = ['loginkey' => $loginkey];
    foreach (array_values($pdfs) as $i => $p) {
        $fields["files[{$i}]"] = new CURLFile($p['path'], 'application/pdf', $p['name']);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
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

/**
 * 次に登録する1案件分のファイルを取り出す（承認せず専用）。
 *
 * case_group が無いファイルは1本で1案件。case_group があるファイル（ドラッグ＆ドロップで
 * まとめて入れたもの）は、同じ組を全部まとめて取り出す。組の一部だけを別々のワーカーが
 * 取ると、1案件のはずが別々の案件になってしまうため、組は1回の UPDATE でまとめて押さえる
 * （同じ UPDATE が同時に走っても、先に押さえた側以外は0件になる）。
 *
 * @return array|null  取り出した行（seq 順）。無ければ null
 */
function bulk_claim_direct_unit(mysqli $conn, string $batchId): ?array {
    $pid = getmypid();
    $bid = $conn->real_escape_string($batchId);

    for ($try = 0; $try < 10; $try++) {
        $c = $conn->query("SELECT id, case_group FROM bulk_batch_files
                            WHERE batch_id = '{$bid}' AND status = '" . BULK_F_RECEIVED . "'
                            ORDER BY seq LIMIT 1");
        $cand = $c ? $c->fetch_assoc() : null;
        if (!$cand) { return null; }

        $set = "status = '" . BULK_F_DETECTING . "', worker_pid = {$pid}, update_at = now()";
        if ($cand['case_group'] === null) {
            $conn->query("UPDATE bulk_batch_files SET {$set}
                           WHERE id = " . (int)$cand['id'] . " AND status = '" . BULK_F_RECEIVED . "'");
            $pick = "id = " . (int)$cand['id'];
        } else {
            $g = (int)$cand['case_group'];
            $conn->query("UPDATE bulk_batch_files SET {$set}
                           WHERE batch_id = '{$bid}' AND case_group = {$g} AND status = '" . BULK_F_RECEIVED . "'");
            $pick = "batch_id = '{$bid}' AND case_group = {$g}";
        }
        if ($conn->affected_rows < 1) { continue; }   // 他のワーカーに先を越された。次の候補へ

        $res = $conn->query("SELECT * FROM bulk_batch_files
                              WHERE {$pick} AND worker_pid = {$pid} AND status = '" . BULK_F_DETECTING . "'
                              ORDER BY seq");
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : null;
    }
    return null;
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
    while ($loginkey !== null && ($unit = bulk_claim_direct_unit($conn, $batchId)) !== null) {
        $did++;
        $seqs  = implode(',', array_column($unit, 'seq'));
        $names = implode(' / ', array_column($unit, 'original_name'));

        // 1案件分（単独なら1本、まとめた組なら最大3本）を落として、まとめて getpdfinfo.php へ
        $pdfs = [];
        try {
            foreach ($unit as $t) {
                $pdfs[] = ['path' => bulk_download_gcs($t['gcs_path']), 'name' => $t['original_name']];
            }
            $r = bulk_call_getpdfinfo($loginkey, $pdfs, $port);
        } catch (Throwable $e) {
            $r = ['ok' => false, 'ai_case_id' => null, 'error' => '受付ファイルを読めませんでした: ' . $e->getMessage()];
        } finally {
            foreach ($pdfs as $p) { @unlink($p['path']); }
        }

        if (!$r['ok']) {
            // 受付ファイルは残す（入れ直しや原因調査に使うため）。組の全員を同じ理由で失敗にする
            foreach ($unit as $t) {
                bulk_update_file($conn, (int)$t['id'], [
                    'status'        => BULK_F_ERROR,
                    'error_message' => mb_substr((string)$r['error'], 0, 1000),
                ]);
            }
            bulk_log("DIRECT NG batch_id={$batchId} seq={$seqs} name={$names} {$r['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        foreach ($unit as $t) {
            bulk_update_file($conn, (int)$t['id'], [
                'status'        => BULK_F_DONE,
                'ai_case_id'    => (int)$r['ai_case_id'],
                'error_message' => '',
            ]);
            // 案件は upload_files.php が自前の複製（<案件ID>-<キー>.pdf）を持つので、受付ファイルは不要
            bulk_delete_gcs($t['gcs_path']);
        }
        bulk_log("DIRECT OK batch_id={$batchId} seq={$seqs} case={$r['ai_case_id']} name={$names}");
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
