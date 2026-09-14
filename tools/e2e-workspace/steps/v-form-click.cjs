module.exports = async ctx => { const { page, frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = {}; const canvas = L.canvas(); const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const fields = canvas.locator('.wp-block-clara-ve-field, .wp-block-clara-ve-select, .wp-block-clara-ve-textarea');
  out.count = await fields.count(); out.picks = [];
  for (const i of [0, 1, 4]) {
    const f = fields.nth(i); await f.scrollIntoViewIfNeeded(); await wait(300);
    const want = await f.locator('label').first().textContent();
    const ctrl = f.locator('input, select, textarea').first(); const ifr = await (await frame.$('iframe[name="editor-canvas"]')).boundingBox(); const b = await ctrl.boundingBox();
    await page.mouse.click(b.x + 15, b.y + b.height / 2); await wait(800);
    const got = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getSelectedBlockClientId(); return s.getBlockAttributes(id).label; });
    out.picks.push([want.trim(), got]); await L.closePopup().catch(() => {});
  }
  const btn = canvas.locator('.wp-block-clara-ve-submit').first(); await btn.scrollIntoViewIfNeeded();
  out.buttonBg = await btn.evaluate(e => getComputedStyle(e).backgroundColor);
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); wp.data.dispatch('core/block-editor').selectBlock(id); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); }); await wait(900);
  await frame.locator('.cve-w-popup').screenshot({ path: path.join(process.env.OUT, 'form-popup-settings.png') });
  return out; };
