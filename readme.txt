=== Visual Edit Lite – Visual Editor for Block Themes ===
Contributors: filipdvoran
Tags: visual editor, html to wordpress, static site, front-end editor, llms.txt
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.30.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Click-to-edit visual editing for WordPress — static-HTML themes and Gutenberg block themes alike. Text, images, forms, menus, SEO, 1:1 markup.

== Description ==

Visual Edit Lite adds a floating inspector and site tools to the native Gutenberg editor
on a block theme. It is also the editing companion for a WordPress site built
from a static HTML design — a hand-written site, or one exported from Lovable,
Bolt, v0.dev, aidesigner.ai or a Claude design. A converted theme keeps every
page's markup byte-for-byte identical to the original and the plugin makes
those pages editable by clicking.

If you have brought an HTML site into WordPress and want the client to edit it
without touching the markup — or without the design surviving a page builder —
this is the piece that was missing.

Nothing here is locked, timed, or waiting for a key. Everything the plugin
contains works on every install, offline included.

Development happens in the open at
https://github.com/iOSDevSK/visual-edit-lite — the full source, the build
script and the translation-template generator are there. The plugin has no
build step: the PHP, CSS and JavaScript shipped in this package are the
source, unminified and uncompiled.

* **Point-and-click editing** of text, links, images and video, directly on
  the live page, with a git-like per-page edit history.
* **Gutenberg workspace on block themes.** Visual Edit hosts the native editor
  and adds a floating inspector with typography, Google Fonts and styling.
  Native controls remain available for blocks, patterns, templates, navigation
  and Global Styles. See the development acceptance matrix for current limits.
* **Repeating content** (FAQ lists, service cards, team members, portfolio
  tiles) managed as a list: reorder, edit, add and remove items together.
* **Forms** — a designed HTML form is connected by clicking, never rebuilt.
  Submissions are stored and emailed; delivery works through your server,
  SMTP, or the Brevo / SendGrid / Postmark / Mailgun APIs. Anti-spam layers:
  honeypot, minimum fill time, per-IP rate limit (CDN-aware) and optional
  Akismet.
* **Mailing list** signup with double opt-in, provider integration and a
  subscriber list in wp-admin.
* **Blog** — listings driven by a token that repeats the design's own card
  markup for real WordPress posts.
* **Menus** — the design's own navigation markup, managed from Appearance →
  Menus.
* **SEO** — titles, descriptions, Open Graph, canonicals, robots and
  redirects, carried over from the original site and editable per page;
  integrates with Yoast SEO / Rank Math when present, self-sufficient when
  not.
* **AI readiness** — schema.org structured data (including FAQ), llms.txt,
  AI-crawler rules, and a read-only structure audit. This is about being
  *readable by* AI search engines. Lite contains no AI writing or image
  tools and sends nothing to any AI provider.
* **Import** — bring in a converted theme's content bundle: pages, menus,
  media, forms and SEO records, reviewed before anything is written.

= Editing history =

Every Save is a restore point. The history panel lists the last ten saves
plus the Original — the design exactly as the theme shipped it — so there is
always a way back, however long ago you started.

The panel lists ten; the table keeps up to three hundred per page. Those rows
are your own content in your own database, and the plugin never deletes them
to make a point — they are there for backups, for WP-CLI, and for whatever you
run next.

= What this plugin needs =

A native Gutenberg block theme, or a theme whose pages are raw HTML and which
declares its contract through the `clara_ve_theme_contract` filter. The
contract for converted themes is fully documented in
`docs/developer/theme-requirements.md`.

Forms, email delivery, mailing lists, SEO, redirects and llms.txt do not
depend on the theme at all and work anywhere.

== Important notes ==

* **Raw-HTML editing requires `unfiltered_html`**, because markup round-trips
  through that editor. Native Gutenberg mode uses WordPress's normal
  permissions for the page, template or other entity being edited.
* **Deactivating** the plugin keeps all data and reverts the front page to the
  theme's shipped design until reactivation; other pages keep their edited
  content.
