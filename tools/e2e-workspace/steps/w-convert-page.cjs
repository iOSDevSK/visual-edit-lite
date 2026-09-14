/* Converts every theme shortcode form on POST into form blocks through the popup, saves when SAVE=1. */
module.exports = async ctx => {
  const { page, frame, wait, logs } = ctx; const L = require('./lib.cjs')(ctx); const out = {}; const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const ed = (fn, arg) => frame.evaluate(fn, arg);
  const shortcodes = () => ed(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(x => s.getBlockName(x) === 'core/shortcode' && /\[[a-z0-9_-]*form/i.test(s.getBlockAttributes(x).text || '')); });
  out.before = (await shortcodes()).length;
  for (let guard = 0; guard < 5 && (await shortcodes()).length; guard++) {
    const id = (await shortcodes())[0];
    await ed(i => { wp.data.dispatch('core/block-editor').selectBlock(i); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); }, id); await wait(1200);
    await frame.locator('.cve-w-popup button', { hasText: 'Make this form editable' }).click(); await wait(3000);
    await L.apply().catch(() => {});
  }
  out.after = (await shortcodes()).length;
  out.forms = await ed(() => { const s = wp.data.select('core/block-editor'); const walk = id => { const b = s.getBlock(id); const a = b.attributes; return { n: b.name.replace('clara-ve/', ''), ...(a.name ? { name: a.name } : {}), ...(a.label ? { label: a.label } : {}), ...(a.text ? { text: a.text } : {}), ...(a.formId ? { formId: a.formId, formClass: a.formClass, redirect: a.redirect, message: a.message } : {}), ...(b.innerBlocks.length ? { kids: b.innerBlocks.map(x => walk(x.clientId)) } : {}) }; }; return s.getClientIdsWithDescendants().filter(x => s.getBlockName(x) === 'clara-ve/form').map(walk); });
  const first = L.canvas().locator('.wp-block-clara-ve-form').first(); await first.scrollIntoViewIfNeeded().catch(() => {}); await wait(600);
  await page.screenshot({ path: path.join(process.env.OUT, `editor-${process.env.POST}.png`) });
  if (process.env.SAVE === '1' && out.after === 0 && out.forms.length === out.before) {
    await L.save();
    await page.reload(); const f2 = await (await page.waitForSelector('iframe.cve-workspace-frame')).contentFrame(); await f2.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor').getBlocks().length > 0, null, { timeout: 60000 }); await page.waitForTimeout(3000);
    out.saved = true;
    out.invalid = await f2.evaluate(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(id => s.getBlock(id).isValid === false).map(id => s.getBlockName(id)); });
    out.formsAfterReload = await f2.evaluate(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(x => s.getBlockName(x) === 'clara-ve/form').length; });
  }
  out.logs = logs.filter(l => !/added to the iframe incorrectly/.test(l)).slice(0, 5);
  return out;
};
