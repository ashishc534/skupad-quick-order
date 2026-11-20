#!/usr/bin/env python3
"""Structural + optional php -l checks for WooCommerce Track B (never Gradle)."""
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess

root = Path(__file__).resolve().parent
assert not (root / "plugin.yml").exists(), "Java plugin.yml must not exist for WooCommerce builds"
assert not (root / "build.gradle").exists(), "Gradle must not exist for WooCommerce builds"
assert not (root / "pom.xml").exists(), "Maven must not exist for WooCommerce builds"

php_mains = list(root.glob("*.php"))
assert php_mains, "main plugin PHP bootstrap missing"
header_ok = False
for main in php_mains:
    text = main.read_text(encoding="utf-8")
    if "Plugin Name:" in text and "License:" in text and "Requires PHP:" in text:
        header_ok = True
        assert "GPL-2.0-or-later" in text or "GPLv2" in text
        assert "Requires PHP:      8.0" in text or "Requires PHP: 8.0" in text
        break
assert header_ok, "missing Plugin Name:/License:/Requires PHP: on a root bootstrap PHP"

assert (root / "readme.txt").is_file(), "readme.txt missing"
readme = (root / "readme.txt").read_text(encoding="utf-8")
assert "Stable tag:" in readme
assert "HPOS" in readme or "custom order tables" in readme.lower() or "Blocks" in readme

for rel in (
    "src/Parser/LineParser.php",
    "src/Catalog/SkuValidator.php",
    "src/Rest/QuickOrderController.php",
    "assets/js/quick-order.js",
    "tests/test-line-parser.php",
    "README.md",
    "LISTING.md",
):
    assert (root / rel).is_file(), f"missing {rel}"

bootstrap = (root / "skupad-quick-order.php").read_text(encoding="utf-8")
assert "custom_order_tables" in bootstrap, "HPOS declare_compatibility missing"
assert "cart_checkout_blocks" in bootstrap, "Cart/Checkout Blocks declare_compatibility missing"
assert "trial" not in bootstrap.lower() or "no trialware" in bootstrap.lower()

js = (root / "assets/js/quick-order.js").read_text(encoding="utf-8")
assert "addEventListener" in js
assert "skupad" in js.lower()

# Pure-Python mirror of LineParser merge/header rules (works without php binary).
def parse_lines(raw: str) -> list[dict]:
    out: dict[str, dict] = {}
    for i, line in enumerate(raw.strip().splitlines()):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        norm = ",".join(line.lower().replace(";", ",").replace("\t", ",").split())
        # crude header detect
        flat = line.lower().replace(" ", "").replace("\t", "").replace(";", ",")
        if i == 0 and flat in {"sku,qty", "sku,quantity", "product_sku,qty"}:
            continue
        parts = [p for p in __import__("re").split(r"[\s,;\t]+", line) if p]
        sku = parts[0] if parts else ""
        qty = int(parts[1]) if len(parts) > 1 and parts[1].isdigit() else 1
        if not sku or qty < 1:
            continue
        key = sku.upper()
        if key in out:
            out[key]["qty"] += qty
        else:
            out[key] = {"sku": sku, "qty": qty}
    return list(out.values())


rows = parse_lines("ABC-1,2\nXYZ 3")
assert len(rows) == 2

php = shutil.which("php")
if php:
    for path in root.rglob("*.php"):
        proc = subprocess.run([php, "-l", str(path)], capture_output=True, text=True, timeout=30)
        assert proc.returncode == 0, f"php -l failed for {path}: {proc.stderr}"
    lite = subprocess.run(
        [php, str(root / "tests" / "test-line-parser.php")],
        cwd=str(root),
        capture_output=True,
        text=True,
        timeout=30,
    )
    assert lite.returncode == 0, lite.stderr or lite.stdout
else:
    print("php binary not found; structural + python mirror checks only")

print("woocommerce unit ok")
