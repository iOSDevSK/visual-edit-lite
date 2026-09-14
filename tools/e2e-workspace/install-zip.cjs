// Install the built package the way an owner does: Plugins → Add New → Upload,
// then "Replace current with uploaded". Never Delete — deleting a plugin runs
// uninstall.php, which removes every clara_ve_* option and the stored sources
// with them.
const { chromium } = require(process.env.NM + '/playwright');
const path = require('path');
const OUT = process.env.OUT || require('os').tmpdir();
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState: process.env.S + '/qa-state.json' });
  const page = await context.newPage();
  const out = {};
  await page.goto('http://localhost:1111/wp-admin/plugin-install.php?tab=upload');
  await page.setInputFiles('#pluginzip', process.env.ZIP);
  await page.click('#install-plugin-submit');
  await page.waitForLoadState('networkidle');
  const replace = await page.$('a.button:has-text("Replace current with uploaded")');
  out.sawReplacePrompt = !!replace;
  if (replace) { await replace.click(); await page.waitForLoadState('networkidle'); }
  await page.screenshot({ path: path.join(OUT, 'i01-install.png'), fullPage: true });
  out.result = (await page.textContent('body')).replace(/\s+/g, ' ').match(/Plugin (updated|installed)[^.]*\.|could not be|Destination folder|error[^.]{0,80}/i);
  await page.goto('http://localhost:1111/wp-admin/plugins.php');
  out.row = await page.evaluate(() => {
    const tr = document.querySelector('tr[data-slug="visual-edit-lite"]');
    if (!tr) return null;
    return { active: tr.className.includes('active'), text: tr.innerText.replace(/\s+/g, ' ').slice(0, 160) };
  });
  await browser.close();
  console.log(JSON.stringify(out, null, 1));
})();
