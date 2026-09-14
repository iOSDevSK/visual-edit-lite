module.exports = async ctx => {
  const { frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const form = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); const parent = s.getBlockParents(form).pop(); wp.data.dispatch('core/block-editor').selectBlock(parent || form); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); });
  await wait(1200); await L.tab('Section').catch(() => {}); await wait(500);
  const measure = () => frame.evaluate(() => {
    const body = document.querySelector('.cve-w-popup .cve-w-body');
    const ol = document.querySelector('.cve-w-popup .cve-w-items ol');
    const li = ol && ol.querySelector('li');
    return { olW: ol && Math.round(ol.getBoundingClientRect().width), liW: li && Math.round(li.getBoundingClientRect().width), bodyScrolls: body.scrollWidth > body.clientWidth, cols: ol && getComputedStyle(ol).gridTemplateColumns };
  });
  out.before = await measure();
  await frame.evaluate(() => { const s = document.createElement('style'); s.id = 'probe'; s.textContent = '.cve-w-popup .cve-w-items ol { grid-template-columns: minmax(0, 1fr); } .cve-w-popup .cve-w-items li { min-width: 0; }'; document.head.appendChild(s); });
  await wait(400); out.after = await measure();
  return out;
};
