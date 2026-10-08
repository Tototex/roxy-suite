(function () {
    function assetTable(assets) {
        var table=document.createElement('table');table.className='widefat striped';
        var head=document.createElement('thead'),heading=document.createElement('tr');
        ['Asset','Type','Details','Action'].forEach(function(label){var cell=document.createElement('th');cell.textContent=label;heading.appendChild(cell);});
        head.appendChild(heading);table.appendChild(head);
        var body=document.createElement('tbody');table.appendChild(body);
        assets.forEach(function(asset){
            var id=Number(asset.asset_id);if(!Number.isSafeInteger(id)||id<=0)return;
            var row=document.createElement('tr');
            [asset.filename||asset.asset_name,asset.asset_category||asset.file_type,asset.runtime].forEach(function(value){var cell=document.createElement('td');cell.textContent=String(value||'');row.appendChild(cell);});
            var cell=document.createElement('td'),use=document.createElement('button');
            use.type='button';use.className='button';use.textContent='Use';
            use.dataset.id=String(id);use.dataset.name=String(asset.filename||'');
            cell.appendChild(use);row.appendChild(cell);body.appendChild(row);
        });
        return table;
    }

    function choose(form, button, modal, url, type, changed) {
        form.querySelector('[name="media_url"]').value = url;
        form.querySelector('[name="media_type"]').value = type;
        form.querySelector('[name="media_changed"]').value = changed ? '1' : '0';
        button.textContent = 'Media selected';
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
                    draft_revision: form.querySelector('[name="draft_revision"]').value,
                    asset_id: use.dataset.id,
                    filename: use.dataset.name
                });
                fetch(window.roxySocialPicker.ajaxurl, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body: data})
                    .then(function (response) { return response.json(); })
                    .then(function (result) {
                        if (!result.success || !result.data || !result.data.url) { use.disabled = false; use.textContent = 'Try again'; return; }
                        form.querySelector('[name="draft_revision"]').value = result.data.draft_revision;
                        choose(form, button, modal, result.data.url, result.data.media_type, false);
                    }).catch(function(){use.disabled=false;use.textContent='Try again';});
            };
        });
    }

    function showCached(panel, form, button, modal) {
        var saved = null;
        try { saved = JSON.parse(localStorage.getItem('roxy_social_hangar_results') || 'null'); } catch (error) {}
        // Old cached HTML can contain provider-controlled attributes. Never insert it.
        if (!saved || saved.version !== 2 || !Array.isArray(saved.assets)) return false;
        var table = assetTable(saved.assets);
        panel.innerHTML = '<p class="description">Showing the last Hangar search. Use the Hangar tab to refresh it.</p>';
        if (!table) { panel.innerHTML += '<p>No saved Hangar results.</p>'; return true; }
        panel.appendChild(table);
        bindHangarTable(table, form, button, modal);
        return true;
    }

    function openChooser(button) {
        var activeForm = document.getElementById(button.dataset.form);
        if (!activeForm) return;
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
                    if (!result.success || !Array.isArray(result.data) || !result.data.length) { output.textContent = 'No Hangar assets found.'; return; }
                    output.textContent='';output.appendChild(assetTable(result.data));
                    try { localStorage.setItem('roxy_social_hangar_results',JSON.stringify({version:2,assets:result.data,term:input.value})); } catch(error) {}
                    bindHangarTable(output.querySelector('table'), form, button, modal);
                }).catch(function(){output.textContent='Hangar search failed. Please try again.';});
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
