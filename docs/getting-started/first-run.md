# First run

Getting the site's content in, making a first edit, and knowing what each
screen is for.

## Import the content

Your theme carries the site's pages, posts and images inside it. Nothing
appears until you import them.

Go to **Appearance → Set up …** (or **Visual Edit → Import Content**) and
choose **Review what this would add**.

### Read the review before applying

The importer shows you everything it found and what it intends to do with each
item, in four categories:

| Status | Meaning |
|---|---|
| **New** | Doesn't exist here yet. Will be added |
| **Identical** | Already here, byte-for-byte the same. Nothing to do |
| **Conflict** | Already here and different. **Will be left alone** |
| **Blocked** | Failed a safety check. Will be skipped, with the reason |

**An import never overwrites.** If you have already edited a page, the
imported version is reported as a conflict and your version stays. This is
stricter than "overwrite but keep a backup" on purpose — "we can undo it
afterwards" is not the same promise as "we did not touch it".

On a fresh site everything will be *new*, and you can apply it without reading
closely. It is on the second and later imports that the review earns its keep.

Then click apply. Images are imported first, then categories, then posts, then
pages, then menus and settings — that order matters, because a page's image
references and a menu's links need their targets to exist first.

## Look at the site

Visit the front end. It should now look exactly like the design it was
converted from.

If something is visibly wrong at this point — missing styles, a doubled
header, a broken layout — that is a conversion problem rather than an editing
one. See [Troubleshooting](../reference/troubleshooting.md).

## Make a first edit

1. Open **Visual Edit** from the admin menu (or the admin bar on any page).
2. Pick a page from the dropdown at the top left.
3. Click the **pencil** button to turn edit mode on. Editable elements get a
   faint dashed outline.
4. Click a piece of text. A panel opens on the right, and a caret appears in
   the text itself.
5. Type. Press **Enter** to commit, **Escape** to cancel.
6. Click **Save**.

Nothing is written until you press Save. The counter next to it tells you how
many unsaved changes are queued.

That is the whole loop. [The editor](../guide/the-editor.md) covers the rest —
images, links, styling, device widths, and everything the panel offers per
element type.

## Undoing

Every save is a version. **History** (the ↺ button in the toolbar) lists them
per page, newest first, and any of them can be restored. The first entry is
labelled *Original* and is the page as the theme shipped it, so you can always
get back to the delivered design.

See [History](../guide/history.md).

## What to set up next

None of these are required to edit, but each one is a thing that quietly does
not work until you do it.

### If the site has a contact form

A designed form does nothing until it is connected — submitting it just
reloads the page.

1. In the editor, click the form.
2. In the **FORM** section of the panel, set **Does** to *Contact form*.
3. Set where replies should go, and which page to show after submitting.
4. Save.

Then set up email delivery, or the notifications will land in spam. See
[Forms](../guide/forms.md) and
[Email delivery](../guide/email-delivery.md) — the second one matters more
than it sounds and is not something the plugin can solve on its own.

### If the site should be found in search

Open **Visual Edit → SEO & Sharing** and set the business or person name, what
kind of thing it is, and a logo. That is what search engines and AI assistants
quote when they describe the site.

Then check **SEO & AI Readiness**, which lists problems it found — missing
descriptions, heading structure, images without alt text. It only reports;
it never changes a page.

See [SEO](../seo-and-ai/seo.md) and
[AI readiness](../seo-and-ai/ai-readiness.md).

## The screens, in one table

| Screen | Use it for |
|---|---|
| **Visual Edit** | Editing pages. The main event |
| **Form Submissions** | Reading what visitors sent |
| **Form Settings** | Recipient, anti-spam, email delivery, mailing list, uninstall behaviour |
| **Subscribers** | Mailing-list signups and their consent record |
| **SEO & Sharing** | Site-wide identity: name, type, logo, social profiles |
| **SEO & AI Readiness** | The read-only report of what needs attention |
| **Import Content** | Bringing content in from a ZIP |
