# Production readiness — Visual Edit Lite 1.27.0

An assessment of whether this plugin can be put on a live site, and who it is
worth installing for. Dated **14 September 2026**, against the working tree at
version 1.27.0.

Everything below is evidence, not opinion: where a claim comes from a tool it
names the tool and the run, and where it comes from reading the code it names
`file:line`. Where something was not tested, it says so.

---

## The short answer

**Yes for the job it was built for, now that the converted-theme submit path
is fixed — with two release chores outstanding.**

This verdict changed while the document was being written. The first draft read
"yes, with two chores"; then a security review of the code written the same day
found that on a converted theme **every form submission was being silently
discarded**, and that the exemption which caused it was also an unauthenticated
mail relay waiting for one setting. Both are fixed in `includes/class-forms.php`
(see [Security](#security--pass-after-two-fixes-made-during-this-review)); the
honest arc is worth keeping visible, because the job this plugin exists for is
exactly the one that was broken.

With those fixed: the release gate passes, Plugin Check reports zero errors in
every category, the public attack surface is small and deliberately drawn, and
the front end costs a visitor about ten kilobytes. The plugin is not a
general-purpose page builder and does not pretend to be one — see [Who should
install it](#who-should-install-it) before deciding.

The two chores are release hygiene rather than code: **1.26 and 1.27 are
uncommitted** (68 changed files against the last tag, 1.25.12), and the
compatibility matrix in [workspace parity](../developer/workspace-parity.md)
is a development log, not a passed test plan.

---

## What it is

Two editors behind one screen, chosen by the kind of theme:

| Theme | What opens | What edits the page |
|---|---|---|
| A Gutenberg block theme | the VE **workspace** — the page, one dark toolbar, one popup | WordPress's own editor, hidden underneath: blocks, templates, patterns, Global Styles, undo, validation and saving are all core's |
| A theme converted from static HTML | the **click-to-edit preview** | the plugin's own source store, which keeps the original markup byte-for-byte |

The second is the reason this plugin exists. A site hand-written or generated
as HTML — from a design tool, an AI, or a developer's own files — can be
brought into WordPress without a page builder rewriting its markup, and the
owner can still change text, images, forms, menus and search metadata by
clicking on them.

---

## Verdict by axis

### Packaging and WordPress.org compliance — **pass**

`tools/verify.sh` builds the distributable, boots a throwaway WordPress in
Docker, installs the **extracted package** (not the working tree) and runs
Plugin Check across every category. Run three times on 14 September 2026 — once
before this review's changes and twice after — with the same result:

```
WordPress 7.0.2, Plugin Check 2.1.0
  plugin_repo     0 errors, 40 warnings
  security        0 errors, 35 warnings
  general         0 errors,  0 warnings
  performance     0 errors,  2 warnings
  accessibility   0 errors,  0 warnings
✓ VERIFIED — clean and ready to release/submit
```

Every one of the 77 warnings was read. They fall into four groups:

- **35** — direct `$wpdb` access and its missing object cache, all against the
  plugin's own history table. Expected for a plugin that owns a table; each
  call site carries a `phpcs:ignore` with a reason.
- **2** — "hook name not prefixed": `the_content` (`includes/class-tokens.php:612`)
  and `nonce_life` (`includes/class-forms.php:404`). Both are *core* hooks being
  filtered. False positive.
- **2** — one `prepare()` the sniff cannot follow
  (`includes/class-history.php:370`; the placeholders are built from
  `count()`, which is correct) and one `$_GET` read
  (`visual-edit-lite.php:1848`; sanitised with `sanitize_key()` and checked
  against a whitelist before use). False positives.
- the rest — `set_time_limit()` during import (`includes/class-import-page.php:341`)
  and two VIP-only performance notes that do not apply outside WordPress VIP.

Other release facts checked by hand:

- **PHP 7.4** as declared: no `str_contains`, `str_starts_with`,
  `str_ends_with`, `match`, `array_is_list`, nullsafe `?->`, enums or arrow
  functions anywhere in the PHP. Every file parses.
- **Text domain**: 940 strings on `visual-edit-lite`, and
  `wp_set_script_translations()` is registered for every script that uses
  `wp.i18n` (`includes/class-form-blocks.php:51`,
  `includes/class-native-gutenberg.php:187-193`).
- **External services** are disclosed in `readme.txt` under
  `== External services ==` — Google Fonts, Akismet, Brevo, SendGrid,
  Postmark, Mailgun, your own SMTP, remote image import and Gravatar, each
  with what is sent and links to terms and privacy. That list matches every
  outbound host reachable in the code.
- **No build step**: the shipped PHP, CSS and JavaScript are the source,
  unminified. There is nothing to audit that you cannot read.

Missing: **no icon, no banner and no screenshots exist at all.**
`assets-source/` holds only a README describing the wordpress.org SVN `assets/`
directory that should contain them, which is also why `readme.txt` has no
`== Screenshots ==` section. Not a rejection, but the icon is what a listing
is clicked on, and an editor with no pictures sells badly.

Two claims in `readme.txt` were wrong and are now fixed: it promised uninstall
removes "scheduled jobs" (the plugin registers no cron at all), and the
changelog opened with an unversioned heading, *"Development workspace (not yet
release-verified)"*, describing the same workspace the description sells as
shipped — a heading that renders on the public changelog tab and tells every
visitor the current version contains unverified code. An
`== Upgrade Notice ==` entry for 1.27.0 was added; the newest was 1.19.8.

### Security — **pass, after two fixes made during this review**

The public surface is small and deliberately drawn, but a review of it found
two real defects in code written the same day, both in the path a **converted
HTML theme** uses. Both are fixed; both are stated here rather than quietly
corrected, because one of them was a blocker for the plugin's main use.

1. **Every submission on a converted theme was being discarded.** Such a theme
   signs its anti-spam timestamp as `<time>.<flag>.<signature>`; this plugin
   signed `<time>.<signature>` and refused anything that was not exactly two
   parts (`verify_timestamp()`). With Minimum fill time at its default of three
   seconds, `handle_submit()` returned a silent *success* before
   `wp_insert_post()` — the visitor saw a thank-you, the owner got nothing, and
   nothing was logged. Fixed: the signed prefix is verified whatever its length,
   which reads both shapes without loosening the signature.
2. **The delegated path was an unauthenticated mail relay.** 1.27.0 exempted
   `html2wp_theme_form_handle` from the new delivery-signature check, on the
   reading that the theme had already decided the recipient. It had not: what
   that filter forwards is the raw request body, so `to` was whatever the
   browser sent, and `Clara_VE_Form_Settings::recipient()` honours any posted
   address that passes `is_email()`. Defect 1 was the only thing suppressing it;
   setting Minimum fill time to 0 would have made it live. Fixed: the exemption
   is gone — one check, every caller. The cost is that a per-form **Send to** on
   a converted theme falls back to Form Settings until the converter signs it in
   the markup it generates.

A third finding from the same review is fixed here too: the media import copied
archive-supplied filenames into the uploads directory with no extension check
(`includes/class-import-plan.php`), so a crafted bundle could place a `.php`
file in a web-servable path. It is admin-and-nonce-gated and would also bypass
`DISALLOW_FILE_MODS`, and it is the kind of thing a WordPress.org review stops
on. The destination now has to pass `wp_check_filetype()` against
`get_allowed_mime_types()` — the same allowlist as a manual upload, widened the
same way (`upload_mimes`).


Twenty-nine REST routes. Twenty-five require a capability; **four are public,
and each has a reason a visitor needs it**:

| Route | Why public |
|---|---|
| `POST /clara-ve/v1/submit` (`includes/class-forms.php:120`) | a visitor submitting a form |
| `POST /clara-ve/v1/form-submit` (`includes/class-form-blocks.php:195`) | the same, for form blocks |
| `GET /clara-ve/v1/confirm` (`includes/class-optin.php:277`) | the double opt-in link in a confirmation email |
| `GET /clara-ve/v1/posts` (`includes/class-rest.php:204`) | the blog's "load more", published posts only, and registered only when the theme does not own the public runtime |

Everything a visitor can reach goes through five layers before anything is
stored or emailed — origin token, honeypot, signed time-trap, per-IP rate
limit (Cloudflare-aware), optional Akismet — and, since 1.27, a signature over
the delivery choice so a retyped recipient or list id cannot turn a form into
a mail relay — applied to **every** caller, the converted-theme filter
included. See [Security](security.md).

Editing raw HTML requires **both** `edit_theme_options` and `unfiltered_html`
(`visual-edit-lite.php:308`) — the capability WordPress already uses to mean
"trusted with markup". On a block theme the editors keep Gutenberg's normal
per-entity permissions.

There is exactly one file upload in the whole plugin
(`includes/class-import-page.php:241`): capability-guarded,
nonce-checked, restricted to `application/zip`, and extracted through core's
own `unzip_file()` (`includes/class-zip.php:116`) rather than a hand-rolled
loop — so archive path traversal is core's hardened code path.

Secrets (SMTP password, provider API keys) are stored encrypted at rest —
AES-256-CBC with a random IV under a key derived from `wp_salt('auth')`
(`includes/class-form-settings.php:816`) — and are removed on uninstall
**unconditionally**, because "ciphertext with no reader is pure liability"
(`uninstall.php`). The usual caveat applies and is worth stating: the key
lives in `wp-config.php`, so this protects a stolen database dump, not a host
whose filesystem has been read.

A second reviewer confirmed the same picture independently and added two
findings worth knowing:

- **`uninstall.php` has no multisite handling** — no `is_multisite()` or
  `get_sites()` anywhere in the file. On a network, deleting the plugin cleans
  only the site it runs on; every other site keeps its `clara_ve_*` rows *and
  its encrypted API keys*. Now documented in
  [Data and privacy](data-and-privacy.md); the code should follow.
- **Visual Edit writes into Yoast's and Rank Math's own post meta**
  (`includes/class-seo.php:83-104`, written at `:416`, `:429`, `:436`, `:468`,
  `:475`). The automatic back-fill only fills blanks, but an explicit save from
  Visual Edit's SEO panel defaults to `only_if_empty = false`
  (`includes/class-seo.php:345`) and overwrites what the owner typed in Yoast's
  own UI. Defensible — a value typed into a panel that then did nothing would
  be worse — but it was undocumented, and now is, in [SEO](../seo-and-ai/seo.md).

A fourth finding is closed here as well, and its measurement is worth keeping,
because the finding as written overstated it. The form block signed a per-form
**Send to** and **List** chosen by whoever authored the page, with no capability
check — so on paper an Author could have their own address, or the owner's
mailing list, signed by the site itself. Measured on a running install, they
cannot: `<form>` and `<input>` are not in WordPress's post allowlist, so a save
by anyone without `unfiltered_html` strips them
(`wp_kses_post()` leaves `<div class="wp-block-clara-ve-form"><button>` and
nothing else), and there is no form left to deliver anything. The gate was added
anyway, because it costs one capability check and it should not depend on a
kses allowlist staying as it is: a delivery choice is now carried only by a page
whose author administers the site (`includes/class-form-blocks.php`); everyone
else's form is an enquiry to the address in Form Settings. A form in a template
part is unaffected — that is theme territory, and already needs
`edit_theme_options`.

The same measurement turned up something that is **not** a security finding and
matters more day to day. Because those tags are stripped on save, a contributor,
author or editor **without `unfiltered_html` who saves a page containing a form
block destroys the form** — silently, on an ordinary save, in WordPress's own
editor as much as in this one. Nothing in the plugin warns about it. On a
single-author site (which is what this plugin is for) it never happens; on a
multi-author one it is a data-loss bug waiting, and it belongs on the list below.

The two bypasses of the new gate that suggest themselves were both probed on a
running install and neither works: a form block inside a **synced pattern**
(`core/block`) authored by an Author, and a `[wp-form … to=…]` token inside a
`core/html` block carrying the `clara-ve-key` marker. Both lose their form tags
to the same kses pass before they are ever stored.

#### After the review: a third converted-theme fatal

Found by using the plugin rather than reading it, the same afternoon. A theme
converted from HTML carries its own copy of the form runtime, and that copy
delegates to this plugin the moment the class is loaded — it asks
`Clara_VE_Form_Settings::turnstile_enabled()` with no way to know which edition
it is talking to. **Pro has that method; this edition never did.** The result
was `Call to undefined method` inside `the_content`, so every page holding a
`[wp-form]` token showed visitors "There has been a critical error on this
website" — the public page, not the editor.

Two methods now answer, truthfully, that there is no Turnstile here. More to
the point: nothing in the gate could have caught it, because the gate renders
no converted theme. `tests/theme-contract-api.php` now asserts that every
method such a runtime delegates to exists, and runs in the gate. That is a
tripwire, not a substitute — **the real fix is a converted theme in the gate**,
and it is not built.

This is the third defect found in the converted-theme path in one day, after
the timestamp and the delegated exemption. All three were invisible to a gate
that only ever renders a block theme, and that is the single most useful thing
this assessment learned about its own testing.

The same session finally produced the primary evidence this document said it
lacked: a real form on the live converted theme `claire-hayes`, scraped from
the public page, submitted over HTTP after a genuine five-second pause, stored
as one submission with its four fields and the theme recorded against it.

#### Found and not yet fixed

From the same review, ranked, with the code to look at. None is reachable by an
unauthenticated visitor; all are worth closing before a WordPress.org submission.

| | Where | What |
|---|---|---|
| Medium | `includes/class-import-legacy.php:250-278` | `ltrim` leaves `../` in a path; gated behind the `CLARA_VE_ALLOW_STATIC_IMPORT` constant, which is off by default |
| Medium | `includes/class-parked-page.php:305-317`, `includes/class-bundle-writer.php:84,568,620` | `clara_ve_park_export` writes a bundle containing the whole opt-in table and every submission, at `edit_theme_options`, on an unbound nonce; an empty slug exports the active theme |
| Medium | `includes/class-rest.php:359-363,372` | the SSRF guard on image import validates the first hop only, so a redirect can still reach `169.254/16` |
| Medium | `includes/class-optin.php:198,207,306` | CSV export does not neutralise a leading `=`/`+`/`-`/`@`, so a subscriber-supplied value is a formula in Excel |
| Low | `includes/class-parked-page.php:89-104` | park delete takes an unbound nonce, and a parent theme can be deleted while a child is active |
| Low | `includes/class-forms.php:220` | a visitor's field named `_something` is stored as protected post meta |
| Low | `includes/class-form-settings.php:812-836` | AES-256-CBC without a MAC: a database-write attacker can flip ciphertext, not read it |
| Low | `includes/class-fonts.php:460` | a bundle can carry a fonts stylesheet that is served as the theme's |
| Low | `includes/class-zip.php:254` | an interrupted import leaves its scratch directory in uploads |
| Bug | `includes/class-form-blocks.php` (save path is core's) | a user without `unfiltered_html` saving a page that contains a form block strips `<form>`/`<input>` and the form stops working, with no warning |
| Docs | `includes/class-rest.php:320,353-355`, `includes/class-zip.php:103-105`, `includes/class-import-page.php:5-8` | docblocks that assert protections the code does not implement; the fourth of these, at `visual-edit-lite.php:2611`, is fixed |
| UX | `assets/form-blocks.js` | the inspector shows **Send to** and **List** on a page whose author cannot deliver to them, which the plugin's own rule says not to do |

The same review also found, with evidence, no SQL injection (every `ORDER BY`
is a literal, every query prepared), no unescaped output including the JSON-LD
`</script>` case, a nonce and a capability before the write in all nine
`admin_post_*` handlers, no `admin_post_nopriv`, and `hash_equals` on all three
HMACs.

**Neither this section nor that review is a formal security audit.** Both are
readings of the source; nobody has attacked a running install. What the review
did establish is that reading matters: the two defects above were in code
written that morning, and both passed the gate — the gate had no converted
theme to submit through.

### Stability and tests — **good, after this review**

28 test files. Before today the release gate ran six of them; it now runs ten.
Three problems surfaced, all now fixed:

1. `tests/import-dir-names.mjs` had been **unable to start** since the plugin's
   main file was renamed — it still looked for `visual-edit.php`. Nothing ran
   it, so nothing noticed.
2. `tests/regression-page-actions.php` asserted on a storage detail that
   changed when the responsive record became JSON for REST. Page duplication
   was verified to work correctly; the test was rewritten to assert through
   `Clara_VE_Responsive::rules()`.
3. The four dependency-free Node tests that were outside the gate are now in
   it (`tools/verify.sh`). The eleven PHP regression tests are still outside
   it — see the release checklist below.

Against a real WordPress, **10 of the 11 PHP regression tests pass**. The one
failure — `regression-foreign-theme`, "the refused save created no
template-part override" — is a `wp_template_part` post that already existed on
that install (post 443, created the previous evening). The assertion cannot
tell an override this save created from one that was already there; the
test's own comment says as much. Not a code failure, but a test that will keep
failing on any site whose header has ever been saved.

Still thin: **19 of the 43 `includes/` files have no test that names them**,
among them `class-zip.php`, `class-mailer.php`, `class-optin.php`,
`class-bundle-reader.php`, `class-bundle-writer.php`, `class-redirects.php`
and `class-source-store.php`. Four tests (`workspace-popup.cjs`,
`ve-api.cjs`, `workspace-history.cjs`, `form-convert.cjs`) need a
`node_modules` containing jsdom and cannot run in the gate as it stands.

### Front-end cost — **negligible**

On a live page the plugin loads only what that page needs. Measured on the
front page of a real converted site: four files, about **10 KB unminified**
(`form-submit.js`, `forms.css`, `motion.js`, `motion.css`), and only because
that page has a form and motion. No jQuery, no framework, no tracker, no
phone-home. A page with neither loads nothing at all.

### Code quality — **unusually careful**

25,618 lines of PHP and 12,958 of JavaScript. What stands out is the comment
density — `visual-edit-lite.php` is 1,120 comment lines out of 2,664 — and
what the comments contain: not restatements of the code but the reasoning,
including the bug that forced each decision. `Clara_VE_Forms::origin_field()`
spends forty lines explaining why it does not use `wp_create_nonce()`, with
the core function that broke it. That is the kind of comment that stops the
next person from reintroducing the bug.

The architecture holds two invariants that the whole design follows from:
converted markup is preserved byte-for-byte, and on block themes WordPress
stays the engine — the plugin never writes block documents behind Gutenberg's
back.

### Documentation — **good prose, and it had drifted**

31 files, roughly 4,900 lines under `docs/` — better than most commercial
plugins ship, and the writing explains *why* rather than restating the code.
But the developer reference had fallen behind 1.27, and a reference that is
confidently wrong is worse than a thin one. Found and fixed during this
review:

| Was | Is |
|---|---|
| "The plugin fires **three** hooks" | twelve; six were documented nowhere, and now are |
| "**There is no plugin API.** Nothing accepts a registered handler" | `window.ClaraVE` has a write path and four JS filters take render callbacks |
| "Every route the plugin registers" | nine were missing — pages, block patches, structure, patterns, lists, image import, native history |
| "**Three** public routes" (in two files) | four |
| `GET /posts` documented unconditionally | registered only when the theme has not taken the public runtime |
| `/menu-item` "front-page nav location only" | it takes a `location` and searches every declared zone |
| 16 internal constants listed | 24 |
| `clara_ve_optins` "schema version 1" | version 2 |
| A hardcoded anchor table presented as the requirement | anchors and nav selectors come from `clara_ve_theme_contract`, which the page never mentioned; it now has a section |

Every identifier in `docs/` was then checked against the code: 64 hook names,
29 option keys, meta keys and constants, every REST path, and every internal
link. All resolve. One page was missing from the index and is now linked.

---

## Who should install it

**Yes, clearly:**

- An agency or developer converting hand-written or AI-generated HTML sites to
  WordPress and handing them to a client who must be able to edit them without
  touching markup. This is the case the plugin was built for and there is very
  little else that does it.
- Anyone who wants a designed contact form to keep its design and still send,
  store, and feed a mailing list, without a form plugin rebuilding it.

**Probably yes:**

- Someone on a block theme who finds the Site Editor overwhelming and wants a
  single popup instead of a sidebar, an inserter, a toolbar and a list view.
  WordPress still does the work underneath, so nothing is lost by trying it.

**Probably not:**

- A blogger on a stock theme who is happy with the block editor. The plugin
  adds screens and concepts (page keys, parked content, sources) for problems
  that site does not have.
- Anyone wanting a drag-and-drop page builder. This is not one, deliberately.

---

## Before calling it released

1. **Commit 1.26 and 1.27.** The last tag is 1.25.12 (27 August); 68 files
   have changed since. Everything described here lives in an uncommitted
   working tree.
2. **Re-run `tools/verify.sh`** after that commit, so the verified package and
   the tagged source are the same thing.
3. **Get the unrunnable tests into the gate** — either vendor jsdom for the
   four that need it, or move what they assert into tests that do not.
4. **Make the assets — icon, banner, screenshots.** `assets-source/` describes
   them; none exist. Add `== Screenshots ==` to `readme.txt` once they do.
5. **Give `uninstall.php` a multisite loop**, or the network case keeps
   encrypted keys on every other site.
6. **Make `assets/editor.js` translatable.** 6,441 lines of the raw-HTML
   editor's UI — roughly a hundred strings — are hardcoded English with no
   `wp-i18n` dependency (`includes/class-editor-page.php:264`), no
   `wp_set_script_translations` for the `clara-ve-editor` handle and no
   entries in the POT. On a translated site that whole screen stays English.
   The block workspace is fully translated; this is the older editor only.
7. **Prove PHP 7.4 with a tool, not a grep.** The build lints with whatever
   PHP is on the machine (8.5 here), so it proves the files parse under 8.5.
   `phpcs` with PHPCompatibility and `--runtime-set testVersion 7.4` would
   make the header's promise checkable.
8. **Run the PHP regression suite in the gate too.** Eleven tests exist and
   the gate runs none of them; the two stale ones found today would have been
   caught the day they broke if it did.

## Known limitations, stated plainly

- The compatibility matrix in
  [workspace parity](../developer/workspace-parity.md) is a development
  log. It records what was verified on WordPress 7.1 and 6.8.2 and is honest
  about what was not. It is not a passed test plan across every supported
  WordPress version.
- The workspace has been exercised on a handful of themes, not on a matrix of
  them. A block theme that locks patterns in an unusual way, or registers
  patterns in a way the plugin does not recognise, will behave differently —
  one such case was found and fixed during this review
  (`includes/class-patterns.php`, theme patterns namespaced by text domain
  rather than by folder name, which left **+ Section** empty).
- Mail delivery, mailing-list providers and SEO-plugin coexistence are tested
  against mocks and a container. They have not been tested against a real
  host, real provider accounts or a live Yoast installation.
- One incident during this review, not closed. A page on the test install was
  saved with its pre-conversion markup, replacing the converted version. What
  is established: the write arrived over REST, as an administrator, from a
  browser session, and the plugin's history table recorded it — but that table
  records *every* REST save of an entity, so it does not show whether the
  plugin or WordPress itself initiated this one. The likeliest explanation is
  a stale local backup in a reused automation browser profile, which is a
  WordPress feature rather than a plugin fault; it could not be confirmed
  afterwards because later autosaves had replaced the evidence. The page was
  restored from its 09:23 revision, byte-identical, with no invalid blocks and
  a working form. It should be reproduced deliberately before release rather
  than assumed benign.
