# ============================================================
# Colab Prompt: bspl.json → 財務分析（pandas不使用）
# 変更点（今回）:
#  - 流動比率・当座比率を「％表記」に戻し、計算式欄も「× 100」を明記
# 既存仕様:
#  - 「その他の投資」「（投資有価証券）」を別行で出力
#  - ROE: 自己資本<=0 は N/A、ROA: 総資産<=0 は N/A
#  - 増減率（前々期→前期）（前期→今期）を追加
#  - 最終行から1行空けて【対象指標と計算根拠】を追記
# 出力: result.csv / result.json
# ============================================================

import json, csv, os, re, unicodedata
from typing import Dict, List, Tuple, Optional
import sys

# ---- 設定 ----
INPUT_JSON_PATH = "bspl.json"
OUTPUT_CSV_PATH = "result.csv"
OUTPUT_JSON_PATH = "result.json"


def sales_with_net_sales(idx, period: str) -> int:
    """
    売上高が0（または存在しない）場合に限り、
    純売上高の金額を売上高として使用する。
    """
    v = get(idx, "売上高", period)
    if v != 0:
        return v
    # 売上高が0なら純売上高を見る
    alt = get(idx, "純売上高", period)
    return alt


# ---- 表記ゆれ正規化 ----
def pre_normalize(s: str) -> str:
    if s is None:
        return ""
    # 全角→半角など正規化＆「および」→「及び」
    s = unicodedata.normalize("NFKC", s).replace("および", "及び").lower()

    # 空白類を除去
    for ch in [" ", "\u3000", "\t", "\n", "\r"]:
        s = s.replace(ch, "")

    # 記号・括弧類をまとめて除去
    delete_chars = "・･()（）／/-–—−＋+,”\"'.,:;…！？!?　【】「」『』［］[]、"
    for ch in delete_chars:
        s = s.replace(ch, "")

    # 末尾の「勘定」「科目」を削る
    for tail in ("勘定", "科目"):
        if s.endswith(tail):
            s = s[:-len(tail)]

    return s


REGEX_RULES: List[Tuple[str, str, str, str]] = [
    # PL主要
    ("R001", r"^(?:売上高|売上収益|営業収益)$", "売上高", "I"),
    ("R001N", r"^(?:純売上高|純売上).*$", "純売上高", "I"), 
    ("R002", r"^(?:売上原価|原価)$", "売上原価", "I"),
    ("R003", r"^(?:売上総利益|粗利益|粗利)$", "売上総利益", "I"),
    ("R004", r"^(?:販売費及び一般管理費|販売費及一般管理費|販管費)$", "販売費及び一般管理費", "I"),
    ("R010", r"^(?:営業利益)$", "営業利益", "I"),
    ("R011", r"^(?:営業外収益|営業外収入)$", "営業外収益", "I"),
    ("R012", r"^(?:営業外費用)$", "営業外費用", "I"),
    ("R013", r"^(?:経常利益)$", "経常利益", "I"),
    ("R016", r"^(?:税引前利益|税引前当期利益|税金等調整前当期純利益)$", "税引前利益", "I"),
    ("R017", r"^(?:当期純利益|当期利益)$", "当期純利益", "I"),
    # ▼▼▼ 修正点：支払利息の前方一致を許容（末尾に任意文字）
    ("R022", r"^(?:支払利息|支払利子).*$", "支払利息", "I"),
    # ▲▲▲
    # COGS
    ("R030", r"^(?:期首商品棚卸高)$", "期首商品棚卸高", "I"),
    ("R031", r"^(?:期末商品棚卸高)$", "期末商品棚卸高", "I"),
    ("R032", r"^(?:仕入高|当期商品仕入高)$", "当期商品仕入高", "I"),
    ("R033", r"^(?:仕入割戻高|仕入値引|仕入割戻|仕入戻り高)$", "仕入控除", "I"),
    # BS主要
    ("R100", r"^(?:現金及び預金|現金預金|現預金)$", "現金及び預金", "I"),
    ("R104", r"^(?:棚卸資産)$", "棚卸資産", "I"),
    ("R105", r"^(?:商品|商品在庫)$", "商品", "I"),
    ("R106", r"^(?:製品|製品在庫)$", "製品", "I"),
    ("R107", r"^(?:半製品)$", "半製品", "I"),
    ("R108", r"^(?:仕掛品|仕掛在庫)$", "仕掛品", "I"),
    ("R109", r"^(?:原材料|材料|原料)$", "原材料", "I"),
    ("R110", r"^(?:貯蔵品|消耗品在庫)$", "貯蔵品", "I"),
    ("R120", r"^(?:流動資産|流動資産合計)$", "流動資産合計", "I"),
    ("R130", r"^(?:流動負債|流動負債合計)$", "流動負債合計", "I"),
    ("R150", r"^(?:固定資産|固定資産合計)$", "固定資産合計", "I"),
    ("R151", r"^(?:有形固定資産)$", "有形固定資産", "I"),
    ("R160", r"^(?:無形固定資産)$", "無形固定資産", "I"),
    # 投資その他の資産（総称・内訳）
    ("R180", r"^(?:投資有価証券)$", "投資有価証券", "I"),
    ("R181", r"^(?:関係会社株式|関連会社株式)$", "関係会社株式", "I"),
    ("R182", r"^(?:長期貸付金)$", "長期貸付金", "I"),
    ("R183", r"^(?:長期預け金)$", "長期預け金", "I"),
    ("R184", r"^(?:出資金)$", "出資金", "I"),
    ("R185", r"^(?:投資その他の資産|その他の投資)$", "投資その他の資産", "I"),
    # 負債・資本
    ("R190", r"^(?:固定負債|固定負債合計)$", "固定負債合計", "I"),
    ("R191", r"^(?:長期借入金)$", "長期借入金", "I"),
    ("R132", r"^(?:短期借入金)$", "短期借入金", "I"),
    ("R200", r"^(?:負債の部合計|負債合計)$", "負債合計", "I"),
    ("R220", r"^(?:資本金)$", "資本金", "I"),
    ("R230", r"^(?:純資産の部合計|純資産合計|純資産)$", "純資産合計", "I"),
    ("R240", r"^(?:負債・純資産の部合計|負債純資産合計)$", "総資産", "I"),
    ("R241", r"^(?:資産の部合計|資産合計)$", "総資産", "I"),
    ("R250", r"^(?:新株予約権)$", "新株予約権", "I"),
    ("R251", r"^(?:非支配株主持分|非支配持分)$", "非支配株主持分", "I"),
    ("R260", r"^(?:賞与引当金)$", "賞与引当金", "I"),
    ("R261", r"^(?:退職給付引当金|退職給与引当金)$", "退職給付引当金", "I"),
]
_COMPILED_RULES = [(rid, re.compile(pat, re.IGNORECASE if "I" in (flags or "") else 0), rep)
                   for rid, pat, rep, flags in REGEX_RULES]

