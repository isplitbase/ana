<?php
require_once './common.php';
require_once './system_log_helper.php';
require_once __DIR__ . '/stripe_config.php';
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/stripe_customer_helper.php';

// 共通レスポンス関数
function respond($status, $message, $extra = []) {
    http_response_code(200);
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

// リクエストデータ取得
$contentType = $_SERVER["CONTENT_TYPE"] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents("php://input"), true);
} else {
    $data = $_POST;
}

// DB
global $conn;

// パラメータ
$key = $data['key'] ?? '';

if (!$key) {
    respond('error', '認証が失敗しました（キーがありません）');
}

// loginkey作成
$str = '1234567890abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPUQRSTUVWXYZ';
$str_r = substr(str_shuffle($str), 0, 20);
$loginkey = date('YmdHis') . $str_r;

// IPとユーザーエージェント
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$param_json = json_encode([
    'ip' => $ip,
    'user_agent' => $user_agent
], JSON_UNESCAPED_UNICODE);

// check_codeと一致するユーザーを検索
$stmt = $conn->prepare("
    SELECT user_id, username, email, create_at, update_at, status, company_id, company_cd, company_name, auth, bumon_id, billing_status
    FROM users
    WHERE check_code = ?
    LIMIT 1
");
$stmt->bind_param("s", $key);
$stmt->execute();
$result = $stmt->get_result();

if (!($row = $result->fetch_assoc())) {
    $stmt->close();
    respond('error', '認証に失敗しました（キーが無効です）');
}
$stmt->close();

$user_id        = (int)$row['user_id'];
$username       = (string)$row['username'];
$email          = (string)$row['email'];
$create_at      = $row['create_at'];
$company_id     = (int)($row['company_id'] ?? -1);
$company_cd     = (string)($row['company_cd'] ?? '');
$company_name   = (string)($row['company_name'] ?? '');
$auth           = (int)($row['auth'] ?? 0);
$user_bumon_id  = (int)($row['bumon_id'] ?? 0);
$billing_status = (string)($row['billing_status'] ?? '');
$user_status    = (int)($row['status'] ?? 0);

// -----------------------------
// ★ 認証リンクの有効期限（24時間）
// check_code の先頭14桁は発行日時（analygent_create_user.php の generate_check_code: date('YmdHis')）。
// 読めない場合は仮登録の更新日時（無ければ作成日時）を発行日時とみなす。
//
// 例外：本登録済み（status > 0）でカード登録が未完了（email_verified / pending_payment）の企業は、
// フロントがログインを通さず「メール認証リンクから再開」と案内しているため、期限後も再開を許す。
// （ここで止めると、ログインも再登録もできなくなる）
// -----------------------------
$link_ttl_seconds = 24 * 60 * 60;
$can_resume_card_register = ($user_status > 0 && in_array($billing_status, ['email_verified', 'pending_payment'], true));

if (!$can_resume_card_register) {
    $issued_ts = false;
    if (preg_match('/^(\d{14})/', (string)$key, $m)) {
        $issued_dt = DateTime::createFromFormat('YmdHis', $m[1]);
        if ($issued_dt !== false) {
            $issued_ts = $issued_dt->getTimestamp();
        }
    }
    if ($issued_ts === false) {
        $issued_ts = strtotime((string)($row['update_at'] ?? $row['create_at'] ?? ''));
    }

    if ($issued_ts === false || (time() - $issued_ts) > $link_ttl_seconds) {
        if ($user_status > 0) {
            respond('error', '認証リンクの有効期限（24時間）が切れています。登録は完了していますので、ログイン画面からログインしてください。', [
                'error_code' => 'LINK_EXPIRED',
            ]);
        }
        respond('error', '認証リンクの有効期限（24時間）が切れています。お手数ですが、企業登録画面からもう一度登録してください（同じメールアドレスで登録できます）。', [
            'error_code' => 'LINK_EXPIRED',
        ]);
    }
}

// -----------------------------
// ★ auth=300 以外は利用不可
// -----------------------------
if ($auth !== 300) {
    respond('error', 'このAPIは管理者（auth=300）のみ利用できます。', [
        'user_id'    => $user_id,
        'company_id' => $company_id,
        'auth'       => $auth,
        'email'      => $email
    ]);
}

// company_id が無いのは致命的
if ($company_id < 0) {
    respond('error', 'company_id が不正です。', [
        'user_id'    => $user_id,
        'company_id' => $company_id,
        'auth'       => $auth,
        'email'      => $email
    ]);
}

// -----------------------------
// ★ 企業マスター（user_id = company_id）のみ利用可能
// このAPIはメール認証リンク経由の初回登録フロー専用。
// サブユーザー（user_id != company_id）は対象外。
// -----------------------------
if ($user_id !== $company_id) {
    respond('error', 'このAPIは企業マスターアカウントのみ利用できます。', [
        'user_id'    => $user_id,
        'company_id' => $company_id,
    ]);
}

// billing_status は企業マスター自身の値を使う（user_id = company_id が確定済み）

// ============================================================
// Stripe Customer 作成 + card_register_token 発行
// ※ トランザクション外で Stripe API を呼ぶ（ロックを最小化）
// billing_status='pending_email' の会社管理者（auth=300）のみ対象
// ============================================================
$card_register_token      = null;
$card_register_expires_at = null;

// pending_email: Stripe Customer作成 + token発行
// email_verified / pending_payment / payment_failed: token再発行のみ（カード登録途中離脱ユーザーの再開を許可）
$needs_token = in_array($billing_status, ['pending_email', 'email_verified', 'pending_payment', 'payment_failed'], true);

if ($needs_token) {

    $stripe_env  = defined('STRIPE_ENV') ? STRIPE_ENV : 'test';
    $api_key     = ($stripe_env === 'live') ? STRIPE_API_KEY_LIVE : STRIPE_API_KEY_SANDBOX;

    // Stripe Customer作成はpending_emailのみ（初回のみ）
    if ($billing_status === 'pending_email') {
        // ---- ensure_valid_stripe_customer ヘルパーを利用 ----
        // ・既存レコードなし → 新規 Customer を作成 + stripe_customers に INSERT
        // ・既存レコードあり & Stripe で有効 → そのまま返す
        // ・既存レコードあり & Stripe で無効（旧アカウント残骸など）→ 新 Customer 作成 + 既存行を UPDATE
        try {
            $stripe_customer_id = ensure_valid_stripe_customer($conn, (int)$company_id, $stripe_env);
            error_log("analygent_create_user_chcek: ensure_valid_stripe_customer OK - company_id={$company_id} customer={$stripe_customer_id}");
        } catch (Throwable $e) {
            error_log("analygent_create_user_chcek: ensure_valid_stripe_customer 失敗 - company_id={$company_id} error=" . $e->getMessage());
            respond('error', 'Stripe顧客の作成・確認に失敗しました。しばらく時間をおいて再度お試しください。');
        }
    } // end if ($billing_status === 'pending_email')

    // ---- card_register_token を生成 ----
    $card_register_token      = bin2hex(random_bytes(32)); // 64文字
    $card_register_expires_at = date('Y-m-d H:i:s', time() + 86400); // 24時間後
}

// ============================================================
// DB トランザクション
// ============================================================
$conn->begin_transaction();

try {
    // login_history に記録
    $stmt_insert = $conn->prepare("
        INSERT INTO login_history (user_id, loginkey, create_at, status, param)
        VALUES (?, ?, NOW(), 'OK', ?)
    ");
    $stmt_insert->bind_param("iss", $user_id, $loginkey, $param_json);
    $stmt_insert->execute();
    $stmt_insert->close();

    // users.status を 100 に更新
    $stmt_update = $conn->prepare("
        UPDATE users SET status = 100, update_user_id = NULL WHERE user_id = ?
    ");
    $stmt_update->bind_param("i", $user_id);
    $stmt_update->execute();
    $stmt_update->close();

    // ---- billing_status を email_verified に更新（pending_email のときだけ）----
    // user_id = company_id であることは上で確認済みだが、
    // 念のため両方を条件に含めて企業マスターのみを更新する。
    if ($billing_status === 'pending_email') {
        $stmt_bs = $conn->prepare("
            UPDATE users
            SET billing_status = 'email_verified'
            WHERE user_id    = ?
              AND company_id = ?
              AND billing_status = 'pending_email'
        ");
        $stmt_bs->bind_param("ii", $company_id, $company_id);
        $stmt_bs->execute();
        $stmt_bs->close();
    }

    // -----------------------------
    // ★ default部門「本社」を作成（すでに存在すれば作らない）
    // -----------------------------
    $bumon_created   = 0;
    $honsha_bumon_id = null;

    $stmt_bumon_sel = $conn->prepare("
        SELECT bumon_id
        FROM bumon
        WHERE company_id = ?
          AND bumon_name = '本社'
        LIMIT 1
    ");
    $stmt_bumon_sel->bind_param("i", $company_id);
    $stmt_bumon_sel->execute();
    $res_bumon_sel = $stmt_bumon_sel->get_result();
    $bumon_row = $res_bumon_sel ? $res_bumon_sel->fetch_assoc() : null;
    $stmt_bumon_sel->close();

    if ($bumon_row && isset($bumon_row['bumon_id'])) {
        $honsha_bumon_id = (int)$bumon_row['bumon_id'];
    } else {
        $stmt_bumon_ins = $conn->prepare("
            INSERT INTO bumon (bumon_name, create_at, update_at, company_id, update_user_id, create_user_id)
            VALUES ('本社', NOW(), NOW(), ?, ?, ?)
        ");
        $stmt_bumon_ins->bind_param("iii", $company_id, $user_id, $user_id);
        $stmt_bumon_ins->execute();
        $stmt_bumon_ins->close();

        $honsha_bumon_id = (int)$conn->insert_id;
        $bumon_created   = 1;
    }

    // ✅ ユーザに本社部門をセット（未設定のときだけ）
    $bumon_set = 0;
    if ($honsha_bumon_id > 0 && $user_bumon_id <= 0) {
        $stmt_set_bumon = $conn->prepare("
            UPDATE users
            SET bumon_id = ?, update_at = NOW(), update_user_id = NULL
            WHERE user_id = ?
        ");
        $stmt_set_bumon->bind_param("ii", $honsha_bumon_id, $user_id);
        $stmt_set_bumon->execute();
        $stmt_set_bumon->close();
        $bumon_set     = 1;
        $user_bumon_id = $honsha_bumon_id;
    }

    // ---- card_register_token をDBに保存（既存の未消費トークンは無効化）----
    if ($card_register_token !== null) {
        // 既存の未消費トークンを一括無効化
        $stmt_inv = $conn->prepare("
            UPDATE card_register_tokens
            SET consumed_at = NOW()
            WHERE company_id   = ?
              AND consumed_at IS NULL
        ");
        $stmt_inv->bind_param("i", $company_id);
        $stmt_inv->execute();
        $stmt_inv->close();

        // 新しいトークンを挿入
        $stmt_tok = $conn->prepare("
            INSERT INTO card_register_tokens (company_id, token, expires_at, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt_tok->bind_param("iss", $company_id, $card_register_token, $card_register_expires_at);
        if (!$stmt_tok->execute()) {
            error_log("analygent_create_user_chcek: card_register_tokens INSERT 失敗 - " . $stmt_tok->error);
            throw new RuntimeException('card_register_tokens INSERT 失敗: ' . $stmt_tok->error);
        }
        $stmt_tok->close();

        error_log("analygent_create_user_chcek: card_register_token 発行 - company_id={$company_id} expires={$card_register_expires_at}");
    }

    // コミット
    $conn->commit();

    write_system_log($conn, 'info', 'TENANT', 'TENANT_CREATED', 'テナントが作成されました',
        tenant_id:  $company_id > 0 ? $company_id : null,
        user_id:    $user_id,
        username:   $username ?: $email,
        ip_address: $ip,
        detail:     [
            'company_id'   => $company_id,
            'company_cd'   => $company_cd,
            'company_name' => $company_name,
            'bumon_created' => $bumon_created,
            'default_bumon_id' => $honsha_bumon_id,
        ]
    );

    // ---- レスポンス ----
    $extra = [
        'loginkey'   => $loginkey,
        'name'       => $username,
        'email'      => $email,
        'create_at'  => $create_at,
        'company_id' => $company_id,
        'company_cd' => $company_cd,
        'auth'       => $auth,

        // 部門情報
        'default_bumon_created' => $bumon_created,
        'default_bumon_id'      => $honsha_bumon_id,
        'default_bumon_name'    => '本社',
        'user_bumon_set'        => $bumon_set,
        'user_bumon_id'         => $user_bumon_id,

        // billing_status
        'billing_status' => ($billing_status === 'pending_email') ? 'email_verified' : $billing_status,
    ];

    // カード登録トークン（pending_email の会社管理者のみ）
    if ($card_register_token !== null) {
        $extra['card_register_token']      = $card_register_token;
        $extra['card_register_expires_at'] = $card_register_expires_at;
    }

    respond('success', '認証ができました', $extra);

} catch (Throwable $e) {
    $conn->rollback();
    error_log("analygent_create_user_chcek: DB例外 - " . $e->getMessage());
    respond('error', '認証処理中にエラーが発生しました');
}
?>
