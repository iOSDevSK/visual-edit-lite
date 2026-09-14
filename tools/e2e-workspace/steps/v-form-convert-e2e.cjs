module.exports = async ctx => {
  const { page, frame, wait, logs } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {}; const path = require('path'); const canvas = L.canvas();
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  const ed = (fn, arg) => frame.evaluate(fn, arg);
  const selectByName = async (name, nth = 0) => { await ed(([n, i]) => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().filter(x => s.getBlockName(x) === n)[i]; wp.data.dispatch('core/block-editor').selectBlock(id); window.dispatchEvent(new CustomEvent('clara-ve-open-popup')); }, [name, nth]); await wait(900); };
  const tree = () => ed(() => { const s = wp.data.select('core/block-editor'); const walk = id => { const b = s.getBlock(id); return [b.name + (b.attributes.label ? ':' + b.attributes.label : b.attributes.text ? ':' + b.attributes.text : ''), ...(b.innerBlocks || []).map(x => walk(x.clientId))]; }; return s.getBlockOrder().map(walk); });
  // 1) convert
  await canvas.locator('.cve-shortcode-preview').first().waitFor({ timeout: 20000 });
  await selectByName('core/shortcode');
  await frame.locator('.cve-w-popup button', { hasText: 'Make this form editable' }).click(); await wait(2500);
  out.afterConvert = JSON.stringify(await tree());
  out.formAttrs = await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/form'); return s.getBlockAttributes(id); });
  await page.screenshot({ path: path.join(process.env.OUT, 'convert-canvas.png') });
  // 2) undo restores the shortcode, redo brings the form back
  await page.keyboard.press('Meta+z'); await wait(700); out.undoNames = await ed(() => wp.data.select('core/block-editor').getBlocks().map(b => b.name));
  await page.keyboard.press('Meta+Shift+z'); await wait(900); out.redoNames = await ed(() => wp.data.select('core/block-editor').getBlocks().map(b => b.name));
  // 3) click a field on the canvas selects it
  const nameInput = canvas.locator('.wp-block-clara-ve-field input').first(); await nameInput.scrollIntoViewIfNeeded();
  const ifr = await (await frame.$('iframe[name="editor-canvas"]')).boundingBox(); const b = await nameInput.boundingBox();
  await page.mouse.click(ifr.x + b.x + 20, ifr.y + b.y + b.height / 2); await wait(900);
  out.clickSelected = await ed(() => { const s = wp.data.select('core/block-editor'); return s.getBlockName(s.getSelectedBlockClientId()); });
  out.contentGroups = await frame.locator('.cve-w-popup .cve-w-section-title, .cve-w-popup [class*="section"] > button').allTextContents().catch(() => []);
  // 4) edit placeholder + label
  const fieldInput = label => frame.locator(`.cve-w-popup .cve-w-field:has(> span:text-is("${label}")) input`);
  await fieldInput('Placeholder').fill('Your two names'); await fieldInput('Label').fill('Names of the couple'); await wait(300); await L.apply();
  // 5) choice list: add an option
  await selectByName('clara-ve/select', 1);
  const ta = frame.locator('.cve-w-popup .cve-w-field-area textarea'); const v = await ta.inputValue(); await ta.fill(v + '\nA QA option'); await ta.blur(); await wait(300); await L.apply();
  // 6) rename the button
  await selectByName('clara-ve/submit'); await fieldInput('Button text').fill('Send it now'); await wait(200); await L.apply();
  // 7) remove "Where in the world", add an Email field from the form settings
  await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/field' && s.getBlockAttributes(x).name === 'location'); wp.data.dispatch('core/block-editor').removeBlocks([id], false); });
  await selectByName('clara-ve/form'); await frame.locator('.cve-w-popup button', { hasText: '＋ Email' }).click(); await wait(600);
  await L.apply();
  // 7b) a second Email keeps its own key; renaming its label later does not move it; a duplicate gets the next key
  const names = () => ed(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(x => /^clara-ve\/(field|textarea|select|checkbox)$/.test(s.getBlockName(x))).map(x => s.getBlockAttributes(x).name); });
  out.namesAfterAdd = await names();
  await selectByName('clara-ve/field', (await names()).lastIndexOf('email-2') >= 0 ? (await ed(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(x => s.getBlockName(x) === 'clara-ve/field').map(x => s.getBlockAttributes(x).name); })).indexOf('email-2') : 0);
  await fieldInput('Label').fill('Second email'); await wait(300); await L.apply();
  out.namesAfterRename = await names();
  await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/field' && s.getBlockAttributes(x).name === 'email-2'); wp.data.dispatch('core/block-editor').duplicateBlocks([id], false); });
  await wait(900); out.namesAfterDuplicate = await names();
  out.duplicateRemoved = await ed(() => { const s = wp.data.select('core/block-editor'); const id = s.getClientIdsWithDescendants().find(x => s.getBlockName(x) === 'clara-ve/field' && s.getBlockAttributes(x).name === 'email-3'); if (id) wp.data.dispatch('core/block-editor').removeBlocks([id], false); return !!id; });
  await selectByName('clara-ve/form'); await frame.locator('.cve-w-popup .cve-w-tab', { hasText: 'Section' }).click().catch(() => {}); await wait(300);
  out.formAddItem = await frame.locator('.cve-w-popup button', { hasText: 'Add item' }).count(); await L.apply();
  out.afterEdits = JSON.stringify(await tree());
  // 8) save, reload, validity
  await page.keyboard.press('Meta+s'); await wait(4000);
  out.dirty = await ed(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length);
  await page.reload(); const f2 = await (await page.waitForSelector('iframe.cve-workspace-frame')).contentFrame(); await f2.waitForFunction(() => window.wp && wp.data && wp.data.select('core/block-editor').getBlocks().length > 0, null, { timeout: 60000 }); await page.waitForTimeout(3000);
  out.invalid = await f2.evaluate(() => { const s = wp.data.select('core/block-editor'); return s.getClientIdsWithDescendants().filter(id => s.getBlock(id).isValid === false).map(id => s.getBlockName(id)); });
  out.logs = logs.filter(l => /validation|Block/i.test(l)).slice(0, 3);
  return out;
};
