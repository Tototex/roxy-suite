<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Square {
    private const API_VERSION = '2026-09-16';

    public static function pull(): array {
        return Store::with_lock('pull', static function () { return self::retrieve_and_commit(); });
    }
    private static function retrieve_and_commit(): array {
        if (!class_exists('\\RoxyGrosses\\Settings')) throw new \RuntimeException('Enable Grosses or configure the shared Square connection first.');
        $settings = \RoxyGrosses\Settings::get_all(); $locations = \RoxyGrosses\Settings::line_list((string) ($settings['square_location_ids'] ?? ''));
        if (!$locations) throw new \RuntimeException('Add at least one Square location ID in Grosses → Settings.');
        $objects = self::list_catalog(); $items = [];
        foreach ($objects as $object) {
            if (($object['type'] ?? '') !== 'ITEM') continue;
            $item_id = (string) ($object['id'] ?? ''); $item_name = (string) ($object['item_data']['name'] ?? '');
            foreach ((array) ($object['item_data']['variations'] ?? []) as $variation) {
                if (($variation['type'] ?? '') !== 'ITEM_VARIATION') continue;
                $items[(string) $variation['id']] = self::product_from_variation($variation, $item_id, $item_name);
            }
        }
        foreach (array_chunk(array_keys($items), 1000) as $ids) {
            $counts = []; $cursor = null;
            do {
                $body = ['catalog_object_ids'=>$ids,'location_ids'=>$locations,'states'=>['IN_STOCK'],'limit'=>1000];
                if ($cursor !== null) $body['cursor'] = $cursor;
                $data = self::request('POST', '/v2/inventory/counts/batch-retrieve', $body);
                foreach ((array) ($data['counts'] ?? []) as $count) {
                    if (($count['state'] ?? '') !== 'IN_STOCK') continue;
                    $id = (string) ($count['catalog_object_id'] ?? '');
                    $counts[$id] = ($counts[$id] ?? 0) + (float) ($count['quantity'] ?? 0);
                }
                $cursor = !empty($data['cursor']) ? (string) $data['cursor'] : null;
            } while ($cursor !== null);
            foreach ($ids as $id) $items[$id]['on_hand'] = $counts[$id] ?? 0;
        }
        // Finish retrieving every inventory page before changing stored stock.
        [$deactivated,$reset] = Store::transaction(static function () use ($items) {
            $previous=Store::stock_snapshot();
            foreach ($items as $item) Store::upsert_product($item);
            $deactivated = Store::deactivate_missing(array_keys($items));
            $reset = Store::mark_stock_increases($previous);
            if (!Store::log('pull', 'success', count($items) . ' Square variations pulled; ' . $deactivated . ' missing variations deactivated; ' . $reset . ' orders reset after stock increases.')) {
                throw new \RuntimeException('The pull activity could not be saved. Inventory changes were rolled back; please retry.');
            }
            return [$deactivated, $reset];
        });
        return $items;
    }

    private static function list_catalog(): array {
        $all = []; $cursor = null;
        do { $path = '/v2/catalog/list?types=ITEM'; if ($cursor) $path .= '&cursor=' . rawurlencode($cursor); $data = self::request('GET', $path); foreach ((array) ($data['objects'] ?? []) as $o) if (is_array($o)) $all[] = $o; $cursor = !empty($data['cursor']) ? (string) $data['cursor'] : null; } while ($cursor);
        return $all;
    }

    private static function product_from_variation(array $v, string $item_id, string $item_name): array {
        $d = (array) ($v['item_variation_data'] ?? []); $name = trim($item_name . (!empty($d['name']) ? ' - ' . $d['name'] : ''));
        return ['square_variation_id'=>(string) $v['id'],'square_item_id'=>$item_id,'name'=>$name,'sku'=>(string) ($d['sku'] ?? ''),'calculated_at'=>current_time('mysql')];
    }

    private static function request(string $method, string $path, array $body = null): array {
        $settings = \RoxyGrosses\Settings::get_all(); $token = \RoxyGrosses\Settings::square_access_token(); if ($token === '') throw new \RuntimeException('Add a Square access token in Grosses → Settings.');
        $base = (($settings['square_environment'] ?? 'production') === 'sandbox') ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
        $args = ['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json','Square-Version'=>self::API_VERSION],'timeout'=>35]; if ($body !== null) $args['body'] = wp_json_encode($body);
        $response = strtoupper($method) === 'GET' ? wp_remote_get($base.$path,$args) : wp_remote_post($base.$path,$args); if (is_wp_error($response)) throw new \RuntimeException($response->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($response); $data = json_decode(wp_remote_retrieve_body($response), true); if ($code < 200 || $code >= 300) throw new \RuntimeException((string) ($data['errors'][0]['detail'] ?? 'Square request failed.'));
        if (!is_array($data) || !empty($data['errors'])) throw new \RuntimeException((string) ($data['errors'][0]['detail'] ?? 'Square returned an invalid response.'));
        return $data;
    }
}
