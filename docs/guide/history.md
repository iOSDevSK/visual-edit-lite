# History

Every page keeps its own version history. Open it with the **↺** button in the
editor toolbar.

## What you see

A list, newest first:

```
● Save about                    #142 · 5 minutes ago · a3f9c21    [Current]
○ Import: Northfield Studio     #141 · 2 days ago · 8b21e0f       [Restore]
○ Original                      #140 · 2 days ago · 1c4d7a2       [Restore]
```

- The filled dot is the version currently live
- The title is editable — click it and type something meaningful
- The short code is a content fingerprint, useful when two versions look alike
- **Restore** puts that version back

## Where versions come from

- **Every save** you make in the editor
- **Every import** that touched the page, labelled with the theme's name
- **Original** — seeded automatically before your first ever save, holding the
  page exactly as the theme delivered it

That last one means you can always get back to the delivered design, even if
your first edit was months ago.

A save that changes nothing does not create a version.

## What restoring does

Restoring puts the old content back as the live page. It does **not** create a
new version recording the restore, and it does not delete anything after it.

It behaves like checking out an old version rather than undoing forward:
everything stays in the list, and you can move back and forward freely. The
filled dot follows what is actually live, so after restoring an older version
the dot moves to it.

Restoring brings back the page's decorative styling along with its markup, so
a restored page looks the way it did, not the way it does.

## How far back it goes

**The last ten saves, plus the Original.** The Original — the page exactly as
the theme delivered it — is always at the bottom of the list, no matter how
much you have edited since, so there is always a way back to the delivered
design.

Older saves are not deleted, only not listed: the plugin keeps recording up to
300 versions per page in the database. Those rows are your own content in your
own database, and nothing removes them to make a point — they are there for
backups, for WP-CLI, and for whatever you run next.

The cap is per page, not shared across the site — so a page you are working on
heavily cannot push another page's history out.

## Naming versions

The automatic titles are functional ("Save about", "Restore to a3f9c21"). If
you are about to try something significant, rename the version before it —
click the title, type "before rewriting the hero", press Enter.

Clearing the title puts the automatic one back.

## What history does not cover

History is **per page**, and covers the page's markup and its decorative
styling.

It does not version:

- **Settings** — form recipient, email delivery, SEO identity
- **Media** — replacing an image records the change on the page, but the old
  file stays in the Media Library either way
- **Posts** — blog posts use WordPress's own revisions, in the normal editor
- **Menus** — WordPress menus are edited in Appearance → Menus, which has no
  history of its own

## Related

- [The editor](the-editor.md) — how saving works
