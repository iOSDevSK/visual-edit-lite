# Styling

Changing how something looks rather than what it says.

## Typography

Selecting text or a link gives you a **TYPOGRAPHY** section:

| Control | Notes |
|---|---|
| Font | The theme's own fonts, plus any Google fonts you have added |
| Size | In pixels |
| Weight | 300, 400, 500, 600, 700 |
| Colour | Picker plus a hex field |
| Align | Left, centre, right, justify |
| Line | Line height |
| Tracking | Letter spacing |

### Adding Google fonts

**＋ Add Google fonts** opens a searchable list of the whole Google Fonts
catalogue. No API key needed.

You can keep **up to five** font families. That is a page-weight budget, not
an arbitrary number — every kept family is loaded on every page of the site
for visitors, and five is already generous for one design. Weights 300–700 are
loaded for each.

Fonts you keep appear in the Font dropdown for every element from then on.

## Colour, size and spacing

Selecting a container — a section, a wrapper, a card — gives you:

**BACKGROUND** — colour, opacity, corner radius
**SIZE** — width, height
**PADDING** — space inside the box, per side, in 4 px steps
**MARGIN** — space outside the box, per side, in 4 px steps

**LAYOUT** appears only when the element actually uses flexbox or grid, and
offers direction, justify, align and gap. It is hidden otherwise because those
controls would do nothing on a block that does not lay out its children.

## Decorative marks that are not in the markup

Designs often draw things with CSS rather than HTML — a quotation mark before
a testimonial, an arrow after a link, a rule under a heading. These are
`::before` and `::after` pseudo-elements. They are not elements, so they
cannot be clicked.

When the element you selected has one, the panel shows an **ORNAMENT** section
with:

- the **symbol** itself, in a field rendered using the ornament's real font so
  you can see what you are typing
- a quick row of typographic glyphs that are awkward to type: `“ ” ‘ ’ « » —`
- **colour** and **size**
- **Convert to editable text**

### Convert to editable text

Turns the CSS-drawn mark into a real element in the page and switches the
original off. After that it behaves like any other text — clickable,
selectable, movable, deletable.

Use it when the mark needs to become content rather than decoration. Leave it
as an ornament when it is purely visual, since an ornament costs nothing in
the markup.

## How styling is stored

Ordinary style changes are written as inline styles on the element, in the
page's own markup. That keeps everything in one place and means an exported
theme carries your changes with it.

Ornament styling cannot work that way — a pseudo-element has no tag to put an
attribute on. Those are stored separately and emitted as a small stylesheet
scoped to the page. **The saved markup stays byte-identical**, which is the
whole point: your design keeps the shape it was delivered in.

## Article text: styling every post at once

Blog posts share one template, so the words come from each post but the
*styling* is shared. Editing type inside a real post would only change that
post.

The article template solves this with a specimen: one sample of each element
— a paragraph, an h2, an h3, a blockquote, a list, a link, bold, italic —
sitting in the template. Style the samples, and every article on the site
follows.

The specimen is visible only while editing. Visitors never see it.

If you delete the specimen, the save is refused with an explanation, because
losing it removes the only way to style article body text.

## Related

- [Editing content](editing-content.md) — the words and images themselves
- [The editor](the-editor.md) — device widths for checking responsive styling
