# Mailing lists

Signup forms, double opt-in, and the consent record.

## Connecting a signup form

Same as any form — click it in the editor, but set **Does** to **Mailing
list** instead of *Contact form*, then pick which list from the dropdown.

The dropdown shows your provider's real lists with their names and subscriber
counts, so you are choosing a list rather than typing an ID.

See [Forms](forms.md) for the rest of the form settings.

## Provider

**Visual Edit Lite → Form Settings → Mailing list.**

Currently supported: **Brevo**.

If you already set Brevo up for [email delivery](email-delivery.md), the list
integration **reuses that same API key**. Asking for it twice is how you end
up with two keys and one of them stale.

## Double opt-in

Double opt-in means a new subscriber gets an email and has to click a link
before they are actually subscribed. It is required in some jurisdictions,
good practice everywhere, and it keeps typos and hostile signups off your list.

Three modes:

| Mode | Who handles it |
|---|---|
| **Off** | Nobody. The address goes straight to the list |
| **Handled here** | This plugin. Works with any mailer |
| **Handled by the provider** | Your provider's own opt-in template |

### Why "handled here" exists

Provider-side double opt-in is the obvious choice when your provider offers
it. But SendGrid removed it from their marketing API, and a site sending
through plain SMTP has no provider to hand it to at all.

So the plugin can own that half itself: hold the address, send the
confirmation, and only pass it to the provider once someone clicks.

### What you can edit

For the "handled here" mode, in Form Settings:

- **Confirmation email** — subject and body. Must contain `{confirm_url}`,
  which becomes the link
- **Delivery email** — subject and body, sent after they confirm. Supports
  `{download_url}`
- **The file they get** — pick from the Media Library. Leave blank if the
  confirmation is all you send
- **After confirming, go to** — where the confirmation link lands

`{site}` is available in both bodies.

This makes the classic lead-magnet flow work end to end: someone asks for a
checklist, confirms their address, and gets the file.

## The subscriber list

**Visual Edit Lite → Subscribers.**

| Column | |
|---|---|
| Email | With the IP address beneath it |
| Status | **Confirmed** or **Waiting** |
| List | Which list they signed up to |
| Asked | When they signed up |
| Confirmed | When they clicked |
| **Consent given to** | **The exact wording they agreed to** |
| Delete | Remove the record |

There is a CSV export.

### What this screen is for

**It is your consent record, not a contact manager.** The mailable list lives
at your provider; this is the evidence of who agreed to what, and when.

That is why the consent wording is stored per subscriber rather than looked up
from the current setting. If you change your consent text next year, this
still shows what each person actually agreed to at the time — which is the
only version that means anything.

Deleting a row warns you: *"Delete this record? The consent evidence goes with
it."*

## Retention

- **Unconfirmed** signups are removed after **14 days**. Someone who never
  clicked never subscribed, and holding their address indefinitely is holding
  data you have no permission for.
- **Confirmed** subscribers are never removed automatically. They are the
  consent record.

## Security details worth knowing

The confirmation link carries a random token, and **only a hash of it is
stored**. A leaked database cannot be used to confirm anyone.

Every outcome of clicking a confirmation link — valid, invalid, already used,
prefetched by a mail client — lands on the same page. So the endpoint cannot
be used to probe whether an address is on your list, and a mail client's
automatic link-prefetching cannot accidentally confirm someone.

Subscribers travel through an export **without** their confirmation tokens, so
a stale link from an old site can never confirm on a new one.

## Related

- [Forms](forms.md) — connecting the form
- [Email delivery](email-delivery.md) — confirmation mail uses the same
  transport, so this has to work first
- [Data and privacy](../reference/data-and-privacy.md) — retention and
  deletion
