<?php
/**
 * REST API: validate SKU paste and add resolved lines to the WooCommerce cart.
 *
 * Works with classic cart and Cart/Checkout Blocks (uses WC()->cart).
 *
 * @package SkuPad
 */

declare(strict_types=1);

namespace OpenJarvis\SkuPad\Rest;

use OpenJarvis\SkuPad\Catalog\SkuValidator;
use OpenJarvis\SkuPad\Parser\LineParser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class QuickOrderController
{
    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'skupad/v1',
                    '/validate',
                    [
                        'methods' => 'POST',
                        'permission_callback' => [self::class, 'can_use'],
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
                    'skupad/v1',
                    '/quick-order',
                    [
                        'methods' => 'POST',
                        'permission_callback' => [self::class, 'can_use'],
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
                        ],
                    ]
                );
            }
        );
    }

    /**
     * Storefront-safe: any visitor with a valid REST nonce may validate / add.
     * Filter `skupad_quick_order_can_use` to require login for wholesale stores.
     */
    public static function can_use(): bool
    {
        $allowed = true;
        /**
         * @param bool $allowed
         */
        return (bool) apply_filters('skupad_quick_order_can_use', $allowed);
    }

    public static function validate(WP_REST_Request $request): WP_REST_Response
    {
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
        $raw = (string) $request->get_param('payload');
        $add = filter_var($request->get_param('add_to_cart'), FILTER_VALIDATE_BOOLEAN);

        $parsed = LineParser::parse($raw);
        if ($parsed === []) {
            return new WP_Error(
                'skupad_empty',
                __('No valid SKU lines found.', 'skupad-quick-order'),
                ['status' => 400]
            );
        }

        $lines = SkuValidator::resolveCatalog($parsed);
        $added = [];
        $failed = [];

        if ($add) {
            if (!function_exists('WC')) {
                return new WP_Error(
                    'skupad_no_cart',
                    __('WooCommerce cart is not available.', 'skupad-quick-order'),
                    ['status' => 500]
                );
            }

            // REST requests often skip frontend cart bootstrap — load it explicitly.
            if (is_null(WC()->cart) && function_exists('wc_load_cart')) {
                wc_load_cart();
            }

            if (!WC()->cart) {
                return new WP_Error(
                    'skupad_no_cart',
                    __('WooCommerce cart is not available.', 'skupad-quick-order'),
                    ['status' => 500]
                );
            }

            // Ensure session exists for guests (Blocks + classic).
            if (WC()->session && !WC()->session->has_session()) {
                WC()->session->set_customer_session_cookie(true);
            }

            foreach ($lines as $line) {
                if (empty($line['ok']) || (int) $line['product_id'] <= 0) {
                    $failed[] = $line;
                    continue;
                }
                $key = WC()->cart->add_to_cart((int) $line['product_id'], (int) $line['qty']);
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
