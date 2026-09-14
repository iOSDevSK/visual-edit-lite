module.exports = async ctx => {
  const { page, frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = {}; const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const form = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); const parent = s.getBlockParents(form).pop(); wp.data.dispatch('core/block-editor').selectBlock(parent || form); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); });
  await wait(1200);
  await L.tab('Section').catch(() => {});
  await wait(600);
  out.measure = await frame.evaluate(() => {
    const body = document.querySelector('.cve-w-popup .cve-w-body');
    const items = document.querySelector('.cve-w-popup .cve-w-items');
    const li = items && items.querySelector('li');
    const box = el => el ? { w: Math.round(el.getBoundingClientRect().width), sw: el.scrollWidth, cw: el.clientWidth } : null;
    return {
      popup: box(document.querySelector('.cve-w-popup')),
      body: box(body),
      bodyScrolls: body ? body.scrollWidth > body.clientWidth : null,
      items: box(items),
      li: box(li),
      children: li ? [...li.children].map(c => ({ cls: c.className.slice(0, 24), w: Math.round(c.getBoundingClientRect().width), minW: getComputedStyle(c).minWidth, flex: getComputedStyle(c).flex, pad: getComputedStyle(c).padding })) : [],
      liRight: li ? Math.round(li.getBoundingClientRect().right) : null,
      bodyRight: body ? Math.round(body.getBoundingClientRect().right) : null
    };
  });
  await page.screenshot({ path: path.join(process.env.OUT, 'items-ui.png') });
  return out;
};
