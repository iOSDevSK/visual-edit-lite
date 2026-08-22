<?php
/**
 * Writing block styling the way the block editor would have written it.
 *
 * A block's appearance lives in two places at once: attributes inside the
 * delimiter, and the class + style attributes baked into the saved HTML by the
 * block type's own save(). Change one without the other and the page either
 * looks unchanged (attribute only) or opens in Gutenberg as "unexpected or
 * invalid content" (markup only). This class is the single authority for
 * keeping the two halves in step.
 *
 * What it does NOT have to do is reproduce Gutenberg's bytes exactly. The
 * validator compares `style` as an unordered map of normalised properties and
 * `class` as an unordered set (wp-includes/js/dist/blocks.js) — so declaration
 * order and class order are free, and only the SET of each has to match, along
 * with whether the attribute is present at all. That last part is why an empty
 * style is removed rather than written as style="".
 *
 * The declarations themselves come from WordPress's own style engine, which is
 * the server twin of the JavaScript one the editor uses — same metadata, same
 * spacing-preset expansion (var:preset|spacing|50 → var(--wp--preset--spacing--50)).
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Block_Supports {

	/**
	 * Which descendant carries the class and style attributes, when it is not
	 * the block's outermost element.
	 *
	 * core/button is the odd one: its wrapper <div class="wp-block-button">
	 * carries nothing, and every colour, typography and spacing declaration
	 * lands on the <a> inside it.
	 */
	const TARGET_TAG = array(
		'core/button' => 'A',
	);

	/**
	 * Blocks that style TWO elements at once.
	 *
	 * core/image is the only one so far, and it is not a general mechanism —
	 * it is what core's own save() does, read from getSaveContent() rather
	 * than guessed. A bordered image comes out as:
	 *
	 *   <figure class="wp-block-image has-custom-border" style="margin-top:12px">
	 *     <img class="has-border-color" style="border-radius:8px">
	 *
	 * so the border and the shadow land on the picture, the spacing stays on
	 * the figure around it, and the figure carries a marker class for any
	 * border at all — radius alone included. Writing all of it to one element,
	 * which is what every other block wants, produces a block Gutenberg
	 * refuses to open.
	 */
	const INNER_STYLE = array(
		'core/image' => array(
			'tag'           => 'IMG',
			'groups'        => array( 'border', 'shadow' ),
			'wrapper_class' => 'has-custom-border',
			'wrapper_when'  => 'border',
		),
	);

	/**
	 * Blocks whose text alignment is a top-level attribute rather than a
	 * typography support.
	 *
	 * Read from core's own block.json: paragraph, heading and button declare
	 * `supports.typography.textAlign` and have no textAlign attribute at all,
	 * so their alignment belongs in style.typography.textAlign. core/quote
	 * declares the attribute and no support, so it is the other way round.
	 * Writing the wrong one leaves markup carrying a class the block's save()
	 * would not produce.
	 */
	const TEXT_ALIGN_ATTR = array(
		'core/quote' => true,
	);

	/**
	 * Preset-capable properties: the style path a custom value lives at, and
	 * the attribute a preset slug lives at instead.
	 *
	 * The two are mutually exclusive — the editor moves a value from one to
	 * the other rather than holding both — so setting either clears the other.
	 */
	const PRESET_PAIRS = array(
		'color.text'          => 'textColor',
		'color.background'    => 'backgroundColor',
		'color.gradient'      => 'gradient',
		'border.color'        => 'borderColor',
		'typography.fontSize' => 'fontSize',
		'typography.fontFamily' => 'fontFamily',
	);

	/**
	 * Which support flag each style path needs the block to declare.
	 *
	 * Read from the registry rather than listed here, because the answers are
	 * not guessable: core/column supports padding but NOT margin, core/spacer
	 * supports neither colour nor typography, core/image supports no
	 * typography at all. Writing a property a block does not support produces
	 * markup its own save() would never emit — an invalid block, discovered
	 * by the owner weeks later.
	 *
	 * Deliberately NOT modelled: __experimentalSkipSerialization. It reads
	 * like "do not write this", but core/button sets it on typography, colour
	 * and spacing and is styled successfully all the same — it means "the
	 * block writes this itself, somewhere else", which is what TARGET_TAG is
	 * for. Blocks with a bespoke recipe of their own (core/separator's
	 * colour) are settled by the validity matrix, not by this flag.
	 *
	 * Sides are not checked either: core/button declares its padding as
	 * ["horizontal","vertical"] yet accepts top/bottom in the matrix, so a
	 * side-level rule would refuse what demonstrably works.
	 */
	const SUPPORT_FLAG = array(
		'border.radius'             => '__experimentalBorder.radius',
		'border.width'              => '__experimentalBorder.width',
		'border.style'              => '__experimentalBorder.style',
		'border.color'              => '__experimentalBorder.color',
		// A single-level support key, unlike every other entry here.
		'shadow'                    => 'shadow',
		'color.gradient'            => 'color.gradients',
		'dimensions.minHeight'      => 'dimensions.minHeight',
		'dimensions.aspectRatio'    => 'dimensions.aspectRatio',
		'spacing.blockGap'          => 'spacing.blockGap',
		'position.type'             => 'position.sticky',
		'typography.fontSize'       => 'typography.fontSize',
		'typography.lineHeight'     => 'typography.lineHeight',
		'typography.textAlign'      => 'typography.textAlign',
		'typography.fontFamily'     => 'typography.__experimentalFontFamily',
		'typography.fontWeight'     => 'typography.__experimentalFontWeight',
		'typography.fontStyle'      => 'typography.__experimentalFontStyle',
		'typography.textTransform'  => 'typography.__experimentalTextTransform',
		'typography.textDecoration' => 'typography.__experimentalTextDecoration',
		'typography.letterSpacing'  => 'typography.__experimentalLetterSpacing',
		'color.text'                => 'color.text',
		'color.background'          => 'color.background',
		'spacing.padding'           => 'spacing.padding',
		'spacing.margin'            => 'spacing.margin',
	);

	/**
	 * Supports a block declares but this editor will not write.
	 *
	 * core/separator declares a background colour and styles it by a recipe of
	 * its own: `has-text-color` plus `has-{slug}-color` for a preset, and for
	 * a custom value BOTH `background-color` and `color` inline. The generic
	 * writer produces the ordinary `has-background` spelling instead, and the
	 * validity matrix said so plainly — the custom row came back invalid, and
	 * the preset row only "passed" by matching one of the block's DEPRECATED
	 * saves, which is worse: WordPress migrates such a block silently on the
	 * next open, and the preset attribute is already gone by then.
	 *
	 * So it is cut, per the plan's rule for a property the matrix rejects.
	 * Separator keeps its margin, which is written the ordinary way and
	 * passes. Reinstating the colour means implementing core's separator
	 * save() against the file rather than from memory, and re-running the
	 * matrix.
	 */
	const CUT = array(
		// The gradient belongs with the colour, and for the same reason: the
		// probe showed core/separator's own save() writing `has-background`
		// and NO declaration for it, because its colour supports carry
		// __experimentalSkipSerialization. The generic writer would emit the
		// declaration, and a block whose style map has one more entry than
		// its save() would produce is invalid.
		'core/separator' => array( 'color.background', 'color.text', 'color.gradient' ),
	);

	/**
	 * Properties a block STORES but never writes into its markup.
	 *
	 * These are real, supported, and applied at render time — the layout
	 * support turns blockGap into a rule for a generated container class, and
	 * position and aspect ratio are handled the same way. What matters here is
	 * that their save() emits nothing, so writing a declaration for them puts
	 * one more entry in the style map than the block would have produced. That
	 * is the definition of invalid content.
	 *
	 * Read from core, not assumed: getSaveContent() returned markup identical
	 * to the plain block for all three, while wp_style_engine_get_styles()
	 * happily produced `aspect-ratio:16/9` for one of them. The engine and the
	 * block do not agree, and the block is the authority.
	 */
	const STORED_ONLY = array(
		'spacing.blockGap',
		'dimensions.aspectRatio',
		'position',
	);

	/**
	 * Does this block declare the support a style path needs?
	 *
	 * @param string $name Block name.
	 * @param string $path Dot path into the style object.
	 * @return bool
	 */
	public static function supports( $name, $path ) {
		// Several paths carry a leaf the support is not declared per: a spacing
		// side, a position's offset. The support lives one level up.
		$key = $path;
		if ( 0 === strpos( $path, 'spacing.' ) ) {
			$parts = explode( '.', $path );
			$key   = $parts[0] . '.' . $parts[1];
		} elseif ( 0 === strpos( $path, 'position.' ) ) {
			$key = 'position.type';
		}
		if ( ! isset( self::SUPPORT_FLAG[ $key ] ) ) {
			return false;
		}
		if ( in_array( $key, self::CUT[ $name ] ?? array(), true ) ) {
			return false;
		}

		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type ) {
			return false;
		}
		$supports = (array) $type->supports;
		$steps    = explode( '.', self::SUPPORT_FLAG[ $key ] );
		$group    = $steps[0];

		// `shadow` is declared at the top level rather than inside a group,
		// as either `true` or a small array of options.
		if ( 1 === count( $steps ) ) {
			return isset( $supports[ $group ] ) && false !== $supports[ $group ];
		}
		$flag = $steps[1];

		if ( ! isset( $supports[ $group ] ) || ! is_array( $supports[ $group ] ) ) {
			return false;
		}
		$node = $supports[ $group ];

		if ( ! array_key_exists( $flag, $node ) ) {
			// Text and background colour are ON by default for any block that
			// supports colour at all — core/paragraph declares neither and is
			// coloured every day. They appear explicitly only to be switched
			// OFF, which is how core/image declares that it has no colour.
			// Every other flag is off unless declared.
			return 'color' === $group;
		}
		return false !== $node[ $flag ] && null !== $node[ $flag ];
	}

	/**
	 * Apply a nested style patch to a block and rewrite its markup to match.
	 *
	 * @param array $block      By reference.
	 * @param array $style      Nested map, e.g.
	 *                          array( 'typography' => array( 'fontSize' => '24px' ) ).
	 *                          A null leaf clears that property.
	 * @return true|WP_Error
	 */
	public static function apply_style( &$block, $style ) {
		if ( ! is_array( $style ) ) {
			return new WP_Error( 'clara_ve_bad_style', __( 'That is not a set of styles.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$clean = self::sanitize_style( $style );
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		// A property the block does not support would be written into markup
		// its own save() never produces. Refused rather than dropped: the
		// panel is built from these same answers, so a refusal here means the
		// two have drifted, and a silent drop would hide that.
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		foreach ( self::leaves( $clean ) as $path => $value ) {
			if ( null === $value ) {
				// Clearing something is always allowed — a block that has an
				// unsupported property already needs a way back.
				continue;
			}
			if ( ! self::supports( $name, $path ) ) {
				return new WP_Error(
					'clara_ve_unsupported_style',
					sprintf(
						/* translators: 1: a block type such as core/column, 2: a style property. */
						__( '%1$s has no %2$s to set.', 'visual-edit-lite' ),
						$name,
						$path
					),
					array( 'status' => 400 )
				);
			}
		}

		$current = isset( $block['attrs']['style'] ) && is_array( $block['attrs']['style'] )
			? $block['attrs']['style']
			: array();
		$merged = self::prune( self::merge( $current, $clean ) );

		if ( $merged ) {
			$block['attrs']['style'] = $merged;
		} else {
			unset( $block['attrs']['style'] );
		}

		// A custom value and a preset slug are two spellings of one decision.
		// Whichever was just written wins; the other is cleared, the way the
		// editor's own styleToAttributes does it.
		foreach ( self::PRESET_PAIRS as $path => $attribute ) {
			if ( null !== self::at( $clean, $path ) ) {
				unset( $block['attrs'][ $attribute ] );
			}
		}

		return self::sync( $block );
	}

	/**
	 * What a block's attribute is worth when it is not written down.
	 *
	 * @param string $name      Block name.
	 * @param string $attribute Attribute name.
	 * @return mixed|null
	 */
	private static function attribute_default( $name, $attribute ) {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );
		if ( ! $type || ! isset( $type->attributes[ $attribute ]['default'] ) ) {
			return null;
		}
		return $type->attributes[ $attribute ]['default'];
	}

	/**
	 * Would the writer accept this value for this path?
	 *
	 * The same whitelist that guards ordinary block styling, asked one path at
	 * a time — so a value stored for a smaller screen is validated by exactly
	 * the rules that validate it for the ordinary one, and the two cannot
	 * drift apart.
	 *
	 * @param string $path  Dot path, e.g. spacing.padding.top.
	 * @param string $value
	 * @return bool
	 */
	public static function accepts( $path, $value ) {
		$style = array();
		self::put( $style, $path, (string) $value );
		$clean = self::sanitize_style( $style );
		if ( is_wp_error( $clean ) ) {
			return false;
		}
		// A path the whitelist does not know is DROPPED rather than refused —
		// the fault that made a typed-in typeface vanish. Absence from the
		// cleaned result is therefore also a no.
		return null !== self::at( $clean, $path );
	}

	/**
	 * Drop a path from a nested style array, in place.
	 *
	 * @param array  $style By reference.
	 * @param string $path
	 */
	private static function forget( &$style, $path ) {
		$steps = explode( '.', $path );
		$leaf  = array_pop( $steps );
		$node  = &$style;
		foreach ( $steps as $step ) {
			if ( ! isset( $node[ $step ] ) || ! is_array( $node[ $step ] ) ) {
				return;
			}
			$node = &$node[ $step ];
		}
		unset( $node[ $leaf ] );
	}

	/**
	 * Every leaf of a nested style object, as dot paths.
	 *
	 * @param array  $style
	 * @param string $prefix
	 * @return array<string,mixed>
	 */
	private static function leaves( $style, $prefix = '' ) {
		$out = array();
		foreach ( $style as $key => $value ) {
			$path = ( '' === $prefix ) ? (string) $key : $prefix . '.' . $key;
			if ( is_array( $value ) ) {
				$out += self::leaves( $value, $path );
				continue;
			}
			$out[ $path ] = $value;
		}
		return $out;
	}

	/**
	 * Rewrite a block's class and style attributes from its current
	 * attributes. The one place markup is brought back into step.
	 *
	 * @param array $block By reference.
	 * @return true|WP_Error
	 */
	public static function sync( &$block, $drop = array() ) {
		$name = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';
		// The opening tag's own piece of the markup, never the concatenation:
		// a container's innerHTML reads as an element already closed, and
		// writing that back is how its children disappear.
		$html = Clara_VE_Block_Patch::open_segment( $block );
		if ( null === $html ) {
			return new WP_Error(
				'clara_ve_unwritable',
				__( 'That block is not shaped the way this editor knows how to change.', 'visual-edit-lite' ),
				array( 'status' => 422 )
			);
		}
		$tags = new WP_HTML_Tag_Processor( $html );

		$wanted_tag = isset( self::TARGET_TAG[ $name ] ) ? self::TARGET_TAG[ $name ] : null;
		$found      = $wanted_tag
			? $tags->next_tag( array( 'tag_name' => $wanted_tag ) )
			: $tags->next_tag();
		if ( ! $found ) {
			return new WP_Error(
				'clara_ve_unwritable',
				__( 'That block is not shaped the way this editor knows how to change.', 'visual-edit-lite' ),
				array( 'status' => 422 )
			);
		}

		$inner = isset( self::INNER_STYLE[ $name ] ) ? self::INNER_STYLE[ $name ] : null;

		// A block's own extra classes belong to its ROOT element, always —
		// useBlockProps puts them there — while its styling may belong to a
		// descendant. For a button those are two different elements: the
		// class the owner added goes on <div class="wp-block-button"> and
		// every colour and border on the <a> inside it. Writing both to the
		// same element is a block Gutenberg refuses to open.
		// Read from a processor of its own: $tags has already been advanced to
		// the styled element, so asking IT which tag it is on would compare
		// the answer with itself and always say yes.
		$first = new WP_HTML_Tag_Processor( $html );
		$root_tag = $first->next_tag() ? strtoupper( (string) $first->get_tag() ) : '';
		$root_is_target = ( null === $wanted_tag ) || ( $root_tag === strtoupper( $wanted_tag ) );

		if ( ! $root_is_target ) {
			$root = new WP_HTML_Tag_Processor( $html );
			$root->next_tag();
			$kept = array_values( array_diff( self::existing_classes( $root ), (array) $drop ) );
			// Classes only: the wrapper's style attribute is none of this
			// class's business, and rewriting it would erase what the block
			// itself put there.
			$list = self::rewrite_classes( $block, $kept, 'root' );
			if ( $list ) {
				$root->set_attribute( 'class', implode( ' ', $list ) );
			} else {
				$root->remove_attribute( 'class' );
			}
			$html = $root->get_updated_html();

			// The cursor has to be found again, on the rewritten markup.
			$tags = new WP_HTML_Tag_Processor( $html );
			if ( ! $tags->next_tag( array( 'tag_name' => $wanted_tag ) ) ) {
				return new WP_Error(
					'clara_ve_unwritable',
					__( 'That block is not shaped the way this editor knows how to change.', 'visual-edit-lite' ),
					array( 'status' => 422 )
				);
			}
		}

		$keep = array_values( array_diff( self::existing_classes( $tags ), (array) $drop ) );
		self::write_element(
			$tags,
			self::rewrite_classes( $block, $keep, $root_is_target ? 'main' : 'styled' ),
			self::declarations( $block, $inner ? $inner['groups'] : array() )
		);
		$html = $tags->get_updated_html();

		// The second element, for the one block that has one — its own pass,
		// over the markup the first pass produced.
		if ( $inner ) {
			$deep = new WP_HTML_Tag_Processor( $html );
			if ( $deep->next_tag( array( 'tag_name' => $inner['tag'] ) ) ) {
				self::write_element(
					$deep,
					self::rewrite_classes( $block, self::existing_classes( $deep ), 'inner' ),
					self::only_groups( self::declarations( $block ), $inner['groups'] )
				);
				$html = $deep->get_updated_html();
			}
		}

		Clara_VE_Block_Patch::write_open_segment( $block, $html );
		return true;
	}

	/**
	 * The classes the owner added, from the block's own className attribute.
	 *
	 * A block type that supports it writes this straight into the class list
	 * in its own save(), so an attribute the markup does not echo is a block
	 * Gutenberg will refuse to open. Taking one away is the caller's job — see
	 * the $drop argument to sync(), which is the only place that knows what
	 * the class list used to say.
	 *
	 * @param array $attrs
	 * @return string[]
	 */
	private static function extra_classes( $attrs ) {
		if ( empty( $attrs['className'] ) ) {
			return array();
		}
		return preg_split( '/\s+/', (string) $attrs['className'], -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * @param WP_HTML_Tag_Processor $tags Positioned on an element.
	 * @return string[]
	 */
	private static function existing_classes( $tags ) {
		$classes = preg_split( '/\s+/', trim( (string) $tags->get_attribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
		return $classes ? $classes : array();
	}

	/**
	 * Put a class list and a declaration list on the element under the cursor.
	 *
	 * @param WP_HTML_Tag_Processor $tags
	 * @param string[]              $classes
	 * @param array<string,string>  $declarations
	 */
	private static function write_element( $tags, $classes, $declarations ) {
		if ( $classes ) {
			$tags->set_attribute( 'class', implode( ' ', $classes ) );
		} else {
			$tags->remove_attribute( 'class' );
		}

		if ( $declarations ) {
			$css = '';
			foreach ( $declarations as $property => $value ) {
				$css .= $property . ':' . $value . ';';
			}
			$tags->set_attribute( 'style', $css );
		} else {
			// Removed, not emptied. An empty attribute still counts as one
			// when the editor compares what it would have written.
			$tags->remove_attribute( 'style' );
		}
	}

	/**
	 * The declarations belonging to a set of style groups.
	 *
	 * Matched by CSS property prefix, which is the only link between a style
	 * group and what the engine emits for it: `border` produces border-color,
	 * border-radius and the rest; `shadow` produces box-shadow.
	 *
	 * @param array<string,string> $declarations
	 * @param string[]             $groups
	 * @return array<string,string>
	 */
	private static function only_groups( $declarations, $groups ) {
		$prefixes = array();
		foreach ( $groups as $group ) {
			$prefixes[] = ( 'shadow' === $group ) ? 'box-shadow' : $group;
		}
		$out = array();
		foreach ( $declarations as $property => $value ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( $property, $prefix ) ) {
					$out[ $property ] = $value;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The CSS declarations a block's style attributes come to.
	 *
	 * @param array $block
	 * @return array<string,string> property => value
	 */
	public static function declarations( $block, $without = array() ) {
		$style = isset( $block['attrs']['style'] ) && is_array( $block['attrs']['style'] )
			? $block['attrs']['style']
			: array();

		// core/spacer's height is not a block support and the style engine
		// knows nothing about it — its save() writes the declaration by hand
		// from the block's own `height` attribute, so this has to as well.
		//
		// The fallback is not a nicety. A spacer left at its default height
		// stores NO height attribute: the delimiter is a bare `<!-- wp:spacer -->`
		// and the 100px lives only in the markup, because that is the value
		// registered as the attribute's default. Reading the attribute alone
		// therefore finds nothing, and rewriting the style from nothing
		// deletes the height — the spacer collapses, and the block is invalid
		// on top of it.
		$extra = array();
		if ( 'core/spacer' === ( $block['blockName'] ?? '' ) ) {
			$height = $block['attrs']['height'] ?? self::attribute_default( 'core/spacer', 'height' );
			if ( $height ) {
				$extra['height'] = (string) $height;
			}
		}

		if ( ! $style || ! function_exists( 'wp_style_engine_get_styles' ) ) {
			return $extra;
		}
		// Pruned before the engine sees them, not filtered out of its answer:
		// the engine produces `aspect-ratio:16/9` for a property core's own
		// save() writes nothing for, and one declaration too many is an
		// invalid block.
		foreach ( self::STORED_ONLY as $path ) {
			self::forget( $style, $path );
		}

		// Groups this element is not the one for — core/image's border and
		// shadow belong to the picture, not the figure around it.
		foreach ( (array) $without as $group ) {
			unset( $style[ $group ] );
		}

		$engine = wp_style_engine_get_styles( $style );
		return array_merge( isset( $engine['declarations'] ) ? (array) $engine['declarations'] : array(), $extra );
	}

	/**
	 * The class list a block should carry: everything already on it that this
	 * class does not own, plus the classes its current attributes imply.
	 *
	 * @param array    $block
	 * @param string[] $classes Existing classes.
	 * @return string[]
	 */
	private static function rewrite_classes( $block, $classes, $scope = 'main' ) {
		$attrs = isset( $block['attrs'] ) ? (array) $block['attrs'] : array();
		$style = isset( $attrs['style'] ) && is_array( $attrs['style'] ) ? $attrs['style'] : array();
		$name  = isset( $block['blockName'] ) ? (string) $block['blockName'] : '';

		// Everything this class is responsible for comes off first, so a
		// cleared value leaves nothing behind.
		$owned = array(
			'~^has-(?!.*-background-color$).+-color$~',
			'~^has-text-color$~',
			'~^has-.+-background-color$~',
			'~^has-background$~',
			'~^has-.+-font-size$~',
			'~^has-custom-font-size$~',
			'~^has-.+-font-family$~',
			'~^has-text-align-.+$~',
			'~^align(wide|full|left|right|center)$~',
			'~^has-custom-border$~',
		);
		$classes = array_values(
			array_filter(
				$classes,
				static function ( $class ) use ( $owned ) {
					foreach ( $owned as $pattern ) {
						if ( preg_match( $pattern, $class ) ) {
							return false;
						}
					}
					return true;
				}
			)
		);

		$add   = array();
		$inner = isset( self::INNER_STYLE[ $name ] ) ? self::INNER_STYLE[ $name ] : null;

		// The root of a block whose styling lives deeper: it carries the
		// owner's own classes and nothing else this class knows about.
		if ( 'root' === $scope ) {
			return array_values( array_unique( array_merge( $classes, self::extra_classes( $attrs ) ) ) );
		}

		// The inner element of a split block carries the border classes and
		// NOTHING else. Falling through to the rest would put a block-level
		// alignment on the picture inside the figure.
		if ( 'inner' === $scope ) {
			if ( ! empty( $attrs['borderColor'] ) ) {
				$add[] = 'has-' . $attrs['borderColor'] . '-border-color';
				$add[] = 'has-border-color';
			} elseif ( null !== self::at( $style, 'border.color' ) ) {
				$add[] = 'has-border-color';
			}
			return array_values( array_unique( array_merge( $classes, $add ) ) );
		}

		// Colour: a preset contributes its own class AND the generic one; a
		// custom value contributes only the generic one, which is what tells
		// the stylesheet a colour was set at all.
		if ( ! empty( $attrs['textColor'] ) ) {
			$add[] = 'has-' . $attrs['textColor'] . '-color';
			$add[] = 'has-text-color';
		} elseif ( null !== self::at( $style, 'color.text' ) ) {
			$add[] = 'has-text-color';
		}
		if ( ! empty( $attrs['backgroundColor'] ) ) {
			$add[] = 'has-' . $attrs['backgroundColor'] . '-background-color';
			$add[] = 'has-background';
		} elseif ( null !== self::at( $style, 'color.background' ) ) {
			$add[] = 'has-background';
		}

		// A gradient is a background too, and brings the same generic class.
		// It has no `-background-color` twin: the preset slug's own class is
		// has-{slug}-gradient-background.
		if ( ! empty( $attrs['gradient'] ) ) {
			$add[] = 'has-' . $attrs['gradient'] . '-gradient-background';
			$add[] = 'has-background';
		} elseif ( null !== self::at( $style, 'color.gradient' ) ) {
			$add[] = 'has-background';
		}

		// Border colour, and ONLY the top-level one. A per-side colour gets no
		// class at all — core's own save() writes the declarations and nothing
		// else, which the probe showed plainly.
		//
		// On a split block they belong to the inner element, handled above;
		// this one gets the marker class instead.
		if ( $inner ) {
			// The figure's marker, which core writes for ANY border property
			// — a radius on its own included.
			if ( ! empty( $style[ $inner['wrapper_when'] ] ) || ! empty( $attrs['borderColor'] ) ) {
				$add[] = $inner['wrapper_class'];
			}
		} elseif ( ! empty( $attrs['borderColor'] ) ) {
			$add[] = 'has-' . $attrs['borderColor'] . '-border-color';
			$add[] = 'has-border-color';
		} elseif ( null !== self::at( $style, 'border.color' ) ) {
			$add[] = 'has-border-color';
		}

		if ( ! empty( $attrs['fontSize'] ) ) {
			$add[] = 'has-' . $attrs['fontSize'] . '-font-size';
		}
		if ( ! empty( $attrs['fontFamily'] ) ) {
			$add[] = 'has-' . $attrs['fontFamily'] . '-font-family';
		}

		// core/button marks any explicit size, preset or not — its own save()
		// does, and a block whose classes disagree with its save() is the
		// definition of invalid content.
		if ( 'core/button' === $name && ( ! empty( $attrs['fontSize'] ) || null !== self::at( $style, 'typography.fontSize' ) ) ) {
			$add[] = 'has-custom-font-size';
		}

		$align = isset( self::TEXT_ALIGN_ATTR[ $name ] )
			? ( isset( $attrs['textAlign'] ) ? $attrs['textAlign'] : null )
			: self::at( $style, 'typography.textAlign' );
		if ( $align ) {
			$add[] = 'has-text-align-' . $align;
		}

		if ( ! empty( $attrs['align'] ) ) {
			$add[] = 'align' . $attrs['align'];
		}

		// Whatever the owner put in "Additional CSS class(es)" — but only when
		// this element IS the block's root. On a button they belong to the
		// wrapper, which had its own pass above.
		if ( 'main' === $scope ) {
			$add = array_merge( $add, self::extra_classes( $attrs ) );
		}

		return array_values( array_unique( array_merge( $classes, $add ) ) );
	}

	/**
	 * Accept only the properties this version claims to understand, with
	 * values shaped the way CSS and the style engine expect.
	 *
	 * A style map arrives from a browser and ends up inside a block delimiter
	 * and a style attribute; anything unrecognised is dropped rather than
	 * stored and puzzled over later, and anything recognised but malformed is
	 * refused rather than written.
	 *
	 * @param array $style
	 * @return array|WP_Error
	 */
	private static function sanitize_style( $style ) {
		// value pattern per property. Presets arrive as var:preset|…|slug,
		// which the style engine expands; lengths carry their unit.
		$length  = '~^(var:preset\|spacing\|[a-z0-9-]{1,40}|-?[0-9.]{1,8}(px|rem|em|%|vw|vh|ch|ex)?|0)$~i';
		// A length that may also be a shorthand of up to four, which is what a
		// border radius is when the corners differ.
		$lengths = '~^(-?[0-9.]{1,8}(px|rem|em|%|vw|vh|ch|ex)?|0)( (-?[0-9.]{1,8}(px|rem|em|%|vw|vh|ch|ex)?|0)){0,3}$~i';

		// Values with their own grammar — parentheses, commas, percentages.
		// The fontFamily lesson applies twice over here: a pattern built for
		// slugs rejects every real gradient, and one built too loosely lets
		// something end up inside a style attribute. So: a required opening
		// keyword, a character whitelist with no semicolon, brace or colon in
		// it, and a length cap.
		$gradient = '~^(linear|radial|conic)-gradient\((?!.*url)[a-z0-9 ,.%#()°-]{1,300}\)$~i';
		// A box shadow is a list of lengths and a colour, optionally inset,
		// optionally several of them. Presets are the ordinary case.
		$shadow   = '~^(var:preset\|shadow\|[a-z0-9-]{1,40}|(?!.*url)[a-z0-9 ,.%#()-]{1,200})$~i';

		$allowed = array(
			'border.radius'             => $lengths,
			'border.width'              => $lengths,
			'border.style'              => '~^(none|solid|dashed|dotted|double|groove|ridge|inset|outset)$~',
			'border.color'              => '~^(#[0-9a-f]{3,8}|var:preset\|color\|[a-z0-9-]{1,40})$~i',
			'shadow'                    => $shadow,
			'color.gradient'            => $gradient,
			'dimensions.minHeight'      => $lengths,
			// 16/9, 4/3, 1, or the browser deciding.
			'dimensions.aspectRatio'    => '~^(auto|[0-9.]{1,6}(\s*/\s*[0-9.]{1,6})?)$~',
			'spacing.blockGap'          => '~^(var:preset\|spacing\|[a-z0-9-]{1,40}|-?[0-9.]{1,8}(px|rem|em|%|vw|vh)?|0)$~i',
			'position.type'             => '~^(sticky|default)$~',
			'position.top'              => '~^(-?[0-9.]{1,8}(px|rem|em|%)?|0)$~i',
			// A font stack, not a single word: quotes, spaces and commas are
			// what one is made of ("Cormorant Garamond", Georgia, serif), and
			// leaving this out of the list did not refuse such a value — it
			// dropped it, so choosing a typeface of one's own appeared to do
			// nothing at all.
			'typography.fontFamily'     => '~^[A-Za-z0-9 ,\'"_-]{1,120}$~',
			'typography.fontSize'       => $length,
			'typography.lineHeight'     => '~^[0-9.]{1,6}$~',
			'typography.letterSpacing'  => $length,
			'typography.fontWeight'     => '~^(100|200|300|400|500|600|700|800|900|normal|bold)$~',
			'typography.fontStyle'      => '~^(normal|italic)$~',
			'typography.textTransform'  => '~^(none|uppercase|lowercase|capitalize)$~',
			'typography.textDecoration' => '~^(none|underline|line-through)$~',
			'typography.textAlign'      => '~^(left|center|right)$~',
			'color.text'                => '~^(#[0-9a-f]{3,8}|var:preset\|color\|[a-z0-9-]{1,40})$~i',
			'color.background'          => '~^(#[0-9a-f]{3,8}|var:preset\|color\|[a-z0-9-]{1,40})$~i',
		);
		foreach ( array( 'padding', 'margin' ) as $box ) {
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				$allowed[ 'spacing.' . $box . '.' . $side ] = $length;
			}
		}

		$out = array();
		foreach ( $allowed as $path => $pattern ) {
			if ( ! self::has( $style, $path ) ) {
				continue;
			}
			$value = self::at( $style, $path );
			if ( null === $value || '' === $value ) {
				// An explicit clear. Kept as null so the merge can remove it.
				self::put( $out, $path, null );
				continue;
			}
			if ( ! preg_match( $pattern, (string) $value ) ) {
				return new WP_Error(
					'clara_ve_bad_style_value',
					sprintf(
						/* translators: %s: a style property such as typography.fontSize. */
						__( 'That is not a value this editor can store for %s.', 'visual-edit-lite' ),
						$path
					),
					array( 'status' => 400 )
				);
			}
			self::put( $out, $path, (string) $value );
		}
		return $out;
	}

	/**
	 * Deep-merge a patch into a style map. A null leaf removes the key.
	 *
	 * @param array $into
	 * @param array $patch
	 * @return array
	 */
	private static function merge( $into, $patch ) {
		foreach ( $patch as $key => $value ) {
			if ( is_array( $value ) ) {
				$into[ $key ] = self::merge(
					isset( $into[ $key ] ) && is_array( $into[ $key ] ) ? $into[ $key ] : array(),
					$value
				);
				continue;
			}
			if ( null === $value ) {
				unset( $into[ $key ] );
				continue;
			}
			$into[ $key ] = $value;
		}
		return $into;
	}

	/**
	 * Drop groups that no longer hold anything.
	 *
	 * The editor does this too (cleanEmptyObject): a style attribute holding
	 * `{"typography":{}}` is not what it would have written, and the leftover
	 * empty group is the kind of difference that only shows up as a block
	 * refusing to open six months later.
	 *
	 * @param array $style
	 * @return array
	 */
	private static function prune( $style ) {
		foreach ( $style as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			$value = self::prune( $value );
			if ( $value ) {
				$style[ $key ] = $value;
			} else {
				unset( $style[ $key ] );
			}
		}
		return $style;
	}

	/**
	 * @param array  $map
	 * @param string $path Dot-separated.
	 * @return mixed|null
	 */
	private static function at( $map, $path ) {
		foreach ( explode( '.', $path ) as $step ) {
			if ( ! is_array( $map ) || ! array_key_exists( $step, $map ) ) {
				return null;
			}
			$map = $map[ $step ];
		}
		return $map;
	}

	/**
	 * Whether a path is present, distinguishing "absent" from "set to null".
	 *
	 * @param array  $map
	 * @param string $path
	 * @return bool
	 */
	private static function has( $map, $path ) {
		foreach ( explode( '.', $path ) as $step ) {
			if ( ! is_array( $map ) || ! array_key_exists( $step, $map ) ) {
				return false;
			}
			$map = $map[ $step ];
		}
		return true;
	}

	/**
	 * @param array  $map By reference.
	 * @param string $path
	 * @param mixed  $value
	 * @return void
	 */
	private static function put( &$map, $path, $value ) {
		$steps  = explode( '.', $path );
		$cursor = &$map;
		foreach ( $steps as $depth => $step ) {
			if ( $depth === count( $steps ) - 1 ) {
				$cursor[ $step ] = $value;
				break;
			}
			if ( ! isset( $cursor[ $step ] ) || ! is_array( $cursor[ $step ] ) ) {
				$cursor[ $step ] = array();
			}
			$cursor = &$cursor[ $step ];
		}
	}
}
