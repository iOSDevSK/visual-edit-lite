# Editing content

Text, links, images and video — what you can change and how.

All of this happens with edit mode on. See [The editor](the-editor.md).

## Text

Click any text and a caret appears where you clicked. Type.

- **Enter** commits
- **Shift + Enter** inserts a line break
- **Escape** cancels and puts the original text back

The panel also has a plain text field for the same content, which is easier
when the text on the page is tiny or awkward to click into.

### Text with formatting inside it

A heading like `The strategist behind your next stage of <em>growth</em>`
stays fully editable and keeps its formatting. The editor recognises inline
formatting — `em`, `strong`, `b`, `i`, `span`, `small`, `mark`, `sup`, `sub`,
links, line breaks — and preserves it through the round trip.

Such a block is edited on the page only, not through the panel's text field,
because a plain text field would flatten the markup.

## Links

Selecting a link gives you:

- **URL**
- **Open in new tab** — sets `target="_blank"` and the matching `rel`
  attributes for you

The link's visible text is edited on the page like any other text.

### Links whose address is generated

Inside the article template, some links point at whatever post is being shown
— the category link, "previous post", a share button. Those have no URL field,
and the panel says why: typing an address there would freeze every article to
one destination. Everything else about the link — its text, type, colour,
spacing — stays editable.

## Images

Selecting an image gives you a thumbnail preview and:

- **Choose image from Media Library** — the standard WordPress picker
- **Alt text** — the description used by screen readers and search engines
- **Replace with video from Media Library** — see below
- **Import image into this site** — offered when the picture is hosted
  elsewhere; copies it into your Media Library and repoints the markup

### Alt text

Worth filling in. It is what a screen reader announces and what search engines
read. The readiness report flags images with no alt attribute at all — but
note it does **not** flag `alt=""`, because an empty alt is the correct,
deliberate choice for a purely decorative image.

## Video

Selecting a video gives you a working preview plus:

- **Replace with image from Media Library** — the reverse conversion
- **Play automatically on scroll** — see below
- **Sources** — one row per video file
- **Poster** — the still frame shown before playback

### Sources

A video can carry several files: different formats for different browsers, or
different crops for different screen sizes. Each is listed separately and
replaced independently, keeping its own type and media condition. Replacing
the mobile version does not touch the desktop one.

### Play automatically on scroll

Turns the video into a decorative clip that plays once when it scrolls into
view: muted, inline, no controls. Turning it off restores an ordinary video
with playback controls.

## Converting between image and video

**Image → video** keeps the current image as the poster frame, so the block
looks unchanged until the video plays.

**Video → image** measures the space the video occupied and holds the new
image to the same size. Without that, layout CSS written for a `<video>`
element would not apply to an `<img>` and the block would collapse.

Both directions are reversible and both are versioned.

## Deleting an element

The 🗑 button in the panel footer removes the selected element on save. Like
everything else it is recoverable from [History](history.md).

## Resetting

The ↺ button in the panel footer reverts that element to how it was at the
start of the session — inline styles, decorative marks, and for images and
video the file itself. It only affects the element you have selected, and only
changes you have not saved yet.

## What you cannot edit here

- **Blog listings, connected forms, WordPress menus** — generated from live
  data. The panel explains and links you to the screen that owns them.
- **The article template's fields** — title, date, body come from each post.
  Edit those in the normal WordPress post editor; edit their *appearance*
  here.

## Related

- [Styling](styling.md) — how it looks rather than what it says
- [Repeating items](repeating-items.md) — sets of cards or list items
- [Dynamic tokens](dynamic-tokens.md) — the generated regions
