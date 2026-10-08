const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { chromium } = require(process.env.ROXY_PLAYWRIGHT_PATH);
const assert = require('node:assert/strict');
(async () => {
  const html = execFileSync(process.env.ROXY_PHP_PATH, [path.join(__dirname, 'social-ai-references-render.php')], { encoding: 'utf8' });
  assert(!html.includes('Fatal error'));
  const browser = await chromium.launch({ channel: 'chrome', headless: true });
  try {
    const page = await browser.newPage();
    for (const [label, width] of [['desktop',1280],['mobile',390]]) {
      await page.setViewportSize({ width, height: 900 });
      await page.setContent(html);
      assert.equal(await page.locator('[name="film_references[0][title]"]').inputValue(),'Wildwood');
      assert.equal(await page.locator('[name="film_references[0][release_year]"]').inputValue(),'2026');
      assert.equal(await page.locator('[name="film_references_present"]').inputValue(),'1');
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1));
      await page.screenshot({ path: path.join(process.env.TEMP, `roxy-ai-references-${label}.png`), fullPage: true });
    }
    console.log('Film-reference editor desktop/mobile checks passed');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
