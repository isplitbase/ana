<?php
/**
 * ai_case_list の検索・ページング・レスポンス構築を行う共通関数
 *
 * @param mysqli $conn
 * @param int    $user_id      ログインユーザのuser_id
 * @param int    $row_start    1始まり
 * @param int|null $row_end    null = 最大3000件
 * @param string|null $search_text  null = 条件なし
 * @return array  json_encode 可能なレスポンス配列
 */
function build_ai_case_list_response($conn, $user_id, $row_start, $row_end, $search_text) {

    $like_text = $search_text !== null ? '%' . $search_text . '%' : null;

    if ($row_start < 1) $row_start = 1;
    if ($row_end !== null && $row_end < $row_start) $row_end = $row_start;

    // ユーザ情報取得（username / company_id / delete_pdf_timing_cd / delete_pdf_timing）
    $stmt_u = $conn->prepare("
        SELECT me.username, me.company_id, company.delete_pdf_timing_cd, dpt.delete_pdf_timing
        FROM users me
        LEFT JOIN users company ON me.company_id = company.user_id
        LEFT JOIN delete_pdf_timings dpt ON company.delete_pdf_timing_cd = dpt.delete_pdf_timing_cd
        WHERE me.user_id = ?
    ");
    $stmt_u->bind_param("i", $user_id);
    $stmt_u->execute();
    $user_row = $stmt_u->get_result()->fetch_assoc();

    $delete_pdf_timing_cd = $user_row['delete_pdf_timing_cd'] ?? null;
    $delete_pdf_timing    = $user_row['delete_pdf_timing']    ?? null;
    $my_company_id        = $user_row['company_id']           ?? null;

    // delete_pdf_timings テーブルの全データ
    $stmt_t = $conn->prepare("SELECT delete_pdf_timing_cd, delete_pdf_timing FROM delete_pdf_timings");
    $stmt_t->execute();
    $result_t = $stmt_t->get_result();
    $delete_pdf_timings = [];
    while ($r = $result_t->fetch_assoc()) {
        $delete_pdf_timings[] = [
            "delete_pdf_timing_cd" => $r['delete_pdf_timing_cd'],
            "delete_pdf_timing"    => $r['delete_pdf_timing'],
        ];
    }

    // 全件数を取得（同じ会社 / DELETED除外）
    if ($search_text !== null) {
        $stmt_count = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM ai_case ac
            INNER JOIN users uc ON ac.user_id = uc.user_id
            WHERE uc.company_id = ?
              AND ac.status != 'DELETED'
              AND ac.upload_file_name LIKE ?
        ");
        $stmt_count->bind_param("is", $my_company_id, $like_text);
    } else {
        $stmt_count = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM ai_case ac
            INNER JOIN users uc ON ac.user_id = uc.user_id
            WHERE uc.company_id = ?
              AND ac.status != 'DELETED'
        ");
        $stmt_count->bind_param("i", $my_company_id);
    }
    $stmt_count->execute();
    $total_rows = (int)$stmt_count->get_result()->fetch_assoc()['cnt'];

    // LIMIT / OFFSET
    $offset = $row_start - 1;
    $limit  = ($row_end !== null) ? ($row_end - $row_start + 1) : 3000;
    if ($limit > 3000) $limit = 3000;

    // ai_case 取得
    if ($search_text !== null) {
        $stmt_list = $conn->prepare("
            SELECT
                ac.ai_case_id,
                ac.user_id,
                ac.case_name,
                ac.status,
                ac.update_at,
                ac.create_at,
                ac.update_user_id,
                uc.username AS create_user_name,
                u2.username AS update_user_name
            FROM ai_case ac
            INNER JOIN users uc ON ac.user_id = uc.user_id
            LEFT JOIN users u2 ON ac.update_user_id = u2.user_id
            WHERE uc.company_id = ?
              AND ac.status != 'DELETED'
              AND ac.upload_file_name LIKE ?
            ORDER BY ac.create_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt_list->bind_param("isii", $my_company_id, $like_text, $limit, $offset);
    } else {
        $stmt_list = $conn->prepare("
            SELECT
                ac.ai_case_id,
                ac.user_id,
                ac.case_name,
                ac.status,
                ac.update_at,
                ac.create_at,
                ac.update_user_id,
                uc.username AS create_user_name,
                u2.username AS update_user_name
            FROM ai_case ac
            INNER JOIN users uc ON ac.user_id = uc.user_id
            LEFT JOIN users u2 ON ac.update_user_id = u2.user_id
            WHERE uc.company_id = ?
              AND ac.status != 'DELETED'
            ORDER BY ac.create_at DESC
            LIMIT ? OFFSET ?
        ");
        $stmt_list->bind_param("iii", $my_company_id, $limit, $offset);
    }
    $stmt_list->execute();
    $result_list = $stmt_list->get_result();

    $ai_case_list = [];
    while ($re = $result_list->fetch_assoc()) {
        $status_db     = $re['status'] ?? '';
        $update_at_val = $re['update_at'];
        $delete_at     = null;

        switch ($delete_pdf_timing_cd) {
            case 'A':
                if (!empty($update_at_val)) {
                    $dt = new DateTime($update_at_val);
                    $dt->modify('+24 hours');
                    $delete_at = $dt->format('Y-m-d H時');
                }
                break;
            // B / C は、実際に削除するバッチ（internal/for_*/batch_delete_expired.py）と同じ 30日 / 365日で計算する
            case 'B':
                if (!empty($update_at_val)) {
                    $dt = new DateTime($update_at_val);
                    $dt->modify('+30 days');
                    $delete_at = $dt->format('Y-m-d H時');
                }
                break;
            case 'C':
                if (!empty($update_at_val)) {
                    $dt = new DateTime($update_at_val);
                    $dt->modify('+365 days');
                    $delete_at = $dt->format('Y-m-d H時');
                }
                break;
            case 'D':
            default:
                $delete_at = '-';
                break;
        }

        $ai_case_list[] = [
            "ai_case_id"       => (int)$re['ai_case_id'],
            "status_db"        => $status_db,
            "status"           => _ai_case_convert_status($status_db),
            "case_name"        => $re['case_name'] ?? '',
            "update_at"        => $re['update_at'],
            "upload_at"        => $re['create_at'],
            "delete_at"        => $delete_at,
            "update_user_id"   => $re['update_user_id'] !== null ? (int)$re['update_user_id'] : null,
            // 作成者のID（削除・案件名変更の権限は作成者で判定するため。名前は同姓同名がありえる）
            "create_user_id"   => $re['user_id'] !== null ? (int)$re['user_id'] : null,
            "create_user_name" => $re['create_user_name'] ?? null,
            "update_user_name" => (!empty($re['update_user_id']))
                                    ? ($re['update_user_name'] ?? null)
                                    : ($user_row['username'] ?? null),
        ];
    }

    return [
        "status"               => "success",
        "total_rows"           => $total_rows,
        "row_start"            => $row_start,
        "row_end"              => ($row_end !== null) ? $row_end : ($row_start + count($ai_case_list) - 1),
        "delete_pdf_timing_cd" => $delete_pdf_timing_cd,
        "delete_pdf_timing"    => $delete_pdf_timing,
        "delete_pdf_timings"   => $delete_pdf_timings,
        "ai_case_list"         => $ai_case_list,
    ];
}

function _ai_case_convert_status(string $status_db): string {
    if (in_array($status_db, ['INIT', 'UPLD', 'MakeImageStart'], true)) return '画像変換中';
    if ($status_db === 'UPST') return '帳票識別中';
    if (in_array($status_db, ['IMGTYPE', 'IMED'], true)) return '帳票識別完了';
    if (in_array($status_db, ['AIST', '1000'], true)) return 'OCR読取中';
    if (in_array($status_db, ['AIOK','FNOK', 'FNST'], true)) return 'OCR読取完了';
    if ($status_db === 'AIERR') return 'OCR読取失敗';
    if ($status_db === '2000') return '財務分析済';
    if (in_array($status_db, ['5006', '7006'], true)) return '処理エラー';
    if (in_array($status_db, ['5000', '5001'], true)) return 'AI診断中';
    if (in_array($status_db, ['7098', '7099'], true)) return 'CF分析中';
    if ($status_db === '7000') return 'AI診断済';
    if ($status_db === '7100') return 'CF分析済';
    if ($status_db === '7200') return 'CF分析済／AI診断済';
    return '開発中';
}