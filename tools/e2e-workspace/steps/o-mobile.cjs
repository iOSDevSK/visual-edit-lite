module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  out.toolbar = await frame.evaluate(() => { const t = document.querySelector('.cve-w-toolbar'); return { overflow: t.scrollWidth > t.clientWidth, visible: [...t.querySelectorAll('button, a, select')].filter(b => b.offsetParent).map(b => (b.getAttribute('aria-label') || b.textContent).trim().slice(0, 14)) }; });
  await L.clickBlock('h2', 20, 8); out.popup = await L.popup(); out.vw = await frame.evaluate(() => [innerWidth, innerHeight]);
  out.sheet = out.popup && out.popup.rect[0] === 0 && out.popup.rect[2] === out.vw[0] && out.popup.rect[1] + out.popup.rect[3] >= out.vw[1] - 1;
  await shot('qa-o-mobile');
  return out;
};
