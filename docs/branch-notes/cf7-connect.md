# Branch notes: `feature/cf7-connect`

A designed `[wp-form]` can be handed to Contact Form 7. The owner connects it
the usual way (click the form, then **FORM › Does › Contact Form 7**), picks a
CF7 form, and checks or overrides which CF7 field each of the form's fields
fills. The design stays as it is; CF7 validates, filters spam, mails and, with
Flamingo, stores the message. This matches what the html2wp Gutenberg target
does for its form block (html2wp-sub-protected `dual/p2-left6`, 8c70dff), with
the same field-matching rules.

Not released. No version bump. Contact and mailing-list delivery are unchanged
(tests below compare them byte for byte).

## What changed

| File | Change |
|---|---|
| `includes/class-form-handlers.php` (new) | Handler registry (`KINDS`), CF7 form and field listing, `map_fields()` (a port of `h2wp_gb_map_form_fields`), the CF7 forward (`WPCF7_ContactForm::submit()` with `$_POST` set the way CF7's REST feedback endpoint sets it, then restored), the verdict mapped back to our field names, the render-time step before the token is hydrated, and the editor route `GET/POST clara-ve/v1/form-handlers` |
| `includes/class-forms.php` | `handle_submit()`: a signed `type="cf7"` is handed over after every existing check (honeypot, origin, time-trap, rate limit). `respond()` adds `message` only when a form plugin supplied one |
| `assets/form-handler.js` (new) | Front-end sender for CF7-connected forms: reCAPTCHA v3 token, errors under fields, the success message |
| `assets/editor.js` | FORM panel: the Contact Form 7 option (offered only while CF7 is active), form picker, per-field "Sends as" list including "Don't send", and a warning about required fields |
| `includes/class-editor-page.php` | Editor config: `handlerNames`, `formHandlers` |
| `visual-edit-lite.php` | Requires the new class |
| `docs/guide/forms.md`, `docs/guide/dynamic-tokens.md` | User and token documentation |
| `tests/form-handlers-map.php` (new) | Matching rules, pure PHP |
| `tests/form-cf7-wp.php` (new) | Against a real WordPress with CF7 (and Flamingo) |

## How it works

- **Where the choice is stored:** in the token.
  `[wp-form type="cf7" list="12|name=your-name,email=your-email,phone="]`. An
  empty value after `=` means "Don't send". The `list` attribute is reused
  because every renderer of the token already emits it as `list_id`, and the
  existing delivery signature (`cve_delivery`) already covers it. A retyped
  form ID or mapping fails the signature and falls back to Form Settings, the
  same as a retyped list or recipient.
- **When the mapping is resolved:** in the editor. Our fields' types and labels
  are known there, while a submission carries only names. The server runs the
  matching (`map_fields`) and the editor writes the result into the token, so
  the page sends exactly what the panel shows.
- **Converted themes (`html2wp-runtime`):** the theme renders the token itself
  and does not sign it. At `render_block_core/html` priority 9, before either
  hydrator (priority 10), the plugin puts the signature into the form markup.
  The theme renders it untouched, and the request it forwards through
  `html2wp_theme_form_handle` verifies. The theme does not need regenerating.
- **Front end:** `form-handler.js` hides the connection marker (the name of
  the origin-token field) on the window, in the capture phase, for that one
  submit. The theme's and the plugin's general submit scripts therefore skip
  the form, and the design's own recorded validation still runs first. On the
  form itself the script puts the marker back and sends the submission, unless
  the validation cancelled it.
- **Plugin or form missing:** at render time the token is removed, so the form
  is not connected, and editors see "Visible only to you: …" under it. The
  edit preview keeps the token so the panel can still change it. A submission
  from a page cached while the form was connected gets a 503 with a neutral
  "This form isn't accepting messages right now." Nothing is sent or stored.
- **Security:** the editor route uses `clara_ve_user_can_edit`, like `/lists`.
  The submit endpoint stays public, with the same origin, honeypot, time-trap
  and rate limit as before. After a CF7 validation failure the rate-limit
  entry is cleared, so the visitor can correct the form and send it again at
  once. CF7 applies its own spam checks on top.

## How to test

```
php tests/form-handlers-map.php                       # no WordPress needed
php tests/form-cf7-wp.php /path/to/wordpress          # CF7 active; Flamingo optional
php tests/form-connect-wp.php /path/to/wordpress      # existing, unchanged
php tests/form-blocks-wp.php /path/to/wordpress       # existing, unchanged
```

Test run on WordPress 7.0.2, Contact Form 7 6.1.7, Flamingo 2.6.4, theme
bruce-banner (html2wp HTML target, `html2wp-runtime`):

- `form-cf7-wp.php`: 35 checks PASS. It covers both runtimes, a successful
  send, CF7 mail to the CF7 form's own recipient, one Flamingo record, and no
  Visual Edit submission. Errors come back under our field names
  (`{"name":"Please fill out this field.","email":"Please enter an email address."}`,
  400), and the rate limit is cleared after an invalid submit. It also checks
  the reCAPTCHA token pass-through with `$_POST` restored, the "required but
  unfilled" case, a retyped mapping falling back to Form Settings, a deleted
  CF7 form (not connected, owner-only note, 503 on a stale post), contact and
  list markup unchanged before hydration, a contact reply of exactly
  `{ok, redirect}`, and editor route permissions and mapping.
- `form-handlers-map.php`, `form-connect-wp.php`, `form-blocks-wp.php`, the
  node suite, `check-js-symbols.php` and `tools/build-plugin.sh` gates: PASS.
- Live, in a browser, on bruce-banner's contact page:
  - The editor offered Contact Form 7 and matched Name, Email, Subject and
    Message to `your-name`, `your-email`, `your-subject` and `your-message`.
    Setting Subject to "Don't send" showed the required-field warning.
  - After saving, the token carried the resolved mapping.
  - A logged-out visitor's empty submit showed only the design's own messages,
    with no request sent.
  - A bad email and an empty subject showed CF7's reasons under those two
    fields, in the design's red message style, plus CF7's summary.
  - A valid submit returned "Thank you for your message. It has been sent.",
    created Flamingo inbound message "Print request" and CF7 mail, and stored
    no Visual Edit submission.
  - With CF7 deactivated, the owner saw the note, a visitor's submit got the
    theme's "isn't accepting messages" line with no request sent, and the
    panel showed "Contact Form 7 (not active)".
  - The test container has no MTA, so a must-use plugin logged `wp_mail`
    instead of sending it.

## Limitations

- **Only `[wp-form]`.** Visual Edit's own form blocks (`clara-ve/form`) and
  Kadence forms connected through the workspace do not offer Contact Form 7
  yet. The server path would accept them, but they have no panel option.
- **No JavaScript:** a plain POST that CF7 refuses gets the REST error JSON,
  as every other refused submission does today. A successful one still
  redirects back with `?cve_sent=1`.
- **Recorded success message:** a design's recorded thank-you
  (`data-spa-success`) is not used. The form's own `data-cve-thanks` is used,
  then CF7's message. The only recording checked (bruce-banner) held the wrong
  message.
- **reCAPTCHA v3:** a token is requested only when CF7's reCAPTCHA integration
  is on and its script (`wpcf7_recaptcha`, `grecaptcha`) is on the page. CF7
  loads it site-wide by default. Tested with a server-side spy only, not
  against Google.
- **CF7 cases that refuse:** "Subscribers only" CF7 forms refuse (the route is
  anonymous). File upload fields are not forwarded.
- **Other mail plugins:** Contact Form 7 only. `KINDS` / `available()` /
  `forward()` are where a second plugin would be added.
- **Pre-existing, not changed here:** on converted themes, `to` and `list` for
  contact and mailing-list forms still fall back to Form Settings, because the
  theme does not sign them.
