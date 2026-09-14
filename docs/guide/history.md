# History

## Gutenberg workspace

Open **↺ History** beside Undo and Redo in the VE toolbar. It docks beside the
page, the way the converted-theme editor's history does. These serve different
purposes: Undo/Redo reverses current editing actions; History keeps saved
versions across editor sessions. This is a local WordPress history, not a
GitHub connection or a log of every click.

Choose the document: the current page/post or template, referenced template
parts, navigation or synced patterns, other edited documents, or Global Styles.
Each document has its own versions with an ID, timestamp, short hash and a
click-to-edit name. The most recent matching saved version is marked Current.

1. Choose a version and click **Restore**.
2. Confirm replacing this document's content in the editor. Unsaved changes
   are explicitly indicated.
3. The toolbar shows **Version restored — Save to keep it** with an **Undo**
   button. Close History to review the restored document. **Undo** returns to the work
   you had immediately before restoring; **Redo** loads the version again.
4. Use the normal **Save** and WordPress publish review to persist the change.

Restore does not itself publish, reload the editor, discard other documents or
delete newer versions. Saving the restored content creates a normal saved
version unless it is already the most recent recorded state. A late response
is ignored after document navigation; concurrent content changes stop restore
and show an error instead of overwriting newer work.

History records successful canonical REST saves, not autosaves or failed saves.
It includes block markup and VE attributes, plus legacy responsive metadata
where available. Global Styles versions contain only their styles/settings.
It does not restore titles, publication status, SEO, arbitrary plugin metadata,
media files or the separately saved VE Google Fonts selection.

The latest ten saves plus the oldest **Original** are available. Up to 300
entries are retained per document, preserving the oldest. Original means the
first captured state, not necessarily the theme's factory design; versions
lost before tracking began cannot be reconstructed. Existing VE page/post
history is reused where present.

Save, restore and re-save were verified on a live WordPress 7.1 block theme:
the document returned byte-for-byte to the version restored.

## Converted HTML-theme editor

The existing HTML editor keeps its original direct-restore behavior described
below. Unlike the Gutenberg workspace, restoring here updates the live source.

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
