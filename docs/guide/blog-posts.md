# Blog posts

How a converted design shows real WordPress posts, and how to write them.

## The idea

Your design has a blog listing — cards in a grid, each with an image, a title,
an excerpt. In the original static site those cards were hand-written HTML.

After conversion, **one** of those cards remains in the page, wrapped in a
token, and WordPress repeats it once per published post. You write posts in
the normal WordPress editor; the listing updates itself.

The card that repeats is your design's own card. It is not replaced by a
generic one.

## Writing a post

**Posts → Add New**, exactly as in any WordPress site. Title, content,
excerpt, category, featured image.

A few things the design depends on:

- **Featured image** — this is what shows on the card. Without one the card
  renders with a blank space where the image goes.
- **Excerpt** — used as the card's summary, trimmed to about 30 words. If you
  leave it empty WordPress generates one from the start of the post.
- **Category** — shown on the card if your design includes it.

New posts appear on the listing immediately. Nothing needs rebuilding.

## The article template

Every post is displayed through one shared template — the design of a single
article, with placeholders where each post's own content goes.

Edit it from the page dropdown in the editor: **Article template**. It
previews using your newest post.

What you are editing there is the **layout**, not any one post's words:
spacing, typography, where the date sits, what the share row looks like, the
previous/next navigation. Change it once and every article on the site
follows.

Clicking the title or body inside the template selects the **box** around
them rather than the text, and the panel explains why — the words come from
each post, so there is nothing to type there, while the box has everything
worth changing.

### Styling article text

The template carries a specimen: one sample of each element a post body might
contain — paragraph, headings, blockquote, lists, links, bold, italic. Style
those samples and every article follows.

The specimen only exists while editing; visitors never see it. See
[Styling](styling.md).

## Load more

If the design has a "load more" button, it fetches the next page of posts and
appends them, without leaving the page.

The new cards are built from **the same card in your page's markup** — so a
card you restyle is restyled on page two automatically, with nothing to keep
in sync.

The button hides itself when there is nothing left to load. In the editor it
stays visible and does nothing, because paging is not loaded there — the panel
says so rather than leaving you with a button that appears broken.

## One thing not to do

**Do not set your listing page under Settings → Reading → "Posts page".**

WordPress ignores the content of whatever page is assigned there and renders
its own blog archive instead — which would throw away your design entirely.

Your listing is an ordinary page that happens to list posts. It needs no
Reading setting at all.

## Changing what the listing shows

The listing is driven by a token you can edit in the page's markup:

```
[wp-posts count="9" category="news" orderby="date" order="desc"]
  …your card…
[/wp-posts]
```

| Attribute | Default | |
|---|---|---|
| `count` | 3 | How many per page |
| `category` | all | Category slug |
| `orderby` | `date` | |
| `order` | `desc` | Newest first |
| `offset` | 0 | Skip N — useful for "one featured post, then the rest" |
| `image_size` | `large` | WordPress image size for the card image |

See [Dynamic tokens](dynamic-tokens.md) for the full reference, including the
placeholders available inside the card.

## Related

- [Dynamic tokens](dynamic-tokens.md) — the full token reference
- [Styling](styling.md) — article typography
- [Repeating items](repeating-items.md) — for card sets that are *not* posts
