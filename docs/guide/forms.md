# Forms

Connecting a designed form so it actually does something, what happens to a
submission, and every layer that stands between you and spam.

## The idea

Your designer drew a form. It has the right fields, the right spacing, the
right button. Visual Edit connects **that form** — it does not rebuild it in a
form plugin's own markup and then ask you to re-style the result.

A form that has not been connected does nothing. Submitting it reloads the
page and no data goes anywhere. There is no error, because nothing is wrong —
it is just an unconnected HTML form.

## Connecting a form

1. Open the page in the editor, edit mode on.
2. Click the form.
3. In the **FORM** section of the panel, set **Does**:

| Setting | Meaning |
|---|---|
| **Nothing (not connected)** | The default. Submitting does nothing |
| **Contact form** | Store the submission and email you |
| **Mailing list** | Add the address to a mailing list |

4. Fill in the rest, and **Save**.

### Contact form settings

- **Send to** — where notifications go for this form. Leave blank to use the
  site-wide address, which is shown as the placeholder so an empty box does
  not read as "goes nowhere".
- **Then go to** — the page shown after submitting. This is a **page picker**,
  not a text field, with a "Somewhere else…" escape hatch for an external URL.
  It is a picker because the first version was a text field and forms shipped
  pointing at thank-you pages that did not exist.

### Mailing list settings

- **List** — picked from your provider's actual lists, showing each one's
  name and subscriber count. Never a numeric ID typed by hand.

See [Mailing lists](mailing-lists.md).

## Adding or changing fields

Fields are ordinary HTML, edited like any other page content. Add an input to
the form's markup, save, and it starts being captured on the next submission.
There is no field configuration to keep in step and nothing to reconnect.

Every field with a `name` attribute is captured. That is the only requirement.

### Fields that accept more than one value

A group of checkboxes, or a `<select multiple>`, needs **one shared name
ending in `[]`**:

```html
<input type="checkbox" name="interests[]" value="strategy"> Strategy
<input type="checkbox" name="interests[]" value="coaching"> Coaching
<input type="checkbox" name="interests[]" value="content"> Content
```

Without the `[]`, browsers send only the last checked value and the rest are
lost. With it, every checked value is captured and stored together.

### One thing HTML does that surprises people

**An unchecked checkbox is not submitted at all.** It does not arrive as
"off" or "false" — it simply is not there. So a submission where the visitor
checked nothing will have no entry for that field. That is standard browser
behaviour, not something the plugin decides.

## What happens to a submission

In this order:

1. It is **stored** in WordPress, under **Visual Edit → Form Submissions**
2. Then it is **emailed** to you

Storage first, deliberately. If email delivery fails — wrong SMTP password,
provider outage, a spam filter — the enquiry is still on the site and the
entry is flagged to say the email did not get through. A lead is never lost to
a mail problem.

Each submission is one record: every field the visitor filled, the IP address,
and flags for spam or delivery failure. The title is the form's name and the
timestamp.

### The visitor gets a reply too

An automatic confirmation ("Thanks — we got your message") goes to the visitor
when their address can be identified from the submission.

### Your notification email

- **From** the site's own name and address, so it passes sender checks
- **Reply-To** the visitor's address, so replying from your inbox reaches them
  directly

## Anti-spam

Five layers, in the order a submission meets them.

### 1. Honeypot

A field visitors never see and bots fill in. Anything that fills it gets a
success response and is silently dropped. Success rather than an error, so a
bot learns nothing about why it failed.

### 2. Origin check

Confirms the submission came from a page this site actually served, rather
than a script posting directly.

If someone leaves a page open for a very long time, the check expires and they
get *"This page has been open too long — please reload it"* rather than a
security error, because that is what actually happened.

> **Why not a standard WordPress nonce?** Because it broke for the one person
> guaranteed to test the form first: the site owner. A WordPress nonce is tied
> to the logged-in user, but WordPress deliberately treats a form posted from
> the front end without a special header as anonymous. So the owner's own form
> submissions failed with "Security check failed" while logged-out visitors
> were fine. The check used now is tied to the site rather than the user.

### 3. Minimum fill time

A submission that arrives faster than a human could type is dropped. Default
**3 seconds**; adjustable in Form Settings; 0 disables it.

Cheap and effective, since bots submit instantly.

### 4. Rate limit

One submission per IP per **60 seconds**.

This works correctly behind Cloudflare. The visitor's real IP is read from
Cloudflare's own header, but **only** when the request genuinely came from
Cloudflare's network — verified against their published address ranges.

> Trusting that header unconditionally is a real, exploitable mistake: anyone
> can set it, and three submissions succeeded in a window that allows one
> before the range check was added.

### 5. Akismet (optional)

If you have the Akismet plugin with a key, submissions are classified.

**Spam is still stored**, flagged rather than discarded, and simply not
emailed. The sender still sees success. Nothing is thrown away on a
classifier's say-so — false positives happen, and a lost enquiry you never
knew about is worse than one sitting in a list marked "spam".

## Consent line (GDPR)

Off by default. Turn it on in Form Settings to show a note under every
connected form, with your own wording, and a link to your privacy policy if
you want one.

Off by default because plenty of forms — an internal enquiry, a site outside
the EU — do not need it, and a note nobody chose is a note nobody maintains.

## Reading submissions

**Visual Edit → Form Submissions.** Each entry opens to show every field, with
the IP address and any flags.

They are stored indefinitely. There is no automatic cleanup — deleting old
submissions is a decision, not a default.

## If forms are not working

See [Troubleshooting](../reference/troubleshooting.md).

## Related

- [Email delivery](email-delivery.md) — **read this**, or notifications land
  in spam
- [Mailing lists](mailing-lists.md) — signup forms and double opt-in
- [Data and privacy](../reference/data-and-privacy.md) — what is stored and
  for how long
