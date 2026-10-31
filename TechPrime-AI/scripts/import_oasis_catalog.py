#!/usr/bin/env python3
"""
Category-by-category Oasis catalog import + verified EasyPC product images.

- Source of truth: Oasis-Itemlist.csv (261 products)
- Stock: Quantity On Hand -> products.stock via catalog_db.php
- Images: assets/products/{Category}/ with Name-based filenames
- Manifest: product_image_manifest.csv
- Missing images: products_without_images.csv
"""
from __future__ import annotations

import csv
import io
import json
import re
import subprocess
import sys
import time
from collections import OrderedDict, Counter
from pathlib import Path
from urllib.parse import quote

import requests
from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
CSV_PATH = ROOT / "Oasis-Itemlist.csv"
OUT_DIR = ROOT / "assets" / "products"
MANIFEST = ROOT / "product_image_manifest.csv"
NO_IMAGE = ROOT / "products_without_images.csv"
TMP_JSON = ROOT / "scripts" / "_tmp_product.json"
PHP = "php"
DB_HELPER = ROOT / "scripts" / "catalog_db.php"

MAX_BYTES = 1_073_741_824
PREF_MAX = 900 * 1024 * 1024
SIZE_LIMIT = 12_000_000  # skip individual downloads larger than ~12MB

SESSION = requests.Session()
SESSION.headers.update(
    {
        "User-Agent": (
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) "
            "AppleWebKit/537.36 (KHTML, like Gecko) "
            "Chrome/124.0.0.0 Safari/537.36"
        ),
        "Accept": "application/json,text/html,*/*",
    }
)

MANIFEST_FIELDS = [
    "MSKU",
    "Product Description",
    "Category",
    "Image Filename",
    "Image Relative Path",
    "Source URL",
    "Source Type",
    "Match Method",
    "Match Confidence",
    "Image Status",
    "File Size Bytes",
    "Notes",
]

NO_IMAGE_FIELDS = [
    "MSKU",
    "Product Name",
    "Product Description",
    "Category",
    "Product/Warranty Type",
    "Product/Sales Price",
    "Quantity On Hand",
    "Image Status",
    "Source URL",
    "Notes",
]

SUCCESS_STATUSES = {"DOWNLOADED", "ALREADY_EXISTS_VERIFIED"}


def title_category(raw: str) -> str:
    raw = (raw or "").strip()
    if not raw:
        return "Others"
    # Known multi-word categories keep readable Title Case
    known = {
        "ACCESSORIES": "Accessories",
        "AUDIO": "Audio",
        "CABLES AND ADAPTERS": "Cables and Adapters",
        "CAMERA": "Camera",
        "COMBO": "Combo",
        "COOLING": "Cooling",
        "CUSTOMIZATION": "Customization",
        "DISPLAY": "Display",
        "GAMING SURFACE": "Gaming Surface",
        "GRAPHIC CARD": "Graphic Card",
        "HARD DISK": "Hard Disk",
        "HOME & OFFICE FURNITURE": "Home & Office Furniture",
        "KEYBOARD": "Keyboard",
        "LAPTOP GA2": "Laptop GA2",
        "LAPTOP GA3": "Laptop GA3",
        "LAPTOP PR2": "Laptop PR2",
        "LAPTOP PR3": "Laptop PR3",
        "MEMORY": "Memory",
        "MINI PC": "Mini PC",
        "MOTHERBOARD": "Motherboard",
        "MOUSE": "Mouse",
        "NETWORK DEVICE": "Network Device",
        "PC CASE": "PC Case",
        "POWER STATION": "Power Station",
        "POWER SUPPLY": "Power Supply",
        "PRINTER AND SCANNER": "Printer and Scanner",
        "PROCESSOR": "Processor",
        "PROMOTIONAL": "Promotional",
        "RECORDER": "Recorder",
        "SERVICES": "Services",
        "SOFTWARE": "Software",
        "SOLID STATE DRIVE": "Solid State Drive",
        "SPEAKER": "Speaker",
        "UPS & AVR": "UPS & AVR",
        "VALUE PLUS": "Value Plus",
    }
    key = raw.upper()
    if key in known:
        return known[key]
    parts = []
    for tok in raw.split():
        if tok.upper() in {"AND"}:
            parts.append("and")
        elif tok == "&":
            parts.append("&")
        else:
            parts.append(tok[:1].upper() + tok[1:].lower() if tok else tok)
    return " ".join(parts)


