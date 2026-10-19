# まとめてアップロード（bulk）

複数ファイルを一度に投入するための API 群。
正式な画面は未実装で、動作確認用の画面を `manyup/index.html` に置いている。

## 前提

- **1案件（ai_case）= 1ファイル**。ファイル名で会社を束ねることはしない。
  各社で命名規則が異なるため、現時点では規則に依存しない作りにしている。
- 1ファイルでも、日本の決算書は当期・前期が併記されているため
  `labels:["今期","前期"]` が返り P2 として扱える。CF計算も成立する。
- 将来、複数ファイルを1案件にまとめられるよう、データとコードに
  入口を用意してある（後述「複数ファイルを1案件にまとめる場合」）。

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
 確認画面表示         →  bulk_upload_review  1ファイル1行で返す
 承認                 →  bulk_upload_approve ai_case作成 / status=APPROVED
                                                   ↓
                                             画像変換(pdf-converter)
                                             読取開始(do_ai.php)
                                             読取の完了を待つ
                                             結果の確定(get_ai_response.php)
                                             status=DONE
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
落ちたワーカーが掴んだままのファイルは、PID が消えていて一定時間
（`BULK_STALE_SECONDS`）を過ぎたら元の状態に戻して拾い直す。

## 並列数

| 段階 | 同時実行 | 理由 |
|---|---|---|
| 期判定 | ワーカー本数まで（既定3） | Cloud Run を呼ぶだけで、サーバの負荷は小さい |
| 解析 | `BULK_ANALYZE_PARALLEL`（1） | `do_ai.php` がこのVM上で Python を起動し、メモリを大きく使う |

2 vCPU / 7.7GB・swap なしの構成のため、解析を増やす場合は
`free -m` と load average を見ながら1ずつにすること。

## 読取の完了を待つ理由

`do_ai.php` は Python を裏に投げるだけで、すぐ success を返す。
読取結果を書いて `status` を `AIOK` にするのは裏の Python 側なので、
待たずに `get_ai_response.php` を呼ぶと空の結果を読んで `AIERR` になる。
ワーカーは案件の状態が処理中（`UPST` / `AIST` / `1000` 等）を抜けるまで待つ。

また `get_ai_response.php` は失敗しても HTTP 200 で `{"status":"error"}` を
返すため、応答の中身と、案件の状態・読取文字数の両方で成否を判定している。

## 整合チェック

`ana-getpdfinfo` から得られるのは**年度まで**で、決算日（日付）は返らない
（`fiscal_end_date` は実装上つねに空）。そのため日付単位の照合は行わない。

| コード | 内容 |
|---|---|
| `UNDETECTED` | 期または年度を判定できなかった |
| `YEAR_RANGE` | 年度が未来、または20年以上前 |
| `LABEL_CONFLICT` | 同じ期ラベルが重複している |
| `DUPLICATE` | 同じファイル名＋同じ年度がバッチ内に既にある（二重投入の疑い） |

## 確認画面

1ファイル1行の一覧で、期区分と「含める／除外」を確認する。
帳票種類（BS / PL / 販管費 など）は、承認後の画像変換時の自動判定をそのまま使う。

`bulk_upload_review.php?only=warn` で**要確認のものだけ**返せる。
件数が多いときは全件を目視させるより現実的。

> 以前はページ単位で1枚ずつ確認する方式（mode=A）も用意していたが、
> ページ画像は承認後の変換で初めて作られるため確認画面に出せず、
> 使われないままだったので削除した（2026-10-19）。
> `bulk_batches.mode` 列は残してあり、常に `B` が入る。

## 複数ファイルを1案件にまとめる場合

現在は 1ファイル=1案件だが、将来まとめられるよう次を用意してある。

**すでに用意してあるもの**

- `ai_case` はもともと複数ファイルに対応している
  （`upload_file_name` / `upload_file_keys` / `full_file_Paths` を `|,|` で連結。
  通常のアップロード画面でも2ファイルの案件が作られている）
- `bulk_batch_files.case_group` 列 — 同じ値のファイルを1案件にまとめる想定。
  `NULL` は単独で1案件。現在は常に `NULL`
- `bulk_create_case()`（`bulk_common.php`）— ファイルの**配列**を受け取って
  1案件を作る。現在は常に1件で呼んでいる

**改修が必要な箇所**

コード中に `【1案件=1ファイル前提】` の目印を付けてある。

```
grep -rn "【1案件=1ファイル前提】" sapis/bulk
```

| 箇所 | 今 | まとめる場合 |
|---|---|---|
| 組の決め方 | なし | 確認画面で人が組む、またはファイル名の規則で組む。結果を `case_group` に入れる |
| 期判定 `bulk_detect.php` | 1ファイルずつ `ana-getpdfinfo` | 同じ組をまとめて渡す（このAPIは本来「1社の複数ファイル」を受けて今期/前期/前々期を割り振る作り） |
| 承認 `bulk_upload_approve.php` | 1行ごとに案件を作る | `case_group` ごとに `bulk_create_case()` へまとめて渡す |
| 帳票キー | 期区分から1つ決める | ファイルごとに「どの期を含むか」から決める（例: 今期+前期 → `currentAndPreviousBalanceSheet`、前期+前々期 → `previousAndTwoAgoBalanceSheet`）。案件内で重複不可 |
| 解析 `bulk_analyze.php` | ファイル単位で取り出す | 同じ `ai_case_id` の行が複数になるので、案件単位で取り出し、結果も全行に反映する |
| 案件名 `bulk_case_name()` | 通し番号＋ファイル名 | 組の代表で名付けるなどの決めが要る |

## ファイル

| ファイル | 役割 |
|---|---|
| `bulk_common.php` | 定数・ログ・GCS・バッチ操作・案件作成の共通処理 |
| `bulk_detect.php` | 期判定（ana-getpdfinfo 呼び出し）と整合チェック |
| `bulk_analyze.php` | 解析（画像変換 → 読取 → 完了待ち → 確定） |
| `bulk_worker.php` | cron から起動するワーカー |
| `bulk_cron.sh` | ワーカーを既定本数まで起動する |
| `bulk_upload_init.php` 〜 `bulk_upload_approve.php` | Web API 6本 |

## 配置

`sapis/` 直下に置く（`common.php` を相対 include するため）。
置かれた場所から環境（8012 / 8056）を判断するので、両環境に同じものを置けばよい。

```
* * * * * /srv/www/apps/analygent_backend/releases/8056/sapis/bulk_cron.sh
* * * * * /srv/www/apps/analygent_backend/releases/8012/sapis/bulk_cron.sh
```

ログは環境と、Web / ワーカーで書き込み先を分けている
（`bulk_<port>_api_YYYYMMDD.log` / `bulk_<port>_worker_YYYYMMDD.log`）。
Web(www-data) と cron 実行ユーザーが同じファイルに書くと、
先に作った側の所有になり、もう一方が書けなくなるため。
