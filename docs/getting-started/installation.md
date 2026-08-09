# Installation

Installing the theme and the plugin, in the order that works, and updating
later.

## What you should have

Two separate files:

- **the theme ZIP** — your converted site's design, with its content inside it
- **`visual-edit.zip`** — this plugin

A theme ZIP never contains the plugin. WordPress has no way for a theme to
declare that it needs a plugin, so the two always travel separately and the
theme's setup screen is what connects them.

## Order

**Theme first, then plugin.** Either order technically works, but installing
the theme first means its setup screen is already waiting to walk you through
the rest.

### 1. Install the theme

**Appearance → Themes → Add New → Upload Theme** → choose the theme ZIP →
**Install Now** → **Activate**.

On activation you will see a notice: *"… is installed. One step left before
the site looks like the design."* At this point the site is running the theme
but has none of its content yet, so pages will be missing. That is expected.

### 2. Install the plugin

**Plugins → Add New → Upload Plugin** → choose `visual-edit.zip` →
**Install Now** → **Activate**.

If you got here from the theme's setup screen, its "Upload the plugin ZIP"
button takes you straight to the right place.

### 3. Import the content

The theme's setup screen (**Appearance → Set up …**) now shows step 1 ticked.
Step 2 imports the pages, posts and images that came with the theme.

That step is covered in [First run](first-run.md), because it is worth
understanding what it will and will not touch before you click it.

## Where things appear

After activating the plugin you get a new top-level admin menu, **Visual
Edit**, with:

| Item | What it is |
|---|---|
| Visual Edit | the editor itself |
| Form Submissions | everything visitors have sent |
| Form Settings | recipient, anti-spam, email delivery, mailing list |
| Subscribers | mailing-list signups and their consent record |
| SEO & AI Readiness | the read-only readiness report |
| SEO & Sharing | site-wide identity and sharing defaults |
| Import Content | bring content in from a ZIP |

There is also a **Visual Edit** link in the admin bar on the front end, and a
**Visual Editor** column on the Pages list showing which pages are editable.

## Updating

The plugin is not on wordpress.org, so WordPress will never offer an update
for it. Updating is the same route as installing:

**Plugins → Add New → Upload Plugin** → choose the newer `visual-edit.zip` →
**Install Now** → WordPress notices a copy is already there and asks →
**Replace current with uploaded**.

Your data is untouched by this. Options, page sources, history, submissions
and subscribers all live in the database, not in the plugin folder.

Database schema changes, if a release has any, apply themselves on the next
page load after the upgrade — there is no migration step to run and no
activation to redo.

## Deactivating

Deactivating keeps all your data. Reactivating picks up exactly where you left
off.

One thing changes visibly while it is deactivated: **the front page reverts to
the design the theme shipped with**. Front-page edits live in an option that
the plugin applies at render time, so with the plugin off, nothing applies
them. Every other page keeps its edited content, because those are mirrored
into WordPress's own page storage.

Any dynamic tokens in your content will show as literal text (`[wp-posts]…`)
while the plugin is off, for the same reason — nothing is there to replace
them.

## Uninstalling

Deleting the plugin **always** removes stored credentials (SMTP password, API
keys), scheduled background jobs, and its cached data.

Everything else — form submissions, subscribers, edit history, your edited
page content — is **kept by default**, so that deleting and reinstalling
loses nothing.

If you want a genuinely clean removal, turn on **Form Settings → Uninstall →
"Also delete all stored data"** *before* deleting the plugin. See
[Data and privacy](../reference/data-and-privacy.md) for exactly what each
tier removes.

## Installing manually over SFTP

If the ZIP is larger than your server's upload limit, unzip it locally and
upload the `visual-edit` folder into `wp-content/plugins/`, then activate it
from the Plugins screen. The folder name matters — keep it `visual-edit`.
