#!/usr/bin/env python3
"""
Compress oversized product images in place (same path / association).
Does not invent images. Updates DB image path only if extension must change PNG->JPG.
"""
from __future__ import annotations

import io
import json
import subprocess
from pathlib import Path

from PIL import Image

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "assets" / "products"
MAX_EDGE = 900
MAX_BYTES_TARGET = 280 * 1024
MIN_COMPRESS_BYTES = 350 * 1024


def php_dump_images() -> dict[str, str]:
    script = ROOT / "scripts" / "_dump_images.php"
    script.write_text(
        """<?php
require_once dirname(__DIR__) . '/backend/config/database.php';
$db = getDbConnection();
$rows = $db->query("SELECT sku, image FROM products WHERE image IS NOT NULL AND BTRIM(image) <> ''")->fetchAll(PDO::FETCH_ASSOC);
$out = [];
foreach ($rows as $r) { $out[(string)$r['sku']] = str_replace('\\\\', '/', (string)$r['image']); }
echo json_encode($out);
""",
        encoding="utf-8",
    )
    out = subprocess.check_output(["php", str(script)], cwd=str(ROOT), text=True)
    script.unlink(missing_ok=True)
    return json.loads(out)


def php_set_image(sku: str, image: str) -> None:
    tmp = ROOT / "scripts" / "_tmp_product.json"
    tmp.write_text(json.dumps({"sku": sku, "image": image, "image_url": image}), encoding="utf-8")
    subprocess.check_output(
        ["php", str(ROOT / "scripts" / "catalog_db.php"), "set_image", str(tmp)],
        cwd=str(ROOT),
    )


def compress_file(path: Path) -> tuple[Path, int, int]:
    before = path.stat().st_size
    im = Image.open(path)
    im.load()
    w, h = im.size
    scale = min(1.0, MAX_EDGE / max(w, h))
    if scale < 1.0:
        im = im.resize((max(1, int(w * scale)), max(1, int(h * scale))), Image.Resampling.LANCZOS)

    has_alpha = im.mode in ("RGBA", "LA") or (im.mode == "P" and "transparency" in im.info)
    ext = path.suffix.lower()

    # Prefer JPEG for opaque photos (much smaller); keep PNG only if transparency needed
    if has_alpha:
        if im.mode != "RGBA":
            im = im.convert("RGBA")
        dest = path.with_suffix(".png")
        buf = io.BytesIO()
        im.save(buf, format="PNG", optimize=True)
        data = buf.getvalue()
        # if still huge, flatten to JPEG on white
        if len(data) > MAX_BYTES_TARGET * 2:
            bg = Image.new("RGB", im.size, (255, 255, 255))
            bg.paste(im, mask=im.split()[-1] if im.mode == "RGBA" else None)
            dest = path.with_suffix(".jpg")
            buf = io.BytesIO()
            bg.save(buf, format="JPEG", quality=82, optimize=True)
            data = buf.getvalue()
    else:
        if im.mode != "RGB":
            im = im.convert("RGB")
        dest = path.with_suffix(".jpg") if ext in (".png", ".webp") else path
        quality = 85
        data = None
        while quality >= 60:
            buf = io.BytesIO()
            im.save(buf, format="JPEG", quality=quality, optimize=True)
            data = buf.getvalue()
            if len(data) <= MAX_BYTES_TARGET or quality <= 60:
                break
            quality -= 5

    if dest != path and path.exists():
        # write new then remove old
        dest.write_bytes(data)
        if dest.resolve() != path.resolve():
            path.unlink(missing_ok=True)
    else:
        path.write_bytes(data)
        dest = path

    after = dest.stat().st_size
    return dest, before, after


def main() -> None:
    mapping = php_dump_images()  # sku -> relative path
    by_path: dict[str, list[str]] = {}
    for sku, rel in mapping.items():
        by_path.setdefault(rel.replace("\\", "/"), []).append(sku)

    files = [f for f in OUT.rglob("*") if f.is_file() and f.stat().st_size >= MIN_COMPRESS_BYTES]
    print(f"candidates {len(files)}")
    total_before = sum(f.stat().st_size for f in OUT.rglob("*") if f.is_file())

    changed = 0
    for path in files:
        rel = path.relative_to(ROOT).as_posix()
        try:
            new_path, before, after = compress_file(path)
        except Exception as e:
            print("FAIL", rel, e)
            continue
        new_rel = new_path.relative_to(ROOT).as_posix()
        print(f"{rel} {before//1024}KB -> {new_rel} {after//1024}KB")
        if new_rel != rel:
            for sku in by_path.get(rel, []):
                php_set_image(sku, new_rel)
                # also update manifest if present
        changed += 1

    # update manifest paths for any changed files
    man = ROOT / "product_image_manifest.csv"
    if man.exists() and changed:
        import csv

        rows = list(csv.DictReader(man.open(encoding="utf-8")))
        fields = list(rows[0].keys()) if rows else []
        path_by_sku = php_dump_images()
        for r in rows:
            sku = r.get("MSKU", "")
            if sku in path_by_sku and r.get("Image Status") in ("DOWNLOADED", "ALREADY_EXISTS_VERIFIED"):
                r["Image Relative Path"] = path_by_sku[sku]
                r["Image Filename"] = Path(path_by_sku[sku]).name
                try:
                    r["File Size Bytes"] = str((ROOT / path_by_sku[sku]).stat().st_size)
                except OSError:
                    pass
        with man.open("w", encoding="utf-8", newline="") as f:
            w = csv.DictWriter(f, fieldnames=fields)
            w.writeheader()
            w.writerows(rows)

    total_after = sum(f.stat().st_size for f in OUT.rglob("*") if f.is_file())
    print("changed_files", changed)
    print("total_before", total_before, "total_after", total_after)


if __name__ == "__main__":
    main()
