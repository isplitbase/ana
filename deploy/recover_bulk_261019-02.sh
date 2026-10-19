#!/bin/bash
# ============================================================================
# recover_bulk_261019-02.sh — 本番バッチ 261019-02 の失敗分をやり直す
#
# 使い方（apply_bulk_8012.sh で修正版を配置したあとに実行する）:
#   ssh -i ~/.ssh/isplit.pem isplit@34.180.79.93 'bash /tmp/bulkdeploy/recover_bulk_261019-02.sh'
#
# 2026-10-19 に並列3本で解析して過負荷になり、
#   読取失敗(AIERR) 5件 … #01 #02 #04 #05 #06
#   処理中のまま停止   3件 … #07 #08 #09
# となった分を、やり直し対象として APPROVED に戻す。
#
# 戻すだけで、解析そのものは cron のワーカーが順に行う。
# 修正版は解析を1件ずつしか走らせないため、9件でおよそ10分かかる。
#
# 行わないこと:
#   案件(ai_case)の削除、成功済み(#03)への変更、他バッチへの影響。
# ============================================================================
set -e

cat > /tmp/bulkdeploy/_recover.php <<'PHPEOF'
<?php
chdir('/srv/www/apps/analygent_backend/releases/8012/sapis');
include './common.php';
require_once './bulk_common.php';
global $conn;

$bid = '261019-02';

echo "=== 実行前 ===\n";
$r = $conn->query("SELECT f.seq, f.status, f.ai_case_id, a.status AS cs
                     FROM bulk_batch_files f
                     LEFT JOIN ai_case a ON a.ai_case_id = f.ai_case_id
                    WHERE f.batch_id = '{$bid}' ORDER BY f.seq");
while ($x = $r->fetch_assoc()) {
    printf("  #%02d %-10s case=%-7s 案件=%s\n", $x['seq'], $x['status'], $x['ai_case_id'] ?? '-', $x['cs'] ?? '-');
}

// やり直す対象: DONE になっていないものすべて。
//
// 案件の状態では判定しない。ワーカーが ERROR と記録した後に
// 裏の Python が完走して AIOK になっている、という食い違いが
// 起きるため（待たずに次へ進んでいたのが原因。修正済み）。
// 確定(get_ai_response)が済んでいないものは、読取からやり直す。
//
// ユーザーが確認画面で除外した SKIPPED には触らない。
$conn->query("UPDATE bulk_batch_files
                 SET status = '" . BULK_F_APPROVED . "',
                     worker_pid = NULL,
                     error_message = NULL,
                     update_at = now()
               WHERE batch_id = '{$bid}'
                 AND status IN ('" . BULK_F_ERROR . "','"
                                   . BULK_F_ANALYZING . "','"
                                   . BULK_F_APPROVED . "')");
$n = $conn->affected_rows;
echo "\nやり直し対象に戻しました: {$n} 件\n";

// バッチ自体も解析待ちに戻す
$conn->query("UPDATE bulk_batches
                 SET status = '" . BULK_ST_APPROVED . "',
                     message = 'やり直しています。',
                     worker_pid = NULL, locked_at = NULL, update_at = now()
               WHERE batch_id = '{$bid}'");
bulk_refresh_counts($conn, $bid);

echo "\n=== 実行後 ===\n";
$b = $conn->query("SELECT status,total_files,done_files,error_files FROM bulk_batches WHERE batch_id='{$bid}'")->fetch_assoc();
printf("  バッチ %s  計%d 完%d 誤%d\n", $b['status'], $b['total_files'], $b['done_files'], $b['error_files']);
$r = $conn->query("SELECT f.seq, f.status, f.ai_case_id, a.status AS cs
                     FROM bulk_batch_files f
                     LEFT JOIN ai_case a ON a.ai_case_id = f.ai_case_id
                    WHERE f.batch_id = '{$bid}' ORDER BY f.seq");
while ($x = $r->fetch_assoc()) {
    printf("  #%02d %-10s case=%-7s 案件=%s\n", $x['seq'], $x['status'], $x['ai_case_id'] ?? '-', $x['cs'] ?? '-');
}
PHPEOF

php /tmp/bulkdeploy/_recover.php

echo
echo "============================================================"
echo "cron のワーカーが1分以内に拾い、1件ずつ解析します。"
echo "進捗は画面、またはログで確認できます。"
echo "  tail -f /var/log/ana/bulk_8012_worker_$(date +%Y%m%d).log"
echo "============================================================"
