# Troubleshooting

Symptoms and their causes. Most of these are real problems that were hit
during development.

## The editor

### The page loads but nothing is clickable

The editor could not find the region it is allowed to edit.

- **On a converted theme:** you may have followed a link to a page the theme
  does not own. Use the page dropdown to get back
- **On any other theme:** expected. The editing canvas needs a converted
  theme. See [Requirements](../getting-started/requirements.md)

### Edit mode keeps turning itself off

It is not. Edit mode starts **off** every time the editor loads — including
after a save that reloaded the canvas. That is deliberate, so a fresh load
never puts you in a state where a stray click changes something.

### I saved and nothing changed

Check whether the save actually completed — the status text says "Saved ✓".

If it said saved and the page is unchanged, the likely cause is the
capability: without `unfiltered_html` the stored source is written but the
mirror to the page WordPress renders is silently skipped. Common on
**multisite**, where site administrators do not have that capability.

Also common when scripting: `wp eval` runs with no user. Use `wp --user=1`.

### The panel shows "managed by WordPress" instead of letting me type

You clicked a generated region — a blog listing, a connected form, a WordPress
menu. Its content comes from live data, so typing into the output would be
overwritten on the next render. The panel links to the screen that owns it.

### An FAQ stopped opening after I edited it

Reload the page. If it works after a reload but not before, that is a bug
worth reporting — the editor is supposed to preserve the page's own scripts
when it rearranges a list, and reloads deliberately when it cannot.

### Save is refused with "missing an expected section"

The markup no longer contains a structural anchor for that page kind — you
deleted something load-bearing. The message names what is missing.

Restore the previous version from [History](../guide/history.md), or put the
section back. For the article template, this most often means the styling
specimen was deleted.

### The front page reverted to the original design

The plugin is deactivated. Front-page edits live in an option applied at
render time, so with the plugin off nothing applies them. Reactivating brings
them back.

### `[wp-posts]` appears as literal text on the site

Same cause: the plugin is deactivated, so nothing replaces the token.

## Forms

### Submitting reloads the page and nothing happens

The form is not connected. Click it in the editor and set **Does** to
*Contact form*. See [Forms](../guide/forms.md).

### "Security check failed"

If this happens to **you while logged in**, that is the bug the origin token
was written to fix — it should not occur in current versions. Report it.

If it happens to visitors, they most likely had the page open for a very long
time. The current message for that case is *"This page has been open too long
— please reload it"*.

### Submissions arrive but no email does

The submission is stored first and emailed second, deliberately, so this means
the form works and delivery does not.

1. Look for a delivery-failure flag on the entry
2. Send a test email from Form Settings
3. Check the spam folder
4. Confirm your DNS records match your configured provider
5. If on SMTP with no response at all, try the provider's API option — many
   hosts block SMTP ports

See [Email delivery](../guide/email-delivery.md).

### Email lands in spam

Almost always missing DNS records. Sending through a provider without adding
their SPF and DKIM records is only a modest improvement.

### A checkbox group only saves one value

The checkboxes need **one shared name ending in `[]`**:

```html
<input type="checkbox" name="interests[]" value="a">
<input type="checkbox" name="interests[]" value="b">
```

Without the `[]` the browser sends only the last checked value.

### A checkbox the visitor left unchecked is missing entirely

Standard browser behaviour — an unchecked checkbox is not submitted at all,
not as "off" or "false". Nothing to fix.

### Genuine submissions are being rejected

- **Minimum fill time** too high — lower it or set it to 0
- **Rate limit** — one per IP per 60 seconds, which two people behind one
  office IP can hit

## Search and structured data

### An FAQ is not being published as structured data

Three possible causes:

1. **Fewer than two questions.** The minimum is two, deliberately
2. **The markup is not a recognised shape.** `<details><summary>`,
   `<dt>/<dd>`, or a heading ending in `?` followed by paragraphs. A `<div>`
   with a class named "faq-question" renders perfectly and is invisible here
3. **Answers under 20 characters** are dropped

See [AI readiness](../seo-and-ai/ai-readiness.md).

### Two titles in the page source

With Yoast on a block theme, WordPress emits two `<title>` tags and Yoast only
removes one. This plugin removes the leftover.

If you see two anyway, deactivate this plugin and check again — if they are
still there, something else is emitting one.

### Yoast shows my values but the live page does not

Yoast serves its output from its own index, rebuilt when posts are saved
rather than when meta is written. Run **SEO → Tools → Optimize SEO Data**.

The plugin shows a notice saying exactly this after copying values into a
newly installed Yoast.

### `/llms.txt` returns 404

The rewrite rules are stale. Go to **Settings → Permalinks** and press Save —
that regenerates them without changing anything.

### AI crawler rules are missing from robots.txt

If you use **Rank Math**, it replaces `robots.txt` with its own editor, so
these lines never appear. The readiness report detects this and shows you the
lines to paste.

### The readiness report shows a stale count

Cached for 15 minutes. Press **Re-check**.

## Import

### "This is a built static site, not a content bundle"

You uploaded a ZIP of raw HTML files rather than a content bundle. Import the
bundle the converted theme ships with, or a package exported from a WordPress
install that already has the site.

If you are re-running a conversion deliberately, see
[Constants](../developer/constants.md).

### Import says "conflict" and skipped my pages

Working as intended — those pages exist here and differ, so **your** versions
were left alone. Nothing was lost. Compare and decide.

### "Made by a newer version of the plugin"

The package came from a newer Visual Edit. Update this install.

### Export fails mentioning a secret

The safety check found something credential-shaped in the output. Deliberate:
it fails loudly rather than filtering quietly. Check whether an API key was
pasted into the wrong settings field.

### Export blocked: no screenshot

The theme has no `screenshot.png`, and WordPress would show it as a blank grey
tile. The screen gives you the command to generate one.

## Reporting a bug

[github.com/iOSDevSK/visual-edit/issues](https://github.com/iOSDevSK/visual-edit/issues)

Useful to include: WordPress and PHP versions, whether it is multisite,
whether Yoast or Rank Math is installed, what you did, what happened, and
anything in the browser console.
