# Data model

Everything the plugin stores.

Every name is prefixed `clara_ve_` — the plugin's original internal prefix,
kept after the rename to Visual Edit because changing it would orphan every
existing install from its data and invalidate every exported content package.

## Custom tables

### `{prefix}clara_ve_history`

Per-page version history. Schema version 2, installed idempotently on `init`.

| Column | Type | |
|---|---|---|
| `id` | BIGINT UNSIGNED | |
| `page_key` | VARCHAR(191) | Which page. Indexed |
| `content` | LONGBLOB | The markup, gzip-compressed |
| `content_hash` | CHAR(64) | SHA-256. Used for no-op detection and HEAD marking |
| `pseudo` | LONGTEXT | Decorative styling map as JSON |
| `message` | VARCHAR(255) | Custom label, or NULL for automatic |
| `kind` | VARCHAR(20) | `save` or `restore` |
| `restored_from_id` | BIGINT UNSIGNED | |
| `author` | BIGINT UNSIGNED | |
| `created_at` | DATETIME | Indexed |

**Retention: 300 entries per `page_key`**, oldest pruned. Per key rather than
globally, so an actively-edited page cannot evict a quiet page's history.

Append-only. A restore writes the old content back as live but records no row;
HEAD is derived by comparing hashes against the live source, not by row order.

### `{prefix}clara_ve_optins`

Mailing-list signups and their consent record. Schema version 1.

| Column | Type | |
|---|---|---|
| `id` | bigint(20) unsigned | |
| `email` | varchar(191) | Indexed |
| `theme` | varchar(191) | Which theme was active at signup. Empty on rows written before schema 2 |
| `list_id` | varchar(64) | |
| `form_id` | varchar(64) | |
| `status` | varchar(20) | `pending` or `confirmed` |
| `token_hash` | char(64) | SHA-256 of the confirmation token. The token itself is never stored |
| `consent_text` | text | **The exact wording the person agreed to** |
| `ip` | varchar(100) | |
| `created_at` | datetime | |
| `confirmed_at` | datetime | |

**Retention:** pending rows swept after 14 days. Confirmed rows never — they
are the consent evidence.

## Options

### Per-page rows

Two families, one row each per edited page **per theme**:

- `clara_ve_source__{theme}__{key}` — the page's markup
- `clara_ve_pseudo__{theme}__{key}` — decorative styling

The theme segment arrived in 1.16.0, when two converted themes became able to
share one install. The unscoped `clara_ve_source__{key}` form is still READ as
a fallback for installs that predate it, gated on the ownership guard.

The front page keeps the older names `clara_ve_front_source` and
`clara_ve_pseudo_css`, byte-for-byte, from before multi-page support.

Both are written with `autoload = false`. Neither can be enumerated from a
fixed list, which is why uninstall sweeps by prefix.

### Settings, grouped

