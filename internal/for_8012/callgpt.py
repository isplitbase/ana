import requests
import time
from datetime import datetime, timezone, timedelta
import openai
import json
import os
import sys
from pathlib import Path
import base64
from openai import OpenAI
from PIL import Image
import io
import mimetypes
import mysql.connector
from mysql.connector import Error
import traceback
from collections import defaultdict
try:
    import boto3
    _BOTO3_AVAILABLE = True
except ImportError:
    _BOTO3_AVAILABLE = False

import tempfile
try:
    from google.cloud import storage as gcs_storage
    _GCS_AVAILABLE = True
except ImportError:
    _GCS_AVAILABLE = False
import csv
import copy
from dateutil.relativedelta import relativedelta
from dateutil.parser import parse as parse_date
import re
import tiktoken
from typing import Any, List, Dict
import random
import concurrent.futures
from kessan_dates import extract_closing_dates_and_unit
import json
import os
import time
import threading
import cv2
from secrets import choice as secure_choice
from collections import defaultdict, Counter
from callgpt_ut import change_kanjyoukamoku_01
from callgpt_ut import change_kanjyoukamoku_03
from callgpt_ut import change_kanjyoukamoku_04
from callgpt_ut import change_kanjyoukamoku_05
from callgpt_ut import change_kanjyoukamoku_06
from callgpt_ut import change_kanjyoukamoku_07
from callgpt_ut import change_kanjyoukamoku_08
from callgpt_ut import change_kanjyoukamoku_09
from callgpt_ut import change_kanjyoukamoku_10
from callgpt_ut import change_kanjyoukamoku_11
from callgpt_ut import change_kanjyoukamoku_12
from callgpt_ut import change_kanjyoukamoku_13
from callgpt_ut import change_kanjyoukamoku_14
from callgpt_ut import change_kanjyoukamoku_15
from callgpt_ut import change_kanjyoukamoku_16
from callgpt_ut import change_kanjyoukamoku_17
from callgpt_ut import change_kanjyoukamoku_18
from callgpt_ut import change_kanjyoukamoku_19
from callgpt_ut import change_kanjyoukamoku_20
from callgpt_ut import change_kanjyoukamoku_21
from callgpt_ut import change_kanjyoukamoku_22
from callgpt_ut import change_kanjyoukamoku_23
from callgpt_toleft import callgpt_toleft
from resetline import resetline_miz
from callgpt_Gemini import analyze_pl_page_with_gemini
from callgpt_process_image_and_draw_boxes import process_image_and_draw_boxes
from callgpt_process_image_and_draw_boxes_pl_v2 import process_image_and_draw_boxes_pl
from callgpt_check_t import classify_bs_layout_by_gpt
import callgpt_tsg
start = time.time()
# Vision対応モデルの入力トークン単価（USD／1Kトークン）
INPUT_TOKEN_RATE = 0.03

# 画像トークン化ルール
BASE_TOKENS = 85
TILE_TOKENS = 170
def normalize_kessan_dates(response_sorted):
    # 今期の最初の決算年月日を取得（西暦前提・日本語付きでもOK）
    base_date_str = ""
    for section in response_sorted.values():
        for item in section:
            term_info = item.get("今期")
            if isinstance(term_info, dict):
                candidate = term_info.get("決算年月日", "")
                if candidate:
                    base_date_str = candidate
                    break
        if base_date_str:
            break

    if not base_date_str:
        print("⚠️ 今期の決算年月日が見つかりません。補完をスキップします。")
        return None

    # 「2025年03月31日」→「2025-03-31」に変換
    def normalize_japanese_date(text):
        text = re.sub(r"年|\.|/|－", "-", text)
        text = re.sub(r"月", "-", text)
        text = re.sub(r"日", "", text)
        return text

    try:
        base_date_str_normalized = normalize_japanese_date(base_date_str)
        base_dt = parse_date(base_date_str_normalized)
    except Exception as e:
        print(f"⚠️ 決算年月日パース失敗（西暦前提）: {base_date_str} → {e}")
        return None

    # 各期の日付を文字列（西暦）で作成
    date_map = {
        "今期": base_dt.strftime("%Y年%m月%d日"),
        "前期": (base_dt - relativedelta(years=1)).strftime("%Y年%m月%d日"),
        "前々期": (base_dt - relativedelta(years=2)).strftime("%Y年%m月%d日"),
    }

    # 各項目に反映
    for section in response_sorted.values():
        for item in section:
            for term in ["今期", "前期", "前々期"]:
                info = item.get(term)
                if isinstance(info, dict):
                    info["決算年月日"] = date_map[term]

    # 戻り値として渡す（DB更新用）
    return date_map["今期"], date_map["前期"], date_map["前々期"]
# --------------------------------------------------
# トークン数＆コスト計算関数
# --------------------------------------------------
def calc_tokens_and_cost(image_path: str):
    with Image.open(image_path) as img:
        width, height = img.size
    tiles_x = (width + 511) // 512
    tiles_y = (height + 511) // 512
    tile_count = tiles_x * tiles_y
    total_tokens = BASE_TOKENS + TILE_TOKENS * tile_count
    cost_usd = total_tokens * INPUT_TOKEN_RATE / 1000
    return width, height, tile_count, total_tokens, cost_usd

# Base64エンコード関数
def encode_image_to_base64(image):
    extension = os.path.splitext(image)[1].lower()
    mime_types = {
        ".jpg": "image/jpeg",
        ".jpeg": "image/jpeg",
        ".png": "image/png",
        ".gif": "image/gif",
        ".webp": "image/webp"
    }
    mime_type = mime_types.get(extension)
    if not mime_type:
        raise ValueError(f"サポートされていない画像形式です: {extension}")
    with open(image, "rb") as image_file:
        encoded_string = base64.b64encode(image_file.read()).decode('utf-8')
    return f"data:{mime_type};base64,{encoded_string}"
def file_to_data_url(image_path: str) -> str:
    mime = _guess_mime(image_path)  # 既にある関数を利用
    with open(image_path, "rb") as f:
        b64 = base64.b64encode(f.read()).decode("utf-8")
    return f"data:{mime};base64,{b64}"

def resize_image_to_canvas(input_path, output_path, default_width=2480, default_height=3508):
    with Image.open(input_path) as src_image:
        src_width, src_height = src_image.size

        # 横向きかどうかでキャンバスサイズを変更
        if src_width > src_height:
            target_width, target_height = default_height, default_width  # 横A4
        else:
            target_width, target_height = default_width, default_height  # 縦A4

        # アスペクト比を維持してリサイズ
        src_ratio = src_width / src_height
        target_ratio = target_width / target_height

        if src_ratio > target_ratio:
            new_width = target_width
            new_height = int(target_width / src_ratio)
        else:
            new_height = target_height
            new_width = int(target_height * src_ratio)

        # キャンバスを作成（白背景）
        canvas = Image.new("RGB", (target_width, target_height), (255, 255, 255))

        # 画像をリサイズ
        resized_img = src_image.resize((new_width, new_height), Image.LANCZOS)

        # 中央配置
        offset_x = (target_width - new_width) // 2
        offset_y = (target_height - new_height) // 2
        canvas.paste(resized_img, (offset_x, offset_y))

        # 保存
        canvas.save(output_path, format="JPEG", quality=90)

        return True
        
def _guess_mime(path: str) -> str:
    m, _ = mimetypes.guess_type(path)
    return m or "image/png"

# ─────────────────────────────────────────────
# GCS ヘルパー関数
# ─────────────────────────────────────────────

def _gcs_client():
    """GCS StorageClient を返す（GOOGLE_APPLICATION_CREDENTIALS 環境変数を使用）"""
    if not _GCS_AVAILABLE:
        raise RuntimeError("google-cloud-storage がインストールされていません。pip install google-cloud-storage を実行してください。")
    return gcs_storage.Client()

def _parse_gs_uri(gs_uri: str):
    """gs://bucket/path/to/obj → (bucket, object_name)"""
    m = re.match(r"^gs://([^/]+)/(.+)$", gs_uri)
    if not m:
        raise ValueError(f"無効な GCS URI: {gs_uri}")
    return m.group(1), m.group(2)

def download_gcs_to_local(gs_uri: str, dest_dir: str) -> str:
    """
    GCS オブジェクトをローカルにダウンロードして、ローカルパスを返す。
    dest_dir が存在しない場合は自動作成する。
    """
    bucket_n, obj_name = _parse_gs_uri(gs_uri)
    # サブディレクトリを "_" に置換してフラットなファイル名にする
    local_filename = obj_name.replace("/", "_")
    local_path = os.path.join(dest_dir, local_filename)
    os.makedirs(dest_dir, exist_ok=True)
    client = _gcs_client()
    bucket = client.bucket(bucket_n)
    blob = bucket.blob(obj_name)
    blob.download_to_filename(local_path)
    print(f"[GCS] ダウンロード完了: {gs_uri} → {local_path}")
    return local_path

def upload_to_gcs(local_path: str, bucket_n: str, object_name: str, content_type: str = "image/jpeg") -> str:
    """
    ローカルファイルを GCS へアップロードして gs:// URI を返す。
    """
    client = _gcs_client()
    bucket = client.bucket(bucket_n)
    blob = bucket.blob(object_name)
    blob.upload_from_filename(local_path, content_type=content_type)
    gs_uri = f"gs://{bucket_n}/{object_name}"
    print(f"[GCS] アップロード完了: {local_path} → {gs_uri}")
    return gs_uri

def is_bs_form(image_path: str) -> bool:
    """
    画像が『貸借対照表（BS）』かをOpenAIに判定させる。
    True: BS, False: それ以外
    """
    key = _pick_api_key()
    client = OpenAI(api_key=key)
    mime = _guess_mime(image_path)
    with open(image_path, "rb") as f:
        b64 = base64.b64encode(f.read()).decode("utf-8")
    data_url = f"data:{mime};base64,{b64}"

    prompt = (
        "以下の単一画像は日本語の会計帳票です。"
        "次の手順と基準で『貸借対照表（BS）かどうか』を厳密に判定してください。\n"
        "手順:\n"
        "1) 画像上部のタイトルを最優先で読み取る。\n"
        "   タイトルに「損益計算書」または「比較損益計算書」が含まれるなら is_bs=false とする（ここで決定）。\n"
        "2) タイトルで決まらない場合、本文の語をスキャンして以下の語のヒット数を数える。\n"
        "   - BS特有語: 貸借対照表, 資産の部, 負債の部, 純資産の部, 流動資産, 固定資産, 流動負債, 固定負債\n"
        "   - PL特有語: 損益計算書, 比較損益計算書, 売上高, 売上原価, 売上総利益, 販売費及び一般管理費, 販管費, 営業利益, 経常利益, 当期純利益\n"
        "判定基準:\n"
        " - is_bs=true は「BS特有語が2語以上ヒット」かつ「PL特有語が0語ヒット」のときのみ。\n"
        " - それ以外は is_bs=false。\n"
        "出力は JSON のみ。余計な文章は出力しない。形式:\n"
        '{\"is_bs\": true|false, \"title\": \"...\", \"bs_hits\": [\"...\"], \"pl_hits\": [\"...\"], \"confidence\": 0.0～1.0}\n'
        "confidence は上記基準への合致度に基づき 0.0～1.0 で与える。"
    )


    resp = client.chat.completions.create(
        model="gpt-4.1-mini-2025-04-14",
        temperature=0,
        messages=[{
            "role": "user",
            "content": [
                {"type": "text", "text": prompt},
                {"type": "image_url", "image_url": {"url": data_url}}
            ]
        }]
    )
    print("BS hantei:::::")
    print(resp.model_dump_json(indent=2, exclude_none=True))
    text = resp.choices[0].message.content or ""
    # JSONだけ返す想定だが、安全のため抽出
    m = re.search(r"\{.*\}", text, flags=re.S)
    if not m:
        return False
    try:
        payload = json.loads(m.group(0))
        return bool(payload.get("is_bs"))
    except Exception:
        return False

