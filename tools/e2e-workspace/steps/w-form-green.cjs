/* Forms are marked green, and only forms: outline colour on the form, on a field inside it and on a paragraph outside it. */
module.exports = async ctx => {
  const { page, frame, wait, logs } = ctx; const out = {}; const path = require('path');
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  await wait(1200);
  out.colours = await frame.evaluate(() => {
    const doc = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
    const read = el => el ? getComputedStyle(el).outlineColor + ' / ' + getComputedStyle(el).outlineStyle : null;
    const form = doc.querySelector('.block-editor-block-list__block[data-type="clara-ve/form"]');
    const field = doc.querySelector('.block-editor-block-list__block[data-type="clara-ve/field"]');
    const para = doc.querySelector('.block-editor-block-list__block[data-type="core/paragraph"], .block-editor-block-list__block[data-type="core/heading"]');
    const inner = form ? form.querySelector('form') : doc.querySelector('form');
    return { form: read(form), field: read(field), outside: read(para), label: form ? getComputedStyle(form, '::before').content : null,
      innerForm: inner ? read(inner) : null, innerLabel: inner ? getComputedStyle(inner, '::before').content : null };
  });
  await frame.evaluate(() => {
    const doc = document.querySelector('iframe[name="editor-canvas"]')?.contentDocument || document;
    const form = doc.querySelector('.block-editor-block-list__block[data-type="clara-ve/form"]') || doc.querySelector('form');
    if (form) form.scrollIntoView({ block: 'center' });
  });
  await wait(900);
  await page.screenshot({ path: path.join(process.env.OUT, 'form-green.png') });
  out.logs = logs.filter(l => !/iframe incorrectly/.test(l)).slice(0, 3);
  return out;
};
