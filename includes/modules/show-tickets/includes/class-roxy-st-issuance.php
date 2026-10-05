<?php
namespace RoxyST;
if (!defined('ABSPATH')) exit;

/** Private ticket projection writes: ordered locks, one connection, one transaction. */
final class Issuance {
  private string $lock;
  private array $locks = [];
  private int $owner = 0;
  private array $posts = [];
  private array $items = [];
  private array $leased = [];

  public function __construct(int|array $order_ids, string|array $scope = '') {
    global $wpdb;
    $ids = array_unique(array_map('intval', (array)$order_ids));
    if (count($ids)>64) throw new \RuntimeException('Too many orders in this group. Use individual ticket check-in.');
    $scopes=array_values(array_unique(array_filter((array)$scope,static fn($value)=>is_string($value)&&$value!=='')));
    if(count($scopes)>4 || ($ids && min($ids)<=0) || (!$ids && !$scopes)) throw new \RuntimeException('Invalid admission lock scope');
    foreach ($ids as $id) $this->locks[] = 'roxy_ticket_' . substr(hash('sha256', $wpdb->prefix . ':' . $id), 0, 48);
    foreach($scopes as $value) $this->locks[] = 'roxy_scope_' . substr(hash('sha256', $wpdb->prefix . ':' . $value), 0, 48);
    sort($this->locks, SORT_STRING);
    $this->lock = $this->locks[0];
  }

  public function run(callable $callback) {
    global $wpdb;
    $this->owner = (int)$wpdb->get_var('SELECT CONNECTION_ID()');
    $started = false;
    $acquired = [];
    try {
      $deadline = microtime(true)+5;
      foreach ($this->locks as $lock) {
        if ((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lock, max(0,(int)ceil($deadline-microtime(true))))) !== '1') throw new \RuntimeException('Ticket issuance is busy');
        $acquired[] = $lock;
      }
      $this->assert_owner();
      foreach ([$wpdb->posts, $wpdb->postmeta, $wpdb->prefix.'woocommerce_order_items', $wpdb->prefix.'woocommerce_order_itemmeta'] as $table) {
        $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
        if ($engine !== 'InnoDB') throw new \RuntimeException('Transactional ticket storage required');
      }
      // Never implicitly commit a transaction owned by WooCommerce/another module.
      if ((string)$wpdb->get_var('SELECT @@SESSION.autocommit') !== '1') throw new \RuntimeException('External transaction active');
      $probe = 'roxy_ticket_probe_' . bin2hex(random_bytes(8));
      if ($wpdb->query('SAVEPOINT '.$probe) === false) throw new \RuntimeException('Transaction probe failed');
      $previous = $wpdb->suppress_errors(true);
      try { $outer = $wpdb->query('RELEASE SAVEPOINT '.$probe) !== false; }
      finally { $wpdb->suppress_errors($previous); }
      if ($outer) throw new \RuntimeException('External transaction active');
      $this->assert_owner();
      if ($wpdb->query('START TRANSACTION') === false) throw new \RuntimeException('Cannot begin ticket transaction');
      $started = true;
      $this->assert_owner();
      $result = $callback($this);
      $this->assert_owner();
      if ($wpdb->query('COMMIT') === false) throw new \RuntimeException('Cannot commit tickets');
      $this->assert_owner();
      $started = false;
      return $result;
    } finally {
      // Do not rollback or release anything on a reconnected/unrelated connection.
      if ($this->owner > 0 && (int)$wpdb->get_var('SELECT CONNECTION_ID()') === $this->owner) {
        if ($started) $wpdb->query('ROLLBACK');
        foreach (array_reverse($acquired) as $lock) {
          if ((int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', $lock)) === $this->owner) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
      }
      foreach ($this->posts as $id) { wp_cache_delete($id, 'posts'); wp_cache_delete($id, 'post_meta'); }
      foreach ($this->items as $id) { wp_cache_delete($id, 'order_item_meta'); wp_cache_delete('item-'.$id, 'order-items'); }
      if ($this->items && class_exists('WC_Cache_Helper')) \WC_Cache_Helper::invalidate_cache_group('order-items');
    }
  }

  /** Named locks only: span Woo's status save without wrapping provider hooks in a transaction. */
  public function acquire_lease(): void {
    global $wpdb;
    if($this->leased)throw new \RuntimeException('Seat lease already acquired');
    $this->owner=(int)$wpdb->get_var('SELECT CONNECTION_ID()');
    try {
      $deadline=microtime(true)+5;
      foreach($this->locks as $lock) {
        if((string)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,%d)',$lock,max(0,(int)ceil($deadline-microtime(true)))))!=='1')throw new \RuntimeException('Seat confirmation is busy');
        $this->leased[]=$lock;
      }
      $this->assert_owner();
    } catch(\Throwable $e){$this->release_lease();throw $e;}
  }

  public function release_lease(): void {
    global $wpdb;
    if($this->owner>0 && (int)$wpdb->get_var('SELECT CONNECTION_ID()')===$this->owner) {
      foreach(array_reverse($this->leased) as $lock)if((int)$wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)',$lock))===$this->owner)$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)',$lock));
    }
    $this->leased=[];
  }

