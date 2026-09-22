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
| **Contact Form 7** | Hand the submission to a Contact Form 7 form. Offered only while Contact Form 7 is active |

4. Fill in the rest, and **Save**.

### Contact form settings

- **Send to** — where notifications go for this form. Leave blank to use the
  site-wide address, which is shown as the placeholder so an empty box does
  not read as "goes nowhere".

  Two limits, both deliberate. **Send to** and **List** are honoured only on a
  page whose author administers the site — otherwise anyone who can publish
  could point a form at themselves, or at the owner's mailing list. And on a
  theme converted from HTML they currently fall back to the site-wide address
  either way, until the converter signs the choice into the page it generates.
  In both cases the form still works; it is the address that falls back.
- **Then go to** — the page shown after submitting. This is a **page picker**,
  not a text field, with a "Somewhere else…" escape hatch for an external URL.
  It is a picker because the first version was a text field and forms shipped
  pointing at thank-you pages that did not exist.

### Mailing list settings

- **List** — picked from your provider's actual lists, showing each one's
  name and subscriber count. Never a numeric ID typed by hand.

See [Mailing lists](mailing-lists.md).

### Contact Form 7 settings

Your form keeps its design, and Contact Form 7 does the processing behind it:
its validation, its spam checks (Akismet, the disallowed list, reCAPTCHA v3),
its mail — with the recipient, subject and body you set in Contact Form 7 —
and, with Flamingo installed, its record of the message. Visual Edit stores
nothing of its own for such a form and sends no mail of its own.

- **Form** — which Contact Form 7 form processes the submissions. Create it in
  Contact Form 7 first, with fields for what your form collects.
- **Sends as** — one line per field of your form, saying which Contact Form 7
  field it fills. Matched automatically when you pick the form: the same name
  (ignoring case, punctuation and Contact Form 7's `your-` prefix), then the
  only field of the same kind (email, message, phone…), then the same label,
  then a name that contains the other. Change any line, or set it to
  **Don't send**.
- A field the Contact Form 7 form **requires** that none of yours fills is
  named under the list. Until one does, Contact Form 7 refuses every
  submission.

What the visitor sees: Contact Form 7's reason under the field it is about
("Please enter an email address."), in the design's own error style when the
design has one, and Contact Form 7's own message after a successful send.
The design's own validation, if it has any, still runs first.

If Contact Form 7 is deactivated, or the form you picked is deleted, the form
behaves as not connected: it sends nothing, and a line under it — visible only
to you when logged in — says which of the two happened. Pick another form, or
reactivate the plugin, and it works again.

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

## Forms from other plugins (block themes)

On a block theme a form is often another plugin's block — Kadence's Form
block, Contact Form 7, WPForms, Gravity Forms and the like. In the Visual Edit
workspace every one of them is marked green and labelled **Form**, the same
as a form in the HTML editor, so it is clear at a glance which part of a page
collects answers.

A **Kadence Form** block can also be sent by Visual Edit. Click it and, under
**Content › Form › Where it goes**, set **Sent by** to **Visual Edit Form
Settings**, then choose what it does:

- **Contact form** — answers are stored under Form Submissions and emailed to
  the Form Settings address, or to the address in **Send to**.
- **Mailing list** — the address is added to the list you pick, exactly as for
  a Visual Edit signup form.

The form keeps its look, its fields and its own thank-you message, and
Kadence still runs its honeypot and reCAPTCHA. Kadence's own sending (its
email, MailerLite and FluentCRM actions) is switched off while the form is
connected, so you get one email per enquiry, not two; switching **Sent by**
back to the block's own settings puts them back.

As for Visual Edit's own form block, **Send to** and a list are honoured only
on a page whose author administers the site. Forms from the other plugins are
marked but keep the delivery set in their own plugin.

## What happens to a submission

In this order:

1. It is **stored** in WordPress, under **Visual Edit Lite → Form Submissions**
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

**Visual Edit Lite → Form Submissions.** Each entry opens to show every field, with
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
