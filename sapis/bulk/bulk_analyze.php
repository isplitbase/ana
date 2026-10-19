<?php
/**
 * bulk_analyze.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 承認済みバッチの解析フェーズ。bulk_worker.php から読み込んで使う。
 * Webから直接叩くものではない。
 *
 * 1案件あたりの流れ（既存の画面操作と同じ順序）:
 *   1. pdf-converter /convert   PDF→画像変換。img_urls と imgtype_request が入る
 *   2. do_ai.php                読取（OCR）の開始。帳票種類は変換時の自動判定をそのまま使う
 *   3. 読取の完了を待つ          do_ai.php は Python を裏に投げるだけで待たないため、
 *                               案件の状態が読取中を抜けるまでポーリングする
 *   4. get_ai_response.php      読取結果の確定。ポイント消費もここで行われる
 *
 * 財務分析・CF分析は do_ai.php の後続で行われる設計のため、
 * ここでは読取までを担当する。
 * ポイント消費も既存APIがそのまま行う（通常の解析と同じ扱い）。
 */

/**
 * pdf-converter を呼んで画像変換する。
 * 変換は1案件あたり数十秒〜数分かかるため、応答を最後まで待つ。
 * （Web側の fire-and-forget と違い、ワーカーは待てる）
 */
function bulk_convert_pdf(array $case, int $port): array {
    $base = ($port == 8012)
        ? 'https://pdf-converter-real-512697354748.asia-northeast1.run.app'
        : 'https://pdf-converter-512697354748.asia-northeast1.run.app';

    $idToken = bulk_get_id_token($base);
    if (!$idToken) {
        return ['ok' => false, 'error' => 'IDトークンの取得に失敗しました'];
    }

    // コールドスタート対策。0台のとき /convert が即 500 で弾かれることがある。
    bulk_warmup($base, $idToken);

    $payload = [
        'input_gs'         => array_values(array_filter(explode('|,|', (string)$case['full_file_Paths']))),
        'output_gs'        => 'gs://' . ($GLOBALS['gcs_bucket'] ?? 'analygent') . '/converted/',
        'upload_file_keys' => json_encode(array_values(array_filter(explode('|,|', (string)$case['upload_file_keys'])))),
        'ai_case_id'       => (string)$case['ai_case_id'],
        'mysql_host'       => '',
        'mysql_user'       => '',
        'mysql_password'   => '',
        'mysql_db'         => (string)($GLOBALS['dbname'] ?? ''),
        'port'             => $port,
        'read_sga'         => true,
        'read_mcr'         => true,
    ];

    $maxAttempts = 2;
    $lastErr = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($base . '/convert');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer {$idToken}"],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 1800,
            CURLOPT_NOSIGNAL       => 1,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === 0 && $code === 200) {
            $j = json_decode((string)$body, true);
            if (!empty($j['ok'])) {
                return ['ok' => true, 'images' => (int)($j['img_urls_count'] ?? 0), 'error' => null];
            }
            return ['ok' => false, 'error' => '変換が完了しませんでした: ' . mb_substr((string)$body, 0, 300)];
        }

        $lastErr = "http={$code} errno={$errno}";
        if ($code >= 400 && $code < 500 && $code !== 429) { break; }
        if ($attempt < $maxAttempts) { sleep(12); }   // コールドスタート実測11秒
    }
    return ['ok' => false, 'error' => '画像変換に失敗しました（' . $lastErr . '）'];
}

/**
 * 変換時に作られた imgtype_request から do_ai.php 用の images[] を組み立てる。
 * 帳票種類は自動判定（page_type.type）をそのまま採用する。
 */
function bulk_build_images_payload(array $imgtype): array {
    $typeToId = [];
    foreach (($imgtype['types'] ?? []) as $t) {
        if (isset($t['type'], $t['page_type_id'])) {
            $typeToId[$t['type']] = (int)$t['page_type_id'];
        }
    }
    // 判定不能だったページは「対象外」に倒す。読取対象から外れるだけで実害が無いため。
    $fallback = $typeToId['対象外'] ?? 2;

    $images = [];
    foreach (($imgtype['images'] ?? []) as $im) {
        $type = $im['page_type']['type'] ?? null;
        $id   = ($type !== null && isset($typeToId[$type])) ? $typeToId[$type] : $fallback;
        $images[] = [
            'upload_file_key' => $im['upload_file_key'] ?? '',
            'page_no'         => (int)($im['page_no'] ?? 0),
            'page_type_ids'   => [$id],
            'page_type_id'    => $id,
            'rotation'        => (int)($im['rotation'] ?? 0),
        ];
    }
    return $images;
}

