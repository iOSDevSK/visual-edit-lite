/* The contrast repair that keeps WordPress's own — and a third party's —
 * controls readable inside VE's dark popup.
 *
 *   node tests/workspace-dark-sweep.cjs /path/to/node_modules
 *
 * It runs the REAL sweep: the block between the cve:dark-sweep markers in
 * assets/workspace.js is sliced out and evaluated against a rendered DOM, so a
 * change to that code is tested rather than a copy of it.
 *
 * The case that made this file: a picker whose icons are LINE icons — stroke
 * and fill:none — sitting on tiles the sweep had just painted dark, with the
 * author's #424242 left on them. Invisible, and the sweep had declared them
 * untouchable because it only ever read `fill`.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const modules = process.argv[2];
if (!modules) throw new Error('Usage: node tests/workspace-dark-sweep.cjs /path/to/node_modules');
const { chromium } = createRequire(path.resolve(modules, '../package.json'))('playwright');

const source = fs.readFileSync(path.join(__dirname, '../assets/workspace.js'), 'utf8');
const start = source.indexOf('/* cve:dark-sweep:start');
const end = source.indexOf('/* cve:dark-sweep:end */');
assert.ok(start > -1 && end > start, 'the dark-sweep markers are still in assets/workspace.js');
const sweep = source.slice(start, end);
const css = fs.readFileSync(path.join(__dirname, '../assets/workspace.css'), 'utf8');

// A third party's icon grid, with its own CSS: a light tile and a dark icon.
const fixture = `<!doctype html><html><head><style>
.kadence-icon-picker-link{display:flex;background:#f5f5f5;color:#424242;padding:8px;min-height:60px;min-width:60px;font-size:24px}
.kadence-icon-picker-link svg{width:1em;height:1em}
.solid-tile{background:#ffffff;color:#1e1e1e;padding:8px}
${css}
</style></head><body><div class="cve-dark-native" id="root">
  <button class="kadence-icon-picker-link" id="line">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12h16"/></svg>
  </button>
  <button class="solid-tile" id="solid">
    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 4h16v16H4z"/></svg>
  </button>
  <span class="component-color-indicator" id="swatch" style="background-color:#101014">
    <svg viewBox="0 0 24 24" fill="none" stroke="#101014" stroke-width="2"><path d="M4 12h16"/></svg>
  </span>
</div></body></html>`;

(async () => {
  const browser = await chromium.launch({ headless: true, executablePath: '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome' });
  const page = await browser.newPage();
  await page.setContent(fixture);
  const out = await page.evaluate(body => {
    const run = new Function(body + '\nreturn darkSweep;')();
    run(document.getElementById('root'));
    const read = id => {
      const svg = document.querySelector('#' + id + ' svg') || document.querySelector('#' + id);
      const cs = getComputedStyle(svg);
      return { classes: svg.getAttribute('class') || '', stroke: cs.stroke, fill: cs.fill };
    };
    const tile = document.getElementById('line');
    return { line: read('line'), solid: read('solid'), swatch: read('swatch'),
             tileClasses: tile.getAttribute('class') || '',
             tileBg: getComputedStyle(tile).backgroundColor };
  }, sweep);
  await browser.close();

  const ok = (cond, msg) => { if (cond) { console.log('  ok   ' + msg); return 0; } console.log('  FAIL ' + msg + '  ' + JSON.stringify(out)); return 1; };
  let bad = 0;
  bad += ok(/cve-dk-surface/.test(out.tileClasses), 'the light tile is repainted dark');
  bad += ok(/cve-dk-stroke\b/.test(out.line.classes), 'a line icon on that tile is marked for stroke repair');
  bad += ok(out.line.stroke === 'rgb(245, 245, 247)', 'and is actually drawn light');
  bad += ok(out.line.fill === 'none', 'and is NOT filled — an outline stays an outline');
  bad += ok(/cve-dk-icon\b/.test(out.solid.classes) && out.solid.fill === 'rgb(245, 245, 247)', 'a solid icon is still repaired by fill');
  bad += ok(!/cve-dk-(stroke|icon)/.test(out.swatch.classes), 'an icon inside a colour swatch is left alone');
  if (bad) { console.log('\nFAIL: ' + bad); process.exit(1); }
  console.log('\nPASS: the dark sweep repairs stroke-drawn icons without filling them');
})().catch(e => { console.error(e); process.exit(1); });
