<?php
// Studio mappings in Grosses settings must override curated, cached, and remote metadata.
define('ABSPATH', __DIR__ . '/');
define('DAY_IN_SECONDS', 86400);

function studio_override_check(bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo "PASS: {$message}\n";
}
function get_transient(string $key) { return $GLOBALS['studio_override_cache'][$key] ?? false; }
function set_transient(string $key, $value, int $expiration = 0): bool { $GLOBALS['studio_override_cache'][$key] = $value; return true; }
function wp_parse_args($args, array $defaults = []): array { return array_merge($defaults, is_array($args) ? $args : []); }

class StudioOverrideSettingsFixture {
  public static array $mappings = [];
  public static function mappings(): array { return []; }
  public static function studio_mappings(): array { return self::$mappings; }
}
class_alias(StudioOverrideSettingsFixture::class, 'RoxyGrosses\\Settings');
class StudioOverrideStoreFixture {
  public static function normalize_title(string $title): string {
    $title = strtolower($title);
    return trim(preg_replace('/[^a-z0-9]+/', ' ', $title) ?? $title);
  }
}
class_alias(StudioOverrideStoreFixture::class, 'RoxyGrosses\\Store');

require_once dirname(__DIR__) . '/includes/modules/grosses/includes/class-roxy-grosses-metadata.php';

StudioOverrideSettingsFixture::$mappings = [['match' => 'barbie', 'studio' => 'Roxy Distribution']];
$local = \RoxyGrosses\Metadata::enrich_movie_row(['movie_title' => 'Barbie'], false, false);
studio_override_check(($local['studio'] ?? '') === 'Roxy Distribution', 'configured studio wins over curated metadata in local report enrichment');
studio_override_check(($local['genre'] ?? '') === 'Adventure, Comedy, Fantasy', 'configured studio override preserves curated genre data');
$existing = \RoxyGrosses\Metadata::enrich_movie_row([
  'movie_title' => 'Barbie',
  'studio' => 'Warner Bros. Entertainment',
  'genre' => 'Existing report genre',
], false, false);
studio_override_check(($existing['studio'] ?? '') === 'Roxy Distribution', 'configured studio replaces existing studio on an already-enriched report row');
studio_override_check(($existing['genre'] ?? '') === 'Existing report genre', 'studio mapping preserves an already-populated report-row genre');

$forced = \RoxyGrosses\Metadata::metadata_for_movie('Barbie', 2023, true);
studio_override_check(($forced['studio'] ?? '') === 'Roxy Distribution', 'configured studio wins in forced metadata refresh');
studio_override_check(($GLOBALS['studio_override_cache'] ?? []) !== [], 'resolved metadata remains cached after applying configured precedence');

$GLOBALS['studio_override_cache'] = [];
StudioOverrideSettingsFixture::$mappings = [['match' => 'barbie', 'studio' => 'Roxy Distribution']];
$stale_key = 'roxy_grosses_meta_v3_' . md5('barbie|2023');
$GLOBALS['studio_override_cache'][$stale_key] = [
  'studio' => 'Warner Bros. Entertainment',
  'genre' => 'Adventure, Comedy, Fantasy',
  'genres' => ['Adventure', 'Comedy', 'Fantasy'],
  'source' => 'cache',
  'entity_id' => '',
];
$cached = \RoxyGrosses\Metadata::metadata_for_movie('Barbie', 2023, false);
studio_override_check(($cached['studio'] ?? '') === 'Roxy Distribution', 'configured studio is applied over a previously cached studio');
studio_override_check(($cached['source'] ?? '') === 'cache', 'cached metadata source and other fields are preserved');

StudioOverrideSettingsFixture::$mappings = [['match' => 'independent', 'studio' => 'Independent Pictures']];
$fallback = \RoxyGrosses\Metadata::enrich_movie_row(['movie_title' => 'Independent Feature'], false, false);
studio_override_check(($fallback['studio'] ?? '') === 'Independent Pictures', 'configured studio fills metadata when no curated match exists');
studio_override_check(($fallback['genre'] ?? '') === '', 'studio-only fallback does not invent a genre');

StudioOverrideSettingsFixture::$mappings = [];
$curated = \RoxyGrosses\Metadata::metadata_for_movie('Barbie', 2023, true);
studio_override_check(($curated['studio'] ?? '') === 'Warner Bros. Entertainment', 'curated studio remains when no configured mapping matches');

echo "Grosses configured studio override precedence regression passed.\n";
