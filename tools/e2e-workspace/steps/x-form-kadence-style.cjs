/* Form button styling reaches a Kadence form in the editor, where Kadence draws the
   button as an editable <div>. Uses the popup like a person: Style tab → Form button →
   Background Custom + Case lowercase. Cancels the popup and undoes; saves nothing.
   POST = a page with a kadence/form block. */
module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.evaluate(() => { try { const p = wp.data.dispatch('core/preferences'); p.set('core/edit-post', 'welcomeGuide', false); p.set('core/edit-site', 'welcomeGuide', false); } catch (e) {} });
  const canvas = () => frame.frameLocator('iframe[name="editor-canvas"]');
  const read = () => frame.evaluate(() => { const doc = document.querySelector('iframe[name="editor-canvas"]').contentDocument; const b = doc.querySelector('.wp-block-kadence-form .kb-forms-submit, [data-type="kadence/form"] .kb-forms-submit'); const c = b && doc.defaultView.getComputedStyle(b); return c && { bg: c.backgroundColor, color: c.color, tt: c.textTransform }; });
  await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(i => s.getBlockName(i) === 'kadence/form'); wp.data.dispatch('core/block-editor').selectBlock(id); });
  await wait(700); await frame.evaluate(() => window.dispatchEvent(new CustomEvent('clara-ve-open-popup'))); await wait(900);
  out.before = await read();
  await frame.locator('.cve-w-popup [role=tab]', { hasText: 'Style' }).click(); await wait(400);
  const title = frame.locator('.cve-w-popup .cve-w-section-title', { hasText: 'Form button' });
  if ((await title.getAttribute('aria-expanded')) !== 'true') { await title.click(); await wait(300); }
  const section = frame.locator('.cve-w-popup section.cve-w-section').filter({ has: frame.locator('.cve-w-section-title', { hasText: 'Form button' }) });
  const bg = section.locator('label.cve-w-field:has(> span:text-is("Background")) select');
  await bg.selectOption('__custom'); await wait(300);
  await section.locator('input[type=color][aria-label="Background"]').fill('#e11d2a'); await wait(200);
  await section.locator('label.cve-w-field:has(> span:text-is("Case")) select').selectOption('lowercase');
  await wait(900); // Kadence animates the button's colours
  out.after = await read();
  out.attr = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); return (s.getBlockAttributes(s.getSelectedBlockClientId()).claraVe || {}).form; });
  await canvas().locator('.kb-forms-submit').first().scrollIntoViewIfNeeded().catch(() => {});
  await shot('x-form-kadence-style-' + page.viewportSize().width);
  out.bgApplied = out.after && out.after.bg === 'rgb(225, 29, 42)';
  out.caseApplied = out.after && out.after.tt === 'lowercase';
  await frame.locator('.cve-w-popup button', { hasText: 'Cancel' }).click().catch(() => {}); await wait(600);
  out.attrAfterCancel = await frame.evaluate(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(i => s.getBlockName(i) === 'kadence/form'); return (s.getBlockAttributes(id).claraVe || {}).form || null; });
  return out;
};
