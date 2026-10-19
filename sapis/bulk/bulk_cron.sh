#!/bin/bash
# ============================================================================
# bulk_cron.sh — まとめてアップロードのワーカーを起動する
#
# crontab 例（毎分）:
#   * * * * * /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_cron.sh
#
# PHP には並行処理が無いため、同じワーカーを BULK_WORKERS 本だけ同時に起動し、
# 各ワーカーがファイルを1件ずつロックして奪い合う形で並列化する。
# 多重起動の上限は、現在動いているプロセス数を数えて制御する。
# ============================================================================
set -u

SAPIS="$(cd "$(dirname "$0")" && pwd)"
PHP=/usr/bin/php
WORKERS=${BULK_WORKERS:-3}          # 同時に走らせる本数。pdf-converter の負荷を見て調整する
LOGDIR=/var/log/ana
LOG="${LOGDIR}/bulk_worker_cron_$(date +%Y%m%d).log"

[ -d "$LOGDIR" ] || mkdir -p "$LOGDIR"

# 既に動いている本数を数える（自分自身は除く）
RUNNING=$(pgrep -fc "bulk_worker.php" 2>/dev/null || echo 0)
NEED=$(( WORKERS - RUNNING ))

if [ "$NEED" -le 0 ]; then
    exit 0                           # 既定数が動いている。何もしない
fi

for _ in $(seq 1 "$NEED"); do
    nohup "$PHP" "${SAPIS}/bulk_worker.php" >> "$LOG" 2>&1 &
    sleep 1                          # 同時起動でロックが競合しすぎないよう少しずらす
done
