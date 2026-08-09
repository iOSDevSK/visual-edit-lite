# Repeating items

Managing a set of cards or list items — services, team members, portfolio
tiles, rooms, FAQs — as a list, instead of hand-editing each one.

## The problem this solves

Editing the text of one card is easy: click it, type. But *managing* a set is
a different job. Adding a card means duplicating markup. Removing one means
deleting the right block and nothing else. Reordering means moving things
around by hand. None of that is clicking on text.

Visual Edit recognises a repeating set and gives you one window where you can
edit, reorder, add and remove — all of it saved together as one change.

## Using it

Click any element inside one of the cards. If the panel shows an **ITEMS**
section — or **QUESTIONS**, for an FAQ — the set was recognised.

Click **Edit items (3)** and a window opens with one row per item.

Each row has:

- **↑ ↓** to move it
- **🗑** to remove it
- one field per piece of content the items differ in

Plus **+ Add item** at the bottom, and **Save items**.

### The orange outline

While the window is open, every element the editor considers part of this set
is outlined in **orange dashed** on the page — deliberately a different colour
from the blue selection outline, so it reads as *"this is the group"* rather
than *"this is selected"*.

**Check it before saving.** If the outline covers something you did not mean —
too much, too little, the wrong section — close the window and nothing
happens. This is the one moment where a wrong match is visible and free to
walk away from.

### Adding an item

A new item is a copy of an existing one with your content in it. The editor
never writes fresh markup — it clones a card that is already on the page, so
the new one carries exactly the same classes and structure as its neighbours
and cannot look subtly wrong.

Adding a genuinely new item reloads the canvas once after saving, so the
page's own JavaScript sees the new element. Editing, reordering and removing
do not reload.

## What gets recognised

The editor looks at **shape, never at class names**. A converted site's cards
carry whatever classes its designer chose, so matching on the word "card"
would work on one site and nowhere else.

For a set to be offered, all of this must hold:

**At least two of them.** One card is a card; two or more of the same shape
side by side is a list. Offering to "manage" a single element would be
offering to make a mess.

**Identical structure.** Same tag, same classes, same arrangement of children.
Not "similar" — identical. There is no similarity score and no threshold to
tune, because a threshold is exactly where wrong matches would come from.

**Side by side, with nothing in between.** The matched items must sit in one
unbroken run. This rule exists because of a real page: four `<h2>` headings
spread across a legal document, each followed by its own paragraph, matched as
a "collection" without it. Reordering "items" there would have scrambled
unrelated sections of the page.

**A card that is different is left out.** If one card carries an extra class —
a "featured" variant, say — it is not part of the set. That is protective:
including it would let a reorder quietly strip the thing that made it
different.

**At most eight editable fields.** More than that means the editor has matched
loosely-similar page sections rather than a set of cards, so it declines.

## What you can edit per item

The editor works out which parts of the cards actually differ, and offers a
field for each:

| Kind | You get |
|---|---|
| Short text | A single-line field |
| Longer text | A text area |
| Image | A thumbnail and a Media Library picker |
| Link | Two fields: the link text and its address |

Parts that are the same in every card are not offered — there is nothing to
choose between. Parts that differ *structurally* between cards (one has a list
of five things, another has three) are left alone entirely: they are not
offered as fields, and they survive reordering untouched, because each item
keeps its own markup.

## FAQs are a special case

A set of questions and answers gets its own **QUESTIONS** window, which works
the same way but knows what a question is.

This matters beyond convenience: questions and answers are published as
structured data, which is what lets Google show them directly and lets an AI
assistant quote them. Keeping the FAQ in a recognised shape is what keeps that
working. See [AI readiness](../seo-and-ai/ai-readiness.md).

Two things to know:

**Below two questions it stops being published as an FAQ.** The window warns
you when you go under. It is still your page to arrange, but a single
question-shaped heading is usually a call to action ("Ready to start?") rather
than a real FAQ, and publishing one invented entry is worse than publishing
none.

**Remove the whole item, not just the question.** The 🗑 button does this for
you. Deleting only the question text by hand would leave an orphaned answer.

## Related

- [Editing content](editing-content.md) — editing one card's text directly
- [Blog posts](blog-posts.md) — for lists that come from real WordPress posts
- [AI readiness](../seo-and-ai/ai-readiness.md) — why FAQ shape matters