/**
 * 同一サーバの do_ai.php を叩いて読取を実行する。
 * 既存APIをそのまま使うことで、ポイント消費や後続処理の分岐を再実装せずに済む。
 */
function bulk_call_do_ai(string $loginkey, int $aiCaseId, string $postingPeriod,
                         array $images, int $totalSetedAll, int $port): array {
    $url = "https://127.0.0.1:{$port}/sapis/do_ai.php";
    $payload = [
        'loginkey'        => $loginkey,
        'ai_case_id'      => $aiCaseId,
        'postingPeriod'   => $postingPeriod,
        'images'          => $images,
        'total_seted_all' => $totalSetedAll,
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) { return ['ok' => false, 'error' => "do_ai 呼出失敗 errno={$errno}"]; }
    $j = json_decode((string)$body, true);
    if ($code !== 200 || ($j['status'] ?? '') !== 'success') {
        return ['ok' => false, 'error' => "do_ai http={$code} " . mb_substr((string)$body, 0, 300)];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * 読取（Python）が終わるまで待つ。
 *
 * do_ai.php は Python を「& で裏に投げる」だけで、すぐ success を返す。
 * 読取結果を書き、status を AIOK にするのは、その裏で走る Python 側である。
 * そのため do_ai.php の応答を待っただけで get_ai_response.php を呼ぶと、
 * response がまだ空で「JSONデコードに失敗しました」となり AIERR になる。
 *
 * 画面では読取中の状態をポーリングして待っているため、ワーカーでも同じく待つ。
 * 判定は案件一覧の表示（_ai_case_list_helper.php の _ai_case_convert_status）
 * に合わせ、処理中を表す状態から抜けるまでを待ちとする。
 *
 * @return array{ok:bool, status:string, error:?string}
 */
function bulk_wait_for_read(mysqli $conn, int $aiCaseId, int $timeoutSec = 1200): array {
    // 読取が終わるまでに通過する状態（画像変換中〜OCR読取中）
    $inProgress = ['INIT', 'UPLD', 'MakeImageStart', 'UPST', 'IMGTYPE', 'IMED', 'AIST', '1000'];

    $deadline = time() + $timeoutSec;
    $last     = '';
    while (time() < $deadline) {
        $r   = $conn->query("SELECT status FROM ai_case WHERE ai_case_id = " . (int)$aiCaseId);
        $row = $r ? $r->fetch_assoc() : null;
        if (!$row) { return ['ok' => false, 'status' => '', 'error' => '案件が見つかりません']; }

        $last = (string)$row['status'];
        if (!in_array($last, $inProgress, true)) {
            return ['ok' => true, 'status' => $last, 'error' => null];
        }
        sleep(5);
    }
    return ['ok' => false, 'status' => $last,
            'error' => "読取が {$timeoutSec} 秒以内に終わりませんでした（状態={$last}）"];
}

/**
 * 人が確認した決算日と、読取AIが書類から読んだ決算日を照合する。
 *
 * 読取AI（callgpt.py）は ai_case.closing_date に今期の決算日を書く。
 * 1ファイル=1案件なので、これは確認画面で人が確定した決算日と同じ期を指すはず。
 * 食い違っても読取結果は残し、要確認として示すだけにする
 * （どちらが正しいかは人が書類を見て判断する）。
 *
 * @return array{read:?string, check:string}
 */
function bulk_compare_end_date(mysqli $conn, int $aiCaseId, ?string $confirmed): array {
    $r = $conn->query("SELECT closing_date FROM ai_case WHERE ai_case_id = " . (int)$aiCaseId);
    $row = $r ? $r->fetch_assoc() : null;
    $read = bulk_parse_date($row['closing_date'] ?? null);
    $conf = bulk_parse_date($confirmed);

    if ($conf === null || $read === null) {
        return ['read' => $read, 'check' => BULK_EDC_SKIP];
    }
    return ['read' => $read, 'check' => ($conf === $read) ? BULK_EDC_MATCH : BULK_EDC_MISMATCH];
}

/**
 * get_ai_response.php を叩いて読取結果を確定させる。
 *
 * do_ai.php は読取を開始して status='1000' にするだけで、
 * 結果の取り込み（status='FNOK'）とポイント消費は get_ai_response.php が行う
 * （do_ai.php のコメント「ポイント消費とusede追加は get_ai_response.php で実施」）。
 * 画面では読取後にポーリングで呼んでいる処理にあたる。
 */
function bulk_call_get_ai_response(string $loginkey, int $aiCaseId, int $port): array {
    $url = "https://127.0.0.1:{$port}/sapis/get_ai_response.php";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode([
            'loginkey'   => $loginkey,
            'ai_case_id' => $aiCaseId,
        ], JSON_UNESCAPED_UNICODE),
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_TIMEOUT        => 1800,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    $body  = curl_exec($ch);
    $errno = curl_errno($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) { return ['ok' => false, 'error' => "get_ai_response 呼出失敗 errno={$errno}"]; }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "get_ai_response http={$code} " . mb_substr((string)$body, 0, 300)];
    }

    // HTTP 200 だけでは成否を判定できない。
    // get_ai_response.php は読取に失敗したとき ai_case.status を AIERR にするが、
    // 応答は 200 のまま {"status":"error", ...} を返す作りになっている。
    // そのため 200 を成功とみなすと、画面には「完了」と出るのに中身は空、
    // という状態になる（2026-10-19 本番で9件中5件が該当）。
    $decoded = json_decode((string)$body, true);
    if (is_array($decoded) && (($decoded['status'] ?? '') === 'error')) {
        $msg = (string)($decoded['message'] ?? '読取に失敗しました');
        return ['ok' => false, 'error' => 'get_ai_response ' . mb_substr($msg, 0, 300)];
    }
    return ['ok' => true, 'error' => null];
}