* **Deleting** the plugin always removes stored secrets (SMTP password,
  provider API keys) and its own transients. Everything else — submissions,
  subscribers, edit history, page sources — is kept unless "also delete all
  stored data" is enabled under Visual Edit Lite → Form Settings → Uninstall.
* **Visual Edit Pro**, the paid edition, shares this plugin's data format and
  its class and option names. The two cannot run at the same time: with Pro
  active, Lite switches itself off and says so rather than crashing the site.

== Installation ==

1. Install and activate the converted theme you were given.
2. Plugins → Add New → search for "Visual Edit Lite" → Install → Activate.
3. Follow the theme's setup screen to import the site's content, then edit any
   page from the Visual Edit menu.

== Frequently Asked Questions ==

= Is anything disabled until I pay? =

No. Visual Edit Lite has no licence key, no trial period and no locked
buttons. The features Lite does not have are simply not part of it — there is
nothing in the plugin to unlock.

= What is in Visual Edit Pro that is not here? =

An AI assistant that edits pages conversationally, AI image editing and AI
video generation (all bring-your-own API key), Cloudflare Turnstile as an
extra anti-spam layer, one-click theme export, and a history panel that lists
300 restore points per page instead of ten. Pro is sold separately and is not
required for anything Lite does.

= Will I lose my work if I switch between Lite and Pro? =

No. Both editions store content, history and settings under the same names, so
either one reads what the other wrote, in both directions and with nothing to
migrate.

= Can I use it on a theme I built myself? =

Yes, if the theme's pages are raw HTML and it declares the
`clara_ve_theme_contract` filter. See the plugin's `docs/` directory for the
theme requirements.

= Does it work without JavaScript on the front end? =

Forms submit and work without JavaScript. The editor itself is a wp-admin
screen and needs JavaScript, like the block editor does.

== External services ==

This plugin does not contact any external service on its own. Every service
below is reached only after you switch it on, and only for the purpose
described.

**Google Fonts** — used only if you add Google fonts in the editor's font
picker. Opening the picker requests the public font catalogue from
`fonts.google.com`; a page that uses a chosen font loads its stylesheet and
font files from `fonts.googleapis.com`, which means visitors' browsers connect
to Google and Google receives their IP address and user agent. No fonts are
requested if you add none.
Terms: https://policies.google.com/terms — Privacy:
https://policies.google.com/privacy — Google Fonts privacy FAQ:
https://developers.google.com/fonts/faq/privacy

**Akismet** — used only if the separate Akismet plugin is installed and
configured and you enable Akismet filtering under Form Settings. Each form
submission is then sent to Akismet for a spam verdict, including its field
values, the sender's IP address and user agent.
Terms: https://akismet.com/tos/ — Privacy: https://automattic.com/privacy/

**Email delivery providers** — used only if you select one as the mailer and
enter its API key. The message being sent (recipient, subject, body) is
transmitted to the provider you chose:
Brevo — https://www.brevo.com/legal/termsofuse/ ,
https://www.brevo.com/legal/privacypolicy/ ;
SendGrid — https://www.twilio.com/en-us/legal/tos ,
https://www.twilio.com/en-us/legal/privacy ;
Postmark — https://postmarkapp.com/terms-of-service ,
https://postmarkapp.com/privacy-policy ;
Mailgun — https://www.mailgun.com/legal/terms/ ,
https://www.mailgun.com/legal/privacy-policy/

**Brevo (mailing lists)** — used only if you connect Brevo as your mailing-list
provider. A subscriber's email address, and the list you chose, are sent to
Brevo when they confirm a double opt-in signup. Same terms and privacy links
as above.

**Your own SMTP server** — used only if you select SMTP as the mailer. The
message is sent to the host you configured.

**Importing a remote image** — when you click "Import image into this site" on
a picture hosted elsewhere, the plugin downloads that one URL, which you chose,
and stores the file in your Media Library.

**Gravatar** — used only if a listing template you designed includes the
`{author_image}` placeholder. It resolves through WordPress's own
`get_avatar_url()`, so unless another plugin serves avatars locally the
portrait is fetched by visitors' browsers from `gravatar.com`, which then
receives their IP address and user agent. A template without that placeholder
requests nothing.
Terms: https://automattic.com/terms/ — Privacy: https://automattic.com/privacy/

