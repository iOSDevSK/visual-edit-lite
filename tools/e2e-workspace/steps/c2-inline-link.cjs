module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const p = canvas.locator('p', { hasText: 'images made to be printed' }).first();
  await p.scrollIntoViewIfNeeded(); const box = await p.boundingBox(); await page.mouse.click(box.x + 20, box.y + 10); await wait(800);
  const ifr = await (await frame.$('iframe[name="editor-canvas"]')).boundingBox();
  async function drag(word) {
    const r = await p.evaluate((el, word) => { const full = el.textContent; const i = full.indexOf(word); const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT); let n, pos = 0, rg = document.createRange(), st = false; while ((n = w.nextNode())) { const len = n.data.length; if (!st && i < pos + len) { rg.setStart(n, i - pos); st = true; } if (st && i + word.length <= pos + len) { rg.setEnd(n, i + word.length - pos); break; } pos += len; } const q = rg.getBoundingClientRect(); return { x: q.left, y: q.top, w: q.width, h: q.height }; }, word);
    await page.mouse.move(ifr.x + r.x + 1, ifr.y + r.y + r.h / 2); await page.mouse.down(); await page.mouse.move(ifr.x + r.x + r.w - 1, ifr.y + r.y + r.h / 2, { steps: 8 }); await page.mouse.up(); await wait(600);
  }
  const url = () => frame.locator('.cve-w-format-link input');
  const linkBtn = frame.locator('.cve-w-format-buttons button[aria-label="Link"]');
  // A: Enter applies, scheme added, input autofocused
  await drag('images made'); await L.tab('Content').catch(() => {}); await L.openGroup('Text').catch(() => {});
  await linkBtn.click(); await wait(300);
  out.autofocus = await frame.evaluate(() => document.activeElement && document.activeElement.matches('.cve-w-format-link input'));
  await page.keyboard.type('example.org/enter', { delay: 10 }); await page.keyboard.press('Enter'); await wait(600);
  out.A_html = await p.innerHTML(); out.A_rowClosed = (await url().count()) === 0; out.A_popup = !!(await L.popup());
  // B: empty apply shows error
  await drag('printed'); await linkBtn.click(); await wait(300);
  await frame.locator('.cve-w-format-link button', { hasText: 'Apply link' }).click(); await wait(300);
  out.B_error = await frame.locator('.cve-w-format-error').textContent().catch(() => null);
  // C: page selection collapses while typing -> link still goes to "printed"
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); wp.data.dispatch('core/block-editor').selectionChange(s.getSelectedBlockClientId(), 'content', 3, 3); }); await wait(400);
  out.C_rowStill = await url().count();
  if (out.C_rowStill) { await url().fill('https://example.org/collapsed'); await frame.locator('.cve-w-format-link button', { hasText: 'Apply link' }).click(); await wait(600); }
  out.C_html = await p.innerHTML();
  // D: Esc closes only the link row
  await drag('kept'); await linkBtn.click(); await wait(300); await page.keyboard.press('Escape'); await wait(400);
  out.D_rowClosed = (await url().count()) === 0; out.D_popupOpen = !!(await L.popup());
  await shot('link-fix');
  return out;
};
