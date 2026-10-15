# まとめてアップロード（bulk）

複数ファイルを一度に投入するための API 群。**バックエンドのみ**で、画面は未実装。

## 前提

- **1案件（ai_case）= 1ファイル**。ファイル名で会社を束ねることはしない。
  各社で命名規則が異なるため、現時点では規則に依存しない作りにしている。
- 1ファイルでも、日本の決算書は当期・前期が併記されているため
  `labels:["今期","前期"]` が返り P2 として扱える。CF計算も成立する。

## 流れ

```
[画面]                    [API]                         [ワーカー(cron毎分)]
 まとめてアップロード →  bulk_upload_init    batch_id発行
 ファイル3本ずつ送信  →  bulk_upload_add     GCS保存
 送信完了             →  bulk_upload_close   status=QUEUED
                                                   ↓
                                             期判定(ana-getpdfinfo 1ファイルずつ)
                                             整合チェック
                                             status=REVIEW
 5秒間隔でポーリング   →  bulk_upload_status  進捗取得
 確認画面表示         →  bulk_upload_review  A案/B案で粒度を変えて返す
 承認                 →  bulk_upload_approve ai_case作成 / status=APPROVED
                                                   ↓
                                             解析実行 → status=DONE
```

## なぜ分割送信なのか

PHP の `max_file_uploads = 20` のため、100本を1リクエストで送ると
**21本目以降が無言で捨てられる**（エラーも出ない）。
サーバ負荷も考慮し、1回 3 本ずつに制限している（`BULK_FILES_PER_REQUEST`）。

## なぜワーカー方式なのか

`Apache Timeout = 900秒` / `max_execution_time = 900秒` に対し、
100ファイルの判定・解析は数十分かかるため、Web リクエストでは完結しない。
`bulk_upload_close` はジョブを登録するだけで即座に返し、
cron で毎分起動するワーカーが処理する。

ワーカーを cron 方式にしたのは、プロセスが落ちても次回拾い直せるため。
Web から `exec()` で直接起動する方式だと、落ちたときに
「無言で止まったまま誰も気づかない」状態になる。

## 整合チェック

`ana-getpdfinfo` から得られるのは**年度まで**で、決算日（日付）は返らない
（`fiscal_end_date` は実装上つねに空）。そのため日付単位の照合は行わない。

| コード | 内容 |
|---|---|
| `UNDETECTED` | 期または年度を判定できなかった |
| `YEAR_RANGE` | 年度が未来、または20年以上前 |
| `LABEL_CONFLICT` | 同じ期ラベルが重複している |
| `DUPLICATE` | 同じファイル名＋同じ年度がバッチ内に既にある（二重投入の疑い） |

## 確認画面の方式

| mode | 粒度 | 備考 |
|---|---|---|
| `A` | ページ単位 | `pages[]` を返すが、画像は承認後の変換で作られるため判定時点では空 |
| `B` | ファイル単位 | 一覧表で承認する想定 |

`bulk_upload_review.php?only=warn` で**要確認のものだけ**返せる。
件数が多いときは全件を目視させるより現実的。

## ファイル

| ファイル | 役割 |
|---|---|
| `bulk_common.php` | 定数・ログ・GCS・バッチ操作の共通処理 |
| `bulk_detect.php` | 期判定（ana-getpdfinfo 呼び出し）と整合チェック |
| `bulk_worker.php` | cron から起動するワーカー |
| `bulk_upload_init.php` 〜 `bulk_upload_approve.php` | Web API 6本 |

## 配置

`sapis/` 直下に置く（`common.php` を相対 include するため）。

```
* * * * * /usr/bin/php /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_worker.php >> /var/log/ana/bulk_worker_cron.log 2>&1
```

ログは Web とワーカーで書き込み先を分けている
（`bulk_api_YYYYMMDD.log` / `bulk_worker_YYYYMMDD.log`）。
同一ファイルだと先に作った側の所有になり、もう一方が書けなくなるため。
