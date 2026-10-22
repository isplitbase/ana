#!/bin/bash
# ============================================================================
# apply_supplement_fix.sh — 末平さんの補足指摘 (1)(2)(3)①(4)(5)(6)(8)(9) の修正を配置する（コミット 25b46d0）
#
# 使い方（ローカルのリポジトリ直下から。ENV は 8012 か 8056）:
#   ENV=8056
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 "rm -rf /tmp/supfix_$ENV && mkdir -p /tmp/supfix_$ENV"
#   scp -i ~/.ssh/isplit.pem sapis/$ENV/do_ai.php sapis/$ENV/get_ai_response.php sapis/$ENV/upload_files.php \
#       sapis/$ENV/_ai_case_list_helper.php sapis/$ENV/get_tenant_usage_history.php \
#       sapis/$ENV/admin_otp.php sapis/$ENV/analygent_login_verify.php isplit@34.180.79.93:/tmp/supfix_$ENV/
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 "bash -s $ENV" < deploy/apply_supplement_fix.sh
#
# 行うこと:
#   1. 配置するファイルが想定どおりか（md5）と php -l
#   2. 配置先の現行ファイルが修正前の版か（md5）。違えば何もせず中止
#   3. 現行ファイルを releases/supfix-<ENV>-<日時>/ に退避（sapis の外）
#   4. 配置して php -l
#
# 戻し方: cp -p <退避先>/*.php /srv/www/apps/analygent_backend/releases/<ENV>/sapis/
# DB は変更しない。
# ============================================================================
set -e

ENV="${1:-}"
case "$ENV" in
    8012|8056) ;;
    *) echo "使い方: bash -s 8012 または bash -s 8056"; exit 1 ;;
esac

S=/tmp/supfix_$ENV
REL=/srv/www/apps/analygent_backend/releases
D=$(readlink -f "$REL/$ENV")/sapis
FILES="do_ai.php get_ai_response.php upload_files.php _ai_case_list_helper.php
       get_tenant_usage_history.php admin_otp.php analygent_login_verify.php"

# "修正前 修正後" の md5（do_ai.php / upload_files.php は環境で内容が違う）
declare -A MD5
if [ "$ENV" = 8012 ]; then
    MD5[do_ai.php]="36b53260e0edc8db76318fc67d62397b a8a06c72e253d0c21137f1f85ea34cbf"
    MD5[upload_files.php]="ca711fdc1e37e3ed1adb6d5078a3125d 14be001a5b64b707e13058b57e9d46b4"
else
    MD5[do_ai.php]="447db3d98a1ba1802f41d9806181146c 2e9ebc3d5d459b82f7af6fead7fec411"
    MD5[upload_files.php]="2eb1983daae9b91d0642e0becd663a5a 55192fa9c165602e976f04306866da0f"
fi
MD5[get_ai_response.php]="116634cc9494199425af567200cd4344 8a261b1770f5993a1e845eb028463ab7"
MD5[_ai_case_list_helper.php]="977cc2a69204fd6854025697bdaeb8ec 33f8c5a5b23c0efc5f5d575324187a51"
MD5[get_tenant_usage_history.php]="f6cca724c13cf0ae41350009bf4316a3 4a6a9ff46f779a7f6fef3e046e89f914"
MD5[admin_otp.php]="9c7ab5571b7b609ec7dce28932648d2a fe98206813c4e0ee1cf3712b76b9c472"
MD5[analygent_login_verify.php]="492697c2335bee318baf3f283995809e 863db328ff20d2d5c7947d7dd11f754b"

md5() { md5sum "$1" | cut -d' ' -f1; }

echo "=== 配置先: $D ($ENV) ==="
echo "--- 1/4 配置するファイル"
for f in $FILES; do
    [ -f "$S/$f" ] || { echo "ありません: $S/$f"; exit 1; }
    set -- ${MD5[$f]}
    [ "$(md5 "$S/$f")" = "$2" ] || { echo "送ったファイルが想定と違います: $f"; exit 1; }
    php -l "$S/$f" >/dev/null || { echo "構文エラー: $f"; exit 1; }
done
echo "OK"

echo "--- 2/4 配置先の現行ファイル"
NG=0; TODO=""
for f in $FILES; do
    set -- ${MD5[$f]}
    cur=$(md5 "$D/$f")
    if [ "$cur" = "$2" ]; then echo "配置済み: $f"
    elif [ "$cur" = "$1" ]; then echo "OK: $f"; TODO="$TODO $f"
    else echo "NG（修正前の版と違う）: $f 現行=$cur"; NG=1; fi
done
[ $NG = 0 ] || { echo "中止しました。何も変更していません。"; exit 1; }
[ -n "$TODO" ] || { echo "すべて配置済みです"; exit 0; }

echo "--- 3/4 退避と配置"
BK="$REL/supfix-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
for f in $TODO; do cp -p "$D/$f" "$BK/"; done
chmod 750 "$BK"
for f in $TODO; do cat "$S/$f" > "$D/$f"; done
echo "退避先: $BK"

echo "--- 4/4 配置後の確認"
for f in $FILES; do
    set -- ${MD5[$f]}
    [ "$(md5 "$D/$f")" = "$2" ] && php -l "$D/$f" >/dev/null && echo "OK: $f" || { echo "NG: $f → 退避先から戻してください"; exit 1; }
done
echo "完了。退避先: $BK"
