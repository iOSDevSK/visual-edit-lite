# Redirects

Keeping old addresses working after a static site becomes a WordPress site.

## Why this exists

A static site had `/about.html`. The WordPress version has `/about/`.

Every inbound link, every search result, every bookmark still points at the old
address — and they all break the moment the site changes over. That happens at
exactly the point where rankings are most fragile.

A perfect meta description on a URL that 404s is worth nothing. This is the
part of a migration that protects the most, and it happens automatically.

## What it does

The conversion records the mapping between old addresses and new pages.
Visiting an old address returns a **301 permanent redirect** to the page that
replaced it.

301 rather than 302, because the move is permanent — that is what tells search
engines to transfer the old URL's accumulated value to the new one.

## Two design decisions worth knowing

### It only acts on 404s

The redirect check runs **only after WordPress has decided it has nothing** at
that address.

This is the whole safety argument. A redirect map that ran earlier could
shadow a page, a post, a feed or an admin screen that works perfectly well.
Gated on 404, it can only ever act where the alternative was an error page.

### It stores pages, not addresses

The map remembers "`/about.html` was replaced by *the about page*", not
"`/about.html` goes to `/about/`".

So if you rename that page's slug next year, the old address still works and
still lands on the right page. A stored URL would have gone stale the moment
you renamed anything, and you would have had to remember to update it.

## Query strings are preserved

`/about.html?utm_source=newsletter` redirects to `/about/?utm_source=newsletter`.

Dropping the query string would turn attributable campaign traffic into
"direct" traffic in your analytics — the visits still arrive, but you lose the
ability to tell where they came from.

## Why not `.htaccess`

Three reasons: it is invisible to the site owner, it does not travel with the
theme when the site moves, and it does not exist at all on nginx.

Handling it in the plugin means the redirects move with the site and survive a
server change.

## Re-running a conversion

If the site is converted again after the static source changes, the new
mapping is **merged** with the existing one. Addresses the first conversion
established keep working.

## Checking it works

```
curl -I https://yoursite.example/about.html
```

You want `HTTP/1.1 301` and a `Location:` header pointing at the new address.

If you get a 404 instead, that address was not in the conversion's mapping.

## An address with nothing behind it

Some old addresses have no equivalent — a `/404.html` page, for instance. Those
fall through to WordPress's real 404 page rather than being redirected
somewhere arbitrary.

## Related

- [SEO](seo.md) — the metadata that also carried over from the original site
- [Import and export](../guide/import-export.md) — the map travels with a
  content package
