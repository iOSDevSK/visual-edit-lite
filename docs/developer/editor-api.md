# Editor API

`window.ClaraVE` is one JavaScript surface for both editors: the Gutenberg
workspace used on block themes and the raw-HTML editor used on converted
themes. The popup and any extension — Visual Edit Pro's assistant, for
example — change a page through the same operations, with the same
permission checks.

Nothing here saves. In the workspace every operation is an ordinary unsaved
editor change: **Undo** reverts it and the toolbar's **Save** publishes it.
In the HTML editor operations join the patch queue that **Save** publishes.

## Loading

Depend on the `clara-ve-api` script handle and enqueue from the
`clara_ve_workspace_enqueue` action:

```php
add_action( 'clara_ve_workspace_enqueue', function ( $workspace, $is_site, $mode ) {
	if ( 'native' === $mode ) {
		return; // the plain WordPress editor, without the VE chrome
	}
	wp_enqueue_script( 'my-extension', plugins_url( 'extension.js', __FILE__ ), array( 'clara-ve-api', 'wp-hooks' ), '1.0', true );
}, 10, 3 );
```

```js
ClaraVE.ready( function ( ve ) {
	ve.on( 'select', function ( selection ) { console.log( selection ); } );
} );
```

## Methods

| Method | Returns |
|---|---|
| `ClaraVE.mode` | `'block'`, `'html'`, or `null` before an editor registers |
| `ready( callback )` | Runs `callback( ClaraVE )` now, or once an editor registers |
| `getSelection()` | `{ id, name, title, attributes, editingMode, parents, sectionName }`, or `null` |
| `select( id )` | Selects a block and opens its popup. `false` in the HTML editor |
| `openPopup()` / `closePopup()` | Reopens or closes the popup for the selection |
| `apply( ops )` | Promise of `{ applied: [ index ], refused: [ { index, op, reason } ] }` |
| `getDocument()` | `{ mode, type, id, title, content }` for the open document |
| `history.list()` | Promise of `{ entity: { type, id, title }, entries: [...] }` for the open document, newest first |
| `history.restore( id )` | Promise. Block mode stages the version (Save publishes); HTML mode restores directly |
| `on( event, callback )` | Subscribe; returns an unsubscribe function |

Events: `ready`, `select`, `apply`, `save`, `restore`, `unlock`.

## Operations

`id` is a block client ID in the workspace and an element address such as
`path-0-2-1` in the HTML editor.

| Operation | Payload | Workspace | HTML editor |
|---|---|---|---|
| `set-text` | `{ id, html }` (or `text`) | paragraph, heading, list item, button | text, tags stripped |
| `set-attrs` | `{ id, attrs }` | Top-level attributes; `metadata` and `lock` are refused | — |
| `set-style` | `{ id, style: { 'typography.fontSize': '32px' } }` | Paths under the block's `style` | Block paths are translated to CSS; CSS property names also work |
| `set-link` | `{ id, href, target }` | button, navigation link | any link |
| `set-image` | `{ id, url, attachmentId, alt, mediaType }` | image, cover, video, audio, Kadence image | image |
| `convert-to-video` | `{ id, url, attachmentId, poster }` | An image (core or Kadence) becomes a muted, looping `core/video`; a cover switches its background to the video | image → video |
| `set-responsive` | `{ id, breakpoint: 'tablet' \| 'mobile', path, value }` | `claraVe.responsive` | — |
| `set-ornament` | `{ id, pseudo: 'before' \| 'after', props }` | `claraVe.ornaments` | — |
| `set-motion` | `{ id, entrance, hover }` | `cve-anim-*` / `cve-hover-*` classes | — |
| `remove` / `duplicate` | `{ id }` | Structural, respecting locks | — |
| `move` | `{ id, direction: 'up' \| 'down' }` | Structural, respecting locks | — |
| `insert-pattern` | `{ pattern, id?, position?: 'before' \| 'after' }` | One of the active theme's own sections | — |

