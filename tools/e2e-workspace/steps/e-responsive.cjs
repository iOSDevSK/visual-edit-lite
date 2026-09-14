module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  await L.clickBlock('h2', 40, 10); await L.tab('Style');
  await frame.locator('.cve-w-screens button', { hasText: 'Mobile' }).click(); await wait(1200);
  out.device = await frame.evaluate(() => wp.data.select('core/editor').getDeviceType()); out.toolbarSelect = await frame.locator('.cve-w-toolbar select').first().inputValue();
  out.note = await frame.evaluate(() => (document.querySelector('.cve-w-popup .cve-w-note') || {}).textContent);
  out.groups = (await L.popup()).groups;
  await L.openGroup('Typography'); await L.setNumber('Size', 22);
  out.canvasWidth = await canvas.locator('body').evaluate(b => b.clientWidth);
  out.mobileSize = await L.canvasStyle('h2', 'fontSize');
  out.marker = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-field span, .cve-w-popup .cve-w-number span')].map(s => s.textContent).filter(t => t.includes('●')));
  await L.openGroup('Visibility'); const hide = frame.locator('.cve-w-popup label.cve-w-field', { has: frame.locator('span', { hasText: /Hide on phones/ }) }).first(); out.hideRow = await hide.count();
  await L.apply(); out.attr = (await L.selected()).attrs.claraVe;
  await frame.locator('.cve-w-screens button', { hasText: 'Desktop' }).click(); await wait(1000); out.desktopSize = await L.canvasStyle('h2', 'fontSize');
  await shot('qa-e-responsive');
  out.saved = await L.save();
  const html = await L.fetchFront('http://localhost:1111/ve-qa-blocks/');
  const ms = [...html.matchAll(/@media[^{]*max-width:\s*600px[^{]*\{[^}]*22px[^}]*\}/g)].map(x => x[0].slice(0, 220)); out.frontMedia = ms; out.frontAnchor = /data-ve-responsive|cve-r-/.test(html); out.frontHas22 = /22px/.test(html);
  await page.reload(); await wait(6000); const f2 = await (await page.$('iframe.cve-workspace-frame')).contentFrame(); await f2.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 }); await wait(2000);
  ctx.frame = f2; const L2 = require('./lib.cjs')(ctx);
  await L2.clickBlock('h2', 40, 10); await L2.tab('Style'); await f2.locator('.cve-w-screens button', { hasText: 'Mobile' }).click(); await wait(1000); await L2.openGroup('Typography');
  out.readBack = await f2.evaluate(() => { const n = [...document.querySelectorAll('.cve-w-popup .cve-w-number')].find(e => /Size/.test(e.querySelector('span').textContent)); return n && n.querySelector('span').textContent + '=' + n.querySelector('input').value; });
  // clear override
  const n = f2.locator('.cve-w-popup .cve-w-number').filter({ has: f2.locator('span', { hasText: /Size/ }) }).first(); await n.locator('input').first().fill(''); await wait(400);
  await L2.apply(); out.attrAfterClear = (await L2.selected()).attrs.claraVe; await L2.save();
  return out;
};
