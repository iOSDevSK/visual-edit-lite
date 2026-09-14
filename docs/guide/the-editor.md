# The editor

The screen you spend your time on: how it is laid out, what each control does,
and what happens when you save.

Open it from **Visual Edit** in the admin menu, from the **Visual Edit** link
in the admin bar while viewing the site, or from the **Visual Editor** column
on the Pages list.

## Gutenberg block themes

On a block theme those links open the VE workspace. WordPress's own editor
does the work underneath — every block, template, template part, pattern and
Global Styles save through it — but what you see is Visual Edit: the page,
one dark toolbar and one popup, the same way a converted theme is edited.

### The toolbar

| Control | What it does |
|---|---|
| **Page name ▾** | Switch to another page, post, template, template part, navigation menu or synced pattern. **Back to the dashboard** is at the bottom |
| **Desktop ▾** | Preview the page at desktop, tablet or phone width |
| **↶ ↷** | Undo and redo |
| **↺ History** | Saved versions of this document, docked beside the page |
| **＋ Section** | Add one of your theme's own sections after the selected one |
| **Design unlocked** | Shown while pattern design is unlocked; click to lock it again |
| **Saved / N unsaved** | Save status |
| **⋯** | Page settings, site styles, page structure, all blocks, Google Fonts, search appearance, locking, WordPress's own controls, and links to SEO settings and form submissions |
| **Save** | Publish every changed document through WordPress's save flow |

### Selecting things

Editable text and images have a faint dashed outline. Hovering outlines a
block; clicking selects it, labels it — for example *Heading · Hero* — and
opens the popup beside it, at the height you clicked. The popup never covers
the block when there is room anywhere else, and it stays where it is while you
scroll. Drag it by its heading, or pin it with the pin button so the next
popup opens in the same place.

**Forms are green.** A form and everything in it carries a green outline and a
green *Form* label, permanently — not only once it is clicked. A form is the
one thing on a page that does something rather than says something, and its
fields otherwise look exactly like the design's own boxes. Everything else
stays blue.

Text can be typed straight into the page. The path above the popup's title —
*Hero › Columns* — selects a parent; so does **↑** or **Alt+↑**. Clicking the
label reopens a closed popup. On a phone the popup is a sheet along the bottom
of the screen.

### The popup

Only the tabs that mean something for the selected block appear.

- **Content** — the text, with **B**, **I** and link for text selected on the
  page; a button's link; media and alternative text; decorative ornaments.
- **Style** — typography and Google Fonts, colours and gradients, size and
  spacing, border, corners and shadow, and entrance and hover motion. The
  **Desktop / Tablet / Mobile** switch at the top edits the same fields for
  that screen only; a field with a value of its own is marked **●**, and
  empty fields keep the larger screen's value. It also switches the preview.
- **Section** — layout, the block's items (select, reorder, remove, or add a
  copy of the last one), and **Add a section after this one**.
- **Advanced** — WordPress's complete native settings for the block, including
  other plugins' controls. Use Undo for changes made there.

**Duplicate**, **▲ ▼** and the bin act on the block itself. **Apply** keeps
the changes in the page; **Cancel**, **×** and **Esc** undo only what this
popup changed, never newer edits made elsewhere. **Reset styles** removes the
block's own styling. Nothing is published until **Save**.

### Locked design

WordPress locks the design of sections that come from theme patterns and of
template parts: their text and images can change, their look cannot. The
popup says so and offers **Unlock design**. That is WordPress's own "Enable
editing all patterns" setting — nothing is saved by unlocking, it applies to
this editing session, and **Design unlocked** in the toolbar locks it again.
Blocks locked by the theme itself stay locked.

### Adding sections

**＋ Section** and **Add a section after this one** show your theme's sections
with a preview. The new section is inserted after the selected one, or at the
end of the page, and is an ordinary change: Undo removes it.

### Extensions

Other plugins can add popup groups, footer buttons and menu entries, and
change a page through the same operations the popup uses. See the
[editor API](../developer/editor-api.md).

The rest of this guide describes the separate raw-HTML editor used by a
converted theme.

## Layout

A toolbar across the top, your real site in the middle, and panels that slide
in from the right.