def folder_category(csv_cat: str) -> str:
    """Windows-safe folder name from actual CSV category (title-cased)."""
    name = title_category(csv_cat)
    name = re.sub(r'[<>:"/\\|?*]', "", name).strip()
    name = re.sub(r"\s+", " ", name)
    return name or "Others"


def db_category(csv_cat: str) -> str:
    """Map CSV category onto existing TechPrime-AI category labels where applicable."""
    key = (csv_cat or "").strip().upper()
    mapping = {
        "GRAPHIC CARD": "Graphic Card",
        "MEMORY": "RAM",
        "PRINTER AND SCANNER": "Printer and Scanner",
        "LAPTOP GA2": "Laptop GA2",
        "LAPTOP GA3": "Laptop GA3",
        "LAPTOP PR2": "Laptop PR2",
        "LAPTOP PR3": "Laptop PR3",
        "MINI PC": "Mini PC",
        "CABLES AND ADAPTERS": "Cables and Adapters",
        "HOME & OFFICE FURNITURE": "Home & Office Furniture",
        "HARD DISK": "Hard Disk",
        "GAMING SURFACE": "Gaming Surface",
        "NETWORK DEVICE": "Network Device",
        "PC CASE": "PC Case",
        "POWER STATION": "Power Station",
        "POWER SUPPLY": "Power Supply",
        "SOLID STATE DRIVE": "Solid State Drive",
        "UPS & AVR": "UPS & AVR",
        "VALUE PLUS": "Value Plus",
    }
    if key in mapping:
        return mapping[key]
    return title_category(csv_cat)


def sanitize_filename(name: str) -> str:
    name = (name or "").strip()
    name = re.sub(r"[\r\n\t]+", " ", name)
    name = re.sub(r'[<>:"/\\|?*\x00-\x1f]', "", name)
    name = re.sub(r"\s+", " ", name).strip(" .")
    if not name:
        name = "product"
    return name[:180]


def dir_size(path: Path) -> int:
    if not path.exists():
        return 0
    return sum(f.stat().st_size for f in path.rglob("*") if f.is_file())


def php_run(*args: str) -> str:
    cmd = [PHP, str(DB_HELPER), *args]
    r = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True)
    if r.returncode != 0:
        raise RuntimeError(f"PHP failed: {' '.join(args)}\n{r.stderr}\n{r.stdout}")
    return (r.stdout or "").strip()


def upsert_product(payload: dict) -> dict:
    TMP_JSON.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
    out = php_run("upsert", str(TMP_JSON))
    return json.loads(out)


def set_image(sku: str, image: str, image_url: str = "") -> dict:
    TMP_JSON.write_text(
        json.dumps({"sku": sku, "image": image, "image_url": image_url}, ensure_ascii=False),
        encoding="utf-8",
    )
    out = php_run("set_image", str(TMP_JSON))
    return json.loads(out)