== Privacy ==

The plugin stores form submissions and mailing-list subscribers in your own
database. It sets no cookies, runs no analytics, and sends nothing anywhere
about you or your site. Secrets you enter (SMTP password, provider API keys)
are encrypted at rest with your site's own salt and are always removed when
the plugin is deleted.

== Changelog ==

= 1.30.0 =

* New: A **Get Pro** screen in the Visual Edit Lite menu. It lists what the
  paid edition adds, and the two screens it brings — AI Settings and Export
  Theme — are shown in the menu, marked Pro, opening the same explanation.
  Nothing is loaded from outside the site and nothing is sent anywhere.
* Changed: The plugin is listed as "Visual Edit Lite – Visual Editor for
  Block Themes". The menu, the admin bar and every screen are unchanged.

= 1.29.1 =

* Fixed: The ＋ Section browser labels its two groups — Theme sections and Your sections.

= 1.29.0 =
* Save a section and use it on any other page. **Save as a section…** on the
  Section tab of a section's popup stores it as one of your site's own
  sections, listed under ＋ Section as Your sections everywhere. Each copy you
  place is ordinary blocks you can edit one by one, independent of the page it
  came from. Saving happens at once rather than as an unsaved change, and a
  saved section is removed again under Patterns in the WordPress admin. A
  section holding a synced pattern, a template part or content bound to that
  one page cannot be saved, and the form says which it was.
* `ClaraVE.apply()` can treat a run of neighbouring blocks as one, with three
  operations: `group` wraps them in a single Group, `ungroup` takes a Group
  apart again in its place, and `move-to` moves a run of blocks elsewhere on
  the page, including inside another container. Each is one change the editor
  undoes in one step. Blocks that are not next to each other, or that
  WordPress will not let move, are refused with the reason instead of quietly
  doing nothing.
* `ClaraVE.saveSection()` and a `save-section` event for the same, and
  GET /block-patterns now says which source each section came from.

= 1.28.0 =
* A theme section shipped as one Custom HTML block — an FAQ of details, a
  list, a row of buttons — goes onto the page as native blocks when it is added
  with ＋ Section: a Details block per question, headings, lists and buttons,
  each editable in the popup, in one undo step. Markup with no native block (an
  embed, an icon drawn in SVG, inline styles) stays Custom HTML exactly as it
  was. A Details block's popup edits its question.
* Forms from other plugins — Kadence Form, Contact Form 7, WPForms, Gravity
  Forms and others — are marked green in the block workspace like Visual Edit's
  own, and a Kadence Form can be sent by Visual Edit: choose Form Settings and a
  contact address or a mailing list in its popup. Kadence's own sending is
  switched off while connected, so nothing arrives twice.
* Form button styles reach a Kadence form in the editor, where Kadence draws
  the button as an editable box rather than a button.
* The HTML editor's popup has a pin: pinned, every element opens it in the same
  place, and dragging it moves the pin.
* History and other side panels no longer open over each other.
* On a phone the workspace toolbar keeps Save on screen.
* The block gate refuses a static core block written as a self-closing comment
  (<!-- wp:separator /-->), which the editor flags as invalid and the site shows
  as nothing.
* For developers: the `clara_ve.toolbar.extras` and `clara_ve.popup.top`
  filters, the `clara-ve-dock-opened` event, the `convert-to-video` operation,
  Kadence images in `set-image`, and `POST /native/convert-blocks`.

= 1.27.0 =
* Icons from a theme's or a plugin's own controls are no longer invisible in
  the dark editing popup. A whole family of icon sets is drawn with strokes
  rather than fills, and the contrast repair only ever looked at fills — so an
  icon picker full of line icons kept its author's dark grey on a ground the
  editor had just painted dark. They are repaired on the property they are
  actually drawn with, and never by filling them in.
* A form whose fields cover it completely — a one-row signup is an input and a
  button, edge to edge — could not be connected at all: there was no pixel of
  the form left to click, and what a form does when someone submits it is only
  offered on the form itself. A field now says **Part of a form** and offers
  the way up, opening the form on the tab that holds those settings.
