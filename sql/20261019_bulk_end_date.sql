-- ============================================================================
-- bulk_batch_files に決算日の列を追加する（2026-10-19）
--
-- 確認画面で「年度」ではなく「決算日」を扱うため。年度は3月決算以外で曖昧になる
-- （例: 5月決算の会社で、期判定AIは「令和4年度」、読取AIは 2023-05-31）。
--
--   detected_end_date  期判定AI（ana-getpdfinfo）が読んだ、このファイルの最新期の決算日
--   confirmed_end_date 確認画面で人が確定した決算日。照合の「正」
--   read_end_date      読取AI（callgpt.py）が書類から読んだ今期決算日（ai_case.closing_date）
--   end_date_check     読取後の照合結果 MATCH / MISMATCH / SKIP
--
-- 既存の行は NULL のまま。再実行しても安全なよう、既にある列は追加しない。
-- 適用先: dbtest1（8056）/ dbwindow（8012）
-- ============================================================================

DROP PROCEDURE IF EXISTS _bulk_add_col;
CREATE PROCEDURE _bulk_add_col(IN col VARCHAR(64), IN ddl TEXT)
BEGIN
  IF (SELECT COUNT(*) FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bulk_batch_files' AND COLUMN_NAME = col) = 0 THEN
    SET @s := ddl; PREPARE p FROM @s; EXECUTE p; DEALLOCATE PREPARE p;
  ELSE
    SELECT CONCAT(col, ' は既にあります') AS note;
  END IF;
END;

CALL _bulk_add_col('detected_end_date',
  "ALTER TABLE bulk_batch_files ADD COLUMN detected_end_date DATE DEFAULT NULL COMMENT '期判定AIが読んだ、このファイルの最新期の決算日' AFTER detected_years");
CALL _bulk_add_col('confirmed_end_date',
  "ALTER TABLE bulk_batch_files ADD COLUMN confirmed_end_date DATE DEFAULT NULL COMMENT '確認画面で人が確定した決算日（照合の正）' AFTER detected_end_date");
CALL _bulk_add_col('read_end_date',
  "ALTER TABLE bulk_batch_files ADD COLUMN read_end_date DATE DEFAULT NULL COMMENT '読取AIが書類から読んだ今期決算日（ai_case.closing_date）' AFTER confirmed_end_date");
CALL _bulk_add_col('end_date_check',
  "ALTER TABLE bulk_batch_files ADD COLUMN end_date_check VARCHAR(8) DEFAULT NULL COMMENT '読取後の決算日の照合 MATCH/MISMATCH/SKIP' AFTER read_end_date");

DROP PROCEDURE IF EXISTS _bulk_add_col;