/**
 * 読取が実際に成立したかを ai_case の状態で確かめる。
 *
 * 応答の形に依存せず、最後にDBの状態を見るのが確実なため、
 * bulk_call_get_ai_response の判定とは別に、もう一段確認する。
 * 完了とみなす状態は案件一覧の表示（_ai_case_list_helper.php）に合わせる。
 */
function bulk_case_read_ok(mysqli $conn, int $aiCaseId): array {
    $r = $conn->query("SELECT status, CHAR_LENGTH(COALESCE(response,'')) AS len
                         FROM ai_case WHERE ai_case_id = " . (int)$aiCaseId);
    $row = $r ? $r->fetch_assoc() : null;
    if (!$row) { return ['ok' => false, 'error' => '案件が見つかりません']; }

    $status = (string)$row['status'];
    if (!in_array($status, ['AIOK', 'FNOK', 'FNST'], true)) {
        return ['ok' => false, 'error' => "読取に失敗しました（案件の状態={$status}）"];
    }
    if ((int)$row['len'] <= 0) {
        return ['ok' => false, 'error' => '読取結果が空でした'];
    }
    return ['ok' => true, 'error' => null];
}

/** バッチのユーザーの有効な loginkey を取得する（ワーカーは画面を経由しないため） */
function bulk_find_loginkey(mysqli $conn, int $userId): ?string {
    $stmt = $conn->prepare(
        "SELECT lh.loginkey
           FROM login_history lh
          INNER JOIN users u ON u.user_id = lh.user_id
          WHERE lh.user_id = ? AND lh.status = 'OK' AND u.status > 0 AND u.status < 999
          ORDER BY lh.create_at DESC LIMIT 1"
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $r = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $r['loginkey'] ?? null;
}

// ─────────────────────────────────────────
// 解析フェーズ本体
// ─────────────────────────────────────────
function bulk_phase_analyze(mysqli $conn, array $batch, int $port, bool $dryRun): void {
    $batchId = $batch['batch_id'];
    $userId  = (int)$batch['user_id'];

    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_ANALYZING,
        'message' => '読取を実行しています。',
    ]);

    $loginkey = bulk_find_loginkey($conn, $userId);
    if ($loginkey === null) {
        bulk_update_batch($conn, $batchId, [
            'status'  => BULK_ST_ERROR,
            'message' => 'ログイン情報が見つかりませんでした。再度ログインしてからやり直してください。',
        ]);
        bulk_log("ANALYZE NO_LOGINKEY batch_id={$batchId} user_id={$userId}");
        return;
    }

    // 落ちたワーカーが掴んだままのファイルを戻してから始める。
    // 戻さないと bulk_claim_file が拾えず、バッチが完了しない。
    bulk_reclaim_stale($conn, $batchId, BULK_F_ANALYZING, BULK_F_APPROVED);

    $remain = bulk_count_files($conn, $batchId, [BULK_F_APPROVED, BULK_F_ANALYZING]);
    bulk_log("ANALYZE START batch_id={$batchId} files={$remain}");

    if ($dryRun) {
        $res = $conn->query("SELECT seq, ai_case_id FROM bulk_batch_files
                              WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'
                                AND status IN ('" . BULK_F_APPROVED . "','" . BULK_F_ANALYZING . "')
                              ORDER BY seq");
        while ($t = $res->fetch_assoc()) { echo "  [dry-run] #{$t['seq']} case={$t['ai_case_id']}\n"; }
        return;
    }

    // 解析は do_ai.php がこのVM上で Python を起動するため、同時実行数を抑える。
    // 他のワーカーが既に上限まで解析していれば、ここでは何もせず譲る。
    $selfPid = getmypid();
    if (bulk_count_live_analyzing($conn, $batchId, $selfPid) >= BULK_ANALYZE_PARALLEL) {
        bulk_log("ANALYZE BUSY batch_id={$batchId}（同時実行の上限 "
               . BULK_ANALYZE_PARALLEL . " に達しているため譲ります）");
        return;
    }

    // 1件ずつロックして取り出す
    //
    // 【1案件=1ファイル前提】ファイル単位で取り出し、そのファイルの案件を解析している。
    // 複数ファイルを1案件にまとめると、同じ ai_case_id を持つ行が複数できるため、
    // このままでは同じ案件を何度も解析してしまう。その場合は
    //   ・取り出しを ai_case_id 単位にする（同じ案件の行をまとめて ANALYZING にする）
    //   ・完了・失敗の記録も、同じ案件の行すべてに反映する
    // 必要がある（README 参照）。
    while (($t = bulk_claim_file($conn, $batchId, BULK_F_APPROVED, BULK_F_ANALYZING)) !== null) {
        $id       = (int)$t['id'];
        $aiCaseId = (int)$t['ai_case_id'];
        if ($aiCaseId <= 0) {
            bulk_update_file($conn, $id, ['status' => BULK_F_ERROR, 'error_message' => '案件が作られていません']);
            continue;
        }

        // ── 案件を取得 ──
        $r = $conn->query("SELECT * FROM ai_case WHERE ai_case_id = {$aiCaseId}");
        $case = $r ? $r->fetch_assoc() : null;
        if (!$case) {
            bulk_update_file($conn, $id, ['status' => BULK_F_ERROR, 'error_message' => '案件が見つかりません']);
            continue;
        }

        // ── 1. 画像変換 ──
        $conv = bulk_convert_pdf($case, $port);
        if (!$conv['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$conv['error'], 0, 1000),
            ]);
            bulk_log("ANALYZE CONVERT_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} {$conv['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }
        bulk_log("ANALYZE CONVERT_OK batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} images={$conv['images']}");

        // ── 2. 変換結果（帳票種類の自動判定）を読む ──
        $r = $conn->query("SELECT imgtype_request FROM ai_case WHERE ai_case_id = {$aiCaseId}");
        $imgtype = json_decode((string)($r->fetch_assoc()['imgtype_request'] ?? ''), true);
        if (!is_array($imgtype) || empty($imgtype['images'])) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => '帳票種類の判定結果が取得できませんでした',
            ]);
            bulk_log("ANALYZE IMGTYPE_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        $images        = bulk_build_images_payload($imgtype);
        $totalSetedAll = (int)($imgtype['total_seted_all'] ?? count($images));
        $period        = (string)($t['posting_period'] ?: ($imgtype['postingPeriod'] ?? 'P1'));

        // ── 3. 読取 ──
        $ai = bulk_call_do_ai($loginkey, $aiCaseId, $period, $images, $totalSetedAll, $port);
        if (!$ai['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$ai['error'], 0, 1000),
            ]);
            bulk_log("ANALYZE DOAI_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} {$ai['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        // ── 4. 読取（Python）が終わるまで待つ ──
        // do_ai.php は起動するだけで待たないため、ここで待たないと
        // 次の get_ai_response.php が空の response を読んで AIERR になる。
        $wait = bulk_wait_for_read($conn, $aiCaseId);
        if (!$wait['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$wait['error'], 0, 1000),
            ]);
            bulk_log("ANALYZE WAIT_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} {$wait['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }
        if ($wait['status'] === 'AIERR') {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => '読取に失敗しました（AIERR）',
            ]);
            bulk_log("ANALYZE READ_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} status=AIERR");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }
        bulk_log("ANALYZE READ_OK batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} status={$wait['status']}");

        // ── 5. 読取結果の確定（ポイント消費もここで行われる）──
        $fin = bulk_call_get_ai_response($loginkey, $aiCaseId, $port);
        if (!$fin['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$fin['error'], 0, 1000),
            ]);
            bulk_log("ANALYZE GETRES_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} {$fin['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        // ── 6. 読取が成立したかを案件の状態で確かめる ──
        $chk = bulk_case_read_ok($conn, $aiCaseId);
        if (!$chk['ok']) {
            bulk_update_file($conn, $id, [
                'status'        => BULK_F_ERROR,
                'error_message' => mb_substr((string)$chk['error'], 0, 1000),
            ]);
            bulk_log("ANALYZE READ_NG batch_id={$batchId} seq={$t['seq']} case={$aiCaseId} {$chk['error']}");
            bulk_refresh_counts($conn, $batchId);
            continue;
        }

        // ── 7. 決算日の照合（人が確認した決算日 と 読取AIが読んだ決算日）──
        $edc = bulk_compare_end_date($conn, $aiCaseId, $t['confirmed_end_date'] ?? null);

        bulk_update_file($conn, $id, [
            'status'         => BULK_F_DONE,
            'error_message'  => '',
            'read_end_date'  => $edc['read'],
            'end_date_check' => $edc['check'],
        ]);
        bulk_log("ANALYZE OK batch_id={$batchId} seq={$t['seq']} case={$aiCaseId}"
               . " end_check={$edc['check']} confirmed=" . ($t['confirmed_end_date'] ?? '-')
               . " read=" . ($edc['read'] ?? '-'));
        bulk_refresh_counts($conn, $batchId);
    }

    // ── 締め ──
    bulk_refresh_counts($conn, $batchId);

    // 未処理が残っていれば、他のワーカーがまだ解析中。状態は変えない。
    if (bulk_count_files($conn, $batchId, [BULK_F_APPROVED, BULK_F_ANALYZING]) > 0) {
        bulk_log("ANALYZE PARTIAL batch_id={$batchId}（他のワーカーが処理中）");
        return;
    }
    $r = $conn->query("SELECT SUM(status = '" . BULK_F_ERROR . "') AS err,
                              SUM(end_date_check = '" . BULK_EDC_MISMATCH . "') AS edc_ng
                         FROM bulk_batch_files
                        WHERE batch_id = '" . $conn->real_escape_string($batchId) . "'");
    $cnt = $r->fetch_assoc();
    $errCount = (int)($cnt['err'] ?? 0);
    $edcCount = (int)($cnt['edc_ng'] ?? 0);

    $notes = [];
    if ($errCount > 0) { $notes[] = "{$errCount} 件が失敗"; }
    if ($edcCount > 0) { $notes[] = "{$edcCount} 件で決算日が食い違い（要確認）"; }

    bulk_update_batch($conn, $batchId, [
        'status'  => BULK_ST_DONE,
        'message' => $notes ? '解析が終わりました（' . implode('、', $notes) . '）。' : '解析が終わりました。',
    ]);
    bulk_log("ANALYZE END batch_id={$batchId} errors={$errCount} end_date_mismatch={$edcCount}");
}
