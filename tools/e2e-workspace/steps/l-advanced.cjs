module.exports = async ctx => {
  const { page, frame, shot, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = ctx.out = {};
  await L.clickBlock('h2', 40, 10); await L.tab('Advanced'); await wait(800);
  const h3 = frame.locator('.cve-w-native [aria-label="Transform to Heading 3"]').first(); out.h3Found = await h3.count(); await h3.click(); await wait(600); out.tagAfterH3 = await L.canvas().locator('.wp-block-heading').first().evaluate(e => e.tagName);
  await L.undo(); out.tagAfterUndo = await L.canvas().locator('.wp-block-heading').first().evaluate(e => e.tagName);
  out.advancedKeepsPopup = !!(await L.popup());
  // darkSweep cost on the inserter
  await L.closePopup(); await L.more('All blocks'); await wait(1500);
  out.sweep = await frame.evaluate(() => { const root = document.querySelector('.interface-interface-skeleton__secondary-sidebar'); const n = root.querySelectorAll('*').length; const t = []; for (let i = 0; i < 3; i++) { const s = performance.now(); window.ClaraVEWorkspace.darkSweep(root); t.push(+(performance.now() - s).toFixed(1)); } return { nodes: n, ms: t }; });
  const search = frame.locator('.interface-interface-skeleton__secondary-sidebar input[type="search"], .block-editor-inserter__search input').first(); await search.fill('image'); await wait(800);
  out.sweepAfterSearch = await frame.evaluate(() => { const root = document.querySelector('.interface-interface-skeleton__secondary-sidebar'); const s = performance.now(); window.ClaraVEWorkspace.darkSweep(root); return { nodes: root.querySelectorAll('*').length, ms: +(performance.now() - s).toFixed(1) }; });
  await L.closePanels();
  // link popover in the canvas (Cmd+K) readability
  await L.clickBlock('p', 20, 8); await L.canvas().locator('p').first().click({ position: { x: 5, y: 5 } }); await page.keyboard.press('Meta+a'); await page.keyboard.press('Meta+k'); await wait(1200);
  out.linkPopover = await frame.evaluate(() => { const p = [...document.querySelectorAll('.components-popover')].find(x => x.querySelector('.block-editor-link-control')); if (!p) return null; const input = p.querySelector('input'); const cs = input && getComputedStyle(input); return { dark: p.classList.contains('cve-dark-native'), inputColor: cs && cs.color, inputBg: cs && cs.backgroundColor }; });
  await shot('qa-l-linkpopover'); await page.keyboard.press('Escape');
  return out;
};
