<?php
/** Ensure Social schema verification includes every newly required Story/worker column. */
define('ABSPATH', __DIR__ . '/');

function dbDelta($sql) {
    global $wpdb;
    if (!preg_match('/CREATE TABLE\s+([a-zA-Z0-9_]+)/i', $sql, $match)) return [];
    $table = $match[1];
    preg_match_all('/^\s*([a-z][a-z0-9_]*)\s+(?:BIGINT|INT|TINYINT|DATETIME|DATE|CHAR|VARCHAR|DECIMAL|LONGTEXT|TEXT)/im', $sql, $columns);
    $wpdb->tables[$table] = array_fill_keys($columns[1], true);
    if ($wpdb->omit_column !== '') unset($wpdb->tables[$table][$wpdb->omit_column]);
    return [];
}

final class SocialSchemaFixtureWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public string $omit_column = '';
    public array $tables = [];
    public function get_charset_collate(): string { return ''; }
    public function prepare(string $sql, ...$args): string { return vsprintf(str_replace('%s', "'%s'", $sql), $args); }
    public function get_var(string $sql) {
        $this->last_error = '';
        if (preg_match("/SHOW TABLES LIKE '([^']+)'/i", $sql, $m)) {
            $name = stripslashes($m[1]);
            return isset($this->tables[$name]) ? $name : null;
        }
        if (preg_match("/SHOW COLUMNS FROM `?([a-z0-9_]+)`? LIKE '([a-z0-9_]+)'/i", $sql, $m)) {
            return isset($this->tables[$m[1]][$m[2]]) ? $m[2] : null;
        }
        $this->last_error = 'Unexpected schema fixture query';
        return null;
    }
}

$wpdb = new SocialSchemaFixtureWpdb();
require dirname(__DIR__) . '/includes/modules/social-publisher/includes/class-roxy-social-store.php';
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) throw new RuntimeException('FAIL: ' . $label);
    ++$checks;
    echo 'PASS: ' . $label . "\n";
};

$wpdb->omit_column = 'facebook_story_state';
$check(!\RoxySocial\Store::install_schema(), 'missing Facebook Story state column blocks schema verification');
$check($wpdb->last_error === '', 'missing required Story state is distinguished from a database read error');
$wpdb->omit_column = '';
$check(\RoxySocial\Store::install_schema(), 'schema retry succeeds after Story state column is available');
foreach (['publish_facebook_story', 'facebook_story_state', 'publish_attempt_token', 'publish_worker_token'] as $column) {
    $check(isset($wpdb->tables['wp_roxy_social_posts'][$column]), 'verified Social schema includes ' . $column);
}
echo "Passed {$checks} Social schema verification assertions.\n";