def to_canonical(name: str) -> str:
    pre = pre_normalize(name or "")
    for _, cre, rep in _COMPILED_RULES:
        if cre.fullmatch(pre):
            return rep
    return name or ""

# ---- JSON 読込・インデックス化 ----
def load_bspl(path: str) -> Dict[str, Dict[str, int]]:
    with open(path, "r", encoding="utf-8") as f:
        data = json.load(f)

    idx: Dict[str, Dict[str, int]] = {}
    def add(canon: str, period: str, amount):
        if amount in (None, ""): return
        try:
            v = int(str(amount).replace(",", ""))
        except Exception:
            return
        idx.setdefault(canon, {"今期":0, "前期":0, "前々期":0})
        idx[canon][period] += v

    for sec in ("PL","BS"):
        for row in data.get(sec, []):
            canon = to_canonical(row.get("勘定科目"))
            for period in ("今期","前期","前々期"):
                add(canon, period, (row.get(period) or {}).get("金額"))

    # 補完
    def ensure_sum(target: str, parts: List[str]):
        if target not in idx:
            total = {"今期":0,"前期":0,"前々期":0}
            anyp = False
            for p in parts:
                if p in idx:
                    anyp = True
                    for k in total: total[k] += idx[p].get(k,0)
            if anyp: idx[target] = total

    if "総資産" not in idx:
        ensure_sum("総資産", ["流動資産合計","固定資産合計"])
    if "固定資産合計" not in idx:
        ensure_sum("固定資産合計", ["有形固定資産","無形固定資産","投資その他の資産"])
    if "負債合計" not in idx and ("流動負債合計" in idx or "固定負債合計" in idx):
        total = {"今期":0,"前期":0,"前々期":0}
        for p in ("流動負債合計","固定負債合計"):
            if p in idx:
                for k in total: total[k] += idx[p].get(k,0)
        idx["負債合計"] = total

    return idx

# ---- 値取得・整形 ----
def get(idx: Dict[str, Dict[str, int]], name: str, period: str) -> int:
    return int(idx.get(name, {}).get(period, 0))

