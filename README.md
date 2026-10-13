# ana — Analygent バックエンド（sapis）

Analygent 本体サーバ（GCP 34.180.79.93）の `sapis` 配下のうち、
修正を加えたファイルを管理するリポジトリです。

## 配置

| リポジトリ | サーバ上の実体 |
|---|---|
| `sapis/8012/` | `/srv/www/apps/analygent_backend/releases/8012/sapis/` （本番・DB `dbwindow`） |
| `sapis/8056/` | `/srv/www/apps/analygent_backend/releases/8056/sapis/` （開発・DB `dbtest1`） |

`8012` / `8056` は日付入りディレクトリへのシンボリックリンクです。
両環境でファイル内容が異なる場合があるため、環境ごとに分けて管理しています。

## 注意

- **本リポジトリに認証情報を含めないこと。**
  `internal/pf`（Stripe 本番キー・OpenAI・Gemini・AWS）と
  サービスアカウント鍵 JSON はサーバ上にのみ存在します。`.gitignore` で除外済みです。
- このリポジトリは **自動デプロイされません**。
  サーバへの反映は手動で行います（Cloud Run 用リポジトリとは異なります）。

## 管理対象ファイル

| ファイル | 役割 |
|---|---|
| `report_generation.php` | AI診断（レポート生成） |
| `upload_files.php` | PDFアップロードと PDF→画像変換の起動 |
| `ana-aiocr-photo.php` | 写真OCR（Cloud Run `ana-aiocr-photo`） |
