module.exports = async ctx => {
  const { frame, wait } = ctx; const L = require('./lib.cjs')(ctx); const out = {}; const canvas = L.canvas(); const path = require('path');
  const pv = canvas.locator('.cve-shortcode-preview').first(); await pv.waitFor({ timeout: 20000 }).catch(e => out.err = e.message);
  out.inputs = await canvas.locator('.cve-shortcode-preview input, .cve-shortcode-preview textarea, .cve-shortcode-preview select').count();
  await pv.scrollIntoViewIfNeeded(); await wait(600);
  await canvas.locator('.cve-shortcode-previewing').first().screenshot({ path: path.join(process.env.OUT, 'contact-canvas.png') });
  out.dirty = await frame.evaluate(() => wp.data.select('core').__experimentalGetDirtyEntityRecords().length);
  return out;
};
