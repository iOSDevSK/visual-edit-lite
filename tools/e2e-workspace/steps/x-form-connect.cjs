/* A form block from another plugin (Kadence) in the workspace: marked green,
   and connectable to Form Settings from the popup. POST = a page holding a
   kadence/form block. Connects, checks what was staged, then undoes — saves
   nothing. */
module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  // A block Kadence's current save() considers valid: inserted fresh unless
  // INSERT=0, so the step does not depend on markup an older version saved.
  if (process.env.INSERT !== '0') { await frame.evaluate(() => { const b = wp.blocks.createBlock('kadence/form'); wp.data.dispatch('core/block-editor').insertBlocks([b]); wp.data.dispatch('core/block-editor').clearSelectedBlock(); }); await wait(2500); }
  const form = L.canvas().locator('[data-type="kadence/form"]').last();
  await form.scrollIntoViewIfNeeded(); await wait(600);
  out.marked = await form.evaluate(e => { const cs = getComputedStyle(e); const b = getComputedStyle(e, '::before'); return { outline: cs.outlineStyle + ' ' + cs.outlineColor, label: b.content, labelBg: b.backgroundColor }; });
  await shot('x-form-connect-marked-' + page.viewportSize().width);
  const box = await form.boundingBox();
  await page.mouse.click(box.x + box.width - 6, box.y + 6); await wait(900);
  let sel = await L.selected();
  if (!sel || sel.name !== 'kadence/form') { await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getBlocksByName('kadence/form').slice(-1)[0]; wp.data.dispatch('core/block-editor').selectBlock(id); }); await wait(900); await frame.evaluate(() => window.dispatchEvent(new CustomEvent('clara-ve-open-popup'))); await wait(900); sel = await L.selected(); out.selectedByApi = true; }
  out.selected = sel && sel.name;
  out.badgeGreen = await frame.evaluate(() => !!document.querySelector('.cve-w-badge.cve-w-badge-form'));
  out.popup = await L.popup();
  const sentBy = L.field('Sent by');
  out.hasSentBy = await sentBy.count();
  await shot('x-form-connect-popup-own-' + page.viewportSize().width);
  if (out.hasSentBy) {
    await L.setSelect('Sent by', 've'); await wait(500);
    out.fieldsAfterConnect = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup label.cve-w-field > span')].map(s => s.textContent));
    await L.setInput('Send to', 'owner@example.test'); await wait(300);
    out.staged = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const a = s.getBlockAttributes(s.getBlocksByName('kadence/form').slice(-1)[0]); return { actions: a.actions, delivery: a.claraVe && a.claraVe.delivery }; });
    await shot('x-form-connect-popup-ve-' + page.viewportSize().width);
    await L.setSelect('Does', 'list'); await wait(1200);
    out.listFields = await frame.evaluate(() => [...document.querySelectorAll('.cve-w-popup label.cve-w-field > span')].map(s => s.textContent));
    await L.setSelect('Sent by', 'own'); await wait(400);
    out.restored = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const a = s.getBlockAttributes(s.getBlocksByName('kadence/form').slice(-1)[0]); return { actions: a.actions, connect: a.claraVe && a.claraVe.delivery && a.claraVe.delivery.connect }; });
    await L.cancel();
    out.afterCancel = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const a = s.getBlockAttributes(s.getBlocksByName('kadence/form').slice(-1)[0]); return { actions: a.actions, claraVe: a.claraVe || null }; });
  }
  return out;
};
