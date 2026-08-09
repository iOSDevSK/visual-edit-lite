# Import

Bringing a converted site's content into WordPress.

## Import

**Visual Edit Lite → Import Content.**

Two ways in:

- **Content that came with this theme** — appears when the active theme ships
  content, which is the normal path after installing a converted theme
- **Import from a ZIP** — any bundle in the plugin's export shape

Only the content is read in both cases. **Importing never installs or changes
a theme.**

### It reviews first

Import is two steps: review, then apply. The review lists everything found and
what will happen to it.

| Status | Meaning |
|---|---|
| **New** | Doesn't exist here. Will be added |
| **Identical** | Already here and the same. Nothing to do |
| **Conflict** | Already here and different. **Left alone** |
| **Blocked** | Failed a safety check. Skipped, with the reason |

### It never overwrites

If you have edited a page, the imported version is reported as a conflict and
**your version stays**.

This is deliberately stricter than "overwrite but keep a backup". This
importer was removed from the plugin once, because a non-technical owner could
reach it and destroy their own work — *"we can undo it afterwards"* is not the
same promise as *"we did not touch it"*.

A conflict is telling you something: the imported version and yours have
diverged. Deciding what to do is your call, and you make it with both versions
still intact.

### The order things are applied

Images, then categories, then posts, then pages, then menus, then settings.
That order is load-bearing — a page's image references need the images to
exist, and a menu's links are rebuilt from permalinks that only exist once the
pages do.

### Limits

A bundle is refused rather than half-unpacked if it exceeds: 500 pages, 2000
posts, 5000 media files, 20000 submissions or 20000 subscribers.

### Version compatibility

A content package made by a **newer** version of the plugin is refused with
"Update Visual Edit and try again", rather than being partly understood.

Older packages are read fine — anything a newer version added is simply absent
and treated as empty.

### One nicety worth knowing

A fresh WordPress ships a draft "Privacy Policy" and a "Sample Page". If your
design has its own privacy policy, WordPress would normally park yours at
`/privacy-policy-2/` while every link in the design points at
`/privacy-policy/`.

The importer notices, and takes over WordPress's untouched placeholder so your
page gets the right address. It only does this when the placeholder is
genuinely untouched scaffolding — a page you have written is never taken over.

---

## Moving a site: the short version

1. On the new site: install the theme, install the plugin
2. **Import Content**, review, apply
3. Re-enter credentials — they deliberately do not travel

Producing the content bundle in the first place is the converter's job (or
Visual Edit Pro's theme export); Lite reads bundles, it does not write them.

## Related

- [First run](../getting-started/first-run.md) — the first import
- [Security](../reference/security.md) — what is redacted and how
- [Bundle format](../developer/bundle-format.md) — the package specification
