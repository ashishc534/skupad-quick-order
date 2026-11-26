<?php
/**
 * Plugin Name:       Pryviko – Quick Order & Paste-SKU Pad for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/pryviko-quick-order/
 * Description:       Paste SKU + quantity lines (comma, tab, or space-separated) and add validated lines to the WooCommerce cart in one step. Built for B2B and wholesale buyers. HPOS and Cart/Checkout Blocks compatible.
 * Version:           1.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            ashishc534
 * Author URI:        https://profiles.wordpress.org/ashishc534/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pryviko-quick-order
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3
 *
 * @package Pryviko
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (function_exists('pqo_fs')) {
    pqo_fs()->set_basename(true, __FILE__);
} else {
    /**
     * Freemius helper. DO NOT REMOVE this if — required for free/paid auto-deactivation.
     */
    if (!function_exists('pqo_fs')) {
        /**
         * @return Freemius
         */
        function pqo_fs()
        {
            global $pqo_fs;

            if (!isset($pqo_fs)) {
                $autoload = dirname(__FILE__) . '/vendor/autoload.php';
                if (file_exists($autoload)) {
                    require_once $autoload;
                }

                $pqo_fs = fs_dynamic_init(
                    [
                        'id'                  => '46450',
                        'slug'                => 'pryviko-quick-order',
                        'type'                => 'plugin',
                        'public_key'          => 'pk_0d021b7398a2d90e903d4c4c8aed5',
                        'is_premium'          => true,
                        'premium_suffix'      => 'Pro',
                        'has_premium_version' => true,
                        'has_addons'          => false,
                        'has_paid_plans'      => true,
                        'is_org_compliant'    => true,
                        'wp_org_gatekeeper'   => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
                        'trial'               => [
                            'days'               => 14,
                            'is_require_payment' => false,
                        ],
                        'menu'                => [
                            'first-path' => 'plugins.php',
                            'support'    => false,
                        ],
                    ]
                );
            }

            return $pqo_fs;
        }

        pqo_fs();
        do_action('pqo_fs_loaded');
    }

    define('PRYVIKO_QUICK_ORDER_VERSION', '1.1.0');
    define('PRYVIKO_QUICK_ORDER_FILE', __FILE__);
    define('PRYVIKO_QUICK_ORDER_DIR', plugin_dir_path(__FILE__));
    define('PRYVIKO_QUICK_ORDER_URL', plugin_dir_url(__FILE__));

    require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Parser/LineParser.php';
    require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Catalog/SkuValidator.php';
    require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Rest/QuickOrderController.php';
    require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Rest/LastOrderController.php';
    require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Plugin.php';

    if (
        function_exists('pqo_fs')
        && pqo_fs()->is__premium_only()
        && pqo_fs()->can_use_premium_code()
    ) {
        require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Pro/ProBootstrap__premium_only.php';
        require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Pro/CsvUploadController__premium_only.php';
        require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Pro/SavedListsController__premium_only.php';
        require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Pro/OrderNotesHandler__premium_only.php';
        require_once PRYVIKO_QUICK_ORDER_DIR . 'src/Pro/SampleCsvController__premium_only.php';
    }

    /**
     * Declare WooCommerce feature compatibility (HPOS + Cart/Checkout Blocks).
     */
    add_action(
        'before_woocommerce_init',
        static function (): void {
            if (!class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
                return;
            }
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                PRYVIKO_QUICK_ORDER_FILE,
                true
            );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'cart_checkout_blocks',
                PRYVIKO_QUICK_ORDER_FILE,
                true
            );
        }
    );

    /**
     * Bootstrap after plugins load; no-op when WooCommerce is missing.
     */
    add_action(
        'plugins_loaded',
        static function (): void {
            if (!class_exists('WooCommerce')) {
                add_action(
                    'admin_notices',
                    static function (): void {
                        if (!current_user_can('activate_plugins')) {
                            return;
                        }
                        echo '<div class="notice notice-error"><p>';
                        echo esc_html__(
                            'Pryviko Quick Order requires WooCommerce 9.0+ to be installed and active.',
                            'pryviko-quick-order'
                        );
                        echo '</p></div>';
                    }
                );
                return;
            }

            \Pryviko\QuickOrder\Rest\QuickOrderController::register();
            \Pryviko\QuickOrder\Rest\LastOrderController::register();
            \Pryviko\QuickOrder\Plugin::init();

            if (
                function_exists('pqo_fs')
                && pqo_fs()->is__premium_only()
                && pqo_fs()->can_use_premium_code()
            ) {
                \Pryviko\QuickOrder\Pro\ProBootstrap__premium_only::boot();
            }
        }
    );
}
