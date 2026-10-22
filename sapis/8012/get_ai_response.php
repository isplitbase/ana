<?php

/**
 * ai_case の集計結果を読み込み、画像URLの再生成、ページ→シート種別推定、
 * OpenAI を使った並び替え（$aitext5）と整合性チェック（$aitext4）を実施し、
 * 整形した JSON を返却するエンドポイント。
 */

include "./common.php";
require 'vendor/autoload.php'; // AWS SDK がまだ読み込まれていない場合

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

// 未捕捉の例外・エラー時に AIERR をセット
set_exception_handler(function(Throwable $e) {
    global $conn, $ai_case_id;
    if (!empty($ai_case_id) && !empty($conn)) {
        $stmt_err = $conn->prepare("UPDATE ai_case SET status='AIERR', update_at=NOW() WHERE ai_case_id=?");
        if ($stmt_err) {
            $stmt_err->bind_param("i", $ai_case_id);
            $stmt_err->execute();
        }
    }
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

global $conn;
global $uploadDir;
global $backendbase;
global $python_base;
global $super_message;
global $s3_access_key;
global $s3_secret_key;
global $s3_region;
global $s3_bucket_name;
global $gcs_bucket;
global $google_application_credentials;
global $damy_flag;
global $point1_1;
global $point1_2;
global $point1_3;

// JSONデータを受け取って配列に変換
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

$loginkey = $data['loginkey'] ?? null;
$ai_case_id = $data['ai_case_id'] ?? null;

// ログイン必須 ＋ 案件が同じ会社のものか確認（loginkey が無い／他社の案件ならエラーで終了）
require_once __DIR__ . "/_case_access_helper.php";
$user_id = ana_require_case_access($loginkey, $ai_case_id);
if (!isset($ai_case_id) || $ai_case_id==null) {
    echo json_encode([
        "status" => "error",
        "message" => "異常アクセス"
    ]);
    exit;
}

// 読取直後の課金対象（do_ai_start=1）は、期数が P1・P2・P3 でなければ料金が決まらないため、
// 何も書き込まずに止める（do_ai.php でも同じ確認をしている）
$stmtPeriod = $conn->prepare("SELECT request, do_ai_start FROM ai_case WHERE ai_case_id = ?");
$stmtPeriod->bind_param("i", $ai_case_id);
$stmtPeriod->execute();
$rowPeriod = $stmtPeriod->get_result()->fetch_assoc();
$stmtPeriod->close();
if ($rowPeriod && (int)$rowPeriod['do_ai_start'] === 1) {
    $requestForPeriod = json_decode((string)$rowPeriod['request'], true);
    $periodForCharge = is_array($requestForPeriod) ? ($requestForPeriod['postingPeriod'] ?? null) : null;
    if (!in_array($periodForCharge, ['P1', 'P2', 'P3'], true)) {
        echo json_encode(["status" => "error", "message" => "異常アクセス"], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// AIST または 1000 の場合のみ status を更新
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status='FNST'
        ,financial_response=NULL
        ,report_response=NULL
        WHERE ai_case_id = ?
          AND status IN ('AIST', '1000')
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();

// 全てのログイン履歴を取得
$stmt = $conn->prepare("
    SELECT response,response_2,response_pdf,request,img_urls,upload_file_keys,upload_file_name,sizes,closing_date, closing_date_zenki, closing_date_zenzenki,financial_response,cf_data,do_ai_start
    FROM ai_case
    WHERE ai_case_id = ?
");
$stmt->bind_param("i", $ai_case_id);
$stmt->execute();
$result = $stmt->get_result();
$response="";
$response_2="";
$response_pdf="";
$financial_response="";
$cf_data="";
$request="";
$img_urls="";
$sizes="";
$upload_file_keys="";
$upload_file_name="";
$closing_date="";
$closing_date_zenki="";
$closing_date_zenzenki="";
$do_ai_start=0;
// 結果をループして出力
while ($re = $result->fetch_assoc()) {
    $response_2= $re['response_2'];
    $response_pdf = $re['response_pdf'];
    $response= $re['response'];
    $request= $re['request'];
    $img_urls= $re['img_urls'];
    $sizes= $re['sizes'];
    $upload_file_keys= $re['upload_file_keys'];
    $upload_file_name= $re['upload_file_name'];
    $closing_date= $re['closing_date'];
    $closing_date_zenki= $re['closing_date_zenki'];
    $closing_date_zenzenki= $re['closing_date_zenzenki'];
    $financial_response = $re['financial_response'];
    $cf_data = $re['cf_data'];
    $do_ai_start = (int)$re['do_ai_start'];
}

// JSONとしてデコード（1つずつ確かめる。以前は最後の response_2 の結果しか見ていなかった）
$json_errors = [];
$responseDecoded = json_decode((string)$response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $json_errors[] = "response: " . json_last_error_msg();
}
$responseDecoded_old = $responseDecoded;
// response_pdf は空の案件がある（空は従来どおり可）。中身があるときだけ確かめる
$response_pdf_raw = (string)$response_pdf;
$response_pdf = null;
if ($response_pdf_raw !== '') {
    $response_pdf = json_decode($response_pdf_raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $json_errors[] = "response_pdf: " . json_last_error_msg();
    }
}
$response_2Decoded = json_decode((string)$response_2, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $json_errors[] = "response_2: " . json_last_error_msg();
}
if ($json_errors) {
    $stmt_err = $conn->prepare("UPDATE ai_case SET status='AIERR', update_at=NOW() WHERE ai_case_id=?");
    $stmt_err->bind_param("i", $ai_case_id);
    $stmt_err->execute();
    echo json_encode([
        "status" => "error",
        "message" => "responseフィールドのJSONデコードに失敗しました: " . implode(" / ", $json_errors),
        "raw" => $response
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$sizesDecoded = null;
if($sizes!=null and !empty($sizes)){
    $sizesDecoded = json_decode($sizes, true);
}
$img_urls = explode('|,|', $img_urls);
$upload_file_keys = explode('|,|', $upload_file_keys);
$upload_file_name = explode('|,|', $upload_file_name);
// ==========================================================
// [FIX] upload_file_key / pdf_names の割当をページ単位に修正
//  旧実装の問題:
//   - img_urls          = 「ページ単位」(例: 3PDF x 4ページ = 12件)
//   - upload_file_keys  = 「PDF単位」(例: 3件)
//   - upload_file_name  = 「PDF単位」(例: 3件)
//   を同じ $i で突き合わせていたため、4ページ目以降は必ず null、
//   かつ 2,3ページ目も別PDFのキーが割り当てられていた。
//  新実装:
//   (1) request JSON (Cloud Run が保存したページ単位マップ) を最優先
//   (2) なければ変換後ファイル名 <ai_case_id>-<upload_file_key>-<NNN>.png から復元
//   (3) upload_file_key -> PDFファイル名 の対応表で pdf_names を補完
// ==========================================================
$key_to_name = array();
foreach ($upload_file_keys as $__ki => $__kv) {
    $__kv = trim($__kv);
    if ($__kv === "") { continue; }
    $key_to_name[$__kv] = (isset($upload_file_name[$__ki]) && $upload_file_name[$__ki] !== "") ? $upload_file_name[$__ki] : null;
}

// request JSON から page_no => (key, name) マップを作る
$req_page_map = array();
$__req_tmp = json_decode($request, true);
if (is_array($__req_tmp) && !empty($__req_tmp["images"]) && is_array($__req_tmp["images"])) {
    foreach ($__req_tmp["images"] as $__ri => $__rimg) {
        if (!is_array($__rimg)) { continue; }
        $__pno = isset($__rimg["page_no"]) ? (int)$__rimg["page_no"] : ($__ri + 1);
        $req_page_map[$__pno] = array(
            "key"  => (isset($__rimg["upload_file_key"]) && $__rimg["upload_file_key"] !== "") ? $__rimg["upload_file_key"] : null,
            "name" => (isset($__rimg["pdf_names"]) && $__rimg["pdf_names"] !== "") ? $__rimg["pdf_names"] : null,
        );
    }
}

$print_images = array();
for($i=0;$i<count($img_urls);$i++){
    $page_no = ($i+1);
    $one_url = isset($img_urls[$i]) ? $img_urls[$i] : null;

    $uf_key = null;
    $pdf_nm = null;

    // (1) request JSON 優先
    if (isset($req_page_map[$page_no]) && !empty($req_page_map[$page_no]["key"])) {
        $uf_key = $req_page_map[$page_no]["key"];
        $pdf_nm = $req_page_map[$page_no]["name"];
    }

    // (2) 変換後画像のファイル名から upload_file_key を復元
    if ($uf_key === null && !empty($one_url)) {
        foreach ($key_to_name as $__kcand => $__nouse) {
            if (strpos($one_url, "-" . $__kcand . "-") !== false) { $uf_key = $__kcand; break; }
        }
    }

    // (3) key から PDF ファイル名を補完
    if ($pdf_nm === null && $uf_key !== null && isset($key_to_name[$uf_key])) {
        $pdf_nm = $key_to_name[$uf_key];
    }

    $print_images[$i] = array();
    $print_images[$i]["page_no"]         = $page_no;
    $print_images[$i]["images_urls"]     = $one_url;
    $print_images[$i]["pdf_names"]       = $pdf_nm;
    $print_images[$i]["upload_file_key"] = $uf_key;
}
// GCS Signed URL 生成関数（1週間有効）
// $err_reason に失敗理由を書き戻す（成功時は null のまま）
function generateGcsSignedUrl($gs_uri, $key_file_path, &$err_reason = null) {
    $err_reason = null;

    if (empty($gs_uri)) {
        $err_reason = "empty gs_uri";
        return null;
    }
    // gs://bucket/object → bucket, object に分解
    if (!preg_match('#^gs://([^/]+)/(.+)$#', $gs_uri, $m)) {
        $err_reason = "uri format mismatch (expected gs://bucket/object): " . $gs_uri;
        return null;
    }
    $bucket_name = $m[1];
    $object_name = $m[2];

    if (empty($key_file_path)) {
        $err_reason = "google_application_credentials path is empty";
        return null;
    }
    if (!file_exists($key_file_path)) {
        $err_reason = "credentials file not found: " . $key_file_path;
        return null;
    }

    try {
        $storage = new \Google\Cloud\Storage\StorageClient([
            'keyFilePath' => $key_file_path,
        ]);
        $bucket = $storage->bucket($bucket_name);
        $object = $bucket->object($object_name);
        $expires = new \DateTime('+7 days');
        $signed = $object->signedUrl($expires, ['version' => 'v4']);
        if (empty($signed)) {
            $err_reason = "signedUrl returned empty (bucket=" . $bucket_name . ", object=" . $object_name . ")";
            return null;
        }
        return $signed;
    } catch (\Throwable $e) {
        $err_reason = get_class($e) . ": " . $e->getMessage();
        return null;
    }
}

// S3クライアントを作成（S3パス用・後方互換）
$s3Client = new S3Client([
    'version'     => 'latest',
    'region'      => $s3_region,
    'credentials' => [
        'key'    => $s3_access_key,
        'secret' => $s3_secret_key,
    ]
]);
$cpimage=[];
function extractS3ObjectKeyFromUrl($url) {
    $url = urldecode($url);
    $pattern = '/https:\/\/[^\/]+\/([a-zA-Z0-9_\-]+\.jpg)/';
    if (preg_match($pattern, $url, $matches)) {
        return $matches[1];
    }
    return null;
}

// 画像URL再生成処理
// （リファレンス &$image を使わず、$print_images[$i] に直接代入する方式）
foreach ($print_images as $i => $image) {
    $original_url = $image["images_urls"];

    if (!empty($original_url) && strpos($original_url, 'gs://') === 0) {
        // GCS パス → 元画像URIから _mini.jpg 版URIを導出して 1週間有効の Signed URL を生成
        //   例: gs://analygent/converted/204-currentTermBalanceSheet-001.png
        //   →   gs://analygent/converted/204-currentTermBalanceSheet-001_mini.jpg
        //  ※ DBの ai_case.img_urls は変更しない（このループは $print_images のみ書き換え）
        // _mini.jpg 版の gs:// URI を導出（preg_replace で末尾拡張子を _mini.jpg に置換）
        $mini_url_raw = preg_replace('#\.[^.]+$#', '_mini.jpg', $original_url);  // # デリミタ＆[^.]+ で / の衝突を回避
        $preg_err = preg_last_error();
        $preg_replaced = ($mini_url_raw !== $original_url);
        if ($mini_url_raw === null || $mini_url_raw === '') {
            $mini_url = $original_url;
        } else {
            $mini_url = $mini_url_raw;
        }

        // 診断: $original_url と $mini_url を必ずレスポンスに出す（成功/失敗どちらでも）
        $print_images[$i]["_debug_original_url"] = $original_url;
        $print_images[$i]["_debug_mini_url"]     = $mini_url;
        $print_images[$i]["_debug_preg_replaced"] = $preg_replaced;
        $print_images[$i]["_debug_preg_err"]     = $preg_err;

        $signed_err = null;
        $signed = generateGcsSignedUrl($mini_url, $google_application_credentials, $signed_err);
        $print_images[$i]["images_urls"] = $signed ?? $original_url;
        if ($signed === null) {
            // 失敗理由をレスポンスとエラーログに残す
            $print_images[$i]["images_url_error"] = $signed_err;
            $print_images[$i]["images_url_attempted"] = $mini_url;
            error_log("[get_ai_response] signedUrl failed: ai_case_id=" . ($ai_case_id ?? "?") .
                " page=" . ($print_images[$i]["page_no"] ?? "?") .
                " uri=" . $mini_url .
                " reason=" . $signed_err);
        }

    } else {
        // S3 パス → Presigned URL（従来処理）
        $key = extractS3ObjectKeyFromUrl($original_url);
        if (!empty($key)) {
            try {
                $cmd = $s3Client->getCommand('GetObject', [
                    'Bucket' => $s3_bucket_name,
                    'Key'    => $key
                ]);
                if ($cmd !== null) {
                    $request_s3 = $s3Client->createPresignedRequest($cmd, '+480 minutes');
                    $presignedUrl = (string) $request_s3->getUri();
                    $print_images[$i]["images_urls"] = $presignedUrl;
                    $cpimage[] = $key;
                } else {
                    $print_images[$i]["images_urls"] = null;
                }
            } catch (AwsException $e) {
                $print_images[$i]["images_urls"] = null;
            }
        }
    }
}

/////////////////////////
// 1. ページ番号と種類を取得（今期・前期・前々期すべて）
//    - BS / PL に加えて 「製造原価」「販売費」も対応
/////////////////////////

// ページごとに出現した type の集合
$page_types = []; // 例: [ 3 => ['BS'=>true, 'PL'=>true], 5 => ['販売費'=>true] ]

// 互換のために個別配列も引き続き保持
$bs_pages = [];
$pl_pages = [];
$cost_pages = []; // 製造原価
$sga_pages  = []; // 販売費

/**
 * 複合種別の正規化（順序固定）
 * - BS と PL のみ混在 → "BSPL"
 * - それ以外の複合は、以下の順に並べて '+' 連結
 *   ["PL+製造原価", "PL+販売費", "BS+製造原価", "BS+販売費", "製造原価+販売費"]
 *   を包含順に評価し、一般化として
 *     優先度配列 ["PL","製造原価","販売費","BS"]
 *   を用いた安定ソートで順序を固定
 * - 3種以上: "BSPL" を先頭にし、残りを優先度順で連結
 */
function canonicalize_sheet_kinds(array $kinds): string {
    // 存在しうる種別
    $valid = ['BS','PL','製造原価','販売費'];

    // 不正値除去 & 重複排除
    $uniq = [];
    foreach ($kinds as $k) {
        if (in_array($k, $valid, true)) $uniq[$k] = true;
    }
    $kinds = array_keys($uniq);
    sort($kinds); // 一旦安定化

    // 特例：BS と PL のみ → BSPL
    if ($kinds === ['BS','PL']) {
        return 'BSPL';
    }

    // 3種以上で BS と PL の両方を含む場合：先頭に BSPL を置き、 BS/PL を除外して残りを並べる
    $hasBS = in_array('BS', $kinds, true);
    $hasPL = in_array('PL', $kinds, true);
    $prefix = [];
    if ($hasBS && $hasPL) {
        $prefix[] = 'BSPL';
        // BS と PL を除去
        $kinds = array_values(array_diff($kinds, ['BS','PL']));
    }

    // 残りの並び順を固定
    // ご要望の「複合の並び」を実現するための優先度（左が高優先度）
    // 単体の結合順にもこの優先度を適用
    $priority = [
        'PL'     => 4, // ※残っていればここでの相対位置用（通常は残らない）
        '製造原価' => 3,
        '販売費'  => 2,
        'BS'     => 1, // ※残っていればここでの相対位置用（通常は残らない）
    ];
    usort($kinds, function($a,$b) use ($priority){
        $pa = $priority[$a] ?? 0;
        $pb = $priority[$b] ?? 0;
        if ($pa === $pb) return strcmp($a, $b); // 安定
        return ($pa > $pb) ? -1 : 1;
    });

    // 先頭に BSPL があればそれを付けてから残りを '+'
    if (!empty($prefix)) {
        if (empty($kinds)) return $prefix[0];
        return $prefix[0] . '+' . implode('+', $kinds);
    }

    // 単体ならそのまま、複合なら '+'
    if (count($kinds) <= 1) {
        return $kinds[0] ?? '';
    }
    return implode('+', $kinds);
}

// helper: kanjyokamoku 配列から page_no と type を収集
function collect_page_types_from_response2($kanjyokamoku_list, array &$page_types, array &$bs_pages, array &$pl_pages, array &$cost_pages, array &$sga_pages) {
    $supported = ['BS', 'PL', '製造原価', '販売費'];

    foreach ($kanjyokamoku_list as $item) {
        $type = $item['type'] ?? null;
        if (!$type || !in_array($type, $supported, true)) {
            continue; // 未サポート/未設定 type はスキップ
        }

        foreach (['今期', '前期', '前々期'] as $term) {
            $page = $item[$term]['page_no'] ?? null;
            if (empty($page)) continue;

            $p = (int)$page;
            if (!isset($page_types[$p])) $page_types[$p] = [];
            $page_types[$p][$type] = true;

            // 互換のための個別配列
            if ($type === 'BS')          $bs_pages[]  = $p;
            elseif ($type === 'PL')      $pl_pages[]  = $p;
            elseif ($type === '製造原価') $cost_pages[] = $p;
            elseif ($type === '販売費')   $sga_pages[]  = $p;
        }
    }
}

// 収集実行
$page_types = [];
$bs_pages = $pl_pages = $cost_pages = $sga_pages = [];
if (!empty($response_2Decoded['ai_response']) && is_array($response_2Decoded['ai_response'])) {
    foreach ($response_2Decoded['ai_response'] as $entry) {
        if (!empty($entry['kanjyokamoku'])) {
            collect_page_types_from_response2(
                $entry['kanjyokamoku'],
                $page_types, $bs_pages, $pl_pages, $cost_pages, $sga_pages
            );
        }
    }
}

// 重複除去
$bs_pages   = array_values(array_unique($bs_pages));
$pl_pages   = array_values(array_unique($pl_pages));
$cost_pages = array_values(array_unique($cost_pages));
$sga_pages  = array_values(array_unique($sga_pages));

/////////////////////////
// 2. page_no → sheet_kind マップ作成（正規化）
/////////////////////////
$page_sheet_map = [];
$all_pages = array_keys($page_types);

// 既存のマップがあれば利用
$re_page_sheet_map = [];
if (isset($response_2Decoded["page_sheet_map"]) && is_array($response_2Decoded["page_sheet_map"])) {
    $re_page_sheet_map = $response_2Decoded["page_sheet_map"];
}

// ページごとに種類をまとめて正規化
foreach ($all_pages as $page_no) {
    $kinds = array_keys($page_types[$page_no]); // 例: ['BS','PL'] / ['販売費'] / ['PL','製造原価'] ...
    $page_sheet_map[$page_no] = canonicalize_sheet_kinds($kinds);
}

// 既存マップにあるが今回検出できなかったページはそれを採用
foreach ($re_page_sheet_map as $p => $kind) {
    if (!isset($page_sheet_map[$p])) {
        // 既存値も正規化（古い "PL+BS" などがあれば矯正）
        $parts = array_filter(explode('+', (string)$kind), fn($x)=>$x!=='');
        $page_sheet_map[(int)$p] = canonicalize_sheet_kinds($parts);
        if (!in_array((int)$p, $all_pages, true)) $all_pages[] = (int)$p;
    }
}

// response にマップが無ければ追加し、DB へ保存（status=FNOK）
$response=$responseDecoded;
if(!isset($response["page_sheet_map"])){
    $response["page_sheet_map"]=$page_sheet_map;
}
$response=json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// AIST または 1000 の場合のみ status を更新
$stmt = $conn->prepare("
    UPDATE ai_case
        SET update_at=now()
        ,status='FNOK'
        ,response = ?
        ,financial_response=NULL
        ,report_response=NULL
        WHERE ai_case_id = ?
          AND status IN ('AIST', '1000')
");
$stmt->bind_param("si", $response, $ai_case_id);
$stmt->execute();

/////////////////////////
// 3. 各 print_images に sheet_kind を付加
/////////////////////////
foreach ($print_images as &$image) {
    $page_no = (int)$image["page_no"];
    if(isset($page_sheet_map[$page_no])){
        $image["sheet_kind"] = $page_sheet_map[$page_no]; // 例: "BS", "PL", "製造原価", "販売費", "PL+製造原価", "BSPL", "BSPL+製造原価"
    }else{
        $image["sheet_kind"] = "";
    }
}
unset($image);

$closing_dates=array();
$closing_dates["今期"]=$closing_date;
$closing_dates["前期"]=$closing_date_zenki;
$closing_dates["前々期"]=$closing_date_zenzenki;

/////////////////////////
if (!isset($response_2Decoded["tani"]) or ($response_2Decoded["tani"]!="単位：千円" and $response_2Decoded["tani"]!="単位：万円" and $response_2Decoded["tani"]!="単位：百万円")){
    $response_2Decoded["tani"]="";
}
$did_financial_flag = true;
if(empty($financial_response)){
    $did_financial_flag = false;
}
$did_cashflow_flag = true;
if(empty($cf_data)){
    $did_cashflow_flag = false;
}

global $openai_api_key;
global $model_str;
global $aitext4;
global $aitext5;

// APIエンドポイント
$url = "https://api.openai.com/v1/chat/completions";

/** ******************************************************************
 * (6) 並び替え（重要）：BS と PL を「別々のプロンプト」で並び替える
 *     - $aitext5."_bs" と $aitext5."_pl" を読み込み
 *     - それぞれ OpenAI に配列だけを渡し、配列だけを返してもらう
 ****************************************************************** */

$openai_url = "https://api.openai.com/v1/chat/completions";
$headers = [
    "Content-Type: application/json",
    "Authorization: Bearer $openai_api_key"
];

// --- プロンプトファイル存在チェック ---
$bs_prompt_path = $aitext5 . "_bs";
$pl_prompt_path = $aitext5 . "_pl";

if (!file_exists($bs_prompt_path)) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "system_promptファイルが見つかりません: {$bs_prompt_path}"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!file_exists($pl_prompt_path)) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => "system_promptファイルが見つかりません: {$pl_prompt_path}"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$system_prompt5_bs = file_get_contents($bs_prompt_path);
$system_prompt5_pl = file_get_contents($pl_prompt_path);

// --- OpenAI 呼び出し共通ヘルパー ---
function sort_section_with_openai($sectionName, $itemsArray, $systemPrompt, $model, $openai_url, $headers, &$raw_api_json_out = null,&$sendmessage) {
    // 念のため配列でなければそのまま返す
    if (!is_array($itemsArray)) return $itemsArray;

    // 「配列だけ」を返してもらうため、json_object で {"result":[...]} を期待する
    $messages = [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user",   "content" =>
            "次の配列（".$sectionName."）を、通常の表示順に並び替えてください。\n" .
            "配列の各要素（勘定科目・金額・page_no 等）の値は書き換えないでください。\n" .
            "応答は必ず JSON オブジェクト形式（response_format=json_object）で返し、\n" .
            "トップレベルは {\"list\": [...]} としてください。\n\n" .
            json_encode($itemsArray, JSON_UNESCAPED_UNICODE)
        ]
    ];
    $sendmessage=$messages;
    $payload = json_encode([
        "model"           => "gpt-5-mini",
        "messages"        => $messages,
        //"temperature"     => 0,
        "response_format" => ["type" => "json_object"]
    ]);

    $ch = curl_init($openai_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $raw = curl_exec($ch);
    curl_close($ch);

    // パース
    $json = json_decode($raw, true);
    $raw_api_json_out = $json; // デバッグ用に呼び出し側へも返す

    $content = $json["choices"][0]["message"]["content"] ?? null;
    if (!$content) return $itemsArray;

    $obj = json_decode($content, true);
    if (!is_array($obj)) return $itemsArray;

    // {"list":[...]} を期待
    if (isset($obj["list"]) && is_array($obj["list"])) {
        return $obj["list"];
    }

    // フォールバック：{"BS":[...]} または {"PL":[...]} で返ってきた場合にも対応
    if (isset($obj[$sectionName]) && is_array($obj[$sectionName])) {
        return $obj[$sectionName];
    }

    // 期待形式でなければ元配列を返す
    return $itemsArray;
}

// -----------------------------
// 空金額レコード削除（今期・前期・前々期の金額が全部 "" または null の場合は除外）
// -----------------------------
function all_three_amounts_empty($item) {
    $terms = ['今期', '前期', '前々期'];
    foreach ($terms as $t) {
        if (isset($item[$t]) && array_key_exists('金額', $item[$t])) {
            $amt = $item[$t]['金額'];
            if (!($amt === null || $amt === '')) {
                return false; // いずれかに値がある -> 削除しない
            }
        }
    }
    return true; // すべて空または null
}

foreach (['BS', 'PL'] as $section) {
    if (isset($responseDecoded[$section]) && is_array($responseDecoded[$section])) {
        $filtered = [];
        foreach ($responseDecoded[$section] as $rec) {
            if (!all_three_amounts_empty($rec)) {
                $filtered[] = $rec;
            }
        }
        $responseDecoded[$section] = array_values($filtered);
    }
}

// --- 並び替え（現状は停止フラグのまま） ---
$sort_api_json_bs = null;
$sort_api_json_bs_message = null;
if (isset($responseDecoded["BS"]) and false) {
    $responseDecoded["BS"] = sort_section_with_openai(
        "BS",
        $responseDecoded["BS"],
        $system_prompt5_bs,
        $model_str,
        $openai_url,
        $headers,
        $sort_api_json_bs,
        $sort_api_json_bs_message
    );
}
$sort_api_json_pl = null;
$sort_api_json_pl_message = null;
if (isset($responseDecoded["PL"]) and false) {
    $responseDecoded["PL"] = sort_section_with_openai(
        "PL",
        $responseDecoded["PL"],
        $system_prompt5_pl,
        $model_str,
        $openai_url,
        $headers,
        $sort_api_json_pl,
        $sort_api_json_pl_message
    );
}

// 並び替え適用後を DB に保存（最新の response を保持）
$responseAfterSort = json_encode($responseDecoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
$stmt = $conn->prepare("
    UPDATE ai_case
       SET update_at = NOW()
         , response  = ?
     WHERE ai_case_id = ?
");
$stmt->bind_param("si", $responseAfterSort, $ai_case_id);
$stmt->execute();

/////////////////////////////////////////////////////////////////
// do_ai_start==1 の場合のみポイント削減とusedeへの追加（do_ai.phpから移管）
/////////////////////////////////////////////////////////////////
if ($do_ai_start === 1) {
    // user情報を loginkey から取得
    $point_for_use = null;
    $user_id_for_use = null;
    $stmtU0 = $conn->prepare("
        SELECT u.point, u.user_id
        FROM users u
        JOIN login_history l ON u.user_id = l.user_id
        WHERE l.loginkey = ? AND u.status > 0 AND u.status < 999
        ORDER BY l.create_at DESC
        LIMIT 1
    ");
    $stmtU0->bind_param("s", $loginkey);
    $stmtU0->execute();
    $resU0 = $stmtU0->get_result();
    if ($rowU0 = $resU0->fetch_assoc()) {
        $point_for_use = $rowU0['point'];
        $user_id_for_use = $rowU0['user_id'];
    }
    $stmtU0->close();

    // request から postingPeriod と images を取得して totalPages を計算
    $requestArr = json_decode($request, true);
    $postingPeriod_local = $requestArr['postingPeriod'] ?? null;
    $images_local = $requestArr['images'] ?? [];
    $totalPages_local = 0;
    if (is_array($images_local)) {
        foreach ($images_local as $img) {
            if (empty($img["page_type_id"]) && !empty($img["page_type_ids"]) && count($img["page_type_ids"]) > 0) {
                $img["page_type_id"] = $img["page_type_ids"][0];
            }
            if (isset($img["page_type_id"]) && $img["page_type_id"] != 2) {
                $totalPages_local++;
            }
        }
    }

    // postingPeriod に基づく use_point 計算
    $use_point_local = 0;
    $postingPeriod_str_local = "(1期分)";
    switch ($postingPeriod_local) {
        case "P1":
            $use_point_local = $totalPages_local * $point1_1;
            break;
        case "P2":
            $use_point_local = $totalPages_local * $point1_2;
            $postingPeriod_str_local = "(2期分)";
            break;
        case "P3":
            $use_point_local = $totalPages_local * $point1_3;
            $postingPeriod_str_local = "(3期分)";
            break;
    }

    // usede に追加
    if ($use_point_local > 0 && $user_id_for_use) {
        $ip_p   = $_SERVER['REMOTE_ADDR'] ?? '';
        $dev_p  = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $file_p = '';
        $p0_label = 'PDF読取' . $postingPeriod_str_local;
        $stmtU = $conn->prepare("
            INSERT INTO usede (user_id, use_point, create_at, filename, pages_number, ip, device, ai_case_id, p0, p1)
            VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtU->bind_param("iisissisi", $user_id_for_use, $use_point_local, $file_p, $totalPages_local, $ip_p, $dev_p, $ai_case_id, $p0_label, $point_for_use);
        $stmtU->execute();
        $stmtU->close();
    }

    // do_ai_start を 0 に戻す（重複課金を防止）
    $stmtR = $conn->prepare("UPDATE ai_case SET do_ai_start = 0 WHERE ai_case_id = ?");
    $stmtR->bind_param("i", $ai_case_id);
    $stmtR->execute();
    $stmtR->close();
}

$checkreply=array();




$cash_list = [];
$sections = ['BS', 'PL', '販売費', '製造原価'];

foreach ($sections as $sec) {
    if (empty($responseDecoded[$sec]) || !is_array($responseDecoded[$sec])) continue;

    foreach ($responseDecoded[$sec] as $row) {
        $now    = $row['今期']   ?? [];
        $zenki  = $row['前期']   ?? [];
        $zenzen = $row['前々期'] ?? [];

        $cash_list[] = [
            '勘定科目' => $row['勘定科目'] ?? null,
            '分類'     => $row['分類'] ?? ($sec === '販売費' ? '販売費及び一般管理費' : $sec),
            'type'     => $sec, // "BS" / "PL" / "販売費" / "製造原価"
            '今期'     => [
                '金額'   => $now['金額'],
                'page_no'=> $now['page_no'],
            ],
            '前期'     => [
                '金額'   => $zenki['金額'],
                'page_no'=> $zenki['page_no'],
            ],
            '前々期'   => [
                '金額'   => $zenzen['金額'],
                'page_no'=> $zenzen['page_no'],
            ],
        ];
    }
}



/** ******************************************************************
 * (6) 整合性チェック（既存ロジックはコメントアウトのまま）
 * 必要に応じて復活させてください。
 ******************************************************************/

$checkreply = array();
$request=json_decode($request,true);
$request["status"]="success";
$request["images"]=$print_images;
$request["cpimage"]=$cpimage;
$request["message"]="分析完了";
$request["all_pages"]=$all_pages;
$request["page_sheet_map"]=$page_sheet_map;
$request["closing_dates"]=$closing_dates;
$request["response"]=$responseDecoded; // 並び替え適用後の最新版
// 開発・検証用の詳細情報を付与する対象かどうか
//   - 従来どおり user_id 96 / 97 / 98
//   - 追加: メールアドレスが @isplit.jp で終わるアカウント（社内ユーザー）
$user_email = '';
if (!empty($user_id)) {
    $stmtEm = $conn->prepare("SELECT email FROM users WHERE user_id = ? LIMIT 1");
    if ($stmtEm) {
        $stmtEm->bind_param("i", $user_id);
        $stmtEm->execute();
        $rowEm = $stmtEm->get_result()->fetch_assoc();
        $stmtEm->close();
        $user_email = (string)($rowEm['email'] ?? '');
    }
}
$is_debug_user = in_array((int)$user_id, [96, 97, 98], true);
if (!$is_debug_user && $user_email !== '') {
    // 大文字小文字を区別せず、末尾が @isplit.jp のものだけを対象にする
    $is_debug_user = (bool)preg_match('/@isplit\\.jp$/i', $user_email);
}
if ($is_debug_user) {
	$request['response_cash'] = ['list' => $cash_list];
	$request["response_org"]=$response_2Decoded;
	$request["responseDecoded_old"]=$responseDecoded_old;
	$request["response_pdf"]=$response_pdf;
	$request["sort_api_json_bs"]=$sort_api_json_bs;
	$request["sort_api_json_pl"]=$sort_api_json_pl;
	$request["sort_api_json_pl_message"]=$sort_api_json_pl_message;
	$request["sort_api_json_bs_message"]=$sort_api_json_bs_message;
}
$request["tani"]=$response_2Decoded["tani"];
$request["sizes"]=$sizesDecoded;
$request["bs_pages"]=$bs_pages;
$request["pl_pages"]=$pl_pages;
// 新規：製造原価・販売費のページ配列も返す
$request["cost_pages"]=$cost_pages; // 製造原価
$request["sga_pages"]=$sga_pages;   // 販売費
$request["did_financial_flag"]=$did_financial_flag;
$request["did_cashflow_flag"]=$did_cashflow_flag;
$request["check"]=$checkreply;
$request["check"]=$checkreply;
// JSONとして整形出力（日本語そのまま）
echo json_encode($request, JSON_UNESCAPED_UNICODE);
exit;
