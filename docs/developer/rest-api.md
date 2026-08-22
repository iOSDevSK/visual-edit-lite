# REST API

Every route the plugin registers, under the namespace `clara-ve/v1`.

Unless stated otherwise, the permission callback is:

```php
current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' )
```

`key` defaults to `front-page` everywhere it appears.

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
`originalTitle`, `originalUrl`, `title`, `url` (**required**), `blank` →
`{ updated: true }`

Matches the item by its current title and URL. Front-page nav location only.

### `GET|POST /google-fonts`
GET → `{ catalog, selected, max }`
POST `families` (**required**) → `{ selected, cssUrl }`, or an error over the
5-family cap.

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

Three routes have `permission_callback => '__return_true'`. Each is public
because it must be, and each has a specific gate.

### `GET /posts` — blog "load more"
`key`, `page` (**required**, 2–500) → `{ html, has_more }`

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
