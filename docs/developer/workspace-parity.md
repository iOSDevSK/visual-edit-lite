# VE workspace implementation and acceptance

## 1.27.0 — one editor for both theme kinds

The workspace now presents the VE popup as the only editing UI on block themes.
WordPress remains the engine for blocks, entities, validation, undo and save.

- Hidden in VE mode: the editor header, the block contextual toolbar (including
  the docked phone toolbar), the in-between inserter and the breadcrumb footer;
  inserter, list view and sidebar are closed on start. **⋯ → Show WordPress
  controls** removes the `cve-native-collapsed` class.
- Canvas highlighting (`#cve-workspace-canvas` stylesheet in each editor
  canvas), a selection label, and click tracking for placement.
- Placement rule `ClaraVEValues.placePopup()`, shared with the HTML editor:
  beside the block at the click height, then below, then above, then over it.
  The popup repositions on device change unless it was dragged or pinned.
- Tabs Content / Style / Section / Advanced; per-screen Style via the device
  switch writing `claraVe.responsive`; quick actions; items list; section
  insertion from theme patterns (header/footer patterns excluded).
- Design unlock uses `core/editor` `updateEditorSettings( {
  disableContentOnlyForUnsyncedPatterns, disableContentOnlyForTemplateParts } )`,
  the setting behind WordPress's "Enable editing all patterns" command.
  Verified on WordPress 7.1: every derived content-only/disabled block becomes
  default, no dirty record, no undo step, fully reversible. On WordPress
  versions without the setting nothing is locked by pattern, so there is
  nothing to unlock.
- `window.ClaraVE` (`assets/ve-api.js`) with implementations in `workspace.js`
  and `editor.js`. See [editor API](editor-api.md).

### Verified for 1.27.0

- `node tests/workspace-popup.cjs` — tabs, unlock, per-screen values, bold on
  a canvas selection, popup group filter, placement rules, plus the earlier
  suite.
- `node tests/ve-api.cjs` — every operation, permissions, structure, patterns,
  selection and document.
- `php tests/standalone-extras.php` (WordPress 6.8.2) — including
  attribute-backed responsive writes from the front-end block editor.
- `php tests/native-history.php` — including the new extension action.
- `node tests/workspace-history.cjs`, `tests/popup-values.mjs`,
  `tests/workspace-model.mjs`.
- Live on localhost:1111 (WordPress 7.1, Nimbsy), headless Chrome at 1440, 834
  and 390 px: toolbar without overflow, hidden native chrome, label and
  outline, popup placement, unlock, Tablet value in the canvas, Cancel,
  breadcrumb parent, Section tab, items add/move/undo, Document and More menus,
  docked History narrowing the canvas, section previews and insertion with
  undo, `ClaraVE.apply` with undo, save → History restore → save returning the
  template byte-for-byte. Converted theme (Mara Elisson): tabs, Apply, RADIUS,
  placement, `ClaraVE` in `html` mode, discard.
- `tests/workspace-css.cjs --dom` fails identically before and after these
  changes under the current jsdom (its `background` shorthand resolution
  differs); it is not a regression signal.


This is development work, not a claim that the full compatibility matrix has
passed. The PHP/JavaScript source and ZIP must be tested together with VE Lite
active on an isolated block-theme site before deployment.

## Implemented foundation

- The Visual Edit entry point hosts the real Site/Post Editor in a same-origin
  iframe. The runtime mounts its own toolbar and draggable dark popup.
- The popup runs in the selected block's React/data registry. No HTML patch
  endpoint writes Gutenberg documents. Native saving, plugin validation,
  multi-entity review and undo remain authoritative.
- Typography, Google Fonts management, colors, gradients, spacing, layout,
  border, shadow, media, links, motion, responsive settings and ornaments have
  popup controls. Unsupported and locked styles are restricted. Native block
  settings remain available through More block settings.
- Unit-preserving numeric steppers, directional spacing controls and individual
  radius corners are implemented. Scalar spacing/radius values expand without
  losing the untouched sides; complex values are not coerced to a number.
- Gradient swatches include the theme presets and adjacent palette pairs.
  From/To/Direction reads the existing two-stop value, including CSS functions.
  Multi-stop/radial gradients stay intact and use the native gradient picker
  embedded in VE. Disabled custom-gradient settings apply to every entry point.
- More block settings embeds the real `BlockInspector` in the popup, releasing
  the native sidebar Slot first. It does not duplicate plugin controls. Native
  actions in this view use WordPress Undo, not the VE field-level Cancel button.