def deskew_image_bytes(data: bytes, ext: str = ".png") -> bytes:
    """
    data: 画像バイト列（JPEG/PNGなど）
    ext : 出力フォーマット（'.png' や '.jpg' など）
          もとの形式に合わせたい場合は呼び出し側で指定

    失敗した場合は元の data をそのまま返す。
    """
    try:
        # bytes → OpenCV画像
        nparr = np.frombuffer(data, np.uint8)
        img = cv2.imdecode(nparr, cv2.IMREAD_COLOR)
        if img is None:
            return data  # 読み込み失敗時はそのまま返す

        # グレースケール＋白黒反転
        gray = cv2.cvtColor(img, cv2.COLOR_BGR2GRAY)
        gray = cv2.bitwise_not(gray)

        # 2値化
        _, thresh = cv2.threshold(
            gray, 0, 255, cv2.THRESH_BINARY | cv2.THRESH_OTSU
        )

        # 黒以外の座標を取得
        coords = np.column_stack(np.where(thresh > 0))
        if coords.size == 0:
            return data  # 画素が取れなければそのまま

        # 最小外接矩形から角度を取得
        angle = cv2.minAreaRect(coords)[-1]

        # OpenCV の仕様に合わせて調整
        if angle < -45:
            angle = -(90 + angle)
        else:
            angle = -angle

        #補正上限が必要かは要確認
        #MAX_ANGLE = 3.0  # ここを好みで調整（度数）
        #
        #angle = cv2.minAreaRect(coords)[-1]
        #
        #if angle < -45:
        #    angle = -(90 + angle)
        #else:
        #    angle = -angle
        #
        ## ここで安全のためにクリップ
        #if abs(angle) > MAX_ANGLE:
        #    # 異常に大きい角度は「おかしい」とみなして補正しない
        #    # もしくは MAX_ANGLE に丸める、どちらでもOK
        #    # 例1: 補正しない
        #    # return data
        #
        #    # 例2: 最大角度までに抑える
        #    angle = MAX_ANGLE if angle > 0 else -MAX_ANGLE





        # 回転
        (h, w) = img.shape[:2]
        center = (w // 2, h // 2)
        M = cv2.getRotationMatrix2D(center, angle, 1.0)
        rotated = cv2.warpAffine(
            img,
            M,
            (w, h),
            flags=cv2.INTER_LINEAR,
            borderMode=cv2.BORDER_REPLICATE,
        )

        # 再エンコード（ext でフォーマット指定）
        success, buf = cv2.imencode(ext, rotated)
        if not success:
            return data
        return buf.tobytes()

    except Exception:
        # 何かあったら元のデータを返す（安全側）
        return data



def rotate_image(path, angle,havebspl):
    """
    画像を -angle 度回転（Pillowは反時計が正なのでマイナス）。
    - 回転後を常にファイル保存（*_r.ext）
    - 回転後をA4キャンバスに合わせて縮小（*_mini.ext）
    - S3へは "mini" の中身をアップロード（オブジェクト名も mini のファイル名に揃える）
    - Data URI は「mini」ではなく「回転後フル解像度」を返す（用途に応じて mini にしてもOK）
    """
    with Image.open(path) as img:
        rotated = img.rotate(-angle, expand=True)

        root, ext = os.path.splitext(path)
        ext = ext.lower()
        if ext in [".jpg", ".jpeg"]:
            fmt = "JPEG"; mime = "image/jpeg"
        elif ext == ".png":
            fmt = "PNG";  mime = "image/png"
        elif ext == ".gif":
            fmt = "GIF";  mime = "image/gif"
        elif ext == ".webp":
            fmt = "WEBP"; mime = "image/webp"
        else:
            raise ValueError(f"サポートされていない画像形式: {ext}")

        # 回転後は必ず保存
        rotated_path = f"{root}_r{ext}"
        rotated.save(rotated_path, format=fmt)
        base_for_encode=rotated_path
        is_pl_ai=False
        if havebspl :
            base_for_encode=rotated_path
            try:
                is_bs_ai = is_bs_form(rotated_path)
            except Exception as e:
                print(f"[warn] BS判定に失敗: {e}; BSとして続行")
                is_bs_ai = True
            if is_bs_ai == False:
                is_pl_ai=True
                #resetline_miz(rotated_path)
                #callgpt_toleft(rotated_path,rotated_path+".toleft.ok.png")
                #base_for_encode=rotated_path+".toleft.ok.png"
        else :
            #resetline_miz(rotated_path)
            is_pl_ai=False
            #base_for_encode=rotated_path+".ok.png"
        # A4キャンバスへ縮小（mini）
        mini_path = f"{root}_mini{ext}"
        resize_image_to_canvas(rotated_path, mini_path)

        # mini 画像を GCS へアップロード（GCS 設定がある場合）
        # フォールバックとして S3 も残す
        _uploaded_gcs = False
        if gcs_bucket_name and _GCS_AVAILABLE:
            try:
                gcs_object_name = "converted/" + os.path.basename(mini_path)
                upload_to_gcs(mini_path, gcs_bucket_name, gcs_object_name, content_type=mime)
                _uploaded_gcs = True
            except Exception as _e:
                print(f"[GCS] mini アップロード失敗（S3 にフォールバック）: {_e}")
        if not _uploaded_gcs and _BOTO3_AVAILABLE:
            s3 = boto3.client(
                's3',
                aws_access_key_id=access_key,
                aws_secret_access_key=secret_key,
                region_name=region
            )
            with open(mini_path, "rb") as fmini:
                s3.upload_fileobj(fmini, bucket_name, os.path.basename(mini_path), ExtraArgs={'ContentType': mime})

        # Data URI は回転後フル解像度を返す（必要なら mini を返す実装に変えてOK）
        #buf = io.BytesIO()
        #rotated.save(buf, format=fmt)
        #base64_str = base64.b64encode(buf.getvalue()).decode("utf-8")
        with open(base_for_encode, "rb") as f:
            data = f.read()
        data = deskew_image_bytes(data, ext=".png")  # もとの形式に合わせて .jpg などに変更
        save_path = base_for_encode + ".cv2hosei.png"
        with open(save_path, "wb") as f:
            f.write(data)
        with open(f"{root}{ext}" + ".cv2hosei.png", "wb") as f:
            f.write(data)
        base64_str = base64.b64encode(data).decode("utf-8")
        #print("base64 :::::::")
        #print(base_for_encode)
        #print(base64_str)
        return f"data:{mime};base64,{base64_str}" ,is_pl_ai

def generate_image_type_description(image_types, image_kakudo, image_key):
    def desc_single(t: str) -> str:
        if t == "BS or PL":
            return "損益計算書または貸借対照表（または混在）"
        elif t == "販売費":
            return "販売費及び一般管理費帳票"
        elif t == "製造原価":
            return "製造原価帳票"
        elif t == "対象外":
            return "対象外の帳票"
        else:
            return f"不明な帳票種類（{t}）"

    # 3パターンの組み合わせ（順不同）を網羅
    pair_map = {
        frozenset({"BS or PL", "販売費"}): "販売費及び一般管理費帳票または損益計算書または貸借対照表（または混在）",
        frozenset({"販売費", "製造原価"}): "販売費及び一般管理費帳票または製造原価帳票",
        frozenset({"BS or PL", "製造原価"}): "製造原価帳票または損益計算書または貸借対照表（または混在）",
    }

    parts = []

    for idx, itype in enumerate(image_types):
        page = f"{idx+1}ページ目"

        # 正規化：配列/単体どちらでもリスト[str]に
        if isinstance(itype, (list, tuple)):
            candidates = [str(x) for x in itype]
        else:
            candidates = [str(itype)]

        # 重複除去した集合で判定（順不同対応）
        uniq_set = frozenset(candidates)

        # 1件のみ → 単体説明
        if len(uniq_set) == 1:
            body = desc_single(next(iter(uniq_set)))

        # 2件（想定ペア） → ペア専用文言
        elif len(uniq_set) == 2 and uniq_set in pair_map:
            body = pair_map[uniq_set]

        # 想定外（例：3種類以上や未知の値） → フォールバック
        else:
            # 安全に個別説明を「、または」で連結
            # 表示順は candidates の出現順を優先
            seen = set()
            ordered = []
            for c in candidates:
                if c not in seen:
                    ordered.append(c)
                    seen.add(c)
            mapped = [desc_single(c) for c in ordered]
            if len(mapped) == 0:
                body = "帳票種類が不明"
            elif len(mapped) == 1:
                body = mapped[0]
            elif len(mapped) == 2:
                body = f"{mapped[0]}または{mapped[1]}"
            else:
                body = "、".join(mapped[:-1]) + f"、または{mapped[-1]}"

        parts.append(f"{page}は{body}")
    summary = "、".join(parts)
    print("image_types:::")
    print(image_types[0])
    if len(image_types)>1 :
        print(image_types[1])
    print("summary:::")
    print(summary)
    # 汎用要件追加関数
    def append_common_requirements(aitext1_n,summary, term_keys):
        try:
            with open(aitext1_n, "r", encoding="utf-8") as f:
                aitext1_content = f.read()
                summary += aitext1_content.strip()
        except Exception as e:
            summary += f"\n***"
            return summary
        return summary
        
    # itype が "販売費" または "製造原価" を含むかどうかで分岐
    targets = {"販売費", "製造原価"}

    # itype を集合に正規化（文字列/配列どちらでも対応）
    vals = {itype} if isinstance(itype, str) else {str(v) for v in itype}
    if image_key == "currentTermBalanceSheet":
        summary += "。この帳票の期間は今期です。\n"
        if targets & vals: 
            if len(uniq_set) == 1 and "販売費" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_4",summary, ["今期"])
            elif len(uniq_set) == 1 and "製造原価" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_5",summary, ["今期"])
            else:
                summary = append_common_requirements(aitext1+"_1_2",summary, ["今期"])
        else :
            summary = append_common_requirements(aitext1+"_1",summary, ["今期"])

    elif image_key == "previousTermBalanceSheet":
        summary += "。この帳票の期間は前期です。\n"
        if targets & vals: 
            if len(uniq_set) == 1 and "販売費" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_4",summary, ["前期"])
            elif len(uniq_set) == 1 and "製造原価" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_5",summary, ["前期"])
            else:
                summary = append_common_requirements(aitext1+"_1_2",summary, ["前期"])
        else :
            summary = append_common_requirements(aitext1+"_1",summary, ["前期"])

    elif image_key == "twoTermsAgoBalanceSheet":
        summary += "。この帳票の期間は前々期です。\n"
        if targets & vals: 
            if len(uniq_set) == 1 and "販売費" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_4",summary, ["前々期"])
            elif len(uniq_set) == 1 and "製造原価" in uniq_set:
                summary = append_common_requirements(aitext1+"_1_5",summary, ["前々期"])
            else:
                summary = append_common_requirements(aitext1+"_1_2",summary, ["前々期"])
        else :
            summary = append_common_requirements(aitext1+"_1",summary, ["前々期"])

    elif image_key == "currentAndPreviousBalanceSheet":
        summary += "。この帳票の期間は今期・前期です。\n"
        if targets & vals: 
            summary = append_common_requirements(aitext1+"_2_2",summary, ["今期", "前期"])
        else :
            summary = append_common_requirements(aitext1+"_2",summary, ["今期", "前期"])

    elif image_key == "previousAndTwoAgoBalanceSheet":
        summary += "。この帳票の期間は今期・前期です。\n"
        if targets & vals: 
            summary = append_common_requirements(aitext1+"_2_2",summary, ["今期", "前期"])
        else :
            summary = append_common_requirements(aitext1+"_2",summary, ["今期", "前期"])
        
    elif image_key == "twoAgoAndTwoPeriodsAgoBalanceSheet":
        summary += "。この帳票の期間は今期・前期です。\n"
        if targets & vals: 
            summary = append_common_requirements(aitext1+"_2_2",summary, ["今期", "前期"])
        else :
            summary = append_common_requirements(aitext1+"_2",summary, ["今期", "前期"])
    elif image_key == "doublePreviousAndTwoAgoBalanceSheet":
        summary += "。この帳票の期間は今期・前期・前々期です。\n"
        if targets & vals: 
            summary = append_common_requirements(aitext1+"_3_2",summary, ["今期", "前期", "前々期"])
        else :
            summary = append_common_requirements(aitext1+"_3",summary, ["今期", "前期", "前々期"])

    return summary

def write_progress(message, file_path):
    with open(file_path, "w", encoding="utf-8") as f:
        json.dump({"message": message}, f, ensure_ascii=False)

def parse_amount(val):
    try:
        if val in ("", None):
            return ""
        return int(str(val).replace(",", ""))
    except:
        return ""
def _ensure_term_dict(v):
    """期の値が dict でなければ空の器にする。"""
    if isinstance(v, dict):
        return {"金額": v.get("金額", ""), "page_no": v.get("page_no", "")}
    return {"金額": "", "page_no": ""}

def _clear_term(d, term):
    d[term]["金額"] = ""
    d[term]["page_no"] = ""

def _move_term(src_dict, dst_dict, src_term, dst_term):
    """src_term の金額/page_no を dst_term へコピー（上書き）。"""
    if src_dict[src_term]["金額"] != "":
        dst_dict[dst_term]["金額"] = src_dict[src_term]["金額"]
        dst_dict[dst_term]["page_no"] = src_dict[src_term]["page_no"]
def _delayed_openai_call(delay_s, messages, model_str, JSON_SCHEMA_KANJYO):
    # ワーカー側で待つ（他の画像のタスク提出はブロックしない）
    if delay_s and delay_s > 0:
        time.sleep(delay_s)
    return _call_openai_with_fallback(messages, model_str, JSON_SCHEMA_KANJYO)
def _call_gemini_for_pl(image_path, page_no, extra_prompt):
    """
    PLページ専用:
    - callgpt_Gemini.analyze_pl_page_with_gemini を呼ぶ
    - OpenAI と同じ (content, mode, used_key) 形式で返す
    """
    from callgpt_Gemini import analyze_pl_page_with_gemini  # 遅延インポート

    try:
        print(image_path)
        print("GEMINI_BASE_PROMPT::::::::::::::::::")
        print(GEMINI_BASE_PROMPT)
        
        rows = analyze_pl_page_with_gemini(
            image_path=image_path,
            page_no=page_no,
            api_key=gemini_api_key,
            base_prompt=GEMINI_BASE_PROMPT,
            extra_prompt=extra_prompt,
        )
    except Exception as e:
        print(f"[Gemini][ERROR] analyze_pl_page_with_gemini(pl) 失敗: {e}")
        rows = []

    # OpenAI 側と同じ {"list": [...]} 形式の JSON 文字列にして返す
    content = json.dumps({"list": rows}, ensure_ascii=False)
    return content, "Gemini", "Gemini"
def _call_gemini_for_seizougenka(image_path, page_no, extra_prompt):
    """
    PLページ専用:
    - callgpt_Gemini.analyze_pl_page_with_gemini を呼ぶ
    - OpenAI と同じ (content, mode, used_key) 形式で返す
    """
    from callgpt_Gemini import analyze_pl_page_with_gemini  # 遅延インポート

    try:
        print(image_path)
        print("GEMINI_BASE_PROMPT_SEIZOU::::::::::::::::::")
        print(GEMINI_BASE_PROMPT_SEIZOU)
        
        rows = analyze_pl_page_with_gemini(
            image_path=image_path,
            page_no=page_no,
            api_key=gemini_api_key,
            base_prompt=GEMINI_BASE_PROMPT_SEIZOU,
            extra_prompt=extra_prompt,
        )
    except Exception as e:
        print(f"[Gemini][ERROR] analyze_pl_page_with_gemini(seizougenka) 失敗: {e}")
        rows = []

    # OpenAI 側と同じ {"list": [...]} 形式の JSON 文字列にして返す
    content = json.dumps({"list": rows}, ensure_ascii=False)
    return content, "Gemini", "Gemini"
def _log_openai_response(label: str, resp):
    try:
        # v1 SDKのPydanticモデルならこれで綺麗にJSON出力できます
        print(f"\n===== {label}: raw OpenAI response =====")
        print(resp.model_dump_json(indent=2, exclude_none=True))
    except Exception:
        # 念のためのフォールバック
        try:
            print(resp)
        except Exception as e:
            print(f"[log error] {e}")
def remap_item_by_upload_key(item: dict, upload_key: str) -> dict:
    """
    期シフト仕様を適用した item を返す。
    - previousAndTwoAgoBalanceSheet:
        前期→前々期、今期→前期、今期は空に
    - twoAgoAndTwoPeriodsAgoBalanceSheet:
        今期→前々期、今期は空に、前期も空に
    それ以外: 変更なし
    """
    new_item = copy.deepcopy(item)

    # 期の器を保証
    for term in ("今期", "前期", "前々期"):
        new_item[term] = _ensure_term_dict(new_item.get(term))

    if upload_key == "previousAndTwoAgoBalanceSheet":
        # もとの値を参照用に確保
        src = {
            "今期": _ensure_term_dict(item.get("今期")),
            "前期": _ensure_term_dict(item.get("前期")),
            "前々期": _ensure_term_dict(item.get("前々期")),
        }
        # 前期→前々期
        _move_term(src, new_item, "前期", "前々期")
        # 今期→前期
        _move_term(src, new_item, "今期", "前期")
        # 今期を空に
        _clear_term(new_item, "今期")

    elif upload_key == "twoAgoAndTwoPeriodsAgoBalanceSheet":
        src = {
            "今期": _ensure_term_dict(item.get("今期")),
            "前期": _ensure_term_dict(item.get("前期")),
            "前々期": _ensure_term_dict(item.get("前々期")),
        }
        # 今期→前々期
        _move_term(src, new_item, "今期", "前々期")
        # 今期を空に
        _clear_term(new_item, "今期")
        # 前期も空に
        _clear_term(new_item, "前期")

    # 期以外のフィールド（勘定科目・分類など）は deepcopy 済みなので保持されます
    return new_item
# メイン処理
# APIキー読み込み
config_path = Path(__file__).parent / "pf"
with open(config_path, 'r') as f:
    db_config = json.load(f)
access_key = db_config["s3_access_key"]
secret_key = db_config["s3_secret_key"]
region = db_config["s3_region"]
bucket_name = db_config["bucket_name"]
uploadDir = db_config["uploadDir"]
model_str = db_config["model_str"]
aitext1 = db_config["aitext1"]

# GCS 設定
gcs_bucket_name = db_config.get("gcs_bucket", "")
_gcs_credentials = db_config.get("google_application_credentials", "")
if _gcs_credentials:
    os.environ["GOOGLE_APPLICATION_CREDENTIALS"] = _gcs_credentials

# ★ 追加: Gemini 用の設定###############################
gemini_api_key = db_config.get("gemini_api_key", "")
GEMINI_PROMPT_PATH = aitext1 + "_1-Gemini"

os.environ["GEMINI_CLOUDRUN_URL"] = "https://aitext1-1-gemini-512697354748.asia-northeast1.run.app/"
# GOOGLE_APPLICATION_CREDENTIALS は上記の gcs_credentials で設定済み
# 未設定の場合のみデフォルトパスにフォールバック
if not os.environ.get("GOOGLE_APPLICATION_CREDENTIALS"):
    os.environ["GOOGLE_APPLICATION_CREDENTIALS"] = "/root/gcp/gen-lang-client-0018414550-f50b079b0584.json"



try:
    with open(GEMINI_PROMPT_PATH, "r", encoding="utf-8") as f:
        GEMINI_BASE_PROMPT = f.read()
except Exception as e:
    print(f"[Gemini][WARN] ベースプロンプトファイルの読み込みに失敗しました: {GEMINI_PROMPT_PATH} ({e})")
    GEMINI_BASE_PROMPT = ""
GEMINI_PROMPT_PATH_SEIZOU = aitext1 + "_1_5-Gemini"
try:
    with open(GEMINI_PROMPT_PATH_SEIZOU, "r", encoding="utf-8") as f:
        GEMINI_BASE_PROMPT_SEIZOU = f.read()
except Exception as e:
    print(f"[Gemini][WARN] ベースプロンプトファイルの読み込みに失敗しました: {GEMINI_PROMPT_PATH_SEIZOU} ({e})")
    GEMINI_BASE_PROMPT_SEIZOU = ""
######################################################


try:
    if len(sys.argv) < 3:
        print("使用例: python3 azure_ai.py 'img1.jpg|,|img2.jpg' 'PL|,|PL=>BS'")
        exit(1)
    
    # 画像パスと種別を分割
    image_paths = sys.argv[1].split("|,|")
    image_types = sys.argv[2].split("|,|")
    image_kakudo = sys.argv[3].split("|,|")
    image_keys = sys.argv[4].split("|,|")
    ai_case_id = sys.argv[5]
    if len(image_paths) != len(image_types):
        print("エラー: 画像パスと帳票種類の数が一致しません")
        exit(1)

    # ─────────────────────────────────────────────
    # GCS パス（gs://）をローカルにダウンロード
    # ローカルパスはそのまま使用
    # ─────────────────────────────────────────────
    _gcs_tmp_dir = tempfile.mkdtemp(prefix="callgpt_gcs_")
    resolved_paths = []
    for _path in image_paths:
        _path = _path.strip()
        if _path.startswith("gs://"):
            try:
                _local = download_gcs_to_local(_path, _gcs_tmp_dir)
                resolved_paths.append(_local)
            except Exception as _e:
                print(f"エラー: GCS からの画像ダウンロードに失敗しました: {_path} ({_e})")
                exit(1)
        else:
            resolved_paths.append(_path)
    image_paths = resolved_paths

    # ファイル存在確認
    for path in image_paths:
        if not os.path.exists(path):
            print(f"エラー: 指定された画像ファイルが見つかりません: {path}")
            exit(1)
    write_progress("読取開始", db_config.get("www_dir") + "progress/" + ai_case_id)
    # データベースに接続
    _db_host = db_config["host"]
    _connect_args = dict(
        host=_db_host,
        database=db_config["dbname"],
        user=db_config["username"],
        password=db_config["password"],
    )
    # localhost の場合は Unix ソケットを優先（TCP が無効な環境対応）
    if _db_host in ("localhost", "127.0.0.1"):
        for _sock in [
            "/var/run/mysqld/mysqld.sock",
            "/tmp/mysql.sock",
            "/var/lib/mysql/mysql.sock",
        ]:
            if os.path.exists(_sock):
                _connect_args["unix_socket"] = _sock
                break
    connection = mysql.connector.connect(**_connect_args)
    api_key = db_config.get("openai_api_key")
    test_flag = db_config.get("test_flag")
    if not api_key:
        raise ValueError("OpenAI APIキーが設定されていません。")
    # （旧実装のAPIキー直書きは削除。キーは pf / 環境変数から取得する）
    client = OpenAI(api_key=api_key)

    all_results = []
    ttpage=len(image_paths)
    dopageno=0
    sizes = []
    encoded_urls = []
    encoded_urls1 = []
    encoded_urls2 = []
    is_pl_flags = []          # ★ 追加：各ページが PL かどうか
    have_seizougenka_flags = []          # ★ 追加：各ページが 製造原価 かどうか
    have_hanbaihi_flags = []          # ★ 追加：各ページが 販売費及び一般管理費 かどうか
    page_no_n = 0
    
    
    def fetch_old_response_2(connection, ai_case_id: str):
        """
        ai_case テーブルから ai_case_id に一致する 1行の response_2 を返す。
        見つからなければ None を返す。
        """
        cur = None
        try:
            cur = connection.cursor()
            sql = "SELECT response_2 FROM ai_case WHERE ai_case_id = %s LIMIT 1"
            cur.execute(sql, (ai_case_id,))
            row = cur.fetchone()
            return row[0] if row else None
        finally:
            if cur:
                cur.close()
    old_response_2_json = ""
    all_results = []
    if (len(sys.argv) >= 7) and (sys.argv[6] == "OK"):
        old_response_2 = fetch_old_response_2(connection, ai_case_id)
        if old_response_2:
            try:
                # JSON としてパースしてから再整形
                old_response_2_json = json.dumps(json.loads(old_response_2), ensure_ascii=False)
            except Exception:
                # JSON でなければそのまま文字列
                old_response_2_json = ""
        else:
            old_response_2_json = ""
    if old_response_2_json != "" :
        try:
            old_response_2_dict = json.loads(old_response_2_json)
            all_results = old_response_2_dict.get("ai_response", [])
        except Exception as e:
            print(f"old_response_2_json のパース失敗: {e}")
            all_results = []
    if len(all_results)>0 :
        print("all_results をDBから取得した！")
    else :
        ########################################
        ########################################
        # マルチ処理開始
        ########################################
        ########################################
        # マルチ処理開始（キー多重化＆ランダム選択対応）
        ########################################

        # from openai import OpenAI  # 既に import 済みなら不要

        # --- 複数キーの読み込み ---
        def _load_api_keys(db_config) -> list[str]:
            keys = []

            # 1) pf(JSON)に openai_api_keys があれば最優先（list想定）
            cfg_keys = db_config.get("openai_api_keys")
            if isinstance(cfg_keys, list):
                keys.extend([k.strip() for k in cfg_keys if isinstance(k, str) and k.strip()])

            # 2) 環境変数 OPENAI_API_KEYS（改行 or カンマ区切り）
            env_keys = os.getenv("OPENAI_API_KEYS", "")
            if env_keys:
                # 改行かカンマで分割
                parts = []
                for line in env_keys.splitlines():
                    parts.extend(line.split(","))
                keys.extend([p.strip() for p in parts if p.strip()])

            # 3) フォールバック：従来の単一キー
            single = db_config.get("openai_api_key", "")
            if single and single.strip():
                keys.append(single.strip())

            # 正規化：空除去＆重複排除
            uniq = []
            seen = set()
            for k in keys:
                if k and k not in seen:
                    uniq.append(k); seen.add(k)

            if not uniq:
                raise ValueError("OpenAI APIキーが1本も見つかりません。pfの openai_api_keys か OPENAI_API_KEYS を設定してください。")
            return uniq

        _API_KEYS = _load_api_keys(db_config)

        # キー選択（ランダム）。並行でも安全。
        def _pick_api_key() -> str:
            # secrets.choice で偏りを抑えつつランダムに
            return secure_choice(_API_KEYS)

        # 例外の種類は SDK により異なるので、ざっくりリトライする汎用ハンドラ
        def _is_rate_or_transient_error(exc: Exception) -> bool:
            s = str(exc).lower()
            # よくあるレート/一時エラーの断片
            return ("rate" in s) or ("429" in s) or ("timeout" in s) or ("temporar" in s) or ("overloaded" in s)

        def _call_openai_once(key: str, messages, model_str, JSON_SCHEMA_KANJYO):
            """単発呼び出し：指定キーで json_schema → 失敗したら json_object にフォールバック"""
            # （旧実装のAPIキー直書きは削除。キーは pf / 環境変数から取得する）
            client = OpenAI(api_key=key)
            try:
                resp = client.chat.completions.create(
                    model=model_str,
                    messages=messages,
                    temperature=0.0,
                    response_format={"type": "json_schema", "json_schema": JSON_SCHEMA_KANJYO}
                )
                _log_openai_response("json_schema try", resp) 
                return resp.choices[0].message.content.strip(), "json_schema"
            except Exception:
                # schema失敗時はjson_objectでフォールバック
                resp = client.chat.completions.create(
                    model=model_str,
                    messages=messages,
                    temperature=0.0,
                    response_format={"type": "json_object"}
                )
                _log_openai_response("json_schema try", resp) 
                return resp.choices[0].message.content.strip(), "json_object"

        def _call_openai_with_fallback(messages, model_str, JSON_SCHEMA_KANJYO,
                                       max_attempts: int = 3, base_backoff: float = 0.4):
            """
            毎回ランダムでキーを選び、失敗時は別キーでリトライ（指数バックオフ）。
            戻り値: (content_str, mode_str, used_key)
            """
            last_err = None
            for attempt in range(max_attempts):
                key = _pick_api_key()
                try:
                    content, mode = _call_openai_once(key, messages, model_str, JSON_SCHEMA_KANJYO)
                    print("_call_openai_once mode:::::: ")
                    print(model_str)
                    return content, mode, key
                except Exception as e:
                    last_err = e
                    # レート/一時エラーっぽければ他キーで再試行
                    if _is_rate_or_transient_error(e) and attempt < max_attempts - 1:
                        # ちょい待ってから次へ（指数＋ランダムジッター）
                        sleep_sec = base_backoff * (4 ** attempt) * (0.75 + random.random() * 0.5)
                        time.sleep(sleep_sec)
                        continue
                    # 恒久的エラーなどは即終了
                    break
            # ここまで来たら失敗
            raise last_err

        def _safe_list_len_from_content(content: str) -> int:
            try:
                obj = json.loads(content)
                lst = obj.get("list", [])
                return len(lst) if isinstance(lst, list) else 0
            except Exception:
                return 0

        def _choose_best_content_by_list_count(run_results):
            """
            run_results: List[Tuple[content:str, mode:str, key:str]]
            list件数が最大のものを返す。
            戻り: (best_content, best_mode, best_key, best_len)
            """
            best_content, best_mode, best_key, best_len = None, None, None, -1
            for content, mode, key in run_results:
                l = _safe_list_len_from_content(content)
                if l > best_len:
                    best_content, best_mode, best_key, best_len = content, mode, key, l
            return best_content, best_mode, best_key, best_len


        def _normalize_number(v):
            if v is None: return None
            if isinstance(v, (int, float)): return v
            if isinstance(v, str):
                s = v.strip()
                if s == "": return None
                s = (s.replace(",", "")
                       .replace("−","-").replace("－","-")
                       .replace("–","-").replace("—","-"))
                try:
                    n = float(s)
                    return int(n) if n.is_integer() else n
                except:
                    return None
            return None

        def _safe_int(x):
            try:
                return int(x)
            except:
                return None

        def _get_amt_page(item, period_key):
            obj = item.get(period_key, {}) if isinstance(item.get(period_key, {}), dict) else {}
            amt = _normalize_number(obj.get("金額", None))
            pno = obj.get("page_no", None)
            return amt, _safe_int(pno)

        def _parse_if_needed(x):
            if isinstance(x, dict): return x
            if isinstance(x, str):
                try: return json.loads(x)
                except: return {"list": [], "ログ": "PARSE_FAILED"}
            return {"list": [], "ログ": "INVALID_INPUT"}

        def _is_empty_current_amount(item):
            if not isinstance(item, dict): return True
            now = item.get("今期", {})
            v = now.get("金額", "")
            return (v is None) or (isinstance(v, str) and v.strip() == "")

        def _order_key_for_record(item, pos, ai_priority_rank):
            pages = []
            for p in ("今期", "前期", "前々期"):
                pg = _safe_int(item.get(p, {}).get("page_no"))
                if pg is not None:
                    pages.append(pg)
            base_page = min(pages) if pages else 10**6
            return (base_page, pos, ai_priority_rank)

        def _trim_after_pl_final(rows):
            """『当期純利益(損失)/当期純利益/当期純損失』の最後の出現以降の PL を削除"""
            PL_FINAL_ALIASES = {"当期純利益(損失)", "当期純利益", "当期純損失"}
            last_idx = None
            for i, it in enumerate(rows):
                if it.get("type") == "PL" and it.get("分類") in PL_FINAL_ALIASES:
                    last_idx = i
            if last_idx is None:
                return rows
            head = rows[: last_idx + 1]
            tail = rows[last_idx + 1:]
            tail_keep = [r for r in tail if r.get("type") != "PL"]
            return head + tail_keep

        def _trim_after_bs_total_on_last_page(rows):
            """『負債純資産合計』の“最終ページ上での”最後の出現以降の BS を削除（PLは残す）"""
            def _safe_page(it):
                try:
                    p = it.get("今期", {}).get("page_no", None)
                    return int(str(p).strip()) if p is not None else None
                except:
                    return None

            # 全体の最終ページを特定
            max_page = None
            for it in rows:
                pg = _safe_page(it)
                if pg is not None:
                    max_page = pg if max_page is None else max(max_page, pg)

            last_idx = None
            last_page = None
            for i, it in enumerate(rows):
                if it.get("type") == "BS" and it.get("分類") == "負債純資産合計":
                    last_idx = i
                    last_page = _safe_page(it)

            if last_idx is None or last_page is None or max_page is None or last_page != max_page:
                return rows

            head = rows[: last_idx + 1]
            tail = rows[last_idx + 1:]
            tail_keep = [r for r in tail if r.get("type") != "BS"]  # PLは残す
            return head + tail_keep

        def _majority_amount_and_page(votes, prefer=("ai1","ai2","ai3")):
            """
            votes: list[(ai_name, (amount, page_no))]
            金額は“数値に解釈できたもの”のみ多数決。タイは prefer 優先。
            page_no は“勝ち金額の支持者だけ”で多数決。
            """
            filtered = [(ai, amt, pg) for (ai,(amt,pg)) in votes if (amt is not None or pg is not None)]
            if not filtered:
                return ("", "")  # 空で返す（上流は "" を空扱いしている）

            amt2support = defaultdict(set)
            amt2pages_by_ai = defaultdict(dict)
            for ai, amt, pg in filtered:
                if amt is not None:
                    amt2support[amt].add(ai)
                    if pg is not None:
                        amt2pages_by_ai[amt][ai] = pg

            if not amt2support:
                # 金額が誰からも出なかった→ページのみ過半（稀）
                pages = [pg for (_,_,pg) in filtered if pg is not None]
                if not pages:
                    return ("", "")
                cntp = Counter(pages).most_common()
                top_pages = {p for p,n in cntp if n == cntp[0][1]}
                # prefer に載っているAIの票を優先
                chosen_pg = None
                for pref in prefer:
                    for (ai,_,pg) in filtered:
                        if pg in top_pages and ai == pref:
                            chosen_pg = pg; break
                    if chosen_pg is not None: break
                if chosen_pg is None:
                    chosen_pg = cntp[0][0]
                return ("", chosen_pg)

            # 金額の支持数
            best_support = max(len(s) for s in amt2support.values())
            tie_amts = [a for a,s in amt2support.items() if len(s) == best_support]

            if len(tie_amts) == 1:
                chosen_amt = tie_amts[0]
            else:
                # タイブレークは prefer 優先
                chosen_amt = None
                for pref in prefer:
                    for a in tie_amts:
                        if pref in amt2support[a]:
                            chosen_amt = a; break
                    if chosen_amt is not None: break
                if chosen_amt is None:
                    chosen_amt = sorted(tie_amts)[0]

            # ページは勝ち金額の支持者だけ
            pages = [pg for pg in amt2pages_by_ai[chosen_amt].values() if pg is not None]
            if pages:
                cntp = Counter(pages).most_common()
                top_pages = {p for p,n in cntp if n == cntp[0][1]}
                chosen_pg = None
                for pref in prefer:
                    if pref in amt2pages_by_ai[chosen_amt] and amt2pages_by_ai[chosen_amt][pref] in top_pages:
                        chosen_pg = amt2pages_by_ai[chosen_amt][pref]; break
                if chosen_pg is None:
                    chosen_pg = cntp[0][0]
            else:
                chosen_pg = ""

            return (chosen_amt, chosen_pg)

        def _choose_best_content_by_majority(ai1_content, ai2_content, ai3_content, image_type=None):
            import json, re, copy
            from collections import Counter

            print("ai1::::")
            print(json.dumps(ai1_content, ensure_ascii=False, indent=2))
            print("ai2::::")
            print(json.dumps(ai2_content, ensure_ascii=False, indent=2))
            print("ai3::::")
            print(json.dumps(ai3_content, ensure_ascii=False, indent=2))
            """
            入力: 各AIのページ結果（JSON文字列 or dict）。トップレベルに "list" があることを想定。
            出力: (best_content_json_str, best_mode, best_key, best_len)
              - best_mode: "majority"
              - best_key:  "row_union"  （行ユニオン＋多数決に変更）
              - best_len:  len(best["list"])
            """

            # ===== ユーティリティ（空判定＆正規化） =====
            EMPTY_TOKENS = {None, "", "-", "—"}

            def _is_empty_amount(v):
                if v in EMPTY_TOKENS:
                    return True
                s = str(v).strip()
                if not s:
                    return True
                s = s.replace(",", "")
                # △ / () は負数表現だが「非空」
                if s.startswith("△"):
                    return False
                if s.startswith("(") and s.endswith(")"):
                    return False
                # 数字以外しか無いなら空扱い
                return not s.replace("+", "").replace("-", "").isdigit()

            def _normalize_amount(v):
                if _is_empty_amount(v):
                    return None
                s = str(v).strip().replace(",", "")
                neg = False
                if s.startswith("△"):
                    neg, s = True, s[1:]
                if s.startswith("(") and s.endswith(")"):
                    neg, s = True, s[1:-1]
                try:
                    n = int(s)
                except Exception:
                    return None
                return -abs(n) if neg else n

            # amount を多数決（空は票に含めない）
            def _majority_amount(values):
                """
                values: [val_ai1, val_ai2, val_ai3]  # 期別の金額
                ルール:
                  - 空は票に含めない
                  - 同一非空が2票以上で採用
                  - 2票無くても非空が1つだけなら採用
                  - それ以外は None
                """
                parsed = []
                for v in values:
                    nv = _normalize_amount(v)
                    if nv is not None:
                        parsed.append(nv)
                if not parsed:
                    return None
                cnt = Counter(parsed)
                top, freq = cnt.most_common(1)[0]
                if freq >= 2:
                    return top
                if len(parsed) == 1:
                    return parsed[0]
                return None

            # page_no は「金額が採用されたAIの page」を優先。なければ多数決で最頻の非空を採用。
            def _choose_page(votes, chosen_amount):
                """
                votes: [(ai, (amt, page_no)), ...]
                chosen_amount: 最終採用の数値（int） or None
                """
                # 1) 金額一致のAIの page を拾う（最初に見つかった非空を採用）
                if chosen_amount is not None:
                    for ai, (amt, pg) in votes:
                        nv = _normalize_amount(amt)
                        if nv is not None and nv == chosen_amount and pg not in (None, ""):
                            return pg
                # 2) 非空 page の多数決（最頻）
                pages = [pg for _, (_, pg) in votes if pg not in (None, "")]
                if not pages:
                    return ""
                cnt = Counter(pages)
                return cnt.most_common(1)[0][0]

            # ===== 見出し系のホワイトリスト（必要なら使う） =====
            _LEADING_NUM = re.compile(
                r'^\s*(?:第?\s*)?(?:[0-9０-９]+|[IVXLCDMivxlcdm]+|[①-⑳㊀-㊉])(?:[\.．、)\］\]：:\-–—\s]*)'
            )
            def _base_heading(s: str) -> str:
                if not isinstance(s, str):
                    return ""
                t = s.strip()
                t = _LEADING_NUM.sub("", t)            # 先頭の番号類を除去
                t = re.sub(r'(?:合計|計)$', '', t)     # 末尾の「…合計/…計」を除去
                return t

            EXEMPT_HEADINGS = {
                "流動資産",
                # 必要に応じて追加
            }

            # ===== 入力をパース（dict/JSONの双方対応） → deepcopy（in-place 汚染対策） =====
            def _parse_if_needed_local(x):
                # 既存の _parse_if_needed を優先して使う（定義が無い場合のフォールバック）
                try:
                    return _parse_if_needed(x)
                except NameError:
                    if isinstance(x, dict):
                        return x
                    if isinstance(x, str):
                        try:
                            return json.loads(x)
                        except Exception:
                            return {"list": []}
                    return {"list": []}

            data1 = copy.deepcopy(_parse_if_needed_local(ai1_content))
            data2 = copy.deepcopy(_parse_if_needed_local(ai2_content))
            data3 = copy.deepcopy(_parse_if_needed_local(ai3_content))

            lists = {
                "ai1": data1.get("list", []) if isinstance(data1.get("list", []), list) else [],
                "ai2": data2.get("list", []) if isinstance(data2.get("list", []), list) else [],
                "ai3": data3.get("list", []) if isinstance(data3.get("list", []), list) else [],
            }

            # ===== 前処理（あなたの既存ロジックを適用） =====
            def _normalize_list_with_pages(row_list):
                row_list = change_kanjyoukamoku_13(row_list)
                row_list = change_kanjyoukamoku_08(copy.deepcopy(row_list))  # 念のためコピー
                wrapped = [{"page_no": 1, "kanjyokamoku": row_list}]
                out = change_kanjyoukamoku_01(wrapped, in_place=False)
                return out[0]["kanjyokamoku"]

            lists["ai1"] = _normalize_list_with_pages(lists["ai1"])
            lists["ai2"] = _normalize_list_with_pages(lists["ai2"])
            lists["ai3"] = _normalize_list_with_pages(lists["ai3"])

            
            one_type = "NG"
            # image_type を一旦 set に正規化する
            labels = set()
            if isinstance(image_type, (list, tuple, set)):
                # ["販売費"] みたいなパターン
                for v in image_type:
                    if v is None:
                        continue
                    s = str(v).strip()
                    if s:
                        labels.add(s)
            elif image_type is not None:
                # "販売費" みたいな単体文字列
                s = str(image_type).strip()
                if s:
                    labels.add(s)

            # 「xxx しかない」場合だけを判定
            if labels == {"BS or PL"}:
                one_type = "BS or PL"
            elif labels == {"販売費"}:
                one_type = "販売費"
            elif labels == {"製造原価"}:
                one_type = "製造原価"
            else:
                one_type = "NG"
            lists["ai1"] = change_kanjyoukamoku_16(one_type,lists["ai1"])
            lists["ai2"] = change_kanjyoukamoku_16(one_type,lists["ai2"])
            lists["ai3"] = change_kanjyoukamoku_16(one_type,lists["ai3"])
            
            print("ai1(normalized)::::")
            print(json.dumps(lists["ai1"], ensure_ascii=False, indent=2))
            print("ai2(normalized)::::")
            print(json.dumps(lists["ai2"], ensure_ascii=False, indent=2))
            print("ai3(normalized)::::")
            print(json.dumps(lists["ai3"], ensure_ascii=False, indent=2))

            # ===== ここから“行ユニオン＋多数決” =====
            ai_names = ("ai1", "ai2", "ai3")  # 順序は ai1 → ai2 → ai3（先勝の優先順位にも使う）

            # 括弧の全角化（キー同一性判定用）
            _PAREN_MAP = str.maketrans({
                "(": "（", ")": "）",
                "﹙": "（", "﹚": "）",
                "⦅": "（", "⦆": "）",
                "⟮": "（", "⟯": "）",
                "❨": "（", "❩": "）",
            })
            def _norm_name(name):
                if not isinstance(name, str):
                    return ""
                return name.translate(_PAREN_MAP).strip()

            # 期ごとの多数決（同額2本以上を優先、なければ ai1→ai2→ai3 の先勝）
            def _as_amount(v):
                if v is None:
                    return ""
                if isinstance(v, (int, float)):
                    return v
                s = str(v).strip()
                return "" if s in {"", "-", "—", "―", "ー", "N/A", "n/a", "NA"} else s

            def _term_pick(candidates):  # candidates: [{"金額":..., "page_no":...}] * 最大3
                amounts = [(_as_amount(c.get("金額", "")), c.get("page_no", "")) for c in candidates]
                amount_counter = Counter([a for a, _ in amounts if a != ""])
                # 2票以上の同額があればそれを採用（page_no はその金額を持つ最初=先勝）
                for a, cnt in amount_counter.items():
                    if cnt >= 2:
                        for a2, p2 in amounts:
                            if a2 == a:
                                return {"金額": a, "page_no": p2}
                # 非空の先勝（ai1, ai2, ai3 の順）
                for a, p in amounts:
                    if a != "":
                        return {"金額": a, "page_no": p}
                # すべて空
                return {"金額": "", "page_no": ""}

            # type/分類 の多数決（2票以上があれば採用、同票/バラは ai1→ai2→ai3）
            def _pick_field(vals_in_ai_order):
                c = Counter([v for v in vals_in_ai_order if v])
                if c:
                    most, cnt = c.most_common(1)[0]
                    if cnt >= 2:
                        return most
                for v in vals_in_ai_order:
                    if v:
                        return v
                return ""

            # 勘定科目キーのユニオン（括弧全角化後の名称をキーに）
            buckets = {}  # norm_name -> [row_ai1, row_ai2, row_ai3]
            def _push_row(row, idx):
                if not isinstance(row, dict):
                    return
                key = _norm_name(row.get("勘定科目", ""))
                if not key:
                    return
                if key not in buckets:
                    buckets[key] = [None, None, None]
                buckets[key][idx] = row

            for i, name in enumerate(ai_names):
                for r in lists[name]:
                    _push_row(r, i)
            #元の配列の各項目の直前項目を記録する開始
            # ai1 に登場する勘定科目キーを「既知」として記録
            seen_keys = set()
            for r in lists.get("ai1", []):
                if not isinstance(r, dict):
                    continue
                k = _norm_name(r.get("勘定科目", ""))
                if k:
                    seen_keys.add(k)

            #元の配列の各項目の直前項目を記録する開始
            # ai1 に登場する勘定科目キーを「既知」として記録
            seen_keys = set()
            for r in lists.get("ai1", []):
                if not isinstance(r, dict):
                    continue
                k = _norm_name(r.get("勘定科目", ""))
                if k:
                    seen_keys.add(k)

            # 新規科目ごとの「直前の科目」（＝アンカー）を記録
            insert_after = {}      # {新規科目キー: アンカー科目キー}
            # a2/a3 の「最初の行」で、かつ ai1 には無い新規科目を記録
            head_first_keys = set()

            def _build_insert_info(ai_key: str):
                rows = lists.get(ai_key, [])
                prev_key = None
                for idx, row in enumerate(rows):
                    if not isinstance(row, dict):
                        prev_key = None
                        continue
                    cur_key = _norm_name(row.get("勘定科目", ""))
                    if not cur_key:
                        prev_key = None
                        continue

                    # まだ一度も出てきていない「新規科目」
                    if cur_key not in seen_keys:
                        if idx == 0:
                            # a2/a3 の最初の行で新規なら、先頭挿入候補として記録
                            head_first_keys.add(cur_key)
                        elif prev_key and prev_key in seen_keys:
                            # それ以外の新規科目は「直前の既知科目」の直後に入れたい
                            insert_after[cur_key] = prev_key

                        seen_keys.add(cur_key)

                    prev_key = cur_key

            # ai2 → ai3 の順で情報を構築
            _build_insert_info("ai2")
            _build_insert_info("ai3")
            #元の配列の各項目の直前項目を記録する終了
            # 行ごとにマージ
            merged = []
            for norm_name, rows in buckets.items():
                r1, r2, r3 = rows
                types = [
                    r1.get("type") if isinstance(r1, dict) else None,
                    r2.get("type") if isinstance(r2, dict) else None,
                    r3.get("type") if isinstance(r3, dict) else None,
                ]
                bunruis = [
                    r1.get("分類") if isinstance(r1, dict) else None,
                    r2.get("分類") if isinstance(r2, dict) else None,
                    r3.get("分類") if isinstance(r3, dict) else None,
                ]

                def _get_term_obj(r, term):
                    if isinstance(r, dict) and isinstance(r.get(term), dict):
                        return r[term]
                    return {"金額": "", "page_no": ""}

                now_objs   = [_get_term_obj(r1, "今期"), _get_term_obj(r2, "今期"), _get_term_obj(r3, "今期")]
                prev_objs  = [_get_term_obj(r1, "前期"), _get_term_obj(r2, "前期"), _get_term_obj(r3, "前期")]
                prev2_objs = [_get_term_obj(r1, "前々期"), _get_term_obj(r2, "前々期"), _get_term_obj(r3, "前々期")]
                #mergedの挿入方式を変更する開始

                # 1行分のマージ結果（多数投票ロジックはそのまま）
                row_out = {
                    "勘定科目": norm_name,                 # 名称は正規化後（下流でも再正規化されるので安全）
                    "type": _pick_field(types),
                    "分類": _pick_field(bunruis),
                    "今期": _term_pick(now_objs),
                    "前期": _term_pick(prev_objs),
                    "前々期": _term_pick(prev2_objs),
                }

                anchor = insert_after.get(norm_name)

                if anchor:
                    # アンカーの直後に挿入する
                    inserted = False
                    for idx, existing in enumerate(merged):
                        if existing.get("勘定科目") == anchor:
                            merged.insert(idx + 1, row_out)
                            inserted = True
                            break
                    if not inserted:
                        # アンカー行がまだ merged に無い場合
                        if norm_name in head_first_keys:
                            # a2/a3 の最初の新規行なら先頭に挿入
                            merged.insert(0, row_out)
                        else:
                            merged.append(row_out)
                else:
                    # アンカーが無い（ai1 由来 or a2/a3 先頭新規など）
                    if norm_name in head_first_keys:
                        # a2/a3 の最初の新規行なら先頭に挿入
                        merged.insert(0, row_out)
                    else:
                        merged.append(row_out)

                #mergedの挿入方式を変更する終了

            merged_json = {"list": merged}
            best_content = json.dumps(merged_json, ensure_ascii=False)
            used_mode = "majority"      # 既存ログの互換維持
            used_key = "row_union"      # 行ユニオンでマージしたことを区別
            best_len = len(merged)

            return best_content, used_mode, used_key, best_len


        # 同時実行数（レートに応じて調整）
        MAX_WORKERS = int(os.getenv("OPENAI_CONCURRENCY", "1000"))
        # 1ページあたりの並行実行回数
        N_RUNS_PER_PAGE = 3

        # 位置代入で順序を保証するためにプレ配列を確保（ここは既存のまま）
        all_results = [None] * len(image_paths)
        sizes = [None] * len(image_paths)

        page_contexts = []  # 後段の整形に必要な文脈を保持

        labels = {"1": "BS or PL", "3": "販売費", "4": "製造原価"}

        with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
            for index, path in enumerate(image_paths):
                page_no_n += 1

                # すでに配列に変換済みならスキップ
                if isinstance(image_types[index], (list, tuple)):
                    continue

                raw = str(image_types[index]).strip()

                # "2" は対象外として扱う
                if raw == "2":
                    image_types[index] = ["対象外"]
                    continue

                # "1@,@3" のような文字列を分解してマッピング
                tokens = [t.strip() for t in raw.split("@,@") if t.strip()]

                # ラベル変換（未知のコードは無視）
                mapped = [labels[t] for t in tokens if t in labels]

                if not mapped:
                    # 何もマップできなければ対象外にフォールバック
                    image_types[index] = ["対象外"]
                else:
                    # 方針に合わせ、単一でも必ずリストで保持
                    # 例: ["BS or PL"] / ["販売費","製造原価"]
                    # 重複は除去（順序保持）
                    seen = set()
                    uniq = []
                    for m in mapped:
                        if m not in seen:
                            uniq.append(m)
                            seen.add(m)
                    image_types[index] = uniq
                                
                if image_types[index][0] == "対象外":
                    result_json = {
                        "kanjyokamoku": [],
                        "upload_file_key": image_keys[index],
                        "type": image_types[index],
                    }
                    all_results[index] = result_json
                    sizes[index] = None
                    continue

                # 画像サイズ・トークン見積もり
                w, h, tiles, tokens, cost = calc_tokens_and_cost(path)
                sizes[index] = {
                    "ファイル名": os.path.basename(path),
                    "幅 (px)": w,
                    "高さ (px)": h,
                    "タイル数": tiles,
                    "トークン数": tokens,
                    "入力コスト (USD)": round(cost, 5)
                }

                dopageno = dopageno + 1
                write_progress(f"{dopageno}ページ目読取中", db_config.get("www_dir") + "progress/" + ai_case_id)

                # 説明文
                image_type_txt = generate_image_type_description(
                    [image_types[index]],
                    [image_kakudo[index]],
                    image_keys[index]
                )

                # 回転→DataURI化
                angle = int(image_kakudo[index])
                itype = image_types[index]
                have_seizougenka = False
                have_hanbaihi = False
                if isinstance(itype, (list, tuple, set)):
                    havebspl = ("BS or PL" in itype)
                    have_seizougenka = ("製造原価" in itype and len(itype)==1)
                    have_hanbaihi = ("販売費" in itype and len(itype)==1)
                else:
                    havebspl = (itype == "BS or PL")
                encoded_url, is_pl_ai = rotate_image(path, angle,havebspl)
                encoded_url_for_ai = encoded_url
                if have_hanbaihi:
                    # rotate_image が保存した補正画像（.cv2hosei.png）を入力にするのが自然
                    han_image_path = path + ".cv2hosei.png"
                    han_green_path = han_image_path + ".green.png"

                    process_image_and_draw_boxes(
                        input_image_path=han_image_path,
                        output_image_path=han_green_path
                    )
                    # ★ここが重要：加工後画像を DataURL(base64) にして差し替える
                    encoded_url_for_ai = file_to_data_url(han_green_path)

                encoded_urls.append(encoded_url)
                is_pl_flags.append(is_pl_ai)
                have_seizougenka_flags.append(have_seizougenka)
                have_hanbaihi_flags.append(have_hanbaihi)
                if image_keys[index] == "currentAndPreviousBalanceSheet":
                    encoded_urls1.append(encoded_url)
                if image_keys[index] == "previousAndTwoAgoBalanceSheet":
                    encoded_urls2.append(encoded_url)
                elif image_keys[index] == "twoAgoAndTwoPeriodsAgoBalanceSheet":
                    encoded_urls2.append(encoded_url)
                STRICT_RULES = """
                【出力形式の絶対条件】
                - 出力は JSON オブジェクト 1個のみ。説明・余計な文字・コードブロック（```）は禁止。
                - ルートは {"list":[...]} のみ。
                - list の各要素は必ず次のキーだけを持つ：勘定科目, 今期, 前期, 前々期, type, 分類（追加キー禁止）
                - 各期は {"金額": <number or "">, "page_no": <integer or "">} の形。読めないときは空文字 "" を入れる。
                - 勘定科目名の括弧は全角（（ ））を使用する。
                - 見出し・親科目も必ず出力（親子は別レコード）。並び順は誌面の順そのまま。
                - 同名が複数箇所にある場合は要件のルールで区別名を付ける（「その他」は1番目から括弧付き）。
                - 「売上原価」「売上総利益」「営業利益」等の中間指標も必ず出力。
                - 期末商品棚卸高が2列ある場合は、右側の数値を「売上原価」として追加で出力。
                - 売上高が不明で●●売上高が2列並ぶ場合、右列を売上高合計（純売上高）とする。
                """
                STRICT_RULES=""
                messages = [
                    {"role": "system", "content": "あなたは正確に表を読み取る財務アナリストです。"},
                    {
                        "role": "user",
                        "content": [
                            {"type": "text", "text": image_type_txt + "\n" + STRICT_RULES.strip()},
                            {"type": "image_url", "image_url": {"url": encoded_url_for_ai, "detail": "auto"}}
                        ]
                    }
                ]

                JSON_SCHEMA_KANJYO = {
                    "name": "KanjyoList",
                    "schema": {
                        "$schema": "http://json-schema.org/draft-07/schema#",
                        "type": "object",
                        "properties": {
                            "list": {
                                "type": "array",
                                "items": {
                                    "type": "object",
                                    "properties": {
                                        "勘定科目": {"type": "string"},
                                        "今期": {
                                            "type": "object",
                                            "properties": {
                                                "金額": {"anyOf": [{"type": "number"}, {"type": "string"}]},
                                                "page_no": {"anyOf": [{"type": "integer"}, {"type": "string"}]}
                                            },
                                            "required": ["金額", "page_no"],
                                            "additionalProperties": False
                                        },
                                        "前期": {
                                            "type": "object",
                                            "properties": {
                                                "金額": {"anyOf": [{"type": "number"}, {"type": "string"}]},
                                                "page_no": {"anyOf": [{"type": "integer"}, {"type": "string"}]}
                                            },
                                            "required": ["金額", "page_no"],
                                            "additionalProperties": False
                                        },
                                        "前々期": {
                                            "type": "object",
                                            "properties": {
                                                "金額": {"anyOf": [{"type": "number"}, {"type": "string"}]},
                                                "page_no": {"anyOf": [{"type": "integer"}, {"type": "string"}]}
                                            },
                                            "required": ["金額", "page_no"],
                                            "additionalProperties": False
                                        },
                                        "type": {"type": "string", "enum": [ "PL", "BS", "販売費", "製造原価" ]},
                                        "分類": {"type": "string"}
                                    },
                                    "required": ["勘定科目", "今期", "前期", "前々期", "type", "分類"],
                                    "additionalProperties": False
                                }
                            },
                            "ログ": {
                                "anyOf": [
                                    {"type": "string"},
                                    {"type": "array", "items": {"type": "string"}}
                                ]
                            }
                        },
                        "required": ["list"],
                        "additionalProperties": False,
                        "propertyNames": {"enum": ["list", "ログ"]}
                    },
                    "strict": True
                }
                if image_keys[index] == "currentAndPreviousBalanceSheet" or image_keys[index] == "previousAndTwoAgoBalanceSheet" or image_keys[index] == "twoAgoAndTwoPeriodsAgoBalanceSheet":
                    try:
                        print("2期分PLプロンプト読取。。。")
                        with open(aitext1 + "_2-Gemini", "r", encoding="utf-8") as f:
                            GEMINI_BASE_PROMPT = f.read()
                    except Exception as e:
                        print(f"[Gemini][WARN] 2期分PLプロンプト読取＠ベースプロンプトファイルの読み込みに失敗しました: {GEMINI_PROMPT_PATH} ({e})")
                        GEMINI_BASE_PROMPT = ""
                # is_pl_ai は rotate_image の戻り値で決まる（PLページなら True）
                use_gemini = (
                    is_pl_ai
                    and gemini_api_key
                    and GEMINI_BASE_PROMPT
                    and image_keys[index] != "doublePreviousAndTwoAgoBalanceSheet"
                )
                

                #BSの2期間はy方向がずれる可能性が非常に高いので、1期分のみに適用する。
                use_gemini_bs = (
                    is_pl_ai==False
                    and havebspl
                    and gemini_api_key
                    and GEMINI_BASE_PROMPT
                    and image_keys[index] != "doublePreviousAndTwoAgoBalanceSheet"
                    and image_keys[index] != "currentAndPreviousBalanceSheet"
                    and image_keys[index] != "previousAndTwoAgoBalanceSheet"
                    and image_keys[index] != "twoAgoAndTwoPeriodsAgoBalanceSheet"
                )
                # --- 追加: is_pl_ai が False（=BS側）の場合、cv2補正画像で 1列 / T型 をGPT判定 ---
                bs_layout = None
                if use_gemini_bs:
                    gem_image_path = path + ".cv2hosei.png"
                    try:
                        bs_layout_result = classify_bs_layout_by_gpt(gem_image_path, api_key=_pick_api_key())
                        bs_layout = bs_layout_result.get("format")  # "single_column" or "t_format"
                        print(f"[BS_LAYOUT]page{page_no_n}::::: {os.path.basename(gem_image_path)} => {bs_layout} (conf={bs_layout_result.get('confidence')})")
                        ev = bs_layout_result.get("evidence", {}) or {}
                        print("  right_item_name_examples:", ev.get("right_item_name_examples"))
                        print("  right_has_item_names:", ev.get("right_has_item_names"))
                        print("  left_has_asset_headers:", ev.get("left_has_asset_headers"))
                        print("  right_has_liability_equity_headers:", ev.get("right_has_liability_equity_headers"))
                        
                        if bs_layout == "t_format" :
                            # T型BS向けの処理（列の扱い、プロンプト、分割など）へ
                            out = callgpt_tsg.callgpt_tsg(
                                input_file=gem_image_path,
                                output_file=gem_image_path + ".green.png",
                                key_path="/srv/www/apps/analygent_backend/internal/service-account-file.json"
                            )
                            print("use_gemini_bs callgpt_tsg.callgpt_tsg output_file :"+gem_image_path + ".green.png")
                        else:
                            # 1列BS向け
                            process_image_and_draw_boxes(
                                input_image_path=gem_image_path,
                                output_image_path=gem_image_path+".green.png"
                            )
                            print("use_gemini_bs pl process_image_and_draw_boxes output :"+gem_image_path+".green.png")
                        
                    except Exception as e:
                        # 迷ったら1列扱い（あなたの最初のルールを踏襲）
                        bs_layout = "single_column"
                        print(f"[BS_LAYOUT][WARN] 判定失敗→single_column扱い: {e}")
                # --- 追加ここまで ---
                
                
                
                
                use_gemini_seizougenka = (
                    have_seizougenka
                    and gemini_api_key
                    and GEMINI_BASE_PROMPT_SEIZOU
                    and image_keys[index] != "doublePreviousAndTwoAgoBalanceSheet"
                )
                

                if use_gemini:
                    # ★ PL + Gemini設定あり → OpenAI は呼ばず、Gemini だけ 1 回
                    print("use_gemini_pl...")
                    print(page_no_n)
                    gem_image_path = path + ".cv2hosei.png"
                    process_image_and_draw_boxes_pl(
                        input_image_path=gem_image_path,
                        output_image_path=gem_image_path+".green.png",
                        key_path=os.getenv("GOOGLE_APPLICATION_CREDENTIALS"),
                    )
                    print("pl process_image_and_draw_boxes_pl output :"+gem_image_path+".green.png")
                    image_type_txt = GEMINI_BASE_PROMPT
                    run_futures = [
                        ("ai1", executor.submit(
                            _call_gemini_for_pl,
                            gem_image_path+".green.png",
                            page_no_n,
                            image_type_txt,   # extra_prompt として
                        )),
                        # ai2/ai3 は ai_raw の初期値 '{"list":[]}' のまま
                    ]
                elif use_gemini_bs :
                    # ★ PL + Gemini設定あり → OpenAI は呼ばず、Gemini だけ 1 回
                    print("use_gemini_bs...")
                    print(page_no_n)
                    print("1期分BSプロンプト読取。。。")
                    GEMINI_BASE_PROMPT_BS=""
                    GEMINI_PROMPT_PATH_BS=aitext1 + "_1-Gemini-BS"
                    try:
                        with open(GEMINI_PROMPT_PATH_BS, "r", encoding="utf-8") as f:
                            GEMINI_BASE_PROMPT_BS = f.read()
                    except Exception as e:
                        print(f"[Gemini][WARN] ベースプロンプトファイル(BS)の読み込みに失敗しました: {GEMINI_PROMPT_PATH_BS} ({e})")
                        GEMINI_BASE_PROMPT = ""
                    gem_image_path = path + ".cv2hosei.png"
                    gem_image_path = gem_image_path+".green.png"
                    image_type_txt = GEMINI_BASE_PROMPT_BS
                    run_futures = [
                        ("ai1", executor.submit(
                            _call_gemini_for_pl,
                            gem_image_path,
                            page_no_n,
                            image_type_txt,   # extra_prompt として
                        )),
                        # ai2/ai3 は ai_raw の初期値 '{"list":[]}' のまま
                    ]
                elif use_gemini_seizougenka:
                    # ★ PL + Gemini設定あり → OpenAI は呼ばず、Gemini だけ 1 回
                    print("use_gemini_seizougenka...")
                    print(page_no_n)
                    gem_image_path = path + ".cv2hosei.png"
                    if image_keys[index] != "doublePreviousAndTwoAgoBalanceSheet" and image_keys[index] != "currentAndPreviousBalanceSheet" and image_keys[index] != "previousAndTwoAgoBalanceSheet" and image_keys[index] != "twoAgoAndTwoPeriodsAgoBalanceSheet" :
                        process_image_and_draw_boxes(
                            input_image_path=gem_image_path,
                            output_image_path=gem_image_path+".green.png"
                        )
                        gem_image_path = gem_image_path+".green.png"
                        print("use_gemini_seizougenka process_image_and_draw_boxes output :"+gem_image_path)
                    
                    image_type_txt = GEMINI_BASE_PROMPT_SEIZOU
                    run_futures = [
                        ("ai1", executor.submit(
                            _call_gemini_for_seizougenka,
                            gem_image_path,
                            page_no_n,
                            image_type_txt,   # extra_prompt として
                        )),
                        # ai2/ai3 は ai_raw の初期値 '{"list":[]}' のまま
                    ]
                else:
                    print("use_openai...")
                    print(page_no_n)
                    # ★ 1ページにつき3回を“並行”で投げる（ai1/ai2/ai3 のラベル付き）
                    # ★ 1ページにつき3回を並行で投げるが、開始をずらす（0s / 3s / 6s）
                    run_futures = [
                        ("ai1", executor.submit(_delayed_openai_call, 0, messages, model_str, JSON_SCHEMA_KANJYO)),
                        ("ai2", executor.submit(_delayed_openai_call, 3, messages, model_str, JSON_SCHEMA_KANJYO)),
                        ("ai3", executor.submit(_delayed_openai_call, 6, messages, model_str, JSON_SCHEMA_KANJYO)),
                    ]
                    print(f"[DEBUG] parallel start   = {time.strftime('%H:%M:%S', time.localtime(time.time()))}")

                page_contexts.append({
                    "index": index,
                    "page_no_n": page_no_n,
                    "image_type_txt": image_type_txt + "\n" + STRICT_RULES.strip(),
                    "image_key": image_keys[index],
                    "image_type": image_types[index],
                    "run_futures": run_futures
                })


        # ここから回収＆ベスト選択
        for ctx in sorted(page_contexts, key=lambda x: x["index"]):
        
            # 3回分を ai1/ai2/ai3 ごとに回収（失敗時は空JSONにする）
            ai_raw = {"ai1": '{"list":[]}', "ai2": '{"list":[]}', "ai3": '{"list":[]}'}
            modes  = {"ai1": "", "ai2": "", "ai3": ""}
            used_keys = {"ai1": "", "ai2": "", "ai3": ""}

            for name, fut in ctx["run_futures"]:
                try:
                    content, mode, used_key = fut.result()
                    #CSV出力
                    page_no = ctx.get("page_no_n", ctx.get("index", 0) + 1)
                    ai_label = name  # "ai1" / "ai2" / "ai3"
                    
                    # content から list を取り出して CSV 出力
                    try:
                        print("生データ出力::::::::::::::::")
                        print("page_no::::::::::::::::")
                        print(page_no)
                        print("ai_label::::::::::::::::")
                        print(ai_label)
                        print(content)
                        data = json.loads(content) if content else {"list": []}
                        rows = data.get("list", [])

                        if rows and isinstance(rows, list):
                            # 出力先ディレクトリ
                            out_dir = "/var/log/pys"
                            os.makedirs(out_dir, exist_ok=True)
                            ts = datetime.now(timezone(timedelta(hours=9))).strftime("%Y-%m-%d-%H-%M-%S")
                            # 例: {案件ID}_p{ページ番号}_{ai}.csv で保存
                            # ai_case_id がスコープにある前提。なければ任意の識別子に置き換え可。
                            csv_name = f"{ai_case_id}_p{page_no}_{ai_label}_{ts}.csv"
                            csv_path = os.path.join(out_dir, csv_name)

                            with open(csv_path, "w", newline="", encoding="utf-8-sig") as f:
                                w = csv.writer(f)
                                # ヘッダ
                                w.writerow([
                                    "勘定科目",
                                    "今期_金額", "今期_page_no",
                                    "前期_金額", "前期_page_no",
                                    "前々期_金額", "前々期_page_no",
                                    "type", "分類"
                                ])
                                # 行書き出し
                                for r in rows:
                                    def term_pair(d, key):
                                        v = d.get(key, {})
                                        if isinstance(v, dict):
                                            return v.get("金額", ""), v.get("page_no", "")
                                        return "", ""

                                    k_now, p_now = term_pair(r, "今期")
                                    k_prev, p_prev = term_pair(r, "前期")
                                    k_prev2, p_prev2 = term_pair(r, "前々期")

                                    w.writerow([
                                        r.get("勘定科目", ""),
                                        k_now,  p_now,
                                        k_prev, p_prev,
                                        k_prev2, p_prev2,
                                        r.get("type", ""),
                                        r.get("分類", "")
                                    ])

                            print(f"[CSV] wrote: {csv_path}")
                    except Exception as _e:
                        # CSV出力は本流を止めない
                        print(f"[CSV][WARN] failed to write CSV for page {page_no}, {ai_label}: {_e}")
                    
                    
                    ai_raw[name] = content if content else '{"list":[]}'
                    modes[name] = mode or ""
                    used_keys[name] = used_key or ""
                    print(f"[DEBUG] page {ctx['index']} - {name} end   = {time.strftime('%H:%M:%S', time.localtime(time.time()))}")
                except Exception:
                    # 失敗は空で埋める（多数決関数は空も扱える）
                    pass
            
            # ★★★ ここで「多数決版」を呼ぶ ★★★
            best_content, used_mode, used_key, best_len = _choose_best_content_by_majority(
                ai_raw["ai1"], ai_raw["ai2"], ai_raw["ai3"], ctx.get("image_type")
            )

            # _choose_best_content_by_majority は used_mode="majority", used_key="majority_voted" を返す実装

            if not best_content:
                print(f"\n⚠️ OpenAI呼び出し全失敗: index={ctx['index']}")
                all_results[ctx["index"]] = []
                continue

            # ↓この先は“今まで通り” best_content を元に整形
            try:
                result_json = {}
                kanjyokamoku = []
                parsed = json.loads(best_content)
                result_json["kanjyokamoku"] = parsed.get("list", [])
                # 付帯情報
                #result_json["content"] = best_content
                # 3回分の生レスポンスも保存（OpenAIのときだけでOKだが、Geminiでもai1だけ入るので共通でも可）
                result_json["contents"] = [
                    {"label": "ai1", "content": ai_raw["ai1"], "mode": modes["ai1"]},
                    {"label": "ai2", "content": ai_raw["ai2"], "mode": modes["ai2"]},
                    {"label": "ai3", "content": ai_raw["ai3"], "mode": modes["ai3"]},
                ]

                result_json["upload_file_key"] = ctx["image_key"]
                result_json["type"] = ctx["image_type"]
                if test_flag != "NO":
                    result_json["gpttxt"] = (
                        ctx["image_type_txt"]
                        + f"\n[mode={used_mode}, runs={N_RUNS_PER_PAGE}, best_len={best_len}]"
                    )
                    
                    
                # 勘定科目名の括弧を全角へ統一
                _PAREN_MAP = str.maketrans({
                    "(": "（", ")": "）",
                    "﹙": "（", "﹚": "）",
                    "⦅": "（", "⦆": "）",
                    "⟮": "（", "⟯": "）",
                    "❨": "（", "❩": "）",
                })
                def _to_zenkaku_parentheses(s):
                    return s.translate(_PAREN_MAP) if isinstance(s, str) else s

                for _row in result_json["kanjyokamoku"]:
                    if isinstance(_row, dict) and "勘定科目" in _row and isinstance(_row["勘定科目"], str):
                        _row["勘定科目"] = _to_zenkaku_parentheses(_row["勘定科目"])

                # 金額が全期間とも空の行は除去（0は残す）
                _EMPTY_MARKS = {"", "-", "—", "―", "ー", "N/A", "n/a", "NA"}
                def _norm_amount_cell(v):
                    if v is None:
                        return ""
                    if isinstance(v, (int, float)):
                        return v
                    s = str(v).strip()
                    return "" if s in _EMPTY_MARKS else s
                def _has_any_amount(row: dict) -> bool:
                    for term in ("今期", "前期", "前々期"):
                        cell = row.get(term, "")
                        if isinstance(cell, dict):
                            cell = cell.get("金額", "")
                        cell = _norm_amount_cell(cell)
                        if isinstance(cell, (int, float)):
                            return True
                        if any(ch.isdigit() for ch in str(cell)):
                            return True
                    return False

                result_json["kanjyokamoku"] = [
                    r for r in result_json["kanjyokamoku"] if isinstance(r, dict) and _has_any_amount(r)
                ]

                # page_no の補完
                page_no_n_ctx = ctx["page_no_n"]
                for page_index, item in enumerate(result_json["kanjyokamoku"]):
                    if not isinstance(item, dict):
                        continue
                    temobj = {
                        "勘定科目": item.get("勘定科目", ""),
                        "type": item.get("type", None),
                        "分類": item.get("分類", "")
                    }
                    for term in ["今期", "前期", "前々期"]:
                        if term not in item or not isinstance(item[term], dict):
                            temobj[term] = {"金額": "", "page_no": page_no_n_ctx}
                        else:
                            item[term]["page_no"] = page_no_n_ctx
                            temobj[term] = item[term]
                    kanjyokamoku.append(temobj)

                result_json["kanjyokamoku"] = kanjyokamoku

                # 決算期間に応じた金額の振り分け（元ロジックそのまま）
                key = ctx["image_key"]
                for page_index, item in enumerate(result_json["kanjyokamoku"]):
                    if not isinstance(item, dict):
                        continue
                    def get_amount(val):
                        if isinstance(val, dict):
                            return val.get("金額", "")
                        return val if val else ""
                    def set_empty(page_index, term):
                        result_json["kanjyokamoku"][page_index][term] = {"金額": "", "page_no": page_no_n_ctx}
                    def set_value(page_index, term, amount):
                        result_json["kanjyokamoku"][page_index][term] = {"金額": amount, "page_no": page_no_n_ctx}

                    if key == "currentTermBalanceSheet":
                        if get_amount(item.get("今期", "")) == "":
                            for src in ["前期", "前々期"]:
                                val = get_amount(item.get(src, ""))
                                if val != "":
                                    set_value(page_index, "今期", val)
                                    break
                        set_empty(page_index, "前期")
                        set_empty(page_index, "前々期")

                    elif key == "previousTermBalanceSheet":
                        if get_amount(item.get("前期", "")) == "":
                            for src in ["今期", "前々期"]:
                                val = get_amount(item.get(src, ""))
                                if val != "":
                                    set_value(page_index, "前期", val)
                                    break
                        set_empty(page_index, "今期")
                        set_empty(page_index, "前々期")

                    elif key == "twoTermsAgoBalanceSheet":
                        if get_amount(item.get("前々期", "")) == "":
                            for src in ["今期", "前期"]:
                                val = get_amount(item.get(src, ""))
                                if val != "":
                                    set_value(page_index, "前々期", val)
                                    break
                        set_empty(page_index, "今期")
                        set_empty(page_index, "前期")
                all_results[ctx["index"]] = result_json
            except json.JSONDecodeError:
                print(f"\n⚠️ JSONデコードエラー: index={ctx['index']}")
                print("返された内容:\n", best_content)
                all_results[ctx["index"]] = []
                continue
        ########################################
        # マルチ処理終了
        ########################################

    #勘定科目の文字を変更します例えば、売上を売上高合計に変更する
    all_results = change_kanjyoukamoku_12(all_results, in_place=True)
    all_results = change_kanjyoukamoku_01(all_results, in_place=True)
    #純資産、株主資本の△誤読対応
    all_results = change_kanjyoukamoku_10(all_results, in_place=True)
    all_results = change_kanjyoukamoku_11(all_results, in_place=True)
    closing_date = ""
    closing_date_zenki = ""
    closing_date_zenzenki = ""

    # 2パス抽出で使う変数も初期化（print/比較での未定義や None 加算エラー回避）
    closing_date1 = ""
    closing_date_zenki1 = ""
    closing_date_zenzenki1 = ""
    closing_date2 = ""
    closing_date_zenki2 = ""
    closing_date_zenzenki2 = ""

    def _pick_first(*candidates: str) -> str:
        """空文字や None をスキップし、最初に見つかった非空文字列を返す。全部空なら空文字。"""
        for c in candidates:
            if c:
                s = str(c).strip()
                if s:
                    return s
        return ""

    if (
        ("currentAndPreviousBalanceSheet" in image_keys and "previousAndTwoAgoBalanceSheet" in image_keys)
        or
        ("currentAndPreviousBalanceSheet" in image_keys and "twoAgoAndTwoPeriodsAgoBalanceSheet" in image_keys)
    ):
        # 2種類の複合BSがあるケースは、必ず2回抽出してから「優先順位ルール」で決める
        # ・encoded_urls1 … currentAndPreviousBalanceSheet
        # ・encoded_urls2 … previousAndTwoAgoBalanceSheet または twoAgoAndTwoPeriodsAgoBalanceSheet（←変更その1で積む）
        closing_date1, closing_date_zenki1, closing_date_zenzenki1, unit_str = extract_closing_dates_and_unit(
            client, model_str, encoded_urls1, start_time=start
        )
        closing_date2, closing_date_zenki2, closing_date_zenzenki2, unit_str = extract_closing_dates_and_unit(
            client, model_str, encoded_urls2, start_time=start
        )

        print("####決算年月日（複合BS）####")
        print(closing_date1, closing_date_zenki1, closing_date_zenzenki1, sep="\n")
        print(closing_date2, closing_date_zenki2, closing_date_zenzenki2, sep="\n")

        # 共通：今期は currentAndPreviousBalanceSheet 側の「今期」
        closing_date = closing_date1

        has_prev_two = ("previousAndTwoAgoBalanceSheet" in image_keys)
        has_two_and_two_prev = ("twoAgoAndTwoPeriodsAgoBalanceSheet" in image_keys)

        if has_prev_two:
            # ケースA:
            # currentAndPreviousBalanceSheet ＆ previousAndTwoAgoBalanceSheet
            # - 今期: closing_date1
            # - 前期: closing_date_zenki1 または closing_date2（closing_date_zenki1 を優先）
            # - 前々期: closing_date_zenzenki1 または closing_date_zenki2（closing_date_zenki2 を優先）
            closing_date_zenki    = _pick_first(closing_date_zenki1, closing_date2)
            closing_date_zenzenki = _pick_first(closing_date_zenki2, closing_date_zenzenki1)
        elif has_two_and_two_prev:
            # ケースB:
            # currentAndPreviousBalanceSheet ＆ twoAgoAndTwoPeriodsAgoBalanceSheet
            # - 今期: closing_date1
            # - 前期: closing_date_zenki1
            # - 前々期: closing_date2 または closing_date_zenzenki1（closing_date2 を優先）
            closing_date_zenki    = _pick_first(closing_date_zenki1)
            closing_date_zenzenki = _pick_first(closing_date2, closing_date_zenzenki1)

        # 最低限のフォールバック（欠落があれば今期を基準に機械補完）
        from dateutil.relativedelta import relativedelta
        from dateutil.parser import parse as parse_date

        def _to_dt_ymd_jp(s: str):
            if not s:
                return None
            try:
                m = re.match(r"^\s*(\d{4})年(\d{1,2})月(\d{1,2})日\s*$", s)
                if m:
                    y, mo, d = map(int, m.groups())
                    return datetime(y, mo, d)
            except Exception:
                pass
            try:
                return parse_date(s)
            except Exception:
                return None

        def _fmt_ja(dt: datetime) -> str:
            return f"{dt.year}年{dt.month:02d}月{dt.day:02d}日"

        dt_now = _to_dt_ymd_jp(closing_date)
        if dt_now:
            if not closing_date_zenki:
                closing_date_zenki = _fmt_ja(dt_now - relativedelta(years=1))
            if not closing_date_zenzenki:
                closing_date_zenzenki = _fmt_ja(dt_now - relativedelta(years=2))

    else:
        # 単票（または複合が揃っていない）ときは従来どおり1回抽出
        closing_date, closing_date_zenki, closing_date_zenzenki, unit_str = extract_closing_dates_and_unit(
            client, model_str, encoded_urls, start_time=start
        )
    # ============================================================
    # ★特例: doublePreviousAndTwoAgoBalanceSheet は Cloud Run を使用
    #  - 勘定科目変更(change_kanjyoukamoku_*)・sort は行わない
    #  - result_json_str / response_pdf_str はデバッグ用途のため "[]" 固定
    #  - closing_date 系は従来どおり extract_closing_dates_and_unit() の結果を使う
    #  - 画像共有は S3 上の *_mini を presigned URL で Cloud Run に渡す
    # ============================================================
    def get_cloudrun_id_token(service_account_json_path: str, audience_url: str) -> str:
        """
        Cloud Run(IAM認証) 呼び出し用のIDトークンをサービスアカウントJSONから発行する
        audience_url は Cloud Run のURL（https://xxxxx.run.app）を指定
        """
        from google.oauth2 import service_account
        from google.auth.transport.requests import Request

        creds = service_account.IDTokenCredentials.from_service_account_file(
            service_account_json_path,
            target_audience=audience_url
        )
        creds.refresh(Request())
        return creds.token
    def build_ai_response_pagewise(response_sorted: dict, upload_file_key: str, tani_value: str = "") -> dict:
        """
        response_sorted(例: {"BS":[...],"PL":[...],"販売費":[...],"製造原価":[...]})
        を page_no ごとに分割して、指定フォーマットの dict を返す。
        """
        def _to_int(x):
            try:
                return int(str(x).strip())
            except Exception:
                return None

        # 1) 全ページ番号を収集（今期/前期/前々期 のどれかに page_no があれば採用）
        pages = set()
        for _, rows in (response_sorted or {}).items():
            if not isinstance(rows, list):
                continue
            for it in rows:
                if not isinstance(it, dict):
                    continue
                for key in ("今期", "前期", "前々期"):
                    pg = _to_int((it.get(key) or {}).get("page_no"))
                    if pg is not None:
                        pages.add(pg)

        # page_no が一切無い場合でも落ちないように
        if not pages:
            pages = {1}

        page_list = []
        for pg in sorted(pages):
            kanjyokamoku = []

            for typ, rows in (response_sorted or {}).items():
                if not isinstance(rows, list):
                    continue

                for it in rows:
                    if not isinstance(it, dict):
                        continue

                    # この行がこのページに属するか判定（3期のどれかが該当ページなら採用）
                    hit = False
                    for key in ("今期", "前期", "前々期"):
                        tpg = _to_int((it.get(key) or {}).get("page_no"))
                        if tpg == pg:
                            hit = True
                            break
                    if not hit:
                        continue

                    # ページ単位にコピーして page_no をこの pg に揃える（あなたの例に合わせる）
                    row = dict(it)
                    row["type"] = row.get("type") or typ  # 念のため

                    for key in ("今期", "前期", "前々期"):
                        obj = row.get(key)
                        if not isinstance(obj, dict):
                            obj = {"金額": "", "page_no": ""}
                        obj = dict(obj)
                        obj["page_no"] = pg
                        row[key] = obj

                    kanjyokamoku.append(row)

            # ページ内に含まれる type を自動収集
            type_set = set()
            for row in kanjyokamoku:
                t = row.get("type")
                if isinstance(t, str) and t.strip():
                    type_set.add(t.strip())

            page_list.append({
                "kanjyokamoku": kanjyokamoku,
                "contents": [],
                "upload_file_key": upload_file_key,
                "type": sorted(list(type_set)),  # ["BS"] / ["PL"] / ["BS","PL"]
                "gpttxt": ""
            })


        return {
            "ai_response": page_list,
            "tani": tani_value or ""
        }

    if "doublePreviousAndTwoAgoBalanceSheet" in image_keys:
        cloudrun_url = os.getenv(
            "CASH_AI_CLOUDRUN_URL",
            "https://runaitext1-3-512697354748.asia-northeast1.run.app"
        ).rstrip("/")

        # doublePreviousAndTwoAgoBalanceSheet に該当する画像の *_mini を GCS 署名URL 化
        # ana は画像を GCS(converted/) に保存しているため、S3 presigned ではなく GCS v4 署名URL を使う
        if not _GCS_AVAILABLE:
            raise RuntimeError("google-cloud-storage が無いため GCS 署名URL を生成できません。")
        from google.oauth2 import service_account as _sa_mod
        from datetime import timedelta as _timedelta
        _sa_path = os.environ.get("GOOGLE_APPLICATION_CREDENTIALS")
        _signing_creds = _sa_mod.Credentials.from_service_account_file(_sa_path)
        _gcs_signing_client = gcs_storage.Client(credentials=_signing_creds)
        _gcs_signing_bucket = _gcs_signing_client.bucket(gcs_bucket_name)

        image_urls = []
        for pth, key in zip(image_paths, image_keys):
            if key != "doublePreviousAndTwoAgoBalanceSheet":
                continue
            root, ext = os.path.splitext(pth)
            mini_key = "converted/" + os.path.basename(f"{root}_mini{ext}")
            try:
                _blob = _gcs_signing_bucket.blob(mini_key)
                url = _blob.generate_signed_url(
                    version="v4",
                    expiration=_timedelta(hours=10),
                    method="GET",
                )
                image_urls.append(url)
            except Exception as e:
                raise RuntimeError(f"GCS 署名URL 生成に失敗しました: {mini_key} ({e})")

        if not image_urls:
            raise RuntimeError("doublePreviousAndTwoAgoBalanceSheet の *_mini が見つかりませんでした。")

        # Cloud Run 呼び出し（戻り値は result_final.json と同等の配列を想定）
        payload = {
            "image_urls": image_urls,
            "image_keys": ["doublePreviousAndTwoAgoBalanceSheet"] * len(image_urls),
            "ai_case_id": ai_case_id
        }
        try:
            cloudrun_url = "https://runaitext1-3-512697354748.asia-northeast1.run.app"
            service_account_json_path = "/srv/www/apps/analygent_backend/internal/gen-lang-client-0018414550-f50b079b0584.json"

            id_token = get_cloudrun_id_token(service_account_json_path, cloudrun_url)

            headers = {
                "Authorization": f"Bearer {id_token}",
                "Content-Type": "application/json",
            }

            r = requests.post(
                cloudrun_url,
                json=payload,
                headers=headers,
                timeout=(10, 900)  # 念のため長め
            )
            if r.status_code != 200:
                print("=== Cloud Run ERROR ===")
                print("status:", r.status_code)
                print("body:", r.text[:2000])  # 長い場合があるので先頭だけ
                print("headers:", dict(r.headers))
                raise RuntimeError("Cloud Run returned error")
            cloud_rows = r.json()
        except Exception as e:
            raise RuntimeError(f"Cloud Run API 呼び出しに失敗しました: {e}")

        if not isinstance(cloud_rows, list):
            raise RuntimeError("Cloud Run の戻り値が配列(list)ではありません。")

        # type で BS/PL/販売費/製造原価 に振り分け
        response_sorted = {"BS": [], "PL": [], "販売費": [], "製造原価": []}
        for row in cloud_rows:
            if not isinstance(row, dict):
                continue
            t = row.get("type")
            if t in response_sorted:
                response_sorted[t].append(row)

        normalized_json_str = json.dumps(response_sorted, ensure_ascii=False)

        # tani を入れたい（unit_str がある想定。無ければ空）
        tani_value = ""
        try:
            tani_value = unit_str  # もしこのスコープに unit_str が居るなら採用
        except Exception:
            tani_value = ""

        result_obj = build_ai_response_pagewise(
            response_sorted=response_sorted,
            upload_file_key="doublePreviousAndTwoAgoBalanceSheet",
            tani_value=tani_value
        )
        result_json_str = json.dumps(result_obj, ensure_ascii=False)

        response_pdf_str = "[]"
        sizes_str = json.dumps(sizes, ensure_ascii=False)

        # DB 更新（従来と同等）
        cursor = connection.cursor()
        cursor.execute("OPTIMIZE TABLE ai_case")
        _ = cursor.fetchall()

        purge_old_query = """
            UPDATE ai_case
            SET response = NULL,
                response_2 = NULL,
                request = NULL,
                sizes = NULL
            WHERE update_at < NOW() - INTERVAL 1 MONTH
        """
        cursor.execute(purge_old_query)

        update_query = """
            UPDATE `ai_case`
            SET
                `response` = %s,
                `response_2` = %s,
                `sizes` = %s,
                `update_at` = NOW(),
                `status` = %s,
                `closing_date` = %s,
                `closing_date_zenki` = %s,
                `closing_date_zenzenki` = %s,
                `response_pdf` = %s
            WHERE `ai_case_id` = %s
        """
        cursor.execute(update_query, (
            normalized_json_str,
            result_json_str,
            sizes_str,
            'AIOK',
            closing_date,
            closing_date_zenki,
            closing_date_zenzenki,
            response_pdf_str,
            ai_case_id
        ))
        connection.commit()
        cursor.close()

        write_progress(f"読取完了まで", db_config.get("www_dir") + "progress/" + ai_case_id)

        # 以降の OpenAI 結果整形・change_kanjyoukamoku・sort・pdf処理は全てスキップ
        sys.exit(0)

    final_result = {
        "ai_response": all_results,
        "tani": unit_str
    }
    #####################################################
    print(json.dumps(final_result, ensure_ascii=False, indent=2))
    #messages_string = json.dumps(messages, ensure_ascii=False, indent=2)
    #print(messages_string)
    # ここにDB更新処理を追加
    result_json_str = json.dumps(final_result, ensure_ascii=False)
    
    # all_results が既に与えられているとする
    final_response_2 = {
        "BS": [],
        "PL": [],
        "販売費": [],
        "製造原価": []
    }

    # マージ用データ構造
    merged_data = {
        "BS": defaultdict(lambda: {
            "勘定科目": "",
            "分類": "",  # ★追加
            "今期": {"金額": "", "page_no": ""},
            "前期": {"金額": "", "page_no": ""},
            "前々期": {"金額": "", "page_no": ""}
        }),
        "PL": defaultdict(lambda: {
            "勘定科目": "",
            "分類": "",  # ★追加
            "今期": {"金額": "", "page_no": ""},
            "前期": {"金額": "", "page_no": ""},
            "前々期": {"金額": "", "page_no": ""}
        }),
        "販売費": defaultdict(lambda: {
            "勘定科目": "",
            "分類": "",  # ★追加
            "今期": {"金額": "", "page_no": ""},
            "前期": {"金額": "", "page_no": ""},
            "前々期": {"金額": "", "page_no": ""}
        }),
        "製造原価": defaultdict(lambda: {
            "勘定科目": "",
            "分類": "",  # ★追加
            "今期": {"金額": "", "page_no": ""},
            "前期": {"金額": "", "page_no": ""},
            "前々期": {"金額": "", "page_no": ""}
        })
    }
    # ▼ 追加：マージキー正規化（末尾「計」「合計」を同一視）
    def _normalize_merge_key_org(name: str) -> str:
        if not isinstance(name, str):
            return name
        s = name.strip()
        # 全角・半角スペースはマージ判断から除外
        s = re.sub(r'[\s\u3000]+', '', s)
        # 末尾が「…計」「…合計」なら「…合計」に統一（末尾だけ対象）
        s = re.sub(r'(?:合計|計)$', '合計', s)
        return s
    def _strip_leading_number(s: str) -> str:
        """先頭の番号/番号風表記を削除して返す（空白は既に除去済み前提でも安全）。"""
        # 先頭番号を取り除くパターン（第1、1.、1) 、全角数字、丸数字①〜⑳、ローマ数字などを想定）
        _LEADING_NUM_PATTERN = re.compile(
            r'^\s*'                              # 先頭の空白を除去
            r'(?:第\s*)?'                        # 「第」があれば許容（例: 第1）
            r'(?:[0-9０-９]+|[\u2460-\u2473]+|[IVXivx]+)'  # 半角/全角数字、丸数字①〜⑳、ローマ数字
            r'(?:[\.．、\)\）\]\:：\s\-–—]*)'     # 区切り文字（.,)、：、空白、ダッシュ等）
        )
        if not isinstance(s, str):
            return s
        # まず前後の空白をトリム
        s = s.strip()
        # パターンにマッチすれば除去
        s = _LEADING_NUM_PATTERN.sub("", s)
        return s
    # 先頭番号（通常用）
    _LEADING_NUM_RE = re.compile(
        r"""^(
            (?:第?[0-9０-９]+(?:\.[0-9０-９]+)*)     |  # 1. / 1.2 / 第1 など
            (?:[0-9０-９]+[.)])                      |  # 1) / １）
            (?:[①-⑳㊀-㊉])                          |  # 丸数字
            (?:[IVXLCDMivxlcdm]+[.)]?)               |  # ローマ数字＋区切り
            (?:[\u2160-\u217F])                      |  # Unicodeローマ数字（ⅠⅡⅢ…ⅰⅱ…）
            (?:[A-Za-zＡ-Ｚａ-ｚ][.)])               |  # A) a) など
        )""",
        re.VERBOSE,
    )

    # 「番号＋固定/流動資産」専用（番号を残したいので検出だけに使う）
    _ASSET_PREFIX_RE = re.compile(
        r"""^
        (?P<prefix>
            (?:[0-9０-９]+)|
            (?:[IVXLCDM]+)|(?:[ivxlcdm]+)|        # ASCIIローマ数字
            (?:[\u2160-\u217F])|                  # Unicodeローマ数字
            (?:[\u0391-\u03A9\u03B1-\u03C9])      # ギリシャ文字 Α-Ω α-ω
        )
        (?P<base>固定資産|流動資産)
        (?P<total>合計)?$
        """,
        re.VERBOSE,
    )

    def _strip_leading_number_general(s: str) -> str:
        """一般用の先頭番号削除（固定/流動資産のときは後述でスキップ）"""
        return _LEADING_NUM_RE.sub("", s, count=1)

    def _normalize_merge_key_org_org(name: str) -> str:
        """
        マージ判定用のキー正規化。
        - 全角/半角スペース除去
        - 先頭番号（例: "1.", "①", "第1" など）を削除
          ※ ただし「(番号)固定資産 / (番号)流動資産」は番号を残す
        - MERGE_EQUIV_RULES のパターンでマッチしたらベース名に置き換え
          （固定/流動資産は「番号＋資産名」を維持できる置換に変更）
        - それ以外は末尾「計/合計」を「合計」に統一（末尾のみ）
        """
        if not isinstance(name, str):
            return name

        # ▼▼▼ 合計←→親科目を同一視するためのホワイトリスト
        # 固定/流動資産は (prefix)(base)(合計)? を保持できるように2グループ化して \1\2 に置換
        MERGE_EQUIV_RULES = [
            {"label": "投資その他の資産", "pattern": r"(投資その他の資産)(?:合計)?$"},
            {"label": "有形固定資産",   "pattern": r"(有形固定資産)(?:合計)?$"},
            {"label": "雑費",           "pattern": r"^(雑費)(?:.*)$"},
            {"label": "利益剰余金",     "pattern": r"^(利益剰余金)(?:.*)$"},
            {"label": "資本金",         "pattern": r"^(資本金)(?:.*)$"},

            # ここが重要：番号＋資産名をベースとして保持（例：Ⅰ流動資産、１流動資産、α固定資産）
            # 置換先は \1\2（= prefix + base）。「…合計」も \1\2 に正規化（= 合計は落とす）
            {"label": "固定資産",
             "pattern": r"^(?:(?P<prefix>[0-9０-９]+|[IVXLCDM]+|[ivxlcdm]+|[\u2160-\u217F\u0391-\u03A9\u03B1-\u03C9]))?(?P<base>固定資産)(?:合計)?$",
             "repl": r"\g<prefix>\g<base>"},
            {"label": "流動資産",
             "pattern": r"^(?:(?P<prefix>[0-9０-９]+|[IVXLCDM]+|[ivxlcdm]+|[\u2160-\u217F\u0391-\u03A9\u03B1-\u03C9]))?(?P<base>流動資産)(?:合計)?$",
             "repl": r"\g<prefix>\g<base>"},
        ]

        # コンパイル
        _MERGE_EQUIV_REGEX = []
        for rdef in MERGE_EQUIV_RULES:
            repl = rdef.get("repl", r"\1")  # 従来ルールは \1 を想定
            _MERGE_EQUIV_REGEX.append((re.compile(rdef["pattern"]), repl))

        s = name.strip()
        # スペース類は除去（全角含む）
        s = re.sub(r'[\s\u3000]+', '', s)

        # ★ 固定/流動資産については、番号付きは保持したいので「先頭番号削除」を条件付きにする
        if not _ASSET_PREFIX_RE.match(s):
            s = _strip_leading_number_general(s)

        # ホワイトリストで正規化（固定/流動資産は prefix+base に揃う）
        for rx, repl in _MERGE_EQUIV_REGEX:
            if rx.search(s):
                s = rx.sub(repl, s)
                break

        # 末尾「計/合計」は「合計」に統一（ホワイトリスト未ヒット時のフォールバック）
        s = re.sub(r'(?:合計|計)$', '合計', s)

        return s
    def _normalize_merge_key(name: str) -> str:
        """
        マージ判定用のキー正規化。
        - 全角/半角スペース除去
        - 先頭番号（例: "1.", "①", "第1" など）を削除
        - MERGE_EQUIV_RULES のパターンでマッチしたらベース名 (\1) に置き換え（先頭「雑費」ルール含む）
        - それ以外は末尾「計/合計」を「合計」に統一（末尾のみ）
        """
        if not isinstance(name, str):
            return name
        


        # ▼▼▼ 合計←→親科目を同一視するためのホワイトリスト（末尾「合計」を吸収）
        #   ラベルは可読名、pattern は必ず (ベース名)(?:合計)?$ の形にしてください。
        MERGE_EQUIV_RULES = [
            {"label": "投資その他の資産", "pattern": r"(投資その他の資産)(?:合計)?$"},
            {"label": "有形固定資産",   "pattern": r"(有形固定資産)(?:合計)?$"},
            {"label": "雑費",           "pattern": r"^(雑費)(?:.*)$"},
            {"label": "利益剰余金",           "pattern": r"^(利益剰余金)(?:.*)$"},
            {"label": "資本金",           "pattern": r"^(資本金)(?:.*)$"}
        ]
        # コンパイルしておく
        _MERGE_EQUIV_REGEX = [(re.compile(r["pattern"]), r"\1") for r in MERGE_EQUIV_RULES]
        s = name.strip()
        # スペース類は除去（全角含む）
        s = re.sub(r'[\s\u3000]+', '', s)

        # ① 先頭の番号を削る
        s = _strip_leading_number(s)

        # ② ホワイトリストでベース名に吸収（先頭が雑費のルールもここで処理される）
        for rx, repl in _MERGE_EQUIV_REGEX:
            if rx.search(s):
                s = rx.sub(repl, s)
                break

        # ③ 末尾が「計/合計」の語は「合計」に統一（ホワイトリストで処理されなかった場合のフォールバック）
        s = re.sub(r'(?:合計|計)$', '合計', s)

        return s
    #=============================================================
    # マージ処理=====================================================
    # 日付正規化 & 等価判定ユーティリティ（どこか共通のヘルパ領域に置く）
    def _normalize_jp_ymd_str(s: str) -> str | None:
        """'2022年06月30日' と '2022年6月30日' を同一表記へ正規化して返す（失敗時 None）。"""
        if not s:
            return None
        s = str(s).strip()
        # 全角→半角数字
        z2h = str.maketrans("０１２３４５６７８９", "0123456789")
        s = s.translate(z2h)
        # 空白除去
        s = re.sub(r"\s+", "", s)
        # よくある区切りに対応（年/月/日 or /.- 区切り）
        m = re.search(r"^(\d{4})年(\d{1,2})月(\d{1,2})日$", s)
        if not m:
            m = re.search(r"^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$", s)
        if m:
            y, mo, d = map(int, m.groups())
            return f"{y:04d}年{mo:02d}月{d:02d}日"
        # フォールバック：dateutil で解釈してから統一表記へ
        try:
            dt = parse_date(s)
            return f"{dt.year:04d}年{dt.month:02d}月{dt.day:02d}日"
        except Exception:
            return None

    def same_date(a: str, b: str) -> bool:
        """正規化後に完全一致なら同日とみなす。"""
        na = _normalize_jp_ymd_str(a)
        nb = _normalize_jp_ymd_str(b)
        return (na is not None) and (nb is not None) and (na == nb)
    #================================
    #PDF事にデータを集める
    index895=0
    response_pdf= {
        "list": [
            {"closing_date_now":closing_date1,"list":[]},
            {"closing_date_now":closing_date2,"list":[]},
            {"closing_date_now":closing_date_zenzenki,"list":[]}
        ]
    }
    response_pdf_tmp=[]
    oldkey=""
    for page_data in all_results:
        if not isinstance(page_data, dict):
            index895+=1
            continue
        kanjyokamoku_list = page_data.get("kanjyokamoku", [])  # ← KeyError回避のため最小修正
        key = image_keys[index895]
        print("910====================\n")
        print(oldkey+"\n")
        print(key+"\n")
        if oldkey != key :
            print("key\n")
            if index895!=0 :
                if oldkey=="currentTermBalanceSheet" :
                    response_pdf["list"][0]["list"]=response_pdf_tmp
                    response_pdf["list"][0]["key"]=oldkey
                if oldkey=="previousTermBalanceSheet" :
                    response_pdf["list"][1]["list"]=response_pdf_tmp
                    response_pdf["list"][1]["key"]=oldkey
                if oldkey=="twoTermsAgoBalanceSheet" :
                    response_pdf["list"][2]["list"]=response_pdf_tmp
                    response_pdf["list"][2]["key"]=oldkey
                if oldkey=="currentAndPreviousBalanceSheet" :
                    response_pdf["list"][0]["list"]=response_pdf_tmp
                    response_pdf["list"][0]["key"]=oldkey
                if oldkey=="previousAndTwoAgoBalanceSheet" :
                    response_pdf["list"][1]["list"]=response_pdf_tmp
                    response_pdf["list"][1]["key"]=oldkey
                if oldkey=="twoAgoAndTwoPeriodsAgoBalanceSheet" :
                    response_pdf["list"][1]["list"]=response_pdf_tmp
                    response_pdf["list"][1]["key"]=oldkey
                if oldkey=="doublePreviousAndTwoAgoBalanceSheet" :
                    response_pdf["list"][0]["list"]=response_pdf_tmp
                    response_pdf["list"][0]["key"]=oldkey
            response_pdf_tmp=[]
        oldkey = key
        for item in kanjyokamoku_list:
            response_pdf_tmp.append(item)
        index895+=1
    if oldkey=="currentTermBalanceSheet" :
        response_pdf["list"][0]["list"]=response_pdf_tmp
        response_pdf["list"][0]["key"]=oldkey
    if oldkey=="previousTermBalanceSheet" :
        response_pdf["list"][1]["list"]=response_pdf_tmp
        response_pdf["list"][1]["key"]=oldkey
    if oldkey=="twoTermsAgoBalanceSheet" :
        response_pdf["list"][2]["list"]=response_pdf_tmp
        response_pdf["list"][2]["key"]=oldkey
    if oldkey=="currentAndPreviousBalanceSheet" :
        response_pdf["list"][0]["list"]=response_pdf_tmp
        response_pdf["list"][0]["key"]=oldkey
    if oldkey=="previousAndTwoAgoBalanceSheet" :
        #前期＋前々期
        response_pdf["list"][1]["list"]=response_pdf_tmp
        response_pdf["list"][1]["key"]=oldkey
    if oldkey=="twoAgoAndTwoPeriodsAgoBalanceSheet" :
        #前々期＋前々前期
        response_pdf["list"][1]["list"]=response_pdf_tmp
        response_pdf["list"][1]["key"]=oldkey
    if oldkey=="doublePreviousAndTwoAgoBalanceSheet" :
        response_pdf["list"][0]["list"]=response_pdf_tmp
        response_pdf["list"][0]["key"]=oldkey
    response_pdf_str = json.dumps(response_pdf, ensure_ascii=False)

    print("\n merge with period remapping by upload_key\n")
    print(json.dumps(all_results, ensure_ascii=False, indent=2))
    print("\n merge with period remapping by upload_key\n")
    #勘定項目が10以上の場合重複項目が50%あるなら、今期金額を前期、前々期に移動するstart
    # 対象ページ:
    #   A) twoAgoAndTwoPeriodsAgoBalanceSheet
    #       条件成立以降、そのページ以降の「twoAgo...」で
    #           今期 → 前期 にコピー（宛先が空のときのみ）、コピー成功時に今期は空
    #   B) previousAndTwoAgoBalanceSheet
    #       条件成立以降、そのページ以降の「previousAndTwoAgo...」で
    #           前期 → 前々期 にコピー（宛先が空のときのみ）
    #           今期 → 前期 にコピー（宛先が空のときのみ）
    #           いずれかコピー成功時のみ 今期は空
    #   C) currentAndPreviousBalanceSheet
    #       条件成立以降、そのページ以降の「currentAndPrevious...」で
    #           今期 → 前期 にコピー（宛先が空のときのみ）、コピー成功時に今期は空
    #
    # 判定条件（共通）:
    #   - 勘定科目ユニーク数が10件以上
    #   - 先行ベース集合との重複（後続側の一意集合を分母）が 50%以上
    # 追加ガード:
    #   - ベースに使ったページ（最初に現れた同 upload_file_key）は判定から除外する

    def _norm_name_for_dup(s: str) -> str:
        """重複判定用の緩め正規化（空白/記号/括弧など除去、数字英字は半角化）"""
        if not isinstance(s, str):
            return ""
        t = s.strip()
        z2h_num = str.maketrans(
            "０１２３４５６７８９ＡＢＣＤＥＦＧＨＩＪＫＬＭＮＯＰＱＲＳＴＵＶＷＸＹＺａｂｃｄｅｆｇｈｉｊｋｌｍｎｏｐｑｒｓｔｕｖｗｘｙｚ",
            "0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz"
        )
        t = t.translate(z2h_num)
        t = re.sub(r"[\s\u3000・･\.\-‐-‒–—―／/\\,，．\(\)（）\[\]［］{}＜＞〈〉「」『』【】\:：;；'\"`~^_^]+", "", t)
        return t

    def _should_shift_by_overlap(later_names: list[str], base_set: set[str]) -> bool:
        """後続ページ側の一意集合を分母に重複率を算出して 50% 以上なら True。10件未満は False。"""
        later_norm = [_norm_name_for_dup(n) for n in later_names if isinstance(n, str) and n.strip()]
        later_set = set(n for n in later_norm if n)
        if len(later_set) < 10:
            return False
        overlap = len(later_set & base_set)
        ratio = overlap / max(1, len(later_set))
        return ratio >= 0.5

    def _is_empty_term(term: dict) -> bool:
        """{金額, page_no} が共に空（"" か None か欠落）とみなす"""
        if not isinstance(term, dict):
            return True
        v1 = str(term.get("金額", "")).strip()
        v2 = str(term.get("page_no", "")).strip()
        return (v1 == "" or v1.lower() == "none") and (v2 == "" or v2.lower() == "none")

    # ========= A) twoAgoAndTwoPeriodsAgoBalanceSheet =========
    base_twoago_set: set[str] | None = None
    base_twoago_idx: int | None = None
    for i, _pd in enumerate(all_results):
        if isinstance(_pd, dict) and _pd.get("upload_file_key") == "twoAgoAndTwoPeriodsAgoBalanceSheet":
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            base_twoago_set = set(_norm_name_for_dup(n) for n in names if n)
            base_twoago_idx = i
            break

    if base_twoago_set is not None and base_twoago_idx is not None:
        # ベース以降（ベースを除外）で発火点を探す
        for _idx in range(base_twoago_idx + 1, len(all_results)):
            _pd = all_results[_idx]
            if not (isinstance(_pd, dict) and _pd.get("upload_file_key") == "twoAgoAndTwoPeriodsAgoBalanceSheet"):
                continue
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            if _should_shift_by_overlap(names, base_twoago_set):
                # このページ以降の twoAgo... すべてに適用
                for j in range(_idx, len(all_results)):
                    pdj = all_results[j]
                    if not (isinstance(pdj, dict) and pdj.get("upload_file_key") == "twoAgoAndTwoPeriodsAgoBalanceSheet"):
                        continue
                    for it in pdj.get("kanjyokamoku", []):
                        if not isinstance(it, dict):
                            continue
                        it["今期"] = _ensure_term_dict(it.get("今期"))
                        it["前期"] = _ensure_term_dict(it.get("前期"))
                        # 宛先（前期）が空の場合のみコピー
                        if _is_empty_term(it["前期"]) and not _is_empty_term(it["今期"]):
                            it["前期"]["金額"]   = it["今期"].get("金額", "")
                            it["前期"]["page_no"] = it["今期"].get("page_no", "")
                            _clear_term(it, "今期")  # コピー成功時のみ空にする
                break  # 最初の発火点のみ採用

    # ========= B) previousAndTwoAgoBalanceSheet =========
    base_prev2_set: set[str] | None = None
    base_prev2_idx: int | None = None
    for i, _pd in enumerate(all_results):
        if isinstance(_pd, dict) and _pd.get("upload_file_key") == "previousAndTwoAgoBalanceSheet":
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            base_prev2_set = set(_norm_name_for_dup(n) for n in names if n)
            base_prev2_idx = i
            break

    if base_prev2_set is not None and base_prev2_idx is not None:
        for _idx in range(base_prev2_idx + 1, len(all_results)):
            _pd = all_results[_idx]
            if not (isinstance(_pd, dict) and _pd.get("upload_file_key") == "previousAndTwoAgoBalanceSheet"):
                continue
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            if _should_shift_by_overlap(names, base_prev2_set):
                for j in range(_idx, len(all_results)):
                    pdj = all_results[j]
                    if not (isinstance(pdj, dict) and pdj.get("upload_file_key") == "previousAndTwoAgoBalanceSheet"):
                        continue
                    for it in pdj.get("kanjyokamoku", []):
                        if not isinstance(it, dict):
                            continue
                        it["今期"]   = _ensure_term_dict(it.get("今期"))
                        it["前期"]   = _ensure_term_dict(it.get("前期"))
                        it["前々期"] = _ensure_term_dict(it.get("前々期"))

                        copied = False

                        # 前期 → 前々期（宛先が空なら）
                        if _is_empty_term(it["前々期"]) and not _is_empty_term(it["前期"]):
                            it["前々期"]["金額"]   = it["前期"].get("金額", "")
                            it["前々期"]["page_no"] = it["前期"].get("page_no", "")
                            copied = True

                        # 今期 → 前期（宛先が空なら）
                        if _is_empty_term(it["前期"]) and not _is_empty_term(it["今期"]):
                            it["前期"]["金額"]   = it["今期"].get("金額", "")
                            it["前期"]["page_no"] = it["今期"].get("page_no", "")
                            copied = True

                        # どちらかのコピーが成立した場合のみ 今期を空にする
                        if copied:
                            _clear_term(it, "今期")
                break

    # ========= C) currentAndPreviousBalanceSheet =========
    base_curprev_set: set[str] | None = None
    base_curprev_idx: int | None = None
    for i, _pd in enumerate(all_results):
        if isinstance(_pd, dict) and _pd.get("upload_file_key") == "currentAndPreviousBalanceSheet":
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            base_curprev_set = set(_norm_name_for_dup(n) for n in names if n)
            base_curprev_idx = i
            break

    if base_curprev_set is not None and base_curprev_idx is not None:
        for _idx in range(base_curprev_idx + 1, len(all_results)):
            _pd = all_results[_idx]
            if not (isinstance(_pd, dict) and _pd.get("upload_file_key") == "currentAndPreviousBalanceSheet"):
                continue
            names = [it.get("勘定科目", "") for it in _pd.get("kanjyokamoku", []) if isinstance(it, dict)]
            if _should_shift_by_overlap(names, base_curprev_set):
                for j in range(_idx, len(all_results)):
                    pdj = all_results[j]
                    if not (isinstance(pdj, dict) and pdj.get("upload_file_key") == "currentAndPreviousBalanceSheet"):
                        continue
                    kj_list = pdj.get("kanjyokamoku", [])
                    if not isinstance(kj_list, list):
                        continue
                    for it in kj_list:
                        if not isinstance(it, dict):
                            continue
                        it["今期"] = _ensure_term_dict(it.get("今期"))
                        it["前期"] = _ensure_term_dict(it.get("前期"))

                        if _is_empty_term(it["前期"]) and not _is_empty_term(it["今期"]):
                            it["前期"]["金額"]   = it["今期"].get("金額", "")
                            it["前期"]["page_no"] = it["今期"].get("page_no", "")
                            _clear_term(it, "今期")
                break
    #勘定項目が10以上の場合重複項目が50%あるなら、今期金額を前期、前々期に移動するend

    print("勘定項目が10以上の場合重複項目が50%あるなら、今期金額を前期、前々期に移動する")
    print(json.dumps(all_results, ensure_ascii=False, indent=2))
    print("勘定項目が10以上の場合重複項目が50%あるなら、今期金額を前期、前々期に移動する")
    
    # --- 1パス目：merge_keyごとの最初の登場位置を記録（ページ・行の位置を最優先、seqは弱いタイブレーク） ---
    type_keys = ("BS", "PL", "販売費", "製造原価")

    # merge_key -> (first_page_idx, first_row_idx, seq_in_type)
    first_pos  = {t: {} for t in type_keys}
    seq_by_type = {t: 0 for t in type_keys}

    # 追加：ページごとの“そのタイプの科目シーケンス”（連続重複は圧縮）
    page_seqs_by_type = {t: [] for t in type_keys}

    for page_idx, page_data in enumerate(all_results):
        if not isinstance(page_data, dict):
            continue
        kanjyokamoku_list = page_data.get("kanjyokamoku", [])
        upload_key = page_data.get("upload_file_key", "")

        # このページのタイプ別シーケンス（正規化キー列）
        seq_per_type = {t: [] for t in type_keys}

        for row_idx, item in enumerate(kanjyokamoku_list):
            kamoku = item.get("勘定科目")
            typ = item.get("type")
            if not kamoku or typ not in type_keys:
                continue

            # マージ時と同じ前処理でキー生成
            item_for_order = remap_item_by_upload_key(item, upload_key)
            merge_key = _normalize_merge_key(item_for_order.get("勘定科目", kamoku))

            # 初出位置（ページ・行優先、seqは弱いTB）
            seq_by_type[typ] += 1
            if merge_key not in first_pos[typ]:
                first_pos[typ][merge_key] = (page_idx, row_idx, seq_by_type[typ])

            # ページ内シーケンス（連続重複を1回に圧縮）
            if not seq_per_type[typ] or seq_per_type[typ][-1] != merge_key:
                seq_per_type[typ].append(merge_key)

        # ページごとのシーケンスを保存
        for t in type_keys:
            if seq_per_type[t]:
                page_seqs_by_type[t].append(seq_per_type[t])


    # --- 2パス目：実マージ（_order は first_pos のタプルをそのまま使う＝安定） ---
    for page_data in all_results:
        if not isinstance(page_data, dict):
            continue
        kanjyokamoku_list = page_data.get("kanjyokamoku", [])
        upload_key = page_data.get("upload_file_key", "")

        for item in kanjyokamoku_list:
            kamoku = item.get("勘定科目")
            typ = item.get("type")
            if not kamoku or typ not in type_keys:
                continue

            item_for_merge = remap_item_by_upload_key(item, upload_key)
            merge_key = _normalize_merge_key(item_for_merge.get("勘定科目", kamoku))
            merged = merged_data[typ][merge_key]

            if not merged.get("_init"):
                merged.update({
                    "勘定科目": _normalize_merge_key(kamoku),
                    "分類": item_for_merge.get("分類", ""),
                    "今期": {"金額":"", "page_no":""},
                    "前期": {"金額":"", "page_no":""},
                    "前々期": {"金額":"", "page_no":""},
                    "_order": first_pos[typ].get(merge_key, (10**9, 10**9, 10**9)),  # (page,row,seq)
                    "_init": True
                })

            for term in ("今期", "前期", "前々期"):
                v = item_for_merge.get(term)
                if isinstance(v, dict) and v.get("金額", "") != "":
                    merged[term]["金額"] = v["金額"]
                    merged[term]["page_no"] = v.get("page_no", "")


    # --- 出力：ページ順“インクリメンタル挿入”で最終順を構築（直前項目の直後に必ず入れる） ---
    def build_master_order_by_pages(bucket_keys: set, typ: str):
        """
        ページ0→1→2…の順で、そのページのシーケンスをなぞり、
        まだ出ていないキーは「同じページで最も近い“前方の既出キー”の直後」に挿入。
        それも無ければ「同ページで最も近い“後方の既出キー”の直前」、
        どちらも無ければ末尾に追加。
        """
        master = []  # 最終キー順（merge_key の列）

        # すでにマージ対象でないキーはスキップ（bucketに無いキーは無視）
        for seq in page_seqs_by_type[typ]:
            # このページのキー列から、今回 bucket に存在するキーだけ抽出
            page_keys = [k for k in seq if k in bucket_keys]

            for idx, k in enumerate(page_keys):
                if k in master:
                    continue

                # 1) 直近の前方アンカー（このページ内で、すでに master にある最も近い左側のキー）
                anchor = None
                for j in range(idx - 1, -1, -1):
                    pj = page_keys[j]
                    if pj in master:
                        anchor = pj
                        break
                if anchor is not None:
                    pos = master.index(anchor) + 1
                    master.insert(pos, k)
                    continue

                # 2) 直近の後方アンカー（このページ内で、すでに master にある最も近い右側のキー）
                fanchor = None
                for j in range(idx + 1, len(page_keys)):
                    pj = page_keys[j]
                    if pj in master:
                        fanchor = pj
                        break
                if fanchor is not None:
                    pos = master.index(fanchor)
                    master.insert(pos, k)
                    continue

                # 3) アンカーが無い（このページで最初のキー群）→ 末尾に追加
                master.append(k)

        return master

    def render_in_master_order(merged_bucket: dict, typ: str):
        # マスター順を作成
        keys_in_bucket = set(merged_bucket.keys())
        master = build_master_order_by_pages(keys_in_bucket, typ)

        # master に無い“取りこぼし”は first_pos（_order）で後ろに安定追加
        rest = [k for k in merged_bucket.keys() if k not in master]
        big = (10**9, 10**9, 10**9)
        rest.sort(key=lambda k: merged_bucket[k].get("_order", big))
        ordered_keys = master + rest

        # レコード化（内部フィールドの除去）
        out = []
        for k in ordered_keys:
            r = dict(merged_bucket[k])
            r.pop("_order", None)
            r.pop("_init", None)
            out.append(r)
        return out

    # ← ここを4つすべてに適用（トップレベルの並びは既存通り維持）
    final_response_2["BS"]     = render_in_master_order(merged_data["BS"], "BS")
    final_response_2["PL"]     = render_in_master_order(merged_data["PL"], "PL")
    final_response_2["販売費"]   = render_in_master_order(merged_data["販売費"], "販売費")
    final_response_2["製造原価"] = render_in_master_order(merged_data["製造原価"], "製造原価")



    print("set to final_response_2")
    print(json.dumps(final_response_2, ensure_ascii=False, indent=2))
    final_response_2=change_kanjyoukamoku_03(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_07(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_04(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_05(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_06(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_17(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_18(final_response_2,in_place=True,margelist_path=db_config.get("pys_dir") + "margelist")
    final_response_2=change_kanjyoukamoku_04(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_09(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_15(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_19(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_20(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_21(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_22(final_response_2,in_place=True)
    final_response_2=change_kanjyoukamoku_23(final_response_2,in_place=True)
    print("DEBUT1058")
    print(json.dumps(final_response_2, ensure_ascii=False, indent=2))
    from sortdatas_bs_2 import sort_bs_from_input_2
    print("1046 make pdf list ====================\n")
    response_pdf=sort_bs_from_input_2(response_pdf)
    print("1046====================\n")
    normalized_json_str = json.dumps(final_response_2, ensure_ascii=False)
    
    sizes_str= json.dumps(sizes, ensure_ascii=False)

    
    ####################################################
    response = final_response_2
    bs_section = response.get("BS", [])
    pl_section = response.get("PL", [])
    hb_section = response.get("販売費", [])
    sg_section = response.get("製造原価", [])
    response_sorted = {
        "BS": bs_section,
        "PL": pl_section,
        "販売費": hb_section,
        "製造原価": sg_section
    }

    # --- 置換開始: 勘定科目マージ処理中 直後から終了までの堅牢版 --- #
    from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeoutError

    print("DEBUG: マージプロセス開始（堅牢版）")
    write_progress(f"勘定科目マージ処理中", db_config.get("www_dir") + "progress/" + ai_case_id)

    # ヘルパ：安全に呼ぶ wrapper（タイムアウトを設定）
    def safe_call(func, *args, timeout=30, **kwargs):
        """関数を別スレッドで呼んで timeout 秒で打ち切る。例外/タイムアウト時は (False, reason) を返す。"""
        with ThreadPoolExecutor(max_workers=1) as ex:
            fut = ex.submit(func, *args, **kwargs)
            try:
                ret = fut.result(timeout=timeout)
                return True, ret
            except FuturesTimeoutError:
                try:
                    fut.cancel()
                except Exception:
                    pass
                return False, f"timeout after {timeout}s"
            except Exception as e:
                return False, f"exception: {e}"

    # デバッグ出力ユーティリティ
    def _print_out_rows(out_rows, label="out_rows"):
        try:
            tname = type(out_rows).__name__
            print(f"{label} type: {tname}")
            if out_rows is None:
                print(f"{label} is None")
                return
            if isinstance(out_rows, list):
                print(f"{label}: list (len={len(out_rows)})")
                try:
                    print(json.dumps(out_rows, ensure_ascii=False, indent=2))
                except Exception:
                    import pprint
                    pprint.pprint(out_rows)
                return
            if isinstance(out_rows, dict):
                print(f"{label}: dict (keys={list(out_rows.keys())})")
                try:
                    print(json.dumps(out_rows, ensure_ascii=False, indent=2))
                except Exception:
                    import pprint
                    pprint.pprint(out_rows)
                return
            try:
                print(f"{label} (json attempt):")
                print(json.dumps(out_rows, ensure_ascii=False, indent=2))
            except Exception:
                print(f"{label} (repr fallback):")
                print(repr(out_rows))
        except Exception as _e:
            print("WARN: _print_out_rows failed:", _e)

    # 1) attach_merged_to_response を試す（存在すれば）
    attach_merged_ok = False
    merged = None
    print("DEBUG: 877")
#    try:
#        print("DEBUG: import sortdatas_pl (attach_merged_to_response) を試行")
#        from sortdatas_pl import process_pl
#        print("DEBUG: sortdatas_pl imported OK")
#        print(json.dumps(response_sorted, ensure_ascii=False, indent=2))
#        res = process_pl(response_sorted["PL"])
#        response_sorted["PL"] = res['list']
#        out_rows = res['list']
#        response_sorted["BS"] = final_response_2["BS"]
#        print("sortdatas_pl response_sorted::::::::::::\n")
#
#        _print_out_rows(out_rows, "sortdatas_pl:list")
#        _print_out_rows(res.get('checks'), "sortdatas_pl:checks")
#
#        attach_merged_ok = True
#        print("DEBUG: attach_merged_to_response 成功")
#    except Exception as e_import:
#        print("WARN: sortdatas_pl import failed (フォールバック):", e_import)
#    print("DEBUG: 895")
#
#    # 2) sortdatas_bs の sort を試す（BSの整形）
#    try:
#        print("DEBUG: import sortdatas_bs を試行")
#        from sortdatas_bs import sort_bs_from_input
#        print("DEBUG: sortdatas_bs imported OK")
#        try:
#            # 入力選択：attach成功時はその merged を優先、さもなくば final_response_2
#            if attach_merged_ok == False:
#                response_sorted = final_response_2
#            print(json.dumps(response_sorted, ensure_ascii=False, indent=2))
#            ok, sorted_bs = safe_call(sort_bs_from_input, response_sorted, timeout=25)
#            if ok and isinstance(sorted_bs, list) and sorted_bs:
#                response_sorted["BS"] = sorted_bs
#                print("DEBUG: sort_bs_from_input 成功、BS上書き")
#            else:
#                print("WARN: sort_bs_from_input failed/empty:", sorted_bs)
#                if not response_sorted.get("BS"):
#                    response_sorted["BS"] = final_response_2.get("BS", []) if isinstance(final_response_2.get("BS", []), list) else []
#        except Exception as e_call:
#            print("WARN: sort_bs_from_input 実行中に例外:", e_call)
#            if not response_sorted.get("BS"):
#                response_sorted["BS"] = final_response_2.get("BS", []) if isinstance(final_response_2.get("BS", []), list) else []
#    except Exception as e_bs_import:
#        print("WARN: sortdatas_bs import failed (skip):", e_bs_import)
#        if not response_sorted.get("BS"):
#            response_sorted["BS"] = final_response_2.get("BS", []) if isinstance(final_response_2.get("BS", []), list) else []
#    print("DEBUG: 920")
#
#    # 3) PL のフォールバック（attach が失敗した場合）
#    if not response_sorted.get("PL"):
#        response_sorted["PL"] = final_response_2.get("PL", []) if isinstance(final_response_2.get("PL", []), list) else []

#    # 4) 販売費（SGA）: sortdatas_sga を試す
#    def _resolve_sga_fn(mod):
#        for name in ("process_sga", "sort_sga_from_input", "process"):
#            fn = getattr(mod, name, None)
#            if callable(fn):
#                return fn
#        return None
#
#    try:
#        print("DEBUG: import sortdatas_sga を試行")
#        import sortdatas_sga as _sga_mod
#        sga_fn = _resolve_sga_fn(_sga_mod)
#        if sga_fn is None:
#            raise ImportError("sortdatas_sga から有効な関数を見つけられません (process_sga/sort_sga_from_input/process).")
#        print("DEBUG: sortdatas_sga imported OK ->", sga_fn.__name__)
#
#        # 入力（存在しない/不正ならフォールバック）
#        if not isinstance(response_sorted.get("販売費"), list):
#            response_sorted["販売費"] = final_response_2.get("販売費", []) if isinstance(final_response_2.get("販売費", []), list) else []
#
#        ok, sga_res = safe_call(sga_fn, response_sorted["販売費"], timeout=25)
#        if ok:
#            # 返り値が dict で 'list' を持つ or list そのもの の2系統に対応
#            if isinstance(sga_res, dict) and isinstance(sga_res.get("list"), list):
#                response_sorted["販売費"] = sga_res["list"]
#                _print_out_rows(sga_res.get("checks"), "sortdatas_sga:checks")
#            elif isinstance(sga_res, list):
#                response_sorted["販売費"] = sga_res
#            else:
#                print("WARN: sortdatas_sga の戻り値形式が想定外:", type(sga_res).__name__)
#        else:
#            print("WARN: sortdatas_sga 呼び出し失敗:", sga_res)
#            # フォールバック: 既存 or final_response_2
#            if not isinstance(response_sorted.get("販売費"), list):
#                response_sorted["販売費"] = final_response_2.get("販売費", []) if isinstance(final_response_2.get("販売費", []), list) else []
#    except Exception as e_sga:
#        print("WARN: sortdatas_sga import/exec failed (skip):", e_sga)
#        if not isinstance(response_sorted.get("販売費"), list):
#            response_sorted["販売費"] = final_response_2.get("販売費", []) if isinstance(final_response_2.get("販売費", []), list) else []
#
#    # 5) 製造原価（COGS）: sortdatas_cogs を試す
#    def _resolve_cogs_fn(mod):
#        for name in ("process_cogs", "sort_cogs_from_input", "process"):
#            fn = getattr(mod, name, None)
#            if callable(fn):
#                return fn
#        return None
#    print(json.dumps(response_sorted, ensure_ascii=False, indent=2))
#    try:
#        print("DEBUG: import sortdatas_cogs を試行")
#        import sortdatas_cogs as _cogs_mod
#        cogs_fn = _resolve_cogs_fn(_cogs_mod)
#        if cogs_fn is None:
#            raise ImportError("sortdatas_cogs から有効な関数を見つけられません (process_cogs/sort_cogs_from_input/process).")
#        print("DEBUG: sortdatas_cogs imported OK ->", cogs_fn.__name__)
#
#        # 入力（存在しない/不正ならフォールバック）
#        if not isinstance(response_sorted.get("製造原価"), list):
#            response_sorted["製造原価"] = final_response_2.get("製造原価", []) if isinstance(final_response_2.get("製造原価", []), list) else []
#
#        ok, cogs_res = safe_call(cogs_fn, response_sorted["製造原価"], timeout=25)
#        if ok :
#            if isinstance(cogs_res, dict) and isinstance(cogs_res.get("list"), list):
#                response_sorted["製造原価"] = cogs_res["list"]
#                _print_out_rows(cogs_res.get("checks"), "sortdatas_cogs:checks")
#            elif isinstance(cogs_res, list):
#                response_sorted["製造原価"] = cogs_res
#            else:
#                print("WARN: sortdatas_cogs の戻り値形式が想定外:", type(cogs_res).__name__)
#        else:
#            print("WARN: sortdatas_cogs 呼び出し失敗:", cogs_res)
#            if not isinstance(response_sorted.get("製造原価"), list):
#                response_sorted["製造原価"] = final_response_2.get("製造原価", []) if isinstance(final_response_2.get("製造原価", []), list) else []
#    except Exception as e_cogs:
#        print("WARN: sortdatas_cogs import/exec failed (skip):", e_cogs)
#        if not isinstance(response_sorted.get("製造原価"), list):
#            response_sorted["製造原価"] = final_response_2.get("製造原価", []) if isinstance(final_response_2.get("製造原価", []), list) else []

    write_progress(f"勘定科目マージ処理終了", db_config.get("www_dir") + "progress/" + ai_case_id)
    print("DEBUG: マージ処理 終了。続行します。")
    # --- 置換終了 --- #

    # 結果表示
    print("new response_sorted::::::::::::\n")
    print(json.dumps(response_sorted, ensure_ascii=False, indent=2))
    print("run change_kanjyoukamoku_14::::::::::::\n")
    response_sorted=change_kanjyoukamoku_14(response_sorted)
    print(json.dumps(response_sorted, ensure_ascii=False, indent=2))
    # === 保存処理を追加 ===
    # スクリプトと同じフォルダに出力
    #output_dir = Path(uploadDir)
    #timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    #output_path = Path(output_dir / f"sorted_{ai_case_id}_{timestamp}.json")

    #with open(output_path, 'w', encoding='utf-8') as out_file:
    #    json.dump(response_sorted, out_file, ensure_ascii=False, indent=2)

    # CSV保存パス
    #csv_path = Path(output_dir / f"sorted_{ai_case_id}_{timestamp}.csv")

    # 共通ヘッダーを抽出（PLとBSで統一されたキー構造を仮定）
    #all_rows = []
    #for section_name, section_data in response_sorted.items():
    #    for item in section_data:
    #        row = {"区分": section_name}
    #        row.update(item)
    #        all_rows.append(row)

    # CSVに保存
    #with open(csv_path, 'w', newline='', encoding='utf-8') as csvfile:
    #    if all_rows:
    #        writer = csv.DictWriter(csvfile, fieldnames=all_rows[0].keys())
    #        writer.writeheader()
    #        writer.writerows(all_rows)

    normalized_json_str = json.dumps(response_sorted, ensure_ascii=False)


    #######################################################################
    cursor = connection.cursor()
    # ✅ ① テーブル最適化（断片化を解消）
    cursor.execute("OPTIMIZE TABLE ai_case")
    _ = cursor.fetchall()  # ← これがポイント
    ######################
    purge_old_query = """
        UPDATE ai_case
        SET response = NULL,
            response_2 = NULL,
            request = NULL,
            sizes = NULL
        WHERE update_at < NOW() - INTERVAL 1 MONTH
    """
    cursor.execute(purge_old_query)
    #####################
    update_query = """
        UPDATE `ai_case`
        SET
            `response` = %s,
            `response_2` = %s,
            `sizes` = %s,
            `update_at` = NOW(),
            `status` = %s,
            `closing_date` = %s,
            `closing_date_zenki` = %s,
            `closing_date_zenzenki` = %s,
            `response_pdf` = %s
        WHERE `ai_case_id` = %s
    """
    cursor.execute(update_query, (
        normalized_json_str,
        result_json_str,
        sizes_str,
        'AIOK',                 # ← リテラルだった箇所も param に
        closing_date,
        closing_date_zenki,
        closing_date_zenzenki,
        response_pdf_str,
        ai_case_id
    ))
    connection.commit()
    cursor.close()
    write_progress(f"読取完了まで", db_config.get("www_dir") + "progress/" + ai_case_id)
except Exception as e:
    write_progress(f"読取完了まで", db_config.get("www_dir") + "progress/" + ai_case_id)
    print(json.dumps({
        "error": str(e),
        "traceback": traceback.format_exc()
    }, ensure_ascii=False))
