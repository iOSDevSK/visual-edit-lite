/* The form's delivery choice in the popup: Does / Send to / List, and what it writes. */
module.exports = async ctx => {
  const { page, frame, wait, logs } = ctx; const L = require('./lib.cjs')(ctx); const out = {}; const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const ed = (fn, arg) => frame.evaluate(fn, arg);
  await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); wp.data.dispatch('core/block-editor').selectBlock(id); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); });
  await wait(1200);
  const field = label => frame.locator(`.cve-w-popup .cve-w-field:has(> span:text-is("${label}"))`).first();
  out.fields = await frame.locator('.cve-w-popup .cve-w-field > span').allTextContents();
  out.sendToPlaceholder = await field('Send to').locator('input').getAttribute('placeholder');
  await field('Send to').locator('input').fill('studio@example.org'); await wait(400);
  await field('Does').locator('select').selectOption('list'); await wait(1400);
  out.afterList = await frame.locator('.cve-w-popup .cve-w-field > span').allTextContents();
  out.listNote = await frame.locator('.cve-w-popup .cve-w-note').allTextContents();
  await field('List').locator('input, select').first().fill('7').catch(async () => { await field('List').locator('select').selectOption({ index: 0 }); });
  await wait(400);
  await page.screenshot({ path: path.join(process.env.OUT, 'form-delivery.png') });
  out.attrs = await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); const a = s.getBlockAttributes(id); return { formType: a.formType, listId: a.listId, recipient: a.recipient }; });
  await L.btn('Cancel').click(); await wait(600);
  out.afterCancel = await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); const a = s.getBlockAttributes(id); return { formType: a.formType, listId: a.listId, recipient: a.recipient }; });
  out.dirty = await ed(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length);
  out.logs = logs.filter(l => !/iframe incorrectly/.test(l)).slice(0, 3);
  return out;
};