def load_csv() -> list[dict]:
    rows: list[dict] = []
    with CSV_PATH.open(encoding="utf-8-sig", newline="") as f:
        reader = csv.DictReader(f)
        for r in reader:
            name = re.sub(r"\s+", " ", (r.get("Name") or "").strip())
            desc = (r.get("Product Description") or "").strip()
            warranty = (r.get("Product/Warranty Type") or "").strip()
            price_raw = (r.get("Product/Sales Price") or "0").replace(",", "").strip()
            try:
                price = float(price_raw)
            except ValueError:
                price = 0.0
            qoh_raw = (r.get("Quantity On Hand") or "0").strip()
            try:
                qoh = int(float(qoh_raw))
            except ValueError:
                qoh = 0
            full_desc = desc
            if warranty:
                full_desc = (desc + "\n\nProduct/Warranty Type: " + warranty).strip()
            rows.append(
                {
                    "csv_category": (r.get("Product Category") or "").strip(),
                    "msku": (r.get("MSKU") or "").strip(),
                    "name": name,
                    "description": desc,
                    "full_description": full_desc,
                    "warranty": warranty,
                    "price": price,
                    "stock": qoh,
                }
            )
    return rows


def normalize_text(s: str) -> str:
    s = (s or "").lower()
    s = s.replace("×", "x")
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def significant_tokens(s: str) -> list[str]:
    stop = {
        "the", "and", "for", "with", "from", "pcs", "pc", "set", "pair", "new",
        "oem", "gen", "series", "edition", "version", "black", "white", "rgb",
    }
    toks = []
    for t in normalize_text(s).split():
        if t in stop:
            continue
        if len(t) < 2 and not t.isdigit():
            continue
        toks.append(t)
    return toks


SPEC_PATTERNS = [
    re.compile(r"\b(rtx|gtx|rx)\s*\d{3,4}\s*(ti|super)?\b", re.I),
    re.compile(r"\b\d+\s*gb\b", re.I),
    re.compile(r"\b\d+\s*tb\b", re.I),
    re.compile(r"\bddr[45]\b", re.I),
    re.compile(r"\b\d{3,5}\s*mhz\b", re.I),
    re.compile(r"\b\d{2,3}(\.\d)?\s*inch\b", re.I),
    re.compile(r"\b\d{2,3}(\.\d)?\"", re.I),
    re.compile(r"\b\d{2,4}\s*hz\b", re.I),
    re.compile(r"\b(i[3579]|ryzen\s*[3579]|r[3579])\s*-?\s*\d{4,5}\w*\b", re.I),
    re.compile(r"\b\d{3,4}\s*w\b", re.I),
    re.compile(r"\b(wifi|non[\s-]?wifi|oc|non[\s-]?oc)\b", re.I),
]


def extract_specs(s: str) -> set[str]:
    found = set()
    for pat in SPEC_PATTERNS:
        for m in pat.finditer(s or ""):
            found.add(normalize_text(m.group(0)))
    return found


