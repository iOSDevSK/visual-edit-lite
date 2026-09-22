# Branch notes: `feature/cf7-connect`

A designed `[wp-form]` can be handed to **Contact Form 7** or **Fluent
Forms**. The owner connects it the usual way (click the form, then
**FORM › Does › Contact Form 7 / Fluent Forms**), picks one of the plugin's
forms, and checks or overrides which of that form's fields each of our fields
fills. The design stays as it is, and the plugin processes the submission:

- **Contact Form 7:** validation, spam checks, reCAPTCHA v3, mail, and the
  Flamingo record.
- **Fluent Forms:** validation, spam checks, reCAPTCHA or hCaptcha, the
  entry, notifications and confirmation.

It mirrors the html2wp Gutenberg target (`dual/p2-left6` 8c70dff, and agent T's
`dual/p2-cf7gf` 745cdc8 for Fluent Forms and captchas), with the same
field-matching rules.

Not released. No version bump. Contact and mailing-list delivery are unchanged
(tests below compare them byte for byte).

## Commits

| Commit | What |
|---|---|
| 8026589 | Contact Form 7: editor option, mapping, forward, errors and success, missing-plugin fallback, theme-runtime signing |
| 8f15bca | `verify.sh` runs the mapping test |
| 8418b74 | Fluent Forms; captchas; recorded success; no-JS round trip; `method="post"`; idempotent signing; CF7 labels |
| 33f7fcd | A page keeps every backslash it was saved with (the reason recorded messages were unreadable) |

## What changed

| File | Change |
|---|---|
| `includes/class-form-handlers.php` (new) | The handler registry (`KINDS`: `cf7`, `fluentform`), the plugin form and field listing, and `map_fields()` (a port of `h2wp_gb_map_form_fields`). The captcha spec is `captcha()`. `forward()` feeds CF7 through `WPCF7_ContactForm::submit()`, with `$_POST` set the way its REST endpoint sets it and then restored, and Fluent through `SubmissionHandlerService::handleSubmission()`. It also maps the verdict back to our field names, keeps the no-JS result, runs the render step before the token is hydrated, and serves the editor route `GET/POST clara-ve/v1/form-handlers` |
| `includes/class-forms.php` | `handle_submit()`: a signed handler type is handed over after every existing check (honeypot, origin, time-trap, rate limit). A plain post goes back to its page. `respond()` adds `message` only when a form plugin supplied one |
| `assets/form-handler.js` (new) | Front-end sender for handler forms: the captcha token, errors under fields, and the recorded success, then `data-cve-thanks`, then the plugin's message |
| `assets/editor.js` | FORM panel: the plugin options (offered only while the plugin is active), form picker, per-field "Sends as" list including "Don't send", and a warning about required fields |
| `includes/class-editor-page.php` | Editor config: `handlerNames`, `formHandlers` |
| `includes/class-source-store.php`, `class-page-actions.php`, `class-import-plan.php` | `wp_slash()` on every post-content write (see "Backslashes") |
| `visual-edit-lite.php` | Requires the new class |
| `docs/guide/forms.md`, `docs/guide/dynamic-tokens.md` | User and token documentation |
| `tests/form-handlers-map.php`, `tests/form-cf7-wp.php`, `tests/form-fluent-wp.php`, `tests/source-slashes-wp.php` (new) | See "How to test" |

## How it works

- **Where the choice is stored:** in the token, e.g.
  `[wp-form type="cf7" list="12|name=your-name,email=your-email,phone="]`
  (or `type="fluentform"`). An empty value after `=` means "Don't send". The
  `list` attribute is reused because every renderer of the token already emits
  it as `list_id` and the delivery signature (`cve_delivery`) already covers
  it. A retyped form ID or mapping fails the signature and falls back to Form
  Settings, like a retyped list or recipient.
- **When the mapping is resolved:** in the editor. Our fields' types and labels
  are known there, while a submission carries only names. The server runs the
  matching and the editor writes the result into the token, so the page sends
  exactly what the panel shows. CF7 labels are read from the `<label>` around
  each tag.
- **Converted themes (`html2wp-runtime`):** the theme renders the token. Since
  html2wp `dfefc7e` (on `feature/dual-target`) the theme signs every type
  itself through `Clara_VE_Forms::delivery_field()`. Older themes do not sign.
  For them, at `render_block_core/html` priority 9 (before either hydrator),
  VE Lite signs a handler form. It adds nothing when the markup already
  carries `cve_delivery`, and the theme likewise adds nothing when VE has
  signed, so there is always exactly one signature. The signing rule is the
  same on both sides:
  `delivery_field( sanitize_key(id), trim(to), sanitize_key(type), (string) list )`.
