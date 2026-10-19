<?php
// 使い方: php apply_migration.php <8012|8056> <sqlファイル>
// 既存DBにマイグレーションSQLを流し、case_group 列の状態を表示する。
$env = $argv[1]; $file = $argv[2];
$j = json_decode(file_get_contents("/srv/www/apps/analygent_backend/internal/for_{$env}/pf"), true);
$c = new mysqli($j['host'] ?? 'localhost', $j['username'], $j['password'], $j['dbname']);
$c->set_charset('utf8mb4');
if (!$c->multi_query(file_get_contents($file))) { fwrite(STDERR, "失敗: {$c->error}\n"); exit(1); }
do { if ($r = $c->store_result()) { while ($x = $r->fetch_row()) echo "  " . implode(' ', $x) . "\n"; $r->free(); } }
while ($c->more_results() && $c->next_result());
if ($c->error) { fwrite(STDERR, "失敗: {$c->error}\n"); exit(1); }
echo "適用しました（{$j['dbname']}）\n";
$r = $c->query("SHOW COLUMNS FROM bulk_batch_files LIKE 'case_group'");
$x = $r->fetch_assoc();
echo "  case_group: " . ($x ? "{$x['Type']} / NULL={$x['Null']} / 既定={$x['Default']}" : 'なし') . "\n";
$r = $c->query("SELECT COUNT(*) n, SUM(case_group IS NULL) nul FROM bulk_batch_files")->fetch_assoc();
echo "  既存の行: {$r['n']} 件（うち case_group が NULL: {$r['nul']} 件）\n";
