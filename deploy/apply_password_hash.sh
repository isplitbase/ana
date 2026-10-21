#!/bin/bash
# ============================================================================
# apply_password_hash.sh — パスワードのハッシュ化とログイン・企業登録の修正を配置する
#
# 使い方（ローカルから）:
#   1) 配置するファイルをサーバの /tmp/pwdeploy/ に置く
#        scp -i ~/.ssh/isplit.pem sapis/8056/ana_password_helper.php \
#            sapis/8056/analygent_add_user.php sapis/8056/analygent_create_user.php \
#            sapis/8056/analygent_create_user_chcek.php sapis/8056/analygent_login_start.php \
#            sapis/8056/analygent_login_verify.php sapis/8056/analygent_repass_user_verify.php \
#            sapis/8056/analygent_repass_user_verify_updated.php \
#            isplit@34.180.79.93:/tmp/pwdeploy/
#      （先に ssh ... 'mkdir -p /tmp/pwdeploy' ）
#   2) 配置先を引数で指定して実行
#        ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' 8012 < deploy/apply_password_hash.sh
#
# 行うこと:
#   1. 配置するファイルの存在確認と構文チェック
#   2. 配置先の現行ファイルが、修正の元にしたファイル（下の ORIG_MD5）と同じか確認
#      → 1つでも違えば何もせず中止（その環境のファイルは別内容なので、差分を見て作り直す）
#   3. releases/sec_backup-<env>-<日時>/ に現行ファイルを退避（sapis/ の外）
#   4. 配置（ana_password_helper.php を先に置いてから、それを読む API を置く）
#   5. 配置後の構文チェック
#
# 行わないこと:
#   DB の変更。既存の平文パスワードは、各自のログイン時にハッシュへ置き換わる。
#
# 戻し方（配置後に問題があった場合）:
#   cp -p <退避先>/*.php <配置先>/sapis/ && rm <配置先>/sapis/ana_password_helper.php
#   ※ ただし戻すと、配置中にログイン・登録・パスワード変更した人の保存値はハッシュになっており、
#     旧コード（平文で照合）ではログインできない。戻した場合は該当者にパスワード再設定を案内する。
# ============================================================================
set -e

ENV="$1"
case "$ENV" in
    8012|8056) ;;
    *) echo "使い方: bash apply_password_hash.sh <8012|8056>"; exit 1 ;;
esac

S=/tmp/pwdeploy
REL=/srv/www/apps/analygent_backend/releases
D="$(readlink -f "$REL/$ENV")"

NEW_FILE="ana_password_helper.php"
FILES="analygent_add_user.php analygent_create_user.php analygent_create_user_chcek.php
       analygent_login_start.php analygent_login_verify.php
       analygent_repass_user_verify.php analygent_repass_user_verify_updated.php"

# 修正の元にした開発(8056)のファイル（2026-10-21 時点）の md5
declare -A ORIG_MD5=(
    [analygent_add_user.php]=ff8937c16342b68852df9b17bb178c47
    [analygent_create_user.php]=f7080accf15b615ec5f62c7cf40621e8
    [analygent_create_user_chcek.php]=b6b3ab80381e8149efcb13a48c7291a6
    [analygent_login_start.php]=57c3864831238a64cff5baf85ac04e18
    [analygent_login_verify.php]=f8e668620c6da5d1476623bb9a247cb6
    [analygent_repass_user_verify.php]=3b5c586470d0fbe89a7dc830049aba4d
    [analygent_repass_user_verify_updated.php]=e8678bd05717d57838555443eb9dcbdb
)

echo "=== 配置先: $ENV -> $D ==="

echo "--- 1/5 配置するファイルの確認 ---"
for f in $NEW_FILE $FILES; do
    [ -f "$S/$f" ] || { echo "ありません: $S/$f"; exit 1; }
    php -l "$S/$f" >/dev/null || { echo "構文エラー: $S/$f"; exit 1; }
done
[ -d "$D/sapis" ] || { echo "配置先がありません: $D/sapis"; exit 1; }
echo "OK"

echo "--- 2/5 配置先の現行ファイルが修正の元と同じか ---"
NG=0
for f in $FILES; do
    cur="$(md5sum "$D/sapis/$f" | cut -d' ' -f1)"
    new="$(md5sum "$S/$f" | cut -d' ' -f1)"
    if [ "$cur" = "$new" ]; then
        echo "配置済み（同じ内容）: $f"
    elif [ "$cur" = "${ORIG_MD5[$f]}" ]; then
        echo "OK: $f"
    else
        echo "NG（修正の元と内容が違う）: $f  現行=$cur 元=${ORIG_MD5[$f]}"
        NG=1
    fi
done
if [ -f "$D/sapis/$NEW_FILE" ] && ! cmp -s "$D/sapis/$NEW_FILE" "$S/$NEW_FILE"; then
    echo "NG（同名の別ファイルがある）: $NEW_FILE"
    NG=1
fi
[ $NG -eq 0 ] || { echo "中止しました。何も変更していません。"; exit 1; }

echo "--- 3/5 現行ファイルを退避 ---"
BK="$REL/sec_backup-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
for f in $FILES; do cp -p "$D/sapis/$f" "$BK/"; done
ls -la "$D/sapis/" > "$BK/sapis_before.txt"
echo "退避先: $BK"

echo "--- 4/5 配置 ---"
# 共通関数を先に置く（後から置く API がこれを読むため）
cp "$S/$NEW_FILE" "$D/sapis/$NEW_FILE"
chmod 0644 "$D/sapis/$NEW_FILE"
for f in $FILES; do
    cat "$S/$f" > "$D/sapis/$f"   # 既存ファイルの所有者・権限はそのまま
done

echo "--- 5/5 配置後の確認 ---"
for f in $NEW_FILE $FILES; do
    php -l "$D/sapis/$f" >/dev/null || { echo "構文エラー: $D/sapis/$f （退避先から戻してください: $BK）"; exit 1; }
    echo "$(md5sum "$D/sapis/$f")"
done
echo "完了。退避先: $BK"
