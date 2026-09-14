# Theme requirements

Exactly what a theme must provide for the editing canvas to work. A converted
theme satisfies all of this; this page is for anyone building or debugging one.

These requirements apply only to the raw-HTML driver used by converted VE
themes. A normal WordPress block theme uses the native Gutenberg Site/Post
Editor and needs no VE theme contract.

## Summary

| Requirement | Consequence if missing |
|---|---|
| A pattern named `front-page-original` | Front page not editable |
| `.wp-site-blocks` wrapper | Canvas inert — nothing clickable |
| `.wp-block-post-content` inside it | Ordinary pages not editable |
| Template parts `header`, `footer`, `article`, `404` | Those keys have nothing to edit |
| `<!-- clara-ve-key: … -->` marker in each owned HTML block | Tokens never hydrate; saves do not mirror |
| `anchors` in the contract | Only the non-empty guard protects that key |
| `menus` zones in the contract | Menu management is off, and says so |

## The contract, first

One filter carries everything the plugin cannot work out on its own. Generated
themes ship it in `inc/visual-edit.php`:

```php
add_filter( 'clara_ve_theme_contract', function ( $contract ) {
    $contract['anchors'] = array(
        'front-page' => array( 'class="hero"' ),
        'header'     => array( 'site-header' ),
    );
    $contract['menus'] = array(
        array(
            'location' => 'theme_nav_1',
            'selector' => 'nav.nav-links',
            'label'    => 'Header navigation',
            'active'   => 'is-current',   // optional
            'rest'     => '',             // optional
        ),
    );
    $contract['parts'] = array(
        array(
            'key'         => 'header-2',
            'area'        => 'header',
            'label'       => 'Header (variant 2)',
            'preview_key' => 'signin',
        ),
    );
    return $contract;
} );
```

| Key | What it declares |
|---|---|
| `anchors` | per visual-edit key, substrings a save must preserve |
| `menus` | which elements are navigation zones, and which menu location each renders |
| `parts` | chrome *variant* template parts beyond the standard header and footer |

**The plugin ships no defaults for any of it.** Every markup-specific fact
belongs to the theme that owns the markup; hardcoded defaults were tried once
and are exactly what broke every theme that was not the one they came from.
Several zones may share one location — a desktop nav and its mobile drawer
rendering the same menu.

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

Saving validates that the markup still contains the substrings **the theme
declares** for that key. An empty source is always refused, for every key,
unconditionally — that guard is the plugin's own and cannot be switched off.

Anchors come from the contract:

```php
'anchors' => array(
    'front-page' => array( 'class="hero"' ),
    'header'     => array( 'site-header' ),
    'footer'     => array( 'site-footer' ),
),
```

Declare none and only the non-empty rule applies, which is the right default:
a theme that declares anchors it does not actually have refuses every save of
that page.

> **This used to be a hardcoded list** — `class="hero"`, `site-header`,
> `site-footer`, `utility` — taken from one specific site. In a plugin that
> converts *any* site that is not a default but a bug shaped like a feature:
> every generated theme whose markup differed had its front page silently
> refused at import. The list is now the theme's to declare. The
> `clara_ve_required_anchors` filter still runs, with the contract's anchors
> as its default, so themes generated before the contract existed keep
> working.

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

Anchors exist to catch a save that has destroyed a page's structure. Declare
the few substrings that would only disappear if something went wrong — a
section wrapper, not a class that a redesign might rename.

## 6. Navigation

Menu-driven navigation needs the theme to say **which elements are navigation**,
in the contract's `menus` array — one entry per zone:

```php
'menus' => array(
    array(
        'location' => 'theme_nav_1',       // nav menu location slug
        'selector' => 'nav.nav-links',     // "tag.class", "tag", or [data-ve-nav="1"]
        'label'    => 'Header navigation',
        'active'   => 'is-current',        // optional: the class the design
        'rest'     => '',                  // gives the current page's link
    ),
),
```

Declare nothing and menu management is **visibly off** — the plugin says so on
its own screens rather than letting somebody build a menu that connects to no
markup. A mobile drawer is its own zone with its own entry; it is an unmarked
sibling of the header block, so matching only the header's key marker once
left the burger menu showing stale links while the desktop nav showed the
WordPress menu's.

> `nav.nav-links` and `nav.drawer-nav` above are an **example**, not a
> requirement. They used to be hardcoded — one specific site's markup — which
> meant menu management silently did nothing on every other theme.

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
