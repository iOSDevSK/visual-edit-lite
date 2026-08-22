# Security

The capability model, the public endpoints, how secrets are stored, and the
anti-spam layers — including the one known gap.

## Capability model

Editing requires **both**:

```php
current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' )
```

Raw HTML round-trips through the editor, so anyone who can use it can put
arbitrary markup on the site. `unfiltered_html` is the capability WordPress
already uses to mean "trusted with markup", so it is the one used rather than
inventing a new one.

**On multisite that capability belongs to Super Admins only by default**, so
ordinary site administrators cannot use the editor there. That is WordPress's
decision, not this plugin's.

Screens holding credentials or personal data sit one tier higher at
`manage_options`: Form Settings, SEO & Sharing, Subscribers.

## Public REST endpoints

Three routes are unauthenticated. Each is public because it must be, and each
has a specific gate.

### `GET /posts` — blog "load more"

Serves already-published posts to anyone, which is what a blog does.

**The gate:** the card markup is read from the site's own stored source, never
accepted from the request. The only caller-controlled inputs are a sanitised
page key and a page number bounded to 2–500.

### `POST /submit` — form submissions

Must be anonymous; visitors are not logged in.

**The gate:** five layers, below.

### `GET /confirm` — mailing-list double opt-in

A link in a confirmation email, opened logged-out.

**The gate:** a 32-character random token, stored **only as a SHA-256 hash**
and compared in constant time. A leaked database cannot confirm anyone.

Every outcome — valid, invalid, already used, prefetched by a mail client —
lands on the same page. The endpoint is not an oracle for whether an address
is on a list, and a mail client's link-prefetching cannot accidentally confirm
someone.

## Form anti-spam

In the order a submission meets them.

### 1. Honeypot

A field visitors never see. Anything that fills it gets **success** and is
dropped — success rather than an error, so a bot learns nothing.

### 2. Origin token

An HMAC over the current time window, keyed on the site's own salts, valid for
a two-tick window. A token 2–14 ticks old is recognised as stale and answered
with *"This page has been open too long — please reload it"* rather than a
security error.

> **Why not a WordPress nonce?** Because it broke for the site owner. A nonce
> is bound to the user, and WordPress deliberately drops a cookie-authenticated
> REST request with no `X-WP-Nonce` header to anonymous — so a nonce created
> while logged in was verified as logged out and could never match. The owner
> testing their own contact form always got "Security check failed" while
> logged-out visitors were fine. The token is now site-scoped rather than
> user-scoped.

### 3. Signed time-trap

A signed timestamp; anything faster than the configured minimum fill time
(default 3 seconds) is dropped silently. Signed so a bot cannot forge an old
timestamp to fake a human fill delay.

Weaker behind full-page caching, where every visitor gets the same stamp.

### 4. Rate limit

One submission per IP per 60 seconds.

The visitor's real IP is read from `CF-Connecting-IP` **only when the request
genuinely arrived from Cloudflare**, verified against their published address
ranges with a binary CIDR match. Arbitrary `X-Forwarded-For` is never used.

> Trusting a forwarded header unconditionally is exploitable, and was
> measurably so: three submissions succeeded in a window that permits one
> before the range check existed.

Extend the trusted set with `clara_ve_trusted_proxies` if you sit behind a
different CDN. Anything in that list can set any visitor's apparent IP.

### 5. Akismet (optional)

Spam is **stored and flagged**, not discarded, and simply not emailed. The
sender still sees success. Errors fail open.

Nothing is thrown away on a classifier's say-so — a lost enquiry you never
knew about is worse than one sitting in a list marked "spam".

## Secret storage

Five options hold credentials. Encryption is AES-256-CBC with a random IV,
the key derived from `wp_salt('auth')`.

**What it protects:** a database-only exposure — a leaked backup, read-only
database access, another plugin dumping the options table.

**What it does not:** an attacker with both filesystem and database access.
The key comes from `wp-config.php`; holding both means holding everything.

No key rotation, no versioning, CBC without a MAC. Stated plainly because a
security claim that overreaches is worse than a modest one.

The settings UI never redisplays a stored secret. A saved one shows a
placeholder, and submitting the field empty keeps what is there.

## Export redaction

Three independent layers stop credentials leaving in a package:

1. **The options list is an allowlist** — the writer iterates that array
   rather than scanning the options table, so a new setting is excluded until
   deliberately listed. That is the intended failure direction
2. **A never-export list** of the five credential options, checked on the way
   out *and* on the way in
3. **A value-shape pattern** matched against exported values, catching a key
   pasted into the wrong field

**A match fails the whole export**, loudly. A silent filter would let a mistake
ship undetected for as long as nobody looked.

Personal data — submissions and subscriber emails — is separately gated and
off by default in every package.

## Other hardening worth knowing

- **Import path traversal** — absolute paths, `..` segments and Windows drive
  prefixes are rejected
- **Import cannot be tampered into overwriting** — apply re-derives what is
  allowed from the stored plan, not from the browser's POST
- **The redirect map is gated on 404** — it can only act where WordPress has
  already decided it has nothing, so it can never shadow a working URL
- **Provider errors are not passed through verbatim** for auth and billing
  failures, since those responses sometimes quote back part of the key
- **`_clara_ve_key` is registered `show_in_rest => false`** — internal

## Reporting a vulnerability

Open an issue at
[github.com/iOSDevSK/visual-edit-lite/issues](https://github.com/iOSDevSK/visual-edit-lite/issues).
For something you would rather not post publicly, say so in the issue without
details and a private channel will be arranged.

## Related

- [Data and privacy](data-and-privacy.md)
- [REST API](../developer/rest-api.md)
- [Forms](../guide/forms.md)
