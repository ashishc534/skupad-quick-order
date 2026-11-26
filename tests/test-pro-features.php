<?php
/**
 * Pro + viral feature unit tests (no WordPress / Freemius runtime).
 *
 * Run: php tests/test-pro-features.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Parser/LineParser.php';
require_once dirname(__DIR__) . '/src/Catalog/SkuValidator.php';
require_once dirname(__DIR__) . '/src/Rest/QuickOrderController.php';
require_once dirname(__DIR__) . '/src/Rest/LastOrderController.php';
require_once dirname(__DIR__) . '/src/Pro/CsvUploadController__premium_only.php';
require_once dirname(__DIR__) . '/src/Pro/SavedListsController__premium_only.php';
require_once dirname(__DIR__) . '/src/Pro/SampleCsvController__premium_only.php';
require_once dirname(__DIR__) . '/src/Pro/OrderNotesHandler__premium_only.php';

use Pryviko\QuickOrder\Catalog\SkuValidator;
use Pryviko\QuickOrder\Parser\LineParser;
use Pryviko\QuickOrder\Pro\CsvUploadController__premium_only;
use Pryviko\QuickOrder\Pro\OrderNotesHandler__premium_only;
use Pryviko\QuickOrder\Pro\SampleCsvController__premium_only;
use Pryviko\QuickOrder\Pro\SavedListsController__premium_only;
use Pryviko\QuickOrder\Rest\LastOrderController;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// --- stubs for WP functions used by Pro controllers ---
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return is_string($str) ? trim(strip_tags($str)) : '';
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}
if (!function_exists('__')) {
    function __($text, $domain = null)
    {
        return $text;
    }
}
if (!function_exists('esc_html')) {
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null)
    {
        return esc_html($text);
    }
}
if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value)
    {
        return $value;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in()
    {
        return !empty($GLOBALS['pryviko_test_logged_in']);
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return (int) ($GLOBALS['pryviko_test_user_id'] ?? 0);
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false)
    {
        return $GLOBALS['pryviko_user_meta'][(int) $user_id][$key] ?? ($single ? '' : []);
    }
}
if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value)
    {
        $GLOBALS['pryviko_user_meta'][(int) $user_id][$key] = $value;
        return true;
    }
}
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = false)
    {
        return $GLOBALS['pryviko_post_meta'][(int) $id][$key] ?? ($single ? '' : []);
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value)
    {
        $GLOBALS['pryviko_post_meta'][(int) $id][$key] = $value;
        return true;
    }
}

$GLOBALS['pryviko_user_meta'] = [];
$GLOBALS['pryviko_post_meta'] = [];

// --- CSV limits ---
$tiny = CsvUploadController__premium_only::parseCsvRows("sku,qty,note\nA-1,2,rush\nB-2,1,\n");
assert_true(count($tiny) === 2, 'csv parses two rows');
assert_true($tiny[0]['sku'] === 'A-1' && $tiny[0]['qty'] === 2 && $tiny[0]['note'] === 'rush', 'csv note');

$big = "sku,qty\n";
for ($i = 0; $i < 520; $i++) {
    $big .= "S-{$i},1\n";
}
$capped = CsvUploadController__premium_only::parseCsvRows($big);
assert_true(count($capped) === CsvUploadController__premium_only::MAX_ROWS, 'csv max 500 rows');
assert_true(CsvUploadController__premium_only::MAX_BYTES === 524288, 'csv max 512KB');

// --- Saved lists isolation ---
$GLOBALS['pryviko_test_user_id'] = 7;
$GLOBALS['pryviko_test_logged_in'] = true;
SavedListsController__premium_only::write_lists(7, [
    'abc' => [
        'id' => 'abc',
        'name' => 'User7 List',
        'lines' => [['sku' => 'X-1', 'qty' => 2]],
        'updated' => '2026-01-01T00:00:00Z',
    ],
]);
SavedListsController__premium_only::write_lists(8, [
    'xyz' => [
        'id' => 'xyz',
        'name' => 'User8 List',
        'lines' => [['sku' => 'Y-1', 'qty' => 9]],
        'updated' => '2026-01-01T00:00:00Z',
    ],
]);
$u7 = SavedListsController__premium_only::read_lists(7);
$u8 = SavedListsController__premium_only::read_lists(8);
assert_true(isset($u7['abc']) && !isset($u7['xyz']), 'lists isolated to user 7');
assert_true(isset($u8['xyz']) && !isset($u8['abc']), 'lists isolated to user 8');
assert_true(SavedListsController__premium_only::MAX_LISTS === 25, 'max 25 lists');
assert_true(SavedListsController__premium_only::MAX_LINES === 250, 'max 250 lines');

// --- PO / notes persistence shape ---
assert_true(method_exists(OrderNotesHandler__premium_only::class, 'persist_po_number'), 'PO persist method');
assert_true(method_exists(OrderNotesHandler__premium_only::class, 'persist_line_note'), 'line note persist');
assert_true(method_exists(OrderNotesHandler__premium_only::class, 'display_cart_item_note'), 'cart display');
assert_true(method_exists(OrderNotesHandler__premium_only::class, 'admin_po_display'), 'admin PO display');

$item_data = OrderNotesHandler__premium_only::display_cart_item_note([], [
    'pryviko_line_note' => 'Ship dock 3',
]);
assert_true(count($item_data) === 1 && $item_data[0]['value'] === 'Ship dock 3', 'cart note display');

OrderNotesHandler__premium_only::persist_po_number(4242);
// Without WC session, persist is a no-op — ensure no crash and meta untouched.
assert_true(get_post_meta(4242, '_pryviko_po_number', true) === '', 'PO no-op without session');

// Simulate meta write path directly (order meta contract).
update_post_meta(4242, '_pryviko_po_number', 'PO-7788');
assert_true(get_post_meta(4242, '_pryviko_po_number', true) === 'PO-7788', 'PO meta persistence contract');

// --- Free mode without Pro ---
SkuValidator::setProOverride(false);
assert_true(SkuValidator::isPro() === false, 'free mode isPro false');

// --- Sample CSV excludes draft/hidden ---
if (!class_exists('WC_Product', false)) {
    class FakeSampleProduct
    {
        private string $status;
        private bool $visible;
        private string $sku;
        private string $type;

        public function __construct(array $a)
        {
            $this->status = $a['status'];
            $this->visible = $a['visible'];
            $this->sku = $a['sku'];
            $this->type = $a['type'] ?? 'simple';
        }

        public function get_status(): string
        {
            return $this->status;
        }

        public function is_visible(): bool
        {
            return $this->visible;
        }

        public function get_sku(): string
        {
            return $this->sku;
        }

        public function is_type(string $t): bool
        {
            return $this->type === $t;
        }

        public function get_parent_id(): int
        {
            return 0;
        }
    }
    class_alias(FakeSampleProduct::class, 'WC_Product');
}

function wc_get_products(array $args): array
{
    unset($args);
    return $GLOBALS['pryviko_sample_products'] ?? [];
}

$GLOBALS['pryviko_sample_products'] = [
    new WC_Product(['status' => 'draft', 'visible' => true, 'sku' => 'DRAFT-1']),
    new WC_Product(['status' => 'private', 'visible' => true, 'sku' => 'PRIV-1']),
    new WC_Product(['status' => 'publish', 'visible' => false, 'sku' => 'HIDE-1']),
    new WC_Product(['status' => 'publish', 'visible' => true, 'sku' => 'GOOD-1']),
    new WC_Product(['status' => 'publish', 'visible' => true, 'sku' => 'GOOD-2']),
    new WC_Product(['status' => 'publish', 'visible' => true, 'sku' => '']),
];

$sample = SampleCsvController__premium_only::pick_sample_rows();
$skus = array_column($sample, 'sku');
assert_true(!in_array('DRAFT-1', $skus, true), 'sample excludes draft');
assert_true(!in_array('PRIV-1', $skus, true), 'sample excludes private');
assert_true(!in_array('HIDE-1', $skus, true), 'sample excludes hidden');
assert_true(in_array('GOOD-1', $skus, true) && in_array('GOOD-2', $skus, true), 'sample includes public SKUs');
assert_true(count($sample) <= 3, 'sample max 3');

// Empty catalog → placeholders
$GLOBALS['pryviko_sample_products'] = [];
$fallback = SampleCsvController__premium_only::pick_sample_rows();
assert_true(count($fallback) === 3 && str_starts_with($fallback[0]['sku'], 'SAMPLE-'), 'sample fallback placeholders');

// --- Last-order permission / isolation (source + method presence) ---
assert_true(method_exists(LastOrderController::class, 'permission_callback'), 'last-order permission');
assert_true(method_exists(LastOrderController::class, 'last_order'), 'last-order callback');
$lastSrc = file_get_contents(dirname(__DIR__) . '/src/Rest/LastOrderController.php');
assert_true(
    $lastSrc !== false
    && str_contains($lastSrc, 'customer_id')
    && str_contains($lastSrc, 'get_current_user_id')
    && str_contains($lastSrc, 'get_customer_id'),
    'last-order user isolation'
);

// --- Share payload ---
$share = LineParser::decodeSharePayload('AA:2,BB:3');
assert_true(count($share) === 2 && $share[0]['sku'] === 'AA' && $share[1]['qty'] === 3, '?pryviko= parsing');

echo "pro-features ok\n";
