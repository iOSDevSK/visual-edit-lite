const { chromium } = require(process.env.NM + '/playwright'); const fs = require('fs');
(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 }, storageState: process.env.S + '/qa-state.json' });
  const page = await context.newPage(); const logs = []; page.on('pageerror', e => logs.push(e.message.slice(0, 120))); page.on('console', m => { if (m.type() === 'error') logs.push(m.text().slice(0, 120)); });
  await page.goto('http://localhost:1111/wp-admin/post.php?post=417&action=edit'); await page.waitForTimeout(9000);
  const out = await page.evaluate(() => ({ veToolbar: !!document.querySelector('.cve-w-toolbar'), workspaceClass: document.body.classList.contains('cve-workspace-runtime'), nativeHeader: !!document.querySelector('.editor-header') && getComputedStyle(document.querySelector('.editor-header')).display !== 'none', legacySidebar: !!document.querySelector('[aria-label="Visual Edit Lite"], .components-button[aria-label*="Visual Edit"]'), pinned: [...document.querySelectorAll('.interface-pinned-items button')].map(b => b.getAttribute('aria-label')), scripts: [...document.scripts].map(s => (s.id || '')).filter(id => /clara-ve/.test(id)), seoPanel: !!document.querySelector('[class*="cve-gutenberg-seo"], .components-panel__body-title button:is([aria-label*="Search appearance"])') || [...document.querySelectorAll('.components-panel__body-toggle')].some(b => /Search appearance|SEO/.test(b.textContent)) }));
  // open document sidebar and look for the SEO panel
  await page.evaluate(() => wp.data.dispatch('core/interface').enableComplementaryArea('core', 'edit-post/document')); await page.waitForTimeout(1500);
  out.seoPanelOpen = await page.evaluate(() => [...document.querySelectorAll('.components-panel__body-toggle, .components-panel__body-title')].map(b => b.textContent.trim()).filter(t => /Search|SEO|Visual/.test(t)));
  await page.screenshot({ path: '/Users/filipdvoran/Developer/visual-edit-lite-gut/.playwright-mcp/shots/qa-n-plain.png' });
  console.log(JSON.stringify(out, null, 1)); console.log('LOGS', JSON.stringify(logs.filter(l => !/BricolageGrotesque|Failed to load resource|iframe incorrectly/.test(l))));
  await browser.close();
})();
