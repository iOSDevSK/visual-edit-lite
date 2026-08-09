# SEO

Per-page search appearance, site-wide identity, and how this behaves alongside
Yoast SEO or Rank Math.

## The design in one paragraph

Visual Edit keeps **one SEO record per page** in its own storage. If Yoast or
Rank Math is installed, it **writes through** to that plugin so their UI,
their sitemap and their output all see your values. If neither is installed,
it emits the tags itself. It never does both — the moment an SEO plugin
appears, Visual Edit stops emitting and hands over.

## Per-page: the Search appearance panel

In the editor, the **🔍** button in the toolbar.

| Field | |
|---|---|
| **Title** | With a `n / 60` counter |
| **Description** | With a `n / 155` counter |
| **Sharing image** | Used when the page is shared on social media |
| **Hide this page from search engines** | Adds `noindex` |

Above them, a Google-style preview updating as you type. Leave a field blank
and the preview shows what WordPress would actually emit instead.

The counters are **guidance, not limits**. Google truncates by pixel width,
not character count, so a title of 62 characters may well be fine. Red means
"likely to be cut", not "rejected".

### Why there is no keyword field or readability score

Because they would be worse than nothing here. Those tools analyse
`post_content`, which on a converted site is one block of raw HTML. They would
be grading markup rather than writing, and confidently reporting problems that
are not real.

### With Yoast or Rank Math installed

The panel keeps working and says so: *"Yoast SEO is installed, so these are
saved into it too"*, with a link to that plugin's own settings for the page.

It is a two-way arrangement. Saving here writes to both. Reading shows
whatever is actually live — so a value you changed in Yoast's sidebar appears
here. One truth, edited from either place.

## What was carried over from your original site

If your site had `<title>` tags, meta descriptions, Open Graph tags or
canonical links before conversion, they came across. You are editing values
that already exist, not filling in blanks.

## Site-wide: SEO & Sharing

**Visual Edit → SEO & Sharing.**

| Setting | |
|---|---|
| **Business or personal name** | Defaults to the site title |
| **What it is** | Organization, LocalBusiness, ProfessionalService, or Person |
| **Logo** | Used in structured data and search results |
| **Default sharing image** | Used when a page has none of its own |
| **Profiles elsewhere** | One URL per line — social profiles, directory listings |
| **Title separator** | The character between page title and site name |
| **AI assistants** | Whether AI crawlers are explicitly allowed |

### About "What it is"

Four options, not the whole schema.org vocabulary. Picking the exact right
subtype is a research project with little payoff, and **Organization is never
wrong**. Choose the closest and move on.

This is asked rather than guessed because it is a classification, and the
plugin's rule is to extract what is there rather than invent something that
is not.

### Profiles elsewhere

These become `sameAs` in structured data — the machine-readable statement that
"this business is also that Instagram account and that LinkedIn page". It is
one of the stronger signals for a search engine or an AI assistant
consolidating what it knows about you.

Only absolute `http(s)` URLs are kept.

### These settings still matter with Yoast installed

They describe the **business**, which is information Yoast does not carry.
A notice on the screen says so.

## Working with Yoast or Rank Math

### Detection

Either plugin is detected by its presence, and every check happens at the
moment a page renders rather than once at startup. That sounds like a detail;
it is not. Plugins load in alphabetical order, so `visual-edit` loads before
`wordpress-seo`. Deciding at startup would mean the decision was made before
Yoast existed, and every page would ship two titles, two descriptions and two
canonicals.

### If you install an SEO plugin later

Everything you have already set is **copied into it automatically**, on the
next admin page load, without overwriting anything you have set there.

Then a notice asks you to run **SEO → Tools → Optimize SEO Data** in Yoast.
That is not optional: Yoast serves its output from its own index, built when
posts are saved, so it will not show the copied values until it re-indexes.

The same notice mentions that Yoast's readability and keyword analysis will
grade your markup rather than your writing, and where to turn both off. It
tells you rather than changing another plugin's settings for you.

### The duplicate `<title>` fix

With Yoast installed on a block theme, WordPress emits **two** `<title>` tags.
Yoast removes the classic theme's title function but not the block theme's.

Visual Edit removes the leftover one. Verified reproducible with this plugin
deactivated — not ours to have caused, but ours to fix, because a converted
site is a block theme site and would ship the bug otherwise.

## When neither plugin is installed

Visual Edit emits everything itself: description, canonical, the full Open
Graph set, Twitter Card tags, structured data and robots directives.

Two details:

**Descriptions are extracted, never invented.** A post's excerpt, a term's
description, the site tagline. If there is nothing to use, no tag is emitted —
a wrong description is worse than none.

**Robots go through WordPress's own mechanism** rather than a second `<meta
name="robots">` tag, so there is exactly one on the page. Thin archive pages —
date archives, author archives, attachment pages — are set to `noindex,
follow`: WordPress invents these URLs and your design never covered them, but
their links still lead somewhere real.

## Related

- [AI readiness](ai-readiness.md) — structured data, `llms.txt`, the audit
- [Redirects](redirects.md) — keeping old URLs alive after conversion
