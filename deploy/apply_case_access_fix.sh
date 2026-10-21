#!/bin/bash
# ============================================================================
# apply_case_access_fix.sh — 案件 API のセキュリティ修正を配置する
#
#   指摘1   案件番号（ai_case_id）を受け取る API にログイン必須＋同じ会社チェック
#           （_case_access_helper.php を新規追加し、各 API から呼ぶ）
#           ana-aiocr-photo.php は Web からの起動を止める（CLI 専用）
#   指摘3   get_ai_response.php の使われていない OpenAI キー直書き行を削除
#   指摘4   cash_ai_checkbyclaude.php / financial_ai.chatgpt.php の
#           usede.user_id の取り違え（$row['id'] → $row['user_id']）
#   指摘5-6 report_generation.php の主要KPI（売上成長率・営業利益率）の計算式
#
# 使い方（ローカルのリポジトリ直下から。ENV は 8012 か 8056）:
#   ENV=8012
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 "rm -rf /tmp/secfix_$ENV && mkdir -p /tmp/secfix_$ENV"
#   scp -i ~/.ssh/isplit.pem sapis/$ENV/_case_access_helper.php sapis/$ENV/ai_Diagnostics.php \
#       sapis/$ENV/ana-aiocr-photo.php sapis/$ENV/cash_ai.php sapis/$ENV/cash_ai_02_excel.php \
#       sapis/$ENV/cash_ai_02_save.php sapis/$ENV/cash_ai_03.php sapis/$ENV/cash_ai_checkbyclaude.php \
#       sapis/$ENV/cash_ai_usepoint.php sapis/$ENV/do_ai.php sapis/$ENV/financial_ai.chatgpt.php \
#       sapis/$ENV/financial_ai.php sapis/$ENV/financial_ai_usepoint.php sapis/$ENV/get_ai_response.php \
#       sapis/$ENV/get_check_count.php sapis/$ENV/get_upload_files_response.php \
#       sapis/$ENV/report_generation.php sapis/$ENV/report_show.php \
#       isplit@34.180.79.93:/tmp/secfix_$ENV/
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 "bash -s $ENV" < deploy/apply_case_access_fix.sh
#
# 行うこと:
#   1. 配置するファイルの存在確認と php -l
#   2. 配置先の現行ファイルが、修正前に取り込んだ版（下の md5）と同じか確認
#      （その後に誰かが変えていたら止まる。承知の上で進めるなら FORCE=1）
#   3. 現行ファイルを releases/sec_backup-<ENV>-<日時>/ に退避（Web から見えない場所）
#   4. 配置して、配置後のファイルを php -l
#   5. loginkey 無し／無効な loginkey で呼んでエラーになることを curl で確認
#
# 戻し方（表示される BK のパスを使う）:
#   cp -p $BK/*.php /srv/www/apps/analygent_backend/releases/<ENV>/sapis/
#   rm /srv/www/apps/analygent_backend/releases/<ENV>/sapis/_case_access_helper.php
# ============================================================================
set -e

ENV="${1:-}"
case "$ENV" in
    8012|8056) ;;
    *) echo "使い方: bash -s 8012 または bash -s 8056"; exit 1 ;;
esac

S=/tmp/secfix_$ENV
REL=/srv/www/apps/analygent_backend/releases
D=$(readlink -f "$REL/$ENV")/sapis

NEW_FILES="_case_access_helper.php"
MOD_FILES="ai_Diagnostics.php ana-aiocr-photo.php cash_ai.php cash_ai_02_excel.php cash_ai_02_save.php
           cash_ai_03.php cash_ai_checkbyclaude.php cash_ai_usepoint.php do_ai.php financial_ai.chatgpt.php
           financial_ai.php financial_ai_usepoint.php get_ai_response.php get_check_count.php
           get_upload_files_response.php report_generation.php report_show.php"

# 修正前（2026-10-21 にサーバから取り込んだ版）の md5。do_ai.php だけ環境で内容が違う。
if [ "$ENV" = 8012 ]; then DO_AI_MD5=d761d7ec2b7239385d69dc585bed3b55; else DO_AI_MD5=5027dd0be0b42afc4b8ed167f1e6e9ac; fi
EXPECTED="2c99c945d194c05a4d5c761dd24f456e  ai_Diagnostics.php
d0bb61fa2a0e78bc055118346175c2ef  ana-aiocr-photo.php
716933bc6b836c834710f3363dd5c987  cash_ai.php
cb90f315430696be1404204d81f074b6  cash_ai_02_excel.php
bb8d4da74218e70771b28ec7fb2657e8  cash_ai_02_save.php
fb8803454385c3fce9b15087e3a024ba  cash_ai_03.php
4e2df69c3e09344c6406a4a11d114df5  cash_ai_checkbyclaude.php
c39713e45359738a93c2862d224113fa  cash_ai_usepoint.php
$DO_AI_MD5  do_ai.php
14f6cca4205fb6e342544bd61a7a9137  financial_ai.chatgpt.php
c7130660e7072327ddd24bd93bc65eae  financial_ai.php
89ad43033a6d344ec7f5a91c519a9e2c  financial_ai_usepoint.php
28546337b3f9126f6ddb0378a337ec52  get_ai_response.php
fc11c46141df08d7b526b508ab05b5c4  get_check_count.php
a29ab2093d87fb051926f9638193d2ef  get_upload_files_response.php
b811ebb75068e881f49b00f5b90e1891  report_generation.php
3c973e627f51545778a70fff3e5e046b  report_show.php"

