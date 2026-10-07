const fs = require('fs');
const path = require('path');
const {spawnSync} = require('child_process');
const {chromium} = require(process.env.ROXY_PLAYWRIGHT_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.ROXY_PHP_PATH;
const html = spawnSync(php, [path.join(__dirname, 'social-bulk-editor-regression.php'), '--render'], {encoding:'utf8'});
if (html.status !== 0) throw new Error(html.stderr || html.stdout);
function assert(ok, message) { if (!ok) throw new Error(message); }
(async () => {
    const browser = await chromium.launch({headless:true,channel:process.env.ROXY_BROWSER_CHANNEL || 'chrome'});
    try {
        const page = await browser.newPage();
        let requests = [], failFirst = false;
        await page.route('https://example.test/**', async route => {
            const url = route.request().url();
            if (url.includes('admin-ajax.php')) {
                const body = route.request().postData();
                const id = /name="id"\r\n\r\n(\d+)/.exec(body)?.[1];
                requests.push({id, body});
                const failed = failFirst && id === '1';
                return route.fulfill({status:failed ? 409 : 200,contentType:'application/json',body:JSON.stringify(failed ? {success:false,data:{message:'Conflict; edits retained'}} : {success:true,data:{revision:'fresh-'+id}})});
            }
            if (url.includes('draft-bulk-editor.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'includes/modules/social-publisher/assets/draft-bulk-editor.js'),'utf8')});
            if (url.includes('draft-media-picker.js')) return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.join(root,'includes/modules/social-publisher/assets/draft-media-picker.js'),'utf8')});
            return route.fulfill({contentType:'text/html',body:'<style>.button { display:inline-block; }</style>'+html.stdout});
        });
        await page.goto('https://example.test/wp-admin/admin.php?page=roxy-social-posts&status=approved');
        assert(await page.locator('.roxy-social-save-all').count()===2,'Top/bottom controls missing');
        assert(await page.locator('form[id^="roxy-social-draft-"]').count()===3,'Invalid row forms');
        assert(await page.locator('form[id^="roxy-social-draft-"] button[type="submit"]:visible').count()===0,'WordPress styles expose row Save buttons');
        await page.locator('[form="roxy-social-draft-1"][name="post_text"]').fill("We're changed one");
        await page.locator('[form="roxy-social-draft-2"][name="post_text"]').fill('Changed two');
        await page.locator('.roxy-social-save-all').first().click();
        await page.waitForURL('**&updated=1');
        assert(requests.length===2 && requests[0].id==='1' && requests[1].id==='2','Unchanged row submitted or edits missing');
        assert(new URL(page.url()).searchParams.get('status')==='approved','Selected tab lost');
        const action = await page.locator('a[href*="roxy_social_status"]').first().getAttribute('href');
        assert(new URL(action).searchParams.get('return_status')==='approved','Action lost selected tab');
        requests=[]; failFirst=true;
        await page.locator('[form="roxy-social-draft-1"][name="post_text"]').fill('Keep unsaved edit');
        await page.locator('[form="roxy-social-draft-2"][name="post_text"]').fill('Save this one');
        await page.locator('.roxy-social-save-all').last().click();
        await page.locator('.roxy-social-save-message').first().filter({hasText:'could not be saved'}).waitFor();
        assert(await page.locator('[form="roxy-social-draft-1"][name="post_text"]').inputValue()==='Keep unsaved edit','Conflict erased edits');
        assert(await page.locator('#roxy-social-draft-2 [name="draft_revision"]').inputValue()==='fresh-2','Saved revision not refreshed');
        assert(await page.locator('#roxy-social-draft-1 .roxy-social-row-save-result').textContent()==='Conflict; edits retained','Conflict not displayed');
        console.log('Browser checks passed: multi-row saving, unchanged rows, both buttons, tab retention and partial failure.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
