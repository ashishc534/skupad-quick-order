<?php
/**
 * Pro: PO number + per-line notes → cart → checkout → order meta.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Pro;

final class OrderNotesHandler__premium_only
{
    public static function register(): void
    {
        add_filter('woocommerce_get_item_data', [self::class, 'display_cart_item_note'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'persist_line_note'], 10, 4);
        add_action('woocommerce_checkout_update_order_meta', [self::class, 'persist_po_number'], 10, 1);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'admin_po_display'], 10, 1);
        add_action('woocommerce_cart_calculate_fees', [self::class, 'noop_keep_session'], 1);
    }

    /**
     * @param list<array{key:string,value:string}> $item_data
     * @param array<string,mixed> $cart_item
     * @return list<array{key:string,value:string}>
     */
    public static function display_cart_item_note(array $item_data, array $cart_item): array
    {
        if (!empty($cart_item['pryviko_line_note'])) {
            $item_data[] = [
                'key' => __('Note', 'pryviko-quick-order'),
                'value' => sanitize_text_field((string) $cart_item['pryviko_line_note']),
            ];
        }
        return $item_data;
    }

    /**
     * @param \WC_Order_Item_Product $item
     * @param string $cart_item_key
     * @param array<string,mixed> $values
     * @param \WC_Order $order
     */
    public static function persist_line_note($item, string $cart_item_key, array $values, $order): void
    {
        unset($cart_item_key, $order);
        if (!empty($values['pryviko_line_note']) && is_object($item) && method_exists($item, 'add_meta_data')) {
            $item->add_meta_data(
                __('Line note', 'pryviko-quick-order'),
                sanitize_text_field((string) $values['pryviko_line_note']),
                true
            );
        }
    }

    public static function persist_po_number(int $order_id): void
    {
        if (!function_exists('WC') || !WC()->session) {
            return;
        }
        $po = (string) WC()->session->get('pryviko_po_number');
        if ($po === '') {
            return;
        }
        update_post_meta($order_id, '_pryviko_po_number', sanitize_text_field($po));
        if (function_exists('wc_get_order')) {
            $order = wc_get_order($order_id);
            if ($order && method_exists($order, 'update_meta_data')) {
                $order->update_meta_data('_pryviko_po_number', sanitize_text_field($po));
                $order->save();
            }
        }
        WC()->session->set('pryviko_po_number', null);
    }

    /**
     * @param \WC_Order $order
     */
    public static function admin_po_display($order): void
    {
        if (!is_object($order) || !method_exists($order, 'get_meta')) {
            return;
        }
        $po = (string) $order->get_meta('_pryviko_po_number');
        if ($po === '') {
            $po = (string) get_post_meta($order->get_id(), '_pryviko_po_number', true);
        }
        if ($po === '') {
            return;
        }
        echo '<p><strong>' . esc_html__('PO Number', 'pryviko-quick-order') . ':</strong> ';
        echo esc_html($po) . '</p>';
    }

    /** Keep hook registered so session survives cart recalculation. */
    public static function noop_keep_session(): void
    {
    }
}
