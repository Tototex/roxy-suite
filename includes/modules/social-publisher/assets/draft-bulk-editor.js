(function () {
    'use strict';
    var buttons = Array.from(document.querySelectorAll('.roxy-social-save-all'));
    if (!buttons.length) return;
    var forms = Array.from(document.querySelectorAll('form[id^="roxy-social-draft-"]'));
    var fields = ['post_text', 'scheduled_for', 'media_url', 'media_type', 'media_changed'];
    var baseline = new Map();
    var busy = false;
    var allowLeave = false;
    function values(form) {
        var data = new FormData(form);
        return JSON.stringify(fields.map(function (key) { return data.get(key); }));
    }
    function editable(form) { return !form.querySelector('button[type="submit"]').disabled; }
    function changed(form) { return editable(form) && baseline.get(form) !== values(form); }
    function message(text) {
        document.querySelectorAll('.roxy-social-save-message').forEach(function (node) { node.textContent = text; });
    }
    forms.forEach(function (form) {
        baseline.set(form, values(form));
        if (!editable(form)) Array.from(form.elements).forEach(function (field) { field.disabled = true; });
        form.addEventListener('submit', function (event) { event.preventDefault(); save(); });
    });
    var filter = new URL(window.location.href).searchParams.get('status') || 'draft';
    document.querySelectorAll('a[href*="admin-post.php"], form[action*="admin-post.php"]').forEach(function (node) {
        if (node.tagName === 'A') {
            var url = new URL(node.href); url.searchParams.set('return_status', filter); node.href = url.href;
        } else if (!node.querySelector('[name="return_status"]')) {
            var field = document.createElement('input'); field.type = 'hidden'; field.name = 'return_status'; field.value = filter; node.appendChild(field);
        }
    });
    async function save() {
        if (busy) return;
        var pending = forms.filter(changed);
        if (!pending.length) { message('No changes to save.'); return; }
        var invalid = pending.find(function (form) { return !form.checkValidity(); });
        if (invalid) { invalid.reportValidity(); return; }
        busy = true;
        buttons.forEach(function (button) { button.disabled = true; });
        var failures = 0, saved = 0;
        for (var form of pending) {
            message('Saving ' + (saved + failures + 1) + ' of ' + pending.length + '...');
            var submitted = values(form);
            var row = form.closest('tr');
            var data = new FormData(form);
            var notice = row.querySelector('.roxy-social-row-save-result');
            if (!notice) { notice = document.createElement('p'); notice.className = 'roxy-social-row-save-result'; form.appendChild(notice); }
            try {
                var response = await fetch(window.ajaxurl, {method: 'POST', body: data, credentials: 'same-origin'});
                var result = await response.json();
                if (!response.ok || !result.success) throw new Error(result.data && result.data.message || 'Could not save this post. Your edits remain here.');
                baseline.set(form, submitted);
                form.querySelector('[name="draft_revision"]').value = result.data.revision;
                notice.textContent = 'Saved. Changes need approval again.';
                notice.style.color = '#008a20';
                // Keep stale approval controls inactive after a partial save.
                row.querySelectorAll('a[href*="admin-post.php"]').forEach(function (link) { link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); });
                row.querySelectorAll('form:not([id^="roxy-social-draft-"]) button').forEach(function (button) { button.disabled = true; });
                saved++;
            } catch (error) {
                failures++; notice.textContent = error.message; notice.style.color = '#b32d2e';
            }
        }
        busy = false;
        buttons.forEach(function (button) { button.disabled = false; });
        if (!failures && !forms.some(changed)) {
            allowLeave = true;
            var url = new URL(window.location.href); url.searchParams.set('status', filter); url.searchParams.set('updated', '1');
            window.location.assign(url.href);
        } else message(saved + ' saved; ' + failures + ' could not be saved. Unsaved edits remain on this page.');
    }
    buttons.forEach(function (button) { button.addEventListener('click', save); });
    window.addEventListener('beforeunload', function (event) {
        if (!allowLeave && (busy || forms.some(changed))) { event.preventDefault(); event.returnValue = ''; }
    });
}());
