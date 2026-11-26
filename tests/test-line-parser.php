<?php
/**
 * PHPUnit-lite smoke tests (no WordPress bootstrap required).
 *
 * Run: php tests/test-line-parser.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Parser/LineParser.php';
require_once dirname(__DIR__) . '/src/Catalog/SkuValidator.php';

use Pryviko\QuickOrder\Catalog\SkuValidator;
use Pryviko\QuickOrder\Parser\LineParser;

function assert_true(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

$parsed = LineParser::parse("ABC-1,2\nXYZ 3\n# comment\n!!!,1");
assert_true(count($parsed) === 2, 'expected 2 sanitized-ready rows (ABC, XYZ); !!! fails SKU shape');

$valid = SkuValidator::validate($parsed);
assert_true(count($valid) === 2, 'expected 2 sanitized rows');
assert_true($valid[0]['sku'] === 'ABC-1' && $valid[0]['qty'] === 2, 'first row qty');
assert_true($valid[1]['sku'] === 'XYZ' && $valid[1]['qty'] === 3, 'second row qty');

$merged = LineParser::parse("AA-1,2\nAA-1,3");
assert_true(count($merged) === 1 && $merged[0]['qty'] === 5, 'duplicate SKUs merge qty');

$header = LineParser::parse("sku,qty\nPROD-9,4");
assert_true(count($header) === 1 && $header[0]['sku'] === 'PROD-9', 'header row skipped');

$tsv = LineParser::parse("SKU-T\t7");
assert_true($tsv[0]['sku'] === 'SKU-T' && $tsv[0]['qty'] === 7, 'TSV parse');

// Smart paste: QTYx SKU, SKU (QTY), QTY of SKU, ignore prose
$messy = LineParser::parse(
    "Hi team, please send:\n" .
    "3x ABC-100\n" .
    "Thanks!\n" .
    "DEF-200 (2)\n" .
    "5 of GHI-300\n" .
    "Can you also check stock?\n" .
    "JKL-400 x 4"
);
assert_true(count($messy) === 4, 'messy email/WhatsApp extracts 4 SKUs');
$bySku = [];
foreach ($messy as $row) {
    $bySku[$row['sku']] = $row['qty'];
}
assert_true(($bySku['ABC-100'] ?? 0) === 3, '3x SKU');
assert_true(($bySku['DEF-200'] ?? 0) === 2, 'SKU (QTY)');
assert_true(($bySku['GHI-300'] ?? 0) === 5, 'QTY of SKU');
assert_true(($bySku['JKL-400'] ?? 0) === 4, 'SKU x Qty');

// Share encode / decode
$encoded = LineParser::encodeSharePayload([
    ['sku' => 'A-1', 'qty' => 2],
    ['sku' => 'B-2', 'qty' => 5],
]);
assert_true($encoded === 'A-1:2,B-2:5', 'share encode');
$decoded = LineParser::decodeSharePayload($encoded);
assert_true(count($decoded) === 2 && $decoded[0]['sku'] === 'A-1' && $decoded[0]['qty'] === 2, 'share decode');
assert_true($decoded[1]['sku'] === 'B-2' && $decoded[1]['qty'] === 5, 'share decode qty2');

// Max line cap
$many = '';
for ($i = 0; $i < 600; $i++) {
    $many .= "SKU-{$i},1\n";
}
$capped = LineParser::parse($many);
assert_true(count($capped) === LineParser::MAX_LINES, 'max line cap honored');

echo "phpunit-lite ok\n";