def safe_div(num: Optional[float], den: Optional[float]) -> Optional[float]:
    try:
        if den in (0, None) or num is None: return None
        return num/den
    except Exception:
        return None

def fmt_amount(v: Optional[float]) -> str:
    if v is None: return ""
    try: return f"{int(round(v)):,.0f}"
    except: return ""

def fmt_percent(v: Optional[float]) -> str:
    if v is None: return "N/A"
    try: return f"{v*100:.2f}%"
    except: return "N/A"

def fmt_ratio(v: Optional[float]) -> str:
    if v is None: return "N/A"
    try: return f"{v:.2f}"
    except: return "N/A"

# 棚卸資産（期末）推定
INVENTORY_PARTS = ["棚卸資産","商品","製品","半製品","仕掛品","原材料","貯蔵品"]
def inventory_ending(idx, period: str) -> int:
    if "棚卸資産" in idx:
        return get(idx,"棚卸資産",period)
    total, anyp = 0, False
    for p in INVENTORY_PARTS[1:]:
        if p in idx:
            anyp = True
            total += get(idx,p,period)
    if anyp: return total
    if period == "今期":
        return get(idx,"期末商品棚卸高","今期")
    if period == "前期":
        v = get(idx,"期首商品棚卸高","今期")
        return v if v!=0 else get(idx,"期末商品棚卸高","前期")
    if period == "前々期":
        v = get(idx,"期首商品棚卸高","前期")
        return v if v!=0 else get(idx,"期末商品棚卸高","前々期")
    return 0

# 投資その他の資産 合計
INVESTMENT_PARTS = ["投資有価証券","関係会社株式","長期貸付金","長期預け金","出資金"]
def investments_total(idx, period: str) -> int:
    if "投資その他の資産" in idx:
        return get(idx,"投資その他の資産",period)
    total, anyp = 0, False
    for p in INVESTMENT_PARTS:
        if p in idx:
            anyp = True
            total += get(idx,p,period)
    return total if anyp else 0

def self_equity(idx, period: str) -> int:
    v = get(idx,"純資産合計",period)
    v -= get(idx,"新株予約権",period)
    v -= get(idx,"非支配株主持分",period)
    return v

def fixed_assets(idx, period: str) -> int:
    v = get(idx,"固定資産合計",period)
    if v != 0: return v
    return get(idx,"有形固定資産",period) + get(idx,"無形固定資産",period) + investments_total(idx, period)

def quick_assets(idx, period: str) -> int:
    return get(idx,"流動資産合計",period) - inventory_ending(idx, period)

def total_liabilities(idx, period: str) -> int:
    v = get(idx,"負債合計",period)
    if v != 0: return v
    return get(idx,"流動負債合計",period) + get(idx,"固定負債合計",period)

# 増減率（後期−前期）/前期。分母が0または欠損なら None
def change_rate(new: Optional[float], old: Optional[float]) -> Optional[float]:
    if old in (None, 0) or new is None: return None
    return (new - old) / old

# ---- 指標定義（順序） ----
METRICS_ORDER = [
    ("売上高", ""),
    ("売上原価", ""),
    ("売上総利益", ""),
    ("売上総利益率", "売上総利益 ÷ 売上高 × 100"),
    ("売上原価率", "売上原価 ÷ 売上高 × 100"),
    ("一般管理販売費", ""),
    ("売上高経費比率", "一般管理販売費 ÷ 売上高 × 100"),
    ("粗利経費率", "一般管理販売費 ÷ 総利益 × 100"),
    ("営業利益", ""),
    ("営業利益率", "営業利益 ÷ 売上高 × 100"),
    ("営業外収益", ""),
    ("営業外費用", ""),
    ("経常利益", ""),
    ("経常利益率", "経常利益 ÷ 売上高 × 100"),
    ("税引前利益", ""),
    ("当期利益", ""),
    ("現預金", ""),
    ("棚卸資産", ""),
    ("棚卸回転率（回転/年）", "売上原価 ÷ ((前期棚卸資産＋棚卸資産)/2)"),
    # ▼ ％表記に戻す
    ("流動比率", "流動資産 ÷ 流動負債 × 100"),
    ("当座比率", "(流動資産 − 棚卸資産) ÷ 流動負債 × 100"),
    # ▲
    ("有形固定資産額", ""),
    ("その他の投資", ""),
    ("（投資有価証券）", ""),
    ("固定比率", "固定資産 ÷ 純資産 × 100"),
    ("固定長期適合率", "固定資産 ÷ (純資産 + 固定負債) × 100"),
    ("借入金", ""),
    ("支払利息", ""),
    ("賞与引当金", ""),
    ("退職給与引当金", ""),
    ("資本金", ""),
    ("自己資本額", "純資産 −（新株予約権＋非支配株主持分）"),
    ("自己資本比率", "自己資本 ÷ 総資産 × 100"),
    ("負債比率", "負債 ÷ 純資産 × 100"),
    ("財務レバレッジ比率", "総資産 ÷ 自己資本"),
    ("自己資本利益率（ROE）", "当期純利益 ÷ 自己資本 × 100"),
    ("総資本金額", "総資産"),
    ("総資本回転率", "売上高 ÷ 総資産"),
    ("総資産利益率（ROA）", "当期純利益 ÷ 総資産 × 100"),
]

