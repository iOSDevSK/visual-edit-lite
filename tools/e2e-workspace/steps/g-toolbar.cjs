module.exports = async ctx => {
  const { page, frame, shot, wait, dialogs, setDialog } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  // device select <-> pill sync
  await frame.locator('.cve-w-toolbar select').first().selectOption('Tablet'); await wait(2500);
  out.deviceAfterSelect = await frame.evaluate(() => wp.data.select('core/editor').getDeviceType());
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); out.pill = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-screens button')].map(b => b.textContent + (b.getAttribute('aria-pressed') === 'true' || b.classList.contains('is-on') ? '*' : '')));
  await frame.locator('.cve-w-screens button', { hasText: 'Desktop' }).click(); await wait(2500); out.selectAfterPill = await frame.locator('.cve-w-toolbar select').first().inputValue();
  await L.closePopup();
  // Cmd+Z in canvas after a popup edit
  await L.clickBlock('p', 20, 8); await L.tab('Style'); await L.openGroup('Typography'); const px = 14 + Math.floor(Math.random() * 9); out.px = px; await L.setCustom('Size', px + 'px'); await L.apply(); out.sizeSet = await L.canvasStyle('p', 'fontSize');
  out.stateBeforeCmdZ = await frame.evaluate(() => ({ hasUndo: wp.data.select('core').hasUndo(), dirty: wp.data.select('core').__experimentalGetDirtyEntityRecords().length }));
  await L.canvas().locator('p').first().click({ position: { x: 5, y: 5 } }); await wait(300); await page.keyboard.press('Meta+z'); await wait(800); out.sizeAfterCmdZ = await L.canvasStyle('p', 'fontSize'); out.stateAfterCmdZ = await frame.evaluate(() => ({ hasUndo: wp.data.select('core').hasUndo(), hasRedo: wp.data.select('core').hasRedo(), dirty: wp.data.select('core').__experimentalGetDirtyEntityRecords().length, status: document.querySelector('.cve-w-status').textContent }));
  if (out.stateAfterCmdZ.hasRedo) { await L.redo(); out.sizeAfterRedoBtn = await L.canvasStyle('p', 'fontSize'); } else { await shot('qa-g-cmdz'); }
  // Preview / View site link
  out.previewHref = await frame.evaluate(() => { const a = [...document.querySelectorAll('.cve-w-toolbar a')].find(x => /Preview|View site/.test(x.textContent)); return a && [a.textContent, a.getAttribute('href'), a.getAttribute('target')]; });
  // Ctrl/Cmd+S
  await frame.locator('.cve-w-toolbar').click({ position: { x: 700, y: 20 } }); await page.keyboard.press('Meta+s'); await wait(2500); out.dirtyAfterCmdS = await L.dirty(); out.statusAfterCmdS = await frame.evaluate(() => document.querySelector('.cve-w-status').textContent);
  if (out.dirtyAfterCmdS.length) { await L.save(); }
  // ⋯ menu: each item opens and closes
  const names = ['Page settings', 'Page structure (list view)', 'All blocks', 'Google Fonts', 'Search appearance', 'Show WordPress controls'];
  out.hrefItems = await (async () => { await frame.locator('.cve-w-toolbar button', { hasText: '⋯' }).first().click(); await wait(400); const r = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-menu.is-right a')].map(a => [a.textContent.trim(), a.getAttribute('href').slice(-50), a.getAttribute('target')])); await page.keyboard.press('Escape'); await wait(300); return r; })();
  out.menu = {};
  for (const n of names) {
    await L.more(n).catch(e => { out.menu[n] = 'ERR ' + e.message.slice(0, 60); });
    if (out.menu[n]) continue;
    out.menu[n] = await frame.evaluate(() => { const vis = s => { const e = document.querySelector(s); return !!e && e.getBoundingClientRect().width > 0; }; return { sidebar: vis('.interface-interface-skeleton__sidebar'), secondary: vis('.interface-interface-skeleton__secondary-sidebar'), modal: vis('.components-modal__frame'), nativeHeader: vis('.editor-header'), collapsed: document.body.classList.contains('cve-native-collapsed'), link: location.href.slice(-30) }; });
    await shot('qa-g-' + n.replace(/\W+/g, '_'));
    if (n === 'Search appearance') { const title = frame.locator('.components-modal__frame input').first(); await title.fill('QA search title'); await frame.locator('.components-modal__frame button', { hasText: /Save search appearance/ }).click(); await wait(2000); out.seoSaved = await frame.evaluate(() => (document.querySelector('.components-modal__frame .cve-gutenberg-seo-status, .components-modal__frame [role=status], .components-modal__frame .components-notice') || {}).textContent); out.seoMeta = await frame.evaluate(async () => { try { return await wp.apiFetch({ path: '/clara-ve/v1/native/seo/417' }); } catch (e) { return 'ERR ' + e.message; } }); }
    if (n === 'Show WordPress controls') { await L.more('Hide WordPress controls').catch(() => {}); out.menu[n].collapsedAgain = await frame.evaluate(() => document.body.classList.contains('cve-native-collapsed')); }
    await page.keyboard.press('Escape'); await wait(300); await L.closePanels();
  }
  const seoHtml = await L.fetchFront('http://localhost:1111/ve-qa-blocks/'); out.seoOnFront = /<title>QA search title/.test(seoHtml) || /QA search title/.test(seoHtml);
  // Document menu: navigate with dirty state → confirm dialog
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography'); await L.setCustom('Size', (30 + Math.floor(Math.random() * 9)) + 'px'); await L.apply(); out.dirtyBeforeNav = await L.dirty();
  await frame.locator('.cve-w-doc > button').first().click(); await wait(1500);
  out.docMenu = await frame.evaluate(() => ({ show: document.querySelector('.cve-w-doc .cve-w-menu select') && document.querySelector('.cve-w-doc .cve-w-menu select').value, items: [...document.querySelectorAll('.cve-w-doc .cve-w-menu button, .cve-w-doc .cve-w-menu a')].map(b => b.textContent.trim()).slice(0, 12) }));
  setDialog('dismiss'); await frame.locator('.cve-w-doc .cve-w-menu button', { hasText: /^Sample Page$/ }).click(); await wait(800); out.dialogDismissed = { dialogs: dialogs.slice(), url: page.url().slice(-40) };
  setDialog('accept'); await frame.locator('.cve-w-doc .cve-w-menu button', { hasText: /^Sample Page$/ }).click().catch(() => {}); await wait(6000); out.afterAccept = page.url().slice(-60);
  return out;
};
