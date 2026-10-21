<?php
/**
 * hash_existing_passwords.php — users.password に残っている平文を password_hash に置き換える
 *
 * 使い方（サーバ上で。sapis の外に置いて実行する）:
 *   php hash_existing_passwords.php 8056            … 件数の確認だけ（書き換えない）
 *   php hash_existing_passwords.php 8056 --apply    … 置き換える
 *
 * - 環境 8056 は DB dbtest1、8012 は DB dbwindow でなければ中止する。
 * - ana_password_helper.php の ana_pw_replace_stored() を使う
 *   （照合した値のままの行だけ更新・update_at は変えない）。ログイン時の置き換えと同じ処理。
 * - パスワードの値は表示も保存もしない。元に戻す手段は用意しない（ログインはこれまでどおりできる）。
 * - 2026-10-21 開発(8056)で尚さんの判断により実行。本番(8012)で使うかは配置後に判断する。
 */
$env = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
$expected_db = ['8056' => 'dbtest1', '8012' => 'dbwindow'][$env] ?? null;
if ($expected_db === null) {
    fwrite(STDERR, "使い方: php hash_existing_passwords.php <8056|8012> [--apply]\n");
    exit(1);
}

chdir("/srv/www/apps/analygent_backend/releases/$env/sapis");
require_once './common.php';
require_once './ana_password_helper.php';
global $conn;

$db = $conn->query("SELECT DATABASE() d")->fetch_assoc()['d'];
if ($db !== $expected_db) {
    fwrite(STDERR, "DB が想定と違うので中止: env=$env db=$db（想定 $expected_db）\n");
    exit(1);
}

function count_kinds(mysqli $conn): array {
    $n = ['ハッシュ' => 0, '平文' => 0, '空' => 0];
    $r = $conn->query("SELECT password FROM users");
    while ($c = $r->fetch_assoc()) {
        $pw = (string)$c['password'];
        $n[$pw === '' ? '空' : (ana_pw_is_hashed($pw) ? 'ハッシュ' : '平文')]++;
    }
    return $n;
}

echo "env=$env db=$db\n";
echo "実行前: " . json_encode(count_kinds($conn), JSON_UNESCAPED_UNICODE) . "\n";
if (!$apply) {
    echo "確認のみ（--apply で置き換える）\n";
    exit(0);
}

$targets = [];
$r = $conn->query("SELECT user_id, password FROM users WHERE password IS NOT NULL AND password <> ''");
while ($c = $r->fetch_assoc()) {
    if (!ana_pw_is_hashed($c['password'])) {
        $targets[] = [(int)$c['user_id'], (string)$c['password']];
    }
}
$ok = 0;
$ng = [];
$sel = $conn->prepare("SELECT password FROM users WHERE user_id = ?");
foreach ($targets as [$uid, $plain]) {
    ana_pw_replace_stored($conn, $uid, $plain, ana_pw_hash($plain));
    // 読み直して、元のパスワードで照合できるか（= 今までどおりログインできるか）
    $sel->bind_param('i', $uid);
    $sel->execute();
    $now = (string)($sel->get_result()->fetch_assoc()['password'] ?? '');
    if (ana_pw_is_hashed($now) && password_verify($plain, $now)) {
        $ok++;
    } else {
        $ng[] = $uid;
    }
}
$sel->close();
unset($targets, $plain);
echo "置き換え: 照合OK {$ok} 件" . ($ng ? " / 要確認 user_id=" . implode(',', $ng) : '') . "\n";

echo "実行後: " . json_encode(count_kinds($conn), JSON_UNESCAPED_UNICODE) . "\n";
