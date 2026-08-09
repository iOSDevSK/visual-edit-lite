# Email delivery

Making sure form notifications actually arrive. This is the setting most
worth your attention, and the one most likely to be quietly broken.

## The problem

By default WordPress sends mail directly from your web server. That mail
usually fails modern authentication checks:

- The **From** address is something like `wordpress@yourserver.example`, which
  does not match your domain
- Your web server's IP is **not authorised** to send mail for your domain
  (SPF fails)
- Nothing is cryptographically signed (no DKIM)
- Shared hosting IPs are frequently on blocklists

The result is mail landing in spam, or being dropped silently — no bounce, no
error, nothing in your inbox. And because the plugin **stores every submission
before emailing it**, you can be losing every notification for weeks without
noticing, while the enquiries sit safely in Form Submissions.

**No plugin can fix this on its own.** It is a domain authentication problem,
not a code problem. What Visual Edit gives you is a way to send through
something authorised, without installing a separate SMTP plugin.

## Choosing how mail is sent

**Visual Edit → Form Settings → Email delivery.**

| Option | Use when |
|---|---|
| **Default** | Your host already handles mail properly. Test it |
| **Other SMTP** | You have SMTP credentials from your host or provider |
| **Brevo** | ↓ |
| **SendGrid** | ↓ |
| **Postmark** | Any of these four — paste one API key |
| **Mailgun** | Also needs the domain and US/EU region |

### Why an API provider is often the better answer

The four API options send over HTTPS rather than SMTP. That matters because
**many hosts block outbound SMTP ports** (25, 465, 587) to limit spam from
compromised sites. On such a host, SMTP settings simply time out, while an API
provider works because port 443 is never blocked.

If you are choosing fresh and have no preference, an API provider is the more
reliable path.

## Then set up your DNS

**This is the part that actually fixes deliverability, and it is not optional.**

Your provider will give you DNS records to add to your domain — typically SPF
and DKIM, sometimes DMARC. Adding them is what tells the world that this
provider is allowed to send mail on your behalf.

Without them, sending through a provider is only a modest improvement. With
them, mail lands.

It is a one-time task, done at whoever manages your domain's DNS. Your
provider's setup guide will walk you through it.

## The From and Reply-To addresses

| Setting | Blank means |
|---|---|
| **From name** | Your site's title |
| **From address** | `no-reply@yourdomain` — with `www.` stripped, so it aligns with your domain for SPF and DKIM |
| **Send submissions to** | The WordPress admin email |

Notifications are sent **from** your site and have **Reply-To** set to the
visitor's own address. So you can reply straight from your inbox and it
reaches them, while the message itself passes authentication as coming from
your domain. Sending *as* the visitor would fail every check.

## Test it

**Send a test email** at the bottom of Form Settings sends a message to your
configured recipient using whichever mailer is selected.

Save your settings first — the test uses what is stored, not what is typed on
screen.

Check the spam folder as well as the inbox. Arriving in spam is a different
diagnosis from not arriving at all: it usually means DNS records are missing,
whereas nothing arriving means the credentials or the transport are wrong.

## How credentials are stored

SMTP passwords and API keys are **encrypted at rest** in the database, and the
settings screen never displays a stored secret — a saved one shows
`•••••••• (saved — leave blank to keep)`, and submitting the form with that
field empty keeps what is already there.

Being precise about what that protects: it protects a **database-only**
exposure — a leaked backup, read-only database access, another plugin dumping
options. It does **not** protect against someone with both database *and*
filesystem access, because the encryption key is derived from your
`wp-config.php` salts. Anyone who has both has everything anyway.

Credentials are **never included in an export**, guarded three separate ways.
See [Security](../reference/security.md).

## If the mail still does not arrive

1. Check **Form Submissions** — is the enquiry stored? If yes, the form works
   and this is purely a delivery problem.
2. Look for a delivery-failure flag on the entry.
3. Send a test email.
4. Check the spam folder.
5. Confirm your DNS records are in place and match the provider you selected.
6. If you are on SMTP and nothing happens at all, try the same provider's API
   option instead — your host may be blocking the port.

## Related

- [Forms](forms.md) — connecting a form in the first place
- [Mailing lists](mailing-lists.md) — signup mail uses the same transport
