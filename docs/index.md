# Visual Edit documentation

Visual Edit is an editing layer for native Gutenberg block themes and for
WordPress sites converted from hand-written or AI-generated HTML. Block themes
open in the VE workspace hosting the WordPress editor. Converted
themes keep their raw markup and use the click-to-edit preview.

A native block theme needs no VE contract. A converted raw-HTML theme declares
the `clara_ve_theme_contract` filter; that contract is open and documented.
See [Requirements](getting-started/requirements.md) for the two drivers.

---

## Start here

| You are | Start with |
|---|---|
| Setting up a site for the first time | [Installation](getting-started/installation.md) → [First run](getting-started/first-run.md) |
| Editing a site day to day | [The editor](guide/the-editor.md) |
| Setting up forms and email | [Forms](guide/forms.md) → [Email delivery](guide/email-delivery.md) |
| Working on search visibility | [SEO](seo-and-ai/seo.md) → [AI readiness](seo-and-ai/ai-readiness.md) |
| Building or integrating | [Architecture](developer/architecture.md) |

---

## Getting started

- **[Requirements](getting-started/requirements.md)** — WordPress and PHP
  versions, the capabilities editing needs, what happens on multisite, and
  which themes this works with.
- **[Installation](getting-started/installation.md)** — installing the theme
  and the plugin, in the right order, and updating later.
- **[First run](getting-started/first-run.md)** — importing the site's
  content, making a first edit, and a tour of every screen the plugin adds.

## The site owner's guide

- **[The editor](guide/the-editor.md)** — the canvas, the toolbar, edit mode,
  device widths, the page picker, and how saving works.
- **[Editing content](guide/editing-content.md)** — text, links, images,
  video, alt text, and converting an image to video or back.
- **[Styling](guide/styling.md)** — typography, colour, spacing, layout, and
  editing the decorative marks that come from CSS rather than markup.
- **[Repeating items](guide/repeating-items.md)** — managing FAQs, service
  cards, team members, portfolio tiles and any other repeating set as a list.
- **[History](guide/history.md)** — per-page versions, what a restore does,
  and how far back it goes.
- **[Forms](guide/forms.md)** — connecting a designed form, what gets stored,
  and every anti-spam layer.
- **[Email delivery](guide/email-delivery.md)** — sending through your server,
  SMTP, or a provider API, and why mail lands in spam without it.
- **[Mailing lists](guide/mailing-lists.md)** — signup, double opt-in, the
  subscriber list, and the consent record.
- **[Blog posts](guide/blog-posts.md)** — listings built from the design's own
  card, the article template, and "load more".
- **[Dynamic tokens](guide/dynamic-tokens.md)** — the four tokens that let a
  static design show live WordPress content.
- **[Import](guide/import-export.md)** — bringing a converted site's content
  in, moving a site, and why an import never overwrites your work.

## Search and AI

- **[SEO](seo-and-ai/seo.md)** — per-page search appearance, the site-wide
  identity settings, and how this works alongside Yoast SEO or Rank Math.
- **[Redirects](seo-and-ai/redirects.md)** — keeping old addresses working
  after a site moves to WordPress.
- **[AI readiness](seo-and-ai/ai-readiness.md)** — structured data,
  `llms.txt`, AI-crawler rules, and the readiness report.

## Developer reference

- **[Architecture](developer/architecture.md)** — page keys, positional
  paths, the source store, render targets, and the two invariants everything
  else follows from.
- **[Theme requirements](developer/theme-requirements.md)** — exactly what a
  theme must provide for the editor to work.
- **[REST API](developer/rest-api.md)** — all routes, their arguments and
  returns, and what protects the public ones.
- **[Editor API](developer/editor-api.md)** — `window.ClaraVE`: operations,
  events and popup hooks shared by both editors.
- **[Hooks and filters](developer/hooks-and-filters.md)** — what the plugin
  fires for you, and every core hook it attaches to.
- **[Data model](developer/data-model.md)** — tables, options, post meta,
  transients, and how secrets are stored.
- **[Bundle format](developer/bundle-format.md)** — the `clara-content`
  content-package specification.
- **[Constants](developer/constants.md)** — what can be set in `wp-config.php`.
- **[Extending](developer/extending.md)** — what is pluggable and what
  requires editing the source. Honestly.
- **[Workspace parity](developer/workspace-parity.md)** — the development
  record for the block-theme workspace: what each release changed, what was
  verified and on which WordPress, and what was not.

## Reference

- **[Security](reference/security.md)** — the capability model, public
  endpoints, secret storage and its limits, and the anti-spam layers.
- **[Data and privacy](reference/data-and-privacy.md)** — everything stored,
  what uninstalling removes and what it keeps.
- **[Troubleshooting](reference/troubleshooting.md)** — symptoms and their
  causes.
- **[Production readiness](reference/production-readiness.md)** — whether this
  release can go on a live site, who it is worth installing for, what the
  release gate and Plugin Check actually reported, and the known limitations.
