# WordPress.org directory assets

These images are **not** part of the plugin ZIP. They live in the top level of
the plugin's Subversion repository, beside `trunk/` and `tags/`:

```
svn-checkout/
  assets/          <- here
  trunk/
  tags/
```

Putting them in `trunk/assets/` does nothing. They are served through a CDN and
cached hard, so a change can take minutes — occasionally hours — to appear.

## What to produce

| File | Size | Where it shows |
|---|---|---|
| `icon-128x128.png` | 128 × 128 | **Search results** and the plugin card |
| `icon-256x256.png` | 256 × 256 | The same, on retina displays |
| `icon.svg` | vector | Preferred; **still requires a PNG fallback** |
| `banner-772x250.png` | 772 × 250 | Top of the plugin's directory page |
| `banner-1544x500.png` | 1544 × 500 | The same, retina — only works alongside the 772 wide one |
| `screenshot-1.png` … | any | Below the description, captioned from `readme.txt` |

Sizes are fixed. Renaming a differently sized image to one of these filenames
makes it render badly rather than making it fit. Icons cap at 1 MB, banners at
4 MB; smaller is better.

**The icon is the one that earns its keep.** It appears in search results, so
it decides whether someone clicks — and clicks become installs, which is the
signal the directory's ranking actually rewards. Without one, WordPress
generates a flat coloured circle with an initial in it.

Banners are read right-to-left in the Hebrew and Arabic directories. Either
design one that survives mirroring, or ship `banner-772x250-rtl.png`.

## Screenshots

**One line in `readme.txt` per screenshot**, in order, under a
`== Screenshots ==` section — the line becomes the caption:

```
== Screenshots ==

1. Click any element on the real page to edit it.
2. Every save is a restore point; the Original is always at the bottom.
3. A designed HTML form, connected by clicking.
```

Do not add that section until the images exist, or the page shows captions
with nothing under them.

Suggested set, in the order that explains the product fastest:

1. The editor with an element selected and its panel open — this is the whole
   pitch in one image.
2. The History panel, showing the ten saves and the Original.
3. The form zone being connected.
4. Search appearance for a single page.
5. SEO & AI Readiness, the read-only report.

Take them at a 2× device pixel ratio on a light background, crop to the
browser viewport with no desktop furniture, and keep the same site and content
across all five so they read as one story.
