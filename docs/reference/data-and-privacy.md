# Data and privacy

Everything the plugin stores, how long it keeps it, and what happens when you
remove the plugin.

## Personal data it stores

Three kinds, all of it from people who used your site.

### Form submissions

Whatever the visitor typed, plus:

- their **IP address**
- a spam flag
- a delivery-failure flag if the notification email did not send

Stored under **Visual Edit Lite → Form Submissions**, indefinitely. There is no
automatic cleanup — deleting old submissions is a decision, not a default.

### Mailing-list subscribers

- email address
- IP address
- when they signed up and when they confirmed
- **the exact consent wording they agreed to**

The consent wording is stored per subscriber rather than looked up from the
current setting, so it stays accurate if you change your wording later. That
is the point of a consent record.

**Retention:** unconfirmed signups are removed after **14 days** — someone who
never confirmed never subscribed, and holding their address longer is holding
data you have no permission for. Confirmed subscribers are kept until you
delete them.

### Nothing else

No analytics, no telemetry, no usage reporting. The plugin does not phone
home.

## Data sent to third parties

Only when you enable the feature, and only to the service you configured.

| Feature | Sends | To |
|---|---|---|
| Email delivery | The notification email | Your chosen provider |
| Mailing list | Subscriber email and fields | Your list provider |
| Akismet | Submission content and IP | Akismet |
| Google Fonts | Nothing about visitors. The catalogue is fetched **server-side** | Google |

With none of these configured, the plugin makes **no external requests at
all**.

Worth being deliberate about the providers you connect: each one receives the
data in the table above. That is inherent to how they work, but it is a
decision to
make knowingly on a site with sensitive content.

## GDPR notes

**Consent line** — off by default, turn it on in Form Settings to show a note
under every connected form with your own wording and a link to your privacy
policy.

**Double opt-in** — available for mailing lists, either handled by the plugin
or by your provider. See [Mailing lists](../guide/mailing-lists.md).

**Right to erasure** — delete the submission from Form Submissions and the
subscriber from Subscribers. Deleting a subscriber warns you that the consent
evidence goes with it, because it does.

**Data portability** — Subscribers has a CSV export. Submissions can be
exported inside a content package.

**IP addresses count as personal data** under GDPR. The plugin stores them for
submissions and subscribers. If that does not suit your situation, they can be
removed by deleting the record.

## What an export contains

**Never, in any package:** SMTP password and mail provider API keys. Guarded
three ways, and a match fails the whole export rather than filtering quietly.

**Only if you tick the box:** form submissions and subscriber emails. Off by
default in every package, with the real counts shown next to the checkbox.

Subscribers travel **without** their confirmation tokens, so a stale link
cannot confirm on the destination site.

## Removing a theme

Separate from removing the plugin, below, and a different promise.

### Deactivating a converted theme

Everything its import created — pages, posts, images, menus, categories,
redirects, the front-page setting — is **put away**, not deleted. The site
behaves as though that theme had never been installed: its addresses 404, and
none of its content appears in Pages, Posts, Media or Appearance → Menus.

Also put away: anything **you** made while that theme was active. A post you
wrote, an image you uploaded, a page you added. It belongs to the design you
were working on and comes back with it.

**Visual Edit Lite → Parked content** lists what is being held, for every inactive
theme, with counts. Activating the theme again restores all of it exactly
where it was.

### Deleting a converted theme

Destroys everything in that list, permanently. There is no undo, and the
confirmation says so and names the numbers before you agree.

Included: pages, posts, images and their files, menus, categories, redirects,
saved versions, **form submissions that arrived through that theme, and
mailing-list subscribers who signed up under it** — addresses, IPs and consent
records with them.

Two things are deliberately kept:

- an image another installed theme also uses, or that content outside this
  theme still references. The confirmation says how many.
- submissions taken before the plugin recorded which theme a submission
  arrived through. They cannot be attributed to one theme or another, so they
  are not deleted and stay under Form Submissions. The confirmation says how
  many of those there are too.

**Export first** if there is any chance you will want it back — the Parked
content screen has a button for exactly that, and it works on a theme that is
not active.

## Removing the plugin

### Deactivating

Keeps everything. Reactivating picks up where you left off.

Deactivating the PLUGIN is not the same as deactivating a theme: no content is
put away, because the machinery that would put it away is what you just
switched off.

The front page reverts to the design the theme shipped with while it is
deactivated, because front-page edits live in an option the plugin applies at
render time. Other pages keep their content.

### Deleting

Two tiers.

**Always removed**, no setting involved:

- stored credentials — SMTP password and all mail provider API keys
- scheduled background jobs
- every cached value

Credentials go unconditionally because encrypted credentials with no software
left to read them are pure liability.

**Removed only if you opted in** — Form Settings → Uninstall → *"Also delete
all stored data"*, off by default:

- the history table
- the subscriber table
- form submissions and their metadata
- the plugin's page-content and settings options
- the plugin's post metadata

**Your Pages and Posts are never deleted.** They are your content and stay in
WordPress, keeping the content that was mirrored into them.

The setting is off by default for one reason: an uninstall must never silently
destroy someone's subscriber list. Deleting and reinstalling should lose
nothing, and by default it does not.

### Removing everything by hand

If you have already deleted the plugin without the setting on, what remains is:

- two tables: `{prefix}clara_ve_history`, `{prefix}clara_ve_optins`
- options matching `clara_ve_%`
- posts of type `clara_ve_submission`
- post meta `_clara_ve_key`, `_clara_ve_seo`, `_clara_ve_noindex`

Reinstalling the plugin, turning the setting on, and deleting again is the
supported route.

## Data locations, in one place

| | Where |
|---|---|
| Page content | `clara_ve_source__*` options, mirrored into pages and template parts |
| Version history | `{prefix}clara_ve_history` |
| Form submissions | `clara_ve_submission` posts |
| Subscribers | `{prefix}clara_ve_optins` |
| Settings | `clara_ve_*` options |
| Credentials | `clara_ve_*` options, encrypted |
| SEO records | `_clara_ve_seo` post meta |

Full detail in [Data model](../developer/data-model.md).

## Related

- [Security](security.md)
- [Mailing lists](../guide/mailing-lists.md)
- [Import](../guide/import-export.md)
