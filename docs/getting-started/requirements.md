# Requirements

What Visual Edit needs to run, and what it needs to be useful. Those are two
different lists.

## Versions

| | |
|---|---|
| WordPress | 6.6 or newer |
| PHP | 7.4 or newer |
| Tested up to | WordPress 7.1 |

No build step, no Composer, no npm, no external PHP libraries.

## Capabilities

The raw-HTML editor requires **both** of these:

- `edit_theme_options`
- `unfiltered_html`

Both are checked together on its editing screen and REST routes. The reason is
simple: that editor round-trips raw HTML between the browser and the database,
so anyone who can use it can put arbitrary markup on the site.

On a native block theme, Gutenberg applies WordPress's capability for each
entity: `edit_post` for a page or post and `edit_theme_options` for templates,
template parts, navigation and Global Styles. Native editing does not require
`unfiltered_html` unless the block being used requires it itself.

Two admin screens sit one tier higher, at `manage_options`, because they hold
credentials or personal data:

| Screen | Capability |
|---|---|
| Visual Edit (raw-HTML editor) | `edit_theme_options` + `unfiltered_html` |
| Visual Edit (native Site Editor) | `edit_theme_options` |
| Import Content | `edit_theme_options` + `unfiltered_html` |
| SEO & AI Readiness | `edit_theme_options` |
| Form Settings, SEO & Sharing, Subscribers | `manage_options` |
| Get Pro (and the two Pro-badged items, AI Settings and Export Theme) | `edit_theme_options` |

### On multisite, this matters

WordPress removes `unfiltered_html` from site administrators on multisite and
gives it only to Super Admins. That is a deliberate WordPress security
decision, not something this plugin sets.

The consequence applies to the raw-HTML editor. Native Gutenberg editing keeps
WordPress's normal multisite capability rules.

There is a quieter consequence worth knowing if you ever script changes:
saving without `unfiltered_html` writes the stored source but silently skips
mirroring it to the page WordPress actually renders. The change looks saved
and does not appear. If you drive the plugin from WP-CLI, run it as a real
user (`wp --user=1 …`).

## The theme

This is the requirement that decides whether the plugin is useful to you.

Visual Edit has two theme drivers. A native block theme uses Gutenberg and
needs no VE contract. A converted theme uses the raw HTML it carries and must
declare that markup through the `clara_ve_theme_contract` filter.

In practice most such themes come from a converter that turns a finished HTML
site — built in Lovable, Bolt, aidesigner.ai, v0.dev, Claude design, or
written by hand — into a WordPress theme whose pages keep their original
markup.

### What it does not work with

Elementor, Divi and Beaver Builder remain outside both drivers. They store
content in their own proprietary structures rather than native blocks or the
converted theme contract.

Those builders own their data and editing interface. Gutenberg blocks use
WordPress's native entities, while the raw-HTML driver owns only sources from a
theme that explicitly declares the VE contract; neither driver can safely
rewrite another builder's private data model.

### What a theme has to provide

For the editing canvas specifically, the theme must have:

- a registered block pattern named `front-page-original`
- a `.wp-site-blocks` wrapper, and `.wp-block-post-content` inside it for
  ordinary pages
- template parts named `header`, `footer`, `article` and `404`
- a `<!-- clara-ve-key: … -->` marker inside the HTML block each of those owns
- certain structural anchors per page kind, which the save guard enforces

The full specification is in
[Theme requirements](../developer/theme-requirements.md). A converted theme
satisfies all of it; you do not have to think about any of it.

### What still works without a converted theme

If you activate Visual Edit on an unrelated theme, it will not error, and a
useful amount of it still runs:

- forms, submissions and the whole anti-spam stack
- email delivery (SMTP and the provider APIs)
- mailing lists, double opt-in and the subscriber record
- the SEO record, the fallback tag emitter, Yoast/Rank Math write-through
- redirects
- structured data, `llms.txt`, AI-crawler rules
- the SEO & AI Readiness report

On an ordinary native block theme the full Site Editor opens. On a classic
theme without the converter contract, the raw-HTML editing canvas still has no
source it can safely own.

## Optional services

None of these are required; each unlocks one feature.

| Service | Used for | Notes |
|---|---|---|
| Akismet | Form spam classification | Requires the Akismet plugin with a key |
| Brevo | Mailing lists | Reuses the API key you already set for email delivery |
| SMTP or a mail provider | Reliable email delivery | See [Email delivery](../guide/email-delivery.md) — this one matters more than it sounds |

## Distribution

Visual Edit Lite is in the wordpress.org plugin directory, so it installs and
updates the way any other directory plugin does. See
[Installation](installation.md).
