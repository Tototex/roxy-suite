<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Store {
    public static function products_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_products'; }
    public static function vendors_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_vendors'; }
    public static function runs_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_runs'; }
    public static function orders_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_orders'; }

    public static function install_schema(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::products_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, square_variation_id VARCHAR(80) NOT NULL,
            square_item_id VARCHAR(80) NULL, name VARCHAR(190) NOT NULL, sku VARCHAR(190) NULL,
            vendor VARCHAR(100) NULL, tracking_status VARCHAR(20) NOT NULL DEFAULT 'tracked', active TINYINT(1) NOT NULL DEFAULT 1, on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
            pack_size DECIMAL(12,2) NOT NULL DEFAULT 1, reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0,
            target_stock DECIMAL(12,2) NOT NULL DEFAULT 0, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            override_qty DECIMAL(12,2) NULL, calculated_at DATETIME NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY square_variation_id (square_variation_id), KEY vendor (vendor), KEY active (active)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::vendors_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, name VARCHAR(100) NOT NULL, order_method VARCHAR(30) NOT NULL DEFAULT 'email',
            email VARCHAR(190) NULL, minimum_amount DECIMAL(12,2) NOT NULL DEFAULT 0, delivery_notes TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1, updated_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY name (name)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::runs_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, run_type VARCHAR(30) NOT NULL, status VARCHAR(20) NOT NULL,
            message TEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY created_at (created_at)
        ) $charset;");
        dbDelta("CREATE TABLE " . self::orders_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, vendor VARCHAR(100) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'approval_emailed', estimated_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            minimum_amount DECIMAL(12,2) NOT NULL DEFAULT 0, item_count INT NOT NULL DEFAULT 0,
            payload LONGTEXT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY vendor (vendor), KEY created_at (created_at)
        ) $charset;");
        if (!$wpdb->get_var("SHOW COLUMNS FROM " . self::products_table() . " LIKE 'tracking_status'")) {
            $wpdb->query("ALTER TABLE " . self::products_table() . " ADD tracking_status VARCHAR(20) NOT NULL DEFAULT 'tracked' AFTER vendor");
        }
        self::seed_vendors();
        self::apply_vendor_assignments();
        update_option('roxy_inventory_db_version', defined('ROXY_INVENTORY_VER') ? ROXY_INVENTORY_VER : '0.1.0');
    }

    public static function maybe_upgrade_schema(): void {
        if (get_option('roxy_inventory_db_version') !== (defined('ROXY_INVENTORY_VER') ? ROXY_INVENTORY_VER : '0.1.0')) self::install_schema();
    }

    public static function apply_vendor_assignments(): void {
        global $wpdb;
        foreach ((array) $wpdb->get_results("SELECT id,name FROM " . self::products_table() . " WHERE vendor IS NULL OR vendor=''", ARRAY_A) as $product) {
            $vendor = Vendor_Map::assign((string) $product['name']);
            if ($vendor !== '') $wpdb->update(self::products_table(), ['vendor' => $vendor], ['id' => (int) $product['id']]);
        }
    }

    private static function seed_vendors(): void {
        global $wpdb; $now = current_time('mysql');
        foreach ([
            ['Pepsi Co', 'online', '', 0, 'Thursday delivery. Workbook note: minimum $400 or 25 gallons.'],
            ['Burkes', 'email', 'orders@burkescandy.com', 250, 'Thursday delivery. $5 fee if order is under the minimum.'],
            ['Tripp', 'email', 'erica.schauls@trippdistributing.com', 0, 'Friday delivery.'],
            ['Concession Supply', 'email', 'office@concessionssupply.com', 0, 'Monthly delivery on the first Thursday, or pickup.'],
            ["Roxy's Atomic Freeze", 'email', 'brittany@newportroxy.com', 0, 'Email orders to Brittany.'],
            ['Cash & Carry', 'manual', '', 0, 'Manual pickup.'],
            ['Amazon', 'online', '', 0, 'Tracking-based delivery.'],
            ['Sysco', 'online', 'Farrell.Karen@spk.sysco.com', 0, 'Thursday delivery.'],
            ['Bills', 'manual', '', 0, 'Manual payment or bank order.'],
            ['Vistar', 'online', 'beverly.nacoste@pfgc.com', 1500, 'Tracking-based delivery. Workbook notes tiered fees below $1,500.'],
            ['Dollar Store', 'manual', '', 0, 'Manual pickup.'],
            ['Safeway', 'manual', '', 0, 'Manual pickup. Usually arrives within three days.'],
            ['Odom', 'text', 'Chris.DeBolt@odomcorp.com', 150, 'Tuesday delivery. Order due Monday by 3 PM.'],
            ['Popcorn County', 'email', 'craigwelty@popcorncounty.com', 0, 'Inactive by default until this vendor is needed again.'],
        ] as $v) {
            $active = $v[0] === 'Popcorn County' ? 0 : 1;
            $wpdb->query($wpdb->prepare("INSERT INTO " . self::vendors_table() . " (name,order_method,email,minimum_amount,delivery_notes,active,updated_at) VALUES (%s,%s,%s,%f,%s,%d,%s) ON DUPLICATE KEY UPDATE email=IF(email='',VALUES(email),email), minimum_amount=IF(minimum_amount=0,VALUES(minimum_amount),minimum_amount), delivery_notes=IF(delivery_notes='',VALUES(delivery_notes),delivery_notes), updated_at=VALUES(updated_at)", $v[0], $v[1], $v[2], $v[3], $v[4], $active, $now));
        }
    }

    public static function products(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::products_table() . " WHERE active=1 AND tracking_status='tracked' ORDER BY vendor,name", ARRAY_A) ?: []; }
    public static function unassigned_products(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::products_table() . " WHERE active=1 AND (vendor IS NULL OR vendor='') ORDER BY name", ARRAY_A) ?: []; }
    public static function review_products(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::products_table() . " WHERE active=1 AND ((vendor IS NULL OR vendor='') OR tracking_status='not_tracked') ORDER BY tracking_status DESC, name", ARRAY_A) ?: []; }
    public static function vendors(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::vendors_table() . " WHERE active=1 ORDER BY name", ARRAY_A) ?: []; }
    public static function all_vendors(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::vendors_table() . " ORDER BY active DESC, name", ARRAY_A) ?: []; }
    public static function upsert_product(array $p): void {
        global $wpdb; $now = current_time('mysql');
        $data = [
            'square_variation_id' => sanitize_text_field($p['square_variation_id']), 'square_item_id' => sanitize_text_field($p['square_item_id'] ?? ''),
            'name' => sanitize_text_field($p['name']), 'sku' => sanitize_text_field($p['sku'] ?? ''), 'active' => 1, 'on_hand' => (float) ($p['on_hand'] ?? 0),
            'calculated_at' => sanitize_text_field($p['calculated_at'] ?? ''), 'updated_at' => $now,
        ];
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::products_table() . " WHERE square_variation_id=%s", $data['square_variation_id']), ARRAY_A);
        if ($existing) {
            if (trim((string) ($existing['vendor'] ?? '')) === '') {
                $assigned = Vendor_Map::assign((string) $data['name']);
                if ($assigned !== '') $data['vendor'] = $assigned;
            }
            $wpdb->update(self::products_table(), $data, ['id' => $existing['id']]);
        } else {
            $data['vendor'] = Vendor_Map::assign((string) $data['name']);
            $data['pack_size'] = 1;
            $data['reorder_point'] = 0;
            $data['target_stock'] = 0;
            $data['unit_cost'] = 0;
            $wpdb->insert(self::products_table(), $data);
        }
    }
    public static function update_product(int $id, array $data): void { global $wpdb; $wpdb->update(self::products_table(), $data, ['id' => $id]); }
    public static function deactivate_missing(array $square_variation_ids): int {
        global $wpdb;
        $ids = array_values(array_filter(array_map('strval', $square_variation_ids)));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $sql = $wpdb->prepare("UPDATE " . self::products_table() . " SET active=0, updated_at=%s WHERE active=1 AND square_variation_id NOT IN ($placeholders)", array_merge([current_time('mysql')], $ids));
        return (int) $wpdb->query($sql);
    }
    public static function update_vendor(int $id, array $data): void { global $wpdb; $wpdb->update(self::vendors_table(), $data, ['id' => $id]); }
    public static function log(string $type, string $status, string $message): void { global $wpdb; $wpdb->insert(self::runs_table(), ['run_type'=>$type,'status'=>$status,'message'=>$message,'created_at'=>current_time('mysql')]); }
    public static function latest_run(string $type): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::runs_table() . " WHERE run_type=%s ORDER BY id DESC LIMIT 1", $type), ARRAY_A); return is_array($row) ? $row : null; }
    public static function create_order(string $vendor, array $lines, float $total, float $minimum, string $status = 'approval_emailed'): int {
        global $wpdb;
        $wpdb->insert(self::orders_table(), ['vendor'=>$vendor,'status'=>$status,'estimated_total'=>round($total,2),'minimum_amount'=>round($minimum,2),'item_count'=>count($lines),'payload'=>wp_json_encode($lines),'created_at'=>current_time('mysql')]);
        return (int) $wpdb->insert_id;
    }
    public static function orders(): array { global $wpdb; return $wpdb->get_results("SELECT * FROM " . self::orders_table() . " ORDER BY created_at DESC, id DESC LIMIT 100", ARRAY_A) ?: []; }
    public static function order(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::orders_table() . " WHERE id=%d", $id), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function update_order_status(int $id, string $status): bool {
        global $wpdb;
        $allowed = [
            'pending_manager' => ['approval_emailed', 'ordered', 'email_failed', 'cancelled'],
            'approval_emailed' => ['ordered', 'rejected', 'cancelled'],
            'ordered' => ['stock_increased', 'cancelled'],
        ];
        $order = self::order($id);
        if (!$order || !in_array($status, $allowed[$order['status']] ?? [], true)) return false;
        return $wpdb->update(self::orders_table(), ['status' => $status], ['id' => $id, 'status' => $order['status']]) === 1;
    }

    public static function update_order_payload(int $id, array $lines): bool {
        global $wpdb;
        return (bool) $wpdb->update(self::orders_table(), ['payload' => wp_json_encode(array_values($lines))], ['id' => $id]);
    }
    public static function open_order_for_vendor(string $vendor): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::orders_table() . " WHERE vendor=%s AND status IN ('pending_manager','approval_emailed','ordered') ORDER BY id DESC LIMIT 1", $vendor), ARRAY_A);
        return is_array($row) ? $row : null;
    }

    public static function mark_stock_increases(): int {
        global $wpdb;
$products = $wpdb->get_results("SELECT square_variation_id,name,on_hand FROM " . self::products_table(), ARRAY_A) ?: [];
        $current = [];
        $by_name = [];
        foreach ($products as $product) {
            $current[(string) $product['square_variation_id']] = (float) $product['on_hand'];
            $by_name[strtolower((string) $product['name'])] = ['square_variation_id' => (string) $product['square_variation_id'], 'on_hand' => (float) $product['on_hand']];
        }
        $orders = $wpdb->get_results("SELECT id,payload FROM " . self::orders_table() . " WHERE status='ordered'", ARRAY_A) ?: [];
        $marked = 0;
        foreach ($orders as $order) {
            $lines = json_decode((string) ($order['payload'] ?? ''), true);
            if (!is_array($lines)) continue;
            $increased = false; $changed = false;
            foreach ($lines as &$line) {
                $variation_id = (string) ($line['square_variation_id'] ?? '');
                if ($variation_id === '') {
                    $name_key = strtolower((string) ($line['product'] ?? ''));
                    if (isset($by_name[$name_key])) {
                        $line['square_variation_id'] = $by_name[$name_key]['square_variation_id'];
                        $line['on_hand'] = $by_name[$name_key]['on_hand'];
                        $variation_id = (string) $line['square_variation_id'];
                        $changed = true;
                    }
                }
                if ($variation_id !== '' && array_key_exists($variation_id, $current) && array_key_exists('on_hand', $line) && $current[$variation_id] > (float) $line['on_hand']) $increased = true;
            }
            unset($line);
            if ($changed) $wpdb->update(self::orders_table(), ['payload' => wp_json_encode($lines)], ['id' => (int) $order['id']]);
            if ($increased && $wpdb->update(self::orders_table(), ['status' => 'stock_increased'], ['id' => (int) $order['id']])) $marked++;
        }
        return $marked;
    }
}
