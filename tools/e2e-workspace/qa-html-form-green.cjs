/* HTML mode: is every <form> in the edit preview green, and labelled? */
const path = require('path');
(async () => {
  const { chromium } = require(process.env.NM + '/playwright');
  const OUT = process.env.OUT;
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  const logs = []; page.on('console', m => { if (m.type() === 'error') logs.push(m.text().slice(0, 160)); });
  await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit');
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin123'); await page.press('#user_pass', 'Enter');
    await page.waitForURL(/wp-admin/); await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit');
  }
  await page.waitForTimeout(6000);
  const pages = await page.$$eval('#clara-ve-page-picker option', os => os.map(o => o.value + '|' + o.textContent));
  const out = { pages: pages.slice(0, 20), frames: [] };
  for (const target of (process.env.KEYS || '').split(',').filter(Boolean)) {
    await page.selectOption('#clara-ve-page-picker', target).catch(() => {});
    await page.waitForTimeout(7000);
    const on = await page.$eval('#clara-ve-toggle', b => b.getAttribute('aria-pressed'));
    if (on !== 'true') { await page.click('#clara-ve-toggle'); await page.waitForTimeout(2500); }
    const all = [];
    for (const fr of page.frames()) {
      if (fr === page.mainFrame()) continue;
      let edit = false, stamped = 0; try { edit = await fr.evaluate(() => document.documentElement.hasAttribute('data-cve-edit-mode')); stamped = await fr.evaluate(() => document.querySelectorAll('[data-cve-path]').length); } catch (e) {}
      all.push({ url: fr.url().slice(0, 90), edit, stamped });
    }
    out.allFrames = all;
    let f = null;
    for (const fr of page.frames()) {
      if (fr === page.mainFrame()) continue;
      try { if (await fr.evaluate(() => !!document.querySelector('[data-cve-path]'))) { f = fr; break; } } catch (e) {}
    }
    if (!f) { out.frames.push({ target, error: 'no preview frame' }); continue; }
    const res = await f.evaluate(() => {
      const forms = [...document.querySelectorAll('form')];
      return { count: forms.length, first: forms[0] ? { outline: getComputedStyle(forms[0]).outlineColor, style: getComputedStyle(forms[0]).outlineStyle, label: getComputedStyle(forms[0], '::before').content, pos: getComputedStyle(forms[0]).position } : null, editMode: document.documentElement.hasAttribute('data-cve-edit-mode') };
    });
    if (res.first) await f.evaluate(() => { document.querySelector('form').scrollIntoView({ block: 'start' }); window.scrollBy(0, -90); });
    await page.waitForTimeout(700);
    await page.screenshot({ path: path.join(OUT, 'html-green-' + target.replace(/\W/g, '') + '.png') });
    out.frames.push({ target, ...res });
  }
  out.logs = logs.slice(0, 3);
  console.log(JSON.stringify(out, null, 1));
  await browser.close();
})();
