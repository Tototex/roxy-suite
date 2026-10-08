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
        $deadline = microtime(true) + 120; $pages = 0;
        $settings = \RoxyGrosses\Settings::get_all(); $locations = array_values(array_unique(\RoxyGrosses\Settings::line_list((string) ($settings['square_location_ids'] ?? ''))));
        if (!$locations) throw new \RuntimeException('Add at least one Square location ID in Grosses → Settings.');
        foreach ($locations as $location) self::identity($location);
        $objects = self::list_catalog($deadline, $pages); $items = [];
        foreach ($objects as $object) {
            if (($object['type'] ?? '') !== 'ITEM' || !is_array($object['item_data'] ?? null)) throw new \RuntimeException('Square returned an invalid catalog item. No inventory was changed.');
            $item_id = self::identity($object['id'] ?? null); $item_name = $object['item_data']['name'] ?? null;
            if (!is_string($item_name) || trim($item_name) === '') throw new \RuntimeException('Square returned an item without a name.');
            foreach ((array) ($object['item_data']['variations'] ?? []) as $variation) {
                if (!is_array($variation) || ($variation['type'] ?? '') !== 'ITEM_VARIATION' || !is_array($variation['item_variation_data'] ?? null)) throw new \RuntimeException('Square returned an invalid item variation.');
                $id = self::identity($variation['id'] ?? null);
                if (isset($items[$id]) || (isset($variation['item_variation_data']['item_id']) && $variation['item_variation_data']['item_id'] !== $item_id)) throw new \RuntimeException('Square returned a duplicate or misplaced variation.');
                foreach (['name','sku'] as $field) if (isset($variation['item_variation_data'][$field]) && !is_string($variation['item_variation_data'][$field])) throw new \RuntimeException('Square returned invalid variation text.');
                $items[$id] = self::product_from_variation($variation, $item_id, $item_name);
                if (count($items) > 20000) throw new \RuntimeException('Square catalog exceeded the inventory safety limit.');
            }
        }
        foreach (array_chunk(array_keys($items), 1000) as $ids) {
            $counts = []; $cursor = null; $seen_cursors = []; $seen_counts = [];
            do {
                self::page_budget($deadline, $pages);
                $body = ['catalog_object_ids'=>$ids,'location_ids'=>$locations,'states'=>['IN_STOCK'],'limit'=>1000];
                if ($cursor !== null) $body['cursor'] = $cursor;
                $data = self::request('POST', '/v2/inventory/counts/batch-retrieve', $body, $deadline);
                foreach ((array) ($data['counts'] ?? []) as $count) {
                    if (!is_array($count) || !is_string($count['state'] ?? null)) throw new \RuntimeException('Square returned an invalid inventory count.');
                    if (($count['state'] ?? '') !== 'IN_STOCK') continue;
                    $id = self::identity($count['catalog_object_id'] ?? null);
                    $location = self::identity($count['location_id'] ?? null);
                    $key = $id . '|' . $location;
                    if (!in_array($id, $ids, true) || !in_array($location, $locations, true) || isset($seen_counts[$key])) throw new \RuntimeException('Square returned an unexpected or duplicate inventory count.');
                    $quantity = $count['quantity'] ?? null;
                    if (!is_string($quantity) || strlen($quantity) > 26 || !preg_match('/^-?\d+(?:\.\d{1,5})?$/D', $quantity)) throw new \RuntimeException('Square returned an invalid stock quantity.');
                    $seen_counts[$key] = true;
                    $counts[$id] = ($counts[$id] ?? 0) + (float) $quantity;
                    if (!is_finite($counts[$id]) || abs($counts[$id]) > 9999999999.99) throw new \RuntimeException('Square stock exceeds the inventory storage limit.');
                }
                $cursor = self::next_cursor($data, $seen_cursors);
            } while ($cursor !== null);
            foreach ($ids as $id) $items[$id]['on_hand'] = $counts[$id] ?? 0;
        }
        // Finish retrieving every inventory page before changing stored stock.
        if (microtime(true) >= $deadline) throw new \RuntimeException('Square inventory retrieval timed out. No partial pull was saved.');
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

    private static function list_catalog(float $deadline, int &$pages): array {
        $all = []; $cursor = null; $seen_cursors = []; $seen_items = [];
        do {
            self::page_budget($deadline, $pages);
            $path = '/v2/catalog/list?types=ITEM'; if ($cursor !== null) $path .= '&cursor=' . rawurlencode($cursor);
            $data = self::request('GET', $path, null, $deadline);
            foreach ($data['objects'] ?? [] as $o) {
                $id = self::identity($o['id'] ?? null);
                if (isset($seen_items[$id])) throw new \RuntimeException('Square repeated a catalog item.');
                $seen_items[$id] = true; $all[] = $o;
                if (count($all) > 10000) throw new \RuntimeException('Square catalog exceeded the inventory safety limit.');
            }
            $cursor = self::next_cursor($data, $seen_cursors);
        } while ($cursor !== null);
        return $all;
    }

    private static function identity($id): string {
        if (!is_string($id) || $id === '' || strlen($id) > 80 || preg_match('/[\x00-\x20|]/', $id)) throw new \RuntimeException('Square returned an invalid inventory identity.');
        return $id;
    }
    private static function page_budget(float $deadline, int &$pages): void {
        if (++$pages > 100 || microtime(true) >= $deadline) throw new \RuntimeException('Square inventory retrieval exceeded its safety limit. No partial pull was saved.');
    }
    private static function next_cursor(array $data, array &$seen): ?string {
        if (!array_key_exists('cursor', $data)) return null;
        $cursor = $data['cursor'];
        if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 10000 || isset($seen[$cursor])) throw new \RuntimeException('Square returned invalid or repeated inventory pagination.');
        $seen[$cursor] = true; return $cursor;
    }

    private static function product_from_variation(array $v, string $item_id, string $item_name): array {
        $d = (array) ($v['item_variation_data'] ?? []); $name = trim($item_name . (!empty($d['name']) ? ' - ' . $d['name'] : ''));
        return ['square_variation_id'=>(string) $v['id'],'square_item_id'=>$item_id,'name'=>$name,'sku'=>(string) ($d['sku'] ?? ''),'calculated_at'=>current_time('mysql')];
    }

    private static function request(string $method, string $path, array $body = null, ?float $deadline = null): array {
        $settings = \RoxyGrosses\Settings::get_all(); $token = \RoxyGrosses\Settings::square_access_token(); if ($token === '') throw new \RuntimeException('Add a Square access token in Grosses → Settings.');
        $base = (($settings['square_environment'] ?? 'production') === 'sandbox') ? 'https://connect.squareupsandbox.com' : 'https://connect.squareup.com';
        $remaining = $deadline === null ? 35 : $deadline - microtime(true);
        if ($remaining <= 0) throw new \RuntimeException('Square inventory retrieval timed out.');
        $args = ['headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json','Accept'=>'application/json','Square-Version'=>self::API_VERSION],'timeout'=>min(35, $remaining)]; if ($body !== null) $args['body'] = wp_json_encode($body);
        $response = strtoupper($method) === 'GET' ? wp_remote_get($base.$path,$args) : wp_remote_post($base.$path,$args); if (is_wp_error($response)) throw new \RuntimeException($response->get_error_message());
        $code = (int) wp_remote_retrieve_response_code($response); $raw = wp_remote_retrieve_body($response);
        $native = json_decode($raw);
        if ($code < 200 || $code >= 300 || !($native instanceof \stdClass) || json_last_error() !== JSON_ERROR_NONE) throw new \RuntimeException('Square inventory request failed or returned invalid JSON.');
        if (property_exists($native, 'errors') && (!is_array($native->errors) || $native->errors)) throw new \RuntimeException('Square reported an inventory request error.');
        $field = strpos($path, '/v2/catalog/list') === 0 ? 'objects' : 'counts';
        if (!property_exists($native, $field) && array_diff(array_keys(get_object_vars($native)), ['errors','cursor'])) throw new \RuntimeException('Square returned an unrecognized inventory response.');
        if (property_exists($native, $field)) {
            if (!is_array($native->$field)) throw new \RuntimeException('Square returned an invalid inventory collection.');
            foreach ($native->$field as $entry) {
                if (!($entry instanceof \stdClass)) throw new \RuntimeException('Square returned an invalid inventory record.');
                if ($field === 'objects') {
                    if (!isset($entry->item_data) || !($entry->item_data instanceof \stdClass)) throw new \RuntimeException('Square returned invalid catalog item data.');
                    if (property_exists($entry->item_data, 'variations')) {
                        if (!is_array($entry->item_data->variations)) throw new \RuntimeException('Square returned invalid catalog variations.');
                        foreach ($entry->item_data->variations as $v) if (!($v instanceof \stdClass) || !isset($v->item_variation_data) || !($v->item_variation_data instanceof \stdClass)) throw new \RuntimeException('Square returned invalid variation data.');
                    }
                }
            }
        }
        $data = json_decode($raw, true);
        return $data;
    }
}