**Theme lifecycle** — `clara_ve_themes` (one record per theme this plugin has
content for: its name, keys, navigation zones, the redirects it established,
whether it is currently parked, and the site options as they were before its
import — the only thing that survives the theme's directory being deleted),
`clara_ve_theme_registry_backfill`, `clara_ve_legacy_media_stamp_version`

**State** — `clara_ve_redirects`, `clara_ve_history_db_version`,
`clara_ve_optin_db_version`, `clara_ve_ai_active_jobs`,
`clara_ve_form_secrets_repaired`, `clara_ve_seo_synced_host`,
`clara_ve_seo_reindex_due`

**Design** — `clara_ve_google_fonts`

**Forms** — `clara_ve_form_to`, `_from_name`, `_from_email`, `_min_seconds`,
`_akismet`, `_consent`, `_consent_text`, `clara_ve_remove_all_data`

**Mail** — `clara_ve_mailer`, `clara_ve_smtp_host`, `_port`, `_encryption`,
`_auth`, `_user`, `_pass`, `clara_ve_api_brevo`, `_sendgrid`, `_postmark`,
`_mailgun`, `clara_ve_mailgun_domain`, `_region`

**Lists and opt-in** — `clara_ve_list_provider`, `_doi_template`,
`_doi_redirect`, `clara_ve_optin_mode`, `_confirm_subject`, `_confirm_body`,
`_deliver_subject`, `_deliver_body`, `_deliver_file`

**SEO and structured data** — `clara_ve_seo_entity_type`, `_entity_name`,
`_entity_logo`, `_entity_extra`, `clara_ve_seo_same_as`,
`_default_og_image`, `_title_separator`, `clara_ve_geo_ai_crawlers`

## Custom post type

### `clara_ve_submission`

Form submissions. `public => false`, `show_ui => true`, nested under the
Visual Edit menu, supports `title` and `custom-fields`.

Title: `{form_id} — {mysql datetime}`.

| Meta | |
|---|---|
| *one per submitted field* | Key is `sanitize_key(field name)`, value `sanitize_textarea_field`'d. Flat arrays (`name[]`) joined with `", "`; nested arrays skipped |
| `_clara_ve_form_ip` | Resolved client IP |
| `_clara_ve_spam` | `'1'` or `''` |
| `_clara_ve_mail_failed` | `'1'` when the notification failed |
| `_clara_ve_list_error` | Provider error message |

The leading-underscore convention is load-bearing: the exporter treats
underscore-prefixed keys as bookkeeping and everything else as a
visitor-typed field.

## Post meta

| Key | On | |
|---|---|---|
| `_clara_ve_key` | pages | The visual-edit key. Registered with `show_in_rest => false` — internal |
| `_clara_ve_theme` | pages, posts, attachments, submissions | Which theme's tenure created it. Also a TERM meta, on categories, tags and nav menus. Absent means nobody's — the owner's own, or content predating the stamp — and that is what keeps it alive when a theme leaves |
| `_clara_ve_status` | pages, posts | The status held before the theme was parked, so restoring puts back what was there rather than assuming published |
| `_clara_ve_slug` | pages, posts | The address held before parking. Present only while parked |
| `_clara_ve_parked` | attachments | Names the parked theme. Attachments keep `inherit`, which WordPress relies on, so they are hidden by query filters rather than by status |
| `_clara_ve_seo` | pages, posts | The whole SEO record as one array: title, description, canonical, noindex, og, twitter, jsonld |
| `_clara_ve_noindex` | pages, posts | A denormalised mirror of the noindex flag, existing **only** so it can be queried — no meta query can look inside a serialised array, and "every page the owner hid" is exactly what the sitemap and llms.txt need to ask |

The SEO record is deliberately one array written and read as a unit, so it
cannot half-update.

When Yoast or Rank Math is installed, values are also mirrored into their
meta keys — 11 each, mapped as data rather than a branch per field.

## Transients

All prefixed `clara_ve_`, which is what lets uninstall sweep them in one
query.

| Key | TTL | |
|---|---|---|
| `clara_ve_article_css` | 1 week | Compiled specimen CSS. One row carrying its own hash, not hash-keyed |
| `clara_ve_google_font_catalog` | 1 week | ~2.7 MB fetched once, trimmed to three fields |
| `clara_ve_lists_brevo` | 5 min | Mailing lists |
| `clara_ve_geo_audit` | 15 min | Readiness report |
| `clara_ve_geo_faq_{hash}` | 1 week | Extracted Q&A, **keyed by page with the content hash inside the value** |
| `clara_ve_import_plan_{id}` | 30 min | A reviewed import plan |
| `clara_ve_import_scrap_{id}` | 30 min | Scratch directory |
| `clara_ve_import_report_{user}` | 1 min | Result summary |
| `clara_ve_form_rl_{md5 ip}` | 60 s | Rate limit |

> The FAQ cache is keyed **by page** with the hash inside the value, rather
> than by hash. Keying by hash left roughly forty orphan rows in `wp_options`
> after a single editing session, since every edit produced a new key and
> nothing removed the old one.

## Secret storage

Five options hold credentials:

`clara_ve_smtp_pass` · `clara_ve_api_brevo` · `_sendgrid` · `_postmark` ·
`_mailgun`

Encryption:

```
key    = hash('sha256', wp_salt('auth'), true)
iv     = openssl_random_pseudo_bytes(16)
cipher = openssl_encrypt($plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv)
stored = base64_encode($iv . $cipher)
```

**What this protects, precisely:** a database-only exposure — a leaked backup,
read-only database access, another plugin dumping the options table.

**What it does not:** an attacker with both filesystem and database access.
The key derives from `wp-config.php` salts, so anyone holding both has
everything.

No key rotation, no versioning, and CBC without a MAC. Stated plainly because
a security claim that overreaches is worse than a modest one.

Two implementation details worth knowing if you touch this:

- The sanitiser detects its own ciphertext by trying to decrypt it, because
  WordPress runs a sanitiser **twice** on a first-ever save (`update_option`
  then `add_option`), which double-encrypted every secret before the guard
  existed
- A one-shot repair migrates values written before that fix

The same eight names are listed independently in three places — the export
allowlist, the settings class, and `uninstall.php` (which deliberately does
not load plugin classes). Redundant on purpose.

## Related

- [Bundle format](bundle-format.md) — what travels in an export
- [Data and privacy](../reference/data-and-privacy.md) — retention, deletion
- [Security](../reference/security.md)
