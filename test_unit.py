#!/usr/bin/env python3
"""Structural + optional php -l checks for WooCommerce Track B (never Gradle)."""
from __future__ import annotations

from pathlib import Path
import re
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
        assert "1.1.0" in text, "bootstrap version must be 1.1.0"
        assert "pryviko-quick-order" in text, "text domain / slug missing"
        assert "Pryviko" in text, "display brand missing"
        break
assert header_ok, "missing Plugin Name:/License:/Requires PHP: on a root bootstrap PHP"

assert (root / "pryviko-quick-order.php").is_file(), "pryviko-quick-order.php missing"
assert not (root / "skupad-quick-order.php").exists(), "legacy bootstrap must be removed"

assert (root / "readme.txt").is_file(), "readme.txt missing"
readme = (root / "readme.txt").read_text(encoding="utf-8")
assert "Stable tag: 1.1.0" in readme
assert "Shareable Order Links" in readme or "shareable" in readme.lower()
assert "Smart Paste" in readme or "WhatsApp" in readme
assert "Load Last Order" in readme
assert "Sample CSV" in readme
assert "Requires at least:" in readme
assert "Tested up to:" in readme
assert "Requires PHP:" in readme
assert "License: GPLv2 or later" in readme
assert "HPOS" in readme or "custom order tables" in readme.lower() or "Blocks" in readme
assert "Pryviko" in readme
assert not re.search(r"(?i)skupad", readme), "readme must not contain skupad"

for rel in (
    "src/Parser/LineParser.php",
    "src/Catalog/SkuValidator.php",
    "src/Rest/QuickOrderController.php",
    "assets/js/quick-order.js",
    "tests/test-line-parser.php",
    "tests/test-visibility-guard.php",
    "README.md",
    "LISTING.md",
):
    assert (root / rel).is_file(), f"missing {rel}"

bootstrap = (root / "pryviko-quick-order.php").read_text(encoding="utf-8")
assert "custom_order_tables" in bootstrap, "HPOS declare_compatibility missing"
assert "cart_checkout_blocks" in bootstrap, "Cart/Checkout Blocks declare_compatibility missing"
assert "PRYVIKO_QUICK_ORDER_VERSION" in bootstrap
assert "Pryviko\\QuickOrder\\" in bootstrap or "Pryviko\\\\QuickOrder\\\\" in bootstrap.replace(
    "\\\\", "\\"
)

js = (root / "assets/js/quick-order.js").read_text(encoding="utf-8")
assert "addEventListener" in js
assert "pryviko" in js.lower()
assert "PryvikoQuickOrder" in js
assert "pryviko/v1" in js

# Zero leftover skupad in shipped plugin files (exclude tests / this script checks).
shipped_globs = [
    "pryviko-quick-order.php",
    "readme.txt",
    "LICENSE",
    "src/**/*.php",
    "assets/**/*",
    "languages/**/*",
]
leftover = []
for pattern in shipped_globs:
    for path in root.glob(pattern):
        if not path.is_file():
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        if re.search(r"(?i)skupad", text):
            leftover.append(str(path.relative_to(root)))
assert not leftover, f"skupad leftover in shipped files: {leftover}"

controller = (root / "src/Rest/QuickOrderController.php").read_text(encoding="utf-8")
assert "pryviko/v1" in controller
assert "permission_callback" in controller
assert "enforce_rate_limit" in controller or "RATE_LIMIT" in controller

validator = (root / "src/Catalog/SkuValidator.php").read_text(encoding="utf-8")
assert "resolvePublicProductBySku" in validator
assert "isPubliclyResolvable" in validator
assert "is_visible()" in validator
assert "get_status()" in validator

# Pure-Python mirror of LineParser merge/header rules (works without php binary).
def parse_lines(raw: str) -> list[dict]:
    out: dict[str, dict] = {}
    for i, line in enumerate(raw.strip().splitlines()):
        line = line.strip()
        if not line or line.startswith("#"):
            continue
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
    for script in ("tests/test-line-parser.php", "tests/test-visibility-guard.php"):
        lite = subprocess.run(
            [php, str(root / script)],
            cwd=str(root),
            capture_output=True,
            text=True,
            timeout=30,
        )
        assert lite.returncode == 0, lite.stderr or lite.stdout
else:
    print("php binary not found; structural + python mirror checks only")

print("woocommerce unit ok")
