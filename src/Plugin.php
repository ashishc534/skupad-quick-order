<?php
/**
 * Shortcode, assets, block, and admin wiring.
 *
 * @package SkuPad
 */

declare(strict_types=1);

namespace OpenJarvis\SkuPad;

final class Plugin
{
    public static function init(): void
    {
        add_shortcode('skupad_quick_order', [self::class, 'render_shortcode']);
        // Register before block themes can render shortcodes (that can run pre-wp_enqueue_scripts).
        add_action('init', [self::class, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('init', [self::class, 'register_block']);
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    public static function register_assets(): void
    {
        wp_register_style(
            'skupad-quick-order',
            SKUPAD_QUICK_ORDER_URL . 'assets/css/quick-order.css',
            [],
            SKUPAD_QUICK_ORDER_VERSION
        );
        wp_register_script(
            'skupad-quick-order',
            SKUPAD_QUICK_ORDER_URL . 'assets/js/quick-order.js',
            [],
            SKUPAD_QUICK_ORDER_VERSION,
            true
        );
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public static function render_shortcode($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'title' => __('Quick order by SKU', 'skupad-quick-order'),
                'placeholder' => "SKU,qty\nABC-100,2\nXYZ-200,1",
            ],
            is_array($atts) ? $atts : [],
            'skupad_quick_order'
        );

        // Block themes may render shortcodes before wp_enqueue_scripts; ensure handle exists
        // so wp_localize_script can attach the wp_rest nonce (empty X-WP-Nonce + cookies → 403).
        self::register_assets();

        $rest_url = esc_url_raw(rest_url('skupad/v1'));
        $nonce = wp_create_nonce('wp_rest');
        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
        $config = [
            'restUrl' => $rest_url,
            'nonce' => $nonce,
            'cartUrl' => $cart_url,
            'i18n' => [
                'empty' => __('Paste at least one SKU line.', 'skupad-quick-order'),
                'validating' => __('Validating SKUs…', 'skupad-quick-order'),
                'adding' => __('Adding to cart…', 'skupad-quick-order'),
                'done' => __('Added to cart.', 'skupad-quick-order'),
                'error' => __('Something went wrong. Please try again.', 'skupad-quick-order'),
            ],
        ];

        wp_enqueue_style('skupad-quick-order');
        wp_enqueue_script('skupad-quick-order');
        wp_localize_script('skupad-quick-order', 'SkuPadQuickOrder', $config);

        $uid = 'skupad-' . wp_unique_id();
        $title = (string) $atts['title'];
        $placeholder = (string) $atts['placeholder'];

        ob_start();
        ?>
        <div
            class="skupad-quick-order"
            data-skupad-root
            data-skupad-id="<?php echo esc_attr($uid); ?>"
            data-skupad-rest-url="<?php echo esc_url($rest_url); ?>"
            data-skupad-nonce="<?php echo esc_attr($nonce); ?>"
            data-skupad-cart-url="<?php echo esc_url($cart_url); ?>"
        >
            <div class="skupad-quick-order__header">
                <h3 class="skupad-quick-order__title"><?php echo esc_html($title); ?></h3>
                <p class="skupad-quick-order__hint">
                    <?php esc_html_e('Paste SKU + quantity lines (comma, tab, or space-separated). One product per line.', 'skupad-quick-order'); ?>
                </p>
            </div>
            <label class="skupad-quick-order__label" for="<?php echo esc_attr($uid); ?>-paste">
                <?php esc_html_e('SKU list', 'skupad-quick-order'); ?>
            </label>
            <textarea
                id="<?php echo esc_attr($uid); ?>-paste"
                class="skupad-quick-order__paste"
                rows="8"
                placeholder="<?php echo esc_attr($placeholder); ?>"
                spellcheck="false"
            ></textarea>
            <div class="skupad-quick-order__actions">
                <button type="button" class="skupad-quick-order__btn skupad-quick-order__btn--validate">
                    <?php esc_html_e('Validate', 'skupad-quick-order'); ?>
                </button>
                <button type="button" class="skupad-quick-order__btn skupad-quick-order__btn--add">
                    <?php esc_html_e('Add to cart', 'skupad-quick-order'); ?>
                </button>
                <a class="skupad-quick-order__cart-link" href="<?php echo esc_url(function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#'); ?>">
                    <?php esc_html_e('View cart', 'skupad-quick-order'); ?>
                </a>
            </div>
            <div class="skupad-quick-order__status" aria-live="polite"></div>
            <div class="skupad-quick-order__results" hidden></div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function register_block(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        register_block_type(
            'skupad/quick-order',
            [
                'api_version' => 2,
                'title' => __('SkuPad Quick Order', 'skupad-quick-order'),
                'description' => __('Paste-SKU quick order pad for WooCommerce.', 'skupad-quick-order'),
                'category' => 'woocommerce',
                'icon' => 'cart',
                'render_callback' => static function (): string {
                    return self::render_shortcode([]);
                },
            ]
        );
    }

    public static function admin_menu(): void
    {
        add_submenu_page(
            'woocommerce',
            __('SkuPad', 'skupad-quick-order'),
            __('SkuPad', 'skupad-quick-order'),
            'manage_woocommerce',
            'skupad-quick-order',
            [self::class, 'render_admin']
        );
    }

    public static function render_admin(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('SkuPad — Quick Order', 'skupad-quick-order'); ?></h1>
            <p><?php esc_html_e('Free paste-SKU pad is fully unlocked (no trialware). Place the shortcode on any page:', 'skupad-quick-order'); ?></p>
            <p><code>[skupad_quick_order]</code></p>
            <h2><?php esc_html_e('Compatibility', 'skupad-quick-order'); ?></h2>
            <ul>
                <li><?php esc_html_e('WordPress 6.6+', 'skupad-quick-order'); ?></li>
                <li><?php esc_html_e('WooCommerce 9.0+ with HPOS (custom order tables)', 'skupad-quick-order'); ?></li>
                <li><?php esc_html_e('Cart &amp; Checkout Blocks', 'skupad-quick-order'); ?></li>
                <li><?php esc_html_e('PHP 8.0+', 'skupad-quick-order'); ?></li>
            </ul>
            <h2><?php esc_html_e('Pro add-on (optional, separate purchase)', 'skupad-quick-order'); ?></h2>
            <p><?php esc_html_e('File upload for SKU lists, saved order lists, and one-click reorder ship as a separate Pro add-on ($39/year). Free paste features stay free forever.', 'skupad-quick-order'); ?></p>
        </div>
        <?php
    }
}
