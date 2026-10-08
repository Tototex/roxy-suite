<?php
namespace RoxySocial {
    final class Secrets { public static function decrypt(string $value): string { return $value; } }
}

namespace {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . DIRECTORY_SEPARATOR);
    $GLOBALS['featured_fixture'] = ['thumbnail' => 41, 'meta' => [], 'meta_fail' => false, 'thumbnail_fail' => false, 'false_after_write' => false];
    function get_post_thumbnail_id($post_id) { return (int) $GLOBALS['featured_fixture']['thumbnail']; }
    function set_post_thumbnail($post_id, $attachment_id) {
        if (!$GLOBALS['featured_fixture']['thumbnail_fail']) $GLOBALS['featured_fixture']['thumbnail'] = (int) $attachment_id;
        return $GLOBALS['featured_fixture']['false_after_write'] ? false : !$GLOBALS['featured_fixture']['thumbnail_fail'];
    }
    function delete_post_thumbnail($post_id) { $GLOBALS['featured_fixture']['thumbnail'] = 0; return true; }
    function update_post_meta($id, $key, $value) {
        if ($GLOBALS['featured_fixture']['meta_fail']) return false;
        $GLOBALS['featured_fixture']['meta'][$id][$key] = $value;
        return true;
    }
    function get_post_meta($id, $key, $single = false) { return $GLOBALS['featured_fixture']['meta'][$id][$key] ?? ''; }

    require dirname(__DIR__) . '/includes/modules/social-publisher/includes/class-roxy-social-hangar.php';
    $assign = new \ReflectionMethod(\RoxySocial\Hangar::class, 'assign_featured_image');
    $assign->setAccessible(true);
    $checks = 0;
    $check = static function (bool $ok, string $label) use (&$checks): void {
        if (!$ok) throw new \RuntimeException('FAIL: ' . $label);
        $checks++;
    };

    $check(!$assign->invoke(null, 0, 55, 900) && $GLOBALS['featured_fixture']['thumbnail'] === 41, 'invalid target is rejected without changing the old featured image');

    $GLOBALS['featured_fixture']['meta_fail'] = true;
    $check(!$assign->invoke(null, 7, 55, 900) && $GLOBALS['featured_fixture']['thumbnail'] === 41, 'failed asset-reference write does not replace the old image');
    $GLOBALS['featured_fixture']['meta_fail'] = false;

    $GLOBALS['featured_fixture']['thumbnail_fail'] = true;
    $check(!$assign->invoke(null, 7, 56, 901) && $GLOBALS['featured_fixture']['thumbnail'] === 41, 'failed thumbnail assignment reports failure and retains old image');

    $GLOBALS['featured_fixture']['thumbnail_fail'] = false;
    $GLOBALS['featured_fixture']['false_after_write'] = true;
    $check($assign->invoke(null, 7, 57, 902) && $GLOBALS['featured_fixture']['thumbnail'] === 57, 'persisted assignment is verified even if WordPress returns a false no-change result');
    $check((int) $GLOBALS['featured_fixture']['meta'][57]['_roxy_hangar_asset_id'] === 902, 'successful assignment retains the exact Hangar asset identity');

    echo "PASS: {$checks} Hangar featured-image persistence checks\n";
}
