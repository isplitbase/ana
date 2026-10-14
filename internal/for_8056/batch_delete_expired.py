"""
batch_delete_expired.py
----------------------------------------------------------------------
crontab で毎時実行し、delete_pdf_timing_cd に基づいて
期限切れ ai_case の GCS ファイルと DB レコードを削除するバッチ。

設定ファイル:
  スクリプトと同じフォルダの "pf"（JSON）を読み込む。
  callgpt.py と同じ pf を使用。

delete_pdf_timing_cd の意味（update_at 基準）:
  A -> 24時間後に削除
  B -> 1ヶ月（30日）後に削除
  C -> 1年（365日）後に削除
  D / NULL / 未設定 -> 削除しない

使い方:
  python3 batch_delete_expired.py            # 本番実行
  python3 batch_delete_expired.py --dry-run  # DB/GCS を変更しない確認モード

crontab 例（毎時0分）:
  0 * * * * /usr/bin/python3 /srv/www/apps/analygent_backend/internal/for_8056/batch_delete_expired.py >> /var/log/ana/batch_delete_$(date +\\%Y\\%m\\%d).log 2>&1
"""

import json
import os
import re
import sys
import logging
import traceback
from datetime import datetime, timedelta, timezone
from pathlib import Path
from typing import Optional

import mysql.connector
from mysql.connector import Error

try:
    from google.cloud import storage as gcs_storage
    _GCS_AVAILABLE = True
except ImportError:
    _GCS_AVAILABLE = False

# -------------------------------------------------------
# 定数
# -------------------------------------------------------
BATCH_LIMIT = 500   # 1回の実行で処理する最大件数（会社ごと）
DRY_RUN     = "--dry-run" in sys.argv

# delete_pdf_timing_cd -> timedelta（None = 削除しない）
TIMING_MAP = {
    "A": timedelta(hours=24),
    "B": timedelta(days=30),
    "C": timedelta(days=365),
    "D": None,
}

# -------------------------------------------------------
# ロギング設定
# -------------------------------------------------------
logging.basicConfig(
    level=logging.INFO,
    format="[%(asctime)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
    stream=sys.stdout,
)
log = logging.getLogger(__name__)


def blog(msg):
    log.info(msg)


# -------------------------------------------------------
# 設定読み込み（pf ファイル）
# -------------------------------------------------------
def load_config():
    config_path = Path(__file__).parent / "pf"
    with open(config_path, "r", encoding="utf-8") as f:
        return json.load(f)


# -------------------------------------------------------
# DB 接続
# -------------------------------------------------------
def get_db_connection(cfg):
    _host     = cfg.get("host", "")
    _user     = cfg.get("username", "")
    _password = cfg.get("password", "")
    _database = cfg.get("dbname", "")
    blog("[DB] host     : " + _host)
    blog("[DB] user     : " + _user)
    blog("[DB] password : " + ("*" * len(_password) if _password else "(未設定)"))
    blog("[DB] database : " + _database)

    connect_args = dict(
        host=_host,
        database=_database,
        user=_user,
        password=_password,
    )

    # localhost の場合は Unix ソケットを優先（TCP が無効な環境対応）
    if _host in ("localhost", "127.0.0.1"):
        for sock_path in [
            "/var/run/mysqld/mysqld.sock",
            "/tmp/mysql.sock",
            "/var/lib/mysql/mysql.sock",
        ]:
            if os.path.exists(sock_path):
                connect_args["unix_socket"] = sock_path
                blog("[DB] unix_socket: " + sock_path)
                break

    return mysql.connector.connect(**connect_args)


# -------------------------------------------------------
# GCS ユーティリティ
# -------------------------------------------------------
def _gcs_client():
    return gcs_storage.Client()


def _delete_blob(bucket, obj_name, dry_run):
    """1オブジェクトを削除する。成功したら True を返す。"""
    uri = "gs://" + bucket.name + "/" + obj_name
    if dry_run:
        blog("  [DRY_RUN] GCS 削除スキップ: " + uri)
        return True
    try:
        bucket.blob(obj_name).delete()
        blog("  [GCS] 削除: " + uri)
        return True
    except Exception as e:
        blog("  [GCS] 削除失敗（無視）: " + uri + " (" + str(e) + ")")
        return False


