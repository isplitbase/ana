#!/bin/bash
# ============================================================================
# apply_numeric_fix.sh — 数値の指摘 5-(6)・5-(7) の修正を配置する
#
# 使い方（ローカルから）:
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'mkdir -p /tmp/numfix'
#   scp -i ~/.ssh/isplit.pem internal/for_8056/financial.py deploy/numfix/report_generation_8012_kpi.php \
#       isplit@34.180.79.93:/tmp/numfix/
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s' <8012|8056> < deploy/apply_numeric_fix.sh
#
# 配置するもの:
#   - internal/for_<env>/financial.py（両環境）
#       5-(7) 税引前利益: 表記ゆれ対応＋損失の符号＋重複行を合算しない（コミット e31eb21）
#   - sapis/report_generation.php（8012 のみ）
#       5-(6) 主要KPI: 売上成長率・営業利益率をグラフと同じ式に（KPI の部分だけ）。
#       リポジトリの sapis/8012/report_generation.php は指摘1（案件の会社チェック）の変更も含むため使わず、
#       本番の現行版に KPI の部分だけを当てた deploy/numfix/report_generation_8012_kpi.php を置く。
#       8056 は add0fa5 で配置済み。
#
# 配置先の現行 md5 が想定と違えば、何もせず中止する。退避先は releases/numfix-<env>-<日時>/。
# 既存の財務分析・レポートは、作り直すまで古い値のまま。
# ============================================================================
set -e

ENV="$1"
case "$ENV" in
    8012|8056) ;;
    *) echo "使い方: bash apply_numeric_fix.sh <8012|8056>"; exit 1 ;;
esac

S=/tmp/numfix
BASE=/srv/www/apps/analygent_backend
REL=$BASE/releases
PY_DST="$BASE/internal/for_$ENV/financial.py"
RG_DST="$(readlink -f "$REL/$ENV")/sapis/report_generation.php"

PY_NEW=936a9b8b61b8e126d139ad2bc3040464
# 上書きしてよい financial.py: 修正前 / 36cc5ba 版
PY_OK="4fc8cd9b20425a42d1e456071d96936c 25b4b766fc9b56f2c29b9d200d05e297"
RG_NEW=ae474b4d904ccd63375897b385caf562
RG_OK=b811ebb75068e881f49b00f5b90e1891   # 本番の修正前

md5() { md5sum "$1" | cut -d' ' -f1; }

echo "=== $ENV ==="
echo "--- 1/4 配置するファイルの確認"
[ -f "$S/financial.py" ] || { echo "ありません: $S/financial.py"; exit 1; }
[ "$(md5 "$S/financial.py")" = "$PY_NEW" ] || { echo "送った financial.py が想定と違います"; exit 1; }
python3 -B -c "import ast; ast.parse(open('$S/financial.py',encoding='utf-8').read())"
if [ "$ENV" = 8012 ]; then
    [ -f "$S/report_generation_8012_kpi.php" ] || { echo "ありません: $S/report_generation_8012_kpi.php"; exit 1; }
    [ "$(md5 "$S/report_generation_8012_kpi.php")" = "$RG_NEW" ] || { echo "送った report_generation が想定と違います"; exit 1; }
    php -l "$S/report_generation_8012_kpi.php" >/dev/null
fi
echo "OK"

echo "--- 2/4 配置先の現行ファイル"
cur=$(md5 "$PY_DST")
if [ "$cur" = "$PY_NEW" ]; then echo "financial.py: 配置済み"; PY_DO=0
elif [[ " $PY_OK " == *" $cur "* ]]; then echo "financial.py: OK"; PY_DO=1
else echo "中止: financial.py が想定と違います（現行=$cur）"; exit 1; fi
RG_DO=0
if [ "$ENV" = 8012 ]; then
    cur=$(md5 "$RG_DST")
    if [ "$cur" = "$RG_NEW" ]; then echo "report_generation.php: 配置済み"
    elif [ "$cur" = "$RG_OK" ]; then echo "report_generation.php: OK"; RG_DO=1
    else echo "中止: report_generation.php が想定と違います（現行=$cur）"; exit 1; fi
fi

echo "--- 3/4 退避と配置"
BK="$REL/numfix-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
if [ $PY_DO = 1 ]; then cp -p "$PY_DST" "$BK/financial.py"; cat "$S/financial.py" > "$PY_DST"; fi
if [ $RG_DO = 1 ]; then cp -p "$RG_DST" "$BK/report_generation.php"; cat "$S/report_generation_8012_kpi.php" > "$RG_DST"; fi
echo "退避先: $BK"

echo "--- 4/4 配置後の確認"
[ "$(md5 "$PY_DST")" = "$PY_NEW" ] && echo "financial.py: $(md5 "$PY_DST")"
if [ "$ENV" = 8012 ]; then php -l "$RG_DST" >/dev/null && echo "report_generation.php: $(md5 "$RG_DST")"; fi
echo "完了"
