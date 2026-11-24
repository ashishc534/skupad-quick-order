# Pryviko – Quick Order & Paste-SKU Pad for WooCommerce

B2B and wholesale WooCommerce buyers with SKU lists waste time adding products one by one; paid quick-order plugins cost $49–$79/yr. Pryviko is a lightweight, GPL-licensed paste pad that validates SKUs against your catalog and adds them to the cart in one step.

## Stack

- WordPress 6.6+
- WooCommerce 9.0+
- PHP 8.0+
- Vanilla JS + shortcode / dynamic block
- HPOS (`custom_order_tables`) + Cart/Checkout Blocks compatible
- License: GPL-2.0-or-later (no trialware)

## Pricing

- Free on WordPress.org: paste SKU + qty pad with live stock/price validation
- Optional Pro add-on: $29–$79 (CSV upload, saved lists, one-click reorder) via WooCommerce.com / Freemius / Dodo Payments

## Install (local / staging)

1. Copy this folder to `wp-content/plugins/pryviko-quick-order/`
2. Activate in WP Admin → Plugins
3. Add `[pryviko_quick_order]` to any page (or insert the **Pryviko Quick Order** block)
4. Paste lines like:

```text
SKU,qty
ABC-100,2
XYZ-200,1
```

## Main files

| File | Role |
|------|------|
| `pryviko-quick-order.php` | Plugin bootstrap + HPOS/Blocks compatibility |
| `src/Parser/LineParser.php` | CSV/TSV/space paste parser |
| `src/Catalog/SkuValidator.php` | Sanitize + strict public catalog resolve |
| `src/Rest/QuickOrderController.php` | REST `/validate` + `/quick-order` |
| `src/Plugin.php` | Shortcode, assets, block, admin |
| `assets/js/quick-order.js` | Storefront UI |
| `readme.txt` | WordPress.org readme |
| `LISTING.md` | Marketplace listing copy |

## REST

- `POST /wp-json/pryviko/v1/validate` — `{ "payload": "SKU,qty\\n..." }`
- `POST /wp-json/pryviko/v1/quick-order` — same + `add_to_cart: true`

Requires `X-WP-Nonce` (localized into the script as `PryvikoQuickOrder.nonce`).

Non-public products (draft, private, pending, catalog-hidden, or variations of non-public parents) return the same line payload as an unknown SKU — no name, price, stock, or product ID.

## Tests

```bash
python3 test_unit.py
# if php is installed:
php tests/test-line-parser.php
php tests/test-visibility-guard.php
```

## Compatibility notes

- Declares `FeaturesUtil::declare_compatibility` for `custom_order_tables` and `cart_checkout_blocks`
- Does not write custom order tables; cart add uses core WooCommerce APIs only
