<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Store {
    private static int $transaction_depth = 0;
    private static array $lock_owners = [];
    private static function lock_predicate(): string {
        global $wpdb;
        $parts=[];
        foreach(self::$lock_owners as $claim) $parts[]=$wpdb->prepare('(CONNECTION_ID()=%d AND IS_USED_LOCK(%s)=%d)', $claim['owner'], $claim['key'], $claim['owner']);
        return $parts ? implode(' AND ', $parts) : '0';
    }
    private static function assert_owner(): void {
        global $wpdb;
        if (self::$lock_owners && (string)$wpdb->get_var('SELECT IF('.self::lock_predicate().',1,0)') !== '1') throw new \RuntimeException('Inventory connection or lock ownership was lost. Refresh and review changes before retrying.');
    }
    /** Predicate executes with the write, including wpdb retry after a reconnect. */
    public static function guard_transaction_query(string $sql): string {
        if (!self::$transaction_depth || !preg_match('/^\s*(?:INSERT|UPDATE|DELETE|REPLACE|ALTER|DROP|TRUNCATE|CREATE)\b/i', $sql)) return $sql;
        $tables=[self::products_table(),self::vendors_table(),self::orders_table(),self::runs_table()];
        $pattern=implode('|',array_map(static fn($t)=>preg_quote($t,'/'),$tables));
        if (!preg_match('/^\s*(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|DROP\s+TABLE|TRUNCATE\s+(?:TABLE\s+)?|CREATE\s+TABLE)\s+`?(?:'.$pattern.')`?(?=[\s(])/i', $sql)) return $sql;
        self::assert_owner();
        $sql=rtrim(trim($sql),';'); $predicate=self::lock_predicate();
        if (preg_match('/^(INSERT\s+INTO\s+`?(?:'.$pattern.')`?\s*\([^)]*\))\s+VALUES\s*\(([\s\S]*)\)$/i',$sql,$match)) return $match[1].' SELECT '.$match[2].' FROM DUAL WHERE '.$predicate;
        if (preg_match('/^(?:UPDATE|DELETE)\b/i',$sql)) {
            $where=self::where_offset($sql);
            if ($where !== null) return substr($sql,0,$where+5).' ('.substr($sql,$where+5).') AND ('.$predicate.')';
        }
        throw new \RuntimeException('Unsupported inventory write inside a protected save. Nothing further was written.');
    }
    private static function where_offset(string $sql): ?int {
        // Ignore WHERE appearing in names/payload string literals; group the
        // actual predicate so an OR cannot bypass connection ownership.
        $quote=null; $length=strlen($sql);
        for($i=0;$i<$length;$i++) {
            $c=$sql[$i];
            if($quote!==null) {
                if($c==='\\') { ++$i; continue; }
                if($c===$quote) { if($i+1<$length && $sql[$i+1]===$quote) ++$i; else $quote=null; }
                continue;
            }
            if($c==="'" || $c==='"' || $c==='`') { $quote=$c; continue; }
            if(($i===0 || ctype_space($sql[$i-1])) && strncasecmp(substr($sql,$i,5),'WHERE',5)===0 && ($i+5===$length || ctype_space($sql[$i+5]) || $sql[$i+5]==='(')) return $i;
        }
        return null;
    }
    public static function with_lock(string $resource, callable $callback) {
        global $wpdb;
        $key = 'roxy_inv_' . substr(hash('sha256', self::products_table() . '|' . $resource), 0, 48);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== '1') {
            throw new \RuntimeException('Inventory is busy. Please refresh and try again.');
        }
        $owner=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
        if (!$owner) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key)); throw new \RuntimeException('Inventory lock ownership could not be established.'); }
        self::$lock_owners[]=['key'=>$key,'owner'=>$owner];
        try { self::assert_owner(); $result=$callback(); self::assert_owner(); return $result; }
        finally {
            array_pop(self::$lock_owners);
            if ((int)$wpdb->get_var('SELECT CONNECTION_ID()')===$owner && (int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$key))===$owner) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }
    public static function transaction(callable $callback) {
        global $wpdb;
        if (self::$transaction_depth > 0) { self::assert_owner(); return $callback(); }
        return self::with_lock('state', static function () use ($callback, $wpdb) {
            if ((string)$wpdb->get_var('SELECT @@SESSION.autocommit')!=='1') throw new \RuntimeException('Another database transaction is active. Inventory was not saved.');
            $probe='roxy_inv_probe_'.bin2hex(random_bytes(8));
            self::checked_write($wpdb->query('SAVEPOINT '.$probe));
            $errors=$wpdb->suppress_errors(true);
            try { $outer=$wpdb->query('RELEASE SAVEPOINT '.$probe)!==false; }
            finally { $wpdb->suppress_errors($errors); }
            if ($outer) throw new \RuntimeException('Another database transaction is active. Inventory was not saved.');
            self::assert_owner();
            $owner=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
            $started=false;
            self::$transaction_depth++;
            add_filter('query',[__CLASS__,'guard_transaction_query'],PHP_INT_MAX);
            try {
                self::assert_owner();
                $begin=$wpdb->query('START TRANSACTION'); $started=$begin!==false;
                self::checked_write($begin);
                $result = $callback();
                self::assert_owner();
                self::checked_write($wpdb->query('COMMIT'));
                $started=false;
                self::assert_owner();
                return $result;
            } catch (\Throwable $e) {
                // Losing a named lock on the original connection still requires
                // rolling back our transaction; a replacement connection does not.
                try { if ($started && (int)$wpdb->get_var('SELECT CONNECTION_ID()')===$owner) $wpdb->query('ROLLBACK'); } catch (\Throwable $lost) { /* Never roll back an unrelated reconnected session. */ }
                throw $e;
            }
            finally { remove_filter('query',[__CLASS__,'guard_transaction_query'],PHP_INT_MAX); self::$transaction_depth--; }
        });
    }
    private static function checked_write($result): void {
        if ($result === false) throw new \RuntimeException('Inventory could not be saved. No success is being reported; please refresh and retry.');
        self::assert_owner();
    }
    private static function checked_read(): void {
        global $wpdb;
        if (!empty($wpdb->last_error)) throw new \RuntimeException('Inventory could not be read. Please refresh and retry.');
        self::assert_owner();
    }
    private static function rows(string $sql): array {
        global $wpdb; $rows=$wpdb->get_results($sql,ARRAY_A); self::checked_read();
        if (!is_array($rows)) throw new \RuntimeException('Inventory could not be read.');
        return $rows;
    }
    public static function stock_snapshot(): array {
        $stock=[];
        foreach(self::rows('SELECT square_variation_id,on_hand FROM ' . self::products_table()) as $p) $stock[(string)$p['square_variation_id']]=(float)$p['on_hand'];
        return $stock;
    }
    public static function products_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_products'; }
    public static function vendors_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_vendors'; }
    public static function runs_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_runs'; }
    public static function orders_table(): string { global $wpdb; return $wpdb->prefix . 'roxy_inventory_orders'; }

    private static function ensure_product_column(string $name, string $definition): void {
        global $wpdb;
        $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . self::products_table() . ' LIKE %s', $name));
        self::checked_read();
        if (!$column) self::checked_write($wpdb->query('ALTER TABLE ' . self::products_table() . ' ADD ' . $name . ' ' . $definition));
        $column = $wpdb->get_var($wpdb->prepare('SHOW COLUMNS FROM ' . self::products_table() . ' LIKE %s', $name));
        self::checked_read();
        if (!$column) throw new \RuntimeException('Inventory product schema upgrade did not add the required ' . $name . ' field.');
    }

    public static function install_schema(): void {
        global $wpdb; require_once ABSPATH . 'wp-admin/includes/upgrade.php'; $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE " . self::products_table() . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, square_variation_id VARCHAR(80) NOT NULL,
            square_item_id VARCHAR(80) NULL, name VARCHAR(190) NOT NULL, sku VARCHAR(190) NULL,
            vendor VARCHAR(100) NULL, tracking_status VARCHAR(20) NOT NULL DEFAULT 'tracked', active TINYINT(1) NOT NULL DEFAULT 1, on_hand DECIMAL(12,2) NOT NULL DEFAULT 0,
            pack_size DECIMAL(12,2) NOT NULL DEFAULT 1, reorder_point DECIMAL(12,2) NOT NULL DEFAULT 0,
            target_stock DECIMAL(12,2) NOT NULL DEFAULT 0, unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit_cost_status VARCHAR(20) NOT NULL DEFAULT 'unknown', unit_cost_source VARCHAR(190) NULL,
            unit_cost_checked_at DATE NULL, supplier_sku VARCHAR(190) NULL,
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
            payload LONGTEXT NULL, submission_key VARCHAR(64) NULL, created_at DATETIME NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY submission_key (submission_key), KEY vendor (vendor), KEY created_at (created_at)
        ) $charset;");
        self::ensure_product_column('tracking_status', "VARCHAR(20) NOT NULL DEFAULT 'tracked' AFTER vendor");
        self::ensure_product_column('unit_cost_status', "VARCHAR(20) NOT NULL DEFAULT 'unknown' AFTER unit_cost");
        self::ensure_product_column('unit_cost_source', 'VARCHAR(190) NULL AFTER unit_cost_status');
        self::ensure_product_column('unit_cost_checked_at', 'DATE NULL AFTER unit_cost_source');
        self::ensure_product_column('supplier_sku', 'VARCHAR(190) NULL AFTER unit_cost_checked_at');
        self::migrate_cost_provenance();
        self::seed_vendors();
        if (!get_option('roxy_inventory_db_version')) self::apply_vendor_assignments();
        self::upgrade_submission_identity();
        update_option('roxy_inventory_db_version', defined('ROXY_INVENTORY_VER') ? ROXY_INVENTORY_VER : '0.1.0');
    }

    public static function maybe_upgrade_schema(): void {
        if (get_option('roxy_inventory_db_version') === (defined('ROXY_INVENTORY_VER') ? ROXY_INVENTORY_VER : '0.1.0')) return;
        try { self::install_schema(); }
        catch (\Throwable $e) {
            // A failed optional-module upgrade must not take down ticket checkout.
            error_log('Roxy Inventory schema upgrade failed: ' . $e->getMessage());
            add_action('admin_notices', static function () { echo '<div class="notice notice-error"><p>Inventory schema upgrade did not finish. Existing data is preserved; contact the administrator before submitting orders.</p></div>'; });
        }
    }

    private static function migrate_cost_provenance(): void {
        global $wpdb;
        if (get_option('roxy_inventory_cost_provenance_v1')) return;
        self::checked_write($wpdb->query("UPDATE " . self::products_table() . " SET unit_cost_status='estimate' WHERE unit_cost_status='unknown' AND unit_cost>0"));
        if (!update_option('roxy_inventory_cost_provenance_v1', 1, false) && (int)get_option('roxy_inventory_cost_provenance_v1') !== 1) {
            throw new \RuntimeException('Inventory cost provenance migration marker could not be saved.');
        }
    }

    public static function upgrade_submission_identity(): void {
        global $wpdb;
        $table = self::orders_table();
        $column = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'submission_key'"); self::checked_read();
        if (!$column) self::checked_write($wpdb->query("ALTER TABLE $table ADD COLUMN submission_key VARCHAR(64) NULL"));
        $index = $wpdb->get_row("SHOW INDEX FROM $table WHERE Key_name='submission_key'", ARRAY_A); self::checked_read();
        if (!$index) self::checked_write($wpdb->query("ALTER TABLE $table ADD UNIQUE KEY submission_key (submission_key)"));
        $column = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'submission_key'"); self::checked_read();
        $index = $wpdb->get_row("SHOW INDEX FROM $table WHERE Key_name='submission_key'", ARRAY_A); self::checked_read();
        if (!$column || !$index || (int)$index['Non_unique'] !== 0) throw new \RuntimeException('Inventory submission identity upgrade did not finish.');
    }

    public static function apply_vendor_assignments(): void {
        global $wpdb;
        foreach (self::rows("SELECT id,name FROM " . self::products_table() . " WHERE vendor IS NULL OR vendor=''") as $product) {
            $vendor = Vendor_Map::assign((string) $product['name']);
            if ($vendor !== '') self::checked_write($wpdb->update(self::products_table(), ['vendor' => $vendor], ['id' => (int) $product['id']]));
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
            self::checked_write($wpdb->query($wpdb->prepare("INSERT INTO " . self::vendors_table() . " (name,order_method,email,minimum_amount,delivery_notes,active,updated_at) VALUES (%s,%s,%s,%f,%s,%d,%s) ON DUPLICATE KEY UPDATE name=name", $v[0], $v[1], $v[2], $v[3], $v[4], $active, $now)));
        }
    }

    public static function products(): array { return self::rows("SELECT * FROM " . self::products_table() . " WHERE active=1 AND tracking_status='tracked' ORDER BY vendor,name"); }
    public static function unassigned_products(): array { return self::rows("SELECT * FROM " . self::products_table() . " WHERE active=1 AND (vendor IS NULL OR vendor='') ORDER BY name"); }
    public static function review_products(): array { return self::rows("SELECT * FROM " . self::products_table() . " WHERE active=1 AND ((vendor IS NULL OR vendor='') OR tracking_status='not_tracked') ORDER BY tracking_status DESC, name"); }
    public static function vendors(): array { return self::rows("SELECT * FROM " . self::vendors_table() . " WHERE active=1 ORDER BY name"); }
    public static function all_vendors(): array { return self::rows("SELECT * FROM " . self::vendors_table() . " ORDER BY active DESC, name"); }
    public static function upsert_product(array $p): void {
        global $wpdb; $now = current_time('mysql');
        $data = [
            'square_variation_id' => sanitize_text_field($p['square_variation_id']), 'square_item_id' => sanitize_text_field($p['square_item_id'] ?? ''),
            'name' => sanitize_text_field($p['name']), 'sku' => sanitize_text_field($p['sku'] ?? ''), 'active' => 1, 'on_hand' => (float) ($p['on_hand'] ?? 0),
            'calculated_at' => sanitize_text_field($p['calculated_at'] ?? ''), 'updated_at' => $now,
        ];
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::products_table() . " WHERE square_variation_id=%s", $data['square_variation_id']), ARRAY_A);
        self::checked_read();
        if ($existing) {
            if (trim((string) ($existing['vendor'] ?? '')) === '') {
                $assigned = Vendor_Map::assign((string) $data['name']);
                if ($assigned !== '') $data['vendor'] = $assigned;
            }
            self::checked_write($wpdb->update(self::products_table(), $data, ['id' => $existing['id']]));
        } else {
            $data['vendor'] = Vendor_Map::assign((string) $data['name']);
            $data['pack_size'] = 1;
            $data['reorder_point'] = 0;
            $data['target_stock'] = 0;
            $data['unit_cost'] = 0;
            $data['unit_cost_status'] = 'unknown';
            $data['unit_cost_source'] = null;
            $data['unit_cost_checked_at'] = null;
            $data['supplier_sku'] = '';
            self::checked_write($wpdb->insert(self::products_table(), $data));
        }
    }
    private static function require_row(string $table, int $id): void {
        global $wpdb;
        if ($id < 1) throw new \RuntimeException('Invalid inventory record. Nothing was saved.');
        $found=$wpdb->get_var($wpdb->prepare('SELECT id FROM '.$table.' WHERE id=%d',$id));
        self::checked_read();
        if ($found === null || (int)$found !== $id) throw new \RuntimeException('This inventory record no longer exists. Refresh before saving.');
    }
    public static function update_product(int $id, array $data): void { global $wpdb; self::transaction(static function () use ($id,$data,$wpdb) { self::require_row(self::products_table(),$id); self::checked_write($wpdb->update(self::products_table(), $data, ['id' => $id])); }); }
    public static function deactivate_missing(array $square_variation_ids): int {
        global $wpdb;
        $ids = array_values(array_filter(array_map('strval', $square_variation_ids)));
        if (!$ids) return 0;
        $placeholders = implode(',', array_fill(0, count($ids), '%s'));
        $sql = $wpdb->prepare("UPDATE " . self::products_table() . " SET active=0, updated_at=%s WHERE active=1 AND square_variation_id NOT IN ($placeholders)", array_merge([current_time('mysql')], $ids));
        $result = $wpdb->query($sql); self::checked_write($result); return (int) $result;
    }
    public static function update_vendor(int $id, array $data): void { global $wpdb; self::transaction(static function () use ($id,$data,$wpdb) { self::require_row(self::vendors_table(),$id); self::checked_write($wpdb->update(self::vendors_table(), $data, ['id' => $id])); }); }
    public static function log(string $type, string $status, string $message): bool {
        global $wpdb;
        // Logging follows irreversible email actions too: never disguise a sent
        // email as an unsent one by throwing from this secondary write.
        try {
            if ($wpdb->insert(self::runs_table(), ['run_type'=>$type,'status'=>$status,'message'=>$message,'created_at'=>current_time('mysql')]) !== false) return true;
        } catch (\Throwable $e) { /* Fall back without exposing database details. */ }
        error_log('Roxy Inventory activity log could not be saved (' . $type . '/' . $status . ').');
        return false;
    }
    public static function latest_run(string $type): ?array { global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::runs_table() . " WHERE run_type=%s ORDER BY id DESC LIMIT 1", $type), ARRAY_A); self::checked_read(); return is_array($row) ? $row : null; }
    public static function create_order(string $vendor, array $lines, float $total, float $minimum, string $status = 'approval_emailed', ?string $submission_key = null): int {
        global $wpdb;
        return self::transaction(static function () use ($vendor,$lines,$total,$minimum,$status,$submission_key,$wpdb) {
            if (self::open_order_for_vendor($vendor)) throw new \RuntimeException('An open order already exists for this vendor.');
            self::checked_write($wpdb->insert(self::orders_table(), ['vendor'=>$vendor,'status'=>$status,'estimated_total'=>round($total,2),'minimum_amount'=>round($minimum,2),'item_count'=>count($lines),'payload'=>wp_json_encode($lines),'submission_key'=>$submission_key,'created_at'=>current_time('mysql')]));
            if (!$wpdb->insert_id) throw new \RuntimeException('Could not save this order.');
            return (int) $wpdb->insert_id;
        });
    }
    public static function order_for_submission(string $key): ?array {
        global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::orders_table() . ' WHERE submission_key=%s', $key), ARRAY_A); self::checked_read();
        return is_array($row) ? $row : null;
    }
    private static function history_where(string $search): string {
        global $wpdb;
        return $search === '' ? '' : $wpdb->prepare(' WHERE vendor LIKE %s','%'.$wpdb->esc_like($search).'%');
    }
    public static function orders(int $page = 1, string $search = ''): array {
        $offset=(max(1,$page)-1)*50;
        return self::rows('SELECT * FROM ' . self::orders_table() . self::history_where($search) . ' ORDER BY created_at DESC, id DESC LIMIT 50 OFFSET '.$offset);
    }
    public static function order_count(string $search = ''): int {
        global $wpdb; $count=$wpdb->get_var('SELECT COUNT(*) FROM '.self::orders_table().self::history_where($search)); self::checked_read(); return (int)$count;
    }
    public static function order(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::orders_table() . " WHERE id=%d", $id), ARRAY_A);
        self::checked_read();
        return is_array($row) ? $row : null;
    }

    public static function update_order_status(int $id, string $status): bool {
        global $wpdb;
        return self::transaction(static function () use ($id,$status,$wpdb) {
        $allowed = [
            'pending_manager' => ['approval_emailed', 'ordered', 'email_failed', 'cancelled'],
            'approval_emailed' => ['ordered', 'rejected', 'cancelled'],
            'ordered' => ['stock_increased', 'cancelled'],
        ];
        $order = self::order($id);
        if (!$order || !in_array($status, $allowed[$order['status']] ?? [], true)) return false;
        return $wpdb->update(self::orders_table(), ['status' => $status], ['id' => $id, 'status' => $order['status']]) === 1;
        });
    }

    public static function update_order_payload(int $id, array $lines, ?string $expected_payload = null): bool {
        global $wpdb;
        return self::transaction(static function () use ($id,$lines,$expected_payload,$wpdb) {
            $order=self::order($id);
            if (!$order || ($expected_payload !== null && !hash_equals($expected_payload,(string)$order['payload']))) return false;
            $payload=wp_json_encode(array_values($lines));
            if ($payload === (string)$order['payload']) return true;
            return $wpdb->update(self::orders_table(), ['payload'=>$payload], ['id'=>$id,'payload'=>(string)$order['payload']]) === 1;
        });
    }
    public static function open_order_for_vendor(string $vendor): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . self::orders_table() . " WHERE vendor=%s AND status IN ('pending_manager','approval_emailed','ordered') ORDER BY id DESC LIMIT 1", $vendor), ARRAY_A);
        self::checked_read();
        return is_array($row) ? $row : null;
    }

    public static function mark_stock_increases(array $previous_stock = []): int {
        return self::transaction(static function () use ($previous_stock) { return self::mark_stock_increases_locked($previous_stock); });
    }
    private static function mark_stock_increases_locked(array $previous_stock): int {
        global $wpdb;
$products = self::rows("SELECT square_variation_id,name,on_hand FROM " . self::products_table());
        $current = [];
        $by_name = [];
        foreach ($products as $product) {
            $current[(string) $product['square_variation_id']] = (float) $product['on_hand'];
            $name_key=strtolower((string)$product['name']);
            if (array_key_exists($name_key,$by_name)) $by_name[$name_key]=null; // Ambiguous names cannot establish receipt identity.
            else $by_name[$name_key] = ['square_variation_id' => (string) $product['square_variation_id'], 'on_hand' => (float) $product['on_hand']];
        }
        $orders = self::rows("SELECT id,payload FROM " . self::orders_table() . " WHERE status='ordered'");
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
                        if (!array_key_exists('on_hand',$line)) $line['on_hand'] = $by_name[$name_key]['on_hand'];
                        $variation_id = (string) $line['square_variation_id'];
                        $changed = true;
                    }
                }
                if ($variation_id !== '' && array_key_exists($variation_id, $current) && array_key_exists('on_hand', $line)) {
                    $before = $previous_stock[$variation_id] ?? ($line['last_observed_on_hand'] ?? $line['on_hand']);
                    if ($current[$variation_id] > (float) $before) {
                        $increased = true;
                        $line['stock_increase_detected_at'] = current_time('mysql');
                        $line['stock_increase_from'] = (float) $before;
                        $line['stock_increase_to'] = $current[$variation_id];
                    }
                    $line['last_observed_on_hand'] = $current[$variation_id];
                    $changed = true;
                }
            }
            unset($line);
            if ($changed) {
                $data=['payload'=>wp_json_encode($lines)];
                if ($increased) $data['status']='stock_increased';
                $result=$wpdb->update(self::orders_table(), $data, ['id'=>(int)$order['id'],'status'=>'ordered']);
                self::checked_write($result);
                if ($increased && $result===1) $marked++;
            }
        }
        return $marked;
    }
}
