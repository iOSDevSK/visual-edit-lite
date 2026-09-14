/* Headless QA driver for the VE workspace on localhost:1111. Usage: node qa.cjs <step> */
const path = require('path');
const { chromium } = require(process.env.NM + '/playwright');
const OUT = process.env.OUT || require('os').tmpdir();
const STATE = (process.env.S || require('os').tmpdir()) + '/qa-state.json';
const fs = require('fs');
(async () => {
  const width = +(process.env.W || 1440), height = +(process.env.H || 900);
  const browser = await chromium.launch({ headless: !process.env.HEADED, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width, height }, storageState: fs.existsSync(STATE) ? STATE : undefined });
  const page = await context.newPage();
  const logs = []; const dialogs = []; let dialogMode = 'dismiss';
  page.on('dialog', async d => { dialogs.push(d.message()); if (dialogMode === 'accept') await d.accept(); else await d.dismiss(); });
  page.on('console', m => { if (['error', 'warning'].includes(m.type())) logs.push(m.type() + ': ' + m.text().slice(0, 300)); });
  page.on('pageerror', e => logs.push('pageerror: ' + e.message));
  await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit' + (process.env.POST ? '&post=' + process.env.POST : ''));
  if (page.url().includes('wp-login.php')) {
    await page.fill('#user_login', 'admin'); await page.fill('#user_pass', 'admin123'); await page.press('#user_pass', 'Enter');
    await page.waitForURL(/wp-admin/); await context.storageState({ path: STATE });
    await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit' + (process.env.POST ? '&post=' + process.env.POST : ''));
  }
  const host = page.frameLocator('iframe.cve-workspace-frame');
  await host.locator('.cve-w-toolbar').waitFor({ timeout: 60000 });
  const frame = await (await page.$('iframe.cve-workspace-frame')).contentFrame();
  await frame.waitForFunction(() => document.body.classList.contains('cve-native-collapsed'), null, { timeout: 60000 });
  await page.waitForTimeout(2500);
  const shot = name => page.screenshot({ path: path.join(OUT, name + '.png') });
  const ctx = { page, frame, host, shot, logs, dialogs, setDialog: m => { dialogMode = m; }, wait: ms => page.waitForTimeout(ms) };
  try {
    const step = require(path.resolve(process.argv[2]));
    const result = await step(ctx);
    console.log(JSON.stringify(result, null, 1));
  } catch (e) { console.log('STEP ERROR', e.message); if (ctx.out) console.log('PARTIAL', JSON.stringify(ctx.out, null, 1)); await shot('error'); }
  console.log('LOGS', JSON.stringify(logs.filter(l => !/was added to the iframe incorrectly|BricolageGrotesque|Failed to load resource/.test(l)), null, 1));
  await browser.close();
})();
