<?php
/**
 * Server-stamped block addresses for the edit preview.
 *
 * The raw-HTML editor stamps positional paths in the browser and leans on
 * "rendered DOM ≡ stored source". Block markup breaks that exactly where it
 * matters: a Query Loop is ONE stored block rendering N cards, so a click
 * inside the third card resolves to a path that exists nowhere in storage.
 * Addresses are therefore computed from the parsed tree on the server, and a
 * block that cannot be addressed unambiguously is simply not stamped —
 * uneditable by construction rather than by a check somebody has to remember.
 *
 * Nothing here runs outside an authorized edit preview. The public page is
 * never stamped.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Block_Stamp {

	const ATTR   = 'data-ve-block';
	const CAP    = 'data-ve-edit';
	const NAME   = 'data-ve-name';
	const STORED = 'data-ve-stored';

	/**
	 * Blocks whose rendered output is not their stored markup, or is stored
	 * once and rendered many times. Nothing inside one of these is ever
	 * addressable: patching "the third card's title" would rewrite the one
	 * template every card is drawn from.
	 */
	const DYNAMIC = array(
		'core/query',
		'core/post-template',
		'core/template-part',
		'core/navigation',
		'core/block',
		'core/pattern',
	);

	/**
	 * What can be changed about each block this version understands, in the
	 * bridge's own vocabulary. Everything absent from this map renders
	 * normally and is read-only.
	 */
	const CAPABILITY = array(
		'core/paragraph' => 'text',
		'core/heading'   => 'text',
		'core/list-item' => 'text',
		'core/image'     => 'image',
		'core/button'    => 'button',

		// Containers. Their words belong to the blocks inside them, so these
		// offer styling only — see Clara_VE_Block_Patch::CAPABILITY_OPS, which
		// is what stops a padding control from being handed to the text
		// writer and emptying the container.
		'core/group'     => 'section',
		'core/columns'   => 'section',
		'core/column'    => 'section',
		'core/quote'     => 'section',
		'core/list'      => 'section',
		// core/cover declares color.background as FALSE — its background is an
		// overlay of its own, set elsewhere — so the support check refuses it
		// while allowing the padding, margin, typography and text colour it
		// really does have.
		'core/cover'     => 'section',

		// A details block's question is its <summary>, which is markup rather
		// than an attribute; the answer below it is ordinary child blocks.
		'core/details'   => 'details',

		'core/spacer'    => 'spacer',
		'core/separator' => 'style-lite',
	);

	/** @var array|null hash => list of ['path' => string, 'cap' => string] */
	private static $index = null;

	/** @var array hash => how many times it has been rendered this request */
	private static $seen = array();

	/** @var bool Whether the content currently rendering is the target post's. */
	private static $inside_content = false;

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_hook' ), 5 );
	}

	/**
	 * Only in an authorized edit preview, and only for a post the block driver
	 * owns. Both conditions are the reason this file can be written without
	 * any consideration for what it would do to a visitor: it never runs for
	 * one.
	 */
	public static function maybe_hook() {
		if ( ! clara_ve_is_edit_preview() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || '' === Clara_VE_Source_Store::block_key( $post ) ) {
			return;
		}

		self::$index = self::index_for( $post );

		// The flag exists because render_block fires for the header, the
		// footer and every template block too, and a paragraph in the footer
		// can be byte-identical to one in the content. Stamping is confined to
		// the window in which the post's own content is being rendered.
		add_filter( 'the_content', array( __CLASS__, 'open_content' ), 1 );
		add_filter( 'the_content', array( __CLASS__, 'close_content' ), 999 );
		add_filter( 'render_block', array( __CLASS__, 'stamp' ), 20, 2 );
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public static function open_content( $content ) {
		self::$inside_content = true;
		// Reset per application, not per request. `the_content` runs more than
		// once on an ordinary page load — wp_trim_excerpt() puts the content
		// through it whenever a post has no excerpt of its own, and a theme
		// building og:description does exactly that during wp_head, before the
		// body renders at all. Without this the first pass consumes every
		// occurrence counter and the real render stamps nothing: the preview
		// comes up with no editable regions and no error anywhere.
		self::$seen = array();
		return $content;
	}

	/**
	 * @param string $content
	 * @return string
	 */
	public static function close_content( $content ) {
		self::$inside_content = false;
		return $content;
	}

	/**
	 * Address every block in a post's stored content.
	 *
	 * Keyed by the block's own serialized bytes rather than by position,
	 * because position is what the renderer will not tell us: render_block
	 * fires innermost-first and says nothing about where in the tree it is.
	 * The same bytes appearing twice get two paths, consumed in render order.
	 *
	 * @param WP_Post $post
	 * @return array hash => list of ['path' => string, 'cap' => string]
	 */
	public static function index_for( $post ) {
		$index = array();
		$walk  = static function ( $blocks, $prefix, $dynamic ) use ( &$walk, &$index ) {
			foreach ( $blocks as $i => $block ) {
				$name = (string) $block['blockName'];
				$path = ( '' === $prefix ) ? (string) $i : $prefix . '-' . $i;
				$here = $dynamic || in_array( $name, self::DYNAMIC, true );

				if ( ! $here && '' !== $name ) {
					$hash = md5( serialize_block( $block ) );
					if ( ! isset( $index[ $hash ] ) ) {
						$index[ $hash ] = array();
					}
					$index[ $hash ][] = array(
						'path' => $path,
						'cap'  => isset( self::CAPABILITY[ $name ] ) ? self::CAPABILITY[ $name ] : 'none',
						// The panel decides what to offer from this: a heading
						// gets a level control, a quote does not.
						'name' => $name,
					);
				}

				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'], $path, $here );
				}
			}
		};
		$walk( parse_blocks( (string) $post->post_content ), '', false );
		return $index;
	}

	/**
	 * Put the address on the block's outermost element.
	 *
	 * A block whose bytes have already been rendered as many times as they
	 * occur in storage gets nothing — that is the Query Loop's second card,
	 * and it is precisely the click that must not resolve.
	 *
	 * @param string $content Rendered HTML.
	 * @param array  $block   Parsed block.
	 * @return string
	 */
	/**
	 * The style values that live only in the block's attributes.
	 *
	 * @param array $block Parsed block.
	 * @return array<string,string> dot path => value.
	 */
	private static function stored_only( $block ) {
		$style = isset( $block['attrs']['style'] ) && is_array( $block['attrs']['style'] )
			? $block['attrs']['style']
			: array();
		$out = array();
		if ( isset( $style['spacing']['blockGap'] ) && is_scalar( $style['spacing']['blockGap'] ) ) {
			$out['spacing.blockGap'] = (string) $style['spacing']['blockGap'];
		}
		if ( isset( $style['dimensions']['aspectRatio'] ) ) {
			$out['dimensions.aspectRatio'] = (string) $style['dimensions']['aspectRatio'];
		}
		if ( isset( $style['position']['type'] ) ) {
			$out['position.type'] = (string) $style['position']['type'];
		}
		return $out;
	}

	/**
	 * The anchor class tying a block to its small-screen rules.
	 *
	 * @param array $block
	 * @return string
	 */
	private static function anchor_of( $block ) {
		if ( empty( $block['attrs']['className'] ) || ! class_exists( 'Clara_VE_Responsive' ) ) {
			return '';
		}
		foreach ( preg_split( '/\s+/', (string) $block['attrs']['className'], -1, PREG_SPLIT_NO_EMPTY ) as $class ) {
			if ( 0 === strpos( $class, Clara_VE_Responsive::ANCHOR_PREFIX ) ) {
				return $class;
			}
		}
		return '';
	}

	public static function stamp( $content, $block ) {
		if ( ! self::$inside_content || null === self::$index || '' === trim( (string) $content ) ) {
			return $content;
		}
		if ( empty( $block['blockName'] ) ) {
			return $content;
		}

		$hash = md5( serialize_block( $block ) );
		if ( empty( self::$index[ $hash ] ) ) {
			return $content;
		}
		$nth = isset( self::$seen[ $hash ] ) ? self::$seen[ $hash ] : 0;
		self::$seen[ $hash ] = $nth + 1;
		if ( ! isset( self::$index[ $hash ][ $nth ] ) ) {
			return $content;
		}
		$entry = self::$index[ $hash ][ $nth ];

		$tags = new WP_HTML_Tag_Processor( $content );
		if ( ! $tags->next_tag() ) {
			return $content;
		}
		$tags->set_attribute( self::ATTR, $entry['path'] );
		$tags->set_attribute( self::CAP, $entry['cap'] );
		$tags->set_attribute( self::NAME, $entry['name'] );

		// Properties a block stores but never writes into its markup — the
		// gap between its children, its aspect ratio, whether it sticks. The
		// browser has no way to see them, so a panel reading the page alone
		// would show them blank on a block that has them set, and the owner
		// would be looking at a control that had forgotten their choice.
		$stored = self::stored_only( $block );
		if ( $stored ) {
			$tags->set_attribute( self::STORED, wp_json_encode( $stored ) );
		}

		// Small-screen rules live in the page's meta, not in the block, so the
		// panel would have no way to show what a block is already tuned to.
		$anchor = self::anchor_of( $block );
		if ( '' !== $anchor ) {
			$rules = Clara_VE_Responsive::rules( get_queried_object_id() );
			if ( ! empty( $rules[ $anchor ] ) ) {
				$tags->set_attribute( 'data-ve-responsive', wp_json_encode( $rules[ $anchor ] ) );
			}
		}
		return $tags->get_updated_html();
	}
}

Clara_VE_Block_Stamp::init();
