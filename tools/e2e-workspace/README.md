# Workspace end-to-end checks (block themes)

Headless-Chrome driver for the plan's §5.3 scenarios against a local WordPress
(`http://localhost:1111`, admin / admin123). Not part of the release ZIP.

```
export NM=/path/to/node_modules   # must contain playwright
export S=/path/to/state-dir        # login cookies are cached in $S/qa-state.json
node qa.cjs steps/b-select.cjs                  # site editor (front page / template)
POST=417 node qa.cjs steps/d-style.cjs          # a page in the post editor
W=390 H=844 POST=417 node qa.cjs steps/o-mobile.cjs
node switch-theme.cjs nimbsy                    # theme switches for j-templatepart / p-tt5
node qa-html.cjs                                # HTML-mode regression (converted theme active)
```

Steps print JSON plus `LOGS` (console errors). Screenshots land in the
`OUT` directory named at the top of `qa.cjs`. `steps/c-content.cjs` through
`steps/h-history.cjs` change the page they run on — use a scratch page.
`steps/m-deactivate.sh` deactivates the plugin; reactivate afterwards.
`audit-lib.js` is the contrast/scrollbar audit used by the `*-audit*` steps.

The steps were written against two scratch pages that no longer exist: page
417 (`/ve-qa-blocks/`, a heading, paragraph, two buttons and two columns with
an image) and page 416 (a copy of the front page). `n-plain-editor.cjs`,
`m-validity.cjs`, `native-dirty.cjs`, `m-deactivate.sh` and the frontend
URLs inside the steps name those IDs and paths directly — create equivalent
pages and edit the IDs before running them.

Form styling steps: `t-form-style.cjs` and `t-form-generic.cjs` expect `POST` to be a
page holding a `[amanda_rose_form]` shortcode block (with `claraVe.form` values) and a
`core/html` block containing a plain `<form>`; `t-contact-preview.cjs` only reads a page
with a form shortcode and saves nothing. `s-audit-swatches-inserter.cjs` audits colour
palettes and the block inserter; `NEG=1` re-adds the old broken rules to prove it catches them.

Form blocks: `v-form-convert-e2e.cjs` expects `POST` to be a page with a heading and a
`[amanda_rose_form id="contact"]` shortcode block; it converts, edits, saves and checks
validity. `v-form-click.cjs` checks that clicking a field on the canvas selects that field.
`v-front-submit.cjs` (plain Node + Playwright, `NM` and `OUT` set) checks the saved page
and submits it in a browser; `v-native-form.cjs` inserts a form in WordPress's own editor.
All four use the `/ve-qa-block-form/` page (ID 460 at the time) — adjust before running.
`tests/form-blocks-wp.php` is an integration test for a live site:
`php tests/form-blocks-wp.php /path/to/wordpress`.

`w-convert-page.cjs` converts every shortcode form on `POST` into form blocks through the
popup and prints the resulting tree; with `SAVE=1` it also saves, reloads and reports invalid
blocks. It changes real content when saving — run it on a copy first.