def delete_gcs_files_for_case(bucket, gcs_bucket_name, ai_case_id,
                               full_file_paths_str, img_urls_str, dry_run):
    """
    1案件のGCSファイルをすべて削除する。
    1. full_file_Paths / img_urls に含まれる gs:// URI を直接削除
    2. プレフィックス「{ai_case_id}-」でPDFを一括削除
    3. プレフィックス「converted/{ai_case_id}-」で変換済み画像を一括削除
    """
    deleted = []

    # 1. gs:// URI を直接削除
    all_uris = []
    for field in [full_file_paths_str, img_urls_str]:
        if field:
            all_uris.extend([p.strip() for p in field.split("|,|") if p.strip()])

    for uri in all_uris:
        m = re.match(r"^gs://([^/]+)/(.+)$", uri)
        if not m:
            continue
        if m.group(1) != gcs_bucket_name:
            blog("  [GCS] バケット不一致、スキップ: " + uri)
            continue
        ok = _delete_blob(bucket, m.group(2), dry_run)
        if ok:
            deleted.append(uri)

    # 2. PDF プレフィックス削除
    # 3. 変換済み画像プレフィックス削除
    prefixes = [
        str(ai_case_id) + "-",
        "converted/" + str(ai_case_id) + "-",
        # 2026-10-14 追加: pdf-converter の旧リビジョンが作っていた中間ファイル
        #   converted/converted_{ai_case_id}-{fileKey}-NNN_mini.png
        #   は上の前方一致（converted/{id}-）に一致せず、削除されずに残っていた。
        #   2026-08-27 以降は生成されないが、過去分が GCS に残るため対象に加える。
        "converted/converted_" + str(ai_case_id) + "-",
    ]
    for prefix in prefixes:
        try:
            blobs = list(bucket.list_blobs(prefix=prefix))
            for blob in blobs:
                ok = _delete_blob(bucket, blob.name, dry_run)
                if ok:
                    deleted.append("gs://" + gcs_bucket_name + "/" + blob.name)
        except Exception as e:
            blog("  [GCS] prefix=" + prefix + " 一覧取得失敗: " + str(e))

    # 重複除去
    return list(dict.fromkeys(deleted))


# -------------------------------------------------------
# 削除対象取得
# -------------------------------------------------------
def fetch_target_cases(conn, now):
    """
    delete_pdf_timing_cd に応じた期限を超えた ai_case を返す。
    users.delete_pdf_timing_cd を ai_case.user_id で直接 JOIN して取得。
    """
    cutoff_A = (now - TIMING_MAP["A"]).strftime("%Y-%m-%d %H:%M:%S")
    cutoff_B = (now - TIMING_MAP["B"]).strftime("%Y-%m-%d %H:%M:%S")
    cutoff_C = (now - TIMING_MAP["C"]).strftime("%Y-%m-%d %H:%M:%S")

    blog("  cutoff A(24h)  : " + cutoff_A)
    blog("  cutoff B(30d)  : " + cutoff_B)
    blog("  cutoff C(365d) : " + cutoff_C)

    cur = conn.cursor(dictionary=True)
    cur.execute("""
        SELECT ac.ai_case_id,
               ac.user_id,
               ac.upload_file_name,
               ac.full_file_Paths,
               ac.img_urls,
               ac.update_at,
               company.delete_pdf_timing_cd AS timing_cd
        FROM ai_case ac
        INNER JOIN users me ON ac.user_id = me.user_id
        INNER JOIN users company ON me.company_id = company.user_id
        WHERE company.delete_pdf_timing_cd IN ('A', 'B', 'C')
          AND ac.status != 'DELETED'
          AND (
              (company.delete_pdf_timing_cd = 'A' AND ac.update_at < %s)
           OR (company.delete_pdf_timing_cd = 'B' AND ac.update_at < %s)
           OR (company.delete_pdf_timing_cd = 'C' AND ac.update_at < %s)
          )
        LIMIT %s
    """, (cutoff_A, cutoff_B, cutoff_C, BATCH_LIMIT))
    rows = cur.fetchall()
    cur.close()

    blog("削除対象 ai_case 合計: " + str(len(rows)) + " 件")
    return rows


