module.exports = async ctx => {
  const { page, frame, shot, wait, dialogs, setDialog } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const vis = () => frame.evaluate(() => { const v = s => { const e = document.querySelector(s); return !!e && getComputedStyle(e).display !== 'none' && e.getBoundingClientRect().height > 0; }; return { header: v('.editor-header'), collapsed: document.body.classList.contains('cve-native-collapsed'), toolbar: v('.block-editor-block-contextual-toolbar') }; });
  await L.more('Show WordPress controls'); out.shown = await vis(); await shot('qa-g2-native-shown');
  await L.clickBlock('h2', 40, 10); out.shownWithSelection = await vis();
  await L.closePopup(); await L.more('Hide WordPress controls'); out.hidden = await vis();
  // document navigation with unsaved changes
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography'); await L.setCustom('Size', (30 + Math.floor(Math.random() * 9)) + 'px'); await L.apply(); out.dirtyBeforeNav = await L.dirty();
  await L.closePopup(); await frame.locator('.cve-w-doc > button').first().click(); await wait(1500);
  out.docMenu = await frame.evaluate(() => ({ show: document.querySelector('.cve-w-doc .cve-w-menu select').value, items: [...document.querySelectorAll('.cve-w-doc .cve-w-menu .cve-w-site-list button')].map(b => b.textContent.trim()).slice(0, 6) }));
  setDialog('dismiss'); await frame.locator('.cve-w-doc .cve-w-menu .cve-w-site-list button', { hasText: /^Sample Page$/ }).click(); await wait(1000); out.afterDismiss = { dialogs: dialogs.slice(), stillHere: page.url().includes('page=visual-edit') && !!(await page.$('iframe.cve-workspace-frame')), frameDoc: await frame.evaluate(() => document.querySelector('.cve-w-doc-title').textContent).catch(() => 'navigated') };
  setDialog('accept'); await frame.locator('.cve-w-doc .cve-w-menu .cve-w-site-list button', { hasText: /^Sample Page$/ }).click().catch(() => {}); await wait(7000);
  const f2 = await (await page.$('iframe.cve-workspace-frame')).contentFrame(); out.afterAccept = { dialogs: dialogs.length, doc: await f2.evaluate(() => (document.querySelector('.cve-w-doc-title') || {}).textContent).catch(() => 'n/a'), url: (await f2.evaluate(() => location.search).catch(() => '')).slice(0, 60) };
  await frame.evaluate(() => wp.data.dispatch('core').undo()).catch(() => {});
  return out;
};
