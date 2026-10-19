<?php
/**
 * bulk_detect.php
 * ─────────────────────────────────────────────────────────────────────────────
 * 期判定（ana-getpdfinfo の呼び出し）と整合チェック。
 * ワーカーから読み込んで使う。Webから直接叩くものではない。
 */

/**
 * ana-getpdfinfo を 1ファイルで呼び、期ラベルと年度を得る。
 *
 * 【1案件=1ファイル前提】1ファイルずつ判定している。
 * このAPIは「渡されたファイル群を1社の複数期」とみなして
 * 今期/前期/前々期を割り当てるため、別会社を混ぜると誤判定する。
 * 逆に言えば、複数ファイルを1案件にまとめる場合は、同じ case_group の
 * ファイルをまとめて渡すのが本来の使い方になる（files / file_names に複数）。
 *
 * @return array{ok:bool, labels:array, years:array, reason:string, error:?string}
 */
function bulk_detect_period(string $gcsUri, string $originalName, int $port): array {
    $base = ($port == 8012)
        ? 'https://ana-getpdfinfo-real-512697354748.asia-northeast1.run.app'
        : 'https://ana-getpdfinfo-512697354748.asia-northeast1.run.app';

    $idToken = bulk_get_id_token($base);
    if (!$idToken) {
        return ['ok' => false, 'labels' => [], 'years' => [], 'reason' => '', 'error' => 'IDトークンの取得に失敗しました'];
    }

    $payload = [
        'files'      => [$gcsUri],
        'file_names' => [$originalName],
    ];

    // コールドスタート対策。先に軽いリクエストでインスタンスを起こす。
    // /convert の件と同じく、0台のときに即 500 で弾かれることがあるため。
    bulk_warmup($base, $idToken);

    $maxAttempts = 3;
    $lastErr = null;
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init($base . '/v1/zlite-getpdfinfo');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', "Authorization: Bearer {$idToken}"],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 300,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno === 0 && $code === 200) {
            $j = json_decode((string)$body, true);
            $res = $j['result_json']['results'][0] ?? null;
            if (!is_array($res)) {
                return ['ok' => false, 'labels' => [], 'years' => [], 'reason' => '', 'error' => '判定結果が空でした'];
            }
            $labels = $res['labels'] ?? [];
            if (!is_array($labels)) { $labels = [(string)$labels]; }
            $years = $res['年度'] ?? [];
            if (!is_array($years)) { $years = [(string)$years]; }
            return [
                'ok'     => true,
                'labels' => array_values(array_map('strval', $labels)),
                'years'  => array_values(array_map('strval', $years)),
                'reason' => (string)($res['reason'] ?? ''),
                'error'  => null,
            ];
        }

        $lastErr = "http={$code} errno={$errno}";
        // 4xx は再送しない（認証エラーやリクエスト不正は再送しても同じ）
        if ($code >= 400 && $code < 500 && $code !== 429) { break; }
        if ($attempt < $maxAttempts) { sleep(2 * $attempt); }
    }

    return ['ok' => false, 'labels' => [], 'years' => [], 'reason' => '', 'error' => '期判定に失敗しました（' . $lastErr . '）'];
}

/** Cloud Run のインスタンスを起こす。失敗しても本処理は続行する。 */
function bulk_warmup(string $baseUrl, string $idToken, int $timeoutSec = 20): void {
    $ch = curl_init(rtrim($baseUrl, '/') . '/health');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$idToken}"],
        CURLOPT_TIMEOUT        => $timeoutSec,
        CURLOPT_NOSIGNAL       => 1,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * ラベルの並びから postingPeriod を決める。
 *   P1 = 今期のみ（単一期）
 *   P2 = 今期 + 前期
 *   P3 = 前期 + 前々期
 * 既存 upload_files.php / getpdfinfo.php と同じ区分に合わせている。
 */
function bulk_posting_period(array $labels): ?string {
    $has = function (string $x) use ($labels) { return in_array($x, $labels, true); };
    if ($has('今期') && $has('前期'))   { return 'P2'; }
    if ($has('前期') && $has('前々期')) { return 'P3'; }
    if ($has('今期'))                   { return 'P1'; }
    return null;
}

/**
 * 和暦の「令和7年度」などから西暦の年を得る。取れなければ null。
 */
function bulk_wareki_to_year(string $text): ?int {
    $map = ['令和' => 2018, '平成' => 1988, '昭和' => 1925];
    foreach ($map as $era => $base) {
        if (mb_strpos($text, $era) !== false) {
            $t = str_replace('元年', '1年', $text);
            if (preg_match('/' . $era . '\s*(\d+)\s*年/u', $t, $m)) {
                return $base + (int)$m[1];
            }
        }
    }
    if (preg_match('/(19|20)\d{2}/', $text, $m)) { return (int)$m[0]; }
    return null;
}

/**
 * 整合チェック。
 *
 * 取得できるのは「年度」までで決算日(日付)は得られないため、
 * 日付単位の照合は行わず次の4点を見る。
 *
 *   UNDETECTED     判定できなかった（ラベルまたは年度が空）
 *   YEAR_RANGE     年度が未来、または20年以上前
 *   LABEL_CONFLICT 同じラベルが重複している（今期が2つ等）
 *   DUPLICATE      バッチ内に同じファイル名＋同じ年度が既にある
 *
 * @param array $seen  これまでに見た「ファイル名|年度」のキー（参照渡しで更新する）
 * @return array{result:string, code:?string, message:?string}
 */
function bulk_check_consistency(array $labels, array $years, string $originalName, array &$seen): array {
    if (!$labels || !$years) {
        return ['result' => 'NG', 'code' => 'UNDETECTED',
                'message' => '帳票の期を判定できませんでした。確認画面で期を指定してください。'];
    }

    if (count($labels) !== count(array_unique($labels))) {
        return ['result' => 'WARN', 'code' => 'LABEL_CONFLICT',
                'message' => '同じ期が重複して判定されました（' . implode('・', $labels) . '）。内容をご確認ください。'];
    }

    $thisYear = (int)date('Y');
    foreach ($years as $y) {
        $yy = bulk_wareki_to_year($y);
        if ($yy === null) {
            return ['result' => 'WARN', 'code' => 'UNDETECTED',
                    'message' => '年度を読み取れませんでした（' . $y . '）。'];
        }
        if ($yy > $thisYear + 1) {
            return ['result' => 'WARN', 'code' => 'YEAR_RANGE',
                    'message' => '未来の年度が読み取られました（' . $y . '）。別の資料が混ざっていないかご確認ください。'];
        }
        if ($yy < $thisYear - 20) {
            return ['result' => 'WARN', 'code' => 'YEAR_RANGE',
                    'message' => '20年以上前の年度が読み取られました（' . $y . '）。'];
        }
    }

    $key = $originalName . '|' . implode(',', $years);
    if (isset($seen[$key])) {
        return ['result' => 'WARN', 'code' => 'DUPLICATE',
                'message' => '同じファイル名・同じ年度が #' . $seen[$key] . ' にもあります。二重アップロードの可能性があります。'];
    }

    return ['result' => 'OK', 'code' => null, 'message' => null];
}
