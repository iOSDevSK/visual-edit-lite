<?php
/**
 * Custom HTML into native blocks.
 *
 * A theme's pattern, or markup someone wrote without block comments, can put a
 * whole section on a page as one Custom HTML block: an FAQ of <details>, a list,
 * a row of buttons. It renders, but nothing in it can be selected or changed on
 * its own. This turns the markup that has a native block into that block —
 * heading, paragraph, list, details, image, buttons, separator, quote, group —
 * written exactly as the block's own save() writes it, so the editor accepts it
 * as valid. Anything without a native equivalent (an embed, an SVG, a form, an
 * element carrying inline style or data attributes) stays Custom HTML untouched.
 *
 * Per top-level element, all or nothing: a card whose icon is an <svg> is not
 * split into a group and a Custom HTML block, it stays one Custom HTML block.
 * Markup that stays is never re-serialised, so converting twice changes nothing.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Block_Convert {

	/** Elements that become a group, with the tag they keep. */
	const CONTAINERS = array( 'div', 'section', 'article', 'main', 'aside', 'header', 'footer' );

	/** Inline formatting a rich-text attribute keeps as it is. */
	const INLINE = array( 'a', 'abbr', 'b', 'br', 'code', 'em', 'i', 'kbd', 'mark', 's', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u' );

	/** Attributes an inline element may carry and still be ordinary rich text. */
	const INLINE_ATTRIBUTES = array(
		'a'    => array( 'href', 'target', 'rel', 'title', 'class' ),
		'abbr' => array( 'title' ),
		'span' => array( 'class' ),
		'time' => array( 'datetime' ),
	);

	/** Attributes an image may carry that the block does not keep in its saved HTML. */
	const IMAGE_DROPPED = array( 'width', 'height', 'loading', 'decoding', 'srcset', 'sizes', 'fetchpriority' );

	/**
	 * Convert every Custom HTML block, and every run of markup written without
	 * block comments, into native blocks where the markup allows it.
	 *
	 * @param string $markup Block markup; comments optional.
	 * @param array  $args {
	 *     @type string|null $baseline        The document before an edit. Custom HTML already in it is
	 *                                        left alone, so a one-word edit never converts an embed
	 *                                        someone placed on purpose.
	 *     @type bool        $expand_patterns Replace pattern references with the pattern's blocks first.
	 * }
	 * @return array{markup: string, changed: bool, converted: string[], kept_html: int, kept_why: string[]}
	 */
	public static function convert_document( $markup, $args = array() ) {
		$markup = (string) $markup;
		$args   = wp_parse_args(
			$args,
			array(
				'baseline'        => null,
				'expand_patterns' => true,
			)
		);
		$stats  = array(
			'changed'   => false,
			'converted' => array(),
			'kept_html' => 0,
			'kept_why'  => array(),
		);

		$blocks = parse_blocks( $markup );
		if ( $args['expand_patterns'] && function_exists( 'resolve_pattern_blocks' ) && self::has_pattern_reference( $blocks ) ) {
			$blocks           = resolve_pattern_blocks( $blocks );
			$stats['changed'] = true;
		}

		$seen = array();
		if ( null !== $args['baseline'] ) {
			foreach ( self::html_keys( parse_blocks( (string) $args['baseline'] ) ) as $key ) {
				$seen[ $key ] = isset( $seen[ $key ] ) ? $seen[ $key ] + 1 : 1;
			}
		}

		$blocks = self::convert_list( $blocks, true, $seen, $stats );

		return array(
			'markup'    => $stats['changed'] ? serialize_blocks( $blocks ) : $markup,
			'changed'   => $stats['changed'],
			'converted' => $stats['converted'],
			'kept_html' => $stats['kept_html'],
			'kept_why'  => array_values( array_unique( $stats['kept_why'] ) ),
		);
	}

	/**
	 * One piece of HTML as native block nodes.
	 *
	 * @param string $html
	 * @return array[]|null parse_blocks()-shaped nodes, or null when nothing in it converts.
	 */
	public static function html_to_blocks( $html ) {
		$result = self::convert_html( (string) $html );
		return $result ? $result['nodes'] : null;
	}

	/**
	 * The words on a page, each exactly as it is written in the markup, so a
	 * caller can quote one back as a find. A block whose text is broken up by
	 * inline formatting is left out: no single string of it exists to quote.
	 *
	 * @param string $markup
	 * @param int    $max
	 * @return string[]
	 */
	public static function texts( $markup, $max = 60 ) {
		$out   = array();
		$total = 0;
		$push  = static function ( $text ) use ( &$out, &$total, $max ) {
			$text = trim( (string) $text );
			if ( '' === $text || in_array( $text, $out, true ) || count( $out ) >= $max || $total + strlen( $text ) > 4000 ) {
				return;
			}
			$out[]  = $text;
			$total += strlen( $text );
		};
		$walk  = static function ( $blocks ) use ( &$walk, $push ) {
			foreach ( (array) $blocks as $block ) {
				$name = (string) $block['blockName'];
				if ( '' === $name || 'core/html' === $name ) {
					continue;
				}
				if ( 'core/details' === $name && isset( $block['innerContent'][0] ) && is_string( $block['innerContent'][0] ) && preg_match( '#<summary>([^<]*)</summary>#', $block['innerContent'][0], $match ) ) {
					$push( $match[1] );
				}
				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
					continue;
				}
				$segments = array_values(
					array_filter(
						preg_split( '/<[^>]*>/', (string) $block['innerHTML'] ),
						static function ( $segment ) {
							return '' !== trim( $segment );
						}
					)
				);
				if ( 1 === count( $segments ) ) {
					$push( $segments[0] );
				}
			}
		};
		$walk( parse_blocks( (string) $markup ) );
		return $out;
	}

	// ---- The document ---------------------------------------------------------

	private static function has_pattern_reference( $blocks ) {
		foreach ( (array) $blocks as $block ) {
			if ( 'core/pattern' === $block['blockName'] || ( ! empty( $block['innerBlocks'] ) && self::has_pattern_reference( $block['innerBlocks'] ) ) ) {
				return true;
			}
		}
		return false;
	}

	/** What identifies a piece of Custom HTML or loose markup, for the edit delta. */
	private static function html_keys( $blocks ) {
		$keys = array();
		foreach ( (array) $blocks as $block ) {
			$key = self::html_key( $block );
			if ( null !== $key ) {
				$keys[] = $key;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$keys = array_merge( $keys, self::html_keys( $block['innerBlocks'] ) );
			}
		}
		return $keys;
	}

	private static function html_key( $block ) {
		if ( 'core/html' === $block['blockName'] ) {
			return 'h:' . trim( (string) $block['innerHTML'] );
		}
		if ( null === $block['blockName'] && '' !== trim( (string) $block['innerHTML'] ) ) {
			return 'f:' . trim( (string) $block['innerHTML'] );
		}
		return null;
	}

	/**
	 * @param array[] $blocks Sibling nodes.
	 * @param bool    $top    Whether these are the document's top level, where loose markup is a node.
	 * @param array   $seen   Baseline multiset, consumed.
	 * @param array   $stats
	 * @return array[]
	 */
	private static function convert_list( $blocks, $top, &$seen, &$stats ) {
		$out = array();
		foreach ( (array) $blocks as $block ) {
			$out[] = self::convert_node( $block, $top, $seen, $stats );
		}
		// Each entry is a list of replacement nodes; flatten with a blank line
		// between what one node became, the spacing parse_blocks() reports.
		$flat = array();
		foreach ( $out as $nodes ) {
			foreach ( $nodes as $i => $node ) {
				if ( $i && $top ) {
					$flat[] = self::separator();
				}
				$flat[] = $node;
			}
		}
		return $flat;
	}

	/** @return array[] What one node becomes: itself, or the blocks it converted into. */
	private static function convert_node( $block, $top, &$seen, &$stats ) {
		$key = self::html_key( $block );
		if ( null !== $key ) {
			if ( ! empty( $seen[ $key ] ) ) {
				--$seen[ $key ];
				return array( $block );
			}
			$result = self::convert_html( (string) $block['innerHTML'] );
			if ( ! $result ) {
				if ( 'core/html' === $block['blockName'] ) {
					++$stats['kept_html'];
				}
				$stats['kept_why'][] = self::why_kept( (string) $block['innerHTML'] );
				return array( $block );
			}
			$stats['changed']   = true;
			$stats['converted'] = array_merge( $stats['converted'], $result['names'] );
			$stats['kept_html'] += $result['kept'];
			$stats['kept_why']  = array_merge( $stats['kept_why'], $result['kept_why'] );
			return $result['nodes'];
		}

		if ( empty( $block['innerBlocks'] ) ) {
			return array( $block );
		}

		// A container: convert its children, then give each child the same
		// number of slots in innerContent as nodes it became. innerBlocks and
		// innerContent must stay in step, or serialize_block() drops blocks.
		$replaced = array();
		foreach ( $block['innerBlocks'] as $child ) {
			$replaced[] = self::convert_node( $child, false, $seen, $stats );
		}
		$inner   = array();
		$content = array();
		$index   = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$content[] = $chunk;
				continue;
			}
			$nodes = isset( $replaced[ $index ] ) ? $replaced[ $index ] : array();
			++$index;
			foreach ( $nodes as $i => $node ) {
				if ( $i ) {
					$content[] = "\n\n";
				}
				$content[] = null;
				$inner[]   = $node;
			}
		}
		$block['innerBlocks']  = $inner;
		$block['innerContent'] = $content;
		return array( $block );
	}

	private static function separator() {
		return array(
			'blockName'    => null,
			'attrs'        => array(),
			'innerBlocks'  => array(),
			'innerHTML'    => "\n\n",
			'innerContent' => array( "\n\n" ),
		);
	}

	private static function why_kept( $html ) {
		if ( preg_match( '#<\s*([a-z][a-z0-9-]*)#i', $html, $match ) ) {
			return strtolower( $match[1] );
		}
		return 'text';
	}

	// ---- HTML -----------------------------------------------------------------

	/**
	 * @param string $html
	 * @return array{nodes: array[], names: string[], kept: int, kept_why: string[]}|null
	 */
	private static function convert_html( $html ) {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$root = self::load( $html );
		if ( ! $root ) {
			return null;
		}

		$segments = array(); // [ 'nodes' => array[] ] or [ 'keep' => DOMNode[] ]
		$run      = array();
		$flush    = static function () use ( &$run, &$segments ) {
			if ( ! $run ) {
				return;
			}
			$has_text = false;
			foreach ( $run as $node ) {
				if ( XML_TEXT_NODE === $node->nodeType && '' !== trim( $node->textContent ) ) {
					$has_text = true;
				}
			}
			$paragraph = $has_text ? self::paragraph_from_run( $run ) : null;
			if ( $paragraph ) {
				$segments[] = array( 'nodes' => array( $paragraph ) );
			} elseif ( self::run_has_content( $run ) ) {
				// Only inline elements at the top, such as an icon <span>: a
				// paragraph would change how it sits, so it stays as it is.
				$segments[] = array( 'keep' => $run );
			}
			$run = array();
		};

		$children = self::child_list( $root );
		$count    = count( $children );
		for ( $i = 0; $i < $count; $i++ ) {
			$child = $children[ $i ];
			if ( self::is_inline_node( $child ) ) {
				$run[] = $child;
				continue;
			}
			$flush();
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				$segments[] = array( 'keep' => array( $child ) );
				continue;
			}
			if ( self::is_button_like( $child ) ) {
				$buttons = array( $child );
				while ( $i + 1 < $count && ( self::is_button_like( $children[ $i + 1 ] ) || self::is_blank( $children[ $i + 1 ] ) ) ) {
					++$i;
					if ( self::is_button_like( $children[ $i ] ) ) {
						$buttons[] = $children[ $i ];
					}
				}
				$node       = self::buttons_block( $buttons );
				$segments[] = $node ? array( 'nodes' => array( $node ) ) : array( 'keep' => $buttons );
				continue;
			}
			$nodes      = self::element_blocks( $child, 0 );
			$segments[] = null === $nodes ? array( 'keep' => array( $child ) ) : array( 'nodes' => $nodes );
		}
		$flush();

		$converted = false;
		foreach ( $segments as $segment ) {
			if ( isset( $segment['nodes'] ) ) {
				$converted = true;
				break;
			}
		}
		if ( ! $converted ) {
			return null;
		}

		// Some of it converted, some of it did not: what stayed becomes its own
		// Custom HTML block, neighbours kept together.
		$nodes    = array();
		$names    = array();
		$kept     = 0;
		$kept_why = array();
		$pending  = array();
		$close    = static function () use ( &$pending, &$nodes, &$kept, &$kept_why ) {
			if ( ! $pending ) {
				return;
			}
			$html = '';
			foreach ( $pending as $node ) {
				$html .= self::outer( $node );
			}
			$html = trim( $html );
			if ( '' !== $html ) {
				$nodes[]    = self::node( 'core/html', array(), array( $html ) );
				$kept_why[] = self::why_kept( $html );
				++$kept;
			}
			$pending = array();
		};
		foreach ( $segments as $segment ) {
			if ( isset( $segment['keep'] ) ) {
				$pending = array_merge( $pending, $segment['keep'] );
				continue;
			}
			$close();
			foreach ( $segment['nodes'] as $node ) {
				$nodes[] = $node;
				$names   = array_merge( $names, self::names( $node ) );
			}
		}
		$close();

		return array(
			'nodes'    => $nodes,
			'names'    => $names,
			'kept'     => $kept,
			'kept_why' => $kept_why,
		);
	}

	/** @return DOMElement|null The wrapper whose children are the markup's top level. */
	private static function load( $html ) {
		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = $doc->loadHTML( '<?xml encoding="UTF-8"><div id="cve-convert-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $loaded ) {
			return null;
		}
		$root = $doc->getElementById( 'cve-convert-root' );
		return $root instanceof DOMElement ? $root : null;
	}

	private static function names( $node ) {
		$names = array( $node['blockName'] );
		foreach ( $node['innerBlocks'] as $child ) {
			$names = array_merge( $names, self::names( $child ) );
		}
		return $names;
	}

	/** @return DOMNode[] */
	private static function child_list( $element ) {
		$list = array();
		foreach ( $element->childNodes as $child ) {
			$list[] = $child;
		}
		return $list;
	}

	private static function is_blank( $node ) {
		return XML_TEXT_NODE === $node->nodeType && '' === trim( $node->textContent );
	}

	private static function is_inline_node( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			return true;
		}
		return XML_ELEMENT_NODE === $node->nodeType && in_array( strtolower( $node->nodeName ), self::INLINE, true ) && ! self::is_button_like( $node );
	}

	private static function run_has_content( $run ) {
		foreach ( $run as $node ) {
			if ( ! self::is_blank( $node ) ) {
				return true;
			}
		}
		return false;
	}

	private static function outer( $node ) {
		return (string) $node->ownerDocument->saveHTML( $node );
	}

	/** @return string[] The element's classes. */
	private static function classes( $element ) {
		return preg_split( '/\s+/', trim( (string) $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/** Whether every attribute of the element is one this conversion understands. */
	private static function attributes_allowed( $element, $allowed ) {
		foreach ( $element->attributes as $attribute ) {
			if ( ! in_array( strtolower( $attribute->name ), $allowed, true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * An element's content as rich text: text and inline formatting only.
	 *
	 * @return string|null Null when anything in it is not inline.
	 */
	private static function rich_text( $element ) {
		$html = '';
		foreach ( $element->childNodes as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$html .= self::outer( $child );
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				return null;
			}
			$tag = strtolower( $child->nodeName );
			if ( ! in_array( $tag, self::INLINE, true ) ) {
				return null;
			}
			$allowed = isset( self::INLINE_ATTRIBUTES[ $tag ] ) ? self::INLINE_ATTRIBUTES[ $tag ] : array();
			if ( ! self::attributes_allowed( $child, $allowed ) || ( 'br' !== $tag && null === self::rich_text( $child ) ) ) {
				return null;
			}
			$html .= self::outer( $child );
		}
		return trim( preg_replace( '/\s*\n\s*/', ' ', $html ) );
	}

	/** Inline nodes directly inside a container, as one paragraph. */
	private static function paragraph_from_run( $run ) {
		$html = '';
		foreach ( $run as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType ) {
				$html .= self::outer( $node );
				continue;
			}
			$tag     = strtolower( $node->nodeName );
			$allowed = isset( self::INLINE_ATTRIBUTES[ $tag ] ) ? self::INLINE_ATTRIBUTES[ $tag ] : array();
			if ( ! self::attributes_allowed( $node, $allowed ) || ( 'br' !== $tag && null === self::rich_text( $node ) ) ) {
				return null;
			}
			$html .= self::outer( $node );
		}
		$html = trim( preg_replace( '/\s*\n\s*/', ' ', $html ) );
		return '' === $html ? null : self::node( 'core/paragraph', array(), array( '<p>' . $html . '</p>' ) );
	}

	/**
	 * Block-level children of a container as blocks: loose text and inline
	 * elements become a paragraph, a row of buttons becomes one Buttons block.
	 *
	 * @return array[]|null Null when any child does not convert.
	 */
	private static function children_blocks( $element, $depth ) {
		$nodes    = array();
		$run      = array();
		$children = self::child_list( $element );
		$count    = count( $children );
		$flush    = static function () use ( &$run, &$nodes ) {
			if ( ! self::run_has_content( $run ) ) {
				$run = array();
				return true;
			}
			$paragraph = self::paragraph_from_run( $run );
			$run       = array();
			if ( ! $paragraph ) {
				return false;
			}
			$nodes[] = $paragraph;
			return true;
		};
		for ( $i = 0; $i < $count; $i++ ) {
			$child = $children[ $i ];
			if ( self::is_inline_node( $child ) ) {
				$run[] = $child;
				continue;
			}
			if ( ! $flush() || XML_ELEMENT_NODE !== $child->nodeType ) {
				return null;
			}
			if ( self::is_button_like( $child ) ) {
				$buttons = array( $child );
				while ( $i + 1 < $count && ( self::is_button_like( $children[ $i + 1 ] ) || self::is_blank( $children[ $i + 1 ] ) ) ) {
					++$i;
					if ( self::is_button_like( $children[ $i ] ) ) {
						$buttons[] = $children[ $i ];
					}
				}
				$node = self::buttons_block( $buttons );
				if ( ! $node ) {
					return null;
				}
				$nodes[] = $node;
				continue;
			}
			$converted = self::element_blocks( $child, $depth + 1 );
			if ( null === $converted ) {
				return null;
			}
			$nodes = array_merge( $nodes, $converted );
		}
		return $flush() ? $nodes : null;
	}

	/**
	 * One element as block nodes.
	 *
	 * @param DOMElement $element
	 * @param int        $depth 0 for the markup's top level.
	 * @return array[]|null
	 */
	private static function element_blocks( $element, $depth ) {
		$tag = strtolower( $element->nodeName );
		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				return self::heading_block( $element, (int) substr( $tag, 1 ) );
			case 'p':
				return self::paragraph_block( $element );
			case 'ul':
			case 'ol':
				$node = self::list_block( $element );
				return $node ? array( $node ) : null;
			case 'details':
				return self::details_block( $element, $depth );
			case 'figure':
				$node = self::figure_block( $element );
				return $node ? array( $node ) : null;
			case 'img':
				$node = self::image_block( $element, null, array() );
				return $node ? array( $node ) : null;
			case 'hr':
				return self::attributes_allowed( $element, array() )
					? array( self::node( 'core/separator', array(), array( '<hr class="wp-block-separator has-alpha-channel-opacity"/>' ) ) )
					: null;
			case 'blockquote':
				return self::quote_block( $element, $depth );
		}
		if ( in_array( $tag, self::CONTAINERS, true ) ) {
			return self::group_block( $element, $tag, $depth );
		}
		return null;
	}

	/** Text alignment written the way this WordPress's block expects it. */
	private static function align_from( &$classes, $name, &$attrs ) {
		foreach ( $classes as $i => $class ) {
			if ( preg_match( '/^has-text-align-(left|center|right)$/', $class, $match ) ) {
				unset( $classes[ $i ] );
				$type = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
				if ( $type && ! empty( $type->supports['typography']['textAlign'] ) ) {
					$attrs['style']['typography']['textAlign'] = $match[1];
				} elseif ( 'core/paragraph' === $name ) {
					$attrs['align'] = $match[1];
				} else {
					$attrs['textAlign'] = $match[1];
				}
				$classes = array_values( $classes );
				return 'has-text-align-' . $match[1];
			}
		}
		return '';
	}

	/** className and anchor, from the element's class and id. */
	private static function common_attrs( $element, $classes, &$attrs ) {
		if ( $classes ) {
			$attrs['className'] = implode( ' ', $classes );
		}
		$id = (string) $element->getAttribute( 'id' );
		if ( '' !== $id ) {
			$attrs['anchor'] = $id;
		}
	}

	private static function class_attr( $list ) {
		$list = array_values( array_filter( $list ) );
		return $list ? ' class="' . esc_attr( implode( ' ', $list ) ) . '"' : '';
	}

	private static function id_attr( $element ) {
		$id = (string) $element->getAttribute( 'id' );
		return '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '';
	}

	private static function heading_block( $element, $level ) {
		if ( ! self::attributes_allowed( $element, array( 'class', 'id' ) ) ) {
			return null;
		}
		$text = self::rich_text( $element );
		if ( null === $text || '' === $text ) {
			return null;
		}
		$classes = self::classes( $element );
		$classes = array_values( array_diff( $classes, array( 'wp-block-heading' ) ) );
		$attrs   = array();
		$align   = self::align_from( $classes, 'core/heading', $attrs );
		if ( 2 !== $level ) {
			$attrs = array( 'level' => $level ) + $attrs;
		}
		self::common_attrs( $element, $classes, $attrs );
		$html = '<h' . $level . self::class_attr( array_merge( array( 'wp-block-heading', $align ), $classes ) ) . self::id_attr( $element ) . '>' . $text . '</h' . $level . '>';
		return array( self::node( 'core/heading', $attrs, array( $html ) ) );
	}

	private static function paragraph_block( $element ) {
		$elements = array();
		foreach ( $element->childNodes as $child ) {
			if ( ! self::is_blank( $child ) ) {
				$elements[] = $child;
			}
		}
		// A paragraph holding nothing but an image is an image.
		if ( 1 === count( $elements ) && XML_ELEMENT_NODE === $elements[0]->nodeType && 'img' === strtolower( $elements[0]->nodeName ) && ! $element->attributes->length ) {
			$node = self::image_block( $elements[0], null, array() );
			return $node ? array( $node ) : null;
		}
		if ( ! self::attributes_allowed( $element, array( 'class', 'id' ) ) ) {
			return null;
		}
		$text = self::rich_text( $element );
		if ( null === $text ) {
			return null;
		}
		if ( '' === $text ) {
			return array(); // An empty paragraph is spacing the theme's CSS already gives.
		}
		$classes = self::classes( $element );
		$attrs   = array();
		$align   = self::align_from( $classes, 'core/paragraph', $attrs );
		self::common_attrs( $element, $classes, $attrs );
		$html = '<p' . self::class_attr( array_merge( array( $align ), $classes ) ) . self::id_attr( $element ) . '>' . $text . '</p>';
		return array( self::node( 'core/paragraph', $attrs, array( $html ) ) );
	}

	private static function list_block( $element ) {
		$tag = strtolower( $element->nodeName );
		if ( ! self::attributes_allowed( $element, 'ol' === $tag ? array( 'class', 'id', 'start', 'reversed' ) : array( 'class', 'id' ) ) ) {
			return null;
		}
		$items = array();
		foreach ( $element->childNodes as $child ) {
			if ( self::is_blank( $child ) ) {
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower( $child->nodeName ) ) {
				return null;
			}
			$item = self::list_item_block( $child );
			if ( ! $item ) {
				return null;
			}
			$items[] = $item;
		}
		if ( ! $items ) {
			return null;
		}
		$classes = array_values( array_diff( self::classes( $element ), array( 'wp-block-list' ) ) );
		$attrs   = array();
		$extra   = '';
		if ( 'ol' === $tag ) {
			$attrs['ordered'] = true;
			if ( $element->hasAttribute( 'start' ) ) {
				if ( ! preg_match( '/^-?\d+$/', (string) $element->getAttribute( 'start' ) ) ) {
					return null;
				}
				$attrs['start'] = (int) $element->getAttribute( 'start' );
			}
			if ( $element->hasAttribute( 'reversed' ) ) {
				$attrs['reversed'] = true;
				$extra            .= ' reversed';
			}
			if ( isset( $attrs['start'] ) ) {
				$extra .= ' start="' . $attrs['start'] . '"';
			}
		}
		self::common_attrs( $element, $classes, $attrs );
		// reversed and start before class: the order the list block's save() writes them in.
		$open    = '<' . $tag . $extra . self::class_attr( array_merge( array( 'wp-block-list' ), $classes ) ) . self::id_attr( $element ) . '>';
		$content = array( $open );
		foreach ( $items as $i => $item ) {
			$content[] = null;
		}
		$content[] = '</' . $tag . '>';
		return self::node( 'core/list', $attrs, $content, $items );
	}

	private static function list_item_block( $element ) {
		if ( $element->attributes->length ) {
			return null;
		}
		// Text first, then at most a nested list — the order the block saves in.
		$text_nodes = array();
		$nested     = null;
		foreach ( $element->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), array( 'ul', 'ol' ), true ) ) {
				if ( $nested ) {
					return null;
				}
				$nested = $child;
				continue;
			}
			if ( $nested && ! self::is_blank( $child ) ) {
				return null;
			}
			if ( ! self::is_inline_node( $child ) ) {
				return null;
			}
			$text_nodes[] = $child;
		}
		$holder = $element->ownerDocument->createElement( 'li' );
		foreach ( $text_nodes as $node ) {
			$holder->appendChild( $node->cloneNode( true ) );
		}
		$text = self::rich_text( $holder );
		if ( null === $text ) {
			return null;
		}
		if ( ! $nested ) {
			return self::node( 'core/list-item', array(), array( '<li>' . $text . '</li>' ) );
		}
		$list = self::list_block( $nested );
		return $list ? self::node( 'core/list-item', array(), array( '<li>' . $text, null, '</li>' ), array( $list ) ) : null;
	}

	private static function details_block( $element, $depth ) {
		if ( ! self::attributes_allowed( $element, array( 'class', 'id', 'open' ) ) ) {
			return null;
		}
		$summary  = null;
		$body     = $element->ownerDocument->createElement( 'div' );
		$children = self::child_list( $element );
		foreach ( $children as $child ) {
			if ( null === $summary && XML_ELEMENT_NODE === $child->nodeType && 'summary' === strtolower( $child->nodeName ) ) {
				if ( $child->attributes->length ) {
					return null;
				}
				$summary = self::rich_text( $child );
				if ( null === $summary ) {
					return null;
				}
				continue;
			}
			$body->appendChild( $child->cloneNode( true ) );
		}
		// WordPress writes "Details" for a details block with no summary.
		$summary = ( null === $summary || '' === $summary ) ? 'Details' : $summary;
		$inner   = self::children_blocks( self::transparent( $body ), $depth );
		if ( null === $inner ) {
			return null;
		}
		$classes = array_values( array_diff( self::classes( $element ), array( 'wp-block-details' ) ) );
		$attrs   = array();
		$open    = $element->hasAttribute( 'open' );
		if ( $open ) {
			$attrs['showContent'] = true;
		}
		self::common_attrs( $element, $classes, $attrs );
		$head    = '<details' . self::class_attr( array_merge( array( 'wp-block-details' ), $classes ) ) . self::id_attr( $element ) . ( $open ? ' open' : '' ) . '><summary>' . $summary . '</summary>';
		$content = array( $head );
		foreach ( $inner as $i => $node ) {
			$content[] = null;
		}
		$content[] = '</details>';
		return array( self::node( 'core/details', $attrs, $content, $inner ) );
	}

	/** A <div> with no attributes of its own adds nothing: its children stand in for it. */
	private static function transparent( $element ) {
		$children = array();
		foreach ( $element->childNodes as $child ) {
			if ( ! self::is_blank( $child ) ) {
				$children[] = $child;
			}
		}
		if ( 1 === count( $children ) && XML_ELEMENT_NODE === $children[0]->nodeType && 'div' === strtolower( $children[0]->nodeName ) && ! $children[0]->attributes->length ) {
			return $children[0];
		}
		return $element;
	}

	private static function figure_block( $element ) {
		if ( ! self::attributes_allowed( $element, array( 'class', 'id' ) ) ) {
			return null;
		}
		$image   = null;
		$caption = null;
		foreach ( $element->childNodes as $child ) {
			if ( self::is_blank( $child ) ) {
				continue;
			}
			if ( XML_ELEMENT_NODE !== $child->nodeType ) {
				return null;
			}
			$tag = strtolower( $child->nodeName );
			if ( 'img' === $tag && ! $image ) {
				$image = $child;
			} elseif ( 'figcaption' === $tag && null === $caption && $image ) {
				if ( ! self::attributes_allowed( $child, array( 'class' ) ) ) {
					return null;
				}
				$caption = self::rich_text( $child );
				if ( null === $caption ) {
					return null;
				}
			} else {
				return null;
			}
		}
		if ( ! $image ) {
			return null;
		}
		$classes = array_values(
			array_filter(
				self::classes( $element ),
				static function ( $class ) {
					return 'wp-block-image' !== $class && ! preg_match( '/^size-/', $class );
				}
			)
		);
		return self::image_block( $image, $caption, $classes, $element );
	}

	private static function image_block( $image, $caption, $classes, $figure = null ) {
		if ( ! self::attributes_allowed( $image, array_merge( array( 'src', 'alt', 'class' ), self::IMAGE_DROPPED ) ) ) {
			return null;
		}
		$src = trim( (string) $image->getAttribute( 'src' ) );
		if ( '' === $src || ! self::safe_url( $src ) ) {
			return null;
		}
		$id = 0;
		foreach ( self::classes( $image ) as $class ) {
			if ( preg_match( '/^wp-image-(\d+)$/', $class, $match ) ) {
				$id = (int) $match[1];
			}
		}
		if ( ! $id && function_exists( 'attachment_url_to_postid' ) ) {
			$id = (int) attachment_url_to_postid( $src );
		}
		$attrs = array();
		if ( $id ) {
			$attrs['id']       = $id;
			$attrs['sizeSlug'] = 'full';
		}
		if ( $figure ) {
			self::common_attrs( $figure, $classes, $attrs );
		}
		$html = '<figure' . self::class_attr( array_merge( array( 'wp-block-image', $id ? 'size-full' : '' ), $classes ) ) . ( $figure ? self::id_attr( $figure ) : '' ) . '>'
			. '<img src="' . esc_attr( $src ) . '" alt="' . esc_attr( (string) $image->getAttribute( 'alt' ) ) . '"' . ( $id ? ' class="wp-image-' . $id . '"' : '' ) . '/>'
			. ( null !== $caption && '' !== $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '' )
			. '</figure>';
		return self::node( 'core/image', $attrs, array( $html ) );
	}

	/** A URL WordPress would keep as it is; anything it would strip (javascript:, data:) stays Custom HTML. */
	private static function safe_url( $url ) {
		return '' !== esc_url_raw( $url ) && 0 !== stripos( ltrim( $url ), 'data:' );
	}

	private static function is_button_like( $node ) {
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return false;
		}
		$tag = strtolower( $node->nodeName );
		if ( 'button' === $tag ) {
			return true;
		}
		if ( 'a' !== $tag ) {
			return false;
		}
		foreach ( self::classes( $node ) as $class ) {
			if ( preg_match( '/^(btn|button|wp-block-button__link)$|^(btn|button)[-_]/', $class ) ) {
				return true;
			}
		}
		return false;
	}

	private static function buttons_block( $elements ) {
		$items = array();
		foreach ( $elements as $element ) {
			$tag     = strtolower( $element->nodeName );
			$allowed = 'a' === $tag ? array( 'href', 'target', 'rel', 'class', 'title' ) : array( 'class', 'type' );
			if ( ! self::attributes_allowed( $element, $allowed ) ) {
				return null;
			}
			$text = self::rich_text( $element );
			if ( null === $text || '' === $text ) {
				return null;
			}
			$attrs = array();
			$link  = '<a class="wp-block-button__link wp-element-button"';
			if ( 'a' === $tag && $element->hasAttribute( 'href' ) ) {
				$href = (string) $element->getAttribute( 'href' );
				if ( ! self::safe_url( $href ) ) {
					return null;
				}
				$link .= ' href="' . esc_attr( $href ) . '"';
			}
			if ( 'a' === $tag && $element->hasAttribute( 'title' ) ) {
				$link .= ' title="' . esc_attr( (string) $element->getAttribute( 'title' ) ) . '"';
			}
			// Target and rel are read back from the link itself, so the block keeps them out of its comment.
			if ( 'a' === $tag && $element->hasAttribute( 'target' ) ) {
				$link .= ' target="' . esc_attr( (string) $element->getAttribute( 'target' ) ) . '"';
			}
			if ( 'a' === $tag && $element->hasAttribute( 'rel' ) ) {
				$link .= ' rel="' . esc_attr( (string) $element->getAttribute( 'rel' ) ) . '"';
			}
			$outline = false;
			foreach ( self::classes( $element ) as $class ) {
				if ( preg_match( '/outline|secondary|ghost/', $class ) ) {
					$outline = true;
				}
			}
			if ( $outline ) {
				$attrs['className'] = 'is-style-outline';
			}
			$items[] = self::node(
				'core/button',
				$attrs,
				array( '<div class="wp-block-button' . ( $outline ? ' is-style-outline' : '' ) . '">' . $link . '>' . $text . '</a></div>' )
			);
		}
		if ( ! $items ) {
			return null;
		}
		$content = array( '<div class="wp-block-buttons">' );
		foreach ( $items as $item ) {
			$content[] = null;
		}
		$content[] = '</div>';
		return self::node( 'core/buttons', array(), $content, $items );
	}

	private static function quote_block( $element, $depth ) {
		if ( ! self::attributes_allowed( $element, array( 'class', 'id' ) ) ) {
			return null;
		}
		$citation = null;
		$body     = $element->ownerDocument->createElement( 'div' );
		foreach ( self::child_list( $element ) as $child ) {
			if ( null === $citation && XML_ELEMENT_NODE === $child->nodeType && in_array( strtolower( $child->nodeName ), array( 'cite', 'footer' ), true ) ) {
				if ( $child->attributes->length ) {
					return null;
				}
				$citation = self::rich_text( $child );
				if ( null === $citation ) {
					return null;
				}
				continue;
			}
			$body->appendChild( $child->cloneNode( true ) );
		}
		$inner = self::children_blocks( $body, $depth );
		if ( null === $inner || ! $inner ) {
			return null;
		}
		$classes = array_values( array_diff( self::classes( $element ), array( 'wp-block-quote' ) ) );
		$attrs   = array();
		self::common_attrs( $element, $classes, $attrs );
		$content = array( '<blockquote' . self::class_attr( array_merge( array( 'wp-block-quote' ), $classes ) ) . self::id_attr( $element ) . '>' );
		foreach ( $inner as $i => $node ) {
			$content[] = null;
		}
		$content[] = ( null !== $citation && '' !== $citation ? '<cite>' . $citation . '</cite>' : '' ) . '</blockquote>';
		return array( self::node( 'core/quote', $attrs, $content, $inner ) );
	}

	private static function group_block( $element, $tag, $depth ) {
		if ( ! self::attributes_allowed( $element, array( 'class', 'id' ) ) ) {
			return null;
		}
		$inner = self::children_blocks( $element, $depth );
		if ( null === $inner || ! $inner ) {
			return null;
		}
		// A plain <div> around blocks adds nothing on its own.
		if ( 'div' === $tag && ! $element->attributes->length && $depth > 0 ) {
			return $inner;
		}
		$classes = array_values( array_diff( self::classes( $element ), array( 'wp-block-group' ) ) );
		$attrs   = array();
		if ( 'div' !== $tag ) {
			$attrs['tagName'] = $tag;
		}
		self::common_attrs( $element, $classes, $attrs );
		if ( 0 === $depth || in_array( $tag, array( 'section', 'article', 'main' ), true ) ) {
			$attrs['layout'] = array( 'type' => 'constrained' );
		}
		$content = array( '<' . $tag . self::class_attr( array_merge( array( 'wp-block-group' ), $classes ) ) . self::id_attr( $element ) . '>' );
		foreach ( $inner as $i => $node ) {
			$content[] = null;
		}
		$content[] = '</' . $tag . '>';
		return array( self::node( 'core/group', $attrs, $content, $inner ) );
	}

	/**
	 * Attributes in the order the block type declares them — the order the
	 * editor writes them in, so its first save does not rewrite every comment.
	 */
	private static function in_registered_order( $name, $attrs ) {
		$type = class_exists( 'WP_Block_Type_Registry' ) ? WP_Block_Type_Registry::get_instance()->get_registered( $name ) : null;
		if ( ! $type || ! is_array( $type->attributes ) || count( $attrs ) < 2 ) {
			return $attrs;
		}
		$ordered = array();
		foreach ( array_keys( $type->attributes ) as $key ) {
			if ( array_key_exists( $key, $attrs ) ) {
				$ordered[ $key ] = $attrs[ $key ];
			}
		}
		return $ordered + $attrs;
	}

	/**
	 * A parse_blocks()-shaped node. String chunks get the line breaks the
	 * editor writes around a block's own markup and between its children.
	 *
	 * @param string       $name
	 * @param array        $attrs
	 * @param array        $chunks Strings, with null where each inner block goes.
	 * @param array[]      $inner
	 * @return array
	 */
	private static function node( $name, $attrs, $chunks, $inner = array() ) {
		$content = array();
		$last    = count( $chunks ) - 1;
		foreach ( $chunks as $i => $chunk ) {
			if ( null === $chunk ) {
				if ( $i > 0 && null === $chunks[ $i - 1 ] ) {
					$content[] = "\n\n";
				}
				$content[] = null;
				continue;
			}
			$before    = ( 0 === $i ) ? "\n" : ( ( $i > 0 && null === $chunks[ $i - 1 ] && 'core/list-item' !== $name ) ? "\n" : '' );
			$after     = ( $i === $last ) ? "\n" : ( ( $i < $last && null === $chunks[ $i + 1 ] && 'core/list-item' !== $name ) ? "\n" : '' );
			$content[] = $before . $chunk . $after;
		}
		$html = '';
		foreach ( $content as $chunk ) {
			if ( null !== $chunk ) {
				$html .= $chunk;
			}
		}
		return array(
			'blockName'    => $name,
			'attrs'        => self::in_registered_order( $name, $attrs ),
			'innerBlocks'  => $inner,
			'innerHTML'    => $html,
			'innerContent' => $content,
		);
	}
}
