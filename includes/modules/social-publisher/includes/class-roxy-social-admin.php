<?php
namespace RoxySocial;

if (!defined('ABSPATH')) exit;

final class Admin {
    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_filter('wp_prepare_attachment_for_js', [__CLASS__, 'prepare_attachment_for_js'], 10, 3);
        add_action('admin_post_roxy_social_status', [__CLASS__, 'handle_status']);
        add_action('admin_post_roxy_social_hangar_settings', [__CLASS__, 'save_hangar_settings']);
        add_action('admin_post_roxy_social_meta_settings', ['\\RoxySocial\\Meta', 'save_settings']);
        add_action('admin_post_roxy_social_meta_callback', ['\\RoxySocial\\Meta', 'handle_callback']);
        add_action('admin_post_roxy_social_meta_verify', ['\\RoxySocial\\Meta', 'verify_connection']);
        add_action('admin_post_roxy_social_update_draft', [__CLASS__, 'update_draft']);
        add_action('admin_post_roxy_social_delete_draft', [__CLASS__, 'delete_draft']);
        add_action('admin_post_roxy_social_publish_now', [__CLASS__, 'publish_now']);
        add_action('admin_post_roxy_social_remove_published', [__CLASS__, 'remove_published']);
        add_action('admin_post_roxy_social_create_manual', [__CLASS__, 'create_manual']);
        add_action('admin_post_roxy_social_remove_media', [__CLASS__, 'remove_media']);
        add_action('admin_post_roxy_social_auto_approve', [__CLASS__, 'save_auto_approve']);
        add_action('wp_ajax_roxy_social_hangar_search', [__CLASS__, 'ajax_hangar_search']);
        add_action('wp_ajax_roxy_social_hangar_assign', [__CLASS__, 'ajax_hangar_assign']);
        add_action('wp_ajax_roxy_social_hangar_import_featured', [__CLASS__, 'ajax_hangar_import_featured']);
        add_action('wp_ajax_roxy_social_hangar_thumbnail', [__CLASS__, 'ajax_hangar_thumbnail']);
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook === 'roxy-suite_page_roxy-social-posts') wp_enqueue_media();
    }

    public static function prepare_attachment_for_js(array $response, $attachment, $meta = null): array {
        $attachment_id = is_object($attachment) ? (int) ($attachment->ID ?? 0) : (int) ($attachment['ID'] ?? 0);
        $poster = (string) get_post_meta($attachment_id, '_roxy_social_video_poster_url', true);
        if ($poster !== '' && (($response['type'] ?? '') === 'video' || ($response['mime'] ?? '') === 'video/mp4')) {
            $response['thumbnail'] = $poster;
            $response['icon'] = $poster;
        }
        return $response;
    }

    public static function render_showing_media_picker(int $post_id): void {
        if ($post_id <= 0 || !Hangar::has_credentials()) {
            echo '<div class="roxy-help" style="margin-top:8px">Connect Hangar under <a href="' . esc_url(admin_url('admin.php?page=roxy-social-posts&tab=hangar')) . '">Roxy Suite → Social Posts → Hangar Assets</a> to import a poster here.</div>';
            return;
        }
        echo '<div class="roxy-help" style="margin-top:8px"><strong>Hangar Poster</strong></div><div style="grid-column:1/-1;max-width:860px"><input id="roxy-showing-hangar-search" type="search" placeholder="Search Hangar by movie title" style="width:70%"> <button type="button" class="button" id="roxy-showing-hangar-search-button">Search</button><div id="roxy-showing-hangar-results" style="margin-top:8px"></div></div>';
        echo '<script>(function(){var b=document.getElementById("roxy-showing-hangar-search-button"),q=document.getElementById("roxy-showing-hangar-search"),o=document.getElementById("roxy-showing-hangar-results");if(!b)return;var titleValue=function(){if(window.wp&&wp.data)return (wp.data.select("core/editor").getEditedPostAttribute("title")||"").trim();var f=document.querySelector("#title, textarea.editor-post-title__input, input.editor-post-title__input");return f?(f.value||"").trim():"";};var syncTitle=function(){if(!q.value||q.dataset.roxyManual!=="1")q.value=titleValue();};if(q)q.addEventListener("input",function(){q.dataset.roxyManual="1";});syncTitle();if(window.wp&&wp.data)wp.data.subscribe(syncTitle);window.roxyImportFeatured=function(id,name){var d=new URLSearchParams({action:"roxy_social_hangar_import_featured",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_import_featured')) . '",post_id:"' . (int) $post_id . '",asset_id:id,filename:name});o.innerHTML="Importing poster...";fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json()}).then(function(r){o.innerHTML=r.success?"Poster imported and set as the featured image.":"Could not import that poster.";if(r.success&&window.wp&&wp.data&&r.data&&r.data.attachment_id){wp.data.dispatch("core/editor").editPost({featured_media:Number(r.data.attachment_id)});}}).catch(function(){o.innerHTML="Poster import failed.";});};b.onclick=function(){o.innerHTML="Searching...";var d=new URLSearchParams({action:"roxy_social_hangar_search",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_search')) . '",term:q.value});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json()}).then(function(r){if(!r.success){o.innerHTML="Search failed.";return;}var images=r.data.filter(function(a){return /\.(jpe?g|png|webp)$/i.test(a.filename);});if(!images.length){o.innerHTML="No poster images found.";return;}o.innerHTML="<table class=\"widefat striped\"><thead><tr><th>Preview</th><th>Asset</th><th>Details</th><th>Action</th></tr></thead><tbody>"+images.map(function(a){var thumb=ajaxurl+"?action=roxy_social_hangar_thumbnail&nonce=' . esc_js(wp_create_nonce('roxy_social_hangar_thumbnail')) . '&asset_id="+Number(a.asset_id);return "<tr><td><img src=\""+thumb+"\" alt=\"\" style=\"width:72px;height:96px;object-fit:contain;background:#f0f0f1\"></td><td>"+esc(a.filename)+"</td><td>"+esc(a.asset_category)+"<br>"+esc(a.runtime)+"</td><td><button type=\"button\" class=\"button\" data-id=\""+Number(a.asset_id)+"\" data-name=\""+esc(a.filename)+"\" onclick=\"roxyImportFeatured(Number(this.dataset.id),this.dataset.name)\">Use as Featured Image</button></td></tr>"}).join("")+"</tbody></table>";}).catch(function(){o.innerHTML="Search failed.";});};function esc(v){return String(v||"").replace(/[&<>\"\x27]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#039;"})[c];});}})();</script>';
    }

    public static function render_page(): void {
        if (!roxy_suite_user_can_access_admin()) return;
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'drafts';
        echo '<div class="wrap"><h1>Social Posts</h1><nav class="nav-tab-wrapper">';
        echo '<a class="nav-tab' . ($tab === 'drafts' ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-social-posts')) . '">Drafts</a>';
        echo '<a class="nav-tab' . ($tab === 'hangar' ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-social-posts&tab=hangar')) . '">Hangar Assets</a>';
        echo '<a class="nav-tab' . ($tab === 'meta' ? ' nav-tab-active' : '') . '" href="' . esc_url(admin_url('admin.php?page=roxy-social-posts&tab=meta')) . '">Meta Connection</a></nav>';
        if ($tab === 'meta') { self::render_meta_page(); echo '</div>'; return; }
        if ($tab === 'hangar') { self::render_hangar_page(); echo '</div>'; return; }
        $auto_approve = (bool) get_option('roxy_social_auto_approve', false);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin:16px 0 18px;padding:12px 14px;border:1px solid #c3c4c7;background:#fff;max-width:900px">';
        echo '<input type="hidden" name="action" value="roxy_social_auto_approve">' . wp_nonce_field('roxy_social_auto_approve', '_wpnonce', true, false);
        echo '<label style="display:flex;align-items:center;gap:8px"><input type="checkbox" name="auto_approve" value="1"' . checked($auto_approve, true, false) . '> <strong>Auto-approve new showing drafts</strong></label>';
        echo '<p class="description" style="margin:6px 0 0 24px">When enabled, newly generated showing drafts are approved after their Hangar media is assigned. Manual posts and existing drafts are not changed.</p>';
        echo '<p style="margin:10px 0 0 24px"><button type="submit" class="button">Save auto-approve setting</button></p></form>';
        self::render_manual_form();
        $rows = Store::all_recent();
        $filter = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : 'all';
        if ($filter !== 'all') $rows = array_values(array_filter($rows, static function ($row) use ($filter) { return (string) $row['status'] === $filter; }));
        echo '<p>Review showing-based drafts here. Only approved posts publish when their scheduled time arrives.</p>';
        echo '<p><strong>After publishing:</strong> View, edit, or delete live posts in <a href="https://business.facebook.com/latest/posts/published_posts/?asset_id=297533574006330&amp;ir_qe_exposed=1&amp;business_id=624729991216395" target="_blank" rel="noopener noreferrer">Meta Business Suite</a>.</p>';
        echo '<p><strong>Show:</strong> ';
        foreach (['all' => 'All', 'draft' => 'Drafts', 'approved' => 'Approved', 'publishing' => 'Publishing', 'posted' => 'Posted', 'failed' => 'Failed'] as $key => $label) echo '<a class="button' . ($filter === $key ? ' button-primary' : '') . '" style="margin-right:5px" href="' . esc_url(add_query_arg(['page' => 'roxy-social-posts', 'status' => $key], admin_url('admin.php'))) . '">' . esc_html($label) . '</a>';
        echo '</p>';
        if (!$rows) {
            echo '<div class="notice notice-info"><p>Save a Friday, Saturday, or Sunday showing to create its five-post campaign.</p></div></div>';
            return;
        }
        echo '<table class="widefat striped"><thead><tr><th>Scheduled</th><th>Campaign</th><th>Post</th><th>Media</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            $scheduled = date_create((string) $row['scheduled_for'], wp_timezone());
            $local_value = $scheduled ? $scheduled->format('Y-m-d\\TH:i') : (string) $row['scheduled_for'];
            echo '<tr><td><input form="roxy-social-draft-' . (int) $row['id'] . '" type="datetime-local" name="scheduled_for" value="' . esc_attr($local_value) . '" style="width:155px"></td>';
            echo '<td>' . esc_html(ucwords(str_replace('-', ' ', (string) $row['campaign_key']))) . '</td>';
            echo '<td><textarea form="roxy-social-draft-' . (int) $row['id'] . '" name="post_text" rows="5" style="width:100%;min-width:320px">' . esc_textarea($row['post_text']) . '</textarea></td>';
            $media_label = $row['media_type'] === 'video' ? 'Video' : 'Poster';
            echo '<td>' . ($row['media_url'] ? '<a href="' . esc_url($row['media_url']) . '" target="_blank">' . ($row['media_type'] === 'video' ? '<span style="display:block;width:72px;height:48px;background:#1d2327;color:#fff;text-align:center;padding-top:24px">Video</span>' : '<img src="' . esc_url($row['media_url']) . '" alt="Poster" style="width:72px;height:96px;object-fit:contain;display:block">') . esc_html($media_label) . '</a>' : '&mdash;');
            if ($row['trailer_url']) echo '<br><a href="' . esc_url($row['trailer_url']) . '" target="_blank">Trailer</a>';
            if (!empty($row['hangar_filename'])) echo '<br><strong>Hangar:</strong> ' . esc_html($row['hangar_filename']);
            if ($row['media_type'] === 'video') {
                $dimensions = self::video_dimensions((string) $row['media_url']);
                if ($dimensions && ($dimensions[0] / max(1, $dimensions[1]) < 0.50 || $dimensions[0] / max(1, $dimensions[1]) > 0.65)) echo '<p class="description" style="color:#996800"><strong>Instagram note:</strong> ' . (int) $dimensions[0] . 'x' . (int) $dimensions[1] . ' is not a vertical 9:16 video. Review before approving.</p>';
            }
            if (!in_array($status, ['publishing', 'posted', 'removed'], true)) {
                echo '<p style="margin-top:6px"><button type="button" class="button roxy-social-media-button" data-form="roxy-social-draft-' . (int) $row['id'] . '">Choose new media</button></p>';
                if ($row['media_url']) echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:6px"><input type="hidden" name="action" value="roxy_social_remove_media"><input type="hidden" name="id" value="' . (int) $row['id'] . '">' . wp_nonce_field('roxy_social_remove_media_' . (int) $row['id'], '_wpnonce', true, false) . '<button class="button" type="submit" onclick="return confirm(\'Remove the selected media from this draft?\')">Remove media</button></form>';
            }
            $facebook_state = !empty($row['facebook_post_id']) ? 'Posted' : (($status === 'publishing') ? 'Publishing' : (($status === 'approved') ? 'Scheduled' : (($status === 'failed' && strpos((string) $row['last_error'], 'Facebook:') !== false) ? 'Failed' : 'Not posted')));
            $instagram_state = !empty($row['instagram_media_id']) ? 'Posted' : (($status === 'publishing') ? 'Publishing' : (($status === 'approved') ? 'Scheduled' : (($status === 'failed' && stripos((string) $row['last_error'], 'Instagram video is still processing') !== false) ? 'Processing' : (($status === 'failed' && strpos((string) $row['last_error'], 'Instagram:') !== false) ? 'Failed' : 'Not posted'))));
            echo '</td><td><strong>' . esc_html(ucwords(str_replace('_', ' ', $status))) . '</strong><br><span class="description">Facebook: ' . esc_html($facebook_state) . '<br>Instagram: ' . esc_html($instagram_state) . '</span></td><td><form id="roxy-social-draft-' . (int) $row['id'] . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="roxy_social_update_draft"><input type="hidden" name="id" value="' . (int) $row['id'] . '"><input type="hidden" name="media_url" value="' . esc_attr((string) $row['media_url']) . '"><input type="hidden" name="media_type" value="' . esc_attr((string) $row['media_type']) . '"><input type="hidden" name="media_changed" value="0">' . wp_nonce_field('roxy_social_update_draft_' . (int) $row['id'], '_wpnonce', true, false) . '<button class="button" type="submit">Save</button></form>';
            $has_published_ids = !empty($row['facebook_post_id']) || !empty($row['instagram_media_id']);
            if (in_array($status, ['draft', 'needs_review', 'failed'], true) && !($status === 'failed' && $has_published_ids)) self::action_link((int) $row['id'], 'approved', 'Approve');
            if ($status === 'approved') self::action_link((int) $row['id'], 'draft', 'Un-approve');
            if ($status === 'approved') {
                $publish_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_publish_now&id=' . (int) $row['id']), 'roxy_social_publish_now_' . (int) $row['id']);
                echo ' <a class="button button-primary" href="' . esc_url($publish_url) . '" onclick="return confirm(\'Post this approved draft now to its selected social accounts?\')">Post now</a>';
            }
            if ($status === 'failed' && $has_published_ids && (empty($row['facebook_post_id']) || empty($row['instagram_media_id']))) {
                $publish_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_publish_now&id=' . (int) $row['id']), 'roxy_social_publish_now_' . (int) $row['id']);
                echo ' <a class="button button-primary" href="' . esc_url($publish_url) . '" onclick="return confirm(\'Retry publishing the missing social account?\')">Retry publish</a>';
            }
            if ($status === 'failed' && $has_published_ids) {
                $remove_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_remove_published&id=' . (int) $row['id']), 'roxy_social_remove_published_' . (int) $row['id']);
                echo '<a class="button" style="margin-top:6px" href="' . esc_url($remove_url) . '" onclick="return confirm(\'Remove this post from Facebook and Instagram?\')">Retry remove</a>';
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_delete_draft&id=' . (int) $row['id']), 'roxy_social_delete_draft_' . (int) $row['id']);
                echo ' <a class="button" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this local draft? Any remaining social post must be removed in Meta Business Suite.\')">Delete</a>';
            } elseif ($status === 'posted') {
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_delete_draft&id=' . (int) $row['id']), 'roxy_social_delete_draft_' . (int) $row['id']);
                echo ' <a class="button" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this local draft? The live Facebook and Instagram posts will remain in Meta Business Suite.\')">Delete</a>';
            } elseif (!in_array($status, ['publishing', 'posted', 'removed'], true)) {
                $delete_url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_delete_draft&id=' . (int) $row['id']), 'roxy_social_delete_draft_' . (int) $row['id']);
                echo ' <a class="button" href="' . esc_url($delete_url) . '" onclick="return confirm(\'Delete this unposted draft and its temporary media?\')">Delete</a>';
            }
            if (!empty($row['last_error'])) echo '<p class="description" style="color:#b32d2e">' . esc_html($row['last_error']) . '</p>';
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo '<script>window.roxySocialPicker=' . wp_json_encode(['ajaxurl' => admin_url('admin-ajax.php'), 'searchNonce' => wp_create_nonce('roxy_social_hangar_search'), 'assignNonce' => wp_create_nonce('roxy_social_hangar_assign')]) . ';</script><script src="' . esc_url(content_url('plugins/roxy-suite/includes/modules/social-publisher/assets/draft-media-picker.js?ver=2')) . '"></script>';
        echo '<script>(function(){var activeForm=null;document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".roxy-social-media-button");if(!b)return;activeForm=document.getElementById(b.dataset.form);e.preventDefault();e.stopImmediatePropagation();var m=document.createElement("div");m.style="position:fixed;z-index:100000;inset:8% 12%;background:#fff;border:1px solid #8c8f94;box-shadow:0 4px 18px rgba(0,0,0,.25);padding:18px;overflow:auto";m.innerHTML="<button type=button class=\"button\">Close</button><h2>Choose draft media</h2><p><button type=button class=\"button button-primary\">Media Library</button> <button type=button class=\"button\">Hangar</button></p><div></div>";document.body.appendChild(m);m.querySelector("button").onclick=function(){m.remove();};var buttons=m.querySelectorAll("h2+p button"),panel=m.querySelector("div");buttons[0].onclick=function(){if(!window.wp||!wp.media){alert("The WordPress media library is not available. Reload and try again.");return;}var f=wp.media({title:"Choose draft media",button:{text:"Use this media"},multiple:false});f.on("select",function(){var a=f.state().get("selection").first().toJSON(),url=a.url||"",low=url.toLowerCase(),type=low.indexOf(".mp4")>=0||low.indexOf(".mov")>=0||low.indexOf(".m4v")>=0||low.indexOf(".webm")>=0?"video":"image";activeForm.querySelector("[name=media_url]").value=url;activeForm.querySelector("[name=media_type]").value=type;activeForm.querySelector("[name=media_changed]").value="1";m.remove();});f.open();};buttons[1].onclick=function(){var saved=null;try{saved=JSON.parse(localStorage.getItem("roxy_social_hangar_results")||"null");}catch(x){}if(!saved||!saved.html){panel.innerHTML="<p>No saved Hangar search. Search from the Hangar Assets tab first.</p>";return;}var holder=document.createElement("div");holder.innerHTML=saved.html;var table=holder.querySelector("table");panel.innerHTML="<p class=description>Showing the last Hangar search. Use the Hangar tab to refresh it.</p>";if(!table)return panel.innerHTML+="<p>No saved Hangar results.</p>";panel.appendChild(table);table.querySelectorAll("button[data-id]").forEach(function(use){use.removeAttribute("onclick");use.onclick=function(){use.disabled=true;use.textContent="Importing...";var d=new URLSearchParams({action:"roxy_social_hangar_assign",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_assign')) . '",post_id:activeForm.querySelector("[name=id]").value,asset_id:use.dataset.id,filename:use.dataset.name});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(x){if(!x.success){use.disabled=false;use.textContent="Try again";return;}activeForm.querySelector("[name=media_url]").value=x.data.url;activeForm.querySelector("[name=media_type]").value=x.data.media_type;activeForm.querySelector("[name=media_changed]").value="0";m.remove();});};});};})();</script>';
        echo '<script>(function(){var activeForm=null;document.addEventListener("click",function(e){var b=e.target.closest&&e.target.closest(".roxy-social-media-button");if(b)activeForm=document.getElementById(b.dataset.form);},true);function bind(m){if(m.dataset.roxyBound||!activeForm)return;m.dataset.roxyBound="1";var close=m.querySelector("button"),heading=m.querySelector("h2"),tabs=heading?heading.nextElementSibling.querySelectorAll("button"):[],panel=m.querySelector("div");if(close)close.onclick=function(){m.remove();};if(tabs.length<2||!panel)return;tabs[0].onclick=function(){if(!window.wp||!wp.media){alert("The WordPress media library is not available. Reload and try again.");return;}var frame=wp.media({title:"Choose draft media",button:{text:"Use this media"},multiple:false});frame.on("select",function(){var a=frame.state().get("selection").first().toJSON(),url=a.url||"",type=(a.mime||"").indexOf("video/")===0||/\\.(mp4|mov|m4v|webm|avi|mkv)(?:[?#]|$)/i.test(url)?"video":"image";activeForm.querySelector("[name=media_url]").value=url;activeForm.querySelector("[name=media_type]").value=type;activeForm.querySelector("[name=media_changed]").value="1";activeForm.dataset.roxyMediaReady="1";m.remove();});frame.open();};tabs[1].onclick=function(){var input=panel.querySelector("input[type=search]"),search=panel.querySelector("button"),out=panel.querySelector("div")||panel;if(!input||!search)return;search.onclick=function(){out.textContent="Searching...";var d=new URLSearchParams({action:"roxy_social_hangar_search",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_search')) . '",term:input.value});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(r){if(!r.success||!r.data.length){out.textContent="No Hangar assets found.";return;}out.innerHTML="<table class=\"widefat striped\"><thead><tr><th>Asset</th><th>Type</th><th>Details</th><th>Action</th></tr></thead><tbody>"+r.data.map(function(a){return "<tr><td>"+String(a.filename||a.asset_name).replace(/[&<>]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;"})[c];})+"</td><td>"+String(a.asset_category||a.file_type)+"</td><td>"+String(a.runtime||"")+"</td><td><button type=button class=\"button\" data-id=\""+Number(a.asset_id)+"\" data-name=\""+String(a.filename||"").replace(/\"/g,"&quot;")+"">Use</button></td></tr>";}).join("")+"</tbody></table>";out.querySelectorAll("button[data-id]").forEach(function(use){use.onclick=function(){use.disabled=true;use.textContent="Importing...";var d=new URLSearchParams({action:"roxy_social_hangar_assign",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_assign')) . '",post_id:activeForm.querySelector("[name=id]").value,asset_id:use.dataset.id,filename:use.dataset.name});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(x){if(!x.success){use.disabled=false;use.textContent="Try again";return;}activeForm.querySelector("[name=media_url]").value=x.data.url;activeForm.querySelector("[name=media_type]").value=x.data.media_type;activeForm.querySelector("[name=media_changed]").value="0";m.remove();});};});});};};tabs[1].click();}var observer=new MutationObserver(function(){document.querySelectorAll("div[style*=position\\:fixed]").forEach(bind);});observer.observe(document.body,{childList:true});})();</script>';
        echo '<script>(function(){document.addEventListener("click",function(event){var button=event.target.closest&&event.target.closest(".roxy-social-media-button");if(!button)return;var saved;try{saved=JSON.parse(localStorage.getItem("roxy_social_hangar_results")||"null");}catch(e){saved=null;}if(!saved||!saved.html)return;event.preventDefault();event.stopImmediatePropagation();var form=document.getElementById(button.dataset.form),modal=document.createElement("div");modal.style="position:fixed;z-index:100000;inset:8% 12%;background:#fff;border:1px solid #8c8f94;box-shadow:0 4px 18px rgba(0,0,0,.25);padding:18px;overflow:auto";modal.innerHTML="<button type=button class=\"button\" style=\"float:right\">Close</button><h2 style=\"margin-top:0\">Choose draft media</h2><p><button type=button class=\"button\">Media Library</button> <button type=button class=\"button button-primary\">Hangar</button></p><p class=description>Showing the last Hangar search. Use the Hangar tab to refresh it.</p><div></div>";document.body.appendChild(modal);modal.querySelector("button").onclick=function(){modal.remove();};var panel=modal.querySelector("h2+p+div"),holder=document.createElement("div");holder.innerHTML=saved.html;panel.appendChild(holder.firstElementChild||holder);var table=panel.querySelector("table"),sortKey="",sortDir=1;function sort(){var body=table&&table.querySelector("tbody");if(!body)return;var rows=Array.from(body.querySelectorAll("tr"));rows.sort(function(a,b){var av=a.dataset[sortKey]||"",bv=b.dataset[sortKey]||"";return av<bv?-1*sortDir:av>bv?1*sortDir:0;});rows.forEach(function(row){body.appendChild(row);});}if(table)table.querySelectorAll("[data-sort]").forEach(function(head){head.onclick=function(){if(sortKey===head.dataset.sort)sortDir*=-1;else{sortKey=head.dataset.sort;sortDir=1;}sort();};});if(table)table.querySelectorAll("button[data-id]").forEach(function(use){use.removeAttribute("onclick");use.onclick=function(){use.disabled=true;use.textContent="Importing...";var d=new URLSearchParams({action:"roxy_social_hangar_assign",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_assign')) . '",post_id:form.querySelector("[name=id]").value,asset_id:use.dataset.id,filename:use.dataset.name});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(x){if(!x.success){use.disabled=false;use.textContent="Try again";return;}form.querySelector("[name=media_url]").value=x.data.url;form.querySelector("[name=media_type]").value=x.data.media_type;form.querySelector("[name=media_changed]").value="0";button.textContent="Media selected - click Save";modal.remove();});};});},true);})();</script>';
        echo '<script>(function(){document.addEventListener("click",function(event){var button=event.target.closest&&event.target.closest(".roxy-social-media-button");if(!button)return;event.preventDefault();event.stopImmediatePropagation();var form=document.getElementById(button.dataset.form),modal=document.createElement("div");modal.style="position:fixed;z-index:100000;inset:8% 12%;background:#fff;border:1px solid #8c8f94;box-shadow:0 4px 18px rgba(0,0,0,.25);padding:18px;overflow:auto";modal.innerHTML="<button type=button class=\"button\" style=\"float:right\">Close</button><h2 style=\"margin-top:0\">Choose draft media</h2><p><button type=button class=\"button button-primary\">Media Library</button> <button type=button class=\"button\">Hangar</button></p><div><p><input type=search class=regular-text placeholder=\"Movie title\"> <button type=button class=\"button button-primary\">Search Hangar</button></p><div></div></div>";document.body.appendChild(modal);modal.querySelector("body");modal.querySelector("button").onclick=function(){modal.remove();};var formUrl=form.querySelector("[name=media_url]"),formType=form.querySelector("[name=media_type]"),changed=form.querySelector("[name=media_changed]"),tabs=modal.querySelectorAll("p:first-of-type button"),panel=modal.querySelector("h2+p+div"),input=panel.querySelector("input"),search=panel.querySelector("button"),out=panel.querySelector("div");function select(url,type,isChanged){formUrl.value=url;formType.value=type;changed.value=isChanged?"1":"0";button.textContent="Media selected - click Save";modal.remove();}tabs[0].onclick=function(){if(!window.wp||!wp.media){alert("The WordPress media library is not available. Reload and try again.");return;}var frame=wp.media({title:"Choose draft media",button:{text:"Use this media"},multiple:false});frame.on("select",function(){var a=frame.state().get("selection").first().toJSON(),url=a.url||"",type=(a.mime||"").indexOf("video/")===0||/\\.(mp4|mov|m4v|webm|avi|mkv)(?:[?#]|$)/i.test(url)?"video":"image";select(url,type,true);});frame.open();};tabs[1].onclick=function(){panel.style.display="block";};search.onclick=function(){out.textContent="Searching...";var d=new URLSearchParams({action:"roxy_social_hangar_search",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_search')) . '",term:input.value});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(r){if(!r.success||!r.data.length){out.textContent="No Hangar assets found.";return;}out.innerHTML="<table class=\"widefat striped\"><tbody>"+r.data.map(function(a){return "<tr><td>"+String(a.filename||a.asset_name).replace(/[&<>]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;"})[c];})+"</td><td><button type=button class=\"button\" data-id=\""+Number(a.asset_id)+"\" data-name=\""+String(a.filename||"").replace(/\"/g,"&quot;")+"\">Use</button></td></tr>";}).join("")+"</tbody></table>";out.querySelectorAll("button[data-id]").forEach(function(use){use.onclick=function(){use.disabled=true;use.textContent="Importing...";var d=new URLSearchParams({action:"roxy_social_hangar_assign",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_assign')) . '",post_id:form.querySelector("[name=id]").value,asset_id:use.dataset.id,filename:use.dataset.name});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json();}).then(function(x){if(!x.success){use.disabled=false;use.textContent="Try again";return;}select(x.data.url,x.data.media_type,false);});};});}).catch(function(){out.textContent="Hangar search failed.";});};},true);})();</script>';
        echo '<script>(function(){document.querySelectorAll(".roxy-social-media-button").forEach(function(b){b.addEventListener("click",function(){if(!window.wp||!wp.media){window.alert("The WordPress media library is not available. Please reload this page and try again.");return;}var f=document.getElementById(b.dataset.form),url=f.querySelector("[name=media_url]"),type=f.querySelector("[name=media_type]"),frame=wp.media({title:"Choose draft media",button:{text:"Use this media"},multiple:false});frame.on("select",function(){var a=frame.state().get("selection").first().toJSON(),mime=a.mime||"",value=a.url||"";url.value=value;type.value=mime.indexOf("video/")===0||/\\.(mp4|mov|m4v|webm|avi|mkv)(?:[?#]|$)/i.test(value)?"video":"image";b.textContent="Media selected - click Save";});frame.open();});});})();</script>';
    }

    private static function video_dimensions(string $url): ?array {
        $attachment_id = $url !== '' ? attachment_url_to_postid($url) : 0;
        if (!$attachment_id) return null;
        $metadata = wp_get_attachment_metadata($attachment_id);
        $width = (int) ($metadata['width'] ?? 0);
        $height = (int) ($metadata['height'] ?? 0);
        return $width > 0 && $height > 0 ? [$width, $height] : null;
    }

    public static function update_draft(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('roxy_social_update_draft_' . $id);
        $text = sanitize_textarea_field((string) ($_POST['post_text'] ?? ''));
        $scheduled = sanitize_text_field((string) ($_POST['scheduled_for'] ?? ''));
        $parsed = date_create($scheduled, wp_timezone());
        $media_url = isset($_POST['media_url']) ? esc_url_raw((string) $_POST['media_url']) : null;
        $media_type = isset($_POST['media_type']) ? sanitize_key((string) $_POST['media_type']) : null;
        $media_changed = isset($_POST['media_changed']) && (string) $_POST['media_changed'] === '1';
        if (!$media_changed) $media_url = null;
        if ($id > 0 && $text !== '' && $parsed) {
            $old = Store::find($id);
            $old_temporary = $old && $media_url !== null && (string) $old['media_url'] !== $media_url ? (int) ($old['temporary_attachment_id'] ?? 0) : 0;
            Store::update_draft($id, $text, $parsed->format('Y-m-d H:i:s'), $media_url, $media_type);
            if ($old_temporary) wp_delete_attachment($old_temporary, true);
        }
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&updated=1'));
        exit;
    }

    public static function create_manual(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_create_manual');
        $text = sanitize_textarea_field((string) ($_POST['post_text'] ?? ''));
        $scheduled = date_create(sanitize_text_field((string) ($_POST['scheduled_for'] ?? '')), wp_timezone());
        $media_url = esc_url_raw((string) ($_POST['media_url'] ?? ''));
        $media_type = self::media_type_from_url($media_url);
        if ($text === '' || !$scheduled) wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&manual_error=missing'));
        else { Store::create_manual(['post_text' => $text, 'scheduled_for' => $scheduled->format('Y-m-d H:i:s'), 'platform' => sanitize_key((string) ($_POST['platform'] ?? 'both')), 'media_url' => $media_url, 'media_type' => $media_type]); wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&manual_created=1')); }
        exit;
    }

    private static function render_manual_form(): void {
        $default_time = wp_date('Y-m-d\\TH:i', current_time('timestamp') + 600, wp_timezone());
        if (isset($_GET['manual_created'])) echo '<div class="notice notice-success is-dismissible"><p>Manual post draft created. Review it below, then approve it when ready.</p></div>';
        if (isset($_GET['manual_error'])) echo '<div class="notice notice-error is-dismissible"><p>Enter post text and a valid scheduled time.</p></div>';
        echo '<details style="margin:16px 0 22px;max-width:900px"><summary class="button button-primary">Create Manual Post</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;padding:14px;border:1px solid #c3c4c7;background:#fff">';
        echo '<input type="hidden" name="action" value="roxy_social_create_manual">' . wp_nonce_field('roxy_social_create_manual', '_wpnonce', true, false);
        echo '<p><label><strong>Post text</strong><br><textarea name="post_text" rows="5" style="width:100%;max-width:760px" required placeholder="Write the post caption..."></textarea></label></p>';
        echo '<p><label><strong>Scheduled for</strong><br><input type="datetime-local" name="scheduled_for" value="' . esc_attr($default_time) . '" required></label> <label style="margin-left:18px"><strong>Send to</strong><br><select name="platform"><option value="both">Facebook and Instagram</option><option value="facebook">Facebook only</option><option value="instagram">Instagram only</option></select></label></p>';
        echo '<p><label><strong>Media URL</strong><br><input id="roxy-manual-media-url" type="url" name="media_url" style="width:100%;max-width:760px" placeholder="Optional for Facebook-only text posts"></label> <button type="button" class="button" id="roxy-manual-media-button">Choose from Media Library</button></p>';
        echo '<p class="description">Manual posts use the same approval and scheduling process as showing posts. Media type is detected automatically. Instagram posts require media.</p><p><button type="submit" class="button button-primary">Save Manual Draft</button></p></form></details>';
        echo '<script>(function(){var b=document.getElementById("roxy-manual-media-button"),u=document.getElementById("roxy-manual-media-url"),tries=0;function bind(){if(!b||!u)return;if(!window.wp||!wp.media){if(tries++<100)window.setTimeout(bind,100);return;}b.onclick=function(){var frame=wp.media({title:"Choose post media",button:{text:"Use this media"},multiple:false});frame.on("select",function(){var a=frame.state().get("selection").first().toJSON();u.value=a.url||"";});frame.open();};}bind();})();</script>';
    }

    private static function media_type_from_url(string $url): string {
        return preg_match('/\.(mp4|mov|m4v|webm|avi|mkv)(?:[?#]|$)/i', $url) ? 'video' : 'image';
    }

    public static function remove_media(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('roxy_social_remove_media_' . $id);
        $temporary_id = $id > 0 ? Store::clear_media($id) : null;
        if ($temporary_id) wp_delete_attachment($temporary_id, true);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&removed_media=1'));
        exit;
    }

    public static function delete_draft(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('roxy_social_delete_draft_' . $id);
        $temporary_id = $id > 0 ? Store::delete_unposted($id) : null;
        if ($temporary_id) wp_delete_attachment($temporary_id, true);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&deleted_draft=1'));
        exit;
    }

    public static function publish_now(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('roxy_social_publish_now_' . $id);
        \RoxySocial\Publisher::queue_publish_now($id);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&published_now=1'));
        exit;
    }

    public static function remove_published(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('roxy_social_remove_published_' . $id);
        Publisher::remove_published($id);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&removed_live=1'));
        exit;
    }

    private static function render_hangar_page(): void {
        echo '<h2>Hangar Connection</h2><p>Connect your Hangar account to search approved campaign assets. Credentials are encrypted before storage.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:560px">';
        echo '<input type="hidden" name="action" value="roxy_social_hangar_settings">';
        wp_nonce_field('roxy_social_hangar_settings');
        echo '<table class="form-table"><tr><th><label for="roxy-hangar-user">Username</label></th><td><input class="regular-text" id="roxy-hangar-user" name="hangar_user" type="text" value="' . esc_attr((string) get_option('roxy_social_hangar_user', '')) . '"></td></tr>';
        echo '<tr><th><label for="roxy-hangar-pass">Password</label></th><td><input class="regular-text" id="roxy-hangar-pass" name="hangar_pass" type="password" value="" autocomplete="new-password"><p class="description">Leave blank to keep the saved password.</p></td></tr></table>';
        submit_button('Save Hangar Connection'); echo '</form>';
        $drafts = Store::all_recent();
        echo '<hr><h2>Search Assets</h2><p><label for="roxy-hangar-target"><strong>Assign to draft</strong></label> <select id="roxy-hangar-target"><option value="0">Choose a draft</option>';
        foreach ($drafts as $draft) { $scheduled = date_create((string) $draft['scheduled_for'], wp_timezone()); $label = ($scheduled ? wp_date('M j', $scheduled->getTimestamp(), wp_timezone()) : (string) $draft['scheduled_for']) . ' — ' . $draft['campaign_key']; if (!empty($draft['hangar_asset_id'])) $label .= ' [Hangar assigned]'; echo '<option value="' . (int) $draft['id'] . '">' . esc_html($label) . '</option>'; }
        echo '</select> <label for="roxy-hangar-type"><strong>Type</strong></label> <select id="roxy-hangar-type"><option value="">All types</option><option>Social Media Graphic</option><option>Social Media Video</option><option>Digital Lobby Poster</option><option>Production Still</option><option>Web Banners - Static</option></select></p><p><input id="roxy-hangar-search" type="search" class="regular-text" placeholder="Movie title"><button type="button" class="button button-primary" id="roxy-hangar-search-button">Search</button></p><p class="description">Click a column heading to sort. Click it again to reverse the order. Type filters the results already loaded.</p><div id="roxy-hangar-results"></div>';
        echo '<script>(function(){var b=document.getElementById("roxy-hangar-search-button"),q=document.getElementById("roxy-hangar-search"),o=document.getElementById("roxy-hangar-results"),t=document.getElementById("roxy-hangar-target"),typeFilter=document.getElementById("roxy-hangar-type");if(!b)return;var sortKey="",sortDir=1;function esc(v){return String(v||"").replace(/[&<>"\x27]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#039;"})[c];});}function renderRows(){var table=o.querySelector("table"),body=table&&table.querySelector("tbody");if(!body)return;var rows=Array.from(body.querySelectorAll("tr"));rows.forEach(function(row){row.style.display=!typeFilter.value||row.dataset.type===typeFilter.value?"":"none";});rows.sort(function(a,b){var av=a.dataset[sortKey]||"",bv=b.dataset[sortKey]||"";if(sortKey==="date"){av=Date.parse(av)||0;bv=Date.parse(bv)||0;}else{av=av.toLowerCase();bv=bv.toLowerCase();}return av<bv?-1*sortDir:av>bv?1*sortDir:0;});rows.forEach(function(row){body.appendChild(row);});}window.roxyAssignHangar=function(id,name,button){if(!t||!t.value){alert("Choose a draft first.");return;}button.disabled=true;button.textContent="Downloading...";var d=new URLSearchParams({action:"roxy_social_hangar_assign",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_assign')) . '",post_id:t.value,asset_id:id,filename:name});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json()}).then(function(r){button.textContent=r.success?"Assigned":"Try again";if(!r.success)button.disabled=false;}).catch(function(){button.textContent="Try again";button.disabled=false;});};b.onclick=function(){o.innerHTML="Searching...";var d=new URLSearchParams({action:"roxy_social_hangar_search",nonce:"' . esc_js(wp_create_nonce('roxy_social_hangar_search')) . '",term:q.value});fetch(ajaxurl,{method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:d}).then(function(r){return r.json()}).then(function(r){if(!r.success){o.innerHTML="<p>Search failed. Check the Hangar connection.</p>";return;}if(!r.data.length){o.innerHTML="<p>No assets found.</p>";return;}o.innerHTML="<table class=\"widefat striped\"><thead><tr><th><button type=\"button\" class=\"button-link\" data-sort=\"name\">Asset</button></th><th><button type=\"button\" class=\"button-link\" data-sort=\"type\">Type</button></th><th><button type=\"button\" class=\"button-link\" data-sort=\"date\">Date</button></th><th>Details</th><th>Action</th></tr></thead><tbody>"+r.data.map(function(a){var thumb=ajaxurl+"?action=roxy_social_hangar_thumbnail&nonce=' . esc_js(wp_create_nonce('roxy_social_hangar_thumbnail')) . '&asset_id="+Number(a.asset_id);return "<tr data-name=\""+esc(a.asset_name||a.filename)+"\" data-type=\""+esc(a.asset_category||a.file_type)+"\" data-date=\""+esc(a.start_date)+"\"><td><img src=\""+thumb+"\" alt=\"\" style=\"width:72px;height:96px;object-fit:contain;background:#f0f0f1;vertical-align:middle;margin-right:8px\"><strong>"+esc(a.asset_name)+"</strong><br>"+esc(a.filename)+"</td><td>"+esc(a.asset_category||a.file_type)+"</td><td>"+esc(a.start_date)+"</td><td>"+esc(a.runtime)+"<br>"+esc(a.description)+"</td><td><button type=\"button\" class=\"button\" data-id=\""+Number(a.asset_id)+"\" data-name=\""+esc(a.filename)+"\" onclick=\"roxyAssignHangar(Number(this.dataset.id),this.dataset.name,this)\">Use for Draft</button></td></tr>"}).join("")+"</tbody></table>";o.querySelectorAll("[data-sort]").forEach(function(button){button.onclick=function(){if(sortKey===button.dataset.sort)sortDir*=-1;else{sortKey=button.dataset.sort;sortDir=1;}renderRows();};});renderRows();}).catch(function(){o.innerHTML="<p>Search failed.</p>";});};if(typeFilter)typeFilter.onchange=renderRows;if(q)q.onkeydown=function(event){if(event.key==="Enter"){event.preventDefault();b.click();}};function esc(v){return String(v||"").replace(/[&<>\"\x27]/g,function(c){return ({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","\x27":"&#039;"})[c];});}})();</script>';
        echo '<script>(function(){var root=document.getElementById("roxy-hangar-results"),query=document.getElementById("roxy-hangar-search");if(!root)return;try{var saved=JSON.parse(localStorage.getItem("roxy_social_hangar_results")||"null");if(saved&&saved.html){if(query)query.value=saved.term||"";root.innerHTML=saved.html;}}catch(e){}var observer=new MutationObserver(function(){if(root.innerHTML&&root.innerHTML.indexOf("Searching...")===-1&&root.innerHTML.indexOf("Search failed")===-1){try{localStorage.setItem("roxy_social_hangar_results",JSON.stringify({term:query?query.value:"",html:root.innerHTML}));}catch(e){}}});observer.observe(root,{childList:true,subtree:true});})();</script>';
        $assigned_assets = [];
        foreach ($drafts as $draft) if (!empty($draft['hangar_asset_id'])) $assigned_assets[] = (int) $draft['hangar_asset_id'];
        echo '<script>(function(){var assigned=' . wp_json_encode(array_values(array_unique($assigned_assets))) . ';var root=document.getElementById("roxy-hangar-results");if(!root)return;var update=function(){root.querySelectorAll("button[data-id]").forEach(function(button){if(assigned.indexOf(Number(button.dataset.id))!==-1){button.disabled=true;button.textContent="Assigned";}});};update();setInterval(update,1000);})();</script>';
    }

    private static function render_meta_page(): void {
        $guide_url = content_url('plugins/roxy-suite/docs/meta-social-connection-setup.txt');
        echo '<h2>Meta Connection</h2><p>Connect the Facebook Page and Instagram professional account used for Roxy social posts. Nothing will publish until a draft is approved. <a href="' . esc_url($guide_url) . '" target="_blank" rel="noopener">Meta setup instructions</a></p>';
        if (isset($_GET['saved'])) echo '<div class="notice notice-success is-dismissible"><p>Meta connection settings saved.</p></div>';
        if (isset($_GET['meta_connected'])) echo '<div class="notice notice-success is-dismissible"><p>Meta authorization completed. The account connection is ready for verification.</p></div>';
        if (isset($_GET['meta_error'])) echo '<div class="notice notice-error is-dismissible"><p>Meta authorization could not be completed. Please try again.</p></div>';
        $verified = sanitize_key((string) ($_GET['meta_verified'] ?? ''));
        if ($verified === 'success') echo '<div class="notice notice-success is-dismissible"><p>Meta connection verified for ' . esc_html(Meta::page_name()) . ' and Instagram @' . esc_html(Meta::instagram_username()) . '.</p></div>';
        elseif ($verified === 'no_instagram') echo '<div class="notice notice-warning is-dismissible"><p>Facebook Page verified, but no linked Instagram professional account was found.</p></div>';
        elseif ($verified === 'missing') echo '<div class="notice notice-error is-dismissible"><p>Authorize Meta first, then verify the connection.</p></div>';
        elseif ($verified === 'failed') echo '<div class="notice notice-error is-dismissible"><p>Meta could not return any Pages for this authorization. Check the Page permissions and try again.</p></div>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="max-width:720px">';
        echo '<input type="hidden" name="action" value="roxy_social_meta_settings">';
        wp_nonce_field('roxy_social_meta_settings');
        echo '<table class="form-table"><tr><th><label for="roxy-meta-app-id">Meta App ID</label></th><td><input class="regular-text" id="roxy-meta-app-id" name="meta_app_id" value="' . esc_attr(Meta::app_id()) . '"><p class="description">The App ID from Meta for Developers.</p></td></tr>';
        echo '<tr><th><label for="roxy-meta-app-secret">Meta App Secret</label></th><td><input class="regular-text" id="roxy-meta-app-secret" name="meta_app_secret" type="password" autocomplete="new-password"><p class="description">Leave blank to keep the saved secret.</p></td></tr>';
        echo '<tr><th><label for="roxy-meta-page-id">Facebook Page ID</label></th><td><input class="regular-text" id="roxy-meta-page-id" name="meta_page_id" value="' . esc_attr(Meta::page_id()) . '"><input type="hidden" name="meta_page_name" value="' . esc_attr(Meta::page_name()) . '"></td></tr>';
        echo '<tr><th><label for="roxy-meta-instagram-id">Instagram User ID</label></th><td><input class="regular-text" id="roxy-meta-instagram-id" name="meta_instagram_user_id" value="' . esc_attr(Meta::instagram_user_id()) . '"><input type="hidden" name="meta_instagram_username" value="' . esc_attr(Meta::instagram_username()) . '"><p class="description">Use the connected Instagram professional account ID.</p></td></tr>';
        echo '<tr><th><label for="roxy-meta-access-token">Access Token</label></th><td><input class="regular-text" id="roxy-meta-access-token" name="meta_access_token" type="password" autocomplete="new-password"><p class="description">Leave blank to keep the saved token. It is encrypted before storage.</p></td></tr></table>';
        submit_button('Save Meta Connection');
        if (Meta::app_id() !== '' && Meta::app_secret_saved()) echo '<p><a class="button button-primary" href="' . esc_url(Meta::connect_url()) . '">Connect Facebook and Instagram</a></p><p class="description">Meta will ask you to authorize the connected Page and Instagram account.</p><p class="description">Callback URL: <code>' . esc_html(Meta::redirect_url()) . '</code></p>';
        echo '</form><hr><h3>Connection status</h3><p><strong>' . (Meta::configured() ? 'Credentials saved' : 'Not configured') . '</strong></p><p>The next step will verify the Page and Instagram account permissions before enabling publishing.</p>';
        if (Meta::access_token() !== '') echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="roxy_social_meta_verify">' . wp_nonce_field('roxy_social_meta_verify', '_wpnonce', true, false) . '<p><button type="submit" class="button">Verify connected accounts</button></p></form>';
    }

    public static function save_hangar_settings(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_hangar_settings');
        $user = sanitize_text_field((string) ($_POST['hangar_user'] ?? ''));
        $pass = (string) ($_POST['hangar_pass'] ?? '');
        if ($user !== '') update_option('roxy_social_hangar_user', $user, false);
        if ($pass !== '') Hangar::save_credentials($user ?: (string) get_option('roxy_social_hangar_user', ''), $pass);
        if (Hangar::has_credentials()) foreach (Store::all_recent() as $draft) {
            $showing_ids = array_filter(array_map('absint', explode(',', (string) $draft['showing_ids'])));
            $title = $showing_ids ? trim((string) get_the_title((int) reset($showing_ids))) : '';
            if ($title !== '' && !empty($draft['campaign_key']) && in_array((string) $draft['status'], ['draft', 'needs_review'], true) && !wp_next_scheduled('roxy_social_auto_assign_media', [(string) $draft['campaign_key'], $title, (int) reset($showing_ids)])) wp_schedule_single_event(time() + 5, 'roxy_social_auto_assign_media', [(string) $draft['campaign_key'], $title, (int) reset($showing_ids)]);
        }
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&tab=hangar&saved=1'));
        exit;
    }

    public static function save_auto_approve(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        check_admin_referer('roxy_social_auto_approve');
        update_option('roxy_social_auto_approve', !empty($_POST['auto_approve']), false);
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts&auto_approve_saved=1'));
        exit;
    }

    public static function ajax_hangar_search(): void {
        check_ajax_referer('roxy_social_hangar_search', 'nonce');
        if (!roxy_suite_user_can_access_admin()) wp_send_json_error('Insufficient permissions', 403);
        if (!Hangar::has_credentials()) wp_send_json_error('Hangar is not connected', 400);
        $assigned = [];
        foreach (Store::all_recent() as $draft) if (!empty($draft['hangar_asset_id'])) $assigned[(int) $draft['hangar_asset_id']] = true;
        $results = Hangar::search(sanitize_text_field((string) ($_POST['term'] ?? '')));
        foreach ($results as &$result) { $result['download_url'] = Hangar::download_url((int) $result['asset_id']); $result['assigned'] = !empty($assigned[(int) $result['asset_id']]); }
        wp_send_json_success($results);
    }

    public static function ajax_hangar_assign(): void {
        check_ajax_referer('roxy_social_hangar_assign', 'nonce');
        if (!roxy_suite_user_can_access_admin()) wp_send_json_error('Insufficient permissions', 403);
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $asset_id = isset($_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
        $filename = sanitize_text_field((string) ($_POST['filename'] ?? ''));
        if ($post_id <= 0 || $asset_id <= 0 || $filename === '') wp_send_json_error('Invalid asset assignment', 400);
        $draft = Store::find($post_id);
        $showing_ids = $draft ? explode(',', (string) $draft['showing_ids']) : [];
        $showing_id = !empty($showing_ids[0]) ? (int) $showing_ids[0] : 0;
        $attachment_id = Hangar::import_social_asset($asset_id, $filename, $showing_id, $post_id);
        if (!$attachment_id) wp_send_json_error('Download or assignment failed', 500);
        wp_send_json_success(['post_id' => $post_id, 'asset_id' => $asset_id, 'attachment_id' => $attachment_id, 'url' => wp_get_attachment_url($attachment_id) ?: '', 'media_type' => in_array(strtolower((string) pathinfo($filename, PATHINFO_EXTENSION)), ['mp4', 'mov', 'm4v'], true) ? 'video' : 'image']);
    }

    public static function ajax_hangar_import_featured(): void {
        check_ajax_referer('roxy_social_hangar_import_featured', 'nonce');
        if (!roxy_suite_user_can_access_admin()) wp_send_json_error('Insufficient permissions', 403);
        $post_id = isset($_POST['post_id']) ? (int) $_POST['post_id'] : 0;
        $asset_id = isset($_POST['asset_id']) ? (int) $_POST['asset_id'] : 0;
        $filename = sanitize_text_field((string) ($_POST['filename'] ?? ''));
        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) wp_send_json_error('Invalid showing', 400);
        $attachment_id = Hangar::import_featured_image($asset_id, $filename, $post_id);
        if ($attachment_id <= 0) wp_send_json_error('Poster import failed', 500);
        wp_send_json_success(['attachment_id' => $attachment_id]);
    }

    public static function ajax_hangar_thumbnail(): void {
        check_ajax_referer('roxy_social_hangar_thumbnail', 'nonce');
        if (!roxy_suite_user_can_access_admin()) {
            status_header(403);
            exit;
        }
        Hangar::thumbnail_response(isset($_GET['asset_id']) ? (int) $_GET['asset_id'] : 0);
    }

    private static function action_link(int $id, string $status, string $label): void {
        $url = wp_nonce_url(admin_url('admin-post.php?action=roxy_social_status&id=' . $id . '&status=' . $status), 'roxy_social_status_' . $id);
        echo '<a class="button" style="margin:0 4px 4px 0" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    public static function handle_status(): void {
        if (!roxy_suite_user_can_access_admin()) wp_die('Insufficient permissions.');
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('roxy_social_status_' . $id);
        Store::update_status($id, sanitize_key((string) ($_GET['status'] ?? '')));
        wp_safe_redirect(admin_url('admin.php?page=roxy-social-posts'));
        exit;
    }
}