def titles_compatible(product_name: str, candidate_title: str) -> tuple[bool, str, str]:
    """Return (ok, confidence, note). Prefer missing over wrong."""
    pn = normalize_text(product_name)
    ct = normalize_text(candidate_title)
    if not pn or not ct:
        return False, "NONE", "empty title"

    # OC / non-OC must agree when either side mentions OC
    p_has_oc = bool(re.search(r"\boc\b", pn)) and not bool(re.search(r"non\s*oc", pn))
    c_has_oc = bool(re.search(r"\boc\b", ct)) and not bool(re.search(r"non\s*oc", ct))
    if p_has_oc != c_has_oc:
        return False, "NONE", "oc variant mismatch"

    # WiFi / non-WiFi must agree when either side mentions wifi
    p_has_wifi = bool(re.search(r"\bwifi\b", pn)) and not bool(re.search(r"non\s*wifi", pn))
    c_has_wifi = bool(re.search(r"\bwifi\b", ct)) and not bool(re.search(r"non\s*wifi", ct))
    p_mentions_wifi = bool(re.search(r"wifi", pn))
    c_mentions_wifi = bool(re.search(r"wifi", ct))
    if p_mentions_wifi and c_mentions_wifi and p_has_wifi != c_has_wifi:
        return False, "NONE", "wifi variant mismatch"

    # Color must agree when both mention black/white
    for color in ("black", "white"):
        if (color in pn) != (color in ct):
            # only enforce when at least one side is a case/peripheral color signal
            if color in pn or color in ct:
                # allow if the other side has no color word at all and product isn't color-critical
                p_colors = {c for c in ("black", "white", "ivory", "silver", "grey", "gray", "red", "blue") if c in pn}
                c_colors = {c for c in ("black", "white", "ivory", "silver", "grey", "gray", "red", "blue") if c in ct}
                if p_colors and c_colors and p_colors.isdisjoint(c_colors):
                    return False, "NONE", f"color mismatch p={sorted(p_colors)} c={sorted(c_colors)}"
            break

    # Alphanumeric model tokens present in product must appear in candidate
    # (e.g. L3310 vs L3210, ST1000DM010 vs ST1000DM014)
    model_toks = []
    for t in re.findall(r"\b(?=[a-z]*\d)[a-z0-9]{4,}\b", pn):
        if t in {"ddr4", "ddr5", "gddr6", "gddr7", "wifi", "rgb", "argb", "atx", "matx"}:
            continue
        if re.search(r"\d", t):
            model_toks.append(t)
    for t in model_toks:
        if t not in ct:
            # allow minor soft misses for capacity-like tokens handled by specs
            if re.fullmatch(r"\d+gb", t) or re.fullmatch(r"\d+tb", t):
                continue
            return False, "NONE", f"model token missing: {t}"

    # Parenthetical / dashed model codes (e.g. DUAL-RTX5060-8G vs -O8G)
    def model_codes(s: str) -> set[str]:
        codes = set()
        for m in re.findall(r"\(([A-Za-z0-9][A-Za-z0-9._/-]{3,})\)", s or ""):
            codes.add(normalize_text(m).replace(" ", ""))
        for m in re.findall(r"\b([A-Z]{2,}[A-Z0-9]*-[A-Z0-9-]{3,})\b", s or "", flags=re.I):
            codes.add(normalize_text(m).replace(" ", ""))
        return {c for c in codes if len(c) >= 5}

    p_codes = model_codes(product_name)
    c_codes = model_codes(candidate_title)
    if p_codes and c_codes and p_codes.isdisjoint(c_codes):
        # allow if one code is substring of other only when equal after stripping oc markers carefully
        return False, "NONE", f"model code mismatch p={sorted(p_codes)} c={sorted(c_codes)}"

    # Exact / near-exact
    if pn == ct or pn in ct or ct in pn:
        return True, "HIGH", "exact/near title"

    p_specs = extract_specs(product_name)
    c_specs = extract_specs(candidate_title)
    if p_specs:
        missing = p_specs - c_specs
        if missing:
            return False, "NONE", f"spec mismatch missing={sorted(missing)}"

    p_toks = significant_tokens(product_name)
    c_toks = set(significant_tokens(candidate_title))
    if not p_toks:
        return False, "NONE", "no tokens"

    hits = sum(1 for t in p_toks if t in c_toks)
    ratio = hits / len(p_toks)
    if len(p_toks) >= 4 and ratio >= 0.75 and hits >= 3:
        return True, "HIGH", f"token_ratio={ratio:.2f}"
    if len(p_toks) >= 3 and ratio >= 0.8 and hits >= 2:
        return True, "HIGH", f"token_ratio={ratio:.2f}"
    if len(p_toks) <= 3 and ratio == 1.0:
        return True, "HIGH", "all short tokens"
    if ratio >= 0.9 and hits >= 2:
        return True, "MEDIUM", f"token_ratio={ratio:.2f}"

    return False, "NONE", f"weak match ratio={ratio:.2f} hits={hits}/{len(p_toks)}"