- Google Fonts has a browsable catalog, retry, independent save and removal.
  A successful save refreshes the native theme font-family setting in the owning
  registry, preserving the native custom font-library layer and face metadata.
- VE ornaments on unbound paragraph/heading/list-item/button text can become
  editable inline RichText. The format remains registered in the ordinary native
  editor, and the old pseudo-element is suppressed to avoid a double glyph.
- Post preview uses WordPress's autosave/preview component. Non-viewable site
  entities explicitly link to the saved site. Save failures and site-browser
  loading failures are reported, with retry for the latter.
- Site browser supports pages/posts, template entities, navigation, synced
  patterns and editor-enabled custom post types. Core inserter, List View,
  document settings and Global Styles retain their original implementations.
- HTML mode retains its DOM patcher and popup renderer. Both renderers share
  `popup-values.js` gradient/value helpers; they are not yet one UI component.

## Data interfaces

- Runtime URLs carry `clara_ve_workspace=1` and an ephemeral
  `clara_ve_session` UUID. The UUID correlates messages; it is not an auth token.
  Host messages require version 1, channel `clara-ve-workspace`, matching
  origin, source window and session. Only readiness and dirty state cross the
  boundary. Native REST authentication remains WordPress's responsibility.
- New block attributes use `claraVe.responsive.{tablet,mobile}` with the
  existing dot-path property map, and `claraVe.ornaments.{before,after}`.
  A promoted ornament keeps its original data with `hidden: true` so native
  Undo and VE Cancel can restore it.
  This deliberately replaces the planned per-entity meta for new extras:
  references, patterns, copy/paste and revisions carry the block data naturally.
- The renderer validates CSS, adds a content-addressed class to the first root
  element and emits rules in the head or footer. Saved block HTML is untouched.
  Multi-root plugin blocks need an explicit targeting adapter before their VE
  extras can be advertised as supported.
- Legacy `_clara_ve_responsive` remains readable. Editing legacy rules in the
  new popup copies the rules into block attributes and removes that block's
  old anchor. Old meta is retained for other consumers and reversibility.
- Popup Apply/close retains edits in the unsaved native document. Cancel
  restores only values still equal to the popup's last write. Reset expands
  into individual style leaves so it can be cancelled correctly. Google Fonts
  and SEO retain their explicit, independent site/page save operations.

## Verified locally

- `node tests/popup-values.mjs`: units, clamping, complex CSS preservation,
  spacing shorthand, elliptical radii, gradient parsing and palette pairs.
- `node tests/workspace-model.mjs`: immutability, rich HTML/binding preservation,
  concurrent edits, reset, preset CSS, duplicated data and CSS injection guards.
- `node tests/workspace-popup.cjs /path/to/node_modules`: React 18/jsdom popup
  mount, scoped registry, steppers, spacing/corner preservation, existing and
  restricted gradients, native inspector mounting, cancel after reset,
  reopening, and locked content. Font catalog/save errors, retry, catalog
  pagination, limits, removal and native setting refresh are exercised with
  mocked REST responses. Ornament promotion round-trips through the real
  WordPress RichText package. The block registry remains a test registry, not a
  real Gutenberg provider. Dependencies: react, react-dom, jsdom and
  @wordpress/rich-text (including its @wordpress/data dependency).
- `php tests/standalone-extras.php /path/to/wordpress`: real WordPress 6.8.2
  sanitization, HTML tag processing, attribute registration, duplicate class
  isolation, preservation of rendered content, promoted-ornament suppression,
  and actual head/footer style output. This caught and fixed an unavailable
  inline-style helper. No database is touched.
- `tools/verify.sh`: the earlier foundation build completed on disposable
  WordPress 7.0.2. Plugin Check reported zero errors (40 repository, 35 security
  and 2 performance warnings); server behavior checks passed with an empty PHP
  debug log. This result predates the subsequent popup/font changes and does
  not establish interactive editor parity or verification of the current ZIP.

## Live read-only audit — September 13, 2026

- The user installed and activated the previous development ZIP on
  `localhost:1111`, WordPress 7.1, with Sailing Adventure active. Pro is absent.
  The VE host becomes ready and the actual Site Editor loads all six VE scripts,
  including the shared popup values and registered ornament format.
- The installed native heading schema includes `claraVe`. Core toolbar APIs
  for inserter, List View, device selection and Undo/Redo are available, as are
  the complementary-area APIs and native save button.
