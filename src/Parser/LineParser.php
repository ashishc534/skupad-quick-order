<?php
/**
 * Parse pasted SKU / CSV / TSV / space-separated quick-order lines.
 *
 * @package SkuPad
 */

declare(strict_types=1);

namespace OpenJarvis\SkuPad\Parser;

final class LineParser
{
    /**
     * Parse raw paste text into SKU/qty rows.
     *
     * Accepted formats (one product per line):
     * - SKU,qty
     * - SKU;qty
     * - SKU\tqty
     * - SKU qty
     * - SKU          (qty defaults to 1)
     *
     * Lines starting with # are comments. A header row of "sku,qty" is skipped.
     *
     * @return list<array{sku:string,qty:int}>
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\0", '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $out = [];

        foreach ($lines as $index => $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($index === 0 && self::isHeader($line)) {
                continue;
            }

            $parts = preg_split('/[\s,;\t]+/', $line) ?: [];
            $sku = trim((string) ($parts[0] ?? ''));
            $qty = isset($parts[1]) && is_numeric($parts[1]) ? (int) $parts[1] : 1;

            if ($sku === '' || $qty < 1) {
                continue;
            }

            $out[] = [
                'sku' => $sku,
                'qty' => $qty,
            ];
        }

        return self::mergeDuplicates($out);
    }

    private static function isHeader(string $line): bool
    {
        $normalized = strtolower(preg_replace('/[\s,;\t]+/', ',', $line) ?? '');
        return in_array($normalized, ['sku,qty', 'sku,quantity', 'product_sku,qty'], true);
    }

    /**
     * @param list<array{sku:string,qty:int}> $rows
     * @return list<array{sku:string,qty:int}>
     */
    private static function mergeDuplicates(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $key = strtoupper($row['sku']);
            if (!isset($merged[$key])) {
                $merged[$key] = $row;
                continue;
            }
            $merged[$key]['qty'] += $row['qty'];
        }
        return array_values($merged);
    }
}
