# Visual Edit Lite

Point-and-click editing for WordPress sites converted from hand-written or
AI-generated HTML. Click any text, image, link or card on the real page and
change it — the page's markup stays byte-for-byte identical to the design it
came from, because nothing is ever re-structured into blocks or widgets.

---

## This plugin needs a converted theme

**Visual Edit Lite is the editing half of a two-part product.** The other half is a
converter that turns a finished HTML site — one built in **Lovable, Bolt,
aidesigner.ai, v0.dev, Claude design**, or written by hand — into a WordPress
theme that keeps the original markup intact. Visual Edit Lite is what makes
that theme editable afterwards.

Install it on such a theme and everything below works. Install it on anything
else and the editing screens will load but have nothing to edit.

**It is not a page builder and does not work with one.** It is not for
Gutenberg block themes, Elementor, Divi, Beaver Builder, or ordinary
WordPress themes. Those store your page as their own data structure — blocks,
widgets, shortcodes — and render markup from it. This plugin does the
opposite: it edits the raw HTML the theme already carries, in place. A theme
that has no raw HTML to edit gives it nothing to work on.

> The converter is a separate, paid service with its own site.
> <!-- CONVERTER_URL --> _(link to be added)_

Parts of the plugin that do **not** depend on the theme — forms, email
delivery, mailing lists, the SEO record and emitter, redirects, structured
data and `llms.txt` — will still work anywhere. The editing canvas is the part
that needs the converted theme.

---

## What it does

**Editing**
- Click-to-edit text, headings, links, images and video, directly on the live
  page, at desktop / tablet / mobile widths
- Typography, colour, spacing and layout controls per element, without
  touching the markup
- Edit CSS `::before` / `::after` ornaments, or promote one into real editable
  text
- Manage any set of repeating cards or list items — reorder, edit, add,
  remove — in one step
- Per-page version history with restore, kept independently for every page —
  the last ten saves plus the Original, which can always be restored

**Content**
- Connect a designed HTML form by clicking; the form's own markup is never
  rebuilt. Submissions are stored in WordPress and emailed
- Layered anti-spam: honeypot, minimum fill time, per-IP rate limiting that
  survives a CDN, and optional Akismet
- Email delivery through your server, SMTP, or the Brevo / SendGrid /
  Postmark / Mailgun APIs — no separate SMTP plugin
- Mailing-list signup with double opt-in and a subscriber consent record
- Blog listings that repeat the design's own card markup for real WordPress
  posts, with "load more"

**Search and AI readiness**
- Title, description, canonical, Open Graph and robots per page, edited from a
  small panel in the editor
- Writes through to Yoast SEO or Rank Math when either is installed, and emits
  the tags itself when neither is
- schema.org structured data, `llms.txt`, and AI-crawler rules — all extracted
  from the site's own content, never invented
- A read-only readiness report that names problems and stops there

**Moving a site**
- Import a converted theme's content bundle — pages, posts, menus, media,
  forms, SEO records
- Import reviews everything first and never overwrites work you have already
  done — conflicts are reported and left alone

---

## Installation

1. Install and activate your converted theme.
2. In WordPress: **Plugins → Add New**, search for "Visual Edit Lite",
   **Install Now**, **Activate**.
3. Follow the theme's setup screen to import the site's content.

The theme and the plugin are two separate files. A theme ZIP never contains
the plugin.

## Lite and Pro

This is the free edition, distributed through the WordPress.org plugin
directory. It has no licence key, no trial and no locked buttons: what it does
not have, it does not contain.

Visual Edit **Pro** adds an AI assistant that edits pages conversationally, AI
image editing and video generation, Cloudflare Turnstile, one-click theme
export, and a 300-entry visible save history. It is sold separately and is not
required for anything Lite does. Both editions store their data under the same
names, so either one reads what the other wrote — and they cannot run at the
same time: with Pro active, Lite switches itself off and says so.

---

## Requirements

| | |
|---|---|
| WordPress | 6.6 or newer |
| PHP | 7.4 or newer |
| Capabilities | `edit_theme_options` **and** `unfiltered_html` |
| Theme | one produced by the converter |

Editing round-trips raw HTML, so it requires `unfiltered_html` — the
capability WordPress uses to mean "this person is trusted with markup". **On
multisite that capability belongs to Super Admins only by default**, so
ordinary site administrators will not see the editor.

No build step, no Composer, no npm. The plugin has no dependencies.

---

## Documentation

Full documentation is in [`docs/`](docs/index.md).

- New here? Start with
  [Getting started](docs/getting-started/installation.md).
- Using the editor day to day?
  [The site owner's guide](docs/guide/the-editor.md).
- Integrating or extending?
  [Developer reference](docs/developer/architecture.md).

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

The editor is open source on purpose. It is delivered to every site it
converts, so it is code you can read, audit and keep — which matters for a
plugin that handles your markup, your form submissions and your subscriber
list. The conversion service is a separate product; this repository is the
editor.

## Regenerating the translation template

    php tools/make-pot.php

Rewrites `languages/visual-edit-lite.pot` from the source. Run it after
changing any translatable string.
