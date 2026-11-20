<?php
/**
 * Sanitize SKU rows and (when WooCommerce is loaded) resolve catalog truth.
 *
 * @package SkuPad
 */

declare(strict_types=1);

namespace OpenJarvis\SkuPad\Catalog;

final class SkuValidator
{
    /**
     * Sanitize SKU characters and clamp qty. Does not hit the catalog.
     *
     * @param list<array{sku:string,qty:int}> $rows
     * @return list<array{sku:string,qty:int}>
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
            $ok[] = [
                'sku' => $sku,
                'qty' => $qty,
            ];
        }
        return $ok;
    }

    /**
     * Resolve each SKU against the WooCommerce catalog (stock, price, purchasable).
     *
     * @param list<array{sku:string,qty:int}> $rows
     * @return list<array{
     *   sku:string,
     *   qty:int,
     *   ok:bool,
     *   product_id:int,
     *   name:string,
     *   price:string,
     *   stock_status:string,
     *   message:string
     * }>
     */
    public static function resolveCatalog(array $rows): array
    {
        $rows = self::validate($rows);
        $results = [];

        foreach ($rows as $row) {
            $sku = $row['sku'];
            $qty = $row['qty'];
            $base = [
                'sku' => $sku,
                'qty' => $qty,
                'ok' => false,
                'product_id' => 0,
                'name' => '',
                'price' => '',
                'stock_status' => '',
                'message' => '',
            ];

            if (!function_exists('wc_get_product_id_by_sku')) {
                $base['message'] = 'WooCommerce is not available.';
                $results[] = $base;
                continue;
            }

            $product_id = (int) wc_get_product_id_by_sku($sku);
            if ($product_id <= 0) {
                $base['message'] = 'SKU not found.';
                $results[] = $base;
                continue;
            }

            $product = wc_get_product($product_id);
            if (!$product instanceof \WC_Product) {
                $base['message'] = 'Product could not be loaded.';
                $results[] = $base;
                continue;
            }

            $base['product_id'] = $product_id;
            $base['name'] = $product->get_name();
            $base['price'] = $product->get_price();
            $base['stock_status'] = $product->get_stock_status();

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

    public static function sanitizeSku(string $sku): string
    {
        $sku = trim($sku);
        $clean = preg_replace('/[^A-Za-z0-9._\-\/]/', '', $sku);
        return is_string($clean) ? $clean : '';
    }
}
