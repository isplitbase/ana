<?php
/**
 * ana_password_helper.php
 *
 * users.password の保存・照合と、企業登録の入力チェックをまとめた共通関数。
 * 関数定義のみで、直接呼ばれても何も出力しない。
 *
 * 保存:
 * - ana_pw_hash() で password_hash(PASSWORD_DEFAULT) した値を保存する。
 *
 * 照合（移行を兼ねる）:
 * - ana_pw_verify_and_upgrade()
 *   保存値がハッシュ形式 → password_verify
 *   平文（ハッシュ化以前に保存された値）→ hash_equals で比較し、
 *   一致したらその場でハッシュに置き換える（利用者にパスワード変更は求めない）。
 * - パスワードは最大50文字のため、平文がハッシュ形式（60文字以上）と誤判定されることはない。
 *
 * メールに載せる画面のリンク:
 * - ana_front_url() で環境に合わせた URL を作る（本番 8012 は $front、それ以外は $testfront）。
 */

/**
 * メールに載せる画面のリンク（例: ana_front_url('auth/activate?key=...')）。
 * common.php の $port で環境を見分ける。$front は両環境とも本番の URL なので、
 * 本番（8012）以外は $testfront（https://corp.analygent.com:<port>/）を使う。
 */
function ana_front_url(string $path): string {
    global $port, $front, $testfront;
    $base = ((int)$port === 8012) ? (string)$front : (string)$testfront;
    if ($base === '') {
        $base = 'https://corp.analygent.com/';
    }
    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

/** フリーメールのドメイン（フロント company-register.ts の FREE_EMAIL_DOMAINS と同じ一覧） */
const ANA_FREE_EMAIL_DOMAINS = [
    'gmail.com',
    'googlemail.com',
    'yahoo.com',
    'yahoo.co.jp',
    'outlook.com',
    'hotmail.com',
    'live.com',
    'icloud.com',
    'me.com',
    'aol.com',
    'proton.me',
    'protonmail.com',
    'gmx.com',
    'gmx.net',
    'zoho.com',
];

function ana_pw_hash(string $plain): string {
    return password_hash($plain, PASSWORD_DEFAULT);
}

/** 保存値が password_hash() 形式か */
function ana_pw_is_hashed(?string $stored): bool {
    if ($stored === null || $stored === '') {
        return false;
    }
    return (password_get_info($stored)['algoName'] ?? 'unknown') !== 'unknown';
}

/**
 * 入力パスワードを保存値と照合する。
 * 平文の保存値と一致した場合や、ハッシュ方式が古い場合は、その場でハッシュに置き換える。
 * 置き換えに失敗してもログインは通す（次回のログインで再度置き換える）。
 */
function ana_pw_verify_and_upgrade(mysqli $conn, int $user_id, string $plain, ?string $stored): bool {
    $stored = (string)$stored;
    if ($user_id <= 0 || $plain === '' || $stored === '') {
        return false;
    }

    if (ana_pw_is_hashed($stored)) {
        if (!password_verify($plain, $stored)) {
            return false;
        }
        if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            ana_pw_replace_stored($conn, $user_id, $stored, ana_pw_hash($plain));
        }
        return true;
    }

    // ハッシュ化以前の平文
    if (!hash_equals($stored, $plain)) {
        return false;
    }
    ana_pw_replace_stored($conn, $user_id, $stored, ana_pw_hash($plain));
    return true;
}

/**
 * 保存値を置き換える。照合したときの値のままの行だけを更新する
 * （照合と同時にパスワードが変更されていた場合に、古いパスワードで上書きしないため）。
 * update_at / update_user_id は利用者の操作ではないので変えない
 * （update_at は ON UPDATE CURRENT_TIMESTAMP のため、update_at = update_at で自動更新を止める）。
 */
function ana_pw_replace_stored(mysqli $conn, int $user_id, string $old_stored, string $new_hash): void {
    try {
        $stmt = $conn->prepare("UPDATE users SET password = ?, update_at = update_at WHERE user_id = ? AND password = ? LIMIT 1");
        if (!$stmt) {
            error_log("ana_pw_replace_stored: prepare failed user_id={$user_id}");
            return;
        }
        $stmt->bind_param('sis', $new_hash, $user_id, $old_stored);
        $stmt->execute();
        $stmt->close();
    } catch (Throwable $e) {
        error_log("ana_pw_replace_stored: user_id={$user_id} " . $e->getMessage());
    }
}

function ana_email_domain(string $email): string {
    $email = strtolower(trim($email));
    $pos = strrpos($email, '@');
    if ($pos === false) {
        return '';
    }
    return substr($email, $pos + 1);
}

function ana_is_free_email_domain(string $domain): bool {
    return in_array(strtolower(trim($domain)), ANA_FREE_EMAIL_DOMAINS, true);
}

/**
 * パスワード条件（フロント company-register.ts と同じ）。満たさなければエラーメッセージ、満たせば null。
 * - 8〜50文字、半角英数字記号のみ（空白不可）
 * - 英字・数字・記号をそれぞれ1文字以上（記号 = 英数字以外の半角記号すべて）
 */
function ana_pw_policy_error(string $password): ?string {
    if (strlen($password) > 50) {
        return 'パスワードは50文字以内で入力してください';
    }
    if (strlen($password) < 8) {
        return 'パスワードは8文字以上で入力してください';
    }
    if (!preg_match('/^[\x21-\x7E]+$/', $password)) {
        return 'パスワードは半角英数字記号のみで入力してください';
    }
    if (!preg_match('/[A-Za-z]/', $password)
        || !preg_match('/[0-9]/', $password)
        || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'パスワードには英字・数字・記号をそれぞれ含めてください';
    }
    return null;
}
