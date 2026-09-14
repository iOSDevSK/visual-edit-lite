module.exports = async ctx => {
  const { page, frame, wait, shot } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas(); const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const preview = canvas.locator('.cve-shortcode-preview');
  await preview.first().waitFor({ timeout: 20000 }).catch(e => out.previewErr = e.message);
  out.previewInputs = await canvas.locator('.cve-shortcode-preview input').count();
  out.nativeHidden = await canvas.locator('.cve-shortcode-previewing > :not(.cve-shortcode-preview)').evaluateAll(els => els.length > 0 && els.every(e => getComputedStyle(e).display === 'none'));
  // styles saved on the block already show in the editor
  out.labelColorEditor = await canvas.locator('.cve-shortcode-preview label').first().evaluate(e => getComputedStyle(e).color);
  // click the form -> selects the shortcode block
  const box = await canvas.locator('.cve-shortcode-preview label').first().boundingBox();
  const ifr = await (await frame.$('iframe[name="editor-canvas"]')).boundingBox();
  await canvas.locator('.cve-shortcode-preview').first().scrollIntoViewIfNeeded(); await wait(500);
  const b2 = await canvas.locator('.cve-shortcode-preview label').first().boundingBox();
  await page.mouse.click(ifr.x + b2.x + 10, ifr.y + b2.y + 5); await wait(900);
  out.selected = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getSelectedBlockClientId(); return id && s.getBlockName(id); });
  out.tabs = await frame.locator('.cve-w-popup [role=tab]').allTextContents();
  out.shortcodeField = await frame.locator('.cve-w-popup .cve-w-field', { hasText: 'Shortcode' }).locator('input').inputValue().catch(() => null);
  await L.tab('Style'); await wait(300);
  out.groups = await frame.locator('.cve-w-popup .cve-w-section-title, .cve-w-popup [class*="section"] > button').allTextContents().catch(() => []);
  // change label colour to a palette colour and button radius
  const labelColour = frame.locator('.cve-w-popup .cve-w-field', { hasText: /^Colour/ }).first().locator('select');
  const opts = await labelColour.locator('option').evaluateAll(o => o.map(x => [x.value, x.textContent]));
  out.paletteOptions = opts.length;
  const pick = opts.find(o => o[0].startsWith('var:preset|color|'));
  await labelColour.selectOption(pick[0]); await wait(600);
  out.labelColorAfter = await canvas.locator('.cve-shortcode-preview label').first().evaluate(e => getComputedStyle(e).color);
  out.attr = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); return JSON.stringify(s.getBlockAttributes(s.getSelectedBlockClientId()).claraVe); });
  await frame.locator('.cve-w-popup').screenshot({ path: path.join(process.env.OUT, 'form-popup.png') });
  await canvas.locator('.cve-shortcode-preview').first().screenshot({ path: path.join(process.env.OUT, 'form-canvas.png') });
  await L.apply(); await wait(300);
  await page.keyboard.press('Meta+s'); await wait(4000);
  out.dirtyAfterSave = await frame.evaluate(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length);
  out.pick = pick;
  return out;
};
