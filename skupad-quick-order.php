<?php
/**
 * Plugin Name:       SkuPad - Quick Order & Paste-SKU Pad for WooCommerce
 * Plugin URI:        https://wordpress.org/plugins/skupad-quick-order/
 * Description:       Paste SKU + quantity lines (comma, tab, or space-separated) and add validated lines to the WooCommerce cart in one step. Built for B2B and wholesale buyers. HPOS and Cart/Checkout Blocks compatible.
 * Version:           1.0.1
 * Requires at least: 6.6
 * Requires PHP:      8.0
 * Author:            ashishc534
 * Author URI:        https://profiles.wordpress.org/ashishc534/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       skupad-quick-order
 * Domain Path:       /languages
 * WC requires at least: 9.0
 * WC tested up to:   11.3
 *
 * @package SkuPad
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('SKUPAD_QUICK_ORDER_VERSION', '1.0.1');
define('SKUPAD_QUICK_ORDER_FILE', __FILE__);
define('SKUPAD_QUICK_ORDER_DIR', plugin_dir_path(__FILE__));
define('SKUPAD_QUICK_ORDER_URL', plugin_dir_url(__FILE__));

require_once SKUPAD_QUICK_ORDER_DIR . 'src/Parser/LineParser.php';
require_once SKUPAD_QUICK_ORDER_DIR . 'src/Catalog/SkuValidator.php';
require_once SKUPAD_QUICK_ORDER_DIR . 'src/Rest/QuickOrderController.php';
require_once SKUPAD_QUICK_ORDER_DIR . 'src/Plugin.php';

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
            SKUPAD_QUICK_ORDER_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            SKUPAD_QUICK_ORDER_FILE,
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
                        'SkuPad requires WooCommerce 9.0+ to be installed and active.',
                        'skupad-quick-order'
                    );
                    echo '</p></div>';
                }
            );
            return;
        }

        \OpenJarvis\SkuPad\Rest\QuickOrderController::register();
        \OpenJarvis\SkuPad\Plugin::init();
    }
);