def easypc_suggest(query: str, retries: int = 5) -> list[dict]:
    url = (
        "https://easypc.com.ph/search/suggest.json"
        f"?q={quote(query)}&resources[type]=product&resources[limit]=6"
    )
    delay = 2.0
    for attempt in range(retries):
        try:
            r = SESSION.get(url, timeout=30)
            if r.status_code == 429:
                time.sleep(delay)
                delay = min(delay * 1.8, 45)
                continue
            if r.status_code != 200 or not r.text.startswith("{"):
                time.sleep(1.5)
                continue
            data = r.json()
            prods = (
                data.get("resources", {})
                .get("results", {})
                .get("products", [])
                or []
            )
            return prods if isinstance(prods, list) else []
        except Exception:
            time.sleep(delay)
            delay = min(delay * 1.5, 30)
    return []


def pick_image_url(prod: dict) -> str:
    for key in ("image", "featured_image", "featured_image_url"):
        v = prod.get(key)
        if isinstance(v, str) and v.startswith("http"):
            return v
        if isinstance(v, dict):
            for k2 in ("src", "url", "featured_image"):
                if isinstance(v.get(k2), str) and v[k2].startswith("http"):
                    return v[k2]
    return ""


def find_match(product: dict) -> tuple[dict | None, str, str, str]:
    """Return (prod, method, confidence, notes)."""
    name = product["name"]
    msku = product["msku"]
    queries = []
    if name:
        queries.append(("name", name))
    # shorter name without trailing fluff
    short = re.sub(r"\s*\([^)]*\)\s*", " ", name).strip()
    if short and short != name:
        queries.append(("name_short", short))
    if msku:
        queries.append(("msku", msku))

    best_uncertain = None
    for method, q in queries:
        time.sleep(1.1)  # be polite / avoid 429
        prods = easypc_suggest(q)
        for p in prods:
            title = (p.get("title") or "").strip()
            ok, conf, note = titles_compatible(name, title)
            img = pick_image_url(p)
            if not img:
                continue
            if ok and conf in ("HIGH", "MEDIUM"):
                # Extra guard: MSKU search must still pass name compatibility (already did)
                return p, method, conf, note
            if not ok and conf == "NONE" and method == "name":
                # keep uncertain only if very close ratio mentioned
                if "weak match ratio=0." in note:
                    # ignore
                    pass
            # Track near-misses for MATCH_UNCERTAIN only when name search returned something close
            if method.startswith("name") and "weak match" in note:
                m = re.search(r"ratio=([0-9.]+)", note)
                if m and float(m.group(1)) >= 0.55:
                    best_uncertain = (p, method, "LOW", note)

    if best_uncertain:
        return best_uncertain[0], best_uncertain[1], best_uncertain[2], best_uncertain[3]
    return None, "", "NONE", "no confident EasyPC match"


def unique_target_path(folder: Path, base_name: str, msku: str, ext: str) -> Path:
    folder.mkdir(parents=True, exist_ok=True)
    primary = folder / f"{base_name}.{ext}"
    if not primary.exists():
        # also treat same stem with other success as occupied only if same msku tracked externally
        return primary
    # same name collision -> suffix MSKU
    return folder / f"{base_name} - {msku}.{ext}"


