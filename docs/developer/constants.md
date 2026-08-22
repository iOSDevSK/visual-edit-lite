# Constants

What can be set in `wp-config.php`, and what is defined internally.

## Settable

### `CLARA_VE_ALLOW_STATIC_IMPORT`

```php
define( 'CLARA_VE_ALLOW_STATIC_IMPORT', true );
```

Enables importing a ZIP of flat built HTML — the conversion pipeline's own
format, one `.html` file per page.

**Default: off, and there is no UI to turn it on.**

That import applies immediately and overwrites whatever it matches. Every
overwrite is recoverable from History, but it does not ask first — which makes
it the pipeline's business and nobody else's. It used to sit behind an
"Advanced" disclosure on the Import screen; a disclosure is still a door, and
a door on the screen a site owner opens looking for their content is a door
that eventually gets opened.

A constant rather than a hidden form field, because a field only hides the
door. The constant means the door is not built.

**Use it when** re-running a conversion against a changed static source on
your own working copy. Remove the line afterwards, and never add it to a site
you have handed over.

Without it, uploading a static-site ZIP gives:

> This is a built static site, not a content bundle. Export the site from a
> WordPress install that already has it, and import that ZIP instead.

## Detected, not settable

Presence probes for other plugins:

| Constant | Set by |
|---|---|
| `WPSEO_VERSION` | Yoast SEO |
| `RANK_MATH_VERSION`, `RANK_MATH_FILE` | Rank Math |

Detection is by constant rather than by checking the active-plugins list,
because the constant is what tells you the code is actually loaded.

## Defined internally

These are set by the plugin and are **not** overridable — they are not wrapped
in `defined()` checks. Listed so you know they exist and do not try:

`CLARA_VE_VERSION` · `CLARA_VE_DIR` · `CLARA_VE_URL` ·
`CLARA_VE_PATTERN_SLUG` · `CLARA_VE_OPTION` · `CLARA_VE_PSEUDO_OPTION` ·
`CLARA_VE_THEME_URI_TOKEN` · `CLARA_VE_NAV_LOCATION` ·
`CLARA_VE_DEFAULT_KEY` · `CLARA_VE_PAGE_KEY_META` · `CLARA_VE_HEADER_KEY` ·
`CLARA_VE_FOOTER_KEY` · `CLARA_VE_ARTICLE_KEY` · `CLARA_VE_404_KEY` ·
`CLARA_VE_SPECIMEN_START` · `CLARA_VE_SPECIMEN_END`

Making any of them configurable would mean adding a `defined()` wrapper first.

## Limits, for reference

Not constants you can set, but numbers worth knowing:

| | |
|---|---|
| History entries per page | 300 |
| Google font families | 5 |
| Collection editor fields | 8 |
| `llms.txt` entries per section | 200 |
| Readiness report posts | 200 |
| Form rate limit | 1 per IP per 60 s |
| Unconfirmed subscriber retention | 14 days |

## Related

- [Import](../guide/import-export.md)
- [Data model](data-model.md)
