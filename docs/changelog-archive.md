# Changelog archive

Releases of Visual Edit Lite before 1.26.0. Newer entries are in
[readme.txt](../readme.txt).

= 1.25.12 =
This edition is derived from Visual Edit Pro 1.25.12.
* Maintenance release, keeping the version in step with the edition it is
  derived from. The three Pro releases since 1.25.9 corrected that edition's
  licence check and its bundled update channel — machinery Lite does not
  contain — so nothing in the editor changes here.

= 1.25.9 =
This edition is derived from Visual Edit Pro 1.25.9.
* Changed: a save refused because an unsaved change points at part of the page
  that is no longer there now says so, and says that Discard clears it.

= 1.25.8 =
This edition is derived from Visual Edit Pro 1.25.8.
* Fixed: a duplicated element came back as scenery — no frame, no click —
  until the page was saved. Duplicating now writes the source first and
  re-renders the canvas from it, so the copy is editable straight away.

= 1.25.7 =
This edition is derived from Visual Edit Pro 1.25.7.
* New: duplicate — any element you can select can be copied, from the icon
  beside delete in the panel's footer. The copy lands after the original and
  keeps everything about it except its id.

= 1.25.6 =
This edition is derived from Visual Edit Pro 1.25.6.
* Fixed: three controls were folded into sections they have nothing to do with
  — Text inside RADIUS, and the FAQ and item editors inside ORNAMENT (AFTER).
  TEXT, QUESTIONS and ITEMS are their own sections now, above the styling.

= 1.25.5 =
This edition is derived from Visual Edit Pro 1.25.5.
* Fixed: a button whose label sits beside an arrow or an icon had no editable
  text at all — no caret and no panel field, because both routes refused an
  element with children. The words are now written into the element's own text
  nodes, so the arrow stays exactly where it was.

= 1.25.4 =
This edition is derived from Visual Edit Pro 1.25.4.
* New: each corner rounds on its own. Radius was one control for all four and
  read the browser's shorthand, so on an element whose corners already differed
  the first nudge squared off the three you were not looking at. Four controls
  now, in a RADIUS section next to BORDER, with a row that still sets all four
  at once.
* Fixed: a radius written as a percentage — how a round avatar is made — was
  read as a number and written back in pixels. The unit a value carries is the
  unit it keeps.
* New: a border can be made transparent, by typing it or by picking Transparent
  from the palette list. Not the same as turning the line off: no line takes up
  no space, and the layout moves.
* Fixed: the panel showed black for a border that was already transparent.
* New: gradients are chosen, not typed — a GRADIENT section with a live
  preview, ready-made gradients built from your theme's own palette, and two
  colours plus a direction to make your own. Custom stays for anything more
  elaborate. Before, a gradient shared a row with the size controls: a 54-pixel
  box suggesting "e.g. 24px", and usually nothing to pick instead.
* New: a gradient background on a raw-HTML theme too — the same GRADIENT
  section, with ready-made gradients from the theme's own palette. It writes
  background-image, so the flat colour underneath survives.
* Fixed: the BORDER Style row read [object Object] for every choice, and the
  only selectable entry was the value already set — so it could not change
  anything.
* Fixed: the editor panel's controls were unstyled in this edition. Deriving
  Lite from Pro cut the AI panel out of the stylesheet and took the panel's own
  rules with it — the number boxes, colour swatches, grids and steppers had no
  styling at all. Only the styling was affected; every control worked.

= 1.25.3 =
This edition is derived from Visual Edit Pro 1.25.3.
* Fixed: after switching themes, Appearance → Menus opened on the menu of the
  theme you had just left, listing its items. The list of menus was right — the
  screen was simply already sitting on the wrong one, because it reopens
  whatever you last edited and that pointer is a plain menu id a theme switch
  does not touch. It now lands on a menu belonging to the theme you are
  actually using, and sites where this has already happened correct themselves
  the first time the screen is opened.

= 1.25.2 =
This edition is derived from Visual Edit Pro 1.25.2.
* New: the style panel folds — each heading opens and closes, and remembers
  which you left open.
* New: padding, margin and border on every element, not only on wrappers.
* Fixed: panel section headings were unstyled here while Pro styled them.
* New: the greyed-out words inside a form field can be changed — click the
  field and the panel offers what it shows before anyone types.
* New: a form's own words are editable again — its labels, its button and any
  small print under it. Those are the design's own wording; only the parts
  WordPress owns stay sealed.
* Fixed: clicking a box that contains a form, a post list or a menu said the
  words in it "come from the post" and "change with every article". Only one of
  those four cases is a post — on a contact form it was simply untrue, and it
  sent people looking in Posts for text that was never there. Each now says
  what it is actually holding.

= 1.25.1 =
This edition is derived from Visual Edit Pro 1.25.1, and brings across
everything Pro added since the last Lite release — none of it licence-gated,
so all of it ships here:
* Block mode: on a block theme, whole sections can be added, copied, moved and
  removed, and the block supports panel is available.
* Movement: scroll and hover animation stored as a class, costing nothing on
  pages that do not use it.
* Different values on smaller screens — padding and the rest can now differ per
  breakpoint, and padding can be dragged on the section itself.
* Copy a page, or remove one, without leaving the editor.
* Typeface handling reworked: a font of your own is kept, a chosen Google font
  actually loads, and added typefaces reach the WordPress editor canvas too.
* Search appearance now works on a block theme.
* Fixes to the picture panel — a new picture appears without saving first, and
  three controls that had never been driven now work.

= 1.19.8 =
* Staggered and carousel lists are collections again. A reveal library's
  per-card delay (`data-aos-delay`/`duration`) and a carousel's frozen runtime
  state (`swiper-slide-active/prev/next/duplicate`,
  `data-swiper-slide-index`) no longer disqualify sibling cards from the
  "manage as a list" panel — those are animation timing and captured state,
  not design differences.
* Listing cards can carry a byline: new `{author}` and `{author_image}`
  placeholders for `[wp-posts]` templates, matching
  `[wp-article field="author"]`.
* Menu labels land on the item's name, never its description. A two-line
  dropdown item (name plus a descriptive sentence) used to get its description
  overwritten by the label on every page; the label now targets the run that
  carries the name.
* Imported blog posts keep their byline. A content bundle may name each
  article's author; the import resolves it to an existing user by display name
  or creates one (role: author) instead of crediting whoever clicked Import.
* Derived from Visual Edit Pro 1.19.8. There is no Lite 1.19.7 — Lite carries
  the version number of the Pro release it was derived from, so the two stay
  comparable at a glance.

= 1.19.6 =
* First public release. Visual Edit Lite is derived from Visual Edit Pro
  1.19.6 and shares its version number so the two stay comparable at a
  glance. Everything the licence gated in Pro — the AI assistant, AI image
  and video tools, Cloudflare Turnstile, theme export — is absent from this
  edition rather than hidden, and there is no licence check, no activation
  call and no bundled updater anywhere in the code.
