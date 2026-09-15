# REST API

Every route the plugin registers, under the namespace `clara-ve/v1`.
Twenty-nine of them: twenty-five require a capability, four are public and are
listed together at the end.

Unless stated otherwise, the permission callback is:

```php
current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' )
```

`key` defaults to `front-page` everywhere it appears.

### `GET|POST /native/seo/{post}`

Search appearance for the VE sidebar inside Gutenberg. Requires
`current_user_can( 'edit_post', {post} )` and accepts only pages and posts.
POST accepts `title`, `description`, `ogImage` and `noindex`; it merges them
onto the full stored SEO record and mirrors them to Yoast or Rank Math.

### `POST /form-submit`

Submissions from the Form block (`clara-ve/form`). Public, like `/submit`, with
the same checks — origin token, honeypot, signed time-trap, rate limit, Akismet —
through `Clara_VE_Forms::handle_submit()`. `to`, `form_type` and `list_id` are
honoured only when `cve_delivery` signs them. That field is written by
`Clara_VE_Tokens::connect_form()`, which connects every form this plugin
renders, so the same rule covers `[wp-form]` forms on `/submit` and block forms
here: a posted recipient is not a mail relay and a posted list id cannot write
into the owner's contacts. Anything unsigned falls back to an enquiry to the
Form Settings recipient. A converted theme that renders its own forms calls in
through `html2wp_theme_form_handle`, and reaches the same `handle_submit()` with
the same check: what that filter receives is the raw request body the theme
forwarded, so a recipient in it is indistinguishable from one a visitor retyped.
Until the converter emits `cve_delivery` in its own markup, a per-form **Send
to** on such a theme falls back to Form Settings.
Registered on every theme, including themes that take over the public runtime
(those stand down only the theme's own form delivery).

### `POST /native/convert-blocks`

Custom HTML in block markup as native blocks, for the workspace when it adds a
theme section. `markup` (**required**, ≤ 200000 chars), `post` (optional). Same
permission as `render-shortcode`. Returns `{ markup, changed, converted,
keptHtml }` from `Clara_VE_Block_Convert::convert_document()`; nothing is
stored. Headings, paragraphs, lists, details, images, buttons, separators,
quotes and plain wrappers convert; anything else stays Custom HTML byte for byte.

### `POST /native/render-shortcode`

What a shortcode puts on the page, for the workspace canvas (WordPress's
shortcode block shows only its text). `text` (**required**, ≤ 2000 chars),
`post` (optional). Requires `edit_post` for `post`, or `edit_theme_options`
without one. Returns `{ html }`, filtered with `wp_kses` using the post
allowlist plus form controls — no scripts or event handlers. Empty when the
text holds no registered shortcode.

## Editing

### `GET /source`
`key` → `{ source, hasEdits, menuManaged }`

### `POST /source`
`key`, `source` (**required**), `pseudo` (array) → `{ saved }`

Validates the structural anchors, seeds a history baseline, writes the option,
mirrors to the render target, records a version.

Pseudo rules are validated as `path-N-N…` ids with `[a-zA-Z]+` property names
and no `{};` in values.

### `GET /seo`
`key` → the page's SEO record plus the effective values read back from Yoast
or Rank Math when present.

For a chrome key returns `{ editable: false, reason }` — header and footer are
fragments of every page, not pages.

### `POST /seo`
`key`, `title`, `description`, `ogImage`, `noindex` → `{ saved, … }`

**Merges** onto the stored record rather than replacing it, so canonical,
Twitter tags and structured data survive an edit from the small panel.

### `POST /reset`
No args → `{ reset: true }`. Front page only.

### `GET /pages`
→ `[{ key, label, url }]` — the page picker, with preview URLs resolved
(article → newest post, 404 → a guaranteed-missing URL, header/footer → the
first tagged page).

### `POST /menu-item`
`originalTitle`, `originalUrl`, `title`, `url` (**required**), `blank`,
`location` → `{ updated: true }`

Matches the item by its current title and URL. It looks in the `location` the
request names first, then every zone the theme's contract declares, and falls
back to `CLARA_VE_NAV_LOCATION` last — so an item in a drawer or a footer menu
is found, not only one in the front-page nav.

### `GET|POST /google-fonts`
GET → `{ catalog, selected, max }`
POST `families` (**required**) → `{ selected, cssUrl }`, or an error over the
5-family cap.

## Pages and blocks

Everything here uses `can_edit_target_post`, an object-level check — the
capability is `edit_post` for the post named in the request, not a blanket
one.

### `POST /pages/duplicate`
`post` (**required**), `title`, `slug` → the copy

Copies a page with everything hanging off it: content, meta, terms, the search
appearance record — but never the canonical, which belongs to the address and
would tell search engines to ignore the copy. The copy is a draft.

### `POST /pages/trash`
`post` (**required**)

### `POST /block-patches`
`post` (**required**), `patches` (**required**) → the updated document

The block-mode write path: an array of operations (`set-text`, `set-attrs`,
`set-style`, `set-link`, `set-image`, `set-responsive`, …) applied to one
post. The queue is all-or-nothing — one refused patch rejects the batch —
and the result must pass the block gate before it is stored.

### `POST /block-structure`
`post` (**required**), `op` (**required**)

Structural edits the patch vocabulary cannot express: remove, duplicate, move,
insert a pattern.

### `GET /block-patterns`
`page` → the active theme's own patterns, with content and a text preview

What **+ Section** offers. Core's bundled patterns and anything the theme
marked `inserter: false` are excluded.

### `GET /lists`
→ the mailing lists at the connected provider

Empty when no provider is configured — which is what the editor's List control
reads to decide whether to offer the choice at all.

### `POST /import-image`
`src` (**required**), `alt` → the new attachment

Copies one image the design points at on somebody else's server into this
site's Media Library. The URL comes from the editor, not from a visitor.

## Native Gutenberg history

Registered by `Clara_VE_Native_History`, and keyed by an `entity` string
rather than a page key, because these versions belong to templates, template
parts, navigation and Global Styles as well as to posts.

### `GET /native/history`
`entity` (**required**) → the versions for that entity

### `GET /native/history/{id}`
`entity` (**required**) → one snapshot, for staging a restore into the editor

### `PATCH /native/history/{id}`
`entity`, `message` (**required**, ≤255) → renames that version

## History

### `GET /history`
`key` → `[{ id, hash, message, kind, isHead, createdAt }]`, newest first,
capped at 300 per key. Seeds the "Original" baseline as a side effect.

### `PATCH /history/{id}`
`key`, `message` (**required**) → `{ renamed: true }`. Empty message restores
the automatic label.

### `POST /history/{id}/restore`
`key` → `{ source, pseudo, history }`

Writes the old content back as live. Records **no** new version — checkout,
not revert.

## Public routes

Four routes have `permission_callback => '__return_true'`. Each is public
because it must be, and each has a specific gate. `POST /form-submit`, the
fourth, is documented above with the other editor routes because it is the
form blocks' own endpoint; the same layers protect it.

### `GET /posts` — blog "load more"
`key`, `page` (**required**, 2–500) → `{ html, has_more }`

Registered **only when the theme has not taken over the public runtime**
(`includes/class-rest.php:197`): a converted theme that ships its own runtime
answers this itself.

**What protects it:** the card template is read from the site's own stored
source, never from the request. The only caller-controlled inputs are a
sanitised key and a bounded page number. It returns already-published content
in markup the site already serves.

### `POST /submit` — form submissions
Free-form. Reserved names: `clara_ve_nonce`, `form_id`, `to`, `redirect`,
`cve_hp`, `cve_ts`, `form_type`, `list_id`.

**What protects it:** five layers — honeypot, an HMAC origin token, a signed
minimum-fill-time stamp, a per-IP rate limit with CDN-aware address
resolution, and optional Akismet. See
[Security](../reference/security.md).

Returns JSON when the request carries `X-Clara-VE-Inline`, otherwise a
redirect.

### `GET /confirm` — mailing-list double opt-in
`id`, `t` (**required**) → always a redirect, never JSON

**What protects it:** a 32-character random token, stored only as a SHA-256
hash and compared in constant time. Every outcome — valid, invalid, already
used, prefetched by a mail client — lands on the same page, so the endpoint
cannot be used to probe whether an address is on a list.

## Notes for integrators

- REST is the only editing interface; there is no admin-ajax path
- Errors are `WP_Error` with meaningful codes (`clara_ve_rate_limited`,
  `clara_ve_stale_form`, `clara_ve_bundle_newer`, …)
- Nothing here is versioned independently of the plugin. The namespace is
  `clara-ve/v1` and has not changed

## Related

- [Security](../reference/security.md)
- [Hooks and filters](hooks-and-filters.md)
- [Architecture](architecture.md)