# ---- 「生値」取得（増減率・根拠用） ----
def raw_value(idx, metric: str, period: str) -> Optional[float]:
    if metric == "売上高":
        # 売上高が無い期間は純売上高の金額を使う
        return sales_with_net_sales(idx, period)
    if metric == "売上原価": return get(idx,"売上原価",period)
    if metric == "売上総利益":
        v = get(idx,"売上総利益",period)
        if v == 0: v = sales_with_net_sales(idx, period) - get(idx,"売上原価",period)
        return v
    if metric == "売上総利益率":
        return safe_div(raw_value(idx,"売上総利益",period), sales_with_net_sales(idx, period))
    if metric == "売上原価率":
        return safe_div(get(idx,"売上原価",period), sales_with_net_sales(idx, period))
    if metric == "一般管理販売費":
        return get(idx,"販売費及び一般管理費",period)
    if metric == "売上高経費比率":
        return safe_div(get(idx,"販売費及び一般管理費",period), sales_with_net_sales(idx, period))
    if metric == "粗利経費率":
        return safe_div(get(idx,"販売費及び一般管理費",period), raw_value(idx,"売上総利益",period))
    if metric == "営業利益":
        v = get(idx,"営業利益",period)
        if v == 0:
            v = raw_value(idx,"売上総利益",period) - get(idx,"販売費及び一般管理費",period)
        return v
    if metric == "営業利益率":
        return safe_div(raw_value(idx,"営業利益",period), sales_with_net_sales(idx, period))
    if metric == "営業外収益": return get(idx,"営業外収益",period)
    if metric == "営業外費用": return get(idx,"営業外費用",period)
    if metric == "経常利益":
        v = get(idx,"経常利益",period)
        if v == 0:
            v = raw_value(idx,"営業利益",period) + get(idx,"営業外収益",period) - get(idx,"営業外費用",period)
        return v
    if metric == "経常利益率":
        return safe_div(raw_value(idx,"経常利益",period), sales_with_net_sales(idx, period))
    if metric == "税引前利益": return get(idx,"税引前利益",period)
    if metric == "当期利益": return get(idx,"当期純利益",period)
    if metric == "現預金": return get(idx,"現金及び預金",period)
    if metric == "棚卸資産": return inventory_ending(idx, period)
    if metric == "棚卸回転率（回転/年）":
        if period == "前々期": return None
        this_inv = inventory_ending(idx, period)
        prev_key = "前期" if period == "今期" else "前々期"
        prev_inv = inventory_ending(idx, prev_key)
        denom = (this_inv + prev_inv)/2 if (this_inv or prev_inv) else 0
        return safe_div(get(idx,"売上原価",period), denom)
    # ★ ％表示（生値は比率＝分子÷分母）
    if metric == "流動比率": return safe_div(get(idx,"流動資産合計",period), get(idx,"流動負債合計",period))
    if metric == "当座比率": return safe_div(get(idx,"流動資産合計",period)-inventory_ending(idx, period), get(idx,"流動負債合計",period))
    if metric == "有形固定資産額": return get(idx,"有形固定資産",period)
    if metric == "その他の投資": return investments_total(idx, period)
    if metric == "（投資有価証券）": return get(idx,"投資有価証券",period)
    if metric == "固定比率": return safe_div(fixed_assets(idx, period), get(idx,"純資産合計",period))
    if metric == "固定長期適合率": return safe_div(fixed_assets(idx, period), get(idx,"純資産合計",period)+get(idx,"固定負債合計",period))
    if metric == "借入金": return get(idx,"短期借入金",period) + get(idx,"長期借入金",period)
    if metric == "支払利息": return get(idx,"支払利息",period)
    if metric == "賞与引当金":
        v = get(idx,"賞与引当金",period); return v if v!=0 else None
    if metric == "退職給与引当金":
        v = get(idx,"退職給付引当金",period); return v if v!=0 else None
    if metric == "資本金": return get(idx,"資本金",period)
    if metric == "自己資本額": return self_equity(idx, period)
    if metric == "自己資本比率": return safe_div(self_equity(idx, period), get(idx,"総資産",period))
    if metric == "負債比率": return safe_div(total_liabilities(idx, period), get(idx,"純資産合計",period))
    if metric == "財務レバレッジ比率":
        eq = self_equity(idx, period)
        if eq is None or eq <= 0: return None
        return safe_div(get(idx,"総資産",period), eq)
    if metric == "自己資本利益率（ROE）":
        eq = self_equity(idx, period)
        if eq is None or eq <= 0: return None
        return safe_div(get(idx,"当期純利益",period), eq)
    if metric == "総資本金額": return get(idx,"総資産",period)
    if metric == "総資本回転率": return safe_div(sales_with_net_sales(idx, period), get(idx,"総資産",period))
    if metric == "総資産利益率（ROA）":
        ta = get(idx,"総資産",period)
        if ta is None or ta <= 0: return None
        return safe_div(get(idx,"当期純利益",period), ta)
    return None