The middle is not a preview or an approximation — it is the actual page,
loaded the way a visitor gets it, with the site's own CSS and JavaScript
running. That is why what you see while editing matches what ships.

## The toolbar

| Control | What it does |
|---|---|
| **Page dropdown** | Switch between the pages you can edit |
| **↗ Preview** | Open the real public URL in a new tab |
| **✏️ Edit mode** | Turn editing on and off |
| **Desktop / Tablet / Mobile** | Resize the canvas to 1440 / 820 / 390 px |
| **Status** | "3 unsaved changes" · "Saving…" · "Saved ✓" |
| **🔍 Search appearance** | The SEO panel for this page |
| **↺ History** | Version history for this page |
| **Discard changes** | Throw away everything unsaved |
| **Save** | Write your changes |

Only **one panel is open at a time** — opening History closes Search
appearance, and the other way round. Each takes a fixed 320 px out of the
canvas, and both at once would leave the page too narrow to work with.

## Edit mode

Edit mode starts **off** every time the editor loads. That is deliberate: it
means a fresh load, a page switch, or a reload after saving never drops you
into a state where a stray click changes something.

**Off** — the page behaves like the live site. You can scroll, open menus,
expand accordions, and check that things still work. Clicking a link navigates;
if it leads to another editable page the editor switches to it, keeping the
toolbar and save target in step.

**On** — editable elements get a faint dashed outline, hovering solidifies it,
and clicking selects instead of following links. Forms are outlined and
labelled in green, so it is clear which part of the page is one.

## Device widths

The three device buttons give the canvas a **real pixel width**, not a scaled
picture of one. Media queries re-evaluate, so a mobile layout is genuinely the
mobile layout. The frame is then scaled down visually to fit your screen, but
the page inside believes it is 390 px wide.

This matters when you edit text that wraps differently on mobile, or a grid
that stacks.

## The page dropdown

Lists everything you can edit:

- **Front page**
- Every other page that came through the conversion
- **Header** and **Footer** — the site chrome, shared by every page
- **Article template** — the layout every blog post uses
- **404 page** — what visitors see at an address that does not exist

Header and footer preview on a real subpage, because the front page is
self-contained and does not show shared chrome. The article template previews
on your newest post, and disappears from the list entirely if you have no
posts. The 404 previews at a deliberately nonexistent address.

Editing the header or footer changes it **everywhere**, on every page. That
is the point of them, but it is worth knowing before you edit one.

## Selecting things

Click an element and the panel opens with controls appropriate to what it is —
text, a link, an image, a video, or a container.

Some things are deliberately not directly editable, and say so:

- **Managed regions** — a blog listing, a connected form, a WordPress-driven
  menu. These are generated from live data, so the panel explains where the
  content comes from and links you to the right screen rather than letting you
  type into output that will be regenerated.
- **Article fields** — the title, date and body inside the article template
  come from each individual post. The panel selects the *box* around such a
  field instead, because the box has everything worth changing (spacing, type,
  colour) and the value has nothing.

## Saving

Changes queue up as you work. Nothing is written until you press **Save**, and
the counter tells you how many are waiting.

On save, every queued change is applied to the page's stored markup in one
pass and written once. This is why you can make ten edits and get one clean
version in History rather than ten.

**Discard changes** throws away everything unsaved and reloads the canvas from
what is stored.

If you try to leave with unsaved changes, the browser asks first.

### Two things happen automatically on save

Anything derived from the page updates itself — extracted FAQs, the readiness
report, structured data. There is no "rebuild" step to remember.

Some structural changes need the page's own JavaScript to run again (adding a
brand-new item to a list, connecting a form). Those reload the canvas after
saving. Your scroll position is restored, so you land back where you were
rather than at the top of the page.

## If the canvas looks inert

If the page loads but nothing is clickable in edit mode, the editor could not
find the region it is allowed to edit. That usually means the current page is
not one of the converted pages — for example you followed a link to a
WordPress screen the theme does not own. A banner explains it, with a way back
to what you were editing.

## Related

- [Editing content](editing-content.md) — text, links, images, video
- [Styling](styling.md) — typography, colour, spacing
- [Repeating items](repeating-items.md) — cards and lists
- [History](history.md) — versions and restore
