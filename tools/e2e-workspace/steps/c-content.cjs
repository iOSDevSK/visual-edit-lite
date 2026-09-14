module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  // Text mirror
  await L.clickBlock('h2', 40, 10); await L.tab('Content'); await L.openGroup('Text');
  const rt = frame.locator('.cve-w-popup .cve-w-body [contenteditable="true"]').first();
  await rt.click(); await page.keyboard.press('End'); await page.keyboard.type(' plus'); await wait(500);
  out.canvasTextAfterMirror = await canvas.locator('h2').first().textContent();
  await L.apply(); out.headingAttr = (await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); return s.getBlockAttributes(s.getSelectedBlockClientId()).content; })).toString();
  // Bold on canvas selection
  const hb = await canvas.locator('h2').first().boundingBox();
  await page.mouse.click(hb.x + 10, hb.y + hb.height / 2); await wait(600); out.reopenedAfterApply = !!(await L.popup());
  await canvas.locator('h2').first().evaluate(el => { const r = document.createRange(); const t = el.firstChild; r.setStart(t, 0); r.setEnd(t, 2); const s = window.getSelection(); s.removeAllRanges(); s.addRange(r); el.dispatchEvent(new Event('selectionchange', { bubbles: true })); }); await wait(500);
  out.formatHint = await frame.evaluate(() => (document.querySelector('.cve-w-format-buttons .cve-w-note') || {}).textContent);
  await frame.locator('.cve-w-format-buttons button', { hasText: /^B$/ }).click(); await wait(500);
  out.boldHtml = await canvas.locator('h2').first().innerHTML();
  await L.apply();
  // Link on button
  await L.clickBlock('.wp-block-button__link', 10, 8, 1); await L.tab('Content'); await L.openGroup('Link');
  await L.setInput('URL', 'https://example.org/qa'); await L.field('Open in').locator('select').selectOption({ label: 'New tab' }); await wait(300);
  await L.apply(); out.linkAttrs = (await L.selected()).attrs;
  out.canvasHref = await canvas.locator('.wp-block-button__link').nth(1).getAttribute('href');
  // Media replace + alt
  await L.clickBlock('h2', 40, 10); await L.closePopup(); await L.clickBlock('figure.wp-block-image img', 200, 200); await L.tab('Content'); await L.openGroup('Media');
  await L.setInput('Alternative text', 'QA alt changed'); await L.apply();
  out.alt = (await L.selected()).attrs.alt;
  await frame.locator('.cve-w-popup button', { hasText: 'Choose or replace media' }).click(); await wait(2500);
  const items = frame.locator('.media-modal .attachments .attachment'); out.mediaItems = await items.count();
  if (out.mediaItems > 1) { await items.nth(2).click(); await wait(400); await frame.locator('.media-modal .media-button-select, .media-modal button.media-button').first().click(); await wait(1500); out.newSrc = await canvas.locator('figure.wp-block-image img').first().getAttribute('src'); await L.apply(); }
  // Ornaments
  await L.clickBlock('h2', 40, 10); await L.tab('Content'); await L.openGroup('Ornaments');
  const beforeGroup = frame.locator('.cve-w-popup .cve-w-body').locator('text=Before the text').locator('..');
  await frame.locator('.cve-w-popup .cve-w-swatches button').first().click(); await wait(300); out.glyphSet = ((await L.selected()).attrs.claraVe || {}).ornaments;
  const sym = frame.locator('.cve-w-popup label.cve-w-field', { has: frame.locator('span', { hasText: /^Symbol$/ }) }).first(); await sym.locator('input').fill('★'); await wait(400);
  const col = frame.locator('.cve-w-popup label.cve-w-field', { has: frame.locator('span', { hasText: /^Colour$/ }) }).first(); await col.locator('input').first().fill('#ff0000'); await wait(400);
  out.ornamentPreview = await canvas.locator('h2').first().evaluate(e => getComputedStyle(e, '::before').content + ' ' + getComputedStyle(e, '::before').color);
  await L.apply(); out.ornamentAttr = (await L.selected()).attrs.claraVe;
  await shot('qa-c-content');
  out.saved = await L.save();
  const html = await L.fetchFront(process.env.FRONT || 'http://localhost:1111/ve-qa-blocks/');
  out.front = { plus: /QA heading (text )?plus/.test(html), bold: /<strong>QA<\/strong>|<b>QA<\/b>/.test(html), href: /example\.org\/qa/.test(html), newtab: /target="_blank"/.test(html), alt: /QA alt changed/.test(html), ornamentCss: /★/.test(html) && /::before|:before/.test(html), ornamentAttr: /"claraVe"/.test(html) };
  return out;
};