# ---- 表示フォーマット ----
RATE_AS_PERCENT = {
    # ％表示の指標（今回「流動比率」「当座比率」を再追加）
    "売上総利益率","売上原価率","売上高経費比率","粗利経費率",
    "営業利益率","経常利益率","固定比率","固定長期適合率",
    "自己資本比率","負債比率","自己資本利益率（ROE）","総資産利益率（ROA）",
    "流動比率","当座比率"
}
RATIO_AS_NUMBER = {
    # 倍・回表示
    "棚卸回転率（回転/年）","財務レバレッジ比率","総資本回転率"
}

def fmt_by_metric(metric: str, value: Optional[float]) -> str:
    if metric in RATE_AS_PERCENT:
        if metric in {"自己資本利益率（ROE）","総資産利益率（ROA）"} and value is None: return "N/A"
        return fmt_percent(value)
    elif metric in RATIO_AS_NUMBER:
        return fmt_ratio(value)
    else:
        return fmt_amount(value)

# ---- 計算本体（表＋増減率） ----
def compute_all(idx: Dict[str, Dict[str, int]]):
    rows = []
    for metric, formula in METRICS_ORDER:
        raw_pp = raw_value(idx, metric, "前々期")
        raw_p  = raw_value(idx, metric, "前期")
        raw_c  = raw_value(idx, metric, "今期")

        v_pp = fmt_by_metric(metric, raw_pp)
        v_p  = fmt_by_metric(metric, raw_p)
        v_c  = fmt_by_metric(metric, raw_c)

        gr_pp_p = change_rate(raw_p, raw_pp)
        gr_p_c  = change_rate(raw_c, raw_p)
        gr_pp_p_s = fmt_percent(gr_pp_p) if gr_pp_p is not None else ""
        gr_p_c_s  = fmt_percent(gr_p_c)  if gr_p_c  is not None else ""

        rows.append({
            "指標": metric,
            "計算式": formula,
            "前々期値": v_pp,
            "前期値": v_p,
            "今期値": v_c,
            "増減率（前々期→前期）": gr_pp_p_s,
            "増減率（前期→今期）": gr_p_c_s,
        })
    return rows

