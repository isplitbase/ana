#!/bin/bash
# ============================================================================
# retire_legacy_apis.sh — 使われていない旧 API を sapis の外へ移して公開をやめる
#
# 使い方（ローカルから）:
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' 8056 < deploy/retire_legacy_apis.sh
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' 8012 < deploy/retire_legacy_apis.sh
#
# 対象（2026-10-21 時点で、フロントから呼ばれず、14日分のアクセスログに呼び出しがなく、
#       他の PHP から include もされていないことを確認済み）:
#   - analygent_login.php / login.php / x9c9b-03d.php
#       パスワードなし・平文照合で loginkey を発行する旧ログイン。users の id / mail 列（ana には無い）を参照
#   - zlit*.php（z-lite からの持ち込み一式）
#       zlite_reset_password.php は check_code だけで任意アカウントのパスワードを書き換え得る
#   - save_user.php / line_login.php / line_login_check.php
#       旧 Google / LINE ログイン。DB 接続情報の直書きあり。id_token の aud を呼び出し側の値と照合している
#   - delete_user.php
#   - analygent_set_credit_no.php / edit_user.php / get_user_list.php
#       カード番号・セキュリティコードを users に保存・表示する旧 API（Stripe 移行後は不要。保存済みデータは0件）
#
# 行うこと:
#   releases/sec_retired-<env>-<日時>/ へ mv（削除はしない）。移した一覧と md5 を同じ場所に残す。
#
# 戻し方:
#   mv releases/sec_retired-<env>-<日時>/*.php <配置先>/sapis/
# ============================================================================
set -e

ENV="$1"
case "$ENV" in
    8012|8056) ;;
    *) echo "使い方: bash retire_legacy_apis.sh <8012|8056>"; exit 1 ;;
esac

REL=/srv/www/apps/analygent_backend/releases
D="$(readlink -f "$REL/$ENV")/sapis"
[ -d "$D" ] || { echo "配置先がありません: $D"; exit 1; }

cd "$D"
TARGETS=""
for f in analygent_login.php login.php x9c9b-03d.php zlit*.php \
         save_user.php line_login.php line_login_check.php delete_user.php \
         analygent_set_credit_no.php edit_user.php get_user_list.php; do
    [ -f "$f" ] && TARGETS="$TARGETS $f"
done
[ -n "$TARGETS" ] || { echo "対象のファイルはありません（移動済み）"; exit 0; }

echo "=== $ENV ($D) ==="
echo "--- 対象: $(echo $TARGETS | wc -w) ファイル"

# 念のため、残る PHP からの include が無いことを確認する
for f in $TARGETS; do
    inc=$(grep -lE "(require|include)(_once)?[^;]*['\"/]${f//./\\.}" *.php 2>/dev/null | grep -vxF -f <(echo $TARGETS | tr ' ' '\n') || true)
    if [ -n "$inc" ]; then
        echo "中止: $f を include しているファイルがあります: $inc"
        exit 1
    fi
done

BK="$REL/sec_retired-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
md5sum $TARGETS > "$BK/retired_md5.txt"
mv $TARGETS "$BK/"
echo "移動先: $BK"
ls -1 "$BK"
echo "--- 残っていないことの確認"
for f in $TARGETS; do [ -e "$D/$f" ] && { echo "残っています: $f"; exit 1; }; done
echo "完了"
