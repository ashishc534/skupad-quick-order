<?php
/**
 * Visibility / information-disclosure guard tests (mocked WC catalog).
 *
 * Run: php tests/test-visibility-guard.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Catalog/SkuValidator.php';
require_once dirname(__DIR__) . '/src/Rest/QuickOrderController.php';

use Pryviko\QuickOrder\Catalog\SkuValidator;
use Pryviko\QuickOrder\Rest\QuickOrderController;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

/**
 * Minimal WC_Product stand-in for unit tests (no WooCommerce bootstrap).
 */
class Fake_WC_Product
{
    private int $id;
    private string $status;
    private bool $visible;
    private string $name;
    private string $price;
    private string $stock_status;
    private bool $purchasable;
    private bool $in_stock;
    private bool $managing_stock;
    private ?int $stock_qty;
    private string $type;
    private int $parent_id;
    /** @var array<string,string> */
    private array $variation_attrs;
    private string $sku;

    public function __construct(array $args)
    {
        $this->id = (int) ($args['id'] ?? 0);
        $this->status = (string) ($args['status'] ?? 'publish');
        $this->visible = (bool) ($args['visible'] ?? true);
        $this->name = (string) ($args['name'] ?? 'Secret');
        $this->price = (string) ($args['price'] ?? '99.00');
        $this->stock_status = (string) ($args['stock_status'] ?? 'instock');
        $this->purchasable = (bool) ($args['purchasable'] ?? true);
        $this->in_stock = (bool) ($args['in_stock'] ?? true);
        $this->managing_stock = (bool) ($args['managing_stock'] ?? false);
        $this->stock_qty = array_key_exists('stock_qty', $args) ? (isset($args['stock_qty']) ? (int) $args['stock_qty'] : null) : null;
        $this->type = (string) ($args['type'] ?? 'simple');
        $this->parent_id = (int) ($args['parent_id'] ?? 0);
        $this->variation_attrs = (array) ($args['variation_attrs'] ?? []);
        $this->sku = (string) ($args['sku'] ?? '');
    }

    public function get_id(): int
    {
        return $this->id;
    }

    public function get_status(): string
    {
        return $this->status;
    }

    public function is_visible(): bool
    {
        return $this->visible;
    }

    public function get_name(): string
    {
        return $this->name;
    }

    public function get_price(): string
    {
        return $this->price;
    }

    public function get_stock_status(): string
    {
        return $this->stock_status;
    }

    public function is_purchasable(): bool
    {
        return $this->purchasable;
    }

    public function is_in_stock(): bool
    {
        return $this->in_stock;
    }

    public function managing_stock(): bool
    {
        return $this->managing_stock;
    }

    public function get_stock_quantity(): ?int
    {
        return $this->stock_qty;
    }

    public function is_type(string $type): bool
    {
        return $this->type === $type;
    }

    public function get_parent_id(): int
    {
        return $this->parent_id;
    }

    public function get_variation_attributes(): array
    {
        return $this->variation_attrs;
    }

    public function get_sku(): string
    {
        return $this->sku;
    }
}

if (!class_exists('WC_Product', false)) {
    class_alias(Fake_WC_Product::class, 'WC_Product');
}

/** @var array<string,int> */
$GLOBALS['pryviko_test_sku_map'] = [];
/** @var array<int,Fake_WC_Product> */
$GLOBALS['pryviko_test_products'] = [];

function wc_get_product_id_by_sku(string $sku): int
{
    return (int) ($GLOBALS['pryviko_test_sku_map'][$sku] ?? 0);
}

/**
 * @return Fake_WC_Product|false|null
 */
function wc_get_product(int $id)
{
    return $GLOBALS['pryviko_test_products'][$id] ?? false;
}

function register_product(string $sku, array $args): Fake_WC_Product
{
    $id = (int) ($args['id'] ?? (count($GLOBALS['pryviko_test_products']) + 1));
    $args['id'] = $id;
    $args['sku'] = $sku;
    $product = new Fake_WC_Product($args);
    $GLOBALS['pryviko_test_products'][$id] = $product;
    $GLOBALS['pryviko_test_sku_map'][$sku] = $id;
    return $product;
}

function assert_same_as_unknown(array $row, string $sku, int $qty, string $label): void
{
    $unknown = SkuValidator::notFoundRow($sku, $qty);
    assert_true($row === $unknown, "{$label}: row must equal unknown SKU response");
    assert_true($row['message'] === SkuValidator::NOT_FOUND_MESSAGE, "{$label}: message");
    assert_true($row['product_id'] === 0, "{$label}: product_id zero");
    assert_true($row['name'] === '', "{$label}: name empty");
    assert_true($row['price'] === '', "{$label}: price empty");
    assert_true($row['stock_status'] === '', "{$label}: stock empty");
    assert_true($row['ok'] === false, "{$label}: ok false");
}

SkuValidator::setProOverride(false);

$cases = [
    'draft' => ['status' => 'draft', 'visible' => true, 'name' => 'Draft Widget', 'price' => '12.00'],
    'private' => ['status' => 'private', 'visible' => true, 'name' => 'Private Widget', 'price' => '22.00'],
    'pending' => ['status' => 'pending', 'visible' => true, 'name' => 'Pending Widget', 'price' => '32.00'],
    'future' => ['status' => 'future', 'visible' => true, 'name' => 'Future Widget', 'price' => '42.00'],
    'catalog-hidden' => ['status' => 'publish', 'visible' => false, 'name' => 'Hidden Widget', 'price' => '52.00'],
];

