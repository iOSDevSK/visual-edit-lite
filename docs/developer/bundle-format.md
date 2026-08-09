# Bundle format

The `clara-content` package specification — how a site's content is
serialised for export, import, and shipping inside a theme.

Format string: **`clara-content/1`**

## Layout

A folder at the theme root:

```
clara-content/
  manifest.json
  sources/
    index.json
    {key}.html
  pseudo/
    {key}.json
  posts.json
  terms.json
  menus.json
  options.json
  settings.json
  redirects.json
  media/
    index.json
    files/…
  submissions.json      (opt-in only)
  subscribers.json      (opt-in only)
```

A reader searches for `manifest.json` at depth 0, 1 and 2, so a ZIP that
nests everything one level down still works.

## Files

### `manifest.json`

```json
{
  "format": "clara-content/1",
  "generator": "Visual Edit 1.14.0",
  "generated_at": "2026-07-28T18:00:00+00:00",
  "mode": "site",
  "theme": { "slug": "…", "name": "…", "version": "1.1.0" },
  "source_site": { "home": "…", "uploads_baseurl": "…", "charset": "UTF-8", "wp_version": "7.0" },
  "tokens": { "theme_uri": "…", "uploads_uri": "…", "home_url": "…" },
  "contains": { "sources": 16, "posts": 7, "media": 42 },
  "excluded": [],
  "notes": ""
}
```

### `sources/index.json`

One row per editable page:

```json
{
  "key": "about",
  "kind": "page",
  "file": "sources/about.html",
  "sha256": "…",
  "title": "About",
  "slug": "about",
  "pseudo": "pseudo/about.json",
  "seo": { … }
}
```

`kind` is `front`, `page` or `part`. **Per-page SEO rides with the page**
rather than sitting in a global file.

### Other files

| File | Contains |
|---|---|
| `sources/{key}.html` | The page's markup, portable |
| `pseudo/{key}.json` | Decorative styling: path → property map |
| `posts.json` | `key`, slug, title, status, dates, excerpt, content, terms, optional `seo`, optional `featured_media` |
| `terms.json` | taxonomy, slug, name, description, parent slug |
| `menus.json` | name, slug, locations, items |
| `options.json` | Allowlisted settings only |
| `settings.json` | Reading/permalink/date settings, plus `page_on_front_key` |
| `redirects.json` | `[{ from, key }]` for a page, `[{ from, post }]` for a blog post |
| `media/index.json` | id, file, url, mime, title, alt, caption, sha1, bytes |

### Redirects reference the target by key, not by URL

A row names the object it points at — `key` for a page, `post` for a blog
post — and the destination URL is resolved per request. That is what lets an
owner rename a page slug, or retitle a post (which changes its slug), without
any old address breaking and with nothing to keep in sync.

`post` rows exist because a blog conversion turns the source site's article
PAGES into WordPress Posts: `/blog/x.html` has to land on a post, and a row
carrying a page key would resolve through `find_page_by_key()` and find
nothing — turning a working old URL into a redirect to a 404. Posts are
stamped with their bundle key at import (`_clara_ve_key`, the same meta a
page carries), which is what a `post` row resolves through; a post with no
stamp falls back to an exact slug match.

### Menu items reference pages by key

A menu item pointing at an editable page is exported as
`{"type": "page_key", "page_key": "about"}` rather than a URL, so it resolves
correctly on the destination site regardless of its domain or permalink
structure.

## Portability tokens

Three, with different lifetimes:

| Token | Replaces | Persisted in the database? |
|---|---|---|
| `__CLARA_THEME_URI__` | The theme directory URI | **Yes** — in the stored source |
| `__CLARA_UPLOADS_URI__` | The uploads base URL | No — bundle boundary only |
| `__CLARA_HOME_URL__` | The site home URL | No — bundle boundary only |

### Three rules you cannot break

**1. Never add substitutions to the persisted tokeniser.**

History marks the current version by comparing a hash of the tokenised live
source against stored hashes. That identity only holds while tokenising and
un-tokenising round-trip exactly. A new rule silently breaks HEAD detection
for every row written before it — invisibly, until someone opens History.

This is why the two bundle tokens exist separately rather than being added to
the persisted one.

**2. Uploads before home, in both directions.**

The uploads base URL normally *begins with* the home URL. Replace home first
and you get `__CLARA_HOME_URL__/wp-content/uploads/…`, and media silently
lands unportable while looking correct in the file.

**3. Resolving a bundle source needs both layers.**

`from_portable()` handles uploads and home. It does **not** resolve the theme
URI. A caller writing a source back must also un-tokenise, or
`<img src="__CLARA_THEME_URI__/assets/…">` ships to visitors.

### Featured images

Exported as `"featured_media": "media:412"` and resolved through a map of old
attachment ID to new. This is the only place the convention is used — media
inside HTML travels as a tokenised URL instead.

## Version gate

The reader checks the **major** number:

- Not starting with `clara-content/` → refused
- Major higher than the plugin's own → refused with *"Update Visual Edit and
  try again"*

Refused rather than half-understood.

## Additive compatibility

Observed convention throughout, worth following if you extend the format:

- **The reader defaults absent keys.** A missing file becomes an empty array;
  a missing `seo` becomes the empty record shape, so the importer has one case
  to handle instead of two
- **The writer omits empty values.** `seo` is written only when it has
  content, `redirects.json` only when there are rows

The result: an old bundle read by a new plugin yields empty defaults; a new
bundle read by an old plugin drops what it does not know. Degradation, not
breakage — which is why the format string has not needed to move.

## Size guards

Refused rather than partly unpacked:

| | |
|---|---|
| Sources | 500 |
| Posts | 2000 |
| Media | 5000 |
| Submissions | 20000 |
| Subscribers | 20000 |

Path traversal is rejected on read: absolute paths, `..` segments and Windows
drive prefixes.

## Secret redaction

Three independent layers.

**1. The options list is an allowlist.** The writer iterates that array rather
than scanning the options table, so any setting added elsewhere in the plugin
is excluded until someone deliberately lists it. That is the intended failure
direction.

Two scopes: `portable` (meaningful anywhere — model preferences, consent text,
SEO identity) and `site` (this site only — recipient address, SMTP settings,
list provider), withheld from a sample bundle.

**2. A never-export list**, checked on the way out *and* on the way in. The
eight credential options, enumerated redundantly on purpose: the cost of the
redundancy is one array intersection, and the cost of being wrong once is a
customer's API key inside a file they hand to someone else.

**3. A value-shape pattern** matched against exported values:

```
/(^sk-|^sk_live|^SG\.|^key-[0-9a-f]{32}|^xkeysib-|^pk_live|BEGIN [A-Z ]*PRIVATE KEY)/
```

Catches a key pasted into the wrong field, or a future option nobody
classified.

**A match fails the whole export**, loudly. A silent filter would let a
mistake in the allowlist ship undetected for as long as nobody looked.

### Personal data is separate

Form submissions and subscriber emails are visitor personal data, gated by
their own opt-in and **off by default in every mode**. Unrelated to the secret
guard.

Subscribers export **without** their confirmation tokens, so a stale link can
never confirm on the destination site.

## Related

- [Import and export](../guide/import-export.md) — the user-facing view
- [Data model](data-model.md) — what exists before it is packaged
- [Architecture](architecture.md) — keys and the source store
