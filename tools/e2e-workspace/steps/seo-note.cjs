module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await frame.locator('.cve-w-hint button', { hasText: 'Got it' }).click().catch(() => {});
  out.cfg = await frame.evaluate(() => ({ publicSeo: window.claraVeGutenberg.publicSeo, url: window.claraVeGutenberg.seoSettingsUrl }));
  await L.more('Search appearance'); await wait(1200);
  out.note = await frame.evaluate(() => (document.querySelector('.components-modal__frame .cve-w-mode-note') || {}).textContent);
  await shot('qa-seo-note'); return out;
};