Every write re-checks WordPress's editing mode and bindings at the moment it
runs. Content-only blocks accept content attributes only; bound attributes are
refused. An empty value removes a style or responsive value. Operations are
applied in order, and a refusal does not stop the ones after it.

Responsive paths are the ones the renderer understands:
`spacing.padding.{top,right,bottom,left}`, `spacing.margin.{top,bottom}`,
`typography.fontSize`, `typography.textAlign`, `dimensions.minHeight` and
`display` (`none` hides the block on that screen).

## Popup and toolbar hooks

These `wp.hooks` filters run in the workspace:

| Filter | Value | Context |
|---|---|---|
| `clara_ve.popup.groups` | Array of `{ key, tab: 'content' \| 'style' \| 'section', title, open, render }`; `render()` returns React elements | `{ block, attributes, mode, screen, registry, write, writeMany, canWrite, element, fields }` |
| `clara_ve.popup.top` | Array of `{ key, label, title, onClick }` shown as full-width buttons at the top of the popup's first tab | `{ block, attributes, mode, registry }` |
| `clara_ve.popup.footer` | Array of `{ key, label, title, onClick }` | `{ block, attributes, mode, registry }` |
| `clara_ve.toolbar.extras` | Array of elements (each with a `key`) placed in the toolbar just before the save status | `{ registry, status, element }` |
| `clara_ve.toolbar.more` | Array of `{ key, label, onClick }` or `{ key, label, href, top }` for the **More** menu | `{ registry, status }` |
| `clara_ve.form.blocks` | Block names that always get the **Form labels / fields / button** style groups. Default `[ 'core/shortcode', 'core/html', 'clara-ve/form' ]`; blocks whose name contains `form`, and blocks showing form controls on the canvas, get them regardless | `block` |

```js
wp.hooks.addFilter( 'clara_ve.popup.groups', 'my-extension/ask', function ( groups, context ) {
	return groups.concat( [ {
		key: 'ask', tab: 'content', title: 'Ask',
		render: function () { return [ context.element.createElement( 'p', { key: 'p' }, 'Selected: ' + context.block.name ) ]; }
	} ] );
} );
```

**Docks on the right edge.** History and any extension's side panel share the
right edge of the workspace. Whoever opens a dock dispatches
`clara-ve-dock-opened` on `window` with `detail.dock` naming it; every other
dock closes when it hears an event that is not its own. History uses
`dock: 'history'`.

```js
window.dispatchEvent( new CustomEvent( 'clara-ve-dock-opened', { detail: { dock: 'my-panel' } } ) );
window.addEventListener( 'clara-ve-dock-opened', function ( event ) {
	if ( event.detail.dock !== 'my-panel' ) { closeMyPanel(); }
} );
```

`ClaraVEValues.placePopup( rect, pointer, size, viewport )` is the placement
rule both popups use, for an extension that positions its own panel.

## PHP hooks

| Hook | Type | Arguments |
|---|---|---|
| `clara_ve_workspace_config` | filter | `$config`, `$workspace` — the `claraVeGutenberg` script configuration |
| `clara_ve_workspace_enqueue` | action | `$workspace`, `$is_site`, `$mode` (`block`, `native` or `html`) |
| `clara_ve_native_entity_saved` | action | `$type`, `$id`, `$request` — after a successful native save is versioned |

## Storage of small-screen values

The canonical place is the block's own `claraVe.responsive` attribute. It
travels with copies, patterns and revisions, and it never changes the block's
markup, so a block cannot become invalid through it. The front end renders it
through a content-addressed class.

Older pages carry rules in the `_clara_ve_responsive` post meta keyed by a
`cve-r-*` anchor class. Those rules keep rendering and are read by both
editors. The workspace moves a block's rules into the attribute the first time
they are edited there; the front-end block editor writes into the attribute
whenever a block already has one. An extension that renders pages without
Visual Edit Lite active must emit `claraVe.responsive` itself, or those
small-screen values will not appear.