# -------------------------------------------------------
# メイン処理
# -------------------------------------------------------
def main():
    blog("=" * 60)
    blog("batch_delete_expired 開始")
    blog("  DRY_RUN    : " + str(DRY_RUN))
    blog("  BATCH_LIMIT: " + str(BATCH_LIMIT))
    blog("=" * 60)

    # 設定読み込み
    try:
        cfg = load_config()
    except Exception as e:
        blog("[ERROR] pf 読み込み失敗: " + str(e))
        sys.exit(1)

    gcs_bucket_name = cfg.get("gcs_bucket", "")
    google_creds    = cfg.get("google_application_credentials", "")
    if google_creds:
        os.environ["GOOGLE_APPLICATION_CREDENTIALS"] = google_creds

    blog("  GCS_BUCKET : " + gcs_bucket_name)

    # DB 接続
    try:
        conn = get_db_connection(cfg)
        blog("[DB] 接続成功")
    except Exception as e:
        blog("[ERROR] DB 接続失敗: " + str(e))
        sys.exit(1)

    # GCS クライアント
    bucket = None
    if _GCS_AVAILABLE and gcs_bucket_name:
        try:
            gcs_client = _gcs_client()
            bucket     = gcs_client.bucket(gcs_bucket_name)
            blog("[GCS] クライアント初期化成功")
        except Exception as e:
            blog("[WARN] GCS クライアント初期化失敗（GCS 削除スキップ）: " + str(e))
    else:
        blog("[WARN] google-cloud-storage 未インストール or gcs_bucket 未設定 -> GCS 削除スキップ")

    JST = timezone(timedelta(hours=9))
    now = datetime.now(JST).replace(tzinfo=None)

    # 削除対象取得
    try:
        targets = fetch_target_cases(conn, now)
    except Exception as e:
        blog("[ERROR] 削除対象取得失敗: " + str(e) + "\n" + traceback.format_exc())
        conn.close()
        sys.exit(1)

    if not targets:
        blog("削除対象なし。処理を終了します。")
        conn.close()
        return

    blog("削除対象合計: " + str(len(targets)) + " 件")

    total_deleted = 0
    total_gcs     = 0
    total_errors  = 0

    for case in targets:
        ai_case_id       = case["ai_case_id"]
        case_user_id     = case["user_id"]
        upload_file_name = case.get("upload_file_name") or ""
        full_file_paths  = case.get("full_file_Paths")  or ""
        img_urls         = case.get("img_urls")          or ""

        blog("--- ai_case_id=" + str(ai_case_id) +
             " file=" + str(upload_file_name) +
             " update_at=" + str(case["update_at"]) + " ---")

        # GCS 物理削除
        if bucket is not None:
            gcs_deleted = delete_gcs_files_for_case(
                bucket,
                gcs_bucket_name,
                ai_case_id,
                full_file_paths,
                img_urls,
                DRY_RUN,
            )
            total_gcs += len(gcs_deleted)
        else:
            blog("  [GCS] スキップ")

        # DB 論理削除 + 操作履歴
        if not DRY_RUN:
            try:
                cur = conn.cursor(dictionary=True)

                # ai_case 論理削除
                cur.execute("""
                    UPDATE ai_case
                    SET status = 'DELETED',
                        update_at = NOW()
                    WHERE ai_case_id = %s
                """, (ai_case_id,))

                # usede 操作履歴（p1 = 削除対象ユーザーの現在ポイント）
                cur.execute(
                    "SELECT point FROM users WHERE user_id = %s LIMIT 1",
                    (case_user_id,)
                )
                point_row = cur.fetchone()
                now_point = point_row["point"] if point_row and point_row["point"] is not None else 0

                cur.execute("""
                    INSERT INTO usede
                        (user_id, use_point, create_at, filename, pages_number,
                         ip, device, ai_case_id, p0, p1)
                    VALUES (%s, %s, NOW(), %s, %s, %s, %s, %s, '自動削除設定によりファイルを削除', %s)
                """, (
                    case_user_id,
                    0,
                    upload_file_name,
                    0,
                    "batch_cron",
                    "crontab",
                    ai_case_id,
                    now_point,
                ))

                cur.close()
                conn.commit()
                blog("  [DB] 論理削除 & 操作履歴記録 完了")
                total_deleted += 1

            except Exception as e:
                conn.rollback()
                blog("  [ERROR] DB 更新失敗: " + str(e))
                total_errors += 1
        else:
            blog("  [DRY_RUN] DB更新スキップ")
            total_deleted += 1

    # サマリ
    blog("=" * 60)
    blog("batch_delete_expired 完了")
    blog("  処理対象ケース数  : " + str(len(targets)))
    blog("  DB論理削除完了数  : " + str(total_deleted))
    blog("  GCS削除ファイル数 : " + str(total_gcs))
    blog("  エラー数          : " + str(total_errors))
    blog("  DRY_RUN           : " + str(DRY_RUN))
    blog("=" * 60)

    conn.close()


if __name__ == "__main__":
    main()
