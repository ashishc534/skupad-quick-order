<?php
/**
 * Shortcode, assets, block, and admin wiring.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder;

use Pryviko\QuickOrder\Catalog\SkuValidator;

final class Plugin
{
    public static function init(): void
    {
        add_shortcode('pryviko_quick_order', [self::class, 'render_shortcode']);
        add_action('init', [self::class, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets']);
        add_action('init', [self::class, 'register_block']);
        add_action('admin_menu', [self::class, 'admin_menu']);
    }

    public static function register_assets(): void
    {
        wp_register_style(
            'pryviko-quick-order',
            PRYVIKO_QUICK_ORDER_URL . 'assets/css/quick-order.css',
            [],
            PRYVIKO_QUICK_ORDER_VERSION
        );
        wp_register_script(
            'pryviko-quick-order',
            PRYVIKO_QUICK_ORDER_URL . 'assets/js/quick-order.js',
            [],
            PRYVIKO_QUICK_ORDER_VERSION,
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
                'title' => __('Quick order by SKU', 'pryviko-quick-order'),
                'placeholder' => "SKU,qty\nABC-100,2\nXYZ-200,1",
            ],
            is_array($atts) ? $atts : [],
            'pryviko_quick_order'
        );

        self::register_assets();

        $is_pro = SkuValidator::isPro();
        $upgrade_url = '';
        if (function_exists('pqo_fs')) {
            try {
                $upgrade_url = (string) pqo_fs()->get_upgrade_url();
            } catch (\Throwable $e) {
                $upgrade_url = '';
            }
        }
        if ($upgrade_url === '') {
            $upgrade_url = 'https://pryviko.com/pricing';
        }

        $rest_url = esc_url_raw(rest_url('pryviko/v1'));
        $nonce = wp_create_nonce('wp_rest');
        $cart_url = function_exists('wc_get_cart_url') ? wc_get_cart_url() : home_url('/cart/');
        $logged_in = is_user_logged_in();

        $config = [
            'restUrl' => $rest_url,
            'nonce' => $nonce,
            'cartUrl' => $cart_url,
            'isPro' => $is_pro,
            'upgradeUrl' => $upgrade_url,
            'isLoggedIn' => $logged_in,
            'i18n' => [
                'empty' => __('Paste at least one SKU line.', 'pryviko-quick-order'),
                'validating' => __('Validating SKUs…', 'pryviko-quick-order'),
                'adding' => __('Adding to cart…', 'pryviko-quick-order'),
                'done' => __('Added to cart.', 'pryviko-quick-order'),
                'error' => __('Something went wrong. Please try again.', 'pryviko-quick-order'),
                'linkCopied' => __('Order link copied', 'pryviko-quick-order'),
                'upgrade' => __('Upgrade to Pro', 'pryviko-quick-order'),
            ],
        ];

        wp_enqueue_style('pryviko-quick-order');
        wp_enqueue_script('pryviko-quick-order');
        wp_localize_script('pryviko-quick-order', 'PryvikoQuickOrder', $config);

        $uid = 'pryviko-' . wp_unique_id();
        $title = (string) $atts['title'];
        $placeholder = (string) $atts['placeholder'];

        ob_start();
        ?>
        <div
            class="pryviko-quick-order"
            data-pryviko-root
            data-pryviko-id="<?php echo esc_attr($uid); ?>"
            data-pryviko-rest-url="<?php echo esc_url($rest_url); ?>"
            data-pryviko-nonce="<?php echo esc_attr($nonce); ?>"
            data-pryviko-cart-url="<?php echo esc_url($cart_url); ?>"
            data-pryviko-is-pro="<?php echo $is_pro ? '1' : '0'; ?>"
            data-pryviko-upgrade-url="<?php echo esc_url($upgrade_url); ?>"
            data-pryviko-logged-in="<?php echo $logged_in ? '1' : '0'; ?>"
        >
            <div class="pryviko-quick-order__header">
                <h3 class="pryviko-quick-order__title"><?php echo esc_html($title); ?></h3>
                <p class="pryviko-quick-order__hint">
                    <?php esc_html_e('Paste SKU + quantity lines (comma, tab, or space-separated). One product per line. Messy email/WhatsApp paste works too.', 'pryviko-quick-order'); ?>
                </p>
            </div>

            <div class="pryviko-quick-order__tabs" role="tablist">
                <button type="button" class="pryviko-quick-order__tab is-active" data-pryviko-tab="paste" role="tab" aria-selected="true">
                    <?php esc_html_e('Paste SKUs', 'pryviko-quick-order'); ?>
                </button>
                <button type="button" class="pryviko-quick-order__tab" data-pryviko-tab="csv" role="tab" aria-selected="false">
                    <?php esc_html_e('Upload CSV', 'pryviko-quick-order'); ?>
                    <?php if (!$is_pro) : ?>
                        <span class="pryviko-quick-order__pro-pill"><?php esc_html_e('Pro', 'pryviko-quick-order'); ?></span>
                    <?php endif; ?>
                </button>
                <button type="button" class="pryviko-quick-order__tab" data-pryviko-tab="lists" role="tab" aria-selected="false">
                    <?php esc_html_e('Saved Lists', 'pryviko-quick-order'); ?>
                    <?php if (!$is_pro) : ?>
                        <span class="pryviko-quick-order__pro-pill"><?php esc_html_e('Pro', 'pryviko-quick-order'); ?></span>
                    <?php endif; ?>
                </button>
            </div>

            <div class="pryviko-quick-order__panel is-active" data-pryviko-panel="paste">
                <label class="pryviko-quick-order__label" for="<?php echo esc_attr($uid); ?>-paste">
                    <?php esc_html_e('SKU list', 'pryviko-quick-order'); ?>
                </label>
                <textarea
                    id="<?php echo esc_attr($uid); ?>-paste"
                    class="pryviko-quick-order__paste"
                    rows="8"
                    placeholder="<?php echo esc_attr($placeholder); ?>"
                    spellcheck="false"
                ></textarea>
            </div>

            <div class="pryviko-quick-order__panel" data-pryviko-panel="csv" hidden>
                <?php if ($is_pro) : ?>
                    <div class="pryviko-quick-order__dropzone" data-pryviko-dropzone>
                        <p><?php esc_html_e('Drag & drop a CSV here, or choose a file (max 512KB, 500 rows).', 'pryviko-quick-order'); ?></p>
                        <input type="file" accept=".csv,text/csv,text/plain" class="pryviko-quick-order__file" data-pryviko-csv-input />
                        <a href="#" class="pryviko-quick-order__sample-link" data-pryviko-sample-csv>
                            <?php esc_html_e('Download Sample CSV (using your store SKUs)', 'pryviko-quick-order'); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <div class="pryviko-quick-order__upgrade" data-pryviko-upgrade-card hidden>
                        <p><?php esc_html_e('CSV upload is a Pro feature — drag-drop SKU lists with optional line notes.', 'pryviko-quick-order'); ?></p>
                        <a class="pryviko-quick-order__btn" href="<?php echo esc_url($upgrade_url); ?>">
                            <?php esc_html_e('Upgrade to Pro', 'pryviko-quick-order'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <div class="pryviko-quick-order__panel" data-pryviko-panel="lists" hidden>
                <?php if ($is_pro) : ?>
                    <div class="pryviko-quick-order__lists" data-pryviko-lists>
                        <div class="pryviko-quick-order__lists-actions">
                            <input type="text" class="pryviko-quick-order__list-name" placeholder="<?php esc_attr_e('List name', 'pryviko-quick-order'); ?>" data-pryviko-list-name />
                            <button type="button" class="pryviko-quick-order__btn pryviko-quick-order__btn--validate" data-pryviko-list-save>
                                <?php esc_html_e('Save list', 'pryviko-quick-order'); ?>
                            </button>
                        </div>
                        <ul class="pryviko-quick-order__list-items" data-pryviko-list-items></ul>
                    </div>
                <?php else : ?>
                    <div class="pryviko-quick-order__upgrade" data-pryviko-upgrade-card hidden>
                        <p><?php esc_html_e('Saved lists are a Pro feature — save up to 25 reorder lists for one-click load.', 'pryviko-quick-order'); ?></p>
                        <a class="pryviko-quick-order__btn" href="<?php echo esc_url($upgrade_url); ?>">
                            <?php esc_html_e('Upgrade to Pro', 'pryviko-quick-order'); ?>
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($is_pro) : ?>
                <label class="pryviko-quick-order__label" for="<?php echo esc_attr($uid); ?>-po">
                    <?php esc_html_e('PO Number (optional)', 'pryviko-quick-order'); ?>
                </label>
                <input
                    id="<?php echo esc_attr($uid); ?>-po"
                    type="text"
                    class="pryviko-quick-order__po"
                    data-pryviko-po
                    maxlength="64"
                    autocomplete="off"
                />
            <?php endif; ?>

            <div class="pryviko-quick-order__actions">
                <button type="button" class="pryviko-quick-order__btn pryviko-quick-order__btn--validate">
                    <?php esc_html_e('Validate', 'pryviko-quick-order'); ?>
                </button>
                <button type="button" class="pryviko-quick-order__btn pryviko-quick-order__btn--add">
                    <?php esc_html_e('Add to cart', 'pryviko-quick-order'); ?>
                </button>
                <button type="button" class="pryviko-quick-order__btn pryviko-quick-order__btn--share" data-pryviko-share>
                    <?php esc_html_e('Copy Order Link', 'pryviko-quick-order'); ?>
                </button>
                <?php if ($logged_in) : ?>
                    <button type="button" class="pryviko-quick-order__btn pryviko-quick-order__btn--validate" data-pryviko-last-order>
                        <?php esc_html_e('Load Last Order', 'pryviko-quick-order'); ?>
                    </button>
                <?php endif; ?>
                <a class="pryviko-quick-order__cart-link" href="<?php echo esc_url(function_exists('wc_get_cart_url') ? wc_get_cart_url() : '#'); ?>">
                    <?php esc_html_e('View cart', 'pryviko-quick-order'); ?>
                </a>
            </div>
            <div class="pryviko-quick-order__toast" data-pryviko-toast hidden></div>
            <div class="pryviko-quick-order__status" aria-live="polite"></div>
            <div class="pryviko-quick-order__results" hidden></div>
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
            'pryviko/quick-order',
            [
                'api_version' => 2,
                'title' => __('Pryviko Quick Order', 'pryviko-quick-order'),
                'description' => __('Paste-SKU quick order pad for WooCommerce.', 'pryviko-quick-order'),
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
            __('Pryviko', 'pryviko-quick-order'),
            __('Pryviko', 'pryviko-quick-order'),
            'manage_woocommerce',
            'pryviko-quick-order',
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
            <h1><?php esc_html_e('Pryviko — Quick Order', 'pryviko-quick-order'); ?></h1>
            <p><?php esc_html_e('Free paste-SKU pad is fully unlocked (no trialware). Place the shortcode on any page:', 'pryviko-quick-order'); ?></p>
            <p><code>[pryviko_quick_order]</code></p>
            <h2><?php esc_html_e('Compatibility', 'pryviko-quick-order'); ?></h2>
            <ul>
                <li><?php esc_html_e('WordPress 6.6+', 'pryviko-quick-order'); ?></li>
                <li><?php esc_html_e('WooCommerce 9.0+ with HPOS (custom order tables)', 'pryviko-quick-order'); ?></li>
                <li><?php esc_html_e('Cart &amp; Checkout Blocks', 'pryviko-quick-order'); ?></li>
                <li><?php esc_html_e('PHP 8.0+', 'pryviko-quick-order'); ?></li>
            </ul>
            <h2><?php esc_html_e('Pro (optional)', 'pryviko-quick-order'); ?></h2>
            <p><?php esc_html_e('Variation SKU labels, CSV upload, saved lists, PO number & line notes — $39/year with 14-day trial. Free paste features stay free forever.', 'pryviko-quick-order'); ?></p>
        </div>
        <?php
    }
}
