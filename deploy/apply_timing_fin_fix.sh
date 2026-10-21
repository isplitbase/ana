#!/bin/bash
# ============================================================================
# apply_timing_fin_fix.sh — 自動削除設定APIの権限と、財務分析の税引前利益の修正を配置する
#
# 引数は環境: 8056=開発 / 8012=本番
#
# 使い方（ローカルのリポジトリのルートから）:
#   1) ファイルを送る（<env> は 8056 か 8012。そのリポジトリのファイルを送ること）
#        ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'mkdir -p /tmp/timingfin_deploy'
#        scp -i ~/.ssh/isplit.pem sapis/<env>/set_delete_pdf_timing_cd.php internal/for_<env>/financial.py isplit@34.180.79.93:/tmp/timingfin_deploy/
#   2) 配置
#        ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash -s -- <env>' < deploy/apply_timing_fin_fix.sh
#
# 行うこと:
#   - sapis/set_delete_pdf_timing_cd.php : auth 300（マスター管理者）のみ許可
#   - internal/for_<env>/financial.py    : 税引前利益の読み替え（R016）を表記ゆれに対応
#   配置前に、サーバ上の現在のファイルが「修正前の取り込み版」と同一か確認し、
#   違えば（他の変更が入っていれば）何もせず中止する。
#   バックアップは Web から見えない releases/timingfin_backup-<env>-<日時>/ に置く。
#
# 戻し方: 最後に表示されるバックアップ先のファイルを cp -p で元の場所へ戻す。
# ============================================================================
set -e
ENV="$1"
case "$ENV" in 8056|8012) ;; *) echo "引数は 8056 か 8012"; exit 1;; esac

S=/tmp/timingfin_deploy
BASE=/srv/www/apps/analygent_backend
REL=$(readlink -f "$BASE/releases/$ENV")
PHP_DST="$REL/sapis/set_delete_pdf_timing_cd.php"
PY_DST="$BASE/internal/for_$ENV/financial.py"

# 修正前（サーバから取り込んだ版）と修正後の md5（8012・8056 とも同一内容）
PHP_OLD=0f0c7a9f31ab66c33282d728e19952b0
PY_OLD=4fc8cd9b20425a42d1e456071d96936c
PHP_NEW=63319071ddaf3d4aea2a62aee7675505
PY_NEW=25b4b766fc9b56f2c29b9d200d05e297

md5() { md5sum < "$1" | cut -c1-32; }

echo "--- 1/4 事前確認 ($ENV) ---"
[ -f "$S/set_delete_pdf_timing_cd.php" ] && [ -f "$S/financial.py" ] || { echo "$S にファイルがありません"; exit 1; }
[ "$(md5 "$S/set_delete_pdf_timing_cd.php")" = "$PHP_NEW" ] || { echo "送ったPHPが想定と違います"; exit 1; }
[ "$(md5 "$S/financial.py")" = "$PY_NEW" ] || { echo "送ったPythonが想定と違います"; exit 1; }
php -l "$S/set_delete_pdf_timing_cd.php" >/dev/null
python3 -B -c "import ast; ast.parse(open('$S/financial.py',encoding='utf-8').read())"
[ "$(md5 "$PHP_DST")" = "$PHP_OLD" ] || { echo "配置先PHPが取り込み版と違います。中止: $PHP_DST"; exit 1; }
[ "$(md5 "$PY_DST")"  = "$PY_OLD"  ] || { echo "配置先Pythonが取り込み版と違います。中止: $PY_DST"; exit 1; }
echo "OK（配置先は取り込み版のまま）"

echo "--- 2/4 バックアップ ---"
BK="$BASE/releases/timingfin_backup-$ENV-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BK"
cp -p "$PHP_DST" "$BK/"
cp -p "$PY_DST"  "$BK/"
echo "$BK"

echo "--- 3/4 配置（中身だけ書き換え、所有者・権限は維持）---"
cat "$S/set_delete_pdf_timing_cd.php" > "$PHP_DST"
cat "$S/financial.py" > "$PY_DST"

echo "--- 4/4 配置後の確認 ---"
php -l "$PHP_DST"
python3 -B -c "import ast; ast.parse(open('$PY_DST',encoding='utf-8').read()); print('py OK')"
[ "$(md5 "$PHP_DST")" = "$PHP_NEW" ] && [ "$(md5 "$PY_DST")" = "$PY_NEW" ] && echo "md5 一致"
echo "バックアップ: $BK"