# ---- 計算根拠（テキスト） ----
def breakdown_lines(idx: Dict[str, Dict[str, int]]) -> List[str]:
    lines: List[str] = []
    lines.append("【対象指標と計算根拠】")

    def amount_line(title: str, period: str, value: Optional[float]):
        v = fmt_amount(value if value is not None else None)
        lines.append(f"■ {title}（{period}）：{v}")

    def rate_line_percent(title: str, period: str, num_name: str, num_v: Optional[float],
                          den_name: str, den_v: Optional[float], disp: str):
        lines.append(f"■ {title}（{period}）：{num_name} {fmt_amount(num_v)}、{den_name} {fmt_amount(den_v)} → {disp}")

    def rate_line_ratio(title: str, period: str, num_name: str, num_v: Optional[float],
                        den_name: str, den_v: Optional[float], disp: str):
        lines.append(f"■ {title}（{period}）：{num_name} {fmt_amount(num_v)}、{den_name} {fmt_amount(den_v)} → {disp}")

    for metric, formula in METRICS_ORDER:
        lines.append(f"■ {metric}（{formula if formula else '金額/数値'}）")
        for period in ("今期", "前期", "前々期"):
            if metric == "売上高":
                amount_line("売上高", period, sales_with_net_sales(idx, period))
            elif metric == "売上原価":
                amount_line("売上原価", period, get(idx,"売上原価",period))
            elif metric == "売上総利益":
                gp = raw_value(idx,"売上総利益",period)
                amount_line("売上総利益", period, gp)
            elif metric == "売上総利益率":
                gp = raw_value(idx,"売上総利益",period); s = fmt_percent(safe_div(gp, sales_with_net_sales(idx, period)))
                rate_line_percent("売上総利益率", period, "売上総利益", gp, "売上高", sales_with_net_sales(idx, period), s)
            elif metric == "売上原価率":
                cogs = get(idx,"売上原価",period); s = fmt_percent(safe_div(cogs, sales_with_net_sales(idx, period)))
                rate_line_percent("売上原価率", period, "売上原価", cogs, "売上高", sales_with_net_sales(idx, period), s)
            elif metric == "一般管理販売費":
                amount_line("一般管理販売費", period, get(idx,"販売費及び一般管理費",period))
            elif metric == "売上高経費比率":
                sgna = get(idx,"販売費及び一般管理費",period); s = fmt_percent(safe_div(sgna, sales_with_net_sales(idx, period)))
                rate_line_percent("売上高経費比率", period, "一般管理販売費", sgna, "売上高", sales_with_net_sales(idx, period), s)
            elif metric == "粗利経費率":
                gp = raw_value(idx,"売上総利益",period); sgna = get(idx,"販売費及び一般管理費",period)
                s = fmt_percent(safe_div(sgna, gp))
                rate_line_percent("粗利経費率", period, "一般管理販売費", sgna, "売上総利益", gp, s)
            elif metric == "営業利益":
                amount_line("営業利益", period, raw_value(idx,"営業利益",period))
            elif metric == "営業利益率":
                op = raw_value(idx,"営業利益",period); s = fmt_percent(safe_div(op, sales_with_net_sales(idx, period)))
                rate_line_percent("営業利益率", period, "営業利益", op, "売上高", sales_with_net_sales(idx, period), s)
            elif metric == "営業外収益":
                amount_line("営業外収益", period, get(idx,"営業外収益",period))
            elif metric == "営業外費用":
                amount_line("営業外費用", period, get(idx,"営業外費用",period))
            elif metric == "経常利益":
                amount_line("経常利益", period, raw_value(idx,"経常利益",period))
            elif metric == "経常利益率":
                rp = raw_value(idx,"経常利益",period); s = fmt_percent(safe_div(rp, sales_with_net_sales(idx, period)))
                rate_line_percent("経常利益率", period, "経常利益", rp, "売上高", sales_with_net_sales(idx, period), s)
            elif metric == "税引前利益":
                amount_line("税引前利益", period, get(idx,"税引前利益",period))
            elif metric == "当期利益":
                amount_line("当期利益", period, get(idx,"当期純利益",period))
            elif metric == "現預金":
                amount_line("現預金", period, get(idx,"現金及び預金",period))
            elif metric == "棚卸資産":
                amount_line("棚卸資産", period, inventory_ending(idx, period))
            elif metric == "棚卸回転率（回転/年）":
                if period == "前々期":
                    lines.append("■ 棚卸回転率（前々期）：前期データ不足のため算出不可")
                else:
                    this_inv = inventory_ending(idx, period)
                    prev_key = "前期" if period == "今期" else "前々期"
                    prev_inv = inventory_ending(idx, prev_key)
                    denom = (this_inv + prev_inv)/2 if (this_inv or prev_inv) else 0
                    s = fmt_ratio(safe_div(get(idx,"売上原価",period), denom))
                    lines.append(f"■ 棚卸回転率（{period}）：売上原価 {fmt_amount(get(idx,'売上原価',period))} ÷ (({prev_key}棚卸資産 {fmt_amount(prev_inv)} + {period}棚卸資産 {fmt_amount(this_inv)})/2) = {s}")
            elif metric == "流動比率":
                s = fmt_percent(safe_div(get(idx,"流動資産合計",period), get(idx,"流動負債合計",period)))
                rate_line_percent("流動比率", period, "流動資産", get(idx,"流動資産合計",period), "流動負債", get(idx,"流動負債合計",period), s)
            elif metric == "当座比率":
                qa = quick_assets(idx, period); s = fmt_percent(safe_div(qa, get(idx,"流動負債合計",period)))
                rate_line_percent("当座比率", period, "当座資産(流動資産−棚卸資産)", qa, "流動負債", get(idx,"流動負債合計",period), s)
            elif metric == "有形固定資産額":
                amount_line("有形固定資産額", period, get(idx,"有形固定資産",period))
            elif metric == "その他の投資":
                total = investments_total(idx, period)
                if "投資その他の資産" in idx:
                    lines.append(f"■ その他の投資（{period}）：投資その他の資産 {fmt_amount(total)}（総称）")
                else:
                    parts = []
                    for p in INVESTMENT_PARTS:
                        v = get(idx, p, period)
                        if v: parts.append(f"{p} {fmt_amount(v)}")
                    lines.append(f"■ その他の投資（{period}）：" + (" + ".join(parts) if parts else "該当なし") + f" = {fmt_amount(total)}")
            elif metric == "（投資有価証券）":
                amount_line("（投資有価証券）", period, get(idx,"投資有価証券",period))
            elif metric == "固定比率":
                fa = fixed_assets(idx, period); na = get(idx,"純資産合計",period)
                s = fmt_percent(safe_div(fa, na))
                rate_line_percent("固定比率", period, "固定資産", fa, "純資産", na, s)
            elif metric == "固定長期適合率":
                fa = fixed_assets(idx, period); base = get(idx,"純資産合計",period) + get(idx,"固定負債合計",period)
                s = fmt_percent(safe_div(fa, base))
                rate_line_percent("固定長期適合率", period, "固定資産", fa, "純資産+固定負債", base, s)
            elif metric == "借入金":
                amount_line("借入金（短期+長期）", period, get(idx,"短期借入金",period) + get(idx,"長期借入金",period))
            elif metric == "支払利息":
                amount_line("支払利息", period, get(idx,"支払利息",period))
            elif metric == "賞与引当金":
                v = get(idx,"賞与引当金",period); amount_line("賞与引当金", period, v if v!=0 else None)
            elif metric == "退職給与引当金":
                v = get(idx,"退職給付引当金",period); amount_line("退職給与引当金", period, v if v!=0 else None)
            elif metric == "資本金":
                amount_line("資本金", period, get(idx,"資本金",period))
            elif metric == "自己資本額":
                amount_line("自己資本額（純資産−新株予約権−非支配株主持分）", period, self_equity(idx, period))
            elif metric == "自己資本比率":
                se = self_equity(idx, period); ta = get(idx,"総資産",period)
                s = fmt_percent(safe_div(se, ta))
                rate_line_percent("自己資本比率", period, "自己資本", se, "総資産", ta, s)
            elif metric == "負債比率":
                li = total_liabilities(idx, period); na = get(idx,"純資産合計",period)
                s = fmt_percent(safe_div(li, na))
                rate_line_percent("負債比率", period, "負債", li, "純資産", na, s)
            elif metric == "財務レバレッジ比率":
                se = self_equity(idx, period); ta = get(idx,"総資産",period)
                if se <= 0:
                    lines.append(f"■ 財務レバレッジ比率（{period}）：自己資本が0以下のため N/A")
                else:
                    s = fmt_ratio(safe_div(ta, se))
                    rate_line_ratio("財務レバレッジ比率", period, "総資産", ta, "自己資本", se, s)
            elif metric == "自己資本利益率（ROE）":
                se = self_equity(idx, period); ni = get(idx,"当期純利益",period)
                if se <= 0:
                    lines.append(f"■ 自己資本利益率（ROE）（{period}）：自己資本が0以下のため N/A")
                else:
                    s = fmt_percent(safe_div(ni, se))
                    rate_line_percent("自己資本利益率（ROE）", period, "当期純利益", ni, "自己資本", se, s)
            elif metric == "総資本金額":
                amount_line("総資本金額（=総資産）", period, get(idx,"総資産",period))
            elif metric == "総資本回転率":
                ta = get(idx,"総資産",period); s = fmt_ratio(safe_div(sales_with_net_sales(idx, period), ta))
                rate_line_ratio("総資本回転率", period, "売上高", sales_with_net_sales(idx, period), "総資産", ta, s)
            elif metric == "総資産利益率（ROA）":
                ta = get(idx,"総資産",period); ni = get(idx,"当期純利益",period)
                if ta <= 0:
                    lines.append(f"■ 総資産利益率（ROA）（{period}）：総資産が0以下のため N/A")
                else:
                    s = fmt_percent(safe_div(ni, ta))
                    rate_line_percent("総資産利益率（ROA）", period, "当期純利益", ni, "総資産", ta, s)
        lines.append("")
    return lines

