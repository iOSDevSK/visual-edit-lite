module.exports = async ctx => {
  const { page, frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const sel = () => frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getSelectedBlockClientId(); return id && { name: s.getBlockName(id), claraVe: s.getBlockAttributes(id).claraVe || null }; });
  // 1) HTML block form: select through the data store (its canvas UI is a code box)
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(i => s.getBlockName(i) === 'core/html'); wp.data.dispatch('core/block-editor').selectBlock(id); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); });
  await wait(1200); out.popup = !!(await L.popup());
  await L.tab('Style'); await wait(300);
  out.htmlGroups = await frame.locator('.cve-w-popup .cve-w-section-title, .cve-w-popup [class*="section"] > button').allTextContents().catch(() => []);
  await L.openGroup('Form button').catch(e => out.openErr = e.message);
  const bg = frame.locator('.cve-w-popup .cve-w-field:has(> span:text-is("Background"))').last().locator('select');
  await bg.selectOption('__custom'); await wait(300);
  await frame.locator('.cve-w-popup input[type=color][aria-label="Background"]').last().evaluate(el => { const set = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value').set; set.call(el, '#0f766e'); el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); });
  await wait(400); out.htmlAttr = (await sel()).claraVe;
  await L.apply();
  // 2) Undo reverts that popup session, Redo brings it back
  await page.keyboard.press('Meta+z'); await wait(500); out.afterUndo = (await sel()).claraVe;
  await page.keyboard.press('Meta+Shift+z'); await wait(500); out.afterRedo = (await sel()).claraVe;
  // 3) Reset styles on the shortcode block removes its form styling
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(i => s.getBlockName(i) === 'core/shortcode'); wp.data.dispatch('core/block-editor').selectBlock(id); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); });
  await wait(1200); await L.tab('Style');
  await frame.locator('.cve-w-popup button', { hasText: 'Reset styles' }).click(); await wait(500);
  out.shortcodeAfterReset = (await sel()).claraVe;
  await L.apply(); await page.keyboard.press('Meta+s'); await wait(4000);
  out.dirty = await frame.evaluate(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length);
  return out;
};
