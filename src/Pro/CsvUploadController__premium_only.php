<?php
/**
 * Pro: POST /pryviko/v1/csv-parse — parse uploaded CSV into validated SKU rows.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Pro;

use Pryviko\QuickOrder\Catalog\SkuValidator;
use Pryviko\QuickOrder\Rest\QuickOrderController;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class CsvUploadController__premium_only
{
    public const MAX_BYTES = 524288; // 512KB
    public const MAX_ROWS = 500;

    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'pryviko/v1',
                    '/csv-parse',
                    [
                        'methods' => 'POST',
                        'permission_callback' => [QuickOrderController::class, 'permission_callback'],
                        'callback' => [self::class, 'parse_csv'],
                    ]
                );
            }
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function parse_csv(WP_REST_Request $request)
    {
        $limited = QuickOrderController::enforce_rate_limit();
        if (is_wp_error($limited)) {
            return $limited;
        }

        $files = $request->get_file_params();
        $file = $files['file'] ?? null;

        if (!is_array($file) || empty($file['tmp_name'])) {
            // Also accept raw CSV text body.
            $raw = (string) $request->get_param('csv');
            if ($raw === '') {
                return new WP_Error(
                    'pryviko_csv_missing',
                    __('No CSV file uploaded.', 'pryviko-quick-order'),
                    ['status' => 400]
                );
            }
            return self::resolveFromText($raw);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return new WP_Error(
                'pryviko_csv_size',
                __('CSV must be under 512KB.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $name = (string) ($file['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = strtolower((string) ($file['type'] ?? ''));
        $allowed_ext = ['csv', 'txt'];
        $allowed_mime = [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'application/octet-stream',
            '',
        ];

        if (!in_array($ext, $allowed_ext, true) || !in_array($mime, $allowed_mime, true)) {
            return new WP_Error(
                'pryviko_csv_type',
                __('Only CSV or plain-text uploads are allowed.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $tmp = (string) $file['tmp_name'];
        if (!is_uploaded_file($tmp) && !is_readable($tmp)) {
            return new WP_Error(
                'pryviko_csv_tmp',
                __('Could not read uploaded file.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $raw = (string) file_get_contents($tmp);
        return self::resolveFromText($raw);
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    private static function resolveFromText(string $raw)
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return new WP_Error(
                'pryviko_csv_size',
                __('CSV must be under 512KB.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $rows = self::parseCsvRows($raw);
        if (count($rows) > self::MAX_ROWS) {
            return new WP_Error(
                'pryviko_csv_rows',
                __('CSV may contain at most 500 data rows.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $lines = SkuValidator::resolveCatalog($rows);

        return new WP_REST_Response(
            [
                'ok' => true,
                'count' => count($lines),
                'valid' => count(array_filter($lines, static fn (array $l): bool => !empty($l['ok']))),
                'lines' => $lines,
            ],
            200
        );
    }

    /**
     * @return list<array{sku:string,qty:int,note?:string}>
     */
    public static function parseCsvRows(string $raw): array
    {
        $raw = str_replace("\0", '', $raw);
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        $out = [];
        $row_index = 0;

        foreach ($lines as $i => $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $cols = str_getcsv($line, ',', '"', '\\');
            if ($cols === [null] || $cols === false) {
                continue;
            }

            $normalized = strtolower(implode(',', array_map('trim', $cols)));
            if (
                $i === 0
                && in_array(
                    $normalized,
                    ['sku,qty', 'sku,quantity', 'sku,qty,note', 'product_sku,qty'],
                    true
                )
            ) {
                continue;
            }

            if ($row_index >= self::MAX_ROWS) {
                break;
            }

            $sku = trim((string) ($cols[0] ?? ''));
            $qty = isset($cols[1]) && is_numeric($cols[1]) ? (int) $cols[1] : 1;
            $note = isset($cols[2]) ? sanitize_text_field((string) $cols[2]) : '';

            if ($sku === '' || $qty < 1) {
                continue;
            }

            $row = ['sku' => $sku, 'qty' => $qty];
            if ($note !== '') {
                $row['note'] = $note;
            }
            $out[] = $row;
            $row_index++;
        }

        return $out;
    }
}
