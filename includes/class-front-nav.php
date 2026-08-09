<?php
/**
 * Renders every navigation zone the ACTIVE THEME declares from the WordPress
 * menu assigned to that zone's location, preserving the zone's own markup
 * structure. When the theme declares no zones, this class is inert — the
 * page's hardcoded nav is left untouched and wp-admin says so, rather than
 * the plugin guessing at markup it cannot know.
 *
 * This class used to hardcode one specific site's nav markup
 * (`nav.nav-links` / `nav.drawer-nav` and that site's dropdown structure),
 * which meant menu management silently did nothing on every other theme: a
 * WordPress menu could be built and assigned, and it was connected to no
 * markup at all. The selectors now come from the theme contract
 * (`clara_ve_theme_contract`, see visual-edit.php) and the rendering is
 * structural — derived from the zone's own existing links — so any theme
 * that declares its zones gets working menu management, and a theme with
 * bespoke nav markup (dropdowns, labelled groups) can take over rendering
 * through the `clara_ve_menu_zone_markup` filter.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Front_Nav {

	public static function init() {
		// Every visual-edit surface renders through core/html blocks — the
		// header/footer parts, the front-page pattern, tagged pages via
		// sync_to_page() — so one filter covers every place a declared zone
		// can appear. Priority 9 to land before class-tokens.php's hydration
		// on the same filter.
		add_filter( 'render_block_core/html', array( __CLASS__, 'maybe_apply_to_block' ), 9, 2 );
	}

	/**
	 * The theme's declared menu zones: [ { location, selector, label } ].
	 *
	 * @return array[]
	 */
	public static function zones() {
		$contract = clara_ve_theme_contract();
		return $contract['menus'];
	}

	/**
	 * Whether ANY declared zone has a menu assigned — the editor's cue to
	 * treat zone links as menu items instead of plain editable text. With no
	 * zones declared this is false by definition, which is what keeps the
	 * feature visibly off instead of half-working.
	 *
	 * @param string|null $location A specific location, or null for "any".
	 * @return bool
	 */
	public static function is_menu_assigned( $location = null ) {
		$locations = get_nav_menu_locations();
		if ( null !== $location ) {
			return ! empty( $locations[ $location ] );
		}
		foreach ( self::zones() as $zone ) {
			if ( ! empty( $locations[ $zone['location'] ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $block_content
	 * @param array  $block
	 * @return string
	 */
	public static function maybe_apply_to_block( $block_content, $block ) {
		return self::apply( $block_content );
	}

	/**
	 * Swap each declared zone's links for menu-driven markup,
	 * structure-preserving. apply() is idempotent — rendering twice from the
	 * same menu produces the same markup — so content passing through more
	 * than one render filter is harmless.
	 *
	 * @param string $html Page or block HTML.
	 * @return string
	 */
	public static function apply( $html ) {
		$zones = self::zones();
		if ( ! $zones || false === strpos( $html, '<' ) ) {
			return $html;
		}
		$locations = get_nav_menu_locations();

		foreach ( $zones as $zone ) {
			if ( empty( $locations[ $zone['location'] ] ) ) {
				continue;
			}
			// Cheap prefilter before any real parsing: the zone's marker
			// attribute, class, or bare tag has to occur at all.
			if ( preg_match( '/\[([a-z0-9_-]+="[^"]*")\]/i', $zone['selector'], $am ) ) {
				$probe = $am[1];
			} else {
				$parts = explode( '.', $zone['selector'], 2 );
				$probe = isset( $parts[1] ) ? $parts[1] : '<' . $parts[0];
			}
			if ( false === strpos( $html, $probe ) ) {
				continue;
			}

			$el = self::find_element( $html, $zone['selector'] );
			if ( ! $el ) {
				continue;
			}

			$tree = self::menu_tree( $zone['location'] );
			if ( empty( $tree ) ) {
				continue;
			}

			$inner = substr( $html, $el['inner_start'], $el['inner_end'] - $el['inner_start'] );

			/**
			 * Let the theme render this zone itself — for markup whose
			 * structure a generic renderer cannot reproduce (dropdown
			 * wrappers, labelled sub-groups). Return a string to take over;
			 * null falls through to the structural renderer.
			 *
			 * @param string|null $rendered New inner HTML, or null.
			 * @param array       $zone     { location, selector, label }.
			 * @param array       $tree     Menu items as a two-level tree.
			 * @param string      $inner    The zone's current inner HTML.
			 */
			$rendered = apply_filters( 'clara_ve_menu_zone_markup', null, $zone, $tree, $inner );
			if ( ! is_string( $rendered ) ) {
				$rendered = self::render_zone_inner( $inner, $tree, $zone );
			}

			$html = substr( $html, 0, $el['inner_start'] ) . $rendered . substr( $html, $el['inner_end'] );
		}

		return $html;
	}

	/**
	 * Generic, structure-preserving renderer.
	 *
	 * Two modes, chosen by evidence rather than configuration:
	 *
	 *  - IN-PLACE SYNC. When the zone holds exactly as many links as the menu
	 *    has items, each link's href and label are rewritten in document
	 *    order and every other byte — wrappers, separators, icons — is kept.
	 *    This is the steady state: the imported menu was generated FROM these
	 *    links, so the counts match until the owner adds or removes an item,
	 *    and 1:1 with the original markup is preserved exactly.
	 *
	 *  - REGENERATE. When the counts differ (an item was added or removed),
	 *    the links are rebuilt from the zone's own first link as a template,
	 *    joined by the separator the original markup used between links.
	 *    Nested items render depth-first, children directly after their
	 *    parent — flat, because the plugin cannot know what this theme's
	 *    dropdown markup looks like; a theme that has one takes over via the
	 *    clara_ve_menu_zone_markup filter above.
	 *
	 * @param string $inner The zone's current inner HTML.
	 * @param array  $tree  Menu tree.
	 * @param array  $zone  { location, selector, label, active, rest }.
	 * @return string
	 */
	private static function render_zone_inner( $inner, $tree, $zone ) {
		$flat  = self::flatten_tree( $tree );
		$links = array();
		// Attr-safe open ('>' is legal inside a quoted attribute value —
		// Tailwind writes class="[&>svg]:size-3") and whitespace-tolerant
		// close: Prettier formats a long link as '</a' + newline + '>'.
		// The literal '</a>' missed that close, the count mismatched, and
		// the renderer fell into REGENERATE on markup that was in steady
		// state — rebuilding a 2-wrapper nav into 11 (mc-005: 367px folded
		// to 184px while the pixel gate scored 0.00097).
		preg_match_all( '/<a\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>.*?<\/a\s*>/is', $inner, $m, PREG_OFFSET_CAPTURE );
		foreach ( $m[0] as $hit ) {
			$links[] = array( 'html' => $hit[0], 'at' => $hit[1] );
		}

		if ( $links && count( $links ) === count( $flat ) ) {
			$out    = '';
			$cursor = 0;
			foreach ( $links as $i => $link ) {
				$out    .= substr( $inner, $cursor, $link['at'] - $cursor );
				$out    .= self::fill_link( $link['html'], $flat[ $i ], $zone );
				$cursor  = $link['at'] + strlen( $link['html'] );
			}
			return $out . substr( $inner, $cursor );
		}

		if ( ! $links ) {
			// A declared zone with no link at all inside it has nothing to
			// derive a template from; leaving it alone is the only move that
			// cannot destroy markup.
			return $inner;
		}

		$template  = $links[0]['html'];
		$separator = count( $links ) > 1
			? substr( $inner, $links[0]['at'] + strlen( $links[0]['html'] ), $links[1]['at'] - $links[0]['at'] - strlen( $links[0]['html'] ) )
			: ' ';
		$leading  = substr( $inner, 0, $links[0]['at'] );
		$last     = $links[ count( $links ) - 1 ];
		$trailing = substr( $inner, $last['at'] + strlen( $last['html'] ) );

		$rendered = array();
		foreach ( $flat as $item ) {
			$rendered[] = self::fill_link( $template, $item, $zone );
		}
		return $leading . implode( $separator, $rendered ) . $trailing;
	}

	/**
	 * Whether a menu item's target is the page being served — same host (or
	 * no host), same path. What the per-page active highlight keys on.
	 *
	 * @param WP_Post $item Menu item.
	 * @return bool
	 */
	private static function is_current_target( $item ) {
		$url  = (string) $item->url;
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host ) {
			$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			if ( strtolower( $host ) !== strtolower( $home_host ) ) {
				return false;
			}
		}
		$request = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_path = untrailingslashit( (string) wp_parse_url( $request, PHP_URL_PATH ) );
		$item_path    = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return $item_path === $request_path;
	}

	/**
	 * One link element with the menu item's url, label and target written in.
	 * The label replaces the run that carried the item's name (see the
	 * ladder at the replacement site), so an icon, badge or description
	 * inside the link survives; a plain-text link is replaced wholesale.
	 *
	 * When the zone declares active/rest nav classes, a link that wears the
	 * RESTING value and points at the page being served is swapped to the
	 * ACTIVE value — restoring the per-page highlight the original design
	 * had and a shared template part cannot carry. Only rest-wearing links
	 * qualify, so a brand link or CTA sharing the zone (different classes)
	 * is never touched, and a design that marks nothing via classes
	 * declares nothing and gets nothing.
	 *
	 * @param string  $link_html Full <a …>…</a> markup to use as the shape.
	 * @param WP_Post $item      Menu item.
	 * @param array   $zone      { active, rest, … }.
	 * @return string
	 */
	private static function fill_link( $link_html, $item, $zone = array() ) {
		// Same two tolerances as the matcher above, or a link the matcher
		// accepted is dismembered here: strpos('>') ends the open tag inside
		// a quoted attribute value, and strrpos('</a>') returns false on
		// '</a' + newline + '>', handing substr a negative length.
		if ( preg_match( '/^<a\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/is', $link_html, $om ) ) {
			$open = $om[0];
		} else {
			$open = substr( $link_html, 0, strpos( $link_html, '>' ) + 1 );
		}
		$inner = preg_replace( '/<\/a\s*>\s*$/is', '', substr( $link_html, strlen( $open ) ) );

		if ( ! empty( $zone['active'] ) && ! empty( $zone['rest'] ) ) {
			$is_current = self::is_current_target( $item );
			foreach ( array(
				// Settled markup wears rest; a re-render of live output may
				// wear active on the previously-current link — normalise
				// from either state so rendering stays idempotent.
				array( $zone['rest'], $zone['active'] ),
				array( $zone['active'], $zone['rest'] ),
			) as $pair ) {
				list( $from, $to ) = $pair;
				if ( false !== strpos( $open, 'class="' . $from . '"' ) ) {
					$open = str_replace( 'class="' . $from . '"', 'class="' . ( $is_current ? $zone['active'] : $zone['rest'] ) . '"', $open );
					break;
				}
				if ( false !== strpos( $open, "class='" . $from . "'" ) ) {
					$open = str_replace( "class='" . $from . "'", "class='" . ( $is_current ? $zone['active'] : $zone['rest'] ) . "'", $open );
					break;
				}
			}
		}

		$href = ' href="' . esc_url( $item->url ) . '"';
		if ( preg_match( '/\shref=("[^"]*"|\'[^\']*\')/i', $open ) ) {
			$open = preg_replace( '/\shref=("[^"]*"|\'[^\']*\')/i', $href, $open, 1 );
		} else {
			$open = substr( $open, 0, -1 ) . $href . '>';
		}
		if ( '_blank' === $item->target ) {
			if ( preg_match( '/\starget=("[^"]*"|\'[^\']*\')/i', $open ) ) {
				$open = preg_replace( '/\starget=("[^"]*"|\'[^\']*\')/i', ' target="_blank"', $open, 1 );
			} else {
				$open = substr( $open, 0, -1 ) . ' target="_blank">';
			}
		} else {
			$open = preg_replace( '/\starget=("[^"]*"|\'[^\']*\')/i', '', $open );
		}

		$label = esc_html( $item->title );
		if ( false === strpos( $inner, '<' ) ) {
			$inner = $label;
		} else {
			// Which text run is the LABEL? "The longest" was the old answer,
			// and it is wrong on the common two-line dropdown item — icon,
			// <span>CRM</span>, <p>For great customer relationships</p> —
			// where the longest run is the DESCRIPTION. The label landed
			// there, so every item read its own name twice and the sentence
			// the design wrote was destroyed on every page (bigspring-017).
			//
			// The ladder, in order of evidence:
			//   1. A run whose text already equals the item's title. Links
			//      are paired with items positionally, so on any render where
			//      the owner has not retitled the item — including every
			//      re-render — the name run identifies itself exactly.
			//   2. Otherwise the longest run OUTSIDE <p>: block-level prose
			//      inside a link is descriptive furniture, never the label.
			//   3. Otherwise the longest run anywhere (one-line links,
			//      glyph-and-text shapes — the old rule, where it was right).
			$chunks = preg_split( '/(<(?:"[^"]*"|\'[^\']*\'|[^>"\'])+>)/', $inner, -1, PREG_SPLIT_DELIM_CAPTURE );
			$title_run   = -1;
			$best        = -1;
			$best_len    = 0;
			$best_any    = -1;
			$best_any_len = 0;
			$p_depth     = 0;
			$item_title  = trim( wp_specialchars_decode( $item->title, ENT_QUOTES ) );
			foreach ( $chunks as $i => $chunk ) {
				if ( '' === $chunk ) {
					continue;
				}
				if ( '<' === $chunk[0] ) {
					if ( preg_match( '/^<p[\s>]/i', $chunk ) ) {
						++$p_depth;
					} elseif ( preg_match( '/^<\/p/i', $chunk ) ) {
						$p_depth = max( 0, $p_depth - 1 );
					}
					continue;
				}
				$text = trim( html_entity_decode( $chunk, ENT_QUOTES ) );
				if ( '' === $text ) {
					continue;
				}
				if ( -1 === $title_run && '' !== $item_title && $text === $item_title ) {
					$title_run = $i;
				}
				if ( strlen( $text ) > $best_any_len ) {
					$best_any     = $i;
					$best_any_len = strlen( $text );
				}
				if ( 0 === $p_depth && strlen( $text ) > $best_len ) {
					$best     = $i;
					$best_len = strlen( $text );
				}
			}
			$target = ( $title_run >= 0 ) ? $title_run : ( ( $best >= 0 ) ? $best : $best_any );
			if ( $target >= 0 ) {
				$chunks[ $target ] = $label;
				$inner             = implode( '', $chunks );
			} else {
				$inner .= $label;
			}
		}

		return $open . $inner . '</a>';
	}

	/**
	 * Depth-first flatten: each parent directly followed by its children —
	 * the same order a nav renders its links in the document.
	 *
	 * @param array $tree
	 * @return WP_Post[]
	 */
	private static function flatten_tree( $tree ) {
		$flat = array();
		foreach ( $tree as $node ) {
			$flat[] = $node['item'];
			foreach ( $node['children'] as $child ) {
				$flat[] = $child;
			}
		}
		return $flat;
	}

	/**
	 * Find the first element matching the zone selector, depth-aware so a
	 * list-based nav's nested lists don't truncate the match.
	 *
	 * Two selector forms:
	 *  - `[attr="value"]` / `tag[attr="value"]` — what generated themes
	 *    declare: the converter stamps each nav group with data-ve-nav="n",
	 *    unique by construction, because real sites' class names routinely
	 *    cannot address one element (shared Tailwind utilities, or tokens
	 *    like "group/navigation-menu" that are not valid CSS classes).
	 *  - `tag.class` / `tag` — for hand-written themes whose markup does
	 *    have a distinguishing class.
	 *
	 * @param string $html
	 * @param string $selector
	 * @return array|null { start, end, inner_start, inner_end }
	 */
	private static function find_element( $html, $selector ) {
		$attr_name  = '';
		$attr_value = null;
		$class      = '';
		if ( preg_match( '/^([a-z][a-z0-9-]*)?\[([a-z0-9_-]+)="([^"]*)"\]$/i', $selector, $sm ) ) {
			$tag        = strtolower( $sm[1] );
			$attr_name  = strtolower( $sm[2] );
			$attr_value = $sm[3];
		} else {
			$parts = explode( '.', $selector, 2 );
			$tag   = strtolower( preg_replace( '/[^a-z0-9-]/i', '', $parts[0] ) );
			$class = isset( $parts[1] ) ? $parts[1] : '';
		}
		if ( '' === $tag && '' === $attr_name ) {
			return null;
		}
		$tag_pattern = '' !== $tag ? preg_quote( $tag, '/' ) : '[a-z][a-z0-9-]*';

		$offset = 0;
		while ( preg_match( '/<(' . $tag_pattern . ')\b[^>]*>/i', $html, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
			$open  = $m[0][0];
			$start = $m[0][1];
			$tag   = strtolower( $m[1][0] );
			$offset = $start + 1;

			if ( '' !== $attr_name ) {
				if ( ! preg_match( '/\b' . preg_quote( $attr_name, '/' ) . '\s*=\s*"' . preg_quote( $attr_value, '/' ) . '"/i', $open ) ) {
					continue;
				}
			} elseif ( '' !== $class ) {
				if ( ! preg_match( '/\bclass\s*=\s*("[^"]*"|\'[^\']*\')/i', $open, $cm ) ) {
					continue;
				}
				$classes = preg_split( '/\s+/', trim( substr( $cm[1], 1, -1 ) ) );
				if ( ! in_array( $class, $classes, true ) ) {
					continue;
				}
			}

			// Depth-scan to this element's own closing tag.
			if ( ! preg_match_all( '/<' . $tag . '\b[^>]*>|<\/' . $tag . '\s*>/i', $html, $all, PREG_OFFSET_CAPTURE, $start ) ) {
				return null;
			}
			$depth = 0;
			foreach ( $all[0] as $hit ) {
				$depth += ( '/' === $hit[0][1] ) ? -1 : 1;
				if ( 0 === $depth ) {
					$end = $hit[1] + strlen( $hit[0] );
					return array(
						'start'       => $start,
						'end'         => $end,
						'inner_start' => $start + strlen( $open ),
						'inner_end'   => $hit[1],
					);
				}
			}
			return null;
		}
		return null;
	}

	/**
	 * Menu items as a two-level tree: [ [item, children: [...] ], ... ].
	 *
	 * @param string $location
	 * @return array
	 */
	private static function menu_tree( $location ) {
		$locations = get_nav_menu_locations();
		if ( empty( $locations[ $location ] ) ) {
			return array();
		}
		$items = wp_get_nav_menu_items( $locations[ $location ] );
		if ( ! $items ) {
			return array();
		}

		$tree = array();
		$map  = array();
		foreach ( $items as $item ) {
			if ( empty( $item->menu_item_parent ) ) {
				$map[ $item->ID ] = array(
					'item'     => $item,
					'children' => array(),
				);
				$tree[]           = &$map[ $item->ID ];
			}
		}
		foreach ( $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			if ( $parent && isset( $map[ $parent ] ) ) {
				$map[ $parent ]['children'][] = $item;
			}
		}
		return $tree;
	}

	/**
	 * One-time seed on plugin activation, from data the THEME declares via
	 * the `clara_ve_seed_menus` filter — the plugin itself knows no site's
	 * pages and seeds nothing by default. A conversion's real menus arrive
	 * through the content bundle importer instead (menus.json), which is why
	 * activation skips this entirely when a theme bundle is present.
	 *
	 * Filter shape: [ { location, name, items: [ { title, url,
	 * children?: [ { title, url } ] } ] } ].
	 */
	public static function seed_menu() {
		/**
		 * Menus to scaffold on activation when nothing is assigned yet.
		 *
		 * @param array[] $seeds Default none — no guessing.
		 */
		$seeds = apply_filters( 'clara_ve_seed_menus', array() );

		foreach ( (array) $seeds as $seed ) {
			if ( empty( $seed['location'] ) || empty( $seed['name'] ) ) {
				continue;
			}
			if ( self::is_menu_assigned( $seed['location'] ) ) {
				continue;
			}

			$existing = wp_get_nav_menu_object( $seed['name'] );
			if ( $existing ) {
				$menu_id = (int) $existing->term_id;
			} else {
				$menu_id = wp_create_nav_menu( $seed['name'] );
				if ( is_wp_error( $menu_id ) ) {
					continue;
				}
				foreach ( (array) ( isset( $seed['items'] ) ? $seed['items'] : array() ) as $entry ) {
					$parent_id = wp_update_nav_menu_item(
						$menu_id,
						0,
						array(
							'menu-item-title'  => isset( $entry['title'] ) ? $entry['title'] : '',
							'menu-item-url'    => isset( $entry['url'] ) ? $entry['url'] : '',
							'menu-item-status' => 'publish',
						)
					);
					if ( is_wp_error( $parent_id ) ) {
						continue;
					}
					foreach ( (array) ( isset( $entry['children'] ) ? $entry['children'] : array() ) as $child ) {
						wp_update_nav_menu_item(
							$menu_id,
							0,
							array(
								'menu-item-title'     => isset( $child['title'] ) ? $child['title'] : '',
								'menu-item-url'       => isset( $child['url'] ) ? $child['url'] : '',
								'menu-item-parent-id' => (int) $parent_id,
								'menu-item-status'    => 'publish',
							)
						);
					}
				}
			}

			$locations                       = get_nav_menu_locations();
			$locations[ $seed['location'] ]  = $menu_id;
			set_theme_mod( 'nav_menu_locations', $locations );
		}
	}
}

Clara_VE_Front_Nav::init();