- **Front end:** at the window capture phase, `form-handler.js` hides the
  connection marker (the origin-token field's name) for the length of that one
  submit. The general submit scripts, the theme's and VE's own, therefore skip
  the form, and the design's recorded validation still runs first. On the form
  itself the script puts the marker back and sends the submission, unless the
  validation cancelled it.
- **Captchas:** the server names the captcha the plugin checks, with the public
  key only, as `data-cve-captcha` on the form. The script loads the provider,
  or reuses the one the plugin already loads. A visible widget goes before the
  submit button. The token is posted as `cve_captcha`, and `forward()` puts it
  where the plugin reads it: `_wpcf7_recaptcha_response`,
  `g-recaptcha-response` or `h-captcha-response`. The plugin verifies it with
  its own secret. A failed captcha is treated as a refusal, not as a field
  error.
- **Success:** the design's recorded thank-you (`data-spa-success`: inline,
  replace or toast), then `data-cve-thanks`, then the plugin's message. A
  Fluent confirmation redirect is followed when the owner set no "Then go to".
- **No JavaScript:** handler forms get `method="post"` when the markup has
  none. The post comes back to its page with `?cve_result=<key>`: a random key
  pointing to a 10-minute transient, bound to the form's ID. The page then
  shows:
  - each reason under its field, in the design's error style;
  - the summary after the form;
  - the visitor's input filled back in;
  - or, on success, the recorded thank-you or the plugin's message.

  The visitor never sees the REST JSON.
- **Plugin or form missing:** the token is removed at render time, so the form
  is not connected, and editors see an owner-only note under it. A post from a
  page cached while the form was connected gets a 503 with a neutral
  "This form isn't accepting messages right now." Nothing is sent or stored.
- **Security:** the editor route uses `clara_ve_user_can_edit`, like `/lists`.
  The submit endpoint stays public, with the same origin, honeypot, time-trap
  and rate limit as before. After a plugin validation failure the rate-limit
  entry is cleared, so the visitor can correct the form and resend at once.
- **Backslashes (33f7fcd):** `wp_insert_post()` and `wp_update_post()` unslash
  their input. VE's page mirror, template-part writes, block save, page
  duplicate and article import passed content raw, so every backslash was lost
  on every VE save. Every one of those writes now uses `wp_slash()`.
  bruce-banner's bundle carries valid recorded JSON (`class=\"…\"`), but its
  imported page does not. That page was written by the theme's own importer
  (`content-import.php`), which has the same bug and is still unfixed on
  `feature/dual-target`; reported to P. After a VE save the page is correct.
  A freshly imported page that was never saved stays broken until the
  importer is fixed.

## How to test

```
php tests/form-handlers-map.php                        # no WordPress needed; in verify.sh
php tests/form-cf7-wp.php /path/to/wordpress           # CF7 active; Flamingo optional
php tests/form-fluent-wp.php /path/to/wordpress        # Fluent Forms active (its Contact Form Demo)
php tests/source-slashes-wp.php /path/to/wordpress
php tests/form-connect-wp.php /path/to/wordpress       # existing, unchanged
php tests/form-blocks-wp.php /path/to/wordpress        # existing, unchanged
```

Test run on WordPress 7.0.2, Contact Form 7 6.1.7, Flamingo 2.6.4 and Fluent
Forms (wordpress.org latest), theme bruce-banner:

- `form-cf7-wp.php`: 47/47 pass, on both runtimes. It covers:
  - a successful send: CF7 mail to its own recipient, one Flamingo record,
    no VE submission;
  - errors under our field names (400), with the rate limit cleared;
  - reCAPTCHA v3 end to end against a stubbed siteverify: a good token is
    delivered, a bad one refused, and `$_POST` is restored;
  - the no-JS round trip: reasons under fields in the design's style, values
    kept, the recorded thank-you inline and replace, and CF7's message when
    nothing is recorded;
  - idempotent signing;
  - a deleted CF7 form: not connected, owner-only note, 503 on a stale post;
  - contact and list unchanged, and the editor route.
- `form-fluent-wp.php`: 14/14 pass. Accepted and stored as a Fluent entry, the
  name split into first and last, errors under our names, hCaptcha answered and
  refused against a stubbed siteverify, and the theme runtime.
- `source-slashes-wp.php`: 6/6 pass. It fails 5 of 6 without 33f7fcd.
- `form-handlers-map.php`, `form-connect-wp.php`, `form-blocks-wp.php`, the
  node suite, `check-js-symbols.php` and the `tools/build-plugin.sh` gates:
  pass.