* **A theme converted from HTML no longer white-screens its visitors.** Such a
  theme carries its own form runtime that asks this plugin about Cloudflare
  Turnstile as soon as it is loaded; this edition has no Turnstile and had no
  answer, so every page holding a form died with a critical error — the public
  page, not only the editor. It now answers "off", which is the truth.
* On a theme converted from HTML, form submissions were being accepted and
  silently thrown away: the theme and the plugin signed the anti-spam timestamp
  in two different shapes, and the visitor saw a thank-you either way. They are
  delivered again, and stored.
* Where a form sends is signed into the page and checked on the way back, for
  every form — including one a converted theme renders and hands over. Until
  that theme signs the value itself, a per-form **Send to** on such a site falls
  back to the address in Form Settings rather than being taken from the request.
* A media bundle can no longer place a file in the uploads folder that you
  could not upload by hand: the extension has to be one this site allows.
* **Send to** and **List** on a form block are honoured only on a page whose
  author administers the site. Anyone who can publish could otherwise point a
  form at their own address, or at your mailing list. A form in a template part
  is unchanged.
* Forms are marked in green — the whole form, its fields and a *Form* label,
  in both editors and without clicking anything first. Everything else stays
  blue, so it is clear at a glance which part of a page collects answers.
* Block themes are now edited the way converted themes are: the page, one dark
  toolbar and one popup. WordPress's block toolbar, sidebar and breadcrumb bar
  no longer compete with it; **⋯ → Show WordPress controls** brings them back.
  The WordPress admin bar and side menu stay available.
* A sidebar WordPress remembered from an earlier session no longer opens over
  the workspace. The Search appearance dialog says when the theme or the
  site-wide SEO settings keep Visual Edit from printing titles and
  descriptions on the public site.
* Clicking the block that is already selected reopens its popup after Apply
  or Cancel. Undo reverts one popup session at a time, and Reset styles is
  its own undo step. A responsive value can be set again after it was cleared
  and saved. Number fields ignore text that is not a number.
* Clicking a photo that sits under a theme's overlay or tint (a hero image
  behind its heading layer) selects the image, as in the HTML editor, so it
  can be replaced. Empty decorative groups no longer show WordPress's
  "Select a layout" placeholder over the design.
* WordPress's own editor UI shown inside Visual Edit (the Advanced tab,
  sidebars opened from ⋯, dropdowns, colour pickers and native dialogs)
  follows the popup design: the same type, rounded dark fields, grey labels,
  uppercase section titles, buttons, tabs and menus. Colours are measured after every change, so
  native controls stay readable while swatches and style previews keep their
  real colours. Menus, selects and scrollbars in the
  dark UI no longer show dark text, white tracks or double borders.
* Clicking a block outlines and labels it and opens the popup beside it, at the
  height of the click, without covering it. The popup stays put while you
  scroll, can be pinned, and is a bottom sheet on phones.
* The popup is organised into Content, Style, Section and Advanced tabs, with a
  path to parent blocks, Duplicate / Move / Delete, bold, italic and link for
  text selected on the page, an items list for containers, and "Add a section
  after this one".
* Style has a Desktop / Tablet / Mobile switch that edits the same fields per
  screen and switches the preview. The separate Responsive section is gone.
* Sections locked by WordPress (theme patterns, template parts) offer **Unlock
  design** — WordPress's own "Enable editing all patterns" setting, per session.
* **＋ Section** adds one of the theme's own sections with previews.
* History docks beside the page and says when a restored version still needs
  Save.
* The toolbar is reduced to page, device, undo/redo, History, Section, status,
  preview, More and Save.
* The converted-theme popup gains the same Content / Style / Section tabs, opens
  beside the clicked element, calls its button Apply, and offers RADIUS on every
  element rather than only boxes that hold a field.
* New `window.ClaraVE` editor API shared by both editors, with operations,
  events, `clara_ve.popup.groups` / `clara_ve.popup.footer` /
  `clara_ve.toolbar.more` filters, and the `clara_ve_workspace_config`,
  `clara_ve_workspace_enqueue` and `clara_ve_native_entity_saved` PHP hooks.
