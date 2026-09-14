const { chromium } = require(process.env.NM + '/playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: process.env.S + '/qa-state.json' });
  const page = await context.newPage();
  await page.goto('http://localhost:1111/wp-admin/post.php?post=417&action=edit'); await page.waitForTimeout(9000);
  const out = await page.evaluate(() => { const s = wp.data.select('core/block-editor'); const ids = s.getClientIdsWithDescendants(); return { blocks: ids.length, invalid: ids.filter(id => s.getBlock(id).isValid === false).length, plugin: !!window.ClaraVE, claraVeAttrs: ids.filter(id => s.getBlockAttributes(id).claraVe).length }; });
  console.log(JSON.stringify(out)); await browser.close();
})();