- `tests/regression-*.php` (via `wp eval-file`) and `theme-contract-api.php`:
  pass, except three that fail identically on `origin/main` 520a74f in the
  same environment. They are `block-convert` (2), `page-actions` ("the copy
  was made") and `patterns` ("the route answers"), and they need a block
  theme and a REST context this env doesn't have. So the duplicate `wp_slash`
  is covered by `source-slashes-wp.php`, not by `page-actions`.
- **Theme signing:** bruce-banner's runtime was re-rendered from
  `feature/dual-target` templates, using `make-theme.mjs`'s own class renames.
  On its own submit route (`BruceBanner_Runtime_Forms::handle_submit`):

  | Form | Old runtime | Current runtime |
  |---|---|---|
  | contact | 0 signatures, sent to Form Settings | 1 signature, sent to `per-form@example.invalid` |
  | list | 0 signatures, emailed as contact | 1 signature, takes the list path |
  | cf7 | 1 signature (VE's) | 1 signature |

  The list path is shown by "No mailing list provider is connected." on the
  stored entry.

  Confirmed afterwards on a real rebuild: P's bruce-banner 1.0.0 ZIP, built
  from html2wp `dual/p2-left11` b226b23 (ce35033 plus a rewrite-rules fix),
  sha1 `845dc55f…`. Same three results (1 signature each, recipient and list
  honoured). `form-cf7-wp` 47/47 and `form-fluent-wp` 14/14 pass on it,
  including "signed exactly once" on the theme runtime.
- **Live in a browser, on bruce-banner /contact/:**
  - **CF7:**
    - The editor matched Name, Email, Subject and Message to `your-name`,
      `your-email`, `your-subject` and `your-message`. "Don't send" on Subject
      showed the required-field warning.
    - An empty submit showed only the design's own messages, with no request
      sent.
    - Bad input showed CF7's reasons under the fields in the design's red
      style.
    - A valid submit returned CF7's thank-you and created Flamingo message
      "Print request".
    - With CF7 deactivated, the owner saw the note, a visitor's submit sent
      nothing, and the panel showed "(not active)".
  - **Fluent Forms:** the editor offered Fluent, and "Contact Form Demo"
    matched with `names` found by its label. Tested both with and without
    JavaScript:
    - reasons appeared under the Email field;
    - the visitor's input was filled back in;
    - success showed Fluent's confirmation;
    - the entries (7, 8) have first and last name split.
  - **Recorded success:** a well-formed `data-spa-success` (inline) was shown
    as the design's own element, both with and without JavaScript.
  - The container has no MTA, so a must-use plugin logged `wp_mail` instead of
    sending it.

## Should Visual Edit's own form blocks and the Kadence connect offer this too?

- **`clara-ve/form` blocks — yes, worth doing. About a day of work, low to
  medium risk.**
  - The submit path is already shared: `Clara_VE_Form_Blocks::submit()` calls
    `Clara_VE_Forms::handle_submit()`, and rendering goes through
    `Clara_VE_Tokens::connect_form()`.
  - Needed:
    - `render_form()` stops collapsing `formType` to contact/list;
    - `listId` carries the mapping;
    - the "author administers the site" gate is extended to a handler choice;
    - the form picker and mapping go into both panels (`form-blocks.js` and
      `workspace.js` "Where it goes"). The fields come from inner-block
      attributes, which is simpler than a DOM scan.
  - `form-handler.js` already takes over any form whose `form_type` is a
    handler kind.
- **Kadence connect — no.**
  - The hook VE uses (`kadence_blocks_form_submission`) runs after Kadence has
    validated the submission and already told the visitor it succeeded. A CF7
    or Fluent refusal at that point could not be shown to anyone, so
    submissions would be lost silently.
  - Doing it properly would mean taking over Kadence's AJAX response: about
    1–2 days, high risk, and it chains two form plugins.
  - Recommendation: a Kadence form keeps Kadence's own actions or VE delivery.
    A site that wants CF7 or Fluent uses the plugin's form, or a designed
    `[wp-form]`.

## Limitations

- **Only `[wp-form]`.** See above for the form blocks and Kadence.
- **Captchas:** Lite answers reCAPTCHA (v2, v2 invisible, v3) and hCaptcha.
  Lite ships no Turnstile code; the build's purity gate refuses it. A plugin
  form protected by Turnstile is refused by its plugin, which shows its own
  message. Captchas were tested against stubbed siteverify endpoints, not
  against Google or hCaptcha.
- **Recorded success in rebuilt themes:** P's rebuild recorded no
  `data-spa-success` on bruce's /contact: the app's own `.min(10)` rule on the
  message field refused the prerender's filled submit (reported to the lead by
  P). So no real recording has been checked end to end yet. The plugin side
  was verified with a well-formed recording, live and in tests. A fresh import
  will also mangle any recording until the theme importer's `wp_slash` fix
  lands.
- **Refused cases:** CF7 "subscribers only" forms refuse, because the route is
  anonymous. File-upload fields are not forwarded.
- **Theme rate limit (reported to P):** an html2wp theme's own limit (5
  submissions per 10 minutes per IP) also counts attempts that CF7 or Fluent
  refused for validation. VE clears only its own limit.
- **Theme `method` (reported to P):** the themes' `render_form` never adds
  `method="post"`, so a contact or list `[wp-form]`
  without JavaScript posts a GET. VE's own renderer has the same gap. VE fixes
  it for handler forms only, because contact and list are held byte-for-byte.
