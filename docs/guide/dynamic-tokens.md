# Dynamic tokens

Four tokens that let a static design display live WordPress content while
keeping the design's own markup.

This is a reference. For everyday use, [Blog posts](blog-posts.md) and
[Forms](forms.md) cover the two you are most likely to touch.

## How they work

A token is **plain text inside the page's markup**:

```
[wp-posts count="6"]
  <article class="card">…</article>
[/wp-posts]
```

WordPress replaces it when serving the page. What is stored stays a token;
what a visitor gets is real content.

The markup between the tags is **your design's own markup**. That is the whole
point — the token repeats or fills in what the designer drew, rather than
generating something generic.

Three rules if you edit them by hand:

- Never wrap a token in extra markup
- Leave tokens you find in place unless you mean to change them
- Keep the opening and closing tags matched

Tokens mentioned inside HTML comments are ignored, so you can safely write
about them in a comment.

---

## `[wp-posts]` — repeat a card per blog post

```
[wp-posts count="9" offset="1" category="news" orderby="date" order="desc" image_size="large"]
  <article class="card">
    <img src="{image}" alt="">
    <h3>{title}</h3>
    <p>{excerpt}</p>
    <a href="{url}">Read more</a>
  </article>
[/wp-posts]
```

| Attribute | Default | Meaning |
|---|---|---|
| `count` | 3 | Posts per page |
| `category` | all | Category slug, or `current` — the category of the post being read, which is what a "related articles" strip on a single post needs. Without it, a bare token returns the newest posts, so every article recommends the same three |
| `orderby` | `date` | |
| `order` | `desc` | |
| `offset` | 0 | Skip the first N posts |
| `image_size` | `large` | WordPress image size |

**Placeholders** available inside: `{title}` `{excerpt}` `{url}` `{image}`
`{date}` `{category}` `{tag}` `{tags}` `{category2}`

A card design that shows two taxonomy chips beside each other ("Product" and
"Management") needs two DIFFERENT values: use `{category}` with `{tag}` (the
post's first tag), `{tags}` (all of them, comma-separated) or `{category2}`
(its second category). Putting `{category}` in both slots renders the same
word twice — which is how a real conversion shipped cards reading
"Product / Product" where the original said "Product / Management". Each of
these falls back to empty rather than to the category, because an empty chip
is visibly missing data while a duplicated one quietly asserts something
false.

`{excerpt}` is trimmed to 30 words. A post with no featured image gets a
transparent placeholder rather than an empty `src`, because an empty `src`
makes browsers re-request the page and draw a broken-image icon.

Put the token **inside** the grid container, not around it, and keep exactly
one card between the tags.

### Load more

```html
<button data-cve-load-more="blog" data-cve-target=".journal-grid">Load more</button>
```

- `data-cve-load-more` — the page key this listing belongs to
- `data-cve-target` — a CSS selector for the container new cards go into

The card template for page two comes from the page's own stored markup, never
from the browser's request. Restyle the card and page two follows.

---

## `[wp-form]` — connect a designed form

```
[wp-form id="contact" type="contact" to="you@example.com" redirect="/thanks/"]
  <form>…your own markup…</form>
[/wp-form]
```

| Attribute | Meaning |
|---|---|
| `id` | Groups submissions. Generated automatically when connected by clicking |
| `type` | `contact` or `list` |
| `to` | Recipient for this form; falls back to the site-wide setting |
| `redirect` | Where to go after submitting |
| `list` | Mailing list ID, for `type="list"` |

The form's own markup is untouched. Hidden fields are injected for security
and routing, the `action` is rewritten, and that is all.

**Field naming**: any field with a `name` is captured. A group of checkboxes
or a `<select multiple>` needs a shared name ending in `[]` —
`name="interests[]"` — or only the last value survives.

You normally never write this token by hand; the FORM panel writes it when you
connect a form by clicking. See [Forms](forms.md).

---

## `[wp-menu]` — render a WordPress menu

```
[wp-menu location="primary" submenu-template='<a href="{url}">{title}</a>']
  <a href="{url}">{title}</a>{submenu}
[/wp-menu]
```

| Attribute | Meaning |
|---|---|
| `location` | The registered menu location |
| `submenu-template` | Markup for each sub-item. Defaults to a plain link |

**Placeholders**: `{title}` `{url}` `{submenu}`

Two levels deep. Renders nothing if the location has no menu assigned.

Menu items themselves are managed in **Appearance → Menus**; clicking one in
the editor gives you a small panel for its label and URL rather than editing
the generated markup.

---

## `[wp-article]` — fields of the current post

Used inside the article template. Each token names one field, and its inner
content is a **sample** shown while designing, so the template reads like a
real article instead of collapsing when no post is in scope.

```html
<h1>[wp-article field="title"]A sample headline[/wp-article]</h1>
```

| `field` | Renders |
|---|---|
| `title` | Post title |
| `category` | First category name |
| `date` | Publication date |
| `author` | Author name |
| `readingtime` | "4 min read" |
| `image` | Featured image. Renders nothing if there isn't one |
| `content` | The post body |
| `share` | Share links — see below |
| `nav` | Previous/next links — see below |

### `share`

Placeholders: `{url}` `{title}`, plus `{url_encoded}` `{title_encoded}` for
building share URLs and mail links safely.

```html
[wp-article field="share"]
  <a href="https://twitter.com/intent/tweet?url={url_encoded}">Share</a>
  <a href="mailto:?subject={title_encoded}&body={url_encoded}">Email</a>
[/wp-article]
```

### `nav`

Takes `{prev}…{/prev}` and `{next}…{/next}` blocks, each with `{url}` and
`{title}`:

```html
[wp-article field="nav"]
  {prev}<a href="{url}">← {title}</a>{/prev}
  {next}<a href="{url}">{title} →</a>{/next}
[/wp-article]
```

A missing neighbour drops that half on the live site. In the editor it stays
with placeholder text, so the layout you are editing does not shift.

### Dynamic attributes

A token cannot live inside an attribute. For those, an element carries a
`data-cve-field-*` attribute and the real attribute is filled in at render
time:

```html
<a href="/blog/" data-cve-field-href="category_url">Category</a>
```

The written-in value stays as a genuine fallback.

---

## Related

- [Blog posts](blog-posts.md) — the everyday view of `[wp-posts]`
- [Forms](forms.md) — the everyday view of `[wp-form]`
- [The editor](the-editor.md) — why generated regions are not directly
  editable
