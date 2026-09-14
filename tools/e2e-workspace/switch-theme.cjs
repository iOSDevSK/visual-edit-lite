const { chromium } = require(process.env.NM + '/playwright');
const fs = require('fs');
(async () => {
  const STATE = process.env.S + '/qa-state.json';
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ storageState: fs.existsSync(STATE) ? STATE : undefined });
  const page = await context.newPage();
  await page.goto('http://localhost:1111/wp-admin/themes.php');
  const href = await page.evaluate(slug => { const a = [...document.querySelectorAll('a.activate')].find(x => x.href.includes('stylesheet=' + slug)); return a && a.href; }, process.argv[2]);
  if (!href) { console.log('already active or not found:', await page.evaluate(() => document.querySelector('.theme.active .theme-name') && document.querySelector('.theme.active .theme-name').textContent)); await browser.close(); return; }
  await page.goto(href);
  console.log('active:', await page.evaluate(() => document.querySelector('.theme.active .theme-name') && document.querySelector('.theme.active .theme-name').textContent.trim()));
  await browser.close();
})();
