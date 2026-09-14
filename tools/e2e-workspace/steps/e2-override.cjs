module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await L.clickBlock('h2', 40, 10); await L.tab('Style'); await frame.locator('.cve-w-screens button', { hasText: 'Mobile' }).click(); await wait(1500); await L.openGroup('Typography'); await L.setNumber('Size', 22); await L.apply(); out.attr = (await L.selected()).attrs.claraVe; await frame.locator('.cve-w-screens button', { hasText: 'Desktop' }).click(); await wait(1000); out.saved = await L.save();
  const html = await L.fetchFront('http://localhost:1111/ve-qa-blocks/'); out.front = [...html.matchAll(/@media[^{]*max-width:\s*600px[^{]*\{[^}]*22px[^}]*\}/g)].map(x => x[0].slice(0, 200)); out.anchor = (html.match(/data-ve-responsive="[^"]*"/) || [])[0];
  return out;
};