# ---- 実行 ----
if __name__ == "__main__":
    
    if len(sys.argv) >= 4:
        INPUT_JSON_PATH  = sys.argv[1]
        OUTPUT_CSV_PATH  = sys.argv[2]
        OUTPUT_JSON_PATH = sys.argv[3]
    else:
        raise SystemExit(
            "usage: financial.py <INPUT_JSON_PATH> <OUTPUT_CSV_PATH> <OUTPUT_JSON_PATH>"
        )
    if not os.path.exists(INPUT_JSON_PATH):
        raise FileNotFoundError(f"{INPUT_JSON_PATH} が見つかりません。")
    idx = load_bspl(INPUT_JSON_PATH)
    rows = compute_all(idx)

    # 計算根拠テキスト
    bdl = breakdown_lines(idx)

    # CSV 出力
    with open(OUTPUT_CSV_PATH, "w", encoding="utf-8-sig", newline="") as f:
        w = csv.writer(f)
        w.writerow(["指標","計算式","前々期値","前期値","今期値","増減率（前々期→前期）","増減率（前期→今期）"])
        for r in rows:
            w.writerow([r["指標"], r["計算式"], r["前々期値"], r["前期値"], r["今期値"],
                        r["増減率（前々期→前期）"], r["増減率（前期→今期）"]])
        w.writerow([])  # 空行
        for line in bdl:
            w.writerow([line])

    # JSON 出力
    out_json = {
        "columns": ["指標","計算式","前々期値","前期値","今期値","増減率（前々期→前期）","増減率（前期→今期）"],
        "rows": rows,
        "breakdown_title": "【対象指標と計算根拠】",
        "breakdown_lines": bdl
    }
    with open(OUTPUT_JSON_PATH, "w", encoding="utf-8") as f:
        json.dump(out_json, f, ensure_ascii=False, indent=2)

    # 追加: 7項目の簡易JSONを OUTPUT_JSON_PATH+"2" に出力
    def _val_or_empty(v):
        try:
            return "" if v in (None, 0) else int(v)
        except Exception:
            return ""

    pp, p, c = "前々期", "前期", "今期"

    simple_json = {
        "流動資産": {
            "previous_previous_term": _val_or_empty(get(idx, "流動資産合計", pp)),
            "previous_term":          _val_or_empty(get(idx, "流動資産合計", p)),
            "current_term":           _val_or_empty(get(idx, "流動資産合計", c)),
        },
        "流動負債": {
            "previous_previous_term": _val_or_empty(get(idx, "流動負債合計", pp)),
            "previous_term":          _val_or_empty(get(idx, "流動負債合計", p)),
            "current_term":           _val_or_empty(get(idx, "流動負債合計", c)),
        },
        "期首棚卸": {
            "previous_previous_term": _val_or_empty(get(idx, "期首商品棚卸高", pp)),
            "previous_term":          _val_or_empty(get(idx, "期首商品棚卸高", p)),
            "current_term":           _val_or_empty(get(idx, "期首商品棚卸高", c)),
        },
        "期末棚卸": {
            "previous_previous_term": _val_or_empty(inventory_ending(idx, pp)),
            "previous_term":          _val_or_empty(inventory_ending(idx, p)),
            "current_term":           _val_or_empty(inventory_ending(idx, c)),
        },
        "固定資産": {
            "previous_previous_term": _val_or_empty(fixed_assets(idx, pp)),
            "previous_term":          _val_or_empty(fixed_assets(idx, p)),
            "current_term":           _val_or_empty(fixed_assets(idx, c)),
        },
        "固定負債": {
            "previous_previous_term": _val_or_empty(get(idx, "固定負債合計", pp)),
            "previous_term":          _val_or_empty(get(idx, "固定負債合計", p)),
            "current_term":           _val_or_empty(get(idx, "固定負債合計", c)),
        },
        "当期純利益": {
            "previous_previous_term": _val_or_empty(get(idx, "当期純利益", pp)),
            "previous_term":          _val_or_empty(get(idx, "当期純利益", p)),
            "current_term":           _val_or_empty(get(idx, "当期純利益", c)),
        },
    }

    with open(OUTPUT_JSON_PATH + "2", "w", encoding="utf-8") as f:
        json.dump(simple_json, f, ensure_ascii=False, indent=2)

    print(f"出力完了: {OUTPUT_CSV_PATH}, {OUTPUT_JSON_PATH} および {OUTPUT_JSON_PATH + '2'}")
