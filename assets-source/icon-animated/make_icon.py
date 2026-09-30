#!/usr/bin/env python3
"""Animated Visual Edit Lite icon for WordPress.org (icon-256x256.gif, icon-128x128.gif).

The shapes are traced from assets/icon-256x256.png (256 px coordinate space).
Usage:
  make_icon.py static OUT.svg        # the finished icon, for comparing with the PNG
  make_icon.py frames DIR            # one SVG per frame at FPS
"""
import math
import os
import sys

FPS = 30
DURATION = 3.0  # seconds, one loop

BG_A = (0, 152, 251)    # top-left of the gradient
BG_B = (57, 39, 249)    # bottom-right
IRIS = "#1457FB"
GREEN = "#2AE4A0"
DASH = "rgba(255,255,255,0.68)"

CORNERS = [(44, 46), (192, 46), (192, 181), (44, 181)]  # 20 x 20 squares, top-left points, clockwise
DASHES = [  # (x1, y1, x2, y2), drawn clockwise from the top-left corner
    (76, 55, 89, 55), (106, 55, 118, 55), (136, 55, 148, 55), (166, 55, 178, 55),
    (202.5, 78, 202.5, 89), (202.5, 105, 202.5, 116), (202.5, 133, 202.5, 144), (202.5, 159, 202.5, 169),
    (178, 190.5, 166, 190.5), (148, 190.5, 136, 190.5), (118, 190.5, 106, 190.5), (89, 190.5, 76, 190.5),
    (52.5, 169, 52.5, 159), (52.5, 144, 52.5, 133), (52.5, 116, 52.5, 105), (52.5, 89, 52.5, 78),
]
CURSOR = [(148.0, 141.0), (199.4, 172.5), (182.7, 180.9), (197.3, 206.1), (188.9, 212.4), (173.2, 189.3), (157.4, 198.8)]
CLICKS = [(213, 145, 221, 129.5), (219.5, 156, 235, 145.5)]
EYE_C = (127, 125)


def clamp(v, a=0.0, b=1.0):
    return max(a, min(b, v))


def ease_out(t):
    return 1 - (1 - t) ** 3


def ease_back(t, s=1.7):
    t -= 1
    return t * t * ((s + 1) * t + s) + 1


def phase(t, start, dur):
    return clamp((t - start) / dur)


def svg(t=None):
    """t=None is the finished icon; otherwise seconds into the loop."""
    done = t is None
    parts = []
    parts.append(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="512" height="512">'
        '<defs><linearGradient id="bg" gradientUnits="userSpaceOnUse" x1="8" y1="8" x2="248" y2="248">'
        f'<stop offset="0" stop-color="rgb{BG_A}"/><stop offset="1" stop-color="rgb{BG_B}"/>'
        '</linearGradient></defs>'
        '<rect x="7.5" y="8" width="241" height="240" rx="48" fill="url(#bg)"/>'
    )

    # 1. Corner handles pop in (0.00 - 0.45 s, staggered)
    for i, (x, y) in enumerate(CORNERS):
        k = 1 if done else ease_back(phase(t, 0.05 + i * 0.08, 0.25))
        if k <= 0:
            continue
        cx, cy = x + 10, y + 10
        parts.append(
            f'<rect x="{x}" y="{y}" width="20" height="20" rx="4.5" fill="#fff" '
            f'transform="translate({cx} {cy}) scale({k:.4f}) translate({-cx} {-cy})"/>'
        )

    # 2. Dashed frame draws itself clockwise (0.30 - 0.95 s)
    for i, (x1, y1, x2, y2) in enumerate(DASHES):
        k = 1 if done else ease_out(phase(t, 0.30 + i * 0.034, 0.13))
        if k <= 0:
            continue
        ex, ey = x1 + (x2 - x1) * k, y1 + (y2 - y1) * k
        parts.append(
            f'<line x1="{x1}" y1="{y1}" x2="{ex:.2f}" y2="{ey:.2f}" stroke="{DASH}" '
            'stroke-width="6" stroke-linecap="round"/>'
        )

    # 3. Eye opens (0.75 - 1.15 s); a blink at 2.05 s
    open_k = 1 if done else ease_back(phase(t, 0.75, 0.4), 1.2)
    if not done and 2.05 <= t < 2.35:
        b = (t - 2.05) / 0.3
        open_k *= 1 - math.sin(b * math.pi) * 0.92
    if open_k > 0:
        cx, cy = EYE_C
        parts.append(f'<g transform="translate({cx} {cy}) scale(1 {open_k:.4f}) translate({-cx} {-cy})">')
        parts.append('<path d="M60 125 C86 70.3 168 70.3 194 125 C168 179.7 86 179.7 60 125 Z" fill="#fff"/>')
        parts.append(f'<circle cx="127.5" cy="125" r="29.5" fill="{IRIS}"/>')
        parts.append('<circle cx="117.5" cy="116" r="8.6" fill="#fff"/>')
        parts.append('</g>')

    # 4. Cursor flies in (1.10 - 1.55 s), clicks (1.60 - 1.80 s)
    k = 1 if done else ease_out(phase(t, 1.10, 0.45))
    if k > 0:
        dx, dy = (1 - k) * 70, (1 - k) * 70
        press = 0 if done else math.sin(phase(t, 1.60, 0.2) * math.pi) * 0.12
        s = 1 - press
        tx, ty = CURSOR[0]
        pts = " ".join(f"{x:.1f},{y:.1f}" for x, y in CURSOR)
        parts.append(
            f'<g transform="translate({dx:.2f} {dy:.2f}) translate({tx} {ty}) scale({s:.4f}) translate({-tx} {-ty})">'
            f'<polygon points="{pts}" fill="{GREEN}" stroke="#fff" stroke-width="14" '
            'stroke-linejoin="round" paint-order="stroke"/></g>'
        )

    # 5. Click marks burst out (1.70 - 1.95 s)
    k = 1 if done else ease_back(phase(t, 1.70, 0.25), 2.2)
    if k > 0:
        for x1, y1, x2, y2 in CLICKS:
            ex, ey = x1 + (x2 - x1) * k, y1 + (y2 - y1) * k
            parts.append(
                f'<line x1="{x1}" y1="{y1}" x2="{ex:.2f}" y2="{ey:.2f}" stroke="{GREEN}" '
                'stroke-width="6.5" stroke-linecap="round"/>'
            )

    parts.append("</svg>")
    return "".join(parts)


def main():
    mode, out = sys.argv[1], sys.argv[2]
    if mode == "static":
        with open(out, "w") as f:
            f.write(svg(None))
        return
    os.makedirs(out, exist_ok=True)
    n = int(round(FPS * DURATION))
    for i in range(n):
        with open(os.path.join(out, f"f{i:03d}.svg"), "w") as f:
            f.write(svg(i / FPS))
    print(n, "frames")


if __name__ == "__main__":
    main()