  private function owns_connection(): bool {
    global $wpdb;
    return $this->owner > 0 && (string)$wpdb->get_var('SELECT IF('.$this->predicate().',1,0)') === '1';
  }
  public function assert_owner(): void {
    if (!$this->owns_connection()) throw new \RuntimeException('Ticket connection ownership lost');
  }
  private function predicate(): string {
    global $wpdb;
    $checks = [$wpdb->prepare('CONNECTION_ID()=%d', $this->owner)];
    foreach ($this->locks as $lock) $checks[] = $wpdb->prepare('IS_USED_LOCK(%s)=%d', $lock, $this->owner);
    return '('.implode(' AND ', $checks).')';
  }
  public function will_call_quantity(int $context_id, string $customer_key): int {
    global $wpdb;
    $this->assert_owner();
    $table=$wpdb->prefix.'roxy_will_call_checkins';
    if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)) !== 'InnoDB') throw new \RuntimeException('Transactional attendance storage required');
    $value=$wpdb->get_var($wpdb->prepare("SELECT used_qty FROM `$table` WHERE product_id=%d AND customer_key=%s",$context_id,$customer_key));
    if ($wpdb->last_error) throw new \RuntimeException('Attendance summary read failed');
    $this->assert_owner();
    return (int)$value;
  }
  public function will_call_summary(int $context_id, string $customer_key, int $quantity): void {
    global $wpdb;
    $this->will_call_quantity($context_id,$customer_key);
    $table=$wpdb->prefix.'roxy_will_call_checkins';
    $guard=$this->predicate();
    $this->write($wpdb->prepare("INSERT INTO `$table` (product_id,customer_key,checked_in,used_qty,updated_at) SELECT %d,%s,%d,%d,%s FROM DUAL WHERE $guard ON DUPLICATE KEY UPDATE checked_in=VALUES(checked_in),used_qty=VALUES(used_qty),updated_at=VALUES(updated_at)",$context_id,$customer_key,$quantity>0?1:0,$quantity,current_time('mysql')));
    if ($this->will_call_quantity($context_id,$customer_key)!==$quantity) throw new \RuntimeException('Attendance summary verification failed');
  }
  public function member_walkup_quantity(int $showing_id, int $subscription_id = 0): int {
    global $wpdb;
    $this->assert_owner();
    $table=$wpdb->prefix.'roxy_member_scans';
    if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)) !== 'InnoDB') throw new \RuntimeException('Transactional membership log storage required');
    $where=$wpdb->prepare('showing_id=%d',$showing_id);
    if($subscription_id>0) $where.=$wpdb->prepare(' AND subscription_id=%d',$subscription_id);
    $value=$wpdb->get_var("SELECT COALESCE(SUM(quantity),0) FROM `$table` WHERE $where AND is_active=1 AND source IN ('manual_admit_walkup','nfc_admit_walkup')");
    if($wpdb->last_error || $value===null) throw new \RuntimeException('Member arrivals could not be read');
    $this->assert_owner();
    return max(0,(int)$value);
  }

  /** Fresh, read-only reservation count; preserves current conservative refund policy. */
  public function reserved_seats(int $showing_id): int {
    $this->assert_owner();
    $quantity=Reservations::quantity_for_showing($showing_id);
    $this->assert_owner();
    return $quantity;
  }

  public function member_visit(array $row): bool {
    global $wpdb;
    $this->assert_owner();
    $table=$wpdb->prefix.'roxy_member_scans';
    if ($wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$table)) !== 'InnoDB') throw new \RuntimeException('Transactional membership log storage required');
    $columns=['scanned_at','subscription_id','user_id','status','is_active','showing_id','source','quantity','ip','user_agent'];
    if (array_keys($row)!==$columns) throw new \RuntimeException('Invalid membership log fields');
    $values=[];
    foreach($columns as $column) $values[]=$row[$column]===null ? 'NULL' : $wpdb->prepare('%s',(string)$row[$column]);
    $sql="INSERT INTO `$table` (`".implode('`,`',$columns)."`) SELECT ".implode(',',$values).' FROM DUAL WHERE '.$this->predicate();
    if ($this->write($sql)!==1) throw new \RuntimeException('Membership visit was not saved');
    return true;
  }

  private function write(string $sql): int {
    global $wpdb;
    $this->assert_owner();
    $result = $wpdb->query($sql);
    if ($result === false) throw new \RuntimeException('Ticket write failed');
    $this->assert_owner();
    return (int)$result;
  }
  private function read_values(string $table, string $column, int $id, string $key): array {
    global $wpdb;
    $this->assert_owner();
    $values = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM `$table` WHERE `$column`=%d AND meta_key=%s", $id, $key));
    if ($wpdb->last_error || !is_array($values)) throw new \RuntimeException('Ticket read failed');
    $this->assert_owner();
    return $values;
  }
  private function metadata(string $table, string $column, int $id, string $key, $value, bool $remove = false): void {
    global $wpdb;
    $where = $wpdb->prepare("`$column`=%d AND meta_key=%s", $id, $key);
    $guard = $this->predicate();
    if ($remove) {
      $this->write("DELETE FROM `$table` WHERE $where AND $guard");
      if ($this->read_values($table, $column, $id, $key)) throw new \RuntimeException('Ticket metadata deletion failed');
      return;
    }
    $stored = (string)maybe_serialize($value);
    $existing = $this->read_values($table, $column, $id, $key);
    if ($existing && !array_filter($existing, static fn($v)=>(string)$v !== $stored)) return;
    if ($existing) $this->write($wpdb->prepare("UPDATE `$table` SET meta_value=%s WHERE $where AND $guard", $stored));
    else {
      // Guard is part of the SQL itself: wpdb reconnect/retry cannot autocommit a stray write.
      $this->write($wpdb->prepare("INSERT INTO `$table` (`$column`,meta_key,meta_value) SELECT %d,%s,%s FROM DUAL WHERE $guard", $id, $key, $stored));
    }
    $values = $this->read_values($table, $column, $id, $key);
    if (!$values || array_filter($values, static fn($v)=>(string)$v !== $stored)) throw new \RuntimeException('Ticket metadata verification failed');
  }
  public function post_meta(int $id, string $key, $value, bool $remove = false): void {
    global $wpdb;
    $this->posts[$id] = $id;
    $this->metadata($wpdb->postmeta, 'post_id', $id, $key, $value, $remove);
    wp_cache_delete($id, 'post_meta');
  }
  public function post_meta_value(int $id, string $key) {
    global $wpdb;
    $values=$this->read_values($wpdb->postmeta,'post_id',$id,$key);
    return $values?maybe_unserialize($values[0]):'';
  }
  public function is_ticket(int $id): bool {
    global $wpdb;
    $this->assert_owner();
    $type=$wpdb->get_var($wpdb->prepare("SELECT post_type FROM `{$wpdb->posts}` WHERE ID=%d",$id));
    if($wpdb->last_error)throw new \RuntimeException('Ticket identity read failed');
    $this->assert_owner();
    return $type==='roxy_ticket';
  }
  public function item_ids(int $id, array $ids): void {
    global $wpdb;
    $this->items[$id] = $id;
    $this->metadata($wpdb->prefix.'woocommerce_order_itemmeta', 'order_item_id', $id, '_roxy_ticket_ids', $ids);
  }
  public function ticket_for_sequence(int $order_id, int $item_id, int $sequence): int {
    global $wpdb;
    $this->assert_owner();
    $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM `{$wpdb->posts}` p
      JOIN `{$wpdb->postmeta}` o ON o.post_id=p.ID AND o.meta_key='_roxy_ticket_order_id' AND o.meta_value=%s
      JOIN `{$wpdb->postmeta}` i ON i.post_id=p.ID AND i.meta_key='_roxy_ticket_order_item_id' AND i.meta_value=%s
      JOIN `{$wpdb->postmeta}` s ON s.post_id=p.ID AND s.meta_key='_roxy_ticket_sequence' AND s.meta_value=%s
      WHERE p.post_type='roxy_ticket' AND p.post_status='publish' LIMIT 2", (string)$order_id, (string)$item_id, (string)$sequence));
    if($wpdb->last_error||!is_array($ids))throw new \RuntimeException('Ticket sequence read failed');
    $this->assert_owner();
    if(count($ids)>1)throw new \RuntimeException('Duplicate ticket sequence requires review');
    return $ids?(int)$ids[0]:0;
  }
  public function tickets_for_item(int $order_id, int $item_id): array {
    global $wpdb;
    $this->assert_owner();
    $ids=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT p.ID FROM `{$wpdb->posts}` p
      JOIN `{$wpdb->postmeta}` o ON o.post_id=p.ID AND o.meta_key='_roxy_ticket_order_id' AND o.meta_value=%s
      JOIN `{$wpdb->postmeta}` i ON i.post_id=p.ID AND i.meta_key='_roxy_ticket_order_item_id' AND i.meta_value=%s
      WHERE p.post_type='roxy_ticket' AND p.post_status='publish' ORDER BY p.ID", (string)$order_id, (string)$item_id));
    if($wpdb->last_error||!is_array($ids))throw new \RuntimeException('Ticket item read failed');
    $this->assert_owner();
    return array_map('intval',$ids);
  }
  public function create(string $title): int {
    global $wpdb;
    // Private records only: no public post-insert callbacks/network effects inside the transaction.
    $row = ['post_author'=>0,'post_date'=>current_time('mysql'),'post_date_gmt'=>current_time('mysql',true),
      'post_content'=>'','post_title'=>sanitize_text_field($title),'post_excerpt'=>'','post_status'=>'publish',
      'comment_status'=>'closed','ping_status'=>'closed','post_password'=>'','post_name'=>'',
      'to_ping'=>'','pinged'=>'','post_modified'=>current_time('mysql'),'post_modified_gmt'=>current_time('mysql',true),
      'post_content_filtered'=>'','post_parent'=>0,'guid'=>'','menu_order'=>0,'post_type'=>'roxy_ticket','post_mime_type'=>'','comment_count'=>0];
    $columns = '`'.implode('`,`',array_keys($row)).'`';
    $placeholders = implode(',',array_fill(0,count($row),'%s'));
    if ($this->write($wpdb->prepare("INSERT INTO `{$wpdb->posts}` ($columns) SELECT $placeholders FROM DUAL WHERE ".$this->predicate(), ...array_values($row))) !== 1) throw new \RuntimeException('Ticket insert was not saved');
    $id = (int)$wpdb->insert_id;
    if ($id <= 0) throw new \RuntimeException('Ticket identity missing');
    $this->posts[$id] = $id;
    return $id;
  }
}
