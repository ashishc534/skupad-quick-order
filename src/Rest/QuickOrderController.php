<?php
/**
 * REST API: validate SKU paste and add resolved lines to the WooCommerce cart.
 *
 * Works with classic cart and Cart/Checkout Blocks (uses WC()->cart).
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Rest;

use Pryviko\QuickOrder\Catalog\SkuValidator;
use Pryviko\QuickOrder\Parser\LineParser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class QuickOrderController
{
    /** Max REST requests per minute per IP/user (SKU enumeration guard). */
    private const RATE_LIMIT = 30;

    /** Transient TTL for the sliding minute window (seconds). */
    private const RATE_WINDOW = 60;

    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'pryviko/v1',
                    '/validate',
                    [
                        'methods' => 'POST',
                        'permission_callback' => [self::class, 'permission_callback'],
                        'callback' => [self::class, 'validate'],
                        'args' => [
                            'payload' => [
                                'type' => 'string',
                                'required' => true,
                            ],
                        ],
                    ]
                );

                register_rest_route(
                    'pryviko/v1',
                    '/quick-order',
                    [
                        'methods' => 'POST',
                        'permission_callback' => [self::class, 'permission_callback'],
                        'callback' => [self::class, 'quick_order'],
                        'args' => [
                            'payload' => [
                                'type' => 'string',
                                'required' => true,
                            ],
                            'add_to_cart' => [
                                'type' => 'boolean',
                                'default' => true,
                            ],
                            'po_number' => [
                                'type' => 'string',
                                'required' => false,
                                'default' => '',
                            ],
                        ],
                    ]
                );
            }
        );
    }

    /**
     * Public storefront callback (PCP-safe): any visitor may validate / add.
     *
     * Filter `pryviko_quick_order_can_use` to require login for wholesale stores.
     */
    public static function permission_callback(): bool
    {
        /**
         * @param bool $allowed Whether the current request may use Pryviko REST.
         */
        return (bool) apply_filters('pryviko_quick_order_can_use', true);
    }

    /**
     * @return true|WP_Error
     */
    public static function enforce_rate_limit()
    {
        $key = self::rate_limit_key();
        $count = (int) get_transient($key);
        if ($count >= self::RATE_LIMIT) {
            return new WP_Error(
                'pryviko_rate_limited',
                __('Too many requests. Please wait a minute and try again.', 'pryviko-quick-order'),
                ['status' => 429]
            );
        }
        set_transient($key, $count + 1, self::RATE_WINDOW);
        return true;
    }

    private static function rate_limit_key(): string
    {
        $user_id = function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
        if ($user_id > 0) {
            return 'pryviko_rl_u_' . $user_id;
        }
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
        }
        if ($ip === '') {
            $ip = 'unknown';
        }
        return 'pryviko_rl_ip_' . md5($ip);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function validate(WP_REST_Request $request)
    {
        $limited = self::enforce_rate_limit();
        if (is_wp_error($limited)) {
            return $limited;
        }

        $raw = (string) $request->get_param('payload');
        $parsed = LineParser::parse($raw);
        $lines = SkuValidator::resolveCatalog($parsed);

        return new WP_REST_Response(
            [
                'ok' => true,
                'count' => count($lines),
                'valid' => count(array_filter($lines, static fn (array $l): bool => !empty($l['ok']))),
                'lines' => $lines,
            ],
            200
        );
    }

    /**
     * Validate paste payload and optionally add valid lines to the cart.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function quick_order(WP_REST_Request $request)
    {
        $limited = self::enforce_rate_limit();
        if (is_wp_error($limited)) {
            return $limited;
        }

        $raw = (string) $request->get_param('payload');
        $add = filter_var($request->get_param('add_to_cart'), FILTER_VALIDATE_BOOLEAN);
        $po_number = sanitize_text_field((string) $request->get_param('po_number'));

        $parsed = LineParser::parse($raw);
        if ($parsed === []) {
            return new WP_Error(
                'pryviko_empty',
                __('No valid SKU lines found.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $lines = SkuValidator::resolveCatalog($parsed);
        $added = [];
        $failed = [];

        if ($add) {
            if (!function_exists('WC')) {
                return new WP_Error(
                    'pryviko_no_cart',
                    __('WooCommerce cart is not available.', 'pryviko-quick-order'),
                    ['status' => 500]
                );
            }

            if (is_null(WC()->cart) && function_exists('wc_load_cart')) {
                wc_load_cart();
            }

            if (!WC()->cart) {
                return new WP_Error(
                    'pryviko_no_cart',
                    __('WooCommerce cart is not available.', 'pryviko-quick-order'),
                    ['status' => 500]
                );
            }

            if (WC()->session && !WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }

            if (SkuValidator::isPro() && $po_number !== '' && WC()->session) {
                WC()->session->set('pryviko_po_number', $po_number);
            }

            foreach ($lines as $line) {
                if (empty($line['ok']) || (int) $line['product_id'] <= 0) {
                    $failed[] = $line;
                    continue;
                }

                $cart_item_data = [];
                if (SkuValidator::isPro() && !empty($line['note'])) {
                    $cart_item_data['pryviko_line_note'] = (string) $line['note'];
                }

                $key = WC()->cart->add_to_cart(
                    (int) $line['product_id'],
                    (int) $line['qty'],
                    0,
                    [],
                    $cart_item_data
                );
                if ($key) {
                    $line['cart_key'] = $key;
                    $added[] = $line;
                } else {
                    $line['ok'] = false;
                    $line['message'] = 'Could not add to cart.';
                    $failed[] = $line;
                }
            }
        } else {
            foreach ($lines as $line) {
                if (!empty($line['ok'])) {
                    $added[] = $line;
                } else {
                    $failed[] = $line;
                }
            }
        }

        return new WP_REST_Response(
            [
                'ok' => $failed === [],
                'added' => $added,
                'failed' => $failed,
                'cart_count' => (function_exists('WC') && WC()->cart) ? WC()->cart->get_cart_contents_count() : 0,
                'cart_url' => function_exists('wc_get_cart_url') ? wc_get_cart_url() : '',
            ],
            200
        );
    }
}
