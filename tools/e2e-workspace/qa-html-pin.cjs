// The HTML editor's popup pin: pinned, the panel opens where it was pinned for the
// next element; dragged while pinned, it stays at the new place; unpinned, it
// follows the element again. BASE, STATE (a logged-in storage state), NM, OUT.
const path = require('path');
const { chromium } = require(process.env.NM + '/playwright');
const OUT = process.env.OUT || require('os').tmpdir();
(async () => {
  const BASE = process.env.BASE || 'http://localhost:1111';
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: +(process.env.W || 1440), height: +(process.env.H || 900) }, storageState: process.env.STATE });
  const page = await context.newPage();
  const logs = []; page.on('pageerror', e => logs.push('pageerror ' + e.message));
  await page.goto(BASE + '/wp-admin/admin.php?page=visual-edit');
  if (page.url().includes('wp-login.php')) { await page.fill('#user_login', 'admin'); await page.fill('#user_pass', process.env.WP_PASS || 'admin'); await page.press('#user_pass', 'Enter'); await page.waitForURL(/wp-admin/); await page.goto(BASE + '/wp-admin/admin.php?page=visual-edit'); }
  await page.waitForSelector('#clara-ve-frame'); await page.waitForTimeout(6000);
  const off = await page.$('#clara-ve-toggle.is-off'); if (off) { await off.click(); await page.waitForTimeout(800); }
  const frame = page.frames().find(f => f.url().includes('clara_edit=1'));
  const fb = await (await page.$('#clara-ve-frame')).boundingBox();
  async function openOn(sel, n) {
    // Visible, wide enough to be text rather than a link inside a button, below the toolbar.
    const els = []; for (const e of await frame.$$(sel)) { const b = await e.boundingBox(); const plain = await e.evaluate(n => !n.closest('a, button')); if (b && plain && b.width > 200 && b.height > 10 && b.y >= 300 && b.y < 820) els.push(b); }
    // boundingBox() of an element inside the frame is already in page coordinates.
    const b = els[Math.min(n || 0, els.length - 1)];
    await page.mouse.click(b.x + Math.min(30, b.width / 2), b.y + b.height / 2); await page.waitForTimeout(1000);
  }
  const box = () => page.evaluate(() => { const p = document.querySelector('.cve-panel'); if (!p) return null; const r = p.getBoundingClientRect(); const pin = p.querySelector('.cve-pin'); return { left: Math.round(r.left), top: Math.round(r.top), pin: !!pin, pressed: pin && pin.getAttribute('aria-pressed') }; });
  const out = {};
  await openOn('h1, h2'); out.first = await box();
  if (!out.first || !out.first.pin) { await page.screenshot({ path: path.join(OUT, 'html-pin-error.png') }); console.log(JSON.stringify({ first: out.first, frame: !!frame, logs })); await browser.close(); process.exit(1); }
  await page.click('.cve-panel .cve-pin'); out.afterPin = await box();
  await page.screenshot({ path: path.join(OUT, 'html-pin-on-' + (process.env.W || 1440) + '.png') });
  await page.click('.cve-panel .cve-close'); await page.waitForTimeout(500);
  await openOn('p', 0); out.secondPinned = await box();
  out.samePlace = !!(out.first && out.secondPinned && Math.abs(out.first.left - out.secondPinned.left) <= 2 && Math.abs(out.first.top - out.secondPinned.top) <= 2);
  const head = await (await page.$('.cve-panel .cve-title')).boundingBox();
  await page.mouse.move(head.x + 20, head.y + head.height / 2); await page.mouse.down(); await page.mouse.move(head.x + (page.viewportSize().width > 600 ? 260 : 0), head.y - 200, { steps: 8 }); await page.mouse.up(); await page.waitForTimeout(300);
  out.dragged = await box();
  await page.click('.cve-panel .cve-close'); await page.waitForTimeout(500);
  await openOn('h1, h2'); out.afterDragReopen = await box();
  out.keptDraggedPlace = !!(out.dragged && out.afterDragReopen && Math.abs(out.dragged.left - out.afterDragReopen.left) <= 24 && Math.abs(out.dragged.top - out.afterDragReopen.top) <= 2);
  await page.click('.cve-panel .cve-pin'); out.unpinned = (await box()).pressed === 'false';
  await page.click('.cve-panel .cve-close'); await page.waitForTimeout(500);
  await openOn('p', 0); out.followsAgain = await box();
  await page.screenshot({ path: path.join(OUT, 'html-pin-off-' + (process.env.W || 1440) + '.png') });
  await page.click('.cve-panel .cve-close').catch(() => {});
  out.logs = logs;
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
