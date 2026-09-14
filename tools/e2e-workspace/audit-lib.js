window.cveAudit = function (label) {
  const parse = c => { const m = c.match(/[\d.]+/g); if (!m) return null; return { r: +m[0], g: +m[1], b: +m[2], a: m[3] === undefined ? 1 : +m[3] }; };
  const lum = c => { const f = v => { v /= 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c.r) + 0.7152 * f(c.g) + 0.0722 * f(c.b); };
  const bgOf = el => { const layers = []; for (let e = el; e && e.nodeType === 1; e = e.parentElement) { const c = parse(getComputedStyle(e).backgroundColor); if (c && c.a > 0) { layers.push(c); if (c.a >= 1) break; } } let base = { r: 255, g: 255, b: 255 }; for (let i = layers.length - 1; i >= 0; i--) { const l = layers[i]; base = { r: l.r * l.a + base.r * (1 - l.a), g: l.g * l.a + base.g * (1 - l.a), b: l.b * l.a + base.b * (1 - l.a) }; } return base; };
  const bad = [], scroll = [];
  document.querySelectorAll('body *').forEach(el => {
    if (el.closest('.block-editor-block-preview__container, iframe')) return;
    const r = el.getBoundingClientRect(); if (!r.width || !r.height || r.bottom < 0 || r.top > innerHeight) return;
    const cs = getComputedStyle(el); if (cs.visibility === 'hidden' || cs.display === 'none') return;
    if ((cs.overflowX === 'auto' || cs.overflowX === 'scroll') && el.scrollWidth > el.clientWidth + 1) scroll.push({ label, cls: String(el.className).slice(0, 60), sw: el.scrollWidth, cw: el.clientWidth });
    if (el.tagName === 'svg' && el.closest('.cve-w-popup, .cve-dark-native, .cve-w-toolbar, .cve-w-dialog, .cve-w-dock')) {
      const f = parse(cs.fill); const bgI = bgOf(el);
      if (f && f.a > 0.2 && cs.fill !== 'none' && !el.closest('[style*="background-color"], [style*="background:"], .component-color-indicator, .block-editor-block-preview__container')) {
        const Li1 = lum(f), Li2 = lum(bgI); const ri = (Math.max(Li1, Li2) + 0.05) / (Math.min(Li1, Li2) + 0.05);
        if (ri < 2) bad.push({ label, ratio: +ri.toFixed(2), tag: 'SVG', cls: String(el.parentElement && el.parentElement.className).slice(0, 70), text: '(icon)', fg: cs.fill, bg: `rgb(${bgI.r | 0},${bgI.g | 0},${bgI.b | 0})` });
      }
    }
    const own = [...el.childNodes].some(n => n.nodeType === 3 && n.textContent.trim()) || /^(INPUT|SELECT|TEXTAREA)$/.test(el.tagName);
    if (!own) return;
    let o = 1; for (let e = el; e; e = e.parentElement) o *= +getComputedStyle(e).opacity; if (o < 0.2) return;
    if (el.disabled || el.closest('[disabled],[aria-disabled=true]')) return;
    const fg = parse(cs.webkitTextFillColor || cs.color) || parse(cs.color); const bg = bgOf(el);
    const fgm = { r: fg.r * fg.a + bg.r * (1 - fg.a), g: fg.g * fg.a + bg.g * (1 - fg.a), b: fg.b * fg.a + bg.b * (1 - fg.a) };
    const L1 = lum(fgm), L2 = lum(bg); const ratio = (Math.max(L1, L2) + 0.05) / (Math.min(L1, L2) + 0.05);
    if (ratio < 3) bad.push({ label, ratio: +ratio.toFixed(2), tag: el.tagName, cls: String(el.className).slice(0, 70), text: (el.value || el.textContent || '').trim().slice(0, 30), fg: cs.color, bg: `rgb(${bg.r | 0},${bg.g | 0},${bg.b | 0})` });
  });
  // Colour swatches paint their value through `color` (an inset box-shadow in currentColor).
  document.querySelectorAll('.components-circular-option-picker__option[style*="background-color"]').forEach(el => {
    const cs = getComputedStyle(el); const want = el.style.backgroundColor;
    if (want && cs.color !== want) bad.push({ label, ratio: 0, tag: 'SWATCH', cls: 'circular-option-picker__option', text: '(swatch)', fg: cs.color, bg: want });
  });
  // Block icons in the inserter: every painted shape, not only the svg element.
  document.querySelectorAll('.block-editor-block-types-list__item .block-editor-block-icon:not([style*="color"]) svg :is(path, rect, circle, polygon)').forEach(el => {
    const r = el.getBoundingClientRect(); if (!r.width || r.bottom < 0 || r.top > innerHeight) return;
    const f = parse(getComputedStyle(el).fill); if (!f || getComputedStyle(el).fill === 'none') return;
    const tile = bgOf(el.closest('.block-editor-block-types-list__item')); const ratio = (Math.max(lum(f), lum(tile)) + 0.05) / (Math.min(lum(f), lum(tile)) + 0.05);
    if (ratio < 3) bad.push({ label, ratio: +ratio.toFixed(2), tag: 'BLOCK-ICON', cls: String(el.closest('.block-editor-block-types-list__item').className).slice(0, 70), text: el.closest('.block-editor-block-types-list__item').textContent.trim().slice(0, 30), fg: getComputedStyle(el).fill, bg: `rgb(${tile.r | 0},${tile.g | 0},${tile.b | 0})` });
  });
  return { bad, scroll };
};
