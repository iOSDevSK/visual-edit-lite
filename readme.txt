=== Visual Edit Lite ===
Contributors: filipdvoran
Tags: visual editor, html to wordpress, static site, front-end editor, llms.txt
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.25.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Click-to-edit visual editing for WordPress — static-HTML themes and Gutenberg block themes alike. Text, images, forms, menus, SEO, 1:1 markup.

== Description ==

Visual Edit Lite is the editing companion for a WordPress site built from a
static HTML design — a hand-written site, or one exported from Lovable, Bolt,
v0.dev, aidesigner.ai or a Claude design. The theme keeps every page's markup
byte-for-byte identical to the original; this plugin is what makes those pages
editable, by clicking rather than by rebuilding them in a page builder.

If you have brought an HTML site into WordPress and want the client to edit it
without touching the markup — or without the design surviving a page builder —
this is the piece that was missing.

Nothing here is locked, timed, or waiting for a key. Everything the plugin
contains works on every install, offline included.

Development happens in the open at
https://github.com/iOSDevSK/visual-edit-lite — the full source, the build
script and the translation-template generator are there. The plugin has no
build step: the PHP, CSS and JavaScript shipped in this package are the
source, unminified and uncompiled.

* **Point-and-click editing** of text, links, images and video, directly on
  the live page, with a git-like per-page edit history.
* **Gutenberg block themes too.** On a block theme the editor works on core
  block markup instead of raw HTML: whole sections can be added, copied, moved
  and removed, the block supports panel is available, and every write passes a
  validation gate first — block markup fails silently at parse time in ways
  HTML does not.
* **Repeating content** (FAQ lists, service cards, team members, portfolio
  tiles) managed as a list: reorder, edit, add and remove items together.
* **Forms** — a designed HTML form is connected by clicking, never rebuilt.
  Submissions are stored and emailed; delivery works through your server,
  SMTP, or the Brevo / SendGrid / Postmark / Mailgun APIs. Anti-spam layers:
  honeypot, minimum fill time, per-IP rate limit (CDN-aware) and optional
  Akismet.
* **Mailing list** signup with double opt-in, provider integration and a
  subscriber list in wp-admin.
* **Blog** — listings driven by a token that repeats the design's own card
  markup for real WordPress posts.
* **Menus** — the design's own navigation markup, managed from Appearance →
  Menus.
* **SEO** — titles, descriptions, Open Graph, canonicals, robots and
  redirects, carried over from the original site and editable per page;
  integrates with Yoast SEO / Rank Math when present, self-sufficient when
  not.
* **AI readiness** — schema.org structured data (including FAQ), llms.txt,
  AI-crawler rules, and a read-only structure audit. This is about being
  *readable by* AI search engines. Lite contains no AI writing or image
  tools and sends nothing to any AI provider.
* **Import** — bring in a converted theme's content bundle: pages, menus,
  media, forms and SEO records, reviewed before anything is written.

= Editing history =

Every Save is a restore point. The history panel lists the last ten saves
plus the Original — the design exactly as the theme shipped it — so there is
always a way back, however long ago you started.

The panel lists ten; the table keeps up to three hundred per page. Those rows
are your own content in your own database, and the plugin never deletes them
to make a point — they are there for backups, for WP-CLI, and for whatever you
run next.

= What this plugin needs =

A theme whose pages are raw HTML and which declares its contract with the
plugin through the `clara_ve_theme_contract` filter. The contract is fully
documented in the plugin's own `docs/developer/theme-requirements.md`, so any
theme can satisfy it — hand-written or generated. On a theme that does not,
the editor still loads, but the canvas has nothing it recognises to edit.

Forms, email delivery, mailing lists, SEO, redirects and llms.txt do not
depend on the theme at all and work anywhere.

== Important notes ==

* **Editing requires the `unfiltered_html` capability**, because raw HTML
  round-trips through the editor. On multisite only Super Admins have it by
  default, so ordinary site administrators will not see the editor.
* **Deactivating** the plugin keeps all data and reverts the front page to the
  theme's shipped design until reactivation; other pages keep their edited
  content.
* **Deleting** the plugin always removes stored secrets (SMTP password,
  provider API keys) and scheduled jobs. Everything else — submissions,
  subscribers, edit history, page sources — is kept unless "also delete all
  stored data" is enabled under Visual Edit Lite → Form Settings → Uninstall.
* **Visual Edit Pro**, the paid edition, shares this plugin's data format and
  its class and option names. The two cannot run at the same time: with Pro
  active, Lite switches itself off and says so rather than crashing the site.

== Installation ==

1. Install and activate the converted theme you were given.
2. Plugins → Add New → search for "Visual Edit Lite" → Install → Activate.
3. Follow the theme's setup screen to import the site's content, then edit any
   page from the Visual Edit menu.

== Frequently Asked Questions ==

= Is anything disabled until I pay? =

No. Visual Edit Lite has no licence key, no trial period and no locked
buttons. The features Lite does not have are simply not part of it — there is
nothing in the plugin to unlock.

= What is in Visual Edit Pro that is not here? =

An AI assistant that edits pages conversationally, AI image editing and AI
video generation (all bring-your-own API key), Cloudflare Turnstile as an
extra anti-spam layer, one-click theme export, and a history panel that lists
300 restore points per page instead of ten. Pro is sold separately and is not
required for anything Lite does.

= Will I lose my work if I switch between Lite and Pro? =

No. Both editions store content, history and settings under the same names, so
either one reads what the other wrote, in both directions and with nothing to
migrate.

= Can I use it on a theme I built myself? =

