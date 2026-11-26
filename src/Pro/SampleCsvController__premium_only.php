<?php
/**
 * Pro: GET /pryviko/v1/sample-csv — downloadable sample using live store SKUs.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Pro;

use Pryviko\QuickOrder\Catalog\SkuValidator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SampleCsvController__premium_only
{
    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'pryviko/v1',
                    '/sample-csv',
                    [
                        'methods' => 'GET',
                        'permission_callback' => static function (): bool {
                            return (bool) apply_filters('pryviko_quick_order_can_use', true);
                        },
                        'callback' => [self::class, 'sample_csv'],
                    ]
                );
            }
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function sample_csv(WP_REST_Request $request)
    {
        unset($request);

        $rows = self::pick_sample_rows();
        $csv = "sku,qty,note\n";
        foreach ($rows as $row) {
            $csv .= sprintf(
                "%s,%d,%s\n",
                self::csvEscape((string) $row['sku']),
                (int) $row['qty'],
                self::csvEscape((string) ($row['note'] ?? ''))
            );
        }

        $response = new WP_REST_Response($csv, 200);
        $response->header('Content-Type', 'text/csv; charset=utf-8');
        $response->header('Content-Disposition', 'attachment; filename="pryviko-sample-order.csv"');
        return $response;
    }

    /**
     * Up to 3 publish + catalog-visible products with non-empty SKUs.
     *
     * @return list<array{sku:string,qty:int,note?:string}>
     */
    public static function pick_sample_rows(): array
    {
        $rows = [];

        if (function_exists('wc_get_products')) {
            $products = wc_get_products(
                [
                    'status' => 'publish',
                    'limit' => 20,
                    'orderby' => 'date',
                    'order' => 'DESC',
                    'return' => 'objects',
                ]
            );

            if (is_array($products)) {
                foreach ($products as $product) {
                    if (!$product instanceof \WC_Product) {
                        continue;
                    }
                    if (!SkuValidator::isPubliclyResolvable($product)) {
                        continue;
                    }
                    $sku = (string) $product->get_sku();
                    if ($sku === '') {
                        continue;
                    }
                    $rows[] = [
                        'sku' => $sku,
                        'qty' => 1,
                        'note' => '',
                    ];
                    if (count($rows) >= 3) {
                        break;
                    }
                }
            }
        }

        if ($rows === []) {
            return [
                ['sku' => 'SAMPLE-SKU-100', 'qty' => 2, 'note' => 'example'],
                ['sku' => 'SAMPLE-SKU-200', 'qty' => 1, 'note' => ''],
                ['sku' => 'SAMPLE-SKU-300', 'qty' => 5, 'note' => 'rush'],
            ];
        }

        return $rows;
    }

    private static function csvEscape(string $value): string
    {
        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            return '"' . str_replace('"', '""', $value) . '"';
        }
        return $value;
    }
}
