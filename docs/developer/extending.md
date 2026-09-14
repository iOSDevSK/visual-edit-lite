# Extending

What you can extend without touching the source, and what you cannot. The
honest version.

## The short answer

**There is a small API, and it stops well short of a plugin platform.** Since
1.27 the editor exposes `window.ClaraVE` — a documented operation vocabulary
with a write path — and four JavaScript filters that accept render callbacks,
so a panel can be added to the popup without patching it. On the PHP side
there are twelve hooks: some are read-only signals, several accept a handler
that changes behaviour, and one (`clara_ve_theme_contract`) is how a converted
theme declares itself at all.

What there is not is an extension point for most of the plugin's own
behaviour. If you need something under "requires editing the source" below,
you are forking or patching.

## What is genuinely pluggable

### The editor, from JavaScript

`window.ClaraVE` — selection, operations, documents, history and events,
identical in both editors — plus `clara_ve.popup.groups`,
`clara_ve.popup.footer`, `clara_ve.toolbar.more` and `clara_ve.form.blocks`,
which take render callbacks. See the [editor API](editor-api.md).

### The PHP hooks

Read-only signals: `clara_ve_source_saved`, `clara_ve_content_imported`,
`clara_ve_native_entity_saved` — invalidate whatever you derive from page
content, react to an import, react to a native save.

Hooks that change behaviour: `clara_ve_theme_contract` (a converted theme's
whole declaration), `clara_ve_required_anchors` (what a save must preserve),
`clara_ve_block_gate_violations` (reject a write the gate would allow),
`clara_ve_menu_zone_markup` (render a navigation zone yourself),
`clara_ve_seed_menus` (menus to scaffold on activation),
`clara_ve_trusted_proxies` (trust a CDN other than Cloudflare),
`clara_ve_ignore_theme_owner` (bypass the foreign-data guard),
`clara_ve_workspace_config` and `clara_ve_workspace_enqueue` (add to the
workspace). See [Hooks and filters](hooks-and-filters.md).

### Mail, via WordPress core

`pre_wp_mail` and `phpmailer_init` are core filters, so **a separate plugin
can add its own mail transport independently.** Visual Edit's own handler
cooperates: if another handler has already claimed the message, it returns
without touching it.

This is the only path that genuinely bypasses the plugin's own dispatch, and
it works. The trade-off is that it also bypasses the plugin's settings UI.

### Token templates

The markup **inside** a token is entirely yours:

```
[wp-posts count="6"]
  <article class="whatever-you-like">
    <img src="{image}"><h3>{title}</h3>
  </article>
[/wp-posts]
```

Attributes are parsed generically and placeholders substituted by name. So
`[wp-posts]`, `[wp-menu]` and the article template's `share` and `nav`
fragments let you author arbitrary per-item markup without touching PHP.

**This is the real extension surface** — templating, not new types.

### The SEO graph, via the host plugin's hooks

If Yoast or Rank Math is installed, their graph filters
(`wpseo_schema_graph`, `rank_math/json_ld`) are open to you the same way they
are to this plugin, which is itself a consumer of them.

### Read `list_keys()`, do not re-derive it

Anything enumerating editable pages should call
`Clara_VE_Source_Store::list_keys()` rather than deriving the list itself.
There was a second, drifting copy once and it cost real debugging.

## What requires editing the source

### A new token type

Two places, minimum:

- the master pattern, a hardcoded alternation
  `(posts|form|menu|article)`
- the dispatch, a `switch` over those four

There is no filter on the pattern, no registry, no filter on rendered output.
You would likely also touch the tables deciding a zone wrapper's tag and the
fast-bail string check.

### A new mail provider

Three places: the dispatch switch, the list of API mailers, and the settings
class — where a provider needs its own option constant, a registration, an
entry in the key lookup, an entry in the secret list, an entry in the
never-export list, and a UI block.

**Or** use `pre_wp_mail` from your own plugin and skip all of it.

### A new mailing-list provider

Same shape: a hardcoded provider list, a hardcoded comparison in the readiness
check, and a direct API call. Three edits.

## Why it is like this

The plugin is delivered with a converted site rather than installed from a
directory, so it has had exactly one consumer and no third-party integrations
to design for. Registries and filters that nobody uses are speculative
generality, and every one of them is a contract you then cannot change.

If that changes — if there are integrators — the shapes above are where the
seams would go. They are all `switch` statements with a stable set of cases,
which is a fine thing to turn into a registry later and a poor thing to build
one for now.

## If you fork

The plugin is GPL-2.0-or-later, so you may.

Two things worth knowing before you do:

**The internal prefix is `clara_ve_`**, not `visual_edit_`. It is the plugin's
original name and was deliberately kept through the rename: it is the key to
every stored option, table, meta key and content package. Renaming it orphans
every existing install from its data and invalidates every exported bundle.
It is data, not branding.

**The two invariants** in [Architecture](architecture.md) — positional paths,
and extract-never-author — are load-bearing. Most of the non-obvious code
exists to keep one of them true, and breaking either produces failures that are
silent rather than loud.

## Related

- [Architecture](architecture.md)
- [Hooks and filters](hooks-and-filters.md)
- [REST API](rest-api.md)
