<?php
/**
 * Applying edits to block markup, on the server, one block at a time.
 *
 * The raw-HTML editor applies its patch queue in the browser and POSTs the
 * resulting document. On block markup that is fatal: Gutenberg decides a
 * block is valid by comparing its stored HTML against what the block type
 * would serialize, and a round trip through DOMParser normalises entities,
 * void elements and attribute order. Converting the reference theme produced
 * fifty invalid image blocks from exactly that class of drift.
 *
 * So the browser sends the PATCHES, not the document, and every byte that
 * ends up stored was written here — by parse_blocks, one addressed block, and
 * serialize_blocks.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Block_Patch {

	/**
	 * Which element inside a block holds its editable words, when it is not
	 * the block's own outermost one. A button's label is in its <a>; a
	 * details block's is in its <summary>, with the answer living in child
	 * blocks that must not be touched.
	 */
	const TEXT_TAG = array(
		'core/button'  => 'a',
		'core/details' => 'summary',
	);

	/**
	 * What each capability is allowed to be asked for.
	 *
	 * The capability map alone says a block may be edited; it does not say
	 * how. Without this, `set-text` on a core/group would pass the gate and
	 * be handed to the text writer, which replaces everything between a
	 * block's tags — every column, every card, gone in one save. A capability
	 * that cannot express an operation refuses it here rather than performing
	 * an approximation of it.
	 */
	/**
	 * Movement a block can be given, as a curated list rather than a free
	 * field.
	 *
	 * These are stored as ordinary CSS classes, which is the whole point: a
	 * class is a core attribute every block type understands, so a page keeps
	 * opening in Gutenberg as valid content and keeps rendering if this plugin
	 * is ever switched off — it simply stops moving. Nothing about the
	 * animation lives in a place WordPress does not already know about.
	 */
	const ANIMATION = array( 'fade', 'fade-up', 'fade-down', 'zoom', 'slide-left', 'slide-right' );
	const HOVER     = array( 'lift', 'grow', 'soften', 'dim' );

	const CAPABILITY_OPS = array(
		'text'       => array( 'set-text', 'set-attrs', 'set-style', 'set-responsive' ),
		'image'      => array( 'set-image', 'set-attrs', 'set-style', 'set-responsive' ),
		'button'     => array( 'set-text', 'set-link', 'set-attrs', 'set-style', 'set-responsive' ),
		'section'    => array( 'set-attrs', 'set-style', 'set-responsive' ),
		'details'    => array( 'set-text', 'set-attrs', 'set-style', 'set-responsive' ),
		// Height is an attribute; margin is a real style support.
		'spacer'     => array( 'set-attrs', 'set-style', 'set-responsive' ),
		'style-lite' => array( 'set-attrs', 'set-style', 'set-responsive' ),
	);

	/**
	 * Apply a queue of patches to a post's content.
	 *
	 * Nothing is written unless every patch applies and the result passes the
	 * gate — a half-applied edit is worse than a rejected one, because the
	 * owner has no way to tell which half landed.
	 *
	 * @param int   $post_id
	 * @param array $patches List of ['block' => path, 'op' => …, …].
	 * @return string|WP_Error The new serialized content.
	 */
	public static function apply( $post_id, $patches ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'clara_ve_no_post', __( 'That page no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}
		if ( '' === Clara_VE_Source_Store::block_key( $post ) ) {
			return new WP_Error(
				'clara_ve_not_block',
				__( 'This page is not edited as blocks.', 'visual-edit-lite' ),
				array( 'status' => 400 )
			);
		}
		if ( ! is_array( $patches ) || ! $patches ) {
			return new WP_Error( 'clara_ve_no_patches', __( 'Nothing to apply.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$blocks = parse_blocks( (string) $post->post_content );

		foreach ( $patches as $patch ) {
			// Not empty(): the address of the first block on a page is "0",
			// which PHP calls empty, so every edit to the top of every page
			// was refused as "a change arrived without a target". Nothing in
			// the earlier tests used a bare "0" and the bug lived through
			// four of them.
			if ( ! is_array( $patch )
				|| ! isset( $patch['block'] ) || '' === (string) $patch['block']
				|| ! isset( $patch['op'] ) || '' === (string) $patch['op'] ) {
				return new WP_Error( 'clara_ve_bad_patch', __( 'A change arrived without a target.', 'visual-edit-lite' ), array( 'status' => 400 ) );
			}
			$applied = self::apply_one( $blocks, (string) $patch['block'], $patch, $post_id );
			if ( is_wp_error( $applied ) ) {
				return $applied;
			}
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Add, copy, move or remove a whole top-level block.
	 *
	 * Structural changes are their own route and their own request, and only
	 * ONE happens at a time, because every one of them renumbers the page.
	 * Addresses are positions: remove the second section and everything below
	 * it moves up, so a queue holding "set the text of block 4" alongside
	 * "delete block 2" would apply the first change to whatever slid into
	 * position 4. The client flushes its queue, waits for that to land, sends
	 * the operation, and reloads.
	 *
	 * Top-level only in this version. A nested address would mean editing a
	 * parent's innerContent, where each child is represented by a null
	 * placeholder that has to be inserted or removed in step with the child
	 * itself — accounting this does not do yet, and getting it wrong loses
	 * blocks silently.
	 *
	 * @param int   $post_id
	 * @param array $op op, block, expect, direction, pattern, position.
	 * @return string|WP_Error The new post content.
	 */
	public static function apply_structure( $post_id, $op ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'clara_ve_no_post', __( 'That page no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}
		if ( '' === Clara_VE_Source_Store::block_key( $post ) ) {
			return new WP_Error(
				'clara_ve_not_block',
				__( 'This page is not edited as blocks.', 'visual-edit-lite' ),
				array( 'status' => 400 )
			);
		}

		$kind    = isset( $op['op'] ) ? (string) $op['op'] : '';
		$address = isset( $op['block'] ) ? (string) $op['block'] : '';
		$blocks  = parse_blocks( (string) $post->post_content );

		// Adding a section at the end of the page is the one operation with
		// nothing to point at.
		$needs_target = ! ( 'insert-pattern' === $kind && 'end' === ( $op['position'] ?? '' ) );

		$index = null;
		if ( $needs_target ) {
			$index = self::structural_target( $blocks, $address, isset( $op['expect'] ) ? (string) $op['expect'] : '' );
			if ( is_wp_error( $index ) ) {
				return $index;
			}
		}

		switch ( $kind ) {
			case 'remove':
				// The rules for a section nobody can see any more would sit in
				// the page's meta for good. Recursively: a removed band takes
				// every anchor inside it with it.
				Clara_VE_Responsive::forget( $post_id, self::anchors_within( $blocks[ $index ] ) );
				self::splice_out( $blocks, $index );
				break;

			case 'duplicate':
				// A PHP array copy is a deep one, placeholders and all — which
				// is exactly the problem for anchors: the copy would share
				// every one of them, so tuning the original on a phone would
				// silently tune the copy too. Fresh anchors, and the rules
				// copied onto them, because duplicating a band somebody has
				// already tuned means duplicating the tuning with it.
				$copy = $blocks[ $index ];
				$map  = self::reanchor( $copy );
				Clara_VE_Responsive::copy( $post_id, $map );
				array_splice( $blocks, $index + 1, 0, array( self::separator(), $copy ) );
				break;

			case 'move':
				$direction = ( 'up' === ( $op['direction'] ?? '' ) ) ? -1 : 1;
				$swap      = self::neighbour( $blocks, $index, $direction );
				if ( null === $swap ) {
					return new WP_Error(
						'clara_ve_no_room',
						( -1 === $direction )
							? __( 'This is already the first section on the page.', 'visual-edit-lite' )
							: __( 'This is already the last section on the page.', 'visual-edit-lite' ),
						array( 'status' => 400 )
					);
				}
				$moved             = $blocks[ $index ];
				$blocks[ $index ]  = $blocks[ $swap ];
				$blocks[ $swap ]   = $moved;
				break;

			case 'insert-pattern':
				$pattern = Clara_VE_Patterns::get( isset( $op['pattern'] ) ? (string) $op['pattern'] : '' );
				if ( ! $pattern ) {
					// Looked up through the composable list, not the registry:
					// a pattern the theme hides from the inserter must not be
					// insertable by naming it directly.
					return new WP_Error(
						'clara_ve_no_pattern',
						__( 'That is not a section this theme offers.', 'visual-edit-lite' ),
						array( 'status' => 400 )
					);
				}
				$added = array_values( array_filter(
					parse_blocks( (string) $pattern['content'] ),
					static function ( $block ) {
						// A pattern file's own leading and trailing newlines
						// arrive as freeform entries; the separators between
						// what is inserted are this function's to decide.
						return ! ( null === $block['blockName'] && '' === trim( (string) $block['innerHTML'] ) );
					}
				) );
				if ( ! $added ) {
					return new WP_Error( 'clara_ve_empty_pattern', __( 'That section is empty.', 'visual-edit-lite' ), array( 'status' => 400 ) );
				}

				$spaced = array();
				foreach ( $added as $i => $block ) {
					if ( $i ) {
						$spaced[] = self::separator();
					}
					$spaced[] = $block;
				}

				$position = isset( $op['position'] ) ? (string) $op['position'] : 'after';
				if ( 'end' === $position ) {
					$blocks = array_merge( $blocks, $blocks ? array( self::separator() ) : array(), $spaced );
				} elseif ( 'before' === $position ) {
					array_splice( $blocks, $index, 0, array_merge( $spaced, array( self::separator() ) ) );
				} else {
					array_splice( $blocks, $index + 1, 0, array_merge( array( self::separator() ), $spaced ) );
				}
				break;

			default:
				return new WP_Error( 'clara_ve_bad_op', __( 'Unknown change requested.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Every anchor class inside a block, itself included.
	 *
	 * @param array $block
	 * @return string[]
	 */
	private static function anchors_within( $block ) {
		return Clara_VE_Responsive::anchors_in( serialize_block( $block ) );
	}

	/**
	 * Give a copied block and everything inside it fresh anchors.
	 *
	 * @param array $block By reference.
	 * @return array old anchor => new anchor.
	 */
	private static function reanchor( &$block ) {
		$map  = array();
		$walk = static function ( &$node ) use ( &$walk, &$map ) {
			if ( ! empty( $node['attrs']['className'] ) ) {
				$classes = preg_split( '/\s+/', (string) $node['attrs']['className'], -1, PREG_SPLIT_NO_EMPTY );
				$changed = false;
				foreach ( $classes as $i => $class ) {
					if ( 0 !== strpos( $class, Clara_VE_Responsive::ANCHOR_PREFIX ) ) {
						continue;
					}
					if ( ! isset( $map[ $class ] ) ) {
						$map[ $class ] = Clara_VE_Responsive::new_anchor();
					}
					$classes[ $i ] = $map[ $class ];
					$changed       = true;
				}
				if ( $changed ) {
					$node['attrs']['className'] = implode( ' ', $classes );
					// The markup carries the class too, and both halves have
					// to say the same thing or the block will not open.
					foreach ( $map as $from => $to ) {
						$node['innerHTML'] = str_replace( $from, $to, (string) $node['innerHTML'] );
						foreach ( (array) $node['innerContent'] as $i => $piece ) {
							if ( null !== $piece ) {
								$node['innerContent'][ $i ] = str_replace( $from, $to, (string) $piece );
							}
						}
					}
				}
			}
			if ( ! empty( $node['innerBlocks'] ) ) {
				foreach ( $node['innerBlocks'] as &$child ) {
					$walk( $child );
				}
				unset( $child );
			}
		};
		$walk( $block );
		return $map;
	}

	/**
	 * The blank line between two top-level blocks.
	 *
	 * parse_blocks() reports the whitespace between top-level blocks as
	 * freeform entries rather than dropping it, which is why addresses on a
	 * normal page step by two. Anything inserted has to bring its own, or the
	 * page serializes with sections run together.
	 *
	 * @return array
	 */
	private static function separator() {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => "\n\n",
			'innerContent' => array( "\n\n" ),
		);
	}

	/**
	 * Resolve a structural address, refusing everything this cannot do safely.
	 *
	 * @param array  $blocks
	 * @param string $address
	 * @param string $expect  Block name the client believes is there.
	 * @return int|WP_Error
	 */
	private static function structural_target( $blocks, $address, $expect ) {
		if ( '' === $address || ! preg_match( '/^\d{1,4}$/', $address ) ) {
			// A nested address is not merely unsupported, it is the case that
			// would lose blocks quietly — see apply_structure().
			return new WP_Error(
				'clara_ve_nested',
				__( 'Only whole sections of the page can be moved or removed here. Open the page in WordPress for anything inside one.', 'visual-edit-lite' ),
				array( 'status' => 400 )
			);
		}
		$index = (int) $address;
		if ( ! isset( $blocks[ $index ] ) || empty( $blocks[ $index ]['blockName'] ) ) {
			return new WP_Error(
				'clara_ve_no_block',
				__( 'That part of the page has moved since it was opened. Reload and try again.', 'visual-edit-lite' ),
				array( 'status' => 409 )
			);
		}
		$name = (string) $blocks[ $index ]['blockName'];

		// The client sends what it believes is there. Positions shift under a
		// second person editing the same page, and a structural operation on
		// the wrong section is not something anybody notices until later.
		if ( '' !== $expect && $expect !== $name ) {
			return new WP_Error(
				'clara_ve_stale',
				__( 'That part of the page has moved since it was opened. Reload and try again.', 'visual-edit-lite' ),
				array( 'status' => 409 )
			);
		}

		if ( in_array( $name, Clara_VE_Block_Stamp::DYNAMIC, true ) ) {
			return new WP_Error(
				'clara_ve_not_editable',
				sprintf(
					/* translators: %s: a block type such as core/query. */
					__( '%s is drawn from the site rather than stored on this page, so it is not moved or removed here.', 'visual-edit-lite' ),
					$name
				),
				array( 'status' => 400 )
			);
		}
		return $index;
	}

	/**
	 * The next real block in a direction, stepping over the whitespace.
	 *
	 * The entry beside a block in the parsed array is usually the blank line
	 * after it, not the next section. Swapping with THAT moves a section past
	 * a newline and leaves the page exactly as it was.
	 *
	 * @param array $blocks
	 * @param int   $index
	 * @param int   $direction -1 or 1.
	 * @return int|null
	 */
	private static function neighbour( $blocks, $index, $direction ) {
		for ( $i = $index + $direction; isset( $blocks[ $i ] ); $i += $direction ) {
			if ( ! empty( $blocks[ $i ]['blockName'] ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * Remove a block and the blank line that belongs to it.
	 *
	 * Without this the separators pile up: delete three sections and the page
	 * carries three orphaned blank lines, every remaining address counts past
	 * them, and the markup grows a gap nobody put there.
	 *
	 * @param array $blocks By reference.
	 * @param int   $index
	 */
	private static function splice_out( &$blocks, $index ) {
		$after = ( isset( $blocks[ $index + 1 ] ) && empty( $blocks[ $index + 1 ]['blockName'] ) );
		if ( $after ) {
			array_splice( $blocks, $index, 2 );
			return;
		}
		// Nothing below it, so the blank line above is the one left dangling.
		$before = ( isset( $blocks[ $index - 1 ] ) && empty( $blocks[ $index - 1 ]['blockName'] ) );
		array_splice( $blocks, $before ? $index - 1 : $index, $before ? 2 : 1 );
	}

	/**
	 * Walk to the addressed block and hand it to the operation.
	 *
	 * By reference the whole way down, so the caller's tree is what changes —
	 * rebuilding the tree from a returned copy is where an "it saved but
	 * nothing moved" bug lives.
	 *
	 * @param array  $blocks By reference.
	 * @param string $path   e.g. "0-2-1".
	 * @param array  $patch
	 * @return true|WP_Error
	 */
	private static function apply_one( &$blocks, $path, $patch, $post_id = 0 ) {
		$steps  = array_map( 'intval', explode( '-', $path ) );
		$cursor = &$blocks;
		$block  = null;

		foreach ( $steps as $depth => $step ) {
			if ( ! isset( $cursor[ $step ] ) ) {
				return new WP_Error(
					'clara_ve_no_block',
					__( 'That part of the page has moved since it was opened. Reload and try again.', 'visual-edit-lite' ),
					array( 'status' => 409 )
				);
			}
			$block = &$cursor[ $step ];
			if ( $depth < count( $steps ) - 1 ) {
				if ( ! isset( $block['innerBlocks'] ) ) {
					return new WP_Error( 'clara_ve_no_block', __( 'That part of the page has moved since it was opened. Reload and try again.', 'visual-edit-lite' ), array( 'status' => 409 ) );
				}
				$cursor = &$block['innerBlocks'];
			}
		}

		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		if ( ! isset( Clara_VE_Block_Stamp::CAPABILITY[ $name ] ) ) {
			return new WP_Error(
				'clara_ve_not_editable',
				sprintf(
					/* translators: %s: a block type such as core/columns. */
					__( '%s is not editable here — open the page in WordPress to change it.', 'visual-edit-lite' ),
					$name ? $name : __( 'That element', 'visual-edit-lite' )
				),
				array( 'status' => 400 )
			);
		}

		$op      = (string) $patch['op'];
		$allowed = self::CAPABILITY_OPS[ Clara_VE_Block_Stamp::CAPABILITY[ $name ] ] ?? array();
		if ( ! in_array( $op, $allowed, true ) ) {
			return new WP_Error(
				'clara_ve_not_editable',
				sprintf(
					/* translators: %s: a block type such as core/group. */
					__( 'That is not something %s can be asked to change here.', 'visual-edit-lite' ),
					$name
				),
				array( 'status' => 400 )
			);
		}

		switch ( $op ) {
			case 'set-text':
				return self::set_text( $block, isset( $patch['html'] ) ? (string) $patch['html'] : '' );
			case 'set-attrs':
				return self::set_attrs( $block, isset( $patch['attrs'] ) ? (array) $patch['attrs'] : array() );
			case 'set-image':
				return self::set_image( $block, $patch );
			case 'set-style':
				return Clara_VE_Block_Supports::apply_style( $block, isset( $patch['style'] ) ? (array) $patch['style'] : array() );
			case 'set-responsive':
				return self::set_responsive( $block, $post_id, $patch );
			case 'set-link':
				return self::set_link(
					$block,
					isset( $patch['href'] ) ? (string) $patch['href'] : '',
					// Absent means "this edit was not about the target" —
					// distinct from '' , which means the person unticked it.
					array_key_exists( 'target', $patch ) ? (string) $patch['target'] : null
				);
		}

		return new WP_Error( 'clara_ve_bad_op', __( 'Unknown change requested.', 'visual-edit-lite' ), array( 'status' => 400 ) );
	}

	/**
	 * Replace a block's visible text.
	 *
	 * innerHTML and innerContent are written together on purpose:
	 * serialize_blocks() re-emits stored innerContent, it does not regenerate
	 * HTML from attributes, so a block whose two halves disagree serializes
	 * to whichever one the caller did not update — silently.
	 *
	 * @param array  $block By reference.
	 * @param string $html  The new inner HTML, already sanitized.
	 * @return true|WP_Error
	 */
	private static function set_text( &$block, $html ) {
		$html    = wp_kses_post( $html );
		$target  = self::TEXT_TAG[ (string) $block['blockName'] ] ?? null;
		$segment = self::open_segment( $block );
		$inner   = ( null === $segment ) ? null : self::replace_inner( $segment, $html, $target );
		if ( null === $inner ) {
			return new WP_Error(
				'clara_ve_unwritable',
				__( 'That block is not shaped the way this editor knows how to change. Open the page in WordPress instead.', 'visual-edit-lite' ),
				array( 'status' => 422 )
			);
		}
		self::write_open_segment( $block, $inner );

		// core/heading and core/paragraph keep no copy of their text in
		// attributes — the HTML is the content. core/button does: its `text`
		// attribute is what the editor shows, and leaving it stale makes the
		// block open in WordPress showing the words it used to say.
		if ( 'core/button' === $block['blockName'] ) {
			$block['attrs']['text'] = $html;
		}
		return true;
	}

	/**
	 * Give a block a different value on a smaller screen.
	 *
	 * Two halves in one operation, because half of it is useless: the rule is
	 * stored against an anchor class, and the block has to be carrying that
	 * class for the rule to reach it. A block that has never been tuned gets
	 * one here, written through the ordinary className path so the attribute
	 * and the markup stay in step exactly as they do for anything else.
	 *
	 * @param array $block By reference.
	 * @param int   $post_id
	 * @param array $patch
	 * @return true|WP_Error
	 */
	private static function set_responsive( &$block, $post_id, $patch ) {
		if ( ! $post_id ) {
			return new WP_Error( 'clara_ve_no_post', __( 'That page no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}

		$anchor = self::anchor_of( $block );
		if ( '' === $anchor ) {
			$anchor  = Clara_VE_Responsive::new_anchor();
			$classes = ! empty( $block['attrs']['className'] )
				? preg_split( '/\s+/', (string) $block['attrs']['className'], -1, PREG_SPLIT_NO_EMPTY )
				: array();
			$classes[] = $anchor;
			$added     = self::set_attrs( $block, array( 'className' => implode( ' ', $classes ) ) );
			if ( is_wp_error( $added ) ) {
				return $added;
			}
		}

		return Clara_VE_Responsive::set(
			$post_id,
			$anchor,
			isset( $patch['breakpoint'] ) ? (string) $patch['breakpoint'] : '',
			isset( $patch['path'] ) ? (string) $patch['path'] : '',
			isset( $patch['value'] ) ? (string) $patch['value'] : ''
		);
	}

	/**
	 * The anchor class a block already carries, if any.
	 *
	 * @param array $block
	 * @return string
	 */
	private static function anchor_of( $block ) {
		if ( empty( $block['attrs']['className'] ) ) {
			return '';
		}
		foreach ( preg_split( '/\s+/', (string) $block['attrs']['className'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			if ( 0 === strpos( $class, Clara_VE_Responsive::ANCHOR_PREFIX ) ) {
				return $class;
			}
		}
		return '';
	}

	/**
	 * @param array $block By reference.
	 * @param array $attrs
	 * @return true|WP_Error
	 */
	private static function set_attrs( &$block, $attrs ) {
		// What the class list says NOW. Changing className has to take the old
		// classes out of the markup as well as putting the new ones in, and
		// this is the only moment at which the old value is still known.
		$was = ! empty( $block['attrs']['className'] )
			? preg_split( '/\s+/', (string) $block['attrs']['className'], -1, PREG_SPLIT_NO_EMPTY )
			: array();

		// Movement is not an attribute of its own — it is a class, translated
		// here so everything downstream treats it as one. A block type that
		// does not take extra classes cannot take movement either.
		// Carried across both, or setting a scroll effect and a hover effect in
		// one breath would leave only the second: each pass has to build on
		// the last one's answer, not on what the block said before either.
		$classes = $was;
		$touched = false;

		foreach ( array( 'veAnimation' => 'anim', 'veHover' => 'hover' ) as $key => $prefix ) {
			if ( ! array_key_exists( $key, $attrs ) ) {
				continue;
			}
			$value   = (string) $attrs[ $key ];
			$allowed = ( 'anim' === $prefix ) ? self::ANIMATION : self::HOVER;
			if ( '' !== $value && ! in_array( $value, $allowed, true ) ) {
				return new WP_Error(
					'clara_ve_bad_attr',
					__( 'That is not a movement this editor offers.', 'visual-edit-lite' ),
					array( 'status' => 400 )
				);
			}
			// No core block declares this false — the check is here for a block
			// type that does, so movement is never stored where its own save()
			// would not write it back.
			$type = WP_Block_Type_Registry::get_instance()->get_registered( (string) $block['blockName'] );
			if ( $type && isset( $type->supports['customClassName'] ) && false === $type->supports['customClassName'] ) {
				return new WP_Error(
					'clara_ve_not_editable',
					sprintf(
						/* translators: %s: a block type such as core/spacer. */
						__( '%s takes no classes of its own, so it cannot be given movement.', 'visual-edit-lite' ),
						$block['blockName']
					),
					array( 'status' => 400 )
				);
			}

			$classes = array_values( array_filter(
				$classes,
				static function ( $class ) use ( $prefix ) {
					return 0 !== strpos( $class, 'cve-' . $prefix . '-' );
				}
			) );
			if ( '' !== $value ) {
				$classes[] = 'cve-' . $prefix . '-' . $value;
			}
			$touched = true;
			unset( $attrs[ $key ] );
		}
		if ( $touched ) {
			$attrs['className'] = implode( ' ', $classes );
		}

		// Only the presentation attributes this version claims to understand,
		// and only values shaped like a theme.json preset slug. An attribute
		// map arriving from a browser is untrusted input that ends up inside
		// a block delimiter; anything not on this list is dropped rather than
		// stored and puzzled over later.
		$allowed = array(
			// Not wide/full — those are AN ALIGNMENT OF THE BLOCK, which is
			// `align`, not an alignment of its text. Accepting them here wrote
			// a value no block declares.
			'textAlign'       => '/^(left|center|right)$/',
			'align'           => '/^(left|center|right|wide|full)$/',
			'backgroundColor' => '/^[a-z0-9-]{1,60}$/',
			'textColor'       => '/^[a-z0-9-]{1,60}$/',
			'fontSize'        => '/^[a-z0-9-]{1,60}$/',
			'fontFamily'      => '/^[a-z0-9-]{1,60}$/',
			'borderColor'     => '/^[a-z0-9-]{1,60}$/',
			'gradient'        => '/^[a-z0-9-]{1,60}$/',
			'className'       => '/^[A-Za-z0-9 _-]{0,200}$/',
			'level'           => '/^[1-6]$/',
			// core/spacer. A bare number is what its own control produces for
			// pixels; the unit spellings are what typing into it produces.
			'height'          => '/^\d{1,4}(px|rem|em|vh|%)?$/',
		);

		// A preset slug and a custom value are two spellings of one decision,
		// so they answer to the same support check. Without this the style
		// path was gated and the attribute path was not: core/separator's
		// colour, refused as a style, still went in as `backgroundColor` and
		// came back matching a deprecated save.
		$support_path = array(
			'textColor'       => 'color.text',
			'backgroundColor' => 'color.background',
			'fontSize'        => 'typography.fontSize',
			'fontFamily'      => 'typography.fontFamily',
			'textAlign'       => 'typography.textAlign',
			'borderColor'     => 'border.color',
			'gradient'        => 'color.gradient',
		);
		$block_name = (string) $block['blockName'];

		foreach ( $attrs as $name => $value ) {
			if ( ! isset( $allowed[ $name ] ) ) {
				continue;
			}
			if ( isset( $support_path[ $name ] ) && null !== $value && '' !== $value
				// A block that carries alignment as its own attribute rather
				// than as a typography support declares no support to find.
				&& ! ( 'textAlign' === $name && isset( Clara_VE_Block_Supports::TEXT_ALIGN_ATTR[ $block_name ] ) )
				&& ! Clara_VE_Block_Supports::supports( $block_name, $support_path[ $name ] ) ) {
				return new WP_Error(
					'clara_ve_unsupported_style',
					sprintf(
						/* translators: 1: a block type such as core/separator, 2: an attribute name. */
						__( '%1$s has no %2$s to set.', 'visual-edit-lite' ),
						$block_name,
						$name
					),
					array( 'status' => 400 )
				);
			}
			if ( null === $value || '' === $value ) {
				unset( $block['attrs'][ $name ] );
				continue;
			}
			$value = (string) $value;
			if ( ! preg_match( $allowed[ $name ], $value ) ) {
				return new WP_Error(
					'clara_ve_bad_attr',
					sprintf(
						/* translators: %s: an attribute name such as textColor. */
						__( 'That is not a value this site defines for %s.', 'visual-edit-lite' ),
						$name
					),
					array( 'status' => 400 )
				);
			}
			$block['attrs'][ $name ] = ( 'level' === $name ) ? (int) $value : $value;
		}

		// Text alignment is not an attribute on most blocks. core's own
		// block.json settles it: paragraph, heading and button declare
		// `supports.typography.textAlign` and have NO textAlign attribute, so
		// the value belongs in style.typography.textAlign; core/quote declares
		// the attribute and no support, so there it is the other way round.
		// Writing the wrong one leaves markup carrying a class the block's own
		// save() would never produce — which is the definition of the "invalid
		// content" notice.
		if ( array_key_exists( 'textAlign', $attrs ) && ! isset( Clara_VE_Block_Supports::TEXT_ALIGN_ATTR[ $block['blockName'] ] ) ) {
			unset( $block['attrs']['textAlign'] );
			$moved = Clara_VE_Block_Supports::apply_style(
				$block,
				array( 'typography' => array( 'textAlign' => ( '' === $attrs['textAlign'] || null === $attrs['textAlign'] ) ? null : $attrs['textAlign'] ) )
			);
			if ( is_wp_error( $moved ) ) {
				return $moved;
			}
		}

		// A preset attribute renders through a CLASS, and for a static block
		// nobody adds that class at render time — the block's own save() bakes
		// it into the stored markup. So storing "textColor":"accent" and
		// nothing else gives a page that has changed in the database and not
		// on screen. Both halves, always, same rule as the heading below.
		$synced = Clara_VE_Block_Supports::sync( $block, $was );
		if ( is_wp_error( $synced ) ) {
			return $synced;
		}

		// A heading's level lives in BOTH the attribute and the tag name, and
		// only the tag name is what a visitor sees.
		if ( 'core/heading' === $block['blockName'] && isset( $attrs['level'] ) ) {
			$level = (int) $attrs['level'];
			$html  = preg_replace( '~^(\s*)<h[1-6]\b~i', '$1<h' . $level, (string) $block['innerHTML'] );
			$html  = preg_replace( '~</h[1-6]>(\s*)$~i', '</h' . $level . '>$1', (string) $html );
			self::write_html( $block, $html );
		}
		return true;
	}

	/**
	 * The classes WordPress renders a preset attribute through, and the ones
	 * it uses that must come off when the attribute is cleared.
	 *
	 * Transcribed from what each block's save() emits. Class ORDER is not
	 * among them: Gutenberg's validator compares a class attribute as an
	 * unordered set, which is what makes rewriting the list here safe at all.
	 *
	 * @param string $attribute
	 * @return array{add:string[],drop:string[]} drop entries are regexes.
	 */
	private static function preset_classes( $attribute, $slug ) {
		$map = array(
			'textColor'       => array(
				'add'  => array( 'has-%s-color', 'has-text-color' ),
				'drop' => array( '~^has-(?!.*-background-color$).+-color$~', '~^has-text-color$~' ),
			),
			'backgroundColor' => array(
				'add'  => array( 'has-%s-background-color', 'has-background' ),
				'drop' => array( '~^has-.+-background-color$~', '~^has-background$~' ),
			),
			'fontSize'        => array(
				'add'  => array( 'has-%s-font-size' ),
				'drop' => array( '~^has-.+-font-size$~' ),
			),
			'fontFamily'      => array(
				'add'  => array( 'has-%s-font-family' ),
				'drop' => array( '~^has-.+-font-family$~' ),
			),
			'textAlign'       => array(
				'add'  => array( 'has-text-align-%s' ),
				'drop' => array( '~^has-text-align-.+$~' ),
			),
		);
		if ( ! isset( $map[ $attribute ] ) ) {
			return array( 'add' => array(), 'drop' => array() );
		}
		$add = array();
		if ( '' !== (string) $slug ) {
			foreach ( $map[ $attribute ]['add'] as $template ) {
				$add[] = sprintf( $template, $slug );
			}
		}
		return array( 'add' => $add, 'drop' => $map[ $attribute ]['drop'] );
	}

	/**
	 * Bring a block's stored markup into line with the preset attributes just
	 * written to it.
	 *
	 * @param array $block By reference.
	 * @param array $attrs The attributes the caller asked for, as given.
	 * @return void
	 */
	private static function sync_preset_classes( &$block, $attrs ) {
		$relevant = array_intersect_key(
			$attrs,
			array_flip( array( 'textColor', 'backgroundColor', 'fontSize', 'fontFamily', 'textAlign' ) )
		);
		if ( ! $relevant ) {
			return;
		}

		// A button's presets are rendered by its <a>, not by the <div> the
		// block wraps — the same element its label lives in.
		$tag_name = ( 'core/button' === $block['blockName'] ) ? 'A' : null;
		$tags     = new WP_HTML_Tag_Processor( (string) $block['innerHTML'] );
		$found    = $tag_name ? $tags->next_tag( array( 'tag_name' => $tag_name ) ) : $tags->next_tag();
		if ( ! $found ) {
			return;
		}

		$classes = preg_split( '/\s+/', trim( (string) $tags->get_attribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		$classes = $classes ? $classes : array();

		foreach ( $relevant as $attribute => $slug ) {
			$rules   = self::preset_classes( $attribute, (string) $slug );
			$classes = array_values(
				array_filter(
					$classes,
					static function ( $class ) use ( $rules ) {
						foreach ( $rules['drop'] as $pattern ) {
							if ( preg_match( $pattern, $class ) ) {
								return false;
							}
						}
						return true;
					}
				)
			);
			$classes = array_merge( $classes, $rules['add'] );
		}

		$tags->set_attribute( 'class', implode( ' ', array_unique( $classes ) ) );
		self::write_html( $block, $tags->get_updated_html() );
	}

	/**
	 * Swap a core/image's picture.
	 *
	 * Deliberately does NOT write width/height: WordPress adds those when it
	 * renders, and an <img> carrying dimensions its block does not declare is
	 * the exact drift that flagged fifty images invalid at once. The gate
	 * would catch it; not writing it is better than being caught.
	 *
	 * @param array $block By reference.
	 * @param array $patch
	 * @return true|WP_Error
	 */
	private static function set_image( &$block, $patch ) {
		$url = isset( $patch['url'] ) ? esc_url_raw( (string) $patch['url'] ) : '';
		$id  = isset( $patch['id'] ) ? (int) $patch['id'] : 0;
		$alt = isset( $patch['alt'] ) ? sanitize_text_field( (string) $patch['alt'] ) : null;

		$tags  = new WP_HTML_Tag_Processor( (string) $block['innerHTML'] );
		$found = false;
		while ( $tags->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
			$found = true;
			if ( '' !== $url ) {
				$tags->set_attribute( 'src', $url );
				$tags->remove_attribute( 'srcset' );
				$tags->remove_attribute( 'sizes' );
			}
			if ( null !== $alt ) {
				$tags->set_attribute( 'alt', $alt );
			}
			if ( $id ) {
				// The wp-image-{id} class is how the editor knows which
				// attachment a picture is; leaving the old one behind makes
				// the media panel offer to replace a file that is no longer
				// there.
				$classes = (string) $tags->get_attribute( 'class' );
				$classes = trim( preg_replace( '/\bwp-image-\d+\b/', '', $classes ) );
				$tags->set_attribute( 'class', trim( $classes . ' wp-image-' . $id ) );
			}
			break;
		}
		if ( ! $found ) {
			return new WP_Error( 'clara_ve_no_image', __( 'That block has no picture in it.', 'visual-edit-lite' ), array( 'status' => 422 ) );
		}

		self::write_html( $block, $tags->get_updated_html() );
		if ( $id ) {
			$block['attrs']['id'] = $id;
		}
		if ( '' !== $url ) {
			$block['attrs']['url'] = $url;
		}
		if ( null !== $alt ) {
			$block['attrs']['alt'] = $alt;
		}

		// Where the picture leads rides this same patch rather than a second
		// one, because wrapping an image in a link changes the shape of the
		// block's markup — a later patch addressing the old shape would land
		// on the wrapper instead of the picture.
		if ( array_key_exists( 'link', $patch ) && null !== $patch['link'] ) {
			$linked = self::set_image_link(
				$block,
				esc_url_raw( (string) $patch['link'] ),
				isset( $patch['linkTarget'] ) ? (string) $patch['linkTarget'] : ''
			);
			if ( is_wp_error( $linked ) ) {
				return $linked;
			}
		}
		return true;
	}

	/**
	 * Wrap a core/image's picture in a link, move an existing one, or take it
	 * away when the address is cleared.
	 *
	 * String surgery rather than a DOM library, for the same reason
	 * replace_inner() is: the bytes around the picture are copied, never
	 * regenerated, so the entities and void-element spelling the block editor
	 * compares against stay exactly as they were.
	 *
	 * @param array  $block  By reference.
	 * @param string $href   Empty removes the link.
	 * @param string $target '_blank' or ''.
	 * @return true|WP_Error
	 */
	private static function set_image_link( &$block, $href, $target ) {
		$html = (string) $block['innerHTML'];

		if ( '' === $href ) {
			// Unwrap: keep the <img>, drop the anchor around it.
			$unwrapped = preg_replace( '~<a\b[^>]*>(\s*<img\b[^>]*/?>)\s*</a\s*>~i', '$1', $html, 1 );
			if ( null !== $unwrapped && $unwrapped !== $html ) {
				self::write_html( $block, $unwrapped );
			}
			unset( $block['attrs']['href'], $block['attrs']['linkTarget'], $block['attrs']['rel'] );
			$block['attrs']['linkDestination'] = 'none';
			return true;
		}

		if ( preg_match( '~<a\b[^>]*>\s*<img\b~i', $html ) ) {
			// Already wrapped — retarget the anchor in place.
			$tags = new WP_HTML_Tag_Processor( $html );
			if ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
				$tags->set_attribute( 'href', $href );
				self::write_link_target( $tags, $target );
				self::write_html( $block, $tags->get_updated_html() );
			}
		} else {
			$anchor = '<a href="' . esc_url( $href ) . '"'
				. ( '_blank' === $target ? ' target="_blank" rel="noreferrer noopener"' : '' ) . '>';
			$wrapped = preg_replace( '~(<img\b[^>]*/?>)~i', $anchor . '$1</a>', $html, 1 );
			if ( null === $wrapped || $wrapped === $html ) {
				return new WP_Error( 'clara_ve_no_image', __( 'That block has no picture to link.', 'visual-edit-lite' ), array( 'status' => 422 ) );
			}
			self::write_html( $block, $wrapped );
		}

		$block['attrs']['href']            = $href;
		$block['attrs']['linkDestination'] = 'custom';
		if ( '_blank' === $target ) {
			$block['attrs']['linkTarget'] = '_blank';
			$block['attrs']['rel']        = 'noreferrer noopener';
		} else {
			unset( $block['attrs']['linkTarget'], $block['attrs']['rel'] );
		}
		return true;
	}

	/**
	 * Point a block's link somewhere else — the <a> inside a button, or the
	 * one wrapping an image.
	 *
	 * @param array       $block  By reference.
	 * @param string      $href
	 * @param string|null $target '_blank', '' to clear, or null to leave the
	 *                            current setting alone.
	 * @return true|WP_Error
	 */
	private static function set_link( &$block, $href, $target = null ) {
		$href  = esc_url_raw( $href );
		$tags  = new WP_HTML_Tag_Processor( (string) $block['innerHTML'] );
		$found = false;
		while ( $tags->next_tag( array( 'tag_name' => 'A' ) ) ) {
			$found = true;
			$tags->set_attribute( 'href', $href );
			if ( null !== $target ) {
				self::write_link_target( $tags, $target );
			}
			break;
		}
		if ( ! $found ) {
			return new WP_Error( 'clara_ve_no_link', __( 'That block has no link in it.', 'visual-edit-lite' ), array( 'status' => 422 ) );
		}
		self::write_html( $block, $tags->get_updated_html() );
		if ( 'core/button' === $block['blockName'] ) {
			$block['attrs']['url'] = $href;
			if ( null !== $target ) {
				self::write_link_target_attrs( $block, $target );
			}
		}
		return true;
	}

	/**
	 * Opening in a new tab, on the element and in the block's attributes.
	 *
	 * `rel` travels with `target` rather than being a separate choice: a link
	 * that opens a new tab without noreferrer/noopener hands the new document
	 * a handle on the one that opened it. Gutenberg writes exactly this pair,
	 * and writing anything else here would make the block disagree with what
	 * its own editor would produce.
	 *
	 * @param WP_HTML_Tag_Processor $tags   Positioned on the <a>.
	 * @param string                $target '_blank' or ''.
	 * @return void
	 */
	private static function write_link_target( $tags, $target ) {
		$rel = (string) $tags->get_attribute( 'rel' );

		if ( '_blank' === $target ) {
			$tags->set_attribute( 'target', '_blank' );
			foreach ( array( 'noreferrer', 'noopener' ) as $token ) {
				if ( ! preg_match( '/(^|\s)' . $token . '(\s|$)/', $rel ) ) {
					$rel = trim( $rel . ' ' . $token );
				}
			}
			$tags->set_attribute( 'rel', $rel );
			return;
		}

		$tags->remove_attribute( 'target' );
		$rel = trim( preg_replace( '/\s+/', ' ', str_replace( array( 'noreferrer', 'noopener' ), '', $rel ) ) );
		if ( '' === $rel ) {
			// Removed, not emptied: an empty attribute still counts as one
			// when the block editor compares what it would have written.
			$tags->remove_attribute( 'rel' );
		} else {
			$tags->set_attribute( 'rel', $rel );
		}
	}

	/**
	 * The same decision, mirrored into a core/button's attributes.
	 *
	 * @param array  $block  By reference.
	 * @param string $target
	 * @return void
	 */
	private static function write_link_target_attrs( &$block, $target ) {
		if ( '_blank' === $target ) {
			$block['attrs']['linkTarget'] = '_blank';
			$block['attrs']['rel']        = 'noreferrer noopener';
			return;
		}
		unset( $block['attrs']['linkTarget'], $block['attrs']['rel'] );
	}

	/**
	 * Both halves of a block's stored HTML, always together.
	 *
	 * @param array  $block By reference.
	 * @param string $html
	 * @return void
	 */
	public static function write_html( &$block, $html ) {
		$block['innerHTML']    = $html;
		$block['innerContent'] = array( $html );
	}

	/**
	 * The part of a block's markup that carries its opening tag.
	 *
	 * A block with children does not store its HTML as one string. A group
	 * holding a paragraph stores
	 * `[ "<div class=\"wp-block-group\">", null, "</div>" ]` — the null is
	 * where serialize_blocks() puts the child back. innerHTML is only the
	 * concatenation of the non-null pieces, so it reads as
	 * `<div class="wp-block-group"></div>`: an element that appears to be
	 * closed and empty. Writing that back over the whole of innerContent is
	 * how a container silently loses every block inside it.
	 *
	 * Everything this editor changes about a container — its classes, its
	 * style, a details block's <summary> — is in the first non-null piece, so
	 * that is the piece read and the piece written.
	 *
	 * @param array $block
	 * @return string|null Null when there is nothing to write to.
	 */
	public static function open_segment( $block ) {
		$content = ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) )
			? $block['innerContent']
			: array( (string) $block['innerHTML'] );
		foreach ( $content as $piece ) {
			if ( null !== $piece ) {
				return (string) $piece;
			}
		}
		return null;
	}

	/**
	 * Put back what open_segment() read, leaving the children's placeholders
	 * where they were.
	 *
	 * @param array  $block By reference.
	 * @param string $html
	 * @return bool
	 */
	public static function write_open_segment( &$block, $html ) {
		$content = ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) )
			? $block['innerContent']
			: array( (string) $block['innerHTML'] );
		$written = false;
		foreach ( $content as $i => $piece ) {
			if ( null !== $piece ) {
				$content[ $i ] = $html;
				$written       = true;
				break;
			}
		}
		if ( ! $written ) {
			return false;
		}
		$block['innerContent'] = $content;
		// innerHTML is the concatenation of the pieces that are really there;
		// it is what the rest of this plugin reads, and it has to agree.
		$block['innerHTML'] = implode( '', array_filter( $content, static function ( $piece ) {
			return null !== $piece;
		} ) );
		return true;
	}

	/**
	 * Replace what is between an element's tags, leaving the tags exactly as
	 * they were.
	 *
	 * A string operation rather than a DOM one, and that is the point: every
	 * DOM library in PHP rewrites entities and void elements on the way out,
	 * and byte drift in stored block HTML is what makes Gutenberg call a
	 * block invalid. The bytes outside the replaced region are copied, not
	 * regenerated.
	 *
	 * @param string      $html   The block's stored HTML.
	 * @param string      $inner  What to put inside.
	 * @param string|null $tag    Which element to fill — null means the
	 *                            outermost one, 'a' means the first link
	 *                            inside it (a button's label is in the <a>,
	 *                            not the <div> around it).
	 * @return string|null Null when the markup is not the simple shape this
	 *                     understands, which is the caller's cue to refuse.
	 */
	private static function replace_inner( $html, $inner, $tag = null ) {
		if ( null === $tag ) {
			if ( ! preg_match( '~^(\s*<([a-z][a-z0-9]*)\b[^>]*>)~i', $html, $open ) ) {
				return null;
			}
			$name = strtolower( $open[2] );
			if ( ! preg_match( '~(</' . $name . '\s*>\s*)$~i', $html, $close ) ) {
				return null;
			}
			return $open[1] . $inner . $close[1];
		}

		$pattern = '~(<' . preg_quote( $tag, '~' ) . '\b[^>]*>)(.*?)(</' . preg_quote( $tag, '~' ) . '\s*>)~is';
		if ( ! preg_match( $pattern, $html, $match, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = $match[2][1];
		$end   = $start + strlen( $match[2][0] );
		return substr( $html, 0, $start ) . $inner . substr( $html, $end );
	}
}
