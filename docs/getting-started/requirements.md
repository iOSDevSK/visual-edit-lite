# Requirements

What Visual Edit needs to run, and what it needs to be useful. Those are two
different lists.

## Versions

| | |
|---|---|
| WordPress | 6.6 or newer |
| PHP | 7.4 or newer |
| Tested up to | WordPress 7.0 |

No build step, no Composer, no npm, no external PHP libraries.

## Capabilities

Editing requires **both** of these:

- `edit_theme_options`
- `unfiltered_html`

Both are checked together on every editing screen and every editing REST
route. The reason is simple: the editor round-trips raw HTML between the
browser and the database, so anyone who can use it can put arbitrary markup on
the site. `unfiltered_html` is the capability WordPress already uses to mean
exactly that, so it is the one used here rather than inventing a new one.

Two admin screens sit one tier higher, at `manage_options`, because they hold
credentials or personal data:

| Screen | Capability |
|---|---|
| Visual Edit (the editor) | `edit_theme_options` + `unfiltered_html` |
| Import Content | `edit_theme_options` + `unfiltered_html` |
| SEO & AI Readiness | `edit_theme_options` |
| Form Settings, SEO & Sharing, Subscribers | `manage_options` |

### On multisite, this matters

WordPress removes `unfiltered_html` from site administrators on multisite and
gives it only to Super Admins. That is a deliberate WordPress security
decision, not something this plugin sets.

The consequence: **on a multisite install, an ordinary site administrator will
not be able to use the editor.** They will see the menu but the screen will
refuse. Either grant the capability deliberately, or accept that editing is a
Super Admin task on that install.

There is a quieter consequence worth knowing if you ever script changes:
saving without `unfiltered_html` writes the stored source but silently skips
mirroring it to the page WordPress actually renders. The change looks saved
and does not appear. If you drive the plugin from WP-CLI, run it as a real
user (`wp --user=1 …`).

## The theme

This is the requirement that decides whether the plugin is useful to you.

**Visual Edit edits raw HTML that the theme carries.** So it needs a theme
whose pages are markup rather than blocks, and which declares what that markup
means through the `clara_ve_theme_contract` filter. That contract is open and
written down in full, so any theme can satisfy it, hand-written or generated.

In practice most such themes come from a converter that turns a finished HTML
site — built in Lovable, Bolt, aidesigner.ai, v0.dev, Claude design, or
written by hand — into a WordPress theme whose pages keep their original
markup.

### What it does not work with

Not Gutenberg block themes, not Elementor, not Divi, not Beaver Builder, not
an ordinary WordPress theme.

This is not a compatibility gap to be closed later; it is what the plugin
*is*. A page builder stores your page as its own data — blocks, widgets,
shortcodes — and generates markup from that data when someone visits. Visual
Edit stores your page as the markup itself and edits that markup in place.
The two designs are opposites. A theme with no raw HTML in it gives the editor
nothing to point at.

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

What will not work is the editing canvas: no front-page override, no
click-to-edit, no dynamic tokens. The page picker will offer entries whose
template parts do not exist.

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
