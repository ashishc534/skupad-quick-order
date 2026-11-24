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
assert_true(count($parsed) === 3, 'expected 3 parsed rows before sanitize (ABC, XYZ, !!!)');

$valid = SkuValidator::validate($parsed);
assert_true(count($valid) === 2, 'expected 2 sanitized rows (!!! stripped to empty)');
assert_true($valid[0]['sku'] === 'ABC-1' && $valid[0]['qty'] === 2, 'first row qty');
assert_true($valid[1]['sku'] === 'XYZ' && $valid[1]['qty'] === 3, 'second row qty');

$merged = LineParser::parse("AA-1,2\nAA-1,3");
assert_true(count($merged) === 1 && $merged[0]['qty'] === 5, 'duplicate SKUs merge qty');

$header = LineParser::parse("sku,qty\nPROD-9,4");
assert_true(count($header) === 1 && $header[0]['sku'] === 'PROD-9', 'header row skipped');

$tsv = LineParser::parse("SKU-T\t7");
assert_true($tsv[0]['sku'] === 'SKU-T' && $tsv[0]['qty'] === 7, 'TSV parse');

echo "phpunit-lite ok\n";
