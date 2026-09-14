module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); await L.openGroup('Typography');
  out.groups = (await L.popup()).groups;
  out.rowsBefore = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-body .cve-w-field, .cve-w-popup .cve-w-body .cve-w-number')].filter(e => e.offsetParent).map(e => e.querySelector('span').textContent));
  await L.setCustom('Size', '41px'); out.size = await L.canvasStyle('h2', 'fontSize');
  await L.setSelect('Weight', '300').catch(async () => { out.weightOpts = await L.field('Weight').locator('select option').allTextContents(); await L.field('Weight').locator('select').selectOption({ index: 2 }); });
  out.weight = await L.canvasStyle('h2', 'fontWeight');
  await L.setSelect('Case', 'uppercase'); out.case = await L.canvasStyle('h2', 'textTransform');
  await L.setSelect('Decoration', 'underline'); out.decoration = await L.canvasStyle('h2', 'textDecorationLine');
  await L.setNumber('Line height', '1.7'); out.lineHeight = await L.canvasStyle('h2', 'lineHeight');
  await L.setNumber('Letter spacing', '2'); out.letterSpacing = await L.canvasStyle('h2', 'letterSpacing');
  await L.setSelect('Align', 'center'); out.align = await L.canvasStyle('h2', 'textAlign');
  await L.openGroup('Colours');
  await L.setCustom('Text colour', '#123456'); out.color = await L.canvasStyle('h2', 'color');
  await L.setCustom('Background', '#eeeeee'); out.bg = await L.canvasStyle('h2', 'backgroundColor');
  await L.openGroup('Size and spacing');
  const pads = frame.locator('.cve-w-popup .cve-w-number').filter({ has: frame.locator('span', { hasText: /^(Top|↑|Padding)/ }) });
  out.spacingRows = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup .cve-w-body .cve-w-field, .cve-w-popup .cve-w-body .cve-w-number')].filter(e => e.offsetParent).map(e => e.querySelector('span').textContent).slice(0, 20));
  const padInputs = frame.locator('.cve-w-popup .cve-w-grid .cve-w-number input');
  if (await padInputs.count()) { await padInputs.nth(0).fill('24'); await wait(300); out.paddingTop = await L.canvasStyle('h2', 'paddingTop'); }
  await L.openGroup('Border');
  await L.setNumber('Border width', '3').catch(e => out.borderErr = e.message.slice(0, 80)); await L.setSelect('Border style', 'dashed').catch(() => {}); await L.setCustom('Border colour', '#00aa00').catch(() => {});
  out.border = await L.canvasStyle('h2', 'borderTopWidth') + ' ' + await L.canvasStyle('h2', 'borderTopStyle') + ' ' + await L.canvasStyle('h2', 'borderTopColor');
  const radius = frame.locator('.cve-w-popup .cve-w-number').filter({ has: frame.locator('span', { hasText: /^(Corners|Radius|↖)/ }) }).first(); if (await radius.count()) { await radius.locator('input').first().fill('12'); await wait(300); out.radius = await L.canvasStyle('h2', 'borderTopLeftRadius'); }
  await L.setSelect('Shadow', 'preset:natural').catch(async () => { const f = L.field('Shadow'); if (await f.count()) { await f.locator('select').selectOption({ index: 1 }); } }); out.shadow = (await L.canvasStyle('h2', 'boxShadow')).slice(0, 40);
  await L.openGroup('Motion');
  await L.setSelect('Entrance', 'cve-anim-fade-up').catch(async () => { const f = L.field('Entrance'); out.entranceOpts = await f.locator('select option').evaluateAll(o => o.map(x => x.value)); await f.locator('select').selectOption({ index: 1 }); });
  await L.setSelect('Hover', 'cve-hover-lift').catch(async () => { const f = L.field('Hover'); await f.locator('select').selectOption({ index: 1 }); });
  out.className = (await L.selected()).attrs.className;
  await shot('qa-d-style');
  await L.apply();
  out.attrsAfterApply = (await L.selected()).attrs.style;
  // Reset styles
  await frame.locator('.cve-w-popup button', { hasText: 'Reset styles' }).click(); await wait(500); out.afterReset = { style: (await L.selected()).attrs.style, className: (await L.selected()).attrs.className, size: await L.canvasStyle('h2', 'fontSize') };
  await L.undo(); out.afterUndoReset = { size: await L.canvasStyle('h2', 'fontSize'), className: (await L.selected()).attrs.className };
  out.saved = await L.save();
  const html = await L.fetchFront('http://localhost:1111/ve-qa-blocks/');
  out.front = { size: /41px/.test(html), color: /#123456/.test(html), anim: /cve-anim-fade/.test(html), hover: /cve-hover-lift/.test(html), motionCss: /motion\.css|cve-motion|clara-ve-motion/.test(html), motionJs: /motion\.js|clara-ve-motion/.test(html) };
  const other = await L.fetchFront('http://localhost:1111/ve-qa-scratch/');
  out.motionNotOnOtherPage = !/clara-ve-motion|cve-motion/.test(other);
  return out;
};
