<?php
ob_start();
require __DIR__ . '/social-bulk-editor-regression.php';
ob_end_clean();
function remove_accents($v) { return $v; }
function esc_url_raw($v) { return $v; }
function untrailingslashit($v) { return rtrim($v,'/'); }
function submit_button($label) { echo '<button type="submit" class="button button-primary">' . esc_html($label) . '</button>'; }
require __DIR__ . '/../includes/modules/social-publisher/includes/class-roxy-social-ai.php';
$_GET=['tab'=>'ai'];
$GLOBALS['social_fixture_options']['roxy_social_film_references']=['wildwood'=>['title'=>'Wildwood','release_year'=>2026,'genre'=>'Fantasy adventure','synopsis'=>'A teenager enters an enchanted forest to rescue her baby brother. A confirmed premise from the distributor.','source_url'=>'https://example.test/wildwood']];
echo '<!doctype html><meta charset="utf-8"><style>body{font:14px Arial;margin:24px;background:#f0f0f1}table{border-collapse:collapse;width:100%}td,th{padding:10px;text-align:left;vertical-align:top}input,textarea{box-sizing:border-box;padding:8px}textarea.large-text{width:100%}th{font-weight:600}h3{margin-top:28px}button{padding:10px}tr{border-bottom:1px solid #ddd}.nav-tab{display:inline-block;margin:4px}@media(max-width:780px){.form-table th,.form-table td{display:block;padding:8px 0}.form-table textarea{max-width:100%}}</style>';
RoxySocial\Admin::render_page();
