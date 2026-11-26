<?php
/**
 * Sanitize SKU rows and (when WooCommerce is loaded) resolve catalog truth.
 *
 * Strict visibility: only published, catalog-visible products (and visible
 * published parents for variations) may leak name/price/stock via REST.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Catalog;

final class SkuValidator
{
    public const NOT_FOUND_MESSAGE = 'SKU not found';

    /** Test override: null = ask Freemius / filter; bool forces Pro mode. */
    private static ?bool $proOverride = null;

    public static function setProOverride(?bool $value): void
    {
        self::$proOverride = $value;
    }

    public static function isPro(): bool
    {
        if (self::$proOverride !== null) {
            return self::$proOverride;
        }

        if (function_exists('apply_filters')) {
            /**
             * @param bool|null $is_pro Whether Pro features are active.
             */
            $filtered = apply_filters('pryviko_is_pro', null);
            if (is_bool($filtered)) {
                return $filtered;
            }
        }

        return function_exists('pqo_fs')
            && function_exists('fs_dynamic_init')
            && pqo_fs()->is__premium_only()
            && pqo_fs()->can_use_premium_code();
    }

    /**
     * Sanitize SKU characters and clamp qty. Does not hit the catalog.
     *
     * @param list<array{sku:string,qty:int,note?:string}> $rows
     * @return list<array{sku:string,qty:int,note?:string}>
     */
    public static function validate(array $rows): array
    {
        $ok = [];
        foreach ($rows as $row) {
            $sku = self::sanitizeSku((string) ($row['sku'] ?? ''));
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($sku === '') {
                continue;
            }
            $item = [
                'sku' => $sku,
                'qty' => $qty,
            ];
            if (!empty($row['note'])) {
                $note = (string) $row['note'];
                $item['note'] = function_exists('sanitize_text_field')
                    ? sanitize_text_field($note)
                    : trim(strip_tags($note));
            }
            $ok[] = $item;
        }
        return $ok;
    }

    /**
     * Empty failure row identical for unknown SKU and non-public products.
     *
     * @return array{
     *   sku:string,
     *   qty:int,
     *   ok:bool,
     *   product_id:int,
     *   name:string,
     *   price:string,
     *   stock_status:string,
     *   message:string
     * }
     */
    public static function notFoundRow(string $sku, int $qty): array
    {
        return [
            'sku' => $sku,
            'qty' => $qty,
            'ok' => false,
            'product_id' => 0,
            'name' => '',
            'price' => '',
            'stock_status' => '',
            'message' => self::NOT_FOUND_MESSAGE,
        ];
    }

    /**
     * Resolve a SKU to a public, catalog-visible WC_Product — or null.
     *
     * Rejects draft/private/pending/future/trash and catalog-hidden products.
     * Variations also require a published, visible parent.
     */
    public static function resolvePublicProductBySku(string $sku): ?\WC_Product
    {
        if (!function_exists('wc_get_product_id_by_sku') || !function_exists('wc_get_product')) {
            return null;
        }

        $product_id = (int) wc_get_product_id_by_sku($sku);
        if ($product_id <= 0) {
            return null;
        }

        $product = wc_get_product($product_id);
        if (!self::isPubliclyResolvable($product)) {
            return null;
        }

        return $product;
    }

    /**
     * True only when the product may appear in REST responses.
     *
     * @param mixed $product
     */
    public static function isPubliclyResolvable($product): bool
    {
        if (!$product instanceof \WC_Product) {
            return false;
        }

        if ($product->get_status() !== 'publish' || !$product->is_visible()) {
            return false;
        }

        if ($product->is_type('variation')) {
            $parent_id = (int) $product->get_parent_id();
            if ($parent_id <= 0 || !function_exists('wc_get_product')) {
                return false;
            }
            $parent = wc_get_product($parent_id);
            if (!$parent instanceof \WC_Product) {
                return false;
            }
            // Parent must be publish + catalog-visible (not hidden).
            if ($parent->get_status() !== 'publish' || !$parent->is_visible()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve each SKU against the WooCommerce catalog (stock, price, purchasable).
     * Non-public products are indistinguishable from unknown SKUs.
     * When Pro: variation names include attribute labels (SKU — Large / Blue).
     *
     * @param list<array{sku:string,qty:int,note?:string}> $rows
     * @return list<array{
     *   sku:string,
     *   qty:int,
     *   ok:bool,
     *   product_id:int,
     *   name:string,
     *   price:string,
     *   stock_status:string,
     *   message:string,
     *   note?:string
     * }>
     */
    public static function resolveCatalog(array $rows): array
    {
        $rows = self::validate($rows);
        $results = [];
        $is_pro = self::isPro();

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $qty = $row['qty'];

            if (!function_exists('wc_get_product_id_by_sku')) {
                $base = self::notFoundRow($sku, $qty);
                $base['message'] = 'WooCommerce is not available.';
                $results[] = $base;
                continue;
            }

            $product = self::resolvePublicProductBySku($sku);
            if (!$product instanceof \WC_Product) {
                // Unknown SKU, draft/private/pending/hidden, or variation of non-public parent.
                $results[] = self::notFoundRow($sku, $qty);
                continue;
            }

            $name = (string) $product->get_name();
            if ($is_pro && $product->is_type('variation')) {
                $name = self::formatVariationLabel($sku, $product);
            }

            $base = [
                'sku' => $sku,
                'qty' => $qty,
                'ok' => false,
                'product_id' => (int) $product->get_id(),
                'name' => $name,
                'price' => (string) $product->get_price(),
                'stock_status' => (string) $product->get_stock_status(),
                'message' => '',
            ];
            if (!empty($row['note'])) {
                $base['note'] = (string) $row['note'];
            }

            if (!$product->is_purchasable()) {
                $base['message'] = 'Product is not purchasable.';
                $results[] = $base;
                continue;
            }

            if (!$product->is_in_stock()) {
                $base['message'] = 'Out of stock.';
                $results[] = $base;
                continue;
            }

            if ($product->managing_stock()) {
                $available = $product->get_stock_quantity();
                if ($available !== null && $qty > (int) $available) {
                    $base['message'] = sprintf('Only %d in stock.', (int) $available);
                    $results[] = $base;
                    continue;
                }
            }

            $base['ok'] = true;
            $base['message'] = 'OK';
            $results[] = $base;
        }

        return $results;
    }

    /**
     * Pro: "SKU-1042 — Large / Blue"
     */
    public static function formatVariationLabel(string $sku, \WC_Product $product): string
    {
        $labels = [];
        if (method_exists($product, 'get_variation_attributes')) {
            /** @var array<string,string> $attrs */
            $attrs = (array) $product->get_variation_attributes();
            foreach ($attrs as $value) {
                $value = trim((string) $value);
                if ($value === '' || $value === 'any') {
                    continue;
                }
                $labels[] = ucwords(str_replace(['-', '_'], ' ', $value));
            }
        }

        if ($labels === []) {
            return $sku . ' — ' . (string) $product->get_name();
        }

        return $sku . ' — ' . implode(' / ', $labels);
    }

    public static function sanitizeSku(string $sku): string
    {
        $sku = trim($sku);
        $clean = preg_replace('/[^A-Za-z0-9._\-\/]/', '', $sku);
        return is_string($clean) ? $clean : '';
    }
}
