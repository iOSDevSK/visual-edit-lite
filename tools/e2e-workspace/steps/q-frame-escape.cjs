module.exports = async ctx => {
  const { page, frame, wait, shot } = ctx; const out = ctx.out = {};
  const fr = () => page.$('iframe.cve-workspace-frame');
  // 1) workspace-to-workspace navigation stays inside
  const wsUrl = await frame.evaluate(() => { const u = new URL(location.href); return u.toString(); });
  await frame.evaluate(u => location.assign(u), wsUrl); await wait(6000);
  out.stayTop = page.url(); out.stayFrame = !!(await fr());
  // 2) a click-like navigation to the dashboard inside the frame
  const f2 = await (await fr()).contentFrame();
  await f2.evaluate(() => { const a = document.createElement('a'); a.href = '/wp-admin/index.php'; document.body.appendChild(a); a.click(); });
  await page.waitForURL(/wp-admin\/index\.php/, { timeout: 20000 }).catch(e => out.err1 = e.message);
  await wait(1500);
  out.dashTop = page.url(); out.adminBars = await page.evaluate(() => document.querySelectorAll('#wpadminbar').length); out.framesLeft = await page.evaluate(() => document.querySelectorAll('iframe.cve-workspace-frame').length);
  await shot('frame-escape-dashboard');
  // 3) a frontend page inside the frame
  await page.goto('http://localhost:1111/wp-admin/admin.php?page=visual-edit'); await page.frameLocator('iframe.cve-workspace-frame').locator('.cve-w-toolbar').waitFor({ timeout: 60000 });
  const f3 = await (await fr()).contentFrame();
  await f3.evaluate(() => { location.href = '/'; });
  await page.waitForURL(u => !String(u).includes('page=visual-edit'), { timeout: 20000 }).catch(e => out.err2 = e.message);
  out.frontTop = page.url();
  return out;
};
