-- ============================================================================
-- bulk_batch_files に case_group 列を追加する（2026-10-19）
--
-- 将来、複数ファイルを1案件にまとめられるようにするための列。
-- 同じ case_group を持つファイルが1つの ai_case になる想定。
-- NULL は「単独で1案件」を表し、現在は常に NULL のため動作は変わらない。
--
-- 既存の行には何もしない（NULL のまま）。再実行しても安全なよう、
-- 列・索引が既にある場合は追加しない。
-- 適用先: dbtest1（8056）/ dbwindow（8012）
-- ============================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bulk_batch_files' AND COLUMN_NAME = 'case_group');
SET @sql := IF(@col = 0,
  "ALTER TABLE bulk_batch_files ADD COLUMN case_group INT DEFAULT NULL COMMENT '同じ案件にまとめるファイルの組番号。NULL=単独で1案件。現在は常にNULL（将来用）' AFTER size_bytes",
  "SELECT 'case_group は既にあります' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'bulk_batch_files' AND INDEX_NAME = 'idx_case_group');
SET @sql := IF(@idx = 0,
  "ALTER TABLE bulk_batch_files ADD KEY idx_case_group (batch_id, case_group)",
  "SELECT 'idx_case_group は既にあります' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- mode 列のコメントも実態に合わせる（A=ページ単位 は廃止）
ALTER TABLE bulk_batches
  MODIFY COLUMN mode CHAR(1) NOT NULL
  COMMENT '確認画面の方式。B=ファイル単位のみ（A=ページ単位は 2026-10-19 に廃止）';
