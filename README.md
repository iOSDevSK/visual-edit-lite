# Visual Edit Lite

Point-and-click editing for WordPress sites converted from hand-written or
AI-generated HTML. Click any text, image, link or card on the real page and
change it — the page's markup stays byte-for-byte identical to the design it
came from, because nothing is ever re-structured into blocks or widgets.

---

## What it needs from a theme

Visual Edit Lite edits raw HTML in place, so it needs a theme that *has* raw
HTML: one whose pages are markup rather than blocks, and which declares what
that markup means through the `clara_ve_theme_contract` filter.

**That contract is open and documented.** Everything a theme must provide is
written down in [`docs/developer/theme-requirements.md`](docs/developer/theme-requirements.md)
— a front-page pattern, a handful of template parts, a key marker per block,
and per-key structural anchors. Any theme can satisfy it, hand-written or
generated, and no part of it depends on a service.

In practice most such themes are produced by a converter that turns a finished
HTML site — one built in **Lovable, Bolt, aidesigner.ai, v0.dev, Claude
design**, or written by hand — into a WordPress theme with the original markup
intact. That converter is a separate project; this repository is the editor.

Install the plugin on a theme that declares the contract and everything below
works. Install it on one that does not and the editing screens still load, but
the canvas has nothing it recognises to edit.

**It is not a page builder and does not work with one.** Elementor, Divi and
Beaver Builder store your page as their own data structure and render markup
from it; this plugin does the opposite, editing the markup the theme already
carries, in place. A page built by one of those gives it nothing to work on.

**Gutenberg block themes are supported**, through a second editing mode. On a
block theme the editor works on core block markup rather than raw HTML: whole
sections can be added, copied, moved and removed, the block supports panel is
available, and every write passes a validation gate — because block markup
fails silently at parse time in ways HTML does not, and only after WordPress
has already stored it. The raw-HTML mode is for themes that declare the
contract; the block mode needs no contract at all.

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
export, and a save history that lists all 300 recorded entries rather
than the ten most recent plus the Original. Both editions RECORD the same
300 — the difference is how many the list shows. It is sold separately and is not
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
| Theme | any theme declaring `clara_ve_theme_contract` (see [theme requirements](docs/developer/theme-requirements.md)) |

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
list. The converter is a separate project; this repository is the editor, and
its theme contract is documented so anyone can build against it.

## Verifying a build

    tools/verify.sh

Builds the package, boots a throwaway WordPress in Docker, installs the
extracted ZIP under its real slug, installs the official
[Plugin Check](https://wordpress.org/plugins/plugin-check/) if it is not
already there, runs it across every category, and asserts the Lite-specific
behaviour (no licence gate, no Pro classes, no AI routes, history listing ten
plus the Original). Exits non-zero on any failure; `--keep` leaves the site up
on `localhost:8897`. Offline, point it at a local copy:
`PLUGIN_CHECK_ZIP=/path/to/plugin-check.zip tools/verify.sh`.

## Releasing

    tools/release.sh            # or: tools/release.sh X.Y.Z

Verifies, pushes, builds, tags (bare version — no `v` prefix), publishes the
GitHub Release with the ZIP attached, then **downloads the published asset
back and diffs it against a fresh build**. One command, because "push now,
release later" is how the download stops matching the code.

Release notes are read from the matching `= X.Y.Z =` block in `readme.txt`,
so the changelog has one home.

## Regenerating the translation template

    php tools/make-pot.php

Rewrites `languages/visual-edit-lite.pot` from the source. Run it after
changing any translatable string.
