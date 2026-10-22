#!/bin/bash
# ============================================================================
# apply_delete_bulk_8012.sh — 本番(8012)へ次の2つを配置する
#   A. 案件の一括削除で応答が返らない問題の修正（コミット ea42838 と同じ内容）
#      delete_cases.php（入れ替え）／_delete_gcs_helper.php・delete_cases_gcs.php（新規）
#   B. まとめてアップロード
#      bulk_detect.php（期区分を決められないファイルを NG にする。af724d4）
#      bulk_upload_pending.php（確認待ちバッチを取り直す API、新規。9f668e5）
#
# 使い方（ローカルのリポジトリ直下から）:
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 "rm -rf /tmp/delbulk_8012 && mkdir -p /tmp/delbulk_8012"
#   scp -i ~/.ssh/isplit.pem sapis/8012/delete_cases.php sapis/8012/_delete_gcs_helper.php \
#       sapis/8012/delete_cases_gcs.php sapis/bulk/bulk_detect.php sapis/bulk/bulk_upload_pending.php \
#       isplit@34.180.79.93:/tmp/delbulk_8012/
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' < deploy/apply_delete_bulk_8012.sh
#
# 行うこと:
#   1. 送ったファイルが想定どおりか（md5）と php -l
#   2. 本番の現行ファイルが修正前の版か（md5）、新規ファイルがまだ無いか。違えば何もせず中止
#   3. 現行ファイルを releases/delbulk-8012-<日時>/ に退避（sapis の外）
#   4. 配置して php -l、Web から呼べないもの・GET を断るものを curl で確認
#
# 戻し方:
#   cp -p <退避先>/delete_cases.php <退避先>/bulk_detect.php /srv/www/apps/analygent_backend/releases/8012/sapis/
#   rm /srv/www/apps/analygent_backend/releases/8012/sapis/{_delete_gcs_helper.php,delete_cases_gcs.php,bulk_upload_pending.php}
# DB は変更しない。
# ============================================================================
set -e

S=/tmp/delbulk_8012
REL=/srv/www/apps/analygent_backend/releases
D=$(readlink -f "$REL/8012")/sapis

# 送るファイルの md5
declare -A NEW=(
    [delete_cases.php]=c8887a13b3e112c2a5f91773b2e21869
    [_delete_gcs_helper.php]=1e180da9ec6de49f939636fe4e86c9d1
    [delete_cases_gcs.php]=2e45b28eb06ef4158e05e5fa9aa92212
    [bulk_detect.php]=69541cdc4a1ce5a260358d615f67c524
    [bulk_upload_pending.php]=1cfe17f500f4f757bd1d2c4daf2e39f9
)
# 入れ替えるファイルの、修正前の md5（本番がこれでなければ中止）
declare -A OLD=(
    [delete_cases.php]=4359bddf9c4880be662574dd69e57c56
    [bulk_detect.php]=dd209d7e1868958e36ba32b714b776e9
)
ADDED="_delete_gcs_helper.php delete_cases_gcs.php bulk_upload_pending.php"

md5() { md5sum "$1" | cut -d' ' -f1; }

echo "=== 配置先: $D ==="
echo "--- 1/4 送ったファイル"
for f in "${!NEW[@]}"; do
    [ -f "$S/$f" ] || { echo "ありません: $S/$f"; exit 1; }
    [ "$(md5 "$S/$f")" = "${NEW[$f]}" ] || { echo "送ったファイルが想定と違います: $f"; exit 1; }
    php -l "$S/$f" >/dev/null || { echo "構文エラー: $f"; exit 1; }
done
echo "OK"

echo "--- 2/4 本番の現行ファイル"
for f in "${!OLD[@]}"; do
    [ "$(md5 "$D/$f")" = "${OLD[$f]}" ] || { echo "中止: 本番の $f が修正前の版ではありません（誰かが変更した可能性）"; exit 1; }
done
for f in $ADDED; do
    [ -e "$D/$f" ] && { echo "中止: 本番に $f が既にあります"; exit 1; }
done
echo "OK"

echo "--- 3/4 退避"
BK=$REL/delbulk-8012-$(date +%Y%m%d-%H%M%S)
mkdir -p "$BK"
for f in "${!OLD[@]}"; do cp -p "$D/$f" "$BK/"; done
chmod o-rwx "$BK"
echo "退避先: $BK"

echo "--- 4/4 配置"
for f in "${!OLD[@]}"; do
    cat "$S/$f" > "$D/$f"          # 所有者・権限はそのまま、中身だけ入れ替える
done
for f in $ADDED; do
    cp "$S/$f" "$D/$f"
    chmod 644 "$D/$f"
done
for f in "${!NEW[@]}"; do
    php -l "$D/$f" >/dev/null || { echo "配置後に構文エラー: $f（退避先から戻してください）"; exit 1; }
    [ "$(md5 "$D/$f")" = "${NEW[$f]}" ] || { echo "配置後の md5 が違います: $f"; exit 1; }
done

U=https://corp.analygent.com:8012/sapis
echo "delete_cases_gcs.php（Web から呼べないこと 404）: $(curl -s -o /dev/null -w '%{http_code}' -X POST $U/delete_cases_gcs.php)"
echo "bulk_upload_pending.php（GET は断る 405）: $(curl -s -o /dev/null -w '%{http_code}' $U/bulk_upload_pending.php)"
echo "bulk_upload_pending.php（loginkey 無し 401）: $(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{}' $U/bulk_upload_pending.php)"
echo "delete_cases.php（loginkey 無し → 異常アクセス）: $(curl -s -X POST -H 'Content-Type: application/json' -d '{}' $U/delete_cases.php | head -c 80)"

rm -rf "$S"
echo "=== 完了 ==="
