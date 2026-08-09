# The editor

The screen you spend your time on: how it is laid out, what each control does,
and what happens when you save.

Open it from **Visual Edit** in the admin menu, from the **Visual Edit** link
in the admin bar while viewing the site, or from the **Visual Editor** column
on the Pages list.

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
and clicking selects instead of following links.

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
