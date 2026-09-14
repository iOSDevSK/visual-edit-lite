/* Offline CSS fixture. Chromium by default; --dom runs a non-rendering jsdom check. */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { createRequire } = require('node:module');
const [modules, wordpress, screenshot, executablePath] = process.argv.slice(2);
if (!modules || !wordpress) throw new Error('Usage: node tests/workspace-css.cjs /path/to/node_modules /path/to/wordpress [--dom | screenshot.png] [chrome-executable]');
const dependency = createRequire(path.resolve(modules, '../package.json'));
const { chromium } = dependency('playwright');
async function run() {
  if (screenshot === '--dom') {
    const { JSDOM } = dependency('jsdom');
    const dom = new JSDOM('<!doctype html><html><head></head><body class="wp-core-ui"><div class="cve-w-popup"><div class="cve-w-field"><select id="enabled"><option>Inherit</option></select><select id="disabled" disabled><option>Inherit</option></select></div><fieldset disabled><div class="cve-w-field"><select id="inherited"><option>Inherit</option></select></div></fieldset><div class="cve-w-field cve-w-number"><select id="unit" disabled><option>px</option></select></div></div><div class="cve-w-dialog"><div class="cve-w-field"><select id="history" disabled><option>Index</option></select></div></div><div class="cve-w-toolbar"><select id="toolbar"><option>Desktop</option></select></div></body></html>');
    try {
      for (const file of [path.join(wordpress, 'wp-admin/css/forms.css'), path.join(__dirname, '../assets/workspace.css')]) {
        const tag = dom.window.document.createElement('style'); tag.textContent = fs.readFileSync(file, 'utf8'); dom.window.document.head.appendChild(tag);
      }
      for (const node of dom.window.document.querySelectorAll('select')) {
        const css = dom.window.getComputedStyle(node);
        // Exactly one arrow: VE's own chevron, never WordPress' forms.css SVG.
        assert.ok(/^url\("data:image\/svg\+xml,.*b2b2bb/.test(css.backgroundImage) && ! /,\s*url\(/.test(css.backgroundImage), node.id + ' ' + css.backgroundImage);
        assert.ok(['transparent', 'rgba(0, 0, 0, 0)'].includes(css.backgroundColor), node.id);
        assert.equal(css.textShadow, 'none', node.id);
        assert.equal(css.appearance, 'none', node.id);
      }
      console.log('PASS: jsdom + WordPress forms CSS — six select states, one VE chevron (not a browser rendering test)');
    } finally { dom.window.close(); }
    return;
  }
  const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
  try {
    const page = await browser.newPage({ viewport: { width: 850, height: 700 } });
    await page.route('**/*', route => route.abort());
    await page.setContent(`<!doctype html><html><body class="wp-core-ui">
      <div class="cve-w-toolbar"><strong>Visual Edit Lite</strong><select aria-label="Device"><option>Desktop</option></select></div>
      <div class="cve-w-popup" style="left:28px;top:90px;right:auto">
        <div class="cve-w-head"><span>⠿</span><strong>Paragraph</strong><button>×</button></div>
        <section class="cve-w-section"><h3>Typography</h3>
          <label class="cve-w-field"><span>Font</span><select id="font"><option>Inherit</option></select></label>
          <label class="cve-w-field"><span>Weight</span><select id="weight" disabled><option>Inherit</option></select></label>
          <div class="cve-w-field cve-w-number"><span>Size</span><input value="calc(1rem + 1vw)"><select id="unit" disabled><option>px</option></select><button class="cve-w-step" disabled>+</button></div>
          <fieldset disabled><label class="cve-w-field"><span>Style</span><select id="inherited"><option>Inherit</option></select></label></fieldset>
        </section>
        <button class="cve-w-primary">Apply</button>
      </div>
      <div class="cve-w-dialog" style="position:absolute;left:420px;top:90px;padding:16px;min-width:300px">
        <h3>History</h3><label class="cve-w-field"><span>Document</span><select id="history" disabled><option>Index</option></select></label>
      </div>
    </body></html>`);
    await page.addStyleTag({ content: fs.readFileSync(path.join(wordpress, 'wp-admin/css/forms.css'), 'utf8') });
    // Reproduce the original cascade before applying the actual fix.
    await page.addStyleTag({ content: '.cve-w-field input, .cve-w-field select { background: transparent; color: #f5f5f7; } .cve-w-field select { appearance: auto; }' });
    const before = await page.locator('#weight').evaluate(node => ({ image: getComputedStyle(node).backgroundImage, repeat: getComputedStyle(node).backgroundRepeat }));
    assert.match(before.image, /svg/, 'The WordPress disabled arrow wins over the old VE reset');
    assert.equal(before.repeat, 'repeat', 'The old reset tiles that SVG across the field');
    await page.addStyleTag({ content: fs.readFileSync(path.join(__dirname, '../assets/workspace.css'), 'utf8') });
    for (const selector of ['#font', '#weight', '#unit', '#inherited', '#history', '.cve-w-toolbar select']) {
      const style = await page.locator(selector).evaluate(node => {
        const css = getComputedStyle(node);
        return { image: css.backgroundImage, repeat: css.backgroundRepeat, background: css.backgroundColor, shadow: css.textShadow, appearance: css.appearance };
      });
      assert.ok(/^url\("data:image\/svg\+xml,.*b2b2bb/.test(style.image) && ! /,\s*url\(/.test(style.image), selector + ' shows only the VE chevron, no competing WordPress SVG');
      assert.equal(style.repeat, 'no-repeat');
      assert.equal(style.background, 'rgba(0, 0, 0, 0)', selector + ' keeps its dark container background');
      assert.equal(style.shadow, 'none');
      assert.equal(style.appearance, 'none', 'No native arrow next to the VE chevron');
    }
    if (screenshot) await page.screenshot({ path: screenshot });
    console.log('PASS: actual Chromium + WordPress forms CSS — enabled, disabled, inherited-disabled, units, toolbar and History');
  } finally {
    await browser.close();
  }
}
run().catch(error => { console.error(error); process.exitCode = 1; });
