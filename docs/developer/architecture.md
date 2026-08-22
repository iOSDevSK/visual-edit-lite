# Architecture

How the plugin is put together, and the two invariants that explain most of
its design decisions.

## The problem it solves

A converted site's pages are raw HTML — the designer's own markup, kept
byte-for-byte. Making that editable without re-structuring it into blocks
means:

- storing the markup as markup
- addressing an element inside it from a browser, reliably
- writing changes back without disturbing anything else
- rendering it through WordPress's normal pipeline so plugins and themes still
  work

Everything below follows from those four.

## Page keys

A **key** is a `sanitize_key()`'d string naming one editable surface. It is the
index for the stored source, the decorative styling, the history table, the
content bundle and the redirect map.

### Reserved keys

| Key | Renders through |
|---|---|
| `front-page` | The theme's `front-page-original` block pattern, re-registered at runtime with stored content |
| `header` | `wp_template_part` post named `header` |
| `footer` | `wp_template_part` post named `footer` |
| `article` | `wp_template_part` named `article`, used by `templates/single.html` |
| `404` | `wp_template_part` named `404` |

Any other WordPress Page becomes editable by carrying `_clara_ve_key` post
meta.

### Why 404 is a template part

WordPress renders the 404 for addresses that do not exist, so there is no
stable URL an editor could load to edit it. A theme that instead ships a "404
preview" Page plus a separate template gives the owner two independent copies
of the same design, where editing the one they can reach changes nothing about
the one visitors see.

### Key resolution per request

In order: an authorised `?clara_ve_key=` override (chrome keys only, inside an
edit preview) → front page → **404** → any single post → a tagged Page's own
meta.

404 is checked before the page and post branches because a 404 *is* the
absence of both. Any single post maps to the shared `article` key, which is
why opening any article in the editor edits the layout rather than that post's
words.

## Storage

| What | Where |
|---|---|
| A page's markup | Option `clara_ve_source__{key}` (the front page keeps the older name `clara_ve_front_source`) |
| Decorative styling | Option `clara_ve_pseudo__{key}` |
| Version history | Table `{prefix}clara_ve_history` |

An option rather than post meta, because chrome keys have no post. See
[Data model](data-model.md).

### Render targets

The stored option is the **source of truth**. Saving mirrors it into whatever
WordPress actually renders:

- **A tagged Page** → its `post_content`, as one Custom HTML block carrying the
  key marker
- **Header / footer / article / 404** → a `wp_template_part` post, which is
  exactly what the Site Editor's own Save writes. Once that post exists,
  WordPress prefers it over the theme file with no hook needed
- **The front page** → nothing is mirrored. The stored source is injected by
  re-registering the theme's pattern at `init`

For header and footer, only the block carrying **this key's marker** is
replaced; everything else in the part is re-serialised untouched. A theme's
header part often carries a second, non-editable block (a mobile drawer), and
a whole-file overwrite would delete it on the first save.

## Invariant 1: paths are positional indices into the source

An element is addressed as `path-3-0-2-1` — child 3, then child 0, then 2,
then 1, counting from a root element.

The browser walks the rendered DOM; the save walks the parsed stored source.
The two agree because the indices are the same on both sides.

**Everything else follows from keeping that true:**

| Consequence | Why |
|---|---|
| Tokens are plain text, never elements | A text node occupies no child index, so wrapping a form in a token shifts nothing |
| A "load more" button with nothing left is hidden by an *attribute* | Removing the element would shift every following sibling |
| `<script>`/`<style>` are skipped from stamping but still counted | They occupy an index in the source too |
| Structural changes apply last, deepest-path first | Removing an element shifts later siblings down; doing it last means every other path was still valid when it was used |
| The article's prev/next keeps its empty half in the editor | Dropping it live is fine; dropping it while editing would shift paths |
| Generated regions are one opaque slot | A region rendering N items where the source has one token cannot have per-item paths |

Any feature that adds or removes a sibling at render time silently misaligns
every editor path below it. That is the failure mode to watch for.

### Class snapshots

At stamp time each element's original class list is recorded. Shape matching
reads that snapshot rather than the live class list, because the page's own
scripts mutate classes — a scroll-reveal script adds a class to elements as
they enter the viewport, so two identical cards can look structurally
different depending on what the visitor scrolled past.

## Invariant 2: extract, never author

The plugin reads what is there. It does not write new content on the site's
behalf.

- **Structured data** is extracted from real content, with thresholds that
  refuse to publish a guess
- **The readiness audit is read-only** — permanently. A one-click fix that
  inserts a heading breaks the byte-for-byte promise silently
- **The collection editor clones an existing item**, never generates markup
- **Import never overwrites** — a conflict is reported and left alone
- **Descriptions fall back to extraction only** — a post excerpt, the tagline,
  or nothing

## Render pipeline

A page's stored source lives in a Custom HTML block carrying:

```html
<!-- clara-ve-key: about -->
```

That marker is what makes hydration, nav swapping and part-scoped saving all
possible. Three filters attach to WordPress's `core/html` block rendering, each
bailing immediately on any block without it.

At render, in order:

1. **Article specimen stripped** (priority 8) — the styling samples are removed
   outside the editor
2. **Navigation swapped** (priority 9) — hardcoded nav replaced from the
   WordPress menu
3. **Tokens hydrated** (priority 10) — `[wp-posts]`, `[wp-form]`, `[wp-menu]`,
   `[wp-article]`

Inside an edit preview, a hydrated token's output is wrapped in a marker
element that the browser-side stamper treats as **one slot it does not recurse
into** — which is how a region rendering nine cards stays consistent with a
source containing one token. On a real visitor's page that wrapper is not
emitted at all.

## Saving

The browser collects patches; nothing is written until Save.

On save the client parses the stored source, applies non-structural patches,
then structural ones in reverse path order, and posts the result as one
string. The server validates the shape, records history, writes the option and
mirrors to the render target.

One save, one version, regardless of how many edits it contains.

## Capability model

```php
current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' )
```

Raw HTML round-trips through the editor, so anyone who can edit can put
arbitrary markup on the site. `unfiltered_html` is the capability WordPress
already uses for exactly that.

See [Security](../reference/security.md).

## File map

| | |
|---|---|
| `visual-edit-lite.php` | Bootstrap, constants, key resolution, render filters, lifecycle |
| `includes/class-source-store.php` | The source of truth: storage, sync, shape validation |
| `includes/class-history.php` | Versioning |
| `includes/class-tokens.php` | Token hydration |
| `includes/class-rest.php` | Editing REST routes |
| `includes/class-forms.php` | Submissions and anti-spam |
| `includes/class-mailer.php` | SMTP and provider APIs |
| `includes/class-seo*.php` | SEO record, emitter, settings |
| `includes/class-geo*.php` | Structured data, llms.txt, readiness audit |
| `includes/class-bundle-*.php`, `class-import-*.php` | Content packaging |
| `assets/bridge.js` | Runs inside the page: stamping, selection, live editing |
| `assets/editor.js` | Runs in the admin: panels, patches, saving |

## Related

- [Theme requirements](theme-requirements.md) — what a theme must provide
- [Data model](data-model.md) — every table, option and meta key
- [REST API](rest-api.md)
- [Extending](extending.md) — what is pluggable, honestly
