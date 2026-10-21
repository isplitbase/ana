#!/bin/bash
# ============================================================================
# apply_bulk_8012.sh — 本番(8012)へ「まとめてアップロード」を配置する
#
# 使い方（ローカルから / サーバ上では bash apply_bulk_8012.sh ）:
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' < deploy/apply_bulk_8012.sh
#
# 前提:
#   /tmp/bulkdeploy/ に配置対象のファイル（下の FILES）が置かれていること
#   （bulk_*.php / bulk_cron.sh / index.html / bulk_upload_schema.sql）
#
# 行うこと:
#   1. 配置対象の存在確認と構文チェック
#   2. sapis へ bulk_*.php と bulk_cron.sh を配置
#   3. manyup/ に動作確認画面を配置
#   4. dbwindow に bulk_batches / bulk_batch_files を作成
#
# 行わないこと:
#   既存ファイルの上書き、既存テーブルの変更。
#   新規追加のみのため、既存機能には影響しない。
#   DDL は CREATE TABLE IF NOT EXISTS のみで、再実行しても安全。
# ============================================================================
set -e

S=/tmp/bulkdeploy
D12=/srv/www/apps/analygent_backend/releases/20251117-0946_8012

FILES="bulk_analyze.php bulk_common.php bulk_cron.sh bulk_detect.php bulk_direct.php
       bulk_upload_add.php bulk_upload_approve.php bulk_upload_close.php
       bulk_upload_init.php bulk_upload_review.php bulk_upload_status.php bulk_upload_failures.php bulk_upload_pending.php
       bulk_worker.php index.html bulk_upload_schema.sql"

echo "--- 1/5 事前確認 ---"
for f in $FILES; do
    [ -f "$S/$f" ] || { echo "ありません: $S/$f"; exit 1; }
done
for f in "$S"/bulk_*.php; do php -l "$f" >/dev/null || exit 1; done
bash -n "$S/bulk_cron.sh"
[ -d "$D12/sapis" ] || { echo "配置先がありません: $D12/sapis"; exit 1; }
echo "$(echo $FILES | wc -w)ファイル / 構文チェック OK"

echo "--- 2/5 配置前の状態を記録 ---"
BK="/srv/www/apps/analygent_backend/releases/bulk_backup-8012-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
ls -la "$D12/sapis/" > "$BK/sapis_before.txt"
ls -la "$D12/"       > "$BK/root_before.txt"
# 万一同名ファイルが既にあれば退避する（通常は存在しない）
for f in $FILES; do
    [ -f "$D12/sapis/$f" ] && cp -p "$D12/sapis/$f" "$BK/" || true
done
echo "記録先: $BK"

echo "--- 3/5 PHP配置 ---"
cp -p "$S"/bulk_*.php   "$D12/sapis/"
cp -p "$S/bulk_cron.sh" "$D12/sapis/"
chmod 0755 "$D12/sapis/bulk_cron.sh"
ls -la "$D12/sapis/" | grep -i bulk

echo "--- 4/5 動作確認画面を配置 ---"
mkdir -p "$D12/manyup"
cp -p "$S/index.html" "$D12/manyup/index.html"
ls -la "$D12/manyup/"

echo "--- 5/5 テーブル作成（dbwindow）---"
cat > "$S/apply_schema.php" <<'PHPEOF'
<?php
// 本番(dbwindow)に まとめてアップロード用テーブルを作成する。
// CREATE TABLE IF NOT EXISTS のみ。既存テーブルは変更しない。
$pf = '/srv/www/apps/analygent_backend/internal/for_8012/pf';
$j  = json_decode(file_get_contents($pf), true);
$c  = new mysqli($j['host'] ?? 'localhost', $j['username'], $j['password'], $j['dbname']);
if ($c->connect_error) { fwrite(STDERR, "接続失敗: {$c->connect_error}\n"); exit(1); }
$sql = file_get_contents('/tmp/bulkdeploy/bulk_upload_schema.sql');
if (!$c->multi_query($sql)) { fwrite(STDERR, "失敗: {$c->error}\n"); exit(1); }
do { if ($r = $c->store_result()) { $r->free(); } } while ($c->more_results() && $c->next_result());
if ($c->error) { fwrite(STDERR, "失敗: {$c->error}\n"); exit(1); }
echo "適用しました（{$j['dbname']}）\n";
foreach (['bulk_batches', 'bulk_batch_files'] as $t) {
    $r = $c->query("SHOW TABLES LIKE '$t'");
    echo "  $t : " . ($r && $r->num_rows ? 'あり' : 'なし') . "\n";
}
PHPEOF
php "$S/apply_schema.php"

echo
echo "============================================================"
echo "完了しました。"
echo "  https://corp.analygent.com:8012/manyup/"
echo "を開いてください（接続先は自動で「本番」になります）。"
echo
echo "loginkey は 8012 の画面にログインして取得したものを使ってください。"
echo "  ※ 8012(dbwindow) と 8056(dbtest1) はDBが別です。"
echo
echo "期判定と解析はワーカーが行います。cron は未登録のため、"
echo "アップロード後に下記を実行してワーカーを起動してください。"
echo "  $D12/sapis/bulk_cron.sh"
echo
echo "常時動かす場合は crontab に追記します（毎分）。"
echo "  * * * * * $D12/sapis/bulk_cron.sh"
echo "============================================================"
