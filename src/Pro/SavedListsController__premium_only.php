<?php
/**
 * Pro: GET/POST/DELETE /pryviko/v1/lists — per-user saved SKU lists.
 *
 * @package Pryviko
 */

declare(strict_types=1);

namespace Pryviko\QuickOrder\Pro;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SavedListsController__premium_only
{
    public const META_KEY = '_pryviko_saved_lists';
    public const MAX_LISTS = 25;
    public const MAX_LINES = 250;

    public static function register(): void
    {
        add_action(
            'rest_api_init',
            static function (): void {
                register_rest_route(
                    'pryviko/v1',
                    '/lists',
                    [
                        [
                            'methods' => 'GET',
                            'permission_callback' => [self::class, 'permission_callback'],
                            'callback' => [self::class, 'get_lists'],
                        ],
                        [
                            'methods' => 'POST',
                            'permission_callback' => [self::class, 'permission_callback'],
                            'callback' => [self::class, 'save_list'],
                            'args' => [
                                'name' => [
                                    'type' => 'string',
                                    'required' => true,
                                ],
                                'lines' => [
                                    'type' => 'array',
                                    'required' => true,
                                ],
                            ],
                        ],
                        [
                            'methods' => 'DELETE',
                            'permission_callback' => [self::class, 'permission_callback'],
                            'callback' => [self::class, 'delete_list'],
                            'args' => [
                                'id' => [
                                    'type' => 'string',
                                    'required' => true,
                                ],
                            ],
                        ],
                    ]
                );
            }
        );
    }

    public static function permission_callback(): bool
    {
        return is_user_logged_in();
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function get_lists(WP_REST_Request $request)
    {
        unset($request);
        $user_id = (int) get_current_user_id();
        $lists = self::read_lists($user_id);

        return new WP_REST_Response(
            [
                'ok' => true,
                'lists' => array_values($lists),
            ],
            200
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function save_list(WP_REST_Request $request)
    {
        $user_id = (int) get_current_user_id();
        $name = sanitize_text_field((string) $request->get_param('name'));
        if ($name === '') {
            return new WP_Error(
                'pryviko_list_name',
                __('List name is required.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $raw_lines = $request->get_param('lines');
        if (!is_array($raw_lines)) {
            return new WP_Error(
                'pryviko_list_lines',
                __('Lines must be an array.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $lines = [];
        foreach ($raw_lines as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = sanitize_text_field((string) ($row['sku'] ?? ''));
            $qty = max(1, (int) ($row['qty'] ?? 1));
            if ($sku === '') {
                continue;
            }
            $item = ['sku' => $sku, 'qty' => $qty];
            if (!empty($row['note'])) {
                $item['note'] = sanitize_text_field((string) $row['note']);
            }
            $lines[] = $item;
            if (count($lines) >= self::MAX_LINES) {
                break;
            }
        }

        if ($lines === []) {
            return new WP_Error(
                'pryviko_list_empty',
                __('List must contain at least one SKU line.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $lists = self::read_lists($user_id);
        if (count($lists) >= self::MAX_LISTS) {
            return new WP_Error(
                'pryviko_list_limit',
                __('You can save at most 25 lists.', 'pryviko-quick-order'),
                ['status' => 400]
            );
        }

        $id = substr(md5(uniqid((string) $user_id, true)), 0, 12);
        $lists[$id] = [
            'id' => $id,
            'name' => $name,
            'lines' => $lines,
            'updated' => gmdate('c'),
        ];
        self::write_lists($user_id, $lists);

        return new WP_REST_Response(
            [
                'ok' => true,
                'list' => $lists[$id],
                'lists' => array_values($lists),
            ],
            200
        );
    }

    /**
     * @return WP_REST_Response|WP_Error
     */
    public static function delete_list(WP_REST_Request $request)
    {
        $user_id = (int) get_current_user_id();
        $id = sanitize_text_field((string) $request->get_param('id'));
        $lists = self::read_lists($user_id);

        if ($id === '' || !isset($lists[$id])) {
            return new WP_Error(
                'pryviko_list_missing',
                __('List not found.', 'pryviko-quick-order'),
                ['status' => 404]
            );
        }

        unset($lists[$id]);
        self::write_lists($user_id, $lists);

        return new WP_REST_Response(
            [
                'ok' => true,
                'lists' => array_values($lists),
            ],
            200
        );
    }

    /**
     * @return array<string, array{id:string,name:string,lines:list<array{sku:string,qty:int,note?:string}>,updated:string}>
     */
    public static function read_lists(int $user_id): array
    {
        if ($user_id <= 0) {
            return [];
        }
        $raw = get_user_meta($user_id, self::META_KEY, true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $id => $list) {
            if (!is_array($list) || empty($list['name']) || empty($list['lines']) || !is_array($list['lines'])) {
                continue;
            }
            $sid = sanitize_key((string) ($list['id'] ?? $id));
            if ($sid === '') {
                continue;
            }
            $out[$sid] = [
                'id' => $sid,
                'name' => sanitize_text_field((string) $list['name']),
                'lines' => array_slice($list['lines'], 0, self::MAX_LINES),
                'updated' => (string) ($list['updated'] ?? ''),
            ];
            if (count($out) >= self::MAX_LISTS) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $lists
     */
    public static function write_lists(int $user_id, array $lists): void
    {
        if ($user_id <= 0) {
            return;
        }
        update_user_meta($user_id, self::META_KEY, $lists);
    }
}
