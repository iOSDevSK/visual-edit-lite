const path = require('path'); const fs = require('fs');
const { chromium } = require(process.env.NM + '/playwright');
const OUT = process.env.OUT || require('os').tmpdir();
(async () => {
  const BASE = process.env.BASE || 'http://localhost:1111';
  const STATE = process.env.STATE || process.env.S + '/qa-state.json';
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: STATE });
  const page = await context.newPage();
  const logs = []; page.on('console', m => { if (['error'].includes(m.type())) logs.push(m.text().slice(0, 200)); }); page.on('pageerror', e => logs.push('pageerror ' + e.message));
  await page.goto(BASE + '/wp-admin/admin.php?page=visual-edit');
  await page.waitForSelector('#clara-ve-frame'); await page.waitForTimeout(6000);
  const out = { api: await page.evaluate(() => window.ClaraVE && window.ClaraVE.mode) };
  // turn edit mode on
  const off = await page.$('#clara-ve-toggle.is-off'); if (off) { await off.click(); await page.waitForTimeout(800); }
  const frame = page.frames().find(f => f.url().includes('clara_edit=1'));
  let h = null; for (const e of await frame.$$('h1, h2')) { const b = await e.boundingBox(); if (b && b.width > 40 && b.y > 300) { h = e; break; } }
  const box = await h.boundingBox();
  const fb = await (await page.$('#clara-ve-frame')).boundingBox();
  // boundingBox() of an element inside the frame is already in page coordinates.
  await page.mouse.click(box.x + Math.min(40, box.width / 2), box.y + box.height / 2);
  await page.waitForTimeout(1200);
  await page.screenshot({ path: path.join(OUT, 'h01-html-popup.png') });
  out.panel = await page.evaluate(() => { const p = document.querySelector('.cve-panel'); if (!p) return null; const r = p.getBoundingClientRect(); return { rect: [r.left, r.top, r.width, r.height].map(Math.round), tabs: [...p.querySelectorAll('.cve-tab')].map(t => t.textContent + (t.getAttribute('aria-selected') === 'true' ? '*' : '')), footer: [...p.querySelectorAll('.cve-foot button')].map(b => b.textContent || b.title), sections: [...p.querySelectorAll('.cve-section')].map(s => s.textContent + ':' + s.getAttribute('data-cve-tab')) }; });
  out.click = [Math.round(box.x + Math.min(40, box.width / 2)), Math.round(box.y + box.height / 2)];
  const styleTab = await page.$('.cve-panel .cve-tab:nth-child(2)'); if (styleTab) { await styleTab.click(); await page.waitForTimeout(400); await page.screenshot({ path: path.join(OUT, 'h02-html-style.png') }); }
  out.visibleSections = await page.evaluate(() => [...document.querySelectorAll('.cve-panel .cve-section')].filter(s => !s.classList.contains('cve-tab-hidden')).map(s => s.textContent));
  out.selection = await page.evaluate(() => window.ClaraVE.getSelection());
  out.apply = await page.evaluate(async () => { const s = window.ClaraVE.getSelection(); return window.ClaraVE.apply([{ op: 'set-style', id: s.id, style: { 'typography.letterSpacing': '2px' } }, { op: 'remove', id: s.id }]); });
  out.status = await page.evaluate(() => document.querySelector('#clara-ve-status').textContent);
  // discard
  await page.evaluate(() => { const b = document.querySelector('#clara-ve-discard'); if (b && !b.disabled) b.click(); });
  await page.waitForTimeout(1500);
  out.statusAfterDiscard = await page.evaluate(() => document.querySelector('#clara-ve-status').textContent);
  out.logs = logs;
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