def download_image(url: str, dest: Path) -> tuple[str, int, str]:
    """Download and save as jpg/png/webp. Returns (status, size, note)."""
    try:
        # HEAD-ish via GET stream
        with SESSION.get(url, timeout=45, stream=True) as r:
            if r.status_code != 200:
                return "DOWNLOAD_FAILED", 0, f"http {r.status_code}"
            cl = r.headers.get("Content-Length")
            if cl and cl.isdigit() and int(cl) > SIZE_LIMIT:
                return "DOWNLOAD_SKIPPED_SIZE_LIMIT", 0, f"content-length {cl}"

            current = dir_size(OUT_DIR)
            projected_add = int(cl) if cl and cl.isdigit() else 800_000
            if current + projected_add > MAX_BYTES:
                return "DOWNLOAD_SKIPPED_SIZE_LIMIT", 0, "assets/products would exceed 1GB"

            data = b""
            for chunk in r.iter_content(64 * 1024):
                if not chunk:
                    continue
                data += chunk
                if len(data) > SIZE_LIMIT:
                    return "DOWNLOAD_SKIPPED_SIZE_LIMIT", 0, "download exceeded per-file limit"

        if len(data) < 800:
            return "INVALID_IMAGE", 0, "too small"

        if dir_size(OUT_DIR) + len(data) > MAX_BYTES:
            return "DOWNLOAD_SKIPPED_SIZE_LIMIT", 0, "assets/products would exceed 1GB"

        try:
            im = Image.open(io.BytesIO(data))
            im.load()
            w, h = im.size
            if w < 100 or h < 100:
                return "INVALID_IMAGE", 0, f"dims {w}x{h}"
            # Prefer JPEG for shop compatibility; keep PNG if transparency needed
            fmt = (im.format or "").upper()
            if im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info):
                out_ext = "png"
                save_kwargs = {}
                if im.mode != "RGBA":
                    im = im.convert("RGBA")
            else:
                out_ext = "jpg"
                if im.mode != "RGB":
                    im = im.convert("RGB")
                save_kwargs = {"quality": 88, "optimize": True}

            # Adjust dest extension
            dest = dest.with_suffix("." + out_ext)
            dest.parent.mkdir(parents=True, exist_ok=True)
            im.save(dest, **save_kwargs)
            size = dest.stat().st_size
            return "DOWNLOADED", size, f"saved {w}x{h} {fmt or out_ext}"
        except Exception as e:
            return "INVALID_IMAGE", 0, f"decode error: {e}"
    except Exception as e:
        return "DOWNLOAD_FAILED", 0, str(e)


def load_manifest() -> dict[str, dict]:
    if not MANIFEST.exists():
        return {}
    with MANIFEST.open(encoding="utf-8", newline="") as f:
        return {r["MSKU"]: r for r in csv.DictReader(f) if r.get("MSKU")}


def write_manifest(rows_by_msku: dict[str, dict], order: list[str]) -> None:
    with MANIFEST.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=MANIFEST_FIELDS)
        w.writeheader()
        for msku in order:
            row = rows_by_msku[msku]
            w.writerow({k: row.get(k, "") for k in MANIFEST_FIELDS})


def write_no_image(products: list[dict], manifest: dict[str, dict]) -> int:
    n = 0
    with NO_IMAGE.open("w", encoding="utf-8", newline="") as f:
        w = csv.DictWriter(f, fieldnames=NO_IMAGE_FIELDS)
        w.writeheader()
        for p in products:
            m = manifest.get(p["msku"], {})
            status = m.get("Image Status", "NOT_FOUND")
            if status in SUCCESS_STATUSES:
                continue
            n += 1
            w.writerow(
                {
                    "MSKU": p["msku"],
                    "Product Name": p["name"],
                    "Product Description": p["description"],
                    "Category": p["csv_category"],
                    "Product/Warranty Type": p["warranty"],
                    "Product/Sales Price": p["price"],
                    "Quantity On Hand": p["stock"],
                    "Image Status": status,
                    "Source URL": m.get("Source URL", ""),
                    "Notes": m.get("Notes", ""),
                }
            )
    return n


