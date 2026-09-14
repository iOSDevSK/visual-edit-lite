const { chromium } = require(process.env.NM + '/playwright');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: process.env.S + '/qa-state.json' });
  const page = await context.newPage();
  await page.goto('http://localhost:1111/wp-admin/post.php?post=2&action=edit'); await page.waitForTimeout(9000);
  const out = await page.evaluate(async () => {
    const s = wp.data.select('core/block-editor'); const d = wp.data.dispatch('core/block-editor'); const core = wp.data.select('core');
    const ids = s.getClientIdsWithDescendants(); const id = ids.find(i => s.getBlockName(i) === 'core/paragraph') || ids[0]; const before = s.getBlockAttributes(id);
    const dirty = () => core.__experimentalGetDirtyEntityRecords().length;
    const r = { d0: dirty() };
    d.updateBlockAttributes(id, { anchor: 'x1' }); await new Promise(r => setTimeout(r, 200)); r.d1 = dirty();
    d.updateBlockAttributes(id, { anchor: before.anchor }); await new Promise(r => setTimeout(r, 200)); r.d2 = dirty();
    wp.data.dispatch('core').undo(); await new Promise(r => setTimeout(r, 200)); wp.data.dispatch('core').undo(); await new Promise(r => setTimeout(r, 200)); r.d3 = dirty();
    return r;
  });
  console.log(JSON.stringify(out)); await browser.close();
})();