Yes, if the theme's pages are raw HTML and it declares the
`clara_ve_theme_contract` filter. See the plugin's `docs/` directory for the
theme requirements.

= Does it work without JavaScript on the front end? =

Forms submit and work without JavaScript. The editor itself is a wp-admin
screen and needs JavaScript, like the block editor does.

== External services ==

This plugin does not contact any external service on its own. Every service
below is reached only after you switch it on, and only for the purpose
described.

**Google Fonts** — used only if you add Google fonts in the editor's font
picker. Opening the picker requests the public font catalogue from
`fonts.google.com`; a page that uses a chosen font loads its stylesheet and
font files from `fonts.googleapis.com`, which means visitors' browsers connect
to Google and Google receives their IP address and user agent. No fonts are
requested if you add none.
Terms: https://policies.google.com/terms — Privacy:
https://policies.google.com/privacy — Google Fonts privacy FAQ:
https://developers.google.com/fonts/faq/privacy

**Akismet** — used only if the separate Akismet plugin is installed and
configured and you enable Akismet filtering under Form Settings. Each form
submission is then sent to Akismet for a spam verdict, including its field
values, the sender's IP address and user agent.
Terms: https://akismet.com/tos/ — Privacy: https://automattic.com/privacy/

**Email delivery providers** — used only if you select one as the mailer and
enter its API key. The message being sent (recipient, subject, body) is
transmitted to the provider you chose:
Brevo — https://www.brevo.com/legal/termsofuse/ ,
https://www.brevo.com/legal/privacypolicy/ ;
SendGrid — https://www.twilio.com/en-us/legal/tos ,
https://www.twilio.com/en-us/legal/privacy ;
Postmark — https://postmarkapp.com/terms-of-service ,
https://postmarkapp.com/privacy-policy ;
Mailgun — https://www.mailgun.com/legal/terms/ ,
https://www.mailgun.com/legal/privacy-policy/

**Brevo (mailing lists)** — used only if you connect Brevo as your mailing-list
provider. A subscriber's email address, and the list you chose, are sent to
Brevo when they confirm a double opt-in signup. Same terms and privacy links
as above.

**Your own SMTP server** — used only if you select SMTP as the mailer. The
message is sent to the host you configured.

**Importing a remote image** — when you click "Import image into this site" on
a picture hosted elsewhere, the plugin downloads that one URL, which you chose,
and stores the file in your Media Library.

**Gravatar** — used only if a listing template you designed includes the
`{author_image}` placeholder. It resolves through WordPress's own
`get_avatar_url()`, so unless another plugin serves avatars locally the
portrait is fetched by visitors' browsers from `gravatar.com`, which then
receives their IP address and user agent. A template without that placeholder
requests nothing.
Terms: https://automattic.com/terms/ — Privacy: https://automattic.com/privacy/

== Privacy ==

The plugin stores form submissions and mailing-list subscribers in your own
database. It sets no cookies, runs no analytics, and sends nothing anywhere
about you or your site. Secrets you enter (SMTP password, provider API keys)
are encrypted at rest with your site's own salt and are always removed when
the plugin is deleted.

== Changelog ==

= 1.25.1 =
This edition is derived from Visual Edit Pro 1.25.1, and brings across
everything Pro added since the last Lite release — none of it licence-gated,
so all of it ships here:
* Block mode: on a block theme, whole sections can be added, copied, moved and
  removed, and the block supports panel is available.
* Movement: scroll and hover animation stored as a class, costing nothing on
  pages that do not use it.
* Different values on smaller screens — padding and the rest can now differ per
  breakpoint, and padding can be dragged on the section itself.
* Copy a page, or remove one, without leaving the editor.
* Typeface handling reworked: a font of your own is kept, a chosen Google font
  actually loads, and added typefaces reach the WordPress editor canvas too.
* Search appearance now works on a block theme.
* Fixes to the picture panel — a new picture appears without saving first, and
  three controls that had never been driven now work.

= 1.19.8 =
* Staggered and carousel lists are collections again. A reveal library's
  per-card delay (`data-aos-delay`/`duration`) and a carousel's frozen runtime
  state (`swiper-slide-active/prev/next/duplicate`,
  `data-swiper-slide-index`) no longer disqualify sibling cards from the
  "manage as a list" panel — those are animation timing and captured state,
  not design differences.
* Listing cards can carry a byline: new `{author}` and `{author_image}`
  placeholders for `[wp-posts]` templates, matching
  `[wp-article field="author"]`.
* Menu labels land on the item's name, never its description. A two-line
  dropdown item (name plus a descriptive sentence) used to get its description
  overwritten by the label on every page; the label now targets the run that
  carries the name.
* Imported blog posts keep their byline. A content bundle may name each
  article's author; the import resolves it to an existing user by display name
  or creates one (role: author) instead of crediting whoever clicked Import.
* Derived from Visual Edit Pro 1.19.8. There is no Lite 1.19.7 — Lite carries
  the version number of the Pro release it was derived from, so the two stay
  comparable at a glance.

= 1.19.6 =
* First public release. Visual Edit Lite is derived from Visual Edit Pro
  1.19.6 and shares its version number so the two stay comparable at a
  glance. Everything the licence gated in Pro — the AI assistant, AI image
  and video tools, Cloudflare Turnstile, theme export — is absent from this
  edition rather than hidden, and there is no licence check, no activation
  call and no bundled updater anywhere in the code.

== Upgrade Notice ==

= 1.19.8 =
Staggered and carousel card lists are editable as collections again, listing
templates can show an author byline, and menu labels stop overwriting item
descriptions.

= 1.19.6 =
First public release.
