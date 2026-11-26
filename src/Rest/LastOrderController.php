<?php
/**
 * REST: load the current customer's most recent WooCommerce order lines.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Rest;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class LastOrderController
{
    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'pryviko/v1',
                    '/last-order',
                    [
                        'methods' => 'GET',
                        'permission_callback' => [self::class, 'permission_callback'],
                        'callback' => [self::class, 'last_order'],
                    ]
                );
            }
        );
    }

    /**
     * Logged-in customers only (cookie auth + X-WP-Nonce).
     */
    public static function permission_callback(): bool
    {
        return is_user_logged_in();
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function last_order(WP_REST_Request $request)
    {
        unset($request);

        $user_id = (int) get_current_user_id();
        if ($user_id <= 0) {
            return new WP_Error(
                'pryviko_auth',
                __('You must be logged in to load your last order.', 'pryviko-quick-order'),
                ['status' => 401]
            );
        }

        if (!function_exists('wc_get_orders')) {
            return new WP_Error(
                'pryviko_no_wc',
                __('WooCommerce is not available.', 'pryviko-quick-order'),
                ['status' => 500]
            );
        }

        $orders = wc_get_orders(
            [
                'customer_id' => $user_id,
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses()),
                'return' => 'objects',
            ]
        );

        if (!is_array($orders) || $orders === []) {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('No previous orders found.', 'pryviko-quick-order'),
                    'lines' => [],
                ],
                200
            );
        }

        $order = $orders[0];
        if (!is_object($order) || !method_exists($order, 'get_items')) {
            return new WP_Error(
                'pryviko_order',
                __('Could not read the last order.', 'pryviko-quick-order'),
                ['status' => 500]
            );
        }

        // Refuse trashed orders even if status filter slips.
        if (method_exists($order, 'get_status') && $order->get_status() === 'trash') {
            return new WP_REST_Response(
                [
                    'ok' => false,
                    'message' => __('No previous orders found.', 'pryviko-quick-order'),
                    'lines' => [],
                ],
                200
            );
        }

        // Isolation: never return another customer's order.
        $order_customer = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($order_customer !== $user_id) {
            return new WP_Error(
                'pryviko_forbidden',
                __('Not allowed.', 'pryviko-quick-order'),
                ['status' => 403]
            );
        }

        $lines = [];
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $product = $item->get_product();
            if (!$product || !method_exists($product, 'get_sku')) {
                continue;
            }
            $sku = (string) $product->get_sku();
            if ($sku === '') {
                continue;
            }
            $qty = method_exists($item, 'get_quantity') ? max(1, (int) $item->get_quantity()) : 1;
            $lines[] = [
                'sku' => $sku,
                'qty' => $qty,
            ];
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
                'lines' => $lines,
            ],
            200
        );
    }
}
