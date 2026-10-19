#!/bin/bash
# ============================================================================
# bulk_cron.sh — まとめてアップロードのワーカーを起動する
#
# crontab 例（毎分 / 環境ごとに1行ずつ登録する）:
#   * * * * * /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_cron.sh
#   * * * * * /srv/www/apps/analygent_backend/releases/8012/sapis/bulk_cron.sh
#
# 置かれたディレクトリから環境を判断するため、本ファイルに環境差は無い
# （8012/8056 それぞれの sapis に同じものを置けばよい）。
#
# PHP には並行処理が無いため、同じワーカーを BULK_WORKERS 本だけ同時に起動し、
# 各ワーカーがファイルを1件ずつロックして奪い合う形で並列化する。
# 多重起動の上限は、現在動いているプロセス数を数えて制御する。
# ============================================================================
set -u

# pwd だけではシンボリックリンク(releases/8056)が解決されないため -P を付ける。
SAPIS="$(cd "$(dirname "$0")" && pwd -P)"
PHP=/usr/bin/php
WORKERS=${BULK_WORKERS:-3}          # 同時に走らせる本数。pdf-converter の負荷を見て調整する
LOGDIR=/var/log/ana

# 8012/8056 のログが混ざらないよう、置かれた場所からポートを取り出す
# （例: .../releases/20250620-1129_8056/sapis → 8056）
ENV_PORT="$(basename "$(dirname "$SAPIS")")"   # 20250620-1129_8056
ENV_PORT="${ENV_PORT##*_}"                     # 8056
case "$ENV_PORT" in ""|*[!0-9]*) ENV_PORT=unknown ;; esac
LOG="${LOGDIR}/bulk_${ENV_PORT}_worker_cron_$(date +%Y%m%d).log"

[ -d "$LOGDIR" ] || mkdir -p "$LOGDIR"

# 既に動いている本数を数える
# 8012 / 8056 でそれぞれ cron を登録するため、自環境のワーカーだけを数える
# （ファイル名だけで数えると、もう一方の環境のワーカーも含んでしまう）。
#
# pgrep -c は未検出のとき 0 を出力したうえで終了コード1を返すため、
# 「|| echo 0」と組み合わせると 0 が二重に出て計算が壊れる。
# 行数で数えれば、検出有無によらず必ず整数ひとつが得られる。
RUNNING=$(pgrep -f -- "${SAPIS}/bulk_worker.php" 2>/dev/null | wc -l | tr -d " ")
case "$RUNNING" in ""|*[!0-9]*) RUNNING=0 ;; esac
NEED=$(( WORKERS - RUNNING ))

if [ "$NEED" -le 0 ]; then
    exit 0                           # 既定数が動いている。何もしない
fi

for _ in $(seq 1 "$NEED"); do
    nohup "$PHP" "${SAPIS}/bulk_worker.php" >> "$LOG" 2>&1 &
    sleep 1                          # 同時起動でロックが競合しすぎないよう少しずらす
done