def process_product(product: dict, manifest: dict[str, dict]) -> dict:
    msku = product["msku"]
    csv_cat = product["csv_category"]
    folder = folder_category(csv_cat)
    db_cat = db_category(csv_cat)
    base_name = sanitize_filename(product["name"])
    folder_path = OUT_DIR / folder

    # Always upsert product + stock first
    image_rel = ""
    existing = manifest.get(msku)
    if existing and existing.get("Image Status") in SUCCESS_STATUSES:
        rel = existing.get("Image Relative Path") or ""
        abs_path = ROOT / rel.replace("/", "\\") if rel else None
        if rel and (ROOT / Path(rel)).is_file():
            image_rel = rel.replace("\\", "/")
            upsert_product(
                {
                    "sku": msku,
                    "name": product["name"],
                    "description": product["full_description"],
                    "price": product["price"],
                    "stock": product["stock"],
                    "category": db_cat,
                    "image": image_rel,
                    "image_url": image_rel,
                }
            )
            row = dict(existing)
            row["Image Status"] = "ALREADY_EXISTS_VERIFIED"
            row["Notes"] = (row.get("Notes") or "") + "; re-verified on disk"
            return row

    upsert_product(
        {
            "sku": msku,
            "name": product["name"],
            "description": product["full_description"],
            "price": product["price"],
            "stock": product["stock"],
            "category": db_cat,
            "image": "",
            "image_url": "",
        }
    )

    # Skip image work if already verified file in expected folder
    for ext in ("jpg", "jpeg", "png", "webp"):
        for candidate in (
            folder_path / f"{base_name}.{ext}",
            folder_path / f"{base_name} - {msku}.{ext}",
        ):
            if candidate.is_file() and candidate.stat().st_size > 800:
                rel = candidate.relative_to(ROOT).as_posix()
                set_image(msku, rel, rel)
                return {
                    "MSKU": msku,
                    "Product Description": product["description"],
                    "Category": csv_cat,
                    "Image Filename": candidate.name,
                    "Image Relative Path": rel,
                    "Source URL": "",
                    "Source Type": "local",
                    "Match Method": "existing_file",
                    "Match Confidence": "HIGH",
                    "Image Status": "ALREADY_EXISTS_VERIFIED",
                    "File Size Bytes": str(candidate.stat().st_size),
                    "Notes": "existing category-folder image",
                }

    prod, method, conf, note = find_match(product)
    if prod is None:
        return {
            "MSKU": msku,
            "Product Description": product["description"],
            "Category": csv_cat,
            "Image Filename": "",
            "Image Relative Path": "",
            "Source URL": "",
            "Source Type": "",
            "Match Method": method or "none",
            "Match Confidence": "NONE",
            "Image Status": "NOT_FOUND",
            "File Size Bytes": "0",
            "Notes": note,
        }

    title = (prod.get("title") or "").strip()
    img_url = pick_image_url(prod)
    if conf == "LOW":
        return {
            "MSKU": msku,
            "Product Description": product["description"],
            "Category": csv_cat,
            "Image Filename": "",
            "Image Relative Path": "",
            "Source URL": img_url,
            "Source Type": "easypc",
            "Match Method": method,
            "Match Confidence": "LOW",
            "Image Status": "MATCH_UNCERTAIN",
            "File Size Bytes": "0",
            "Notes": f"title={title[:120]}; {note}",
        }

    dest = unique_target_path(folder_path, base_name, msku, "jpg")
    status, size, dnote = download_image(img_url, dest)
    # download_image may change extension
    final = dest
    if status == "DOWNLOADED":
        # find written file (ext may change)
        possibles = list(folder_path.glob(f"{dest.stem}.*"))
        if possibles:
            final = max(possibles, key=lambda p: p.stat().st_mtime)
        rel = final.relative_to(ROOT).as_posix()
        set_image(msku, rel, rel)
        return {
            "MSKU": msku,
            "Product Description": product["description"],
            "Category": csv_cat,
            "Image Filename": final.name,
            "Image Relative Path": rel,
            "Source URL": img_url,
            "Source Type": "easypc",
            "Match Method": method,
            "Match Confidence": conf,
            "Image Status": "DOWNLOADED",
            "File Size Bytes": str(size),
            "Notes": f"title={title[:120]}; {note}; {dnote}",
        }

    return {
        "MSKU": msku,
        "Product Description": product["description"],
        "Category": csv_cat,
        "Image Filename": "",
        "Image Relative Path": "",
        "Source URL": img_url,
        "Source Type": "easypc",
        "Match Method": method,
        "Match Confidence": conf,
        "Image Status": status,
        "File Size Bytes": "0",
        "Notes": f"title={title[:120]}; {note}; {dnote}",
    }


