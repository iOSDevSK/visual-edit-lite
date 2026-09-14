// A field inside a form must offer the way up to the form itself, because a
// one-row signup leaves no pixel of <form> to click.
const path = require('path');
const { chromium } = require(process.env.NM + '/playwright');
const OUT = process.env.OUT || require('os').tmpdir();
(async () => {
  const STATE = process.env.S + '/qa-state.json';
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: STATE });
  const page = await context.newPage();
  const logs = []; page.on('console', m => { if (m.type() === 'error') logs.push(m.text().slice(0, 200)); }); page.on('pageerror', e => logs.push('pageerror ' + e.message));
  await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit');
  await page.waitForSelector('#clara-ve-frame'); await page.waitForTimeout(6000);
  if (process.env.SLUG) {
    
    const val = await page.evaluate(slug => {
      const o = [...document.querySelectorAll('#clara-ve-page-picker option')].find(o => (o.value + ' ' + o.textContent).toLowerCase().includes(slug));
      return o ? o.value : null;
    }, process.env.SLUG.toLowerCase());
    if (val) { await page.selectOption('#clara-ve-page-picker', val); await page.waitForTimeout(6000); }
  }
  const off = await page.$('#clara-ve-toggle.is-off'); if (off) { await off.click(); await page.waitForTimeout(900); }
  const frame = page.frames().find(f => f.url().includes('clara_edit=1'));
  const out = {};

  const input = await frame.$('form input[type="email"], form input[type="text"], form input:not([type="hidden"])');
  if (!input) { console.log(JSON.stringify({ error: 'no form field on this page' })); await browser.close(); return; }
  await input.scrollIntoViewIfNeeded();
  await page.waitForTimeout(600);
  const fb = await (await page.$('#clara-ve-frame')).boundingBox();
  const b = await input.boundingBox();
  out.clickAt = [Math.round(b.x), Math.round(b.y), Math.round(b.width), Math.round(b.height)];
  await page.mouse.click(fb.x + b.x + b.width / 2, fb.y + b.y + b.height / 2);
  await page.waitForTimeout(1000);
  await page.screenshot({ path: path.join(OUT, 'f01-field.png') });

  out.fieldPanel = await page.evaluate(() => {
    const p = document.querySelector('.cve-panel'); if (!p) return null;
    return { title: p.querySelector('.cve-title').textContent, up: !!p.querySelector('.cve-form-up-btn'),
      sections: [...p.querySelectorAll('.cve-section')].map(s => s.textContent) };
  });

  // The link says where you are, so it must survive a tab change.
  out.upVisiblePerTab = await page.evaluate(() => {
    const out = {};
    document.querySelectorAll('.cve-panel .cve-tab').forEach(t => {
      t.click();
      const b = document.querySelector('.cve-panel .cve-form-up');
      out[t.textContent] = !!(b && !b.classList.contains('cve-tab-hidden'));
    });
    const first = document.querySelector('.cve-panel .cve-tab'); if (first) first.click();
    return out;
  });

  const up = await page.$('.cve-form-up-btn');
  if (up) { await up.click(); await page.waitForTimeout(1200); }
  await page.screenshot({ path: path.join(OUT, 'f02-form.png') });

  out.formPanel = await page.evaluate(() => {
    const p = document.querySelector('.cve-panel'); if (!p) return null;
    return { title: p.querySelector('.cve-title').textContent, up: !!p.querySelector('.cve-form-up-btn'),
      sections: [...p.querySelectorAll('.cve-section')].map(s => s.textContent) };
  });
  // And the trip has to be worth taking: connect the form, then discard.
  out.connected = await page.evaluate(() => {
    const sel = [...document.querySelectorAll('.cve-panel select')].find(s => /Nothing|Contact|Mailing/.test(s.textContent));
    if (!sel) return 'no delivery select';
    const opt = [...sel.options].find(o => /Mailing/i.test(o.textContent));
    if (!opt) return 'no mailing-list option';
    sel.value = opt.value; sel.dispatchEvent(new Event('change', { bubbles: true }));
    return 'picked: ' + opt.textContent;
  });
  await page.waitForTimeout(600);
  await page.screenshot({ path: require('path').join(process.env.OUT, 'f03-connected.png') });
  out.status = await page.evaluate(() => (document.querySelector('#clara-ve-status') || {}).textContent);
  await page.evaluate(() => { const b = document.querySelector('#clara-ve-discard'); if (b && !b.disabled) b.click(); });
  await page.waitForTimeout(1500);
  page.on('dialog', d => d.accept());
  out.afterDiscard = await page.evaluate(() => (document.querySelector('#clara-ve-status') || {}).textContent);
  out.logs = logs;
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
