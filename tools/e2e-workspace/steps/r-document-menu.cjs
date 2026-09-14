module.exports = async ctx => {
  const { page, frame, wait } = ctx; const out = ctx.out = {}; const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const menu = frame.locator('.cve-w-doc .cve-w-menu');
  await frame.locator('.cve-w-doc > button').click(); await menu.waitFor();
  const sel = menu.locator('.cve-w-field select').first();
  out.options = await sel.locator('option').allTextContents();
  await sel.selectOption('wp_block'); await wait(1500);
  out.syncedButtons = await menu.locator('button').allTextContents();
  out.links = await menu.locator('a').evaluateAll(as => as.map(a => [a.textContent, a.getAttribute('href'), a.target]));
  await menu.screenshot({ path: path.join(process.env.OUT, 'doc-synced.png') });
  // force a load error for posts to see the notice
  await page.route(/\/wp\/v2\/posts/, r => r.fulfill({ status: 500, contentType: 'application/json', body: '{"code":"x","message":"Could not load this content."}' }));
  await frame.evaluate(() => wp.data.dispatch('core').invalidateResolutionForStore && 0);
  await sel.selectOption('post'); await menu.locator('.components-notice').waitFor({ timeout: 15000 }).catch(e => out.noticeErr = e.message); await wait(500);
  out.retryColors = await menu.locator('.components-notice button').evaluate(b => { const s = getComputedStyle(b); return [s.color, s.backgroundColor]; }).catch(() => null);
  await menu.screenshot({ path: path.join(process.env.OUT, 'doc-error.png') });
  return out;
};