def main() -> int:
    products = load_csv()
    if len(products) != 261:
        print(f"STOP: CSV product count is {len(products)}, expected 261.")
        return 2

    print(f"CSV products: {len(products)}")
    print("Ensuring sku column...")
    print(php_run("ensure_sku"))

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    manifest = load_manifest()
    order = [p["msku"] for p in products]

    # Category order = first appearance in CSV
    cats = list(OrderedDict.fromkeys(p["csv_category"] for p in products))

    for cat in cats:
        cat_products = [p for p in products if p["csv_category"] == cat]
        print("\n" + "=" * 60)
        print(f"CATEGORY: {cat} ({len(cat_products)} products)")
        print("=" * 60)
        stats = Counter()
        for i, p in enumerate(cat_products, 1):
            print(f"  [{i}/{len(cat_products)}] {p['msku']} {p['name'][:70]}")
            try:
                row = process_product(p, manifest)
            except Exception as e:
                row = {
                    "MSKU": p["msku"],
                    "Product Description": p["description"],
                    "Category": cat,
                    "Image Filename": "",
                    "Image Relative Path": "",
                    "Source URL": "",
                    "Source Type": "",
                    "Match Method": "error",
                    "Match Confidence": "NONE",
                    "Image Status": "DOWNLOAD_FAILED",
                    "File Size Bytes": "0",
                    "Notes": str(e),
                }
                # still try to keep product imported
                try:
                    upsert_product(
                        {
                            "sku": p["msku"],
                            "name": p["name"],
                            "description": p["full_description"],
                            "price": p["price"],
                            "stock": p["stock"],
                            "category": db_category(cat),
                            "image": "",
                            "image_url": "",
                        }
                    )
                except Exception as e2:
                    row["Notes"] += f"; upsert_err={e2}"

            manifest[p["msku"]] = row
            stats[row["Image Status"]] += 1
            write_manifest(manifest, order)  # checkpoint after each product

        print("--- CATEGORY CHECKPOINT ---")
        print(f"  CSV products: {len(cat_products)}")
        print(f"  Completed: {len(cat_products)}")
        for k in (
            "DOWNLOADED",
            "ALREADY_EXISTS_VERIFIED",
            "MATCH_UNCERTAIN",
            "NOT_FOUND",
            "DOWNLOAD_FAILED",
            "INVALID_IMAGE",
            "DOWNLOAD_SKIPPED_SIZE_LIMIT",
        ):
            print(f"  {k}: {stats.get(k, 0)}")
        print(f"  Storage bytes: {dir_size(OUT_DIR)}")

    # Final manifests
    write_manifest(manifest, order)
    missing_n = write_no_image(products, manifest)

    print("\nValidating stock...")
    print(php_run("validate", str(CSV_PATH)))

    status_counts = Counter(m.get("Image Status") for m in manifest.values())
    print("\n===== FINAL SUMMARY =====")
    print(f"Total products in CSV: {len(products)}")
    print(f"DB count: {php_run('count')}")
    print(f"DOWNLOADED: {status_counts.get('DOWNLOADED', 0)}")
    print(f"ALREADY_EXISTS_VERIFIED: {status_counts.get('ALREADY_EXISTS_VERIFIED', 0)}")
    print(f"Without verified images: {missing_n}")
    for k in (
        "MATCH_UNCERTAIN",
        "NOT_FOUND",
        "DOWNLOAD_FAILED",
        "INVALID_IMAGE",
        "DOWNLOAD_SKIPPED_SIZE_LIMIT",
    ):
        print(f"{k}: {status_counts.get(k, 0)}")
    print(f"Total image storage: {dir_size(OUT_DIR)}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
