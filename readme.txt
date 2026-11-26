=== Pryviko – Quick Order & Paste-SKU Pad for WooCommerce ===
Contributors: ashishc534
Tags: woocommerce, quick order, sku, b2b, wholesale
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 9.0
WC tested up to: 11.3

Paste SKU + quantity lines (comma, tab, or space-separated) and add validated products to the WooCommerce cart. HPOS and Cart/Checkout Blocks ready.

== Description ==

B2B and wholesale buyers often arrive with a SKU list. Adding products one-by-one is slow, and many paid quick-order plugins charge $49–$79/year.

Pryviko is a lightweight paste pad:

* Paste SKU + quantity lines (comma, tab, or space-separated)
* Live validation against your catalog (SKU found, stock, price, purchasable)
* One-click add to cart
* Works with High-Performance Order Storage (HPOS / custom order tables)
* Compatible with Cart and Checkout Blocks (uses the standard WooCommerce cart session)
* Shortcode `[pryviko_quick_order]`
* GPL-2.0-or-later, no trialware — free paste pad stays fully unlocked

Optional Pro add-on (separate purchase, **$39/year**): file upload for SKU lists, saved order lists, and one-click reorder. Free paste features stay free.

== Installation ==

1. Upload the `pryviko-quick-order` folder to `/wp-content/plugins/` or install the zip via Plugins → Add New → Upload.
2. Activate **Pryviko – Quick Order & Paste-SKU Pad for WooCommerce**.
3. Ensure WooCommerce 9.0+ is active.
4. Create a page and add the shortcode `[pryviko_quick_order]`.
5. Open the page, paste SKUs, Validate, then Add to cart.

== Frequently Asked Questions ==

= Does this work with HPOS? =

Yes. The plugin declares compatibility with `custom_order_tables` and does not query legacy order tables.

= Does this work with Cart / Checkout Blocks? =

Yes. Lines are added via `WC()->cart->add_to_cart()`, which updates the shared cart session used by Blocks. The UI also fires `wc-blocks_added_to_cart` / fragment refresh events.

= Is there a free trial lock? =

No. The free paste-SKU workflow is fully unlocked under GPL-2.0-or-later. Pro features are a separate optional add-on.

= Can I require login for wholesale? =

Yes. Filter `pryviko_quick_order_can_use` to return true only for logged-in customers.

= Does Free include file upload? =

No. Free supports pasting SKU + quantity text into the pad. File upload is part of the optional Pro add-on.

== Screenshots ==

1. Storefront Quick Order table with SKU rows, qty, live stock and unit-price badges, subtotal, and Add All to Cart.
2. Paste SKUs tab with multi-line SKU, Qty input and valid/invalid validation feedback.
3. WordPress page shortcode [pryviko_quick_order] filling the WooCommerce cart from one quick-order submit.

== Changelog ==

= 1.0.2 =
* Security: REST validate and quick-order no longer disclose name, price, or stock for draft, private, pending, or catalog-hidden products (or variations of non-public parents). Same response as unknown SKU.
* Brand: Pryviko (slug `pryviko-quick-order`, text domain, REST `pryviko/v1`, shortcode `[pryviko_quick_order]`).
* Rate-limit REST endpoints (~30 requests/minute per IP or user) to slow SKU enumeration.
* Documented public REST `permission_callback` for Plugin Check.

= 1.0.1 =
* Fix REST nonce localization for block themes (logged-in Cookie check failed).
* Plugin Check: escape shortcode output at echo; add languages path.
* WordPress.org packaging: contributor/author ashishc534; free-tier paste wording (no .csv file-upload claim); Pro priced at $39/year; shortcode-first docs.

= 1.0.0 =
* Initial release: paste-line SKU parser, catalog validator, REST validate + quick-order, shortcode UI, HPOS and Cart/Checkout Blocks compatibility declarations.

== Upgrade Notice ==

= 1.0.2 =
Security: closes draft/private SKU information disclosure on REST endpoints. Plugin renamed to Pryviko; update shortcode to `[pryviko_quick_order]`.

= 1.0.1 =
Logged-in storefront Validate / Add to cart nonce fix; Plugin Check packaging; WordPress.org author/readme cleanup for ashishc534.

= 1.0.0 =
Initial public release.
