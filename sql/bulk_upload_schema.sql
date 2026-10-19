-- ============================================================================
-- まとめてアップロード（複数ファイル一括投入）用テーブル
--   2026-10-15 新規
--
-- 設計方針:
--   - 1案件(ai_case) = 1ファイル。ファイル名で会社を束ねることはしない。
--     ただし将来、複数ファイルを1案件にまとめられるよう case_group 列を
--     用意してある（現在は常に NULL）。
--   - ai_case は「確認画面で承認された後」に作成する。
--     承認前の中途半端な案件がファイル一覧に出ないようにするため。
--   - 重い処理(期判定・解析)は Web API では行わず、cron 起動のワーカーが行う。
--     画面はステータス取得APIを5秒間隔で呼んで進捗を表示する。
-- ============================================================================

CREATE TABLE IF NOT EXISTS bulk_batches (
    batch_id      VARCHAR(20)  NOT NULL COMMENT 'バッチID 例: 251015-03（日付＋当日連番）',
    user_id       INT          NOT NULL COMMENT '投入したユーザー',
    company_id    INT          NOT NULL COMMENT '一覧の絞り込みに使用',
    mode          CHAR(1)      NOT NULL COMMENT '確認画面の方式。B=ファイル単位のみ（A=ページ単位は 2026-10-19 に廃止）',
    status        VARCHAR(16)  NOT NULL COMMENT 'OPEN/QUEUED/DETECTING/REVIEW/APPROVED/ANALYZING/DONE/ERROR/CANCELED',
    total_files   INT          NOT NULL DEFAULT 0 COMMENT '受領したファイル数',
    done_files    INT          NOT NULL DEFAULT 0 COMMENT '処理が終わったファイル数',
    error_files   INT          NOT NULL DEFAULT 0 COMMENT '失敗したファイル数',
    message       VARCHAR(512)          DEFAULT NULL COMMENT '画面表示用のメッセージ',
    worker_pid    INT                   DEFAULT NULL COMMENT 'ワーカーのPID（多重起動防止）',
    locked_at     DATETIME              DEFAULT NULL COMMENT 'ワーカーがロックした時刻',
    create_at     DATETIME     NOT NULL,
    update_at     DATETIME     NOT NULL,
    PRIMARY KEY (batch_id),
    KEY idx_status (status),
    KEY idx_company (company_id, create_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='まとめてアップロードのバッチ';

CREATE TABLE IF NOT EXISTS bulk_batch_files (
    id              BIGINT       NOT NULL AUTO_INCREMENT,
    batch_id        VARCHAR(20)  NOT NULL,
    seq             INT          NOT NULL COMMENT 'バッチ内の通し番号（1始まり）',
    original_name   VARCHAR(512) NOT NULL COMMENT 'アップロード時のファイル名',
    gcs_path        VARCHAR(1024) NOT NULL COMMENT 'gs://analygent/bulk/<port>/<batch_id>/<seq>.pdf',
    size_bytes      BIGINT                DEFAULT NULL,
    case_group      INT                   DEFAULT NULL COMMENT '同じ案件にまとめるファイルの組番号。NULL=単独で1案件。現在は常にNULL（将来用）',
    status          VARCHAR(16)  NOT NULL COMMENT 'RECEIVED/DETECTING/DETECTED/APPROVED/ANALYZING/DONE/ERROR/SKIPPED',
    worker_pid      INT                   DEFAULT NULL COMMENT '処理中のワーカーPID（並列制御）',

    -- 自動判定（ana-getpdfinfo の戻り値）
    posting_period  VARCHAR(4)            DEFAULT NULL COMMENT 'P1/P2/P3',
    detected_labels VARCHAR(128)          DEFAULT NULL COMMENT '例: 今期,前期',
    detected_years  VARCHAR(128)          DEFAULT NULL COMMENT '例: 令和7年度,令和6年度',
    detected_end_date  DATE               DEFAULT NULL COMMENT '期判定AIが読んだ、このファイルの最新期の決算日',
    confirmed_end_date DATE               DEFAULT NULL COMMENT '確認画面で人が確定した決算日（照合の正）',
    read_end_date      DATE               DEFAULT NULL COMMENT '読取AIが書類から読んだ今期決算日（ai_case.closing_date）',
    end_date_check     VARCHAR(8)         DEFAULT NULL COMMENT '読取後の決算日の照合 MATCH/MISMATCH/SKIP',
    detect_reason   TEXT                  DEFAULT NULL COMMENT 'AIの判定根拠',

    -- 整合チェック
    check_result    VARCHAR(8)            DEFAULT NULL COMMENT 'OK/WARN/NG',
    check_code      VARCHAR(24)           DEFAULT NULL COMMENT 'YEAR_RANGE/LABEL_CONFLICT/UNDETECTED/DUPLICATE',
    check_message   VARCHAR(512)          DEFAULT NULL COMMENT '画面に出す警告文',

    ai_case_id      INT                   DEFAULT NULL COMMENT '承認後に作成された案件',
    error_message   VARCHAR(1024)         DEFAULT NULL,
    create_at       DATETIME     NOT NULL,
    update_at       DATETIME     NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_batch_seq (batch_id, seq),
    KEY idx_batch_status (batch_id, status),
    KEY idx_claim (batch_id, status, seq),
    KEY idx_ai_case (ai_case_id),
    KEY idx_case_group (batch_id, case_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='まとめてアップロードの明細（1行=1ファイル。現在は1ファイル=1案件）';