* The front-end block editor writes small-screen values into a block's
  `claraVe` attribute when the block already uses it, and shows those values.
* The Gutenberg sidebar integration is no longer loaded inside the workspace,
  where its panel appeared a second time inside the popup's native settings.
* Linking selected text from the popup: Enter applies the link, Esc closes the
  address field, an address without a scheme gets https://, an empty address
  says so instead of doing nothing, and the link still lands on the words
  picked first when the page selection changes while typing. Links inside
  running text get a dotted underline in the editor only, since many themes
  style them exactly like the text around them.
* A link inside the workspace that leads to any other screen (the dashboard, a
  list, the site) opens in the whole window instead of showing a second admin
  bar and menu inside the workspace.
* Document ▾ lists only content that can be opened (Global Styles and menu
  items no longer appear and fail to load), hides Previous/Next when there is
  one page of results, shows load errors readably in the dark menu, and
  WordPress's own site management opens as a labelled link in the whole
  window instead of replacing the workspace inside its frame.
* Native colour palettes inside the workspace show their real colours again
  (the dark skin had recoloured every swatch to the text colour), with room
  around the palette and a faint ring so dark theme colours stay visible.
* The block inserter shows separate rounded tiles with readable icons; block
  icons were painted near-black on the dark panel.
* Style any form from the popup: Form labels, Form fields and Form button
  groups (colours, fonts, sizes, case, letter spacing, field border shape and
  colour, border while typing, placeholder colour, button hover, corners).
  They work on what every form is made of, so a theme's shortcode form,
  Contact Form 7, WPForms or a hand-written HTML form all take the same
  settings, and they are saved on the block like other Visual Edit styling.
* Shortcode blocks show what the shortcode puts on the page on the workspace
  canvas instead of WordPress's text box; the shortcode itself is edited in
  the popup's Content tab.
* More › Site styles opens WordPress's Styles panel again on WordPress 7,
  which shows it only while a template is on screen: the page is shown inside
  its template while the panel is open and returns to the page alone after.
* Notices in Visual Edit's dark dialogs and menus are coloured by kind
  (error, success, warning), and Esc still closes Search appearance after
  saving (focus used to fall back to the page).
