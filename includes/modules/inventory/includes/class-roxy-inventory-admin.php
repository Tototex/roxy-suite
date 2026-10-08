<?php
namespace RoxyInventory;
if (!defined('ABSPATH')) exit;

class Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu'], 11); add_action('admin_post_roxy_inventory_pull', [__CLASS__, 'pull']); add_action('admin_post_roxy_inventory_save_product', [__CLASS__, 'save_product']); add_action('admin_post_roxy_inventory_bulk_save', [__CLASS__, 'bulk_save']); add_action('admin_post_roxy_inventory_products_bulk_save', [__CLASS__, 'products_bulk_save']); add_action('admin_post_roxy_inventory_save_vendor', [__CLASS__, 'save_vendor']); add_action('admin_post_roxy_inventory_send_draft', [__CLASS__, 'send_draft']); add_action('admin_post_roxy_inventory_order_decision', [__CLASS__, 'order_decision']); add_action('admin_post_nopriv_roxy_inventory_order_decision', [__CLASS__, 'order_decision']); add_action('admin_post_roxy_inventory_save_order_items', [__CLASS__, 'save_order_items']); add_action('admin_post_roxy_inventory_cancel_order', [__CLASS__, 'cancel_order']); add_action('admin_post_roxy_inventory_save_settings', [__CLASS__, 'save_settings']);
    }
    public static function menu(): void { if (roxy_suite_module_enabled('inventory')) add_submenu_page('roxy-suite','Inventory','Inventory',roxy_suite_admin_capability(),'roxy-inventory',[__CLASS__,'page']); }
    private static function guard(): void { if (!roxy_suite_user_can_access_admin()) wp_die('You do not have permission to access Inventory.'); }
    private static function url(string $tab='dashboard'): string { return admin_url('admin.php?page=roxy-inventory&tab='.rawurlencode($tab)); }
    public static function page(): void {
        self::guard(); $tab = sanitize_key((string) ($_GET['tab'] ?? 'dashboard')); if (!in_array($tab,['dashboard','products','unassigned','vendors','history','settings'],true)) $tab='dashboard'; $vendor=sanitize_text_field(wp_unslash($_GET['vendor']??''));
        echo '<div class="wrap"><h1>Roxy Inventory</h1><nav class="nav-tab-wrapper">'; foreach(['dashboard'=>'Order Review','products'=>'Products','unassigned'=>'Unassigned','vendors'=>'Vendors','history'=>'Order History','settings'=>'Settings'] as $k=>$label) echo '<a class="nav-tab '.($tab===$k?'nav-tab-active':'').'" href="'.esc_url(self::url($k)).'">'.esc_html($label).'</a>'; echo '</nav>';
        if (!empty($_GET['message'])) echo '<div class="notice notice-'.(($_GET['ok']??'')==='1'?'success':'error').'"><p>'.esc_html(sanitize_text_field(wp_unslash($_GET['message']))).'</p></div>';
        if ($tab==='products') self::products_all(); elseif ($tab==='unassigned') self::unassigned(); elseif ($tab==='vendors') self::vendors(); elseif ($tab==='history') self::history(); elseif ($tab==='settings') self::settings(); elseif ($vendor!=='') self::vendor_order($vendor); else self::dashboard(); echo '</div>';
    }
    private static function dashboard(): void {
        $last_pull = Store::latest_run('pull');
        $pull_text = $last_pull ? $last_pull['created_at'] . ' — ' . ucfirst((string) $last_pull['status']) . ': ' . $last_pull['message'] : 'No Square pull has run yet.';
        echo '<p>Pull Square inventory, review suggested quantities, then open a vendor order for final edits. To release an ordered vendor for reordering, employees should record deliveries with Square’s Receive Stock action. Sales, recounts, and manual corrections will not release the order.</p><p><strong>Last Square pull:</strong> ' . esc_html($pull_text) . '</p><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('roxy_inventory_pull','_wpnonce',true,false) . '<input type="hidden" name="action" value="roxy_inventory_pull">' . get_submit_button('Pull Inventory from Square','primary','submit',false) . '</form>';
        $vendors = Store::vendors(); $products = Store::products();
        echo '<h2>Suggested orders</h2><table class="widefat striped"><thead><tr><th>Vendor</th><th>Items</th><th>Estimated total</th><th>Minimum</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($vendors as $vendor) {
            $rows = [];
            foreach ($products as $p) { if (strcasecmp((string) $p['vendor'], (string) $vendor['name']) !== 0) continue; $qty = self::qty($p); if ($qty <= 0) continue; $rows[] = ['product'=>$p,'qty'=>$qty]; }
            $estimate = self::cost_summary($rows); $total = $estimate['known_total'];
            $open = Store::open_order_for_vendor((string) $vendor['name']);
            if ($open) {
                $status = (string) $open['status'];
                $status_label = $status === 'pending_manager' ? 'Submission awaiting review' : ($status === 'approval_emailed' ? 'Submitted to manager' : ($status === 'ordered' ? 'Ordered' : 'Order in review'));
            } else {
                $status_label = $estimate['incomplete'] ? 'Cost incomplete' : ($total >= (float) $vendor['minimum_amount'] ? 'Ready for review' : 'Below minimum');
            }
            $vendor_link = admin_url('admin.php?page=roxy-inventory&tab=dashboard&vendor=' . rawurlencode($vendor['name']));
            $estimate_label = $estimate['incomplete'] ? 'Incomplete — $' . number_format($total,2) . ' known' : '$' . number_format($total,2);
            echo '<tr><td><a href="' . esc_url($vendor_link) . '">' . esc_html($vendor['name']) . '</a></td><td>' . count($rows) . '</td><td>' . esc_html($estimate_label) . '</td><td>$' . number_format((float) $vendor['minimum_amount'],2) . '</td><td>' . esc_html($status_label) . '</td><td><a class="button" href="' . esc_url($vendor_link) . '">Review Order</a></td></tr>';
        }
        echo '</tbody></table><p><a href="' . esc_url(self::url('products')) . '">Configure vendors, pack sizes, reorder points, targets, costs, and manual quantities.</a></p>';
    }
    private static function vendor_order(string $name): void {
        $vendor = null;
        foreach (Store::all_vendors() as $v) if (strcasecmp($v['name'], $name) === 0) $vendor = $v;
        if (!$vendor) { echo '<p>Vendor not found.</p>'; return; }
        $open = Store::open_order_for_vendor($name);
        $status_text = '';
        if ($open) {
            $status = (string) $open['status'];
            $status_text = $status === 'pending_manager' ? 'Submission awaiting review' : ($status === 'approval_emailed' ? 'Submitted to manager' : ($status === 'ordered' ? 'Ordered' : 'Order in review'));
            echo '<div class="notice notice-warning inline"><p>This vendor already has an open order: <strong>' . esc_html($status_text) . '</strong>. A new order cannot be submitted until Square records a received-stock adjustment for one of the ordered items.</p><p><a class="button" href="' . esc_url(add_query_arg(['tab'=>'history','order_id'=>(int) $open['id']], self::url('history'))) . '">View existing order</a></p></div>';
        }
        echo '<h2>' . esc_html($name) . ' order review</h2><p>Edit quantities before submitting. Quantities must be whole order units, and the vendor minimum must be met.</p>';
        $direct = Settings::get('direct_vendor_sending_enabled') === '1' && $vendor['order_method'] === 'email';
        echo '<p>Submit Order sends this order to ' . esc_html($direct ? (string) $vendor['email'] . ' directly and marks it Ordered.' : 'the manager for review. The vendor is contacted manually.') . '</p>';
        $rows = [];
        foreach (Store::products() as $p) {
            if (strcasecmp((string) $p['vendor'], $name) !== 0) continue;
            $qty = self::qty($p);
            $rows[] = ['product' => $p, 'qty' => $qty];
        }
        $estimate = self::cost_summary($rows); $total = $estimate['known_total'];
        $minimum = (float) $vendor['minimum_amount'];
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo wp_nonce_field('roxy_inventory_send_draft','_wpnonce',true,false) . '<input type="hidden" name="action" value="roxy_inventory_send_draft"><input type="hidden" name="vendor" value="' . esc_attr($name) . '">';
        $review_products = array_column($rows, 'product');
        echo '<input type="hidden" name="review_token" value="' . esc_attr(self::review_token($vendor,$review_products)) . '"><input type="hidden" name="submission_key" value="' . esc_attr(hash('sha256',wp_generate_uuid4())) . '">';
        echo '<p>All tracked vendor items are shown. Set an item to zero to skip it, or increase a zero quantity to add it intentionally. Unknown costs block ordering; estimates are not verified supplier quotes. Confirmed quotes and free items must include their source and checked date.</p>';
        echo '<table class="widefat striped" id="roxy-inventory-vendor-review"><thead><tr><th>Product</th><th>On hand</th><th>Pack</th><th>Order qty</th><th>Unit cost</th><th>Line total</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $p = $row['product']; $id = (int) $p['id']; $qty = (int) $row['qty']; $known = self::cost_is_known($p); $cost = $known ? (float)$p['unit_cost'] : 0.0;
            $cost_label = ($p['unit_cost_status'] ?? '') === 'free' ? 'Free' : ($known ? '$' . number_format($cost,2) . ' (' . ucfirst((string)($p['unit_cost_status'] ?? 'estimate')) . ')' : 'Unknown');
            $line_label = $known ? '$' . number_format($qty * $cost,2) : ($qty === 0 ? '$0.00' : 'Unknown');
            echo '<tr><td>' . esc_html($p['name']) . '</td><td>' . esc_html(number_format((float) $p['on_hand'],0)) . '</td><td>' . esc_html(number_format((float) $p['pack_size'],0)) . '</td><td><input class="roxy-order-qty" data-cost="' . esc_attr($known ? $cost : '') . '" name="order_qty[' . $id . ']" type="number" min="0" step="1" value="' . esc_attr($qty) . '" style="width:90px"' . ($open ? ' disabled' : '') . '></td><td>' . esc_html($cost_label) . '</td><td class="roxy-order-line-total">' . esc_html($line_label) . '</td></tr>';
        }
        $estimate_label = $estimate['incomplete'] ? 'Incomplete — $' . number_format($total,2) . ' known' : '$' . number_format($total,2);
        echo '</tbody><tfoot><tr><th colspan="5">Estimated total</th><th id="roxy-order-total">' . esc_html($estimate_label) . '</th></tr></tfoot></table><input type="hidden" name="review_complete" value="1">';
        if (!$rows) echo '<p>No suggested items for this vendor.</p>';
        if ($open) echo '<p><a class="button" href="' . esc_url(self::url('dashboard')) . '">Back to all vendors</a></p>';
        else echo '<p><button type="submit" class="button button-primary" id="roxy-submit-vendor-order"' . (($rows && !$estimate['incomplete'] && $total >= $minimum) ? '' : ' disabled') . '>Submit Order</button> <span id="roxy-order-minimum" data-minimum="' . esc_attr($minimum) . '">Minimum: $' . number_format($minimum,2) . '</span></p>';
        echo '</form><p><a class="button" href="' . esc_url(self::url('dashboard')) . '">Back to all vendors</a></p>';
        if (!$open) echo '<script>(function(){var t=document.getElementById("roxy-inventory-vendor-review"),b=document.getElementById("roxy-submit-vendor-order"),m=document.getElementById("roxy-order-minimum");if(!t||!b||!m)return;function c(){var total=0,incomplete=false;t.querySelectorAll(".roxy-order-qty").forEach(function(i){var q=Math.max(0,parseInt(i.value||"0",10)),raw=i.dataset.cost,cost=parseFloat(raw||"0"),known=raw!==""&&cost>0,cell=i.closest("tr").querySelector(".roxy-order-line-total");if(q>0&&!known){incomplete=true;if(cell)cell.textContent="Unknown";}else if(cell)cell.textContent="$"+(q*cost).toFixed(2);if(known)total+=q*cost;});document.getElementById("roxy-order-total").textContent=incomplete?"Incomplete — $"+total.toFixed(2)+" known":"$"+total.toFixed(2);b.disabled=incomplete||!(total>=parseFloat(m.dataset.minimum||"0"));}t.addEventListener("input",c);})();</script>';
    }
    private static function unassigned(): void { $vendors=Store::vendors(); echo '<h2>Unassigned / not tracked products</h2><p>Assign vendors and change statuses, then save once. Not tracked items may remain without a vendor and never generate order quantities.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('roxy_inventory_bulk_save','_wpnonce',true,false).'<input type="hidden" name="action" value="roxy_inventory_bulk_save"><table class="widefat striped"><thead><tr><th>Product</th><th>On hand</th><th>Vendor</th><th>Status</th></tr></thead><tbody>'; foreach(Store::review_products() as $p) { $status=($p['tracking_status']??'tracked'); echo '<tr><td>'.esc_html($p['name']).'</td><td>'.esc_html($p['on_hand']).'</td><td><select name="vendor['.esc_attr($p['id']).']"><option value="">Unassigned</option>'; foreach($vendors as $v) echo '<option value="'.esc_attr($v['name']).'" '.selected($p['vendor'],$v['name'],false).'>'.esc_html($v['name']).'</option>'; echo '</select></td><td><select name="tracking_status['.esc_attr($p['id']).']"><option value="tracked" '.selected($status,'tracked',false).'>Tracked</option><option value="not_tracked" '.selected($status,'not_tracked',false).'>Not tracked</option></select></td></tr>'; } echo '</tbody></table><p>'.get_submit_button('Save all changes','primary','submit',false).'</p></form>'; }
    private static function history(): void {
        $selected_id = absint($_GET['order_id'] ?? 0);
        $selected = $selected_id ? Store::order($selected_id) : null;
        echo '<h2>Order history</h2>';
        $search=sanitize_text_field(wp_unslash($_GET['vendor_search']??''));
        $count=Store::order_count($search); $pages=max(1,(int)ceil($count/50));
        $page=min($pages,max(1,(int)($_GET['history_page']??1)));
        echo '<form method="get"><input type="hidden" name="page" value="roxy-inventory"><input type="hidden" name="tab" value="history"><label>Vendor search <input name="vendor_search" value="'.esc_attr($search).'"></label> <button class="button" type="submit">Search</button></form><p>'.esc_html((string)$count).' orders — page '.esc_html((string)$page).' of '.esc_html((string)$pages).'</p>';
        echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Vendor</th><th>Status</th><th>Items</th><th>Total</th><th>Action</th></tr></thead><tbody>';
        foreach (Store::orders($page,$search) as $o) {
            $status = (string) $o['status'];
            $label = $status === 'pending_manager' ? 'Submission awaiting review' : ($status === 'approval_emailed' ? 'Submitted to manager' : ($status === 'stock_increased' ? 'Stock increase detected' : ucfirst(str_replace('_', ' ', $status))));
            $view_url = add_query_arg(['tab' => 'history', 'order_id' => (int) $o['id']], self::url('history'));
            $action = '<a class="button" href="' . esc_url($view_url) . '">View items</a>';
            if ($status === 'approval_emailed') $action .= ' <a class="button" href="' . esc_url(self::decision_url((int)$o['id'], 'ordered')) . '">Ordered</a> <a class="button" href="' . esc_url(self::decision_url((int)$o['id'], 'rejected')) . '">Rejected</a>';
            if ($status === 'pending_manager') $action .= ' <a class="button" href="' . esc_url(self::decision_url((int)$o['id'], 'ordered')) . '">Confirm placed order</a>';
            $order_lines = json_decode((string)($o['payload'] ?? ''), true);
            $order_estimate = is_array($order_lines) ? self::cost_summary($order_lines) : ['known_total'=>(float)$o['estimated_total'],'incomplete'=>false];
            $total_label = $order_estimate['incomplete'] ? 'Incomplete — $' . number_format($order_estimate['known_total'],2) . ' known' : '$' . number_format((float)$o['estimated_total'],2);
            echo '<tr><td>' . esc_html($o['created_at']) . '</td><td>' . esc_html($o['vendor']) . '</td><td>' . esc_html($label) . '</td><td>' . esc_html($o['item_count']) . '</td><td>' . esc_html($total_label) . '</td><td>' . $action . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p>';
        if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg(['history_page'=>$page-1,'vendor_search'=>$search],self::url('history'))).'">Previous</a> ';
        if($page<$pages)echo '<a class="button" href="'.esc_url(add_query_arg(['history_page'=>$page+1,'vendor_search'=>$search],self::url('history'))).'">Next</a>';
        echo '</p>';
        if (!$selected) return;
        $lines = json_decode((string) ($selected['payload'] ?? ''), true);
        if (!is_array($lines)) $lines = [];
        $selected_estimate = self::cost_summary($lines);
        echo '<h2>Order #' . esc_html((string) $selected['id']) . ' — ' . esc_html($selected['vendor']) . '</h2>';
        if ((string) $selected['status'] === 'pending_manager') echo '<div class="notice notice-warning inline"><p>Email delivery for this submission may be unconfirmed. Check the manager/vendor inbox before retrying; cancel only if the order was not placed.</p></div>';
        echo '<p>Check each item as you add it to the vendor cart, then save your progress.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('roxy_inventory_save_order_items', '_wpnonce', true, false);
        echo '<input type="hidden" name="action" value="roxy_inventory_save_order_items"><input type="hidden" name="order_id" value="' . esc_attr((int) $selected['id']) . '">';
        echo '<input type="hidden" name="payload_revision" value="' . esc_attr(hash('sha256',(string)$selected['payload'])) . '">';
        echo '<table class="widefat striped"><thead><tr><th>In cart</th><th>Product</th><th>Quantity</th><th>Pack</th><th>Unit cost</th><th>Total</th></tr></thead><tbody>';
        foreach ($lines as $index => $line) {
            $quantity = (float) ($line['quantity'] ?? 0);
            $unit_cost = (float) ($line['unit_cost'] ?? 0);
            $checked = !empty($line['added_to_cart']);
            if (!empty($line['square_receipt_event_id'])) echo '<tr><td colspan="6"><small>Square recorded a receipt of '.esc_html((string)($line['square_receipt_quantity']??'')).' units for '.esc_html((string)($line['product']??'')).' at '.esc_html((string)($line['square_receipt_created_at']??$line['stock_increase_detected_at']??'')).'. This receipt released the vendor for a new order; it does not confirm the whole order arrived.</small></td></tr>';
            elseif (!empty($line['stock_increase_detected_at'])) echo '<tr><td colspan="6"><small>Legacy stock increase observed for '.esc_html((string)($line['product']??'')).': '.esc_html((string)($line['stock_increase_from']??'')).' → '.esc_html((string)($line['stock_increase_to']??'')).' at '.esc_html((string)$line['stock_increase_detected_at']).'. This unlocks reordering; it does not confirm the whole order arrived.</small></td></tr>';
            if (!empty($line['cart_progress_at'])) echo '<tr><td colspan="6"><small>Cart progress last changed at '.esc_html((string)$line['cart_progress_at']).' by user #'.esc_html((string)($line['cart_progress_by']??'')).'.</small></td></tr>';
            $cost_known = self::cost_is_known($line);
            $cost_label = ($line['unit_cost_status'] ?? '') === 'free' ? 'Free' : ($cost_known ? '$' . number_format($unit_cost,2) . ' (' . ucfirst((string)($line['unit_cost_status'] ?? 'estimate')) . ')' : 'Unknown');
            $line_total_label = !$cost_known ? ($quantity > 0 ? 'Unknown' : '$0.00') : '$' . number_format((float) ($line['line_total'] ?? ($quantity * $unit_cost)), 2);
            echo '<tr><td><input type="checkbox" name="added_to_cart[' . esc_attr((int) $index) . ']" value="1" ' . checked($checked, true, false) . ' aria-label="Added to cart: ' . esc_attr((string) ($line['product'] ?? '')) . '"></td><td>' . esc_html((string) ($line['product'] ?? '')) . '</td><td>' . esc_html(number_format($quantity, 0, '.', '')) . '</td><td>' . esc_html(number_format((float) ($line['pack_size'] ?? 0), 0, '.', '')) . '</td><td>' . esc_html($cost_label) . '</td><td>' . esc_html($line_total_label) . '</td></tr>';
        }
        $selected_total_label = $selected_estimate['incomplete'] ? 'Incomplete — $' . number_format($selected_estimate['known_total'],2) . ' known' : '$' . number_format((float) $selected['estimated_total'],2);
        echo '</tbody><tfoot><tr><th colspan="5">Estimated total</th><th>' . esc_html($selected_total_label) . '</th></tr></tfoot></table><p>' . get_submit_button('Save cart progress', 'primary', 'submit', false) . ' <a class="button" href="' . esc_url(self::url('history')) . '">Close itemized view</a></p></form>';
        if (in_array((string) $selected['status'], ['pending_manager','approval_emailed','ordered'], true)) echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:8px" onsubmit="return confirm(\'Cancel this order and release the vendor for a new order?\');">' . wp_nonce_field('roxy_inventory_cancel_order','_wpnonce',true,false) . '<input type="hidden" name="action" value="roxy_inventory_cancel_order"><input type="hidden" name="order_id" value="' . esc_attr((int) $selected['id']) . '">' . get_submit_button('Cancel Order', 'secondary', 'submit', false) . '</form>';
    }
    private static function qty(array $p): float { if(($p['tracking_status']??'tracked')!=='tracked') return 0; $override = $p['override_qty'] === null || $p['override_qty']==='' ? null : (float)$p['override_qty']; if ($override !== null) return max(0,$override); $need=max(0,(float)$p['target_stock']-(float)$p['on_hand']); if ($need <= 0 || (float)$p['on_hand'] > (float)$p['reorder_point']) return 0; $pack=max(1,(float)$p['pack_size']); return ceil($need/$pack)*$pack; }
    public static function verified_positive_cost($value): bool {
        return is_numeric($value) && is_finite((float)$value) && (float)$value > 0;
    }
    public static function cost_is_known(array $record): bool {
        $status = $record['unit_cost_status'] ?? null;
        $cost = $record['unit_cost'] ?? null;
        if ($status === 'free') return is_numeric($cost) && (float)$cost === 0.0 && !empty($record['unit_cost_source']) && !empty($record['unit_cost_checked_at']);
        if ($status === 'confirmed') return self::verified_positive_cost($cost) && !empty($record['unit_cost_source']) && !empty($record['unit_cost_checked_at']);
        if ($status === 'estimate') return self::verified_positive_cost($cost);
        // Older saved order lines have no provenance fields; retain their historical estimate semantics.
        return $status === null && self::verified_positive_cost($cost);
    }
    public static function cost_summary(array $rows): array {
        $known_total = 0.0; $incomplete = false;
        foreach ($rows as $row) {
            $quantity = max(0, (float) ($row['qty'] ?? $row['quantity'] ?? 0));
            if ($quantity <= 0) continue;
            $record = is_array($row['product'] ?? null) ? $row['product'] : $row;
            $cost = (float) ($record['unit_cost'] ?? 0);
            if (!self::cost_is_known($record)) { $incomplete = true; continue; }
            $known_total += $quantity * $cost;
        }
        return ['known_total'=>round($known_total,2),'incomplete'=>$incomplete];
    }
    private static function vendors(): void {
        echo '<h2>Vendors</h2><table class="widefat striped"><thead><tr><th>Vendor</th><th>Order method</th><th>Email</th><th>Minimum amount</th><th>Delivery / notes</th><th>Save</th></tr></thead><tbody>';
        foreach (Store::vendors() as $v) {
            $form_id = 'roxy-vendor-' . (int) $v['id'];
            $form_attr = ' form="' . esc_attr($form_id) . '"';
            echo '<tr><td>' . esc_html($v['name']) . '</td>';
            echo '<td><input' . $form_attr . ' name="order_method" value="' . esc_attr($v['order_method']) . '"></td>';
            echo '<td><input' . $form_attr . ' type="email" name="email" value="' . esc_attr($v['email']) . '"></td>';
            echo '<td><input' . $form_attr . ' type="number" min="0" step="0.01" name="minimum_amount" value="' . esc_attr($v['minimum_amount']) . '"></td>';
            echo '<td><input' . $form_attr . ' class="large-text" name="delivery_notes" value="' . esc_attr($v['delivery_notes']) . '"></td>';
            echo '<td><form id="' . esc_attr($form_id) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo wp_nonce_field('roxy_inventory_save_vendor','_wpnonce',true,false) . '<input type="hidden" name="action" value="roxy_inventory_save_vendor"><input type="hidden" name="id" value="' . esc_attr($v['id']) . '">' . get_submit_button('Save','secondary','submit',false) . '</form></td></tr>';
        }
        echo '</tbody></table>';
    }
    private static function settings(): void {
        $s = Settings::all();
        echo '<h2>Inventory settings</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('roxy_inventory_save_settings','_wpnonce',true,false) . '<input type="hidden" name="action" value="roxy_inventory_save_settings"><table class="form-table">';
        echo '<tr><th>Manager approval email</th><td><input type="email" class="regular-text" name="jason_email" value="' . esc_attr($s['jason_email']) . '"><p class="description">Orders go here for review unless direct vendor sending is enabled.</p></td></tr>';
        echo '<tr><th>Timezone</th><td><input class="regular-text" name="timezone" value="' . esc_attr($s['timezone']) . '"></td></tr>';
        echo '<tr><th>Nightly pull</th><td><label><input type="checkbox" name="schedule_enabled" value="1" ' . checked($s['schedule_enabled'],'1',false) . '> Pull automatically</label> at <input type="time" name="schedule_time" value="' . esc_attr($s['schedule_time']) . '"><p class="description">Inventory pulls do not send order emails.</p></td></tr>';
        echo '<tr><th>Direct vendor sending</th><td><label><input type="checkbox" name="direct_vendor_sending_enabled" value="1" ' . checked($s['direct_vendor_sending_enabled'],'1',false) . '> Bypass manager approval for email vendors</label><p class="description">Submit Order emails the vendor address configured under Vendors and marks the order Ordered. Portal and pickup orders still go to the manager.</p></td></tr>';
        foreach (['tripp' => 'Tripp', 'odom' => 'Odom'] as $key => $label) {
            $field = $key . '_order_instructions';
            echo '<tr><th>' . esc_html($label) . ' email instructions</th><td><textarea class="large-text" rows="3" name="' . esc_attr($field) . '">' . esc_textarea($s[$field]) . '</textarea><p class="description">Shown above the item list in manager and direct vendor emails.</p></td></tr>';
        }
        echo '</table><input type="hidden" name="inventory_settings_complete" value="1">' . get_submit_button('Save Inventory Settings') . '</form>';
    }
    public static function pull(): void { self::guard(); check_admin_referer('roxy_inventory_pull'); try { $n=count(Square::pull()); self::redirect('dashboard','Pulled '.$n.' products from Square.',true); } catch(\Throwable $e){ Store::log('pull','failed',$e->getMessage()); self::redirect('dashboard',$e->getMessage(),false); } }
    public static function save_product(): void {
        self::guard();
        check_admin_referer('roxy_inventory_save_product');
        try {
            $id=self::record_id($_POST['id']??null); $row=[];
            foreach(['vendor','pack_size','reorder_point','target_stock','unit_cost','unit_cost_status','unit_cost_source','unit_cost_checked_at','supplier_sku','override_qty'] as $field) {
                if(!array_key_exists($field,$_POST))throw new \RuntimeException('Incomplete product changes. Refresh before saving.');
                $row[$field]=$_POST[$field];
            }
            $rows=self::product_rows_from_submission(['product_rows_complete'=>'1','product_row_count'=>'1','product_rows_json'=>wp_json_encode([$id=>$row])]);
            if($rows===null)throw new \RuntimeException('Use nonnegative whole quantities and a cost with at most two decimals.');
            $has_status=array_key_exists('tracking_status',$_POST); $status=$_POST['tracking_status']??null;
            if(array_key_exists('tracking_status',$_POST)) {
                if(!in_array($status,['tracked','not_tracked'],true))throw new \RuntimeException('Invalid tracking status.');
            }
            Store::transaction(static function()use($id,$row,$has_status,$status){
                $changes=self::product_changes($row,array_column(Store::all_vendors(),'name'));
                if($has_status)$changes['tracking_status']=$status;
                Store::update_product($id,$changes);
            });
        } catch (\Throwable $error) {
            self::redirect('products', 'Product could not be saved: ' . $error->getMessage(), false);
        }
        self::redirect('products', 'Product saved.', true);
    }
    public static function bulk_save(): void {
        self::guard(); check_admin_referer('roxy_inventory_bulk_save');
        try { Store::transaction(static function () {
            if(!is_array($_POST['tracking_status']??null)||!is_array($_POST['vendor']??null))throw new \RuntimeException('Incomplete product changes; nothing was saved.');
            $names=array_column(Store::all_vendors(),'name');
            foreach((array)($_POST['tracking_status']??[]) as $id=>$status) {
                $id=self::record_id($id);
                if(!$id || !in_array($status,['tracked','not_tracked'],true) || !array_key_exists($id,(array)($_POST['vendor']??[]))) throw new \RuntimeException('Incomplete product changes; nothing was saved.');
                $vendor=self::validated_vendor($_POST['vendor'][$id],$names);
                Store::update_product($id,['vendor'=>$vendor,'tracking_status'=>$status]);
            }
        }); } catch (\Throwable $e) { self::redirect('unassigned',$e->getMessage(),false); }
        self::redirect('unassigned','All product changes saved.',true);
    }
    public static function save_vendor(): void {
        self::guard();
        check_admin_referer('roxy_inventory_save_vendor');
        try {
            $id=self::record_id($_POST['id']??null);
            foreach(['order_method','email','minimum_amount','delivery_notes']as$field)if(!array_key_exists($field,$_POST)||!is_string($_POST[$field]))throw new \RuntimeException('Incomplete vendor changes. Refresh before saving.');
            $method=sanitize_text_field(wp_unslash($_POST['order_method']));
            if(!in_array($method,['email','online','manual','text','phone'],true))throw new \RuntimeException('Use email, online, manual, text, or phone as the order method.');
            $email=sanitize_email(wp_unslash($_POST['email']));
            if(trim($_POST['email'])!=='' && (!$email||!is_email($email)))throw new \RuntimeException('Enter a valid vendor email address or leave it blank.');
            $minimum=$_POST['minimum_amount'];
            if(!is_numeric($minimum)||!is_finite((float)$minimum)||(float)$minimum<0||(float)$minimum>9999999999.99||round((float)$minimum,2)!==(float)$minimum)throw new \RuntimeException('Use a nonnegative vendor minimum with at most two decimals.');
            Store::update_vendor($id, [
                'order_method' => $method,
                'email' => $email,
                'minimum_amount' => (float)$minimum,
                'delivery_notes' => sanitize_textarea_field(wp_unslash($_POST['delivery_notes'])),
                'updated_at' => current_time('mysql'),
            ]);
        } catch (\Throwable $error) {
            self::redirect('vendors', 'Vendor could not be saved: ' . $error->getMessage(), false);
        }
        self::redirect('vendors', 'Vendor saved.', true);
    }
    public static function save_settings(): void {
        self::guard();
        check_admin_referer('roxy_inventory_save_settings');
        try {
            foreach (['jason_email','timezone','schedule_time','tripp_order_instructions','odom_order_instructions'] as $field) {
                if (!array_key_exists($field, $_POST) || !is_string($_POST[$field])) throw new \RuntimeException('Settings form was incomplete or malformed. Nothing was saved; reload and try again.');
            }
            if (($_POST['inventory_settings_complete'] ?? null) !== '1') throw new \RuntimeException('Settings form was incomplete. Nothing was saved; reload and try again.');
            foreach (['schedule_enabled','direct_vendor_sending_enabled'] as $field) {
                if (array_key_exists($field, $_POST) && $_POST[$field] !== '1') throw new \RuntimeException('Settings form was malformed. Nothing was saved; reload and try again.');
            }
            foreach (['jason_email','timezone','schedule_time','tripp_order_instructions','odom_order_instructions','admin_alert_email'] as $field) {
                if (array_key_exists($field, $_POST) && !is_string($_POST[$field])) throw new \RuntimeException('Settings form was malformed. Nothing was saved; reload and try again.');
            }
            $manager_email = sanitize_email(wp_unslash($_POST['jason_email']));
            if (!is_email($manager_email)) throw new \RuntimeException('Enter a valid manager approval email address. Nothing was saved.');
            $timezone = sanitize_text_field(wp_unslash($_POST['timezone']));
            if (!in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) throw new \RuntimeException('Choose a recognized timezone. Nothing was saved.');
            if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $_POST['schedule_time'])) throw new \RuntimeException('Enter a valid schedule time in HH:MM format. Nothing was saved.');
            if (isset($_POST['admin_alert_email']) && trim($_POST['admin_alert_email']) !== '' && !is_email(sanitize_email(wp_unslash($_POST['admin_alert_email'])))) throw new \RuntimeException('Enter a valid alert email address or leave it blank. Nothing was saved.');
            $sanitized = Settings::sanitize($_POST);
            $updated = update_option(Settings::OPTION_KEY, $sanitized);
            $saved = $updated || get_option(Settings::OPTION_KEY, null) === $sanitized;
        } catch (\Throwable $error) {
            self::redirect('settings', 'Inventory settings could not be saved: ' . $error->getMessage(), false);
        }
        if (!$saved) {
            self::redirect('settings', 'Inventory settings were not saved.', false);
        }
        try {
            Scheduler::sync_schedule();
        } catch (\Throwable $error) {
            self::redirect('settings', 'Inventory settings were saved, but schedule synchronization failed: ' . $error->getMessage(), false);
        }
        self::redirect('settings', 'Inventory settings saved.', true);
    }
    public static function send_draft(): void {
        self::guard(); check_admin_referer('roxy_inventory_send_draft');
        $vendor = sanitize_text_field(wp_unslash($_POST['vendor'] ?? ''));
        $vendor_row = null;
        foreach (Store::vendors() as $candidate) if (strcasecmp((string) $candidate['name'], $vendor) === 0) { $vendor_row = $candidate; break; }
        if (!$vendor_row) self::redirect('dashboard', 'Vendor not found.', false);
        $direct = Settings::get('direct_vendor_sending_enabled') === '1' && $vendor_row['order_method'] === 'email';
        if ($direct && !is_email((string) $vendor_row['email'])) self::redirect('dashboard', 'Add a valid vendor email before submitting a direct order.', false);
        try {
            $prepared = Store::transaction(static function () use ($vendor) {
                $key = (string) ($_POST['submission_key'] ?? '');
                if (!preg_match('/^[a-f0-9]{64}$/D', $key)) throw new \RuntimeException('Please reopen vendor review before submitting.');
                $existing = Store::order_for_submission($key);
                if ($existing) return ['replayed'=>true,'id'=>(int)$existing['id']];
                $fresh_vendor = null;
                foreach (Store::vendors() as $candidate) if (strcasecmp((string)$candidate['name'],$vendor)===0) $fresh_vendor=$candidate;
                if (!$fresh_vendor) throw new \RuntimeException('This vendor is no longer active.');
                $direct = Settings::get('direct_vendor_sending_enabled') === '1' && $fresh_vendor['order_method'] === 'email';
                $recipient = (string) ($direct ? $fresh_vendor['email'] : Settings::get('jason_email'));
                if (!is_email($recipient)) throw new \RuntimeException('Add a valid order recipient email before submitting.');
                $products = array_values(array_filter(Store::products(), static fn($p)=>strcasecmp((string)$p['vendor'],$vendor)===0));
                [$lines,$total] = self::reviewed_lines($fresh_vendor,$products,$_POST);
                $id = Store::create_order((string)$fresh_vendor['name'],$lines,$total,(float)$fresh_vendor['minimum_amount'],'pending_manager',$key);
                $email = self::order_email($fresh_vendor, $lines, $total, $id, $direct);
                return ['replayed'=>false,'id'=>$id,'vendor'=>$fresh_vendor,'lines'=>$lines,'total'=>$total,'direct'=>$direct,'email'=>$email];
            });
        } catch (\Throwable $e) { self::redirect('dashboard',$e->getMessage(),false); }
        if ($prepared['replayed']) self::redirect('history','This reviewed order was already submitted. No second email was sent.',true,$prepared['id']);
        $order_id=$prepared['id']; $vendor_row=$prepared['vendor']; $lines=$prepared['lines']; $total=$prepared['total'];
        $direct = $prepared['direct'];
        $email = $prepared['email'];
        try { $mail_sent = $email['to'] !== '' && wp_mail($email['to'], $email['subject'], $email['body']); }
        catch (\Throwable $error) { $mail_sent = false; }
        if (!$mail_sent) {
            try { Store::log('email_delivery', 'uncertain', 'Delivery could not be confirmed for order #' . $order_id . ' to ' . ($direct ? 'vendor' : 'manager') . '.'); }
            catch (\Throwable $error) { /* The persistent pending order remains the retry guard. */ }
            self::redirect('history', 'Order email delivery could not be confirmed. Do not resubmit; check the manager/vendor inbox, then cancel only if the order was not placed.', false, $order_id);
        }
        try { $saved = Store::update_order_status($order_id, $direct ? 'ordered' : 'approval_emailed'); }
        catch (\Throwable $e) { $saved = false; }
        if (!$saved) self::redirect('history', 'Order email sent, but the order status could not be updated. Check this order before submitting again.', false, $order_id);
        if (!Store::log($direct ? 'vendor_email' : 'approval_email', 'success', 'Order email sent to ' . ($direct ? 'the vendor' : 'the configured manager') . ' for ' . $vendor . '.')) {
            self::redirect('history', 'Order #' . $order_id . ' was emailed and its status was updated, but the activity log could not be saved. Do not submit this order again.', false, $order_id);
        }
        self::redirect('dashboard', $direct ? 'Order emailed to the vendor and marked Ordered.' : 'Order submitted to the manager for approval.', true);
    }
    public static function review_token(array $vendor, array $products): string {
        $state = [];
        foreach ($products as $p) {
            $row=[];
            foreach (['id','square_variation_id','name','vendor','on_hand','pack_size','reorder_point','target_stock','unit_cost','unit_cost_status','unit_cost_source','unit_cost_checked_at','supplier_sku','override_qty'] as $field) $row[$field]=$p[$field] ?? null;
            $state[(int)$p['id']]=$row;
        }
        ksort($state);
        return hash_hmac('sha256',wp_json_encode([$vendor,$state]),wp_salt('auth'));
    }
    public static function reviewed_lines(array $vendor, array $products, array $input): array {
        if (($input['review_complete'] ?? '') !== '1' || !hash_equals(self::review_token($vendor,$products),(string)($input['review_token'] ?? ''))) {
            throw new \RuntimeException('Inventory or vendor rules changed, or the review is incomplete. Refresh and review again; no order was submitted.');
        }
        $quantities=$input['order_qty'] ?? null;
        if (!is_array($quantities) || count($quantities)!==count($products)) throw new \RuntimeException('Some reviewed quantities are missing. Refresh and review again.');
        $lines=[]; $total=0;
        foreach ($products as $p) {
            $raw=$quantities[$p['id']] ?? null;
            if (!is_scalar($raw) || !preg_match('/^\d+$/D',(string)$raw) || (float)$raw>1000000) throw new \RuntimeException('Use nonnegative whole quantities for every reviewed item.');
            $q=(int)$raw; if ($q===0) continue;
            if (!self::cost_is_known($p)) throw new \RuntimeException('Set a positive estimate/confirmed cost or explicitly mark the item Free before ordering.');
            $line_total=round($q*(float)$p['unit_cost'],2);
            $lines[]=['product'=>(string)$p['name'],'square_variation_id'=>(string)$p['square_variation_id'],'on_hand'=>(float)$p['on_hand'],'quantity'=>$q,'pack_size'=>(float)$p['pack_size'],'unit_cost'=>(float)$p['unit_cost'],'unit_cost_status'=>(string)($p['unit_cost_status']??'estimate'),'unit_cost_source'=>(string)($p['unit_cost_source']??''),'unit_cost_checked_at'=>(string)($p['unit_cost_checked_at']??''),'supplier_sku'=>(string)($p['supplier_sku']??''),'line_total'=>$line_total];
            $total+=$line_total;
        }
        $total=round($total,2);
        if (!$lines) throw new \RuntimeException('Choose at least one item to submit.');
        if ($total<(float)$vendor['minimum_amount']) throw new \RuntimeException('This order is below the vendor minimum.');
        return [$lines,$total];
    }
    public static function order_email(array $vendor, array $lines, float $total, int $order_id, bool $direct): array {
        $name = (string) $vendor['name'];
        $instruction_key = strtolower($name) . '_order_instructions';
        $instructions = in_array(strtolower($name), ['tripp', 'odom'], true) ? trim((string) Settings::get($instruction_key)) : '';
        $rows = [];
        foreach ($lines as $line) {
            $row = (int) $line['quantity'] . ' units — ' . $line['product'];
            if (!empty($line['supplier_sku'])) $row .= ' (Supplier SKU: ' . (string)$line['supplier_sku'] . ')';
            if ($instructions === '') $row .= ' (pack size ' . (int) $line['pack_size'] . ')';
            if (!empty($line['unit_cost_source']) || !empty($line['unit_cost_checked_at'])) {
                $row .= ' [Cost ' . ucfirst((string)($line['unit_cost_status'] ?? 'estimate'));
                if (!empty($line['unit_cost_source'])) $row .= '; source: ' . (string)$line['unit_cost_source'];
                if (!empty($line['unit_cost_checked_at'])) $row .= '; checked: ' . (string)$line['unit_cost_checked_at'];
                $row .= ']';
            }
            $rows[] = $row;
        }
        $body = ($instructions !== '' ? $instructions . "\n\n" : '') . "Newport Roxy order #{$order_id} — {$name}\n\n" . implode("\n", $rows) . "\n\nEstimated total: $" . number_format($total, 2);
        if (!$direct) {
            $body .= "\n\nManager review — the vendor has not been contacted.";
            if ($vendor['order_method'] === 'email') {
                // Forwarding this message must not expose signed order-decision links.
                $body .= "\nForward to: " . sanitize_email((string) $vendor['email']);
                $body .= "\nMark Ordered or Rejected in Order History:\n" . add_query_arg('order_id', $order_id, self::url('history'));
            } else {
                $body .= "\nMark as ordered:\n" . self::decision_url($order_id, 'ordered') . "\nReject this order:\n" . self::decision_url($order_id, 'rejected');
            }
        }
        return ['to' => sanitize_email((string) ($direct ? $vendor['email'] : Settings::get('jason_email'))), 'subject' => 'Newport Roxy ' . ($direct ? 'order' : 'order for review') . ' #' . $order_id . ' — ' . $name, 'body' => $body];
    }
    private static function decision_token(int $order_id, string $decision, int $expires = 0): string {
        $message = $order_id . '|' . $decision;
        if ($expires > 0) $message .= '|' . $expires;
        return hash_hmac('sha256', $message, wp_salt('auth'));
    }

    public static function valid_decision_token(int $order_id, string $decision, string $token, int $expires, ?int $now = null): bool {
        if ($order_id <= 0 || !in_array($decision, ['ordered', 'rejected'], true)) return false;
        $now = $now ?? time();
        if ($expires <= $now || $expires > $now + 30 * DAY_IN_SECONDS) return false;
        return hash_equals(self::decision_token($order_id, $decision, $expires), $token);
    }
    public static function save_order_items(): void {
        self::guard();
        check_admin_referer('roxy_inventory_save_order_items');
        $order_id = absint($_POST['order_id'] ?? 0);
        $order = Store::order($order_id);
        if (!$order) self::redirect('history', 'Order not found.', false);
        if (!hash_equals(hash('sha256',(string)$order['payload']),(string)($_POST['payload_revision'] ?? ''))) self::redirect('history','This order changed since you opened it. Refresh and reapply your cart progress.',false,$order_id);
        $lines = json_decode((string) ($order['payload'] ?? ''), true);
        if (!is_array($lines)) $lines = [];
        $selected = array_map('absint', array_keys((array) ($_POST['added_to_cart'] ?? [])));
        foreach ($lines as $index => &$line) {
            $value=in_array((int)$index,$selected,true);
            if ($value!==!empty($line['added_to_cart'])) {
                $line['cart_progress_by']=get_current_user_id();
                $line['cart_progress_at']=current_time('mysql');
            }
            $line['added_to_cart']=$value;
        }
        unset($line);
        try { $saved=Store::update_order_payload($order_id, $lines,(string)$order['payload']); }
        catch (\Throwable $e) { self::redirect('history',$e->getMessage(),false,$order_id); }
        if (!$saved) self::redirect('history','Cart progress could not be saved or another user changed it. Refresh and try again.',false,$order_id);
        self::redirect('history', 'Cart progress saved.', true, $order_id);
    }


    public static function cancel_order(): void {
        self::guard();
        check_admin_referer('roxy_inventory_cancel_order');
        $order_id = absint($_POST['order_id'] ?? 0);
        $order = Store::order($order_id);
        if (!$order) self::redirect('history', 'Order not found.', false);
        if (!in_array((string) $order['status'], ['pending_manager','approval_emailed','ordered'], true)) self::redirect('history', 'This order is already closed.', false, $order_id);
        try { $saved = Store::update_order_status($order_id, 'cancelled'); }
        catch (\Throwable $e) { $saved = false; }
        if (!$saved) self::redirect('history', 'Could not cancel the order. Refresh and try again.', false, $order_id);
        if (!Store::log('order_cancelled', 'success', 'Order #' . $order_id . ' cancelled for ' . $order['vendor'] . '.')) {
            self::redirect('history', 'Order #' . $order_id . ' was cancelled, but the activity log could not be saved. Do not repeat this action.', false, $order_id);
        }
        self::redirect('history', 'Order cancelled and vendor unlocked.', true, $order_id);
    }
    private static function decision_url(int $order_id, string $decision): string {
        $expires = time() + 30 * DAY_IN_SECONDS;
        return add_query_arg(['action'=>'roxy_inventory_order_decision','order_id'=>$order_id,'decision'=>$decision,'expires'=>$expires,'token'=>self::decision_token($order_id,$decision,$expires)], admin_url('admin-post.php'));
    }
    public static function order_decision(): void {
        $is_post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $input = $is_post ? $_POST : $_GET;
        $order_id = absint($input['order_id'] ?? 0);
        $decision = sanitize_key($input['decision'] ?? '');
        $token = sanitize_text_field(wp_unslash($input['token'] ?? ''));
        $expires = absint($input['expires'] ?? 0);
        $valid = self::valid_decision_token($order_id, $decision, $token, $expires);
        // Old emailed links no longer grant anonymous, indefinite write access.
        // A signed-in authorized manager can still confirm one from Order History.
        if (!$expires && roxy_suite_user_can_access_admin() && $order_id > 0 && in_array($decision, ['ordered','rejected'], true)) {
            $valid = hash_equals(self::decision_token($order_id, $decision), $token);
        }
        if (!$valid) wp_die('This order link is invalid or expired. Please open Order History while signed in to mark the order.');
        $found = Store::order($order_id);
        if (!$found) wp_die('Order not found.');
        $pending_confirmation = (string)$found['status'] === 'pending_manager' && $decision === 'ordered' && roxy_suite_user_can_access_admin();
        if ((string) $found['status'] !== 'approval_emailed' && !$pending_confirmation) wp_die('This order has already been marked, or requires a signed-in manager to confirm placement.');
        $nonce_action = 'roxy_inventory_order_decision_' . $token;
        if (!$is_post) {
            // Email scanners/prefetchers may open links. GET must never change an order.
            $html = '<h1>Confirm ' . esc_html(ucfirst($decision)) . '</h1><p>Order #' . esc_html((string)$order_id) . ' — ' . esc_html((string)$found['vendor']) . '</p>';
            $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            foreach (['action'=>'roxy_inventory_order_decision','order_id'=>$order_id,'decision'=>$decision,'expires'=>$expires,'token'=>$token] as $name=>$value) {
                $html .= '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr((string)$value) . '">';
            }
            $html .= wp_nonce_field($nonce_action, '_wpnonce', true, false);
            $html .= '<p><button type="submit">Confirm ' . esc_html(ucfirst($decision)) . '</button></p></form>';
            wp_die($html, 'Confirm order status', ['response'=>200]);
        }
        check_admin_referer($nonce_action);
        try { $saved = Store::update_order_status($order_id, $decision); }
        catch (\Throwable $e) { $saved = false; }
        if (!$saved) wp_die('Could not update this order. It may already have been marked.');
        if (!Store::log('order_decision', 'success', 'Order #' . $order_id . ' marked ' . $decision . ' for ' . $found['vendor'] . '.')) {
            self::redirect('history', 'Order #' . $order_id . ' was marked ' . $decision . ', but the activity log could not be saved. Do not repeat this action.', false, $order_id);
        }
        $message = $decision === 'ordered' ? 'Order marked as Ordered.' : 'Order marked as Rejected.';
        wp_safe_redirect(add_query_arg(['tab'=>'history','message'=>$message,'ok'=>'1'], self::url('history')));
        exit;
    }
    private static function products_all(): void {
        $vendors = Store::vendors();
        echo '<h2>Products</h2><p>Change product rules, then save all rows at once.</p>';
        echo '<form id="roxy-inventory-products" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">' . wp_nonce_field('roxy_inventory_products_bulk_save','_wpnonce',true,false);
        $products = Store::products();
        echo '<input type="hidden" name="product_row_count" value="' . count($products) . '"><input type="hidden" name="product_rows_json" value="">';
        echo '<input type="hidden" name="action" value="roxy_inventory_products_bulk_save"><table class="widefat striped"><thead><tr><th>Product</th><th>On hand</th><th>Vendor</th><th>Pack</th><th>Reorder</th><th>Target</th><th>Unit cost</th><th>Confidence</th><th>Source</th><th>Checked</th><th>Supplier SKU</th><th>Override</th><th>Suggested</th></tr></thead><tbody>';
        foreach ($products as $product) {
            $id = (int) $product['id'];
            $on_hand = number_format((float) $product['on_hand'], 0, '.', '');
            $pack = number_format((float) $product['pack_size'], 0, '.', '');
            $reorder = number_format((float) $product['reorder_point'], 0, '.', '');
            $target = number_format((float) $product['target_stock'], 0, '.', '');
            $cost = number_format((float) $product['unit_cost'], 2, '.', '');
            $override = ($product['override_qty'] === null || $product['override_qty'] === '') ? '' : number_format((float) $product['override_qty'], 0, '.', '');
            $cost_status = (string)($product['unit_cost_status'] ?? 'unknown');
            $cost_source = (string)($product['unit_cost_source'] ?? '');
            $cost_checked = (string)($product['unit_cost_checked_at'] ?? '');
            $supplier_sku = (string)($product['supplier_sku'] ?? '');
            echo '<tr><td>' . esc_html($product['name']) . '</td><td>' . esc_html($on_hand) . '</td><td><select name="vendor[' . $id . ']"><option value="">Unassigned</option>';
            foreach ($vendors as $vendor) echo '<option value="' . esc_attr($vendor['name']) . '"' . selected($product['vendor'],$vendor['name'],false) . '>' . esc_html($vendor['name']) . '</option>';
            echo '</select></td><td><input name="pack_size[' . $id . ']" type="number" min="1" step="1" value="' . esc_attr($pack) . '"></td><td><input name="reorder_point[' . $id . ']" type="number" min="0" step="1" value="' . esc_attr($reorder) . '"></td><td><input name="target_stock[' . $id . ']" type="number" min="0" step="1" value="' . esc_attr($target) . '"></td><td><input name="unit_cost[' . $id . ']" type="number" min="0" step="0.01" value="' . esc_attr($cost) . '"></td>';
            echo '<td><select name="unit_cost_status[' . $id . ']">';
            foreach (['unknown'=>'Unknown','estimate'=>'Estimate','confirmed'=>'Confirmed quote','free'=>'Free'] as $value=>$label) echo '<option value="' . esc_attr($value) . '"' . selected($cost_status,$value,false) . '>' . esc_html($label) . '</option>';
            echo '</select></td><td><input name="unit_cost_source[' . $id . ']" maxlength="190" value="' . esc_attr($cost_source) . '" placeholder="Supplier / list"></td><td><input name="unit_cost_checked_at[' . $id . ']" type="date" value="' . esc_attr($cost_checked) . '"></td><td><input name="supplier_sku[' . $id . ']" maxlength="190" value="' . esc_attr($supplier_sku) . '"></td><td><input name="override_qty[' . $id . ']" type="number" min="0" step="1" value="' . esc_attr($override) . '" placeholder="auto"></td><td>' . esc_html(number_format((float) self::qty($product), 0, '.', '')) . '</td></tr>';
        }
        echo '</tbody></table><p class="description">Unknown costs block ordering. Estimates are configured values, not verified quotes. Confirmed quotes and Free items require a source and checked date. Supplier SKU is kept separate from Square’s SKU.</p><input type="hidden" name="product_rows_complete" value="1"><p>' . get_submit_button('Save all product changes','primary','submit',false) . '</p></form>';
        // Send the table as one variable, avoiding PHP max_input_vars truncation.
        // The complete marker still rejects an incomplete non-JavaScript submission.
        echo <<<'JS'
<script>
document.getElementById('roxy-inventory-products').addEventListener('submit', function () {
    var rows = {};
    var fields = this.querySelectorAll('select[name], input[name]');
    fields.forEach(function (field) {
        var match = field.name.match(/^(vendor|pack_size|reorder_point|target_stock|unit_cost|unit_cost_status|unit_cost_source|unit_cost_checked_at|supplier_sku|override_qty)\[(\d+)\]$/);
        if (!match) return;
        if (!rows[match[2]]) rows[match[2]] = {};
        rows[match[2]][match[1]] = field.value;
    });
    this.elements.product_rows_json.value = JSON.stringify(rows);
    fields.forEach(function (field) {
        if (/^(vendor|pack_size|reorder_point|target_stock|unit_cost|unit_cost_status|unit_cost_source|unit_cost_checked_at|supplier_sku|override_qty)\[/.test(field.name)) field.removeAttribute('name');
    });
});
</script>
JS;
    }
    public static function product_rows_from_submission(array $post): ?array {
        if (($post['product_rows_complete'] ?? '') !== '1' || !isset($post['product_row_count'])) return null;
        if(!is_scalar($post['product_row_count'])||!ctype_digit((string)$post['product_row_count'])||(int)$post['product_row_count']>20000)return null;
        $rows = [];
        if (!empty($post['product_rows_json'])) {
            if(!is_string($post['product_rows_json'])||strlen($post['product_rows_json'])>5000000)return null;
            $rows = json_decode(wp_unslash($post['product_rows_json']), true);
            if (!is_array($rows)) return null;
        } else {
            foreach ((array) ($post['vendor'] ?? []) as $id => $vendor) {
                foreach (['vendor','pack_size','reorder_point','target_stock','unit_cost','unit_cost_status','unit_cost_source','unit_cost_checked_at','supplier_sku','override_qty'] as $field) {
                    if (!isset($post[$field]) || !is_array($post[$field]) || !array_key_exists($id, $post[$field])) return null;
                    $rows[$id][$field] = $post[$field][$id];
                }
            }
        }
        if (count($rows) !== (int) $post['product_row_count']) return null;
        foreach ($rows as $id => $row) {
            if (!ctype_digit((string) $id) || (int) $id < 1 || (string)(int)$id!==(string)$id || !is_array($row)) return null;
            foreach (['vendor','pack_size','reorder_point','target_stock','unit_cost','unit_cost_status','unit_cost_source','unit_cost_checked_at','supplier_sku','override_qty'] as $field) {
                if (!array_key_exists($field, $row) || !is_scalar($row[$field])) return null;
                if ($field === 'vendor' || ($field === 'override_qty' && $row[$field] === '')) continue;
                if (in_array($field,['unit_cost_status','unit_cost_source','unit_cost_checked_at','supplier_sku'],true)) {
                    $value=(string)$row[$field];
                    if ($field==='unit_cost_status' && !in_array($value,['unknown','estimate','confirmed','free'],true)) return null;
                    if ($field==='unit_cost_source' && strlen($value)>190) return null;
                    if ($field==='supplier_sku' && strlen($value)>190) return null;
                    if ($field==='unit_cost_checked_at' && $value!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$value) || !checkdate((int)substr($value,5,2),(int)substr($value,8,2),(int)substr($value,0,4)))) return null;
                    continue;
                }
                if (!is_numeric($row[$field]) || !is_finite((float) $row[$field]) || (float) $row[$field] < 0 || (float)$row[$field]>9999999999.99) return null;
                if ($field === 'unit_cost' && round((float)$row[$field],2)!==(float)$row[$field]) return null;
                if ($field !== 'unit_cost' && floor((float) $row[$field]) !== (float) $row[$field]) return null;
                if ($field === 'pack_size' && (float) $row[$field] < 1) return null;
            }
            $status=(string)$row['unit_cost_status']; $cost=(float)$row['unit_cost'];
            if (in_array($status,['unknown','free'],true) && $cost!==0.0) return null;
            if (in_array($status,['estimate','confirmed'],true) && $cost<=0) return null;
            if (in_array($status,['confirmed','free'],true) && ((string)$row['unit_cost_source']==='' || (string)$row['unit_cost_checked_at']==='')) return null;
        }
        return $rows;
    }
    private static function record_id($id): int {
        if(!is_scalar($id)||!ctype_digit((string)$id)||(int)$id<1||(string)(int)$id!==(string)$id)throw new \RuntimeException('Invalid inventory record identity.');
        return (int)$id;
    }
    private static function validated_vendor($raw, array $names): string {
        if(!is_string($raw))throw new \RuntimeException('Invalid vendor selection.');
        $name=sanitize_text_field(wp_unslash($raw));
        if($name==='')return '';
        foreach($names as $known)if(strcasecmp($name,(string)$known)===0)return (string)$known;
        throw new \RuntimeException('This vendor no longer exists. Refresh before saving.');
    }
    private static function product_changes(array $row,array $names): array {
        return ['vendor'=>self::validated_vendor($row['vendor'],$names),'pack_size'=>(float)$row['pack_size'],'reorder_point'=>(float)$row['reorder_point'],'target_stock'=>(float)$row['target_stock'],'unit_cost'=>(float)$row['unit_cost'],'unit_cost_status'=>(string)$row['unit_cost_status'],'unit_cost_source'=>sanitize_text_field(wp_unslash((string)$row['unit_cost_source'])),'unit_cost_checked_at'=>(string)$row['unit_cost_checked_at']!==''?(string)$row['unit_cost_checked_at']:null,'supplier_sku'=>sanitize_text_field(wp_unslash((string)$row['supplier_sku'])),'override_qty'=>$row['override_qty']===''?null:(float)$row['override_qty']];
    }
    public static function products_bulk_save(): void {
        self::guard(); check_admin_referer('roxy_inventory_products_bulk_save');
        $rows = self::product_rows_from_submission($_POST);
        if ($rows === null) self::redirect('products','The product form was incomplete or invalid. Nothing was saved; reload and try again.',false);
        try { Store::transaction(static function () use ($rows) { $names=array_column(Store::all_vendors(),'name'); foreach ($rows as $id => $row) {
            Store::update_product((int) $id,self::product_changes($row,$names));
        } }); } catch (\Throwable $e) { self::redirect('products',$e->getMessage(),false); }
        self::redirect('products','All product changes saved.',true);
    }
    private static function redirect(string $tab,string $message,bool $ok, int $order_id = 0): void { $args=['tab'=>$tab,'message'=>rawurlencode($message),'ok'=>$ok?'1':'0']; if ($order_id > 0) $args['order_id']=$order_id; wp_safe_redirect(add_query_arg($args,self::url($tab))); exit; }
}