foreach ($cases as $label => $args) {
    $sku = 'SEC-' . strtoupper(str_replace('-', '', $label));
    register_product($sku, $args);
    $rows = SkuValidator::resolveCatalog([['sku' => $sku, 'qty' => 2]]);
    assert_true(count($rows) === 1, "{$label}: one row");
    assert_same_as_unknown($rows[0], $sku, 2, $label);
}

$unknownRows = SkuValidator::resolveCatalog([['sku' => 'NO-SUCH-SKU', 'qty' => 2]]);
assert_same_as_unknown($unknownRows[0], 'NO-SUCH-SKU', 2, 'unknown');

register_product(
    'PARENT-DRAFT',
    ['id' => 900, 'status' => 'draft', 'visible' => true, 'name' => 'Draft Parent', 'type' => 'variable']
);
register_product(
    'VAR-ON-DRAFT',
    [
        'id' => 901,
        'status' => 'publish',
        'visible' => true,
        'name' => 'Var On Draft',
        'price' => '15.00',
        'type' => 'variation',
        'parent_id' => 900,
    ]
);
$varDraft = SkuValidator::resolveCatalog([['sku' => 'VAR-ON-DRAFT', 'qty' => 1]]);
assert_same_as_unknown($varDraft[0], 'VAR-ON-DRAFT', 1, 'variation-of-draft-parent');

register_product(
    'PARENT-PRIVATE',
    ['id' => 910, 'status' => 'private', 'visible' => true, 'name' => 'Private Parent', 'type' => 'variable']
);
register_product(
    'VAR-ON-PRIVATE',
    [
        'id' => 911,
        'status' => 'publish',
        'visible' => true,
        'name' => 'Var On Private',
        'price' => '18.00',
        'type' => 'variation',
        'parent_id' => 910,
    ]
);
$varPrivate = SkuValidator::resolveCatalog([['sku' => 'VAR-ON-PRIVATE', 'qty' => 1]]);
assert_same_as_unknown($varPrivate[0], 'VAR-ON-PRIVATE', 1, 'variation-of-private-parent');

register_product(
    'PARENT-HIDDEN',
    ['id' => 930, 'status' => 'publish', 'visible' => false, 'name' => 'Hidden Parent', 'type' => 'variable']
);
register_product(
    'VAR-ON-HIDDEN',
    [
        'id' => 931,
        'status' => 'publish',
        'visible' => true,
        'name' => 'Var On Hidden',
        'price' => '19.00',
        'type' => 'variation',
        'parent_id' => 930,
    ]
);
$varHidden = SkuValidator::resolveCatalog([['sku' => 'VAR-ON-HIDDEN', 'qty' => 1]]);
assert_same_as_unknown($varHidden[0], 'VAR-ON-HIDDEN', 1, 'variation-of-hidden-parent');

register_product(
    'PUB-OK',
    [
        'id' => 1001,
        'status' => 'publish',
        'visible' => true,
        'name' => 'Public Widget',
        'price' => '9.50',
        'stock_status' => 'instock',
        'purchasable' => true,
        'in_stock' => true,
    ]
);
$ok = SkuValidator::resolveCatalog([['sku' => 'PUB-OK', 'qty' => 1]]);
assert_true($ok[0]['ok'] === true, 'published visible ok');
assert_true($ok[0]['product_id'] === 1001, 'published product_id');
assert_true($ok[0]['name'] === 'Public Widget', 'published name');

register_product(
    'PARENT-PUB',
    ['id' => 920, 'status' => 'publish', 'visible' => true, 'name' => 'Pub Parent', 'type' => 'variable']
);
register_product(
    'SKU-1042',
    [
        'id' => 921,
        'status' => 'publish',
        'visible' => true,
        'name' => 'Var On Pub',
        'price' => '11.00',
        'type' => 'variation',
        'parent_id' => 920,
        'purchasable' => true,
        'in_stock' => true,
        'stock_status' => 'instock',
        'variation_attrs' => [
            'attribute_pa_size' => 'large',
            'attribute_pa_color' => 'blue',
        ],
    ]
);

// Free mode: variation resolves with default name (preserve v1.0.2)
SkuValidator::setProOverride(false);
$varFree = SkuValidator::resolveCatalog([['sku' => 'SKU-1042', 'qty' => 1]]);
assert_true($varFree[0]['ok'] === true && $varFree[0]['product_id'] === 921, 'free variation of public parent ok');
assert_true($varFree[0]['name'] === 'Var On Pub', 'free mode keeps WC name');

// Pro mode: attribute labels
SkuValidator::setProOverride(true);
$varPro = SkuValidator::resolveCatalog([['sku' => 'SKU-1042', 'qty' => 1]]);
assert_true($varPro[0]['ok'] === true, 'pro variation ok');
assert_true($varPro[0]['name'] === 'SKU-1042 — Large / Blue', 'pro variation attribute labels');

SkuValidator::setProOverride(false);

$ref = new ReflectionClass(QuickOrderController::class);
$src = file_get_contents(dirname(__DIR__) . '/src/Rest/QuickOrderController.php');
assert_true($src !== false && str_contains($src, "'pryviko/v1'"), 'REST namespace pryviko/v1');
assert_true(str_contains($src, 'permission_callback'), 'permission_callback registered');
assert_true(str_contains($src, 'enforce_rate_limit'), 'rate limit present');
assert_true(method_exists(QuickOrderController::class, 'permission_callback'), 'documented public permission_callback');
assert_true(
    method_exists(QuickOrderController::class, 'validate')
    && method_exists(QuickOrderController::class, 'quick_order'),
    'both endpoints present'
);
assert_true(str_contains($src, 'SkuValidator::resolveCatalog'), 'endpoints use resolveCatalog');

echo "visibility-guard ok\n";