* Editable forms. A new Form block with field, text area, choice list,
  checkbox, row and send-button blocks: labels, notes, placeholders, choices,
  required fields, field names and the button text are edited in the popup
  (or WordPress's sidebar), fields are added from the form's popup and
  reordered or removed under Items. Submissions go to Form Submissions and are
  emailed to the Form Settings address — a recipient posted with the form is
  ignored — with the spam checks of connected forms; "Go to page" and
  "Message" set what happens after sending. The form is saved as plain HTML,
  so it stays visible (without sending) if the plugin is switched off.
* "Make this form editable" on a shortcode or HTML block holding a form turns
  it into form blocks, keeping its class names and so its look, its redirect
  and its thank-you sentence; its form styling carries over. One Undo step.
* A form says what it is for — "Contact form" or "Mailing list", the same two words in both editors — with the list picked by name and an optional address for that one form. A signup now reaches the mailing list and the Subscribers screen instead of arriving as an enquiry. What the form was set to is signed into the page, so a visitor cannot retype it into somewhere else; that now covers connected `[wp-form]` forms as well as form blocks, and an unsigned or altered one falls back to the address in Form Settings rather than being refused.
* Each form field keeps its own name in submissions: a second Email, a pasted
  or a duplicated field is numbered (email-2) instead of overwriting the first
  one's value, and renaming a label later does not move its column.

= 1.26.1 =
* Fixed repeated dropdown arrows and light disabled fields in the dark VE popup
  caused by WordPress admin select styles.
* Content-only editing now exposes permitted text/media controls instead of
  disabling the whole panel. Design controls remain restricted by WordPress.
* Recheck block editing mode and bindings when asynchronous media choices return.
* Added Gutenberg save history, per-document staged restores and native Undo/Redo.
* Fixed Google Fonts preview stylesheet duplication and removal.
* Development build: packaged live save/reload acceptance remains outstanding.

= 1.26.0 =
* Gutenberg block themes now open WordPress's complete native Site and Post
  Editors from Visual Edit Lite. This covers every registered block, nested
  editing, Query Loops, navigation, patterns, templates, template parts and
  Global Styles through their native WordPress controls.
* Added a Visual Edit Lite Gutenberg sidebar with per-page search appearance,
  Media Library selection for sharing images and shortcuts to site tools.
* Added selected-block movement and responsive controls to Gutenberg. Page
  responsive values share Gutenberg's save state and undo/redo stack, render
  in the editor preview, and are included in VE history.
* Native Gutenberg saves now feed the existing VE page history while core
  keeps revisions for templates, parts and Global Styles. Autosaves are not
  recorded as explicit saves.

= 1.25.12 =
This edition is derived from Visual Edit Pro 1.25.12.
* Maintenance release, keeping the version in step with the edition it is
  derived from. The three Pro releases since 1.25.9 corrected that edition's
  licence check and its bundled update channel — machinery Lite does not
  contain — so nothing in the editor changes here.

= 1.25.9 =
This edition is derived from Visual Edit Pro 1.25.9.
* Changed: a save refused because an unsaved change points at part of the page
  that is no longer there now says so, and says that Discard clears it.

= 1.25.8 =
This edition is derived from Visual Edit Pro 1.25.8.
* Fixed: a duplicated element came back as scenery — no frame, no click —
  until the page was saved. Duplicating now writes the source first and
  re-renders the canvas from it, so the copy is editable straight away.

= 1.25.7 =
This edition is derived from Visual Edit Pro 1.25.7.
* New: duplicate — any element you can select can be copied, from the icon
  beside delete in the panel's footer. The copy lands after the original and
  keeps everything about it except its id.

= 1.25.6 =
This edition is derived from Visual Edit Pro 1.25.6.
* Fixed: three controls were folded into sections they have nothing to do with
  — Text inside RADIUS, and the FAQ and item editors inside ORNAMENT (AFTER).
  TEXT, QUESTIONS and ITEMS are their own sections now, above the styling.

= 1.25.5 =
This edition is derived from Visual Edit Pro 1.25.5.
* Fixed: a button whose label sits beside an arrow or an icon had no editable
  text at all — no caret and no panel field, because both routes refused an
  element with children. The words are now written into the element's own text
  nodes, so the arrow stays exactly where it was.

= 1.25.4 =
This edition is derived from Visual Edit Pro 1.25.4.
* New: each corner rounds on its own. Radius was one control for all four and
  read the browser's shorthand, so on an element whose corners already differed
  the first nudge squared off the three you were not looking at. Four controls
  now, in a RADIUS section next to BORDER, with a row that still sets all four
  at once.
* Fixed: a radius written as a percentage — how a round avatar is made — was
  read as a number and written back in pixels. The unit a value carries is the
  unit it keeps.
* New: a border can be made transparent, by typing it or by picking Transparent
  from the palette list. Not the same as turning the line off: no line takes up
  no space, and the layout moves.
* Fixed: the panel showed black for a border that was already transparent.
* New: gradients are chosen, not typed — a GRADIENT section with a live
  preview, ready-made gradients built from your theme's own palette, and two
  colours plus a direction to make your own. Custom stays for anything more
  elaborate. Before, a gradient shared a row with the size controls: a 54-pixel
  box suggesting "e.g. 24px", and usually nothing to pick instead.
* New: a gradient background on a raw-HTML theme too — the same GRADIENT
  section, with ready-made gradients from the theme's own palette. It writes
  background-image, so the flat colour underneath survives.
* Fixed: the BORDER Style row read [object Object] for every choice, and the
  only selectable entry was the value already set — so it could not change
  anything.
* Fixed: the editor panel's controls were unstyled in this edition. Deriving
  Lite from Pro cut the AI panel out of the stylesheet and took the panel's own
  rules with it — the number boxes, colour swatches, grids and steppers had no
  styling at all. Only the styling was affected; every control worked.

= 1.25.3 =
This edition is derived from Visual Edit Pro 1.25.3.
* Fixed: after switching themes, Appearance → Menus opened on the menu of the
  theme you had just left, listing its items. The list of menus was right — the
  screen was simply already sitting on the wrong one, because it reopens
  whatever you last edited and that pointer is a plain menu id a theme switch
  does not touch. It now lands on a menu belonging to the theme you are
  actually using, and sites where this has already happened correct themselves
  the first time the screen is opened.

= 1.25.2 =
This edition is derived from Visual Edit Pro 1.25.2.
* New: the style panel folds — each heading opens and closes, and remembers
  which you left open.
* New: padding, margin and border on every element, not only on wrappers.
* Fixed: panel section headings were unstyled here while Pro styled them.
* New: the greyed-out words inside a form field can be changed — click the
  field and the panel offers what it shows before anyone types.
* New: a form's own words are editable again — its labels, its button and any
  small print under it. Those are the design's own wording; only the parts
  WordPress owns stay sealed.
* Fixed: clicking a box that contains a form, a post list or a menu said the
  words in it "come from the post" and "change with every article". Only one of
  those four cases is a post — on a contact form it was simply untrue, and it
  sent people looking in Posts for text that was never there. Each now says
  what it is actually holding.

= 1.25.1 =
This edition is derived from Visual Edit Pro 1.25.1, and brings across
everything Pro added since the last Lite release — none of it licence-gated,
so all of it ships here:
* Block mode: on a block theme, whole sections can be added, copied, moved and
  removed, and the block supports panel is available.
* Movement: scroll and hover animation stored as a class, costing nothing on
  pages that do not use it.
* Different values on smaller screens — padding and the rest can now differ per
  breakpoint, and padding can be dragged on the section itself.
* Copy a page, or remove one, without leaving the editor.
* Typeface handling reworked: a font of your own is kept, a chosen Google font
  actually loads, and added typefaces reach the WordPress editor canvas too.
* Search appearance now works on a block theme.
* Fixes to the picture panel — a new picture appears without saving first, and
  three controls that had never been driven now work.

= 1.19.8 =
* Staggered and carousel lists are collections again. A reveal library's
  per-card delay (`data-aos-delay`/`duration`) and a carousel's frozen runtime
  state (`swiper-slide-active/prev/next/duplicate`,
  `data-swiper-slide-index`) no longer disqualify sibling cards from the
  "manage as a list" panel — those are animation timing and captured state,
  not design differences.
* Listing cards can carry a byline: new `{author}` and `{author_image}`
  placeholders for `[wp-posts]` templates, matching
  `[wp-article field="author"]`.
* Menu labels land on the item's name, never its description. A two-line
  dropdown item (name plus a descriptive sentence) used to get its description
  overwritten by the label on every page; the label now targets the run that
  carries the name.
* Imported blog posts keep their byline. A content bundle may name each
  article's author; the import resolves it to an existing user by display name
  or creates one (role: author) instead of crediting whoever clicked Import.
* Derived from Visual Edit Pro 1.19.8. There is no Lite 1.19.7 — Lite carries
  the version number of the Pro release it was derived from, so the two stay
  comparable at a glance.

= 1.19.6 =
* First public release. Visual Edit Lite is derived from Visual Edit Pro
  1.19.6 and shares its version number so the two stay comparable at a
  glance. Everything the licence gated in Pro — the AI assistant, AI image
  and video tools, Cloudflare Turnstile, theme export — is absent from this
  edition rather than hidden, and there is no licence check, no activation
  call and no bundled updater anywhere in the code.

== Upgrade Notice ==

= 1.27.0 =
Block themes now edit the way converted themes do: the page, one toolbar, one
popup. Forms became editable blocks, and where a form sends is signed into the
page and checked for every form. If your theme was converted from HTML, this
release is important: pages with forms were showing visitors a critical error,
and submissions that got through were being discarded. Both are fixed. Nothing
needs migrating.

= 1.19.8 =
Staggered and carousel card lists are editable as collections again, listing
templates can show an author byline, and menu labels stop overwriting item
descriptions.

= 1.19.6 =
First public release.
