module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const b = await L.clickBlock('h2', 40, 10);
  out.click = [Math.round(b.x + 40), Math.round(b.y + 10)];
  out.sel = (await L.selected()) && (await L.selected()).name;
  out.popup = await L.popup();
  const off = await page.$eval('iframe.cve-workspace-frame', f => { const r = f.getBoundingClientRect(); return [r.left, r.top]; }); const pr = out.popup.rect.map((v, i) => i < 2 ? v + off[i] : v); out.popupPage = pr; out.popupBesideClick = pr && (pr[0] >= b.x + b.width - 2 || pr[0] + pr[2] <= b.x + 2 || pr[1] >= b.y + b.height - 2 || pr[1] + pr[3] <= b.y + 2);
  out.popupDistanceY = pr && Math.abs(pr[1] - (b.y + 10));
  out.badge = await frame.evaluate(() => { const bd = document.querySelector('.cve-w-badge'); return bd && { text: bd.textContent, rect: (r => [r.left, r.top, r.width, r.height].map(Math.round))(bd.getBoundingClientRect()) }; });
  out.outline = await L.canvasStyle('h2', 'outlineStyle');
  out.nativeToolbar = await frame.evaluate(() => { const t = document.querySelector('.block-editor-block-contextual-toolbar'); return !!t && getComputedStyle(t).display !== 'none'; });
  out.nativeSidebar = await frame.evaluate(() => { const s = document.querySelector('.interface-interface-skeleton__sidebar'); return !!s && s.getBoundingClientRect().width > 0; });
  await shot('qa-b-selected');
  // scroll canvas: popup stays fixed
  await frame.evaluate(() => { const f = document.querySelector('iframe[name="editor-canvas"]'); f.contentWindow.scrollBy(0, 200); }); await wait(400);
  out.popupAfterScroll = (await L.popup()).rect; out.stays = JSON.stringify(out.popupAfterScroll) === JSON.stringify(out.popup.rect);
  await frame.evaluate(() => { const f = document.querySelector('iframe[name="editor-canvas"]'); f.contentWindow.scrollTo(0, 0); }); await wait(300);
  // Esc closes, badge reopens
  await frame.locator('.cve-w-popup .cve-w-heading strong').click(); await page.keyboard.press('Escape'); await wait(400);
  out.afterEsc = !!(await L.popup()); out.badgeAfterEsc = await frame.evaluate(() => !!document.querySelector('.cve-w-badge'));
  await frame.locator('.cve-w-badge').click().catch(() => {}); await wait(500); out.reopened = !!(await L.popup());
  // crumbs and parent
  out.crumbs = (await L.popup()).crumbs;
  await frame.locator('.cve-w-popup button[title*="parent" i], .cve-w-popup button[aria-label*="parent" i]').first().click(); await wait(600);
  out.parent = (await L.selected()).name;
  await frame.locator('.cve-w-popup .cve-w-heading strong').click(); await page.keyboard.press('Alt+ArrowUp'); await wait(600);
  out.afterAltUpOnRoot = (await L.selected()) && (await L.selected()).name; out.popupAfterAltUp = !!(await L.popup());
  await L.clickBlock('p', 20, 8); await frame.locator('.cve-w-popup .cve-w-body').click({ position: { x: 5, y: 5 } }); await page.keyboard.press('Alt+ArrowUp'); await wait(600); out.altUpFromParagraph = (await L.selected()) && (await L.selected()).name;
  // crumb click selects
  const crumb = frame.locator('.cve-w-popup .cve-w-crumb').first(); if (await crumb.count()) { const t = await crumb.textContent(); await crumb.click(); await wait(500); out.crumbClick = [t, (await L.selected()) && (await L.selected()).name]; }
  // same block click after Esc reopens
  await frame.locator('.cve-w-popup .cve-w-heading strong').click(); await page.keyboard.press('Escape'); await wait(300); await L.clickBlock('p', 20, 8); out.sameBlockClickReopens = !!(await L.popup());
  // pin: select another block, popup stays where pinned
  await L.clickBlock('p', 20, 8); const before = (await L.popup()).rect;
  await frame.locator('.cve-w-popup button[aria-label*="Keep the popup" i]').click(); await wait(300);
  await L.clickBlock('.wp-block-button__link', 10, 8); out.pinnedStays = JSON.stringify((await L.popup()).rect.slice(0, 2)) === JSON.stringify(before.slice(0, 2));
  await frame.locator('.cve-w-popup button[aria-label*="Keep the popup" i]').click(); await wait(300);
  // drag by grip
  const grip = frame.locator('.cve-w-popup .cve-w-grip, .cve-w-popup .cve-w-heading > span').first(); const gb = await grip.boundingBox();
  if (gb) { await page.mouse.move(gb.x + 5, gb.y + 5); await page.mouse.down(); await page.mouse.move(gb.x + 105, gb.y + 85, { steps: 8 }); await page.mouse.up(); await wait(300); const after = (await L.popup()).rect; out.dragMoved = Math.abs(after[0] - before[0]) > 20 || Math.abs(after[1] - before[1]) > 20; }
  out.dirty = await L.dirty();
  return out;
};
