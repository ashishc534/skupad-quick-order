<?php
/**
 * Parse pasted SKU / CSV / TSV / space-separated / messy email-WhatsApp lines.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Parser;

final class LineParser
{
    /** Soft cap aligned with Pro CSV row limit. */
    public const MAX_LINES = 500;

    /**
     * Parse raw paste text into SKU/qty rows.
     *
     * Accepted formats (one product per line):
     * - SKU,qty / SKU;qty / SKU\tqty / SKU qty / SKU x Qty
     * - QTYx SKU  (e.g. 3x ABC-100)
     * - SKU (QTY) (e.g. ABC-100 (3))
     * - QTY of SKU (e.g. 3 of ABC-100)
     * - SKU alone (qty defaults to 1)
     *
     * Lines starting with # are comments. A header row of "sku,qty" is skipped.
     * Conversational prose without a recognizable SKU pattern is ignored.
     *
     * @return list<array{sku:string,qty:int,note?:string}>
     */
    public static function parse(string $raw): array
    {
        $raw = str_replace("\0", '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $out = [];
        $accepted = 0;

        foreach ($lines as $index => $line) {
            if ($accepted >= self::MAX_LINES) {
                break;
            }

            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if ($index === 0 && self::isHeader($line)) {
                continue;
            }

            $row = self::parseLine($line);
            if ($row === null) {
                continue;
            }

            $out[] = $row;
            $accepted++;
        }

        return self::mergeDuplicates($out);
    }

    /**
     * Encode validated rows into a shareable ?pryviko= query value.
     *
     * @param list<array{sku:string,qty:int}> $rows
     */
    public static function encodeSharePayload(array $rows): string
    {
        $parts = [];
        foreach ($rows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($sku === '') {
                continue;
            }
            $parts[] = $sku . ':' . $qty;
        }
        return implode(',', $parts);
    }

    /**
     * Decode ?pryviko=SKU1:QTY1,SKU2:QTY2 into rows.
     *
     * @return list<array{sku:string,qty:int}>
     */
    public static function decodeSharePayload(string $payload): array
    {
        $payload = trim(str_replace("\0", '', $payload));
        if ($payload === '') {
            return [];
        }

        $out = [];
        $chunks = explode(',', $payload);
        foreach ($chunks as $chunk) {
            if (count($out) >= self::MAX_LINES) {
                break;
            }
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }
            if (str_contains($chunk, ':')) {
                [$sku, $qtyRaw] = array_pad(explode(':', $chunk, 2), 2, '1');
            } else {
                $sku = $chunk;
                $qtyRaw = '1';
            }
            $sku = trim($sku);
            $qty = is_numeric($qtyRaw) ? (int) $qtyRaw : 1;
            if ($sku === '' || $qty < 1) {
                continue;
            }
            $out[] = ['sku' => $sku, 'qty' => $qty];
        }

        return self::mergeDuplicates($out);
    }

    /**
     * @return array{sku:string,qty:int}|null
     */
    private static function parseLine(string $line): ?array
    {
        // QTYx SKU  /  QTY x SKU
        if (preg_match('/^(\d+)\s*[x×]\s*([A-Za-z0-9._\-\/]+)\b/iu', $line, $m)) {
            return ['sku' => $m[2], 'qty' => max(1, (int) $m[1])];
        }

        // SKU (QTY)
        if (preg_match('/^([A-Za-z0-9._\-\/]+)\s*\(\s*(\d+)\s*\)\s*$/u', $line, $m)) {
            return ['sku' => $m[1], 'qty' => max(1, (int) $m[2])];
        }

        // QTY of SKU
        if (preg_match('/^(\d+)\s+of\s+([A-Za-z0-9._\-\/]+)\b/iu', $line, $m)) {
            return ['sku' => $m[2], 'qty' => max(1, (int) $m[1])];
        }

        // SKU x Qty  (SKU first)
        if (preg_match('/^([A-Za-z0-9._\-\/]+)\s*[x×]\s*(\d+)\b/iu', $line, $m)) {
            return ['sku' => $m[1], 'qty' => max(1, (int) $m[2])];
        }

        // Structured: SKU,qty / SKU;qty / TSV / space-separated (SKU alone = qty 1).
        // Multi-word lines without a numeric qty are treated as conversational prose.
        $parts = preg_split('/[\s,;\t]+/', $line) ?: [];
        if ($parts === []) {
            return null;
        }

        $sku = trim((string) ($parts[0] ?? ''));
        if ($sku === '' || !preg_match('/^[A-Za-z0-9._\-\/]+$/', $sku)) {
            return null;
        }

        if (count($parts) === 1) {
            return ['sku' => $sku, 'qty' => 1];
        }

        if (!is_numeric($parts[1])) {
            return null;
        }

        $qty = (int) $parts[1];
        if ($qty < 1) {
            return null;
        }

        return ['sku' => $sku, 'qty' => $qty];
    }

    private static function isHeader(string $line): bool
    {
        $normalized = strtolower(preg_replace('/[\s,;\t]+/', ',', $line) ?? '');
        return in_array(
            $normalized,
            ['sku,qty', 'sku,quantity', 'product_sku,qty', 'sku,qty,note'],
            true
        );
    }

    /**
     * @param list<array{sku:string,qty:int,note?:string}> $rows
     * @return list<array{sku:string,qty:int,note?:string}>
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
            if (!empty($row['note']) && empty($merged[$key]['note'])) {
                $merged[$key]['note'] = $row['note'];
            }
        }
        return array_values($merged);
    }
}