- Real Gutenberg create/serialize/parse round trips passed for a heading with
  typography/spacing/corners/responsive extras, a gradient paragraph, a promoted
  ornament paragraph and a navigation link. No document block was inserted or
  selected, and the dirty-entity count stayed zero. The reusable read-only audit
  lives in `tests/workspace-native-audit.js`; it does not prove saving works.
- The Google Fonts GET endpoint returned 1,946 catalog entries, maximum five
  selections, and the already selected Oooh Baby family. That family is present
  in the actual native font-family settings. No font selection was changed.
- Inspection found the native `clara-ve-google-fonts-editor-css` link duplicated
  by the preview. The subsequent local fix reuses that link and removes it on
  last-font removal in both documents. It also limits VE's font-variable
  overrides to its selected Google families, leaving theme presets to Gutenberg.
  The extended React/jsdom test exercises these actual stylesheet IDs. This fix
  is in the follow-up ZIP, not yet verified as deployed on localhost.
- The theme requests a missing `BricolageGrotesque-Variable.ttf` (HTTP 404).
  This is a separate theme asset issue; the plugin font catalog responds normally.
- Selecting a heading with the browser click tool was denied because approval
  is required while this session's approval policy is `never`. No dispatch or
  alternative click mechanism was used to bypass the denial. Interactive popup,
  save/reload, Undo and actual font add/remove tests remain blocked.

## Outstanding release gates

- The 1.26.1 development build fixes the reported tiled dropdown arrows by
  overriding WordPress's disabled-select SVG/background with one native arrow
  for VE-owned fields. It also separates content-only from disabled editing:
  permitted role-based text/link/media controls remain available, design
  controls are hidden, and all writes re-read mode/bindings. Cover media URL,
  ID, alt and background type are one explicit media transaction; no design
  fields are unlocked. The expanded model/popup suites pass, as does
  `tests/workspace-css.cjs ... --dom` with real WP forms CSS in jsdom.
  The actual Chromium CSS fixture could not run because the launched browser
  closed immediately in this environment. Browser visual and packaged live
  tests are still outstanding; do not substitute the jsdom result for those.

- Gutenberg History is now implemented locally in `class-native-history.php`
  and `workspace-history.js`. It captures successful canonical REST entity saves
  and stages restores through native core-data (including `root/globalStyles`).
  It does not publish via the legacy HTML restore endpoint. Latest 10 plus
  Original, entity permissions, rename and concurrency guards are included.
  `php tests/native-history.php` passes with in-memory DB/REST seams, and
  `tests/workspace-history.cjs` passes against actual WordPress 6.8.2 core-data,
  blocks and Undo/Redo in jsdom with mocked REST. This is not real database or
  localhost save/restore verification. The consolidated, updated task plan is
  [PLAN-GUT-CODEX.md](../../PLAN-GUT-CODEX.md).

- End-to-end save/reload/undo with the packaged plugin, WordPress 6.6 and the
  target WordPress version; template-part and synced-pattern ownership.
- Browser screenshots and interaction tests for the original popup's complete
  behavior, native dialogs, keyboard focus, iframe/device geometry and media.
- Google Fonts save/error/removal and native picker refresh on actual WordPress
  providers; local mocked REST tests are not a substitute for that gate.
- Native third-party blocks, bound content, partial saves, editor locking,
  revisions, publish scheduling, draft preview and failed network requests.
- Existing HTML-theme regressions and review of Plugin Check warnings.

## Remaining parity work

- The section browser now lists the sections saved on this site beside the
  theme's own. Synced patterns stay Site Editor territory: they render through
  their original and arrive locked to content-only editing, so they are neither
  offered nor saveable here.
- Share the popup components with the legacy HTML renderer after visual parity
  is established; currently the legacy implementation is deliberately intact.
- HTML-specific collection editing and connecting an arbitrary form need
  explicit block adapters. Core Query Loop/navigation/pattern editing and
  plugin form controls remain available. Ornament promotion currently applies
  to supported unbound text blocks, not arbitrary multi-root plugin markup.
- Complete the custom VE treatment of remaining site-management/revisions
  screens. Advanced block controls are now embedded, but native dialogs and
  inspector appearance still need visual verification.
- Complete the interactive deployment gate on the intended test site. The user
  removed the earlier plugins and installed Lite manually. The live read-only
  audit above confirms that installation; interactive browser tools are still
  denied. No theme switch or content save was performed by the agent.
  The temporary WordPress 7.0.2 verifier did activate Lite on its own isolated
  site and removed that site afterward. Docker access was intermittent; a
  subsequent `--keep` run could not start. Browser clicks on localhost were
  rejected by automatic approval, so those interaction tests remain unrun.
