# Hooks and filters

What the plugin exposes for you, and every WordPress hook it attaches to.

## What the plugin fires

Three. They exist for real reasons rather than as a speculative API.

### `clara_ve_source_saved` (action)

```php
do_action( 'clara_ve_source_saved', string $key, string $source );
```

Fired after a page's markup is successfully saved — from the editor or an
import — once the option is written and the render target is synced.
`$source` carries absolute theme URIs.

**Use it to invalidate anything derived from page content.** It exists because
there was no way to know: extracted FAQs, the readiness report, any cache were
correct at conversion and had no signal that the owner had since rewritten the
page — which turned live features into snapshots of the day they were
installed.

```php
add_action( 'clara_ve_source_saved', function ( $key, $source ) {
    delete_transient( 'my_plugin_summary_' . $key );
}, 10, 2 );
```

### `clara_ve_content_imported` (action)

```php
do_action( 'clara_ve_content_imported' );
```

Fired at the end of an import run, before the redirect.

**Use it to dismiss a theme's setup notice.** It exists so the plugin does not
reach into a theme's option namespace:

```php
add_action( 'clara_ve_content_imported', function () {
    update_option( 'my_theme_setup_dismissed', '1' );
} );
```

### `clara_ve_trusted_proxies` (filter)

```php
apply_filters( 'clara_ve_trusted_proxies', array $cidr_ranges );
```

The CIDR ranges whose `CF-Connecting-IP` header is believed when resolving a
form submitter's IP. Defaults to Cloudflare's published ranges.

**Use it if you sit behind a different CDN.**

```php
add_filter( 'clara_ve_trusted_proxies', function ( $ranges ) {
    $ranges[] = '203.0.113.0/24';
    return $ranges;
} );
```

> **Anything in this list can set any visitor's apparent IP.** Add only ranges
> you control or trust completely. Trusting a forwarded header unconditionally
> is exploitable, and was measurably so before the range check existed.

## WordPress hooks the plugin attaches to

Relevant if you are debugging an interaction with another plugin.

### Rendering

| Hook | Priority | Purpose |
|---|---|---|
| `render_block_core/html` | 8 | Strip the article styling specimen outside the editor |
| `render_block_core/html` | 9 | Swap hardcoded nav for the WordPress menu |
| `render_block_core/html` | 10 | Hydrate `[wp-posts]` / `[wp-form]` / `[wp-menu]` / `[wp-article]` |
| `init` | 20 | Re-register the front-page pattern with stored content |
| `wp_head` | 60, 61 | Decorative styling layer, article specimen CSS |

All three block filters bail immediately on any block lacking the
`clara-ve-key` marker.

### Admin takeover

| Hook | Purpose |
|---|---|
| `replace_editor` | Redirect a tagged Page's edit screen into the Visual Editor. Bypass with `?clara_ve_bypass=1` |
| `page_row_actions`, `manage_pages_columns`, `manage_pages_custom_column` | The "Visual Editor" column and the bypass link |
| `show_admin_bar` | Suppressed inside the edit preview at `template_redirect` priority −1, so core's `html { margin-top: 32px }` never registers |

### SEO

| Hook | Priority | |
|---|---|---|
| `wp_head` | 0 | Remove the duplicate block-theme `<title>` when an SEO plugin is active |
| `wp_head` | 1 | Emit meta tags — only when no SEO plugin is present |
| `pre_get_document_title` | 10 | The page title |
| `wp_robots` | 10 | Robots directives, through core's own mechanism rather than a second meta tag |
| `admin_init` | 10 | Back-fill records into a newly installed SEO plugin |

Every one is registered unconditionally and re-checks for a host plugin at
render time — plugins load alphabetically, so deciding at startup would happen
before Yoast exists.

### Structured data and llms.txt

| Hook | |
|---|---|
| `init` | Rewrite rule for `/llms.txt` |
| `template_redirect` (priority 1) | Serve `/llms.txt` before core's canonical redirect can add a trailing slash |
| `redirect_canonical` | Second guard against the same redirect |
| `robots_txt` | AI-crawler rules |
| `wp_head` (priority 2) | Emit the schema.org graph — only with no SEO plugin |
| `wpseo_schema_graph` | Add pieces to Yoast's graph |
| `rank_math/json_ld` | Add pieces to Rank Math's graph |
| `wp_sitemaps_posts_query_args`, `wp_sitemaps_add_provider` | Drop hidden pages from core's sitemap |

### Mail

| Hook | |
|---|---|
| `phpmailer_init` | Configure SMTP, only when an SMTP mailer is selected |
| `pre_wp_mail` | Send via a provider HTTP API |

`pre_wp_mail` is cooperative: if another handler has already claimed the mail,
this one returns without touching it. A separate SMTP plugin can coexist.

### Other

| Hook | |
|---|---|
| `template_redirect` | Redirect map, **gated on `is_404()`** |
| `register_activation_hook` | Flush rewrite rules; seed a menu only if the theme ships no content |
| `register_deactivation_hook` | Flush rewrite rules. Touches no data |

### One compatibility hazard

`post_updated` / `wp_save_post_revision` is **temporarily removed and
re-added** around plugin-driven writes to a page's content, so the plugin's own
history does not also fill WordPress's revisions with internal churn.

If you hook `post_updated` and expect it on every content change, you will not
see plugin-driven ones. Use `clara_ve_source_saved` instead.

## Related

- [REST API](rest-api.md)
- [Extending](extending.md) — what these hooks do and do not let you do
