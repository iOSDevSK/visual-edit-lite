# Theme requirements

Exactly what a theme must provide for the editing canvas to work. A converted
theme satisfies all of this; this page is for anyone building or debugging one.

## Summary

| Requirement | Consequence if missing |
|---|---|
| A pattern named `front-page-original` | Front page not editable |
| `.wp-site-blocks` wrapper | Canvas inert — nothing clickable |
| `.wp-block-post-content` inside it | Ordinary pages not editable |
| Template parts `header`, `footer`, `article`, `404` | Those keys have nothing to edit |
| `<!-- clara-ve-key: … -->` marker in each owned HTML block | Tokens never hydrate; saves do not mirror |
| Per-key structural anchors | Saves refused for that key |
| `nav.nav-links` / `nav.drawer-nav` | Menu-driven nav silently does nothing |

## 1. The front-page pattern

The theme registers a block pattern whose name ends in `front-page-original`.
Resolution prefers `{stylesheet}/front-page-original` and falls back to
scanning for any registered pattern with that slug, so the plugin is not
welded to one theme slug.

That pattern's rendered HTML is the front page's editable source. At `init`
the plugin re-registers it with the stored content.

The key marker is added at **registration**, not stored in the source, so it
cannot be edited away.

## 2. Root selectors

The browser-side stamper needs a root element per key:

| Key | Selector |
|---|---|
| `front-page` | `.wp-site-blocks`; bridge fallback to `body` when the pattern is rendered beside it |
| `header` | `.wp-site-blocks > header.wp-block-template-part` |
| `footer` | `.wp-site-blocks > footer.wp-block-template-part` |
| `article`, `404` | `.wp-site-blocks > main.wp-block-template-part` |
| any tagged page | `.wp-site-blocks .wp-block-post-content, main .wp-block-post-content` |

These are WordPress's own class names, not theme-specific ones. Core may close
`.wp-site-blocks` after a template part and render the following front-page
pattern or normal-page content beside it. The front-page bridge detects that
shape and maps the source-bearing body siblings while excluding the generated
skip link and shared template parts; ordinary pages use the second selector in
their list. If no valid root exists, the canvas loads with nothing clickable.

`article` and `404` share a selector safely — one is a single post, the other
is the absence of one, so they never render together.

## 3. Template parts

Named `header`, `footer`, `article`, `404`, resolved as
`{stylesheet}//{name}`.

Each contains one `core/html` block carrying that key's marker. The part may
contain other blocks — only the marked one is replaced on save.

## 4. The key marker

```html
<!-- clara-ve-key: about -->
```

Placed inside the HTML block the key owns:

```html
<!-- wp:html -->
<!-- clara-ve-key: header -->
<header class="site-header">…</header>
<!-- /wp:html -->
```

This single convention drives:

- **Token hydration** — every render filter bails on a block without it
- **Part-scoped saving** — identifies which block belongs to this key
- **Navigation swapping** — the header's nav is replaced by matching on it

The marker is stripped on every read path, so it never becomes part of what
gets saved.

## 5. Structural anchors

Saving validates that the markup still contains a per-key substring. An empty
source is always refused.

| Key | Required |
|---|---|
| `front-page` | `class="hero"` |
| `header` | `site-header` |
| `footer` | `site-footer` |
| `article` | `article-body` **and** `data-cve-specimen` |
| `404` | `utility` |
| any tagged page | *(nothing — only the non-empty rule)* |

Failure message:

> The page is missing an expected section (X) — save aborted to protect the
> site.

Notes:

- `header` and `footer` match a **bare substring**, not `class="site-header"`,
  because the live attribute also carries state classes like `is-sticky`
- The `article` key requires the specimen because deleting it removes the only
  way to style article body text
- Enforced in two places — the editor save and the importer — from one
  definition

**This is the most theme-coupled part of the plugin.** A theme using different
class names for these sections will have its saves refused for those keys. The
anchors exist to catch a save that has destroyed a page's structure, which is
worth the coupling, but it is coupling.

## 6. Navigation

For menu-driven navigation, the header must use:

- `<nav class="nav-links">` — desktop
- `<nav class="drawer-nav">` — mobile drawer

Both are matched. If the theme uses different class names, assigning a
WordPress menu does nothing — no error, the menu simply never appears. The
drawer is matched separately from the header's key marker because it is an
unmarked sibling block; matching only the marker left the burger menu showing
stale links while the desktop nav showed the WordPress menu's.

Generated submenu markup uses the classes `has-sub`, `nav-sub`,
`drawer-label`, `drawer-sub`.

## 7. The article specimen

The article template carries one sample of each element a post body might
contain, marked:

```html
<p data-cve-specimen="p">Sample paragraph…</p>
<h2 data-cve-specimen="h2">Sample heading</h2>
```

Recognised values: `p`, `h2`, `h3`, `h4`, `blockquote`, `ul`, `ol`, `li`, `a`,
`strong`, `em`.

Styling a specimen writes ordinary inline styles; those are read back and
re-emitted as rules targeting `.article-body` for every post. The specimen is
cut from the published page outside the editor.

## Theme-side integration points

### Dismissing a setup notice after import

The plugin fires an action when a content import finishes:

```php
add_action( 'clara_ve_content_imported', function () {
    update_option( 'my_theme_setup_dismissed', '1' );
} );
```

The plugin does not write into a theme's option namespace.

### Working without the plugin

A theme should render as a static site with the plugin deactivated. Guard
every reference:

```php
if ( class_exists( 'Clara_VE_Source_Store' ) ) { … }
```

Note that **front-page edits do not survive deactivation** — they live in an
option applied at render time. Other pages keep their content, because it is
mirrored into `post_content`.

That is about deactivating the PLUGIN. Deactivating the THEME is a different
thing entirely and the plugin handles it: everything the theme's import created
is parked, the site behaves as though the theme had never been installed, and
activating it again restores all of it. Nothing a theme does can opt out of
that, and nothing a theme does is needed to opt in — ownership is recorded from
what the import created and from what is made while the theme is active, never
from anything the theme declares.

One consequence worth knowing when developing a theme: while your theme is not
active, its pages carry the `clara_ve_parked` status and its slugs wear a
`--ve-{theme}` suffix. Both are restored on activation. Code that looks for a
converted theme's pages by slug or by `post_status => 'publish'` will find
nothing while another theme is running, which is the intended behaviour rather
than a bug to work around.

### Do not put the theme URI token in static files

`__CLARA_THEME_URI__` is resolved when the plugin renders a stored source.
WordPress renders `parts/*.html` directly, so nothing resolves it there and
`<img src="__CLARA_THEME_URI__/assets/…">` ships to visitors.

Tried, shipped, caught by an end-to-end test, reverted.

## Related

- [Architecture](architecture.md)
- [Data model](data-model.md)
- [Bundle format](bundle-format.md)