echo "=== 配置先: $D ($ENV) ==="

echo "--- 1/5 配置するファイルの確認 ---"
for f in $NEW_FILES $MOD_FILES; do
    [ -f "$S/$f" ] || { echo "ありません: $S/$f"; exit 1; }
    php -l "$S/$f" >/dev/null || { echo "構文エラー: $S/$f"; exit 1; }
done
# 秘密情報（OpenAI キー直書き）が残っていないこと
if grep -lE 'sk-[A-Za-z0-9_-]{20,}' "$S"/*.php; then echo "キーらしき文字列が残っています（上のファイル）"; exit 1; fi
echo "$(echo $NEW_FILES $MOD_FILES | wc -w) ファイル / 構文チェック OK"

echo "--- 2/5 配置先の現行ファイルが修正前の版か確認 ---"
[ -d "$D" ] || { echo "配置先がありません: $D"; exit 1; }
[ -e "$D/_case_access_helper.php" ] && echo "注意: $D/_case_access_helper.php は既にあります（上書きします）"
if ! (cd "$D" && echo "$EXPECTED" | md5sum -c --quiet -); then
    if [ "${FORCE:-0}" != 1 ]; then
        echo "上のファイルが、修正を作った時点の版から変わっています。中止します。"
        echo "（差分を確認し、問題なければ FORCE=1 を付けて再実行）"
        exit 1
    fi
    echo "FORCE=1 のため続行します"
fi
echo "OK"

echo "--- 3/5 現行ファイルを退避 ---"
BK="$REL/sec_backup-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
for f in $MOD_FILES; do cp -p "$D/$f" "$BK/"; done
[ -f "$D/_case_access_helper.php" ] && cp -p "$D/_case_access_helper.php" "$BK/" || true
ls -la "$D" > "$BK/sapis_before.txt"
chmod 750 "$BK"
echo "BK=$BK"

echo "--- 4/5 配置 ---"
for f in $NEW_FILES $MOD_FILES; do
    # 既存ファイルは中身だけ差し替える（所有者・権限は変えない）
    cat "$S/$f" > "$D/$f"
done
chmod 644 "$D/_case_access_helper.php"
for f in $NEW_FILES $MOD_FILES; do
    php -l "$D/$f" >/dev/null || { echo "配置後の構文エラー: $f → 戻してください（上の戻し方）"; exit 1; }
done
echo "配置しました"

echo "--- 5/5 loginkey 無し／無効な loginkey でエラーになるか ---"
URL="https://127.0.0.1:$ENV/sapis"
NG=0
for api in get_ai_response.php get_upload_files_response.php financial_ai.php financial_ai.chatgpt.php \
           financial_ai_usepoint.php cash_ai.php cash_ai_02_excel.php cash_ai_02_save.php cash_ai_03.php \
           cash_ai_checkbyclaude.php cash_ai_usepoint.php ai_Diagnostics.php report_generation.php \
           report_show.php do_ai.php get_check_count.php; do
    for body in '{"ai_case_id":1}' '{"ai_case_id":1,"loginkey":"invalid-key-for-test"}'; do
        res=$(curl -sk -m 20 -X POST -H 'Content-Type: application/json' -d "$body" "$URL/$api")
        if echo "$res" | grep -q '"status" *: *"error"'; then r=OK; else r=NG; NG=1; fi
        printf '%-32s %-4s %s\n' "$api" "$r" "$(echo "$res" | head -c 120)"
    done
done
res=$(curl -sk -m 20 "$URL/ana-aiocr-photo.php?ai_case_id=1" -o /dev/null -w '%{http_code}')
printf '%-32s %-4s http=%s\n' "ana-aiocr-photo.php" "$([ "$res" = 403 ] && echo OK || echo NG)" "$res"
[ "$res" = 403 ] || NG=1

echo
echo "============================================================"
if [ $NG = 0 ]; then echo "完了しました。"; else echo "NG があります。上を確認してください。"; fi
echo "退避先: $BK"
echo "正しいログインでの動作（読取・財務分析・CF分析・AI診断・レポート）は画面で確認してください。"
echo "他社の案件で弾かれた記録は Apache の error log に [case_access] deny として出ます:"
echo "  grep case_access /var/log/apache2/zlite_${ENV}*error.log"
echo "============================================================"
