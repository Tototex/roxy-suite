(function () {
    var activeForm = null;

    function esc(value) {
        return String(value || '').replace(/[&<>]/g, function (character) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;'}[character];
        });
    }

    function choose(form, button, modal, url, type, changed) {
        form.querySelector('[name="media_url"]').value = url;
        form.querySelector('[name="media_type"]').value = type;
        form.querySelector('[name="media_changed"]').value = changed ? '1' : '0';
        button.textContent = 'Media selected - click Save';
        modal.remove();
    }

    function openLibrary(form, button, modal) {
        if (!window.wp || !wp.media) {
            window.alert('The WordPress media library is not available. Reload and try again.');
            return;
        }
        var frame = wp.media({title: 'Choose draft media', button: {text: 'Use this media'}, multiple: false});
        frame.on('select', function () {
            var item = frame.state().get('selection').first().toJSON();
            var url = item.url || '';
            var lower = url.toLowerCase();
            var type = lower.indexOf('.mp4') >= 0 || lower.indexOf('.mov') >= 0 || lower.indexOf('.m4v') >= 0 || lower.indexOf('.webm') >= 0 ? 'video' : 'image';
            choose(form, button, modal, url, type, true);
        });
        frame.open();
    }

    function bindHangarTable(table, form, button, modal) {
        table.querySelectorAll('button[data-id]').forEach(function (use) {
            use.removeAttribute('onclick');
            use.onclick = function () {
                use.disabled = true;
                use.textContent = 'Importing...';
                var data = new URLSearchParams({
                    action: 'roxy_social_hangar_assign',
                    nonce: window.roxySocialPicker.assignNonce,
                    post_id: form.querySelector('[name="id"]').value,
                    asset_id: use.dataset.id,
                    filename: use.dataset.name
                });
                fetch(window.roxySocialPicker.ajaxurl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data})
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result.success) { use.disabled = false; use.textContent = 'Try again'; return; }
                        choose(form, button, modal, result.data.url, result.data.media_type, false);
                    });
            };
        });
    }

    function showCached(panel, form, button, modal) {
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem('roxy_social_hangar_results') || 'null'); } catch (error) {}
        if (!saved || !saved.html) return false;
        var holder = document.createElement('div');
        holder.innerHTML = saved.html;
        var table = holder.querySelector('table');
        panel.innerHTML = '<p class="description">Showing the last Hangar search. Use the Hangar tab to refresh it.</p>';
        if (!table) { panel.innerHTML += '<p>No saved Hangar results.</p>'; return true; }
        panel.appendChild(table);
        bindHangarTable(table, form, button, modal);
        return true;
    }

    function openChooser(button) {
        activeForm = document.getElementById(button.dataset.form);
        var modal = document.createElement('div');
        modal.style = 'position:fixed;z-index:100000;inset:8% 12%;background:#fff;border:1px solid #8c8f94;box-shadow:0 4px 18px rgba(0,0,0,.25);padding:18px;overflow:auto';
        modal.innerHTML = '<button type="button" class="button">Close</button><h2>Choose draft media</h2><p><button type="button" class="button">Media Library</button> <button type="button" class="button button-primary">Hangar</button></p><div></div>';
        document.body.appendChild(modal);
        modal.querySelector('button').onclick = function () { modal.remove(); };
        var tabs = modal.querySelectorAll('h2 + p button');
        var panel = modal.querySelector('div');
        tabs[0].onclick = function () { openLibrary(activeForm, button, modal); };
        tabs[1].onclick = function () { showHangar(activeForm, button, modal, panel); };
        showHangar(activeForm, button, modal, panel);
    }

    function showHangar(form, button, modal, panel) {
        if (showCached(panel, form, button, modal)) return;
        panel.innerHTML = '<p><input type="search" class="regular-text" placeholder="Movie title"> <button type="button" class="button button-primary">Search Hangar</button></p><div></div>';
        var input = panel.querySelector('input');
        var search = panel.querySelector('button');
        var output = panel.querySelector('div');
        search.onclick = function () {
            output.textContent = 'Searching...';
            var data = new URLSearchParams({action: 'roxy_social_hangar_search', nonce: window.roxySocialPicker.searchNonce, term: input.value});
            fetch(window.roxySocialPicker.ajaxurl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data})
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (!result.success || !result.data.length) { output.textContent = 'No Hangar assets found.'; return; }
                    output.innerHTML = '<table class="widefat striped"><thead><tr><th>Asset</th><th>Type</th><th>Details</th><th>Action</th></tr></thead><tbody>' + result.data.map(function (asset) {
                        return '<tr><td>' + esc(asset.filename || asset.asset_name) + '</td><td>' + esc(asset.asset_category || asset.file_type) + '</td><td>' + esc(asset.runtime) + '</td><td><button type="button" class="button" data-id="' + Number(asset.asset_id) + '" data-name="' + esc(asset.filename) + '">Use</button></td></tr>';
                    }).join('') + '</tbody></table>';
                    bindHangarTable(output.querySelector('table'), form, button, modal);
                });
        };
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest && event.target.closest('.roxy-social-media-button');
        if (!button) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        openChooser(button);
    }, true);
}());
