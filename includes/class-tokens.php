<?php
/**
 * Dynamic-content tokens: [wp-posts]/[wp-form]/[wp-menu] inside a visual-edit
 * page's raw source. Tokens are plain text in the stored source — they never
 * shift the source's own element indexes (bridge.js's path stamping walks the
 * PARSED source, where a token is just a text node occupying one slot). Only
 * the hydrated, rendered-for-visitors HTML gains a wrapper element per zone;
 * bridge.js is taught to stamp-but-not-recurse into it so downstream sibling
 * paths still line up (see assets/bridge.js's SKIP_ATTR handling).
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Tokens {

	const PATTERN = '/\[wp-(posts|form|menu|article)([^\]]*)\](.*?)\[\/wp-\1\]/s';

	/** Transparent 1×1 GIF, for {image} on a post with no featured image. */
	const BLANK_IMAGE = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

	/**
	 * Wrapper element used for a hydrated zone, per token type. A zone marker
	 * is `display:contents` so it never affects layout, but it still has to
	 * PARSE correctly where it sits: an article title token lives inside an
	 * <h1>, which accepts phrasing content only, so a <div> there would be a
	 * parse error the browser silently rewrites — taking the path indexes with
	 * it. Fields that emit flow content (the post body, the image, the share
	 * row, prev/next) keep a <div>.
	 */
	const INLINE_ARTICLE_FIELDS = array( 'title', 'category', 'date', 'readingtime', 'author' );

	/**
	 * Fields whose inner content is a markup FRAGMENT the owner authored, not a
	 * single value — the share row, the prev/next pair.
	 *
	 * These are deliberately NOT wrapped as one zone. A zone locks everything
	 * inside it, which for a fragment means the labels, the card boxes and the
	 * link text all become untouchable together, and the only thing you can
	 * style is the outer container. Their structure survives hydration
	 * unchanged — the fragment is emitted once, with values substituted in
	 * place — so the rendered DOM lines up with the stored source element for
	 * element, and the bridge can stamp straight through it. Only the
	 * generated VALUES inside get zones (see wrap_value), so what is locked is
	 * exactly what WordPress supplies and nothing else.
	 */
	const FRAGMENT_ARTICLE_FIELDS = array( 'share', 'nav' );

	/**
	 * Whether the [wp-posts] listing just rendered left posts behind — i.e.
	 * whether a "load more" button on the same page has anything to fetch.
	 * Written by render_posts(), read by hide_exhausted_load_more() a few
	 * lines later in the same hydrate() pass, so it never has to survive a
	 * request.
	 *
	 * @var bool
	 */
	private static $posts_remaining = false;

	/**
	 * Wrap one generated value so it can't be typed over, but only in the edit
	 * preview — on the live page it is just the value.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function wrap_value( $value ) {
		if ( function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview() ) {
			return '<span data-cve-zone="article" data-cve-skip>' . $value . '</span>';
		}
		return $value;
	}

	public static function init() {
		// core/html's own block-render filter — fires for every Custom HTML
		// block on the site, so every call bails out fast unless the block
		// carries the clara-ve-key marker sync_to_page() embeds. Runs on
		// normal front-end requests and the edit-preview iframe alike (both
		// flow through the same the_content()/do_blocks() render path).
		add_filter( 'render_block_core/html', array( __CLASS__, 'maybe_hydrate_block' ), 10, 2 );
	}

	/**
	 * @param string $block_content
	 * @param array  $block
	 * @return string
	 */
	public static function maybe_hydrate_block( $block_content, $block ) {
		if ( false === strpos( $block_content, 'clara-ve-key:' ) ) {
			return $block_content;
		}
		if ( false === strpos( $block_content, '[wp-' ) && false === strpos( $block_content, 'data-cve-field-' ) ) {
			return $block_content;
		}
		return self::hydrate( $block_content );
	}

	/**
	 * Attributes that are filled in from the current post at render time.
	 *
	 * A token cannot live inside an attribute: its own quoting would close the
	 * attribute early, and in the edit preview a token becomes a zone ELEMENT,
	 * which an attribute has no room for. So the element carries a marker
	 * instead — `<a class="eyebrow" data-cve-field-href="category_url"
	 * href="/blog/">` — and this pass rewrites the real attribute.
	 *
	 * The static value stays in the markup as a genuine fallback: it is what
	 * renders with no post in scope, and it means an accidental edit to that
	 * attribute changes only the fallback rather than destroying the binding.
	 * The element itself stays completely ordinary — clickable, styleable —
	 * which is the whole reason for doing it this way rather than wrapping it
	 * in a token and losing it to a locked zone.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function apply_dynamic_attrs( $html ) {
		if ( false === strpos( $html, 'data-cve-field-' ) ) {
			return $html;
		}
		$post = get_post();
		if ( ! $post instanceof WP_Post ) {
			return $html;
		}

		$result = preg_replace_callback(
			'/<([a-z0-9]+)\b([^>]*\bdata-cve-field-([a-z-]+)="([a-z_]+)"[^>]*)>/i',
			function ( $m ) use ( $post ) {
				$attribute = strtolower( $m[3] );
				$value     = self::dynamic_attr_value( $m[4], $post );
				if ( null === $value ) {
					return $m[0];
				}
				$attrs = $m[2];
				$quoted = ' ' . $attribute . '="' . esc_url( $value ) . '"';
				if ( preg_match( '/\s' . preg_quote( $attribute, '/' ) . '="[^"]*"/i', $attrs ) ) {
					$attrs = preg_replace( '/\s' . preg_quote( $attribute, '/' ) . '="[^"]*"/i', $quoted, $attrs, 1 );
				} else {
					$attrs .= $quoted;
				}
				return '<' . $m[1] . $attrs . '>';
			},
			$html
		);
		return null === $result ? $html : $result;
	}

	/**
	 * @param string  $field
	 * @param WP_Post $post
	 * @return string|null Null leaves the markup's own fallback in place.
	 */
	private static function dynamic_attr_value( $field, $post ) {
		switch ( $field ) {
			case 'category_url':
				$categories = get_the_category( $post->ID );
				return $categories ? get_category_link( $categories[0]->term_id ) : null;
			case 'permalink':
				return get_permalink( $post );
		}
		return null;
	}

	public static function hydrate( $html ) {
		// Per block, not per request: the header and footer hydrate through
		// here too, and a listing's leftover answer must not decide whether
		// some other block's button is hidden.
		self::$posts_remaining = false;

		// Mask HTML comments before matching. These templates are heavily
		// commented — that is the house style — and a comment that so much as
		// MENTIONS a token is otherwise catastrophic: an unpaired `[wp-posts]`
		// written in prose pairs with the first real closing tag further down
		// and the callback replaces everything in between, silently deleting a
		// chunk of the page. Comments are restored verbatim afterwards, so
		// documentation stays documentation and never becomes markup.
		$comments = array();
		$masked   = preg_replace_callback(
			'/<!--.*?-->/s',
			function ( $m ) use ( &$comments ) {
				$placeholder              = '<!--cve-c' . count( $comments ) . '-->';
				$comments[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$html
		);
		if ( null === $masked ) {
			$masked = $html;
		}

		$rendered = preg_replace_callback( self::PATTERN, array( __CLASS__, 'render_token' ), $masked );
		if ( null === $rendered ) {
			$rendered = $masked;
		}

		$rendered = self::apply_dynamic_attrs( $rendered );
		$rendered = self::hide_exhausted_load_more( $rendered );

		return $comments ? strtr( $rendered, $comments ) : $rendered;
	}

	/**
	 * @param array $m Regex match: [0] full match, [1] type, [2] raw attrs, [3] inner template.
	 * @return string
	 */
	private static function render_token( $m ) {
		$type  = $m[1];
		$atts  = shortcode_parse_atts( $m[2] );
		$atts  = is_array( $atts ) ? $atts : array();
		$inner = $m[3];

		switch ( $type ) {
			case 'posts':
				$body = self::render_posts( $atts, $inner );
				break;
			case 'form':
				$body = self::render_form( $atts, $inner );
				break;
			case 'menu':
				$body = self::render_menu( $atts, $inner );
				break;
			case 'article':
				$body = self::render_article_field( $atts, $inner );
				break;
			default:
				return $m[0];
		}

		// The zone wrapper exists ONLY for the edit-preview bridge: it keeps a
		// hydrated token as a single stamp-but-don't-recurse node so sibling
		// path indexes stay aligned with the stored source (where the token is
		// one text node). On a real visitor's page load bridge.js never runs,
		// and the wrapper is actively harmful — as the sole child of a CSS grid
		// container (e.g. .journal-grid) it becomes the one grid item and
		// collapses the layout into a single column. So emit it only in the
		// authorized edit preview; render the raw hydrated markup to everyone
		// else, where the cards/links become direct children of their intended
		// parent exactly as the design expects.
		if ( function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview() ) {
			$field = ( 'article' === $type && ! empty( $atts['field'] ) ) ? sanitize_key( $atts['field'] ) : '';
			if ( in_array( $field, self::FRAGMENT_ARTICLE_FIELDS, true ) ) {
				// Already zoned value by value — see FRAGMENT_ARTICLE_FIELDS.
				return $body;
			}
			$tag = in_array( $field, self::INLINE_ARTICLE_FIELDS, true ) ? 'span' : 'div';
			// For a POSTS zone this wrapper's path is load-bearing beyond the
			// sibling-slot alignment: in the parsed stored source, the element
			// occupying this slot is the card TEMPLATE inside the token — the
			// token's [wp-posts]…[/wp-posts] text nodes don't count as
			// elements, its inner card markup does. The editor's CARD STYLE
			// panel leans on exactly that equivalence: a set-style patch on
			// the zone's path lands on the template element, so every card —
			// including future posts — renders with the styling, while
			// bridge.js fans the live preview out to the rendered cards. A
			// token whose inner markup had SEVERAL top-level elements would
			// both break sibling alignment (one wrapper here vs many elements
			// there) and quietly move the style onto only the first — one
			// card container between the tags is the contract.
			return '<' . $tag . ' data-cve-zone="' . esc_attr( $type ) . '" data-cve-skip>' . $body . '</' . $tag . '>';
		}
		return $body;
	}

	/**
	 * @param array  $atts     count, category, orderby, order, offset, image_size.
	 * @param string $template Per-item markup with {title} {excerpt} {url} {image} {date} {category} placeholders.
	 * @return string
	 */
	private static function render_posts( $atts, $template ) {
		$args = array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => isset( $atts['count'] ) ? max( 1, (int) $atts['count'] ) : 3,
			// ID is a TIEBREAK, not a preference. Two posts sharing a
			// publication date — ordinary on an imported archive, where a whole
			// site's articles can carry the same day — leave `orderby=date`
			// with nothing to separate them, so the order falls to however the
			// rows happen to come back. Verified: the same six articles came
			// out in two different orders on two installs, purely because the
			// second one's posts table also held two other themes' posts.
			'orderby'        => isset( $atts['orderby'] )
				? sanitize_key( $atts['orderby'] )
				: array(
					'date' => isset( $atts['order'] ) && 'asc' === strtolower( $atts['order'] ) ? 'ASC' : 'DESC',
					'ID'   => isset( $atts['order'] ) && 'asc' === strtolower( $atts['order'] ) ? 'ASC' : 'DESC',
				),
			'order'          => isset( $atts['order'] ) && 'asc' === strtolower( $atts['order'] ) ? 'ASC' : 'DESC',
			// Only this theme's own articles (plus any the owner wrote). Two
			// converted themes on one install otherwise share one pool of
			// posts, and the listing shows whoever's dates are newest — see
			// clara_ve_theme_post_scope().
			'meta_query'     => clara_ve_theme_post_scope(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
		if ( ! empty( $atts['category'] ) ) {
			// category="current" — the category of the post being READ, resolved
			// per request. This is what a "related articles" strip means, and
			// without it there was no way to express it: a fixed category is
			// wrong on every article outside it, and no category at all returns
			// the newest posts, so a Design article, a Customer Success article
			// and a Software Engineering article all recommended the same three
			// Product posts. Found by reading converted pages side by side
			// against their originals, which hand-pick same-category articles;
			// no pixel or count gate can see it, because three cards render
			// either way.
			//
			// Falls through to no filter when there is no post in scope or it
			// has no category — the newest posts are a defensible answer for a
			// strip that has to render something, and an empty related block
			// would read as a broken page.
			if ( 'current' === strtolower( (string) $atts['category'] ) ) {
				$host = get_post();
				$host_cats = ( $host instanceof WP_Post ) ? get_the_category( $host->ID ) : array();
				if ( $host_cats ) {
					$args['category__in'] = array( (int) $host_cats[0]->term_id );
				}
			} else {
				$args['category_name'] = sanitize_title( $atts['category'] );
			}
		}
		// WordPress's 'medium' is 300px tall — right for a sidebar thumbnail and
		// far too small for a listing card, let alone a full-width feature,
		// where it was being upscaled several times over and looked blurred.
		// 'large' covers a normal card; a page rendering the image bigger can
		// ask for more (image_size="full").
		$image_size = ! empty( $atts['image_size'] ) ? sanitize_key( $atts['image_size'] ) : 'large';

		// Skipping the first N is what makes the common "one featured post, then
		// the rest in a grid" layout possible without the featured one showing
		// up twice.
		if ( ! empty( $atts['offset'] ) ) {
			$args['offset'] = max( 0, (int) $atts['offset'] );
		}

		// A listing rendered on a single post is a "related articles" strip, and
		// it must never offer the post being read as something else to read.
		// Excluded automatically rather than by an attribute nobody would
		// remember: there is no layout where "more articles" should include
		// this one, and a converted article template inherits its related strip
		// from the source page, where the question never came up. Verified
		// live: every post listed itself first. Scoped to a singular POST, so
		// an ordinary listing page is untouched. One extra post is fetched so
		// removing the current one does not silently shorten the strip.
		$exclude_current = is_singular( 'post' ) && get_queried_object_id();
		if ( $exclude_current ) {
			$args['post__not_in']   = array( get_queried_object_id() );
			$args['posts_per_page'] = (int) $args['posts_per_page'] + 1;
		}

		$query = new WP_Query( $args );
		if ( $exclude_current ) {
			$args['posts_per_page'] = (int) $args['posts_per_page'] - 1;
			$query->posts           = array_slice( $query->posts, 0, (int) $args['posts_per_page'] );
		}

		// found_posts counts everything matching the query, offset included, so
		// "how many are we standing on" is offset + how many we just printed.
		$shown                 = ( isset( $args['offset'] ) ? (int) $args['offset'] : 0 ) + count( $query->posts );
		self::$posts_remaining = (int) $query->found_posts > $shown;

		$out = '';
		foreach ( $query->posts as $post ) {
			$categories = get_the_category( $post->ID );
			$thumb      = get_the_post_thumbnail_url( $post, $image_size );
			// A card design routinely shows TWO taxonomy chips — a category and
			// something narrower beside it ("Product" + "Management"). With only
			// {category} available, a conversion had to put it in both slots, so
			// every card read "Product / Product" where the original said
			// "Product / Management": real content lost, on every card, with the
			// card count and every pixel band still matching. Found by reading
			// the listing side by side against its original.
			//
			// {tag} is the post's first tag, {tags} all of them comma-separated,
			// and {category2} the second category for a design that uses two of
			// those instead. Each falls back to empty rather than repeating the
			// category — an empty chip is visibly missing data, which is
			// honest; a duplicated one silently asserts something false.
			$tags = get_the_tags( $post->ID );
			$tags = is_array( $tags ) ? $tags : array();
			$out .= strtr(
				$template,
				array(
					'{title}'    => esc_html( get_the_title( $post ) ),
					'{excerpt}'  => esc_html( wp_trim_words( get_the_excerpt( $post ), 30 ) ),
					'{url}'      => esc_url( get_permalink( $post ) ),
					'{tag}'      => esc_html( $tags ? $tags[0]->name : '' ),
					'{tags}'     => esc_html( implode( ', ', wp_list_pluck( $tags, 'name' ) ) ),
					'{category2}' => esc_html( ( $categories && isset( $categories[1] ) ) ? $categories[1]->name : '' ),
					// A post with no featured image must still leave the card's
					// frame intact (that is the documented behaviour), but it
					// must NOT leave src="". An empty src is not "no image":
					// browsers resolve it against the current document and
					// re-request the PAGE, then draw a broken-image icon. A
					// transparent 1×1 keeps the frame, draws nothing, and asks
					// the network for nothing.
					'{image}'    => $thumb ? esc_url( $thumb ) : self::BLANK_IMAGE,
					'{date}'     => esc_html( get_the_date( '', $post ) ),
					'{category}' => esc_html( $categories ? $categories[0]->name : '' ),
				)
			);
		}
		wp_reset_postdata();
		return $out;
	}

	/**
	 * The next page of a listing, rendered from the SAME per-item template the
	 * page already uses — the "Load more" button's server side.
	 *
	 * The template is read out of the page's own stored source rather than
	 * accepted from the request. That is the whole security model here: the
	 * endpoint is public (it serves already-public posts), so the only thing a
	 * caller may choose is which stored key and which page number, never what
	 * markup gets rendered. It also means a card redesigned in the visual
	 * editor is redesigned for page two automatically, with nothing to keep in
	 * sync.
	 *
	 * @param string $key  Stored-source key of the page holding the listing.
	 * @param int    $page 1-based; page 1 is what the page itself already shows.
	 * @return array|WP_Error { html, has_more }
	 */
	public static function render_posts_page( $key, $page ) {
		$key    = sanitize_key( $key );
		$page   = max( 1, (int) $page );
		$source = Clara_VE_Source_Store::get_current_source( $key );
		if ( '' === trim( (string) $source ) ) {
			return new WP_Error( 'clara_ve_no_source', 'No such page.', array( 'status' => 404 ) );
		}

		// Comments are masked for the same reason hydrate() masks them: a token
		// mentioned in prose must not pair with a real closing tag. The FIRST
		// listing on the page is the one paged through — a page with two would
		// need the button to say which, and no design here has two.
		$masked = preg_replace( '/<!--.*?-->/s', '', $source );
		if ( ! preg_match( '/\[wp-posts([^\]]*)\](.*?)\[\/wp-posts\]/s', null === $masked ? $source : $masked, $m ) ) {
			return new WP_Error( 'clara_ve_no_listing', 'That page has no posts listing.', array( 'status' => 404 ) );
		}

		$atts  = shortcode_parse_atts( $m[1] );
		$atts  = is_array( $atts ) ? $atts : array();
		$count = isset( $atts['count'] ) ? max( 1, (int) $atts['count'] ) : 3;

		// Page 2 starts where page 1 stopped — including any offset the token
		// already had, so a "featured post first, rest in a grid" layout pages
		// through the rest without repeating the featured one.
		$atts['offset'] = ( isset( $atts['offset'] ) ? max( 0, (int) $atts['offset'] ) : 0 ) + $count * ( $page - 1 );

		self::$posts_remaining = false;
		$html                  = self::render_posts( $atts, $m[2] );

		return array(
			'html'     => $html,
			'has_more' => self::$posts_remaining,
		);
	}

	/**
	 * Hide a "load more" button that has nothing left to load.
	 *
	 * The button is ordinary markup in the page source — that is what keeps it
	 * clickable and styleable in the visual editor — so the page cannot know on
	 * its own whether a tenth post exists. Rather than have the button appear
	 * and then vanish once JavaScript has asked, the render that already ran
	 * the query answers it: `hidden` is added when the listing is exhausted.
	 *
	 * An attribute-only change is deliberate. bridge.js derives element paths
	 * from child indexes, so adding or removing the ELEMENT here would shift
	 * every following sibling's path and misalign the editor's patches; an
	 * attribute leaves the tree identical. In the edit preview nothing is
	 * hidden at all — a button you cannot see is a button you cannot restyle.
	 *
	 * @param string $html
	 * @return string
	 */
	private static function hide_exhausted_load_more( $html ) {
		if ( false === strpos( $html, 'data-cve-load-more' ) ) {
			return $html;
		}
		if ( self::$posts_remaining ) {
			return $html;
		}
		if ( function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview() ) {
			return $html;
		}
		$result = preg_replace(
			'/<(button|a)\b((?:[^>]*\s)?data-cve-load-more="[^"]*"[^>]*)>/i',
			'<$1$2 hidden>',
			$html
		);
		return null === $result ? $html : $result;
	}

	/**
	 * One field of the post currently being viewed, for the shared article
	 * template (parts/article.html).
	 *
	 * The token is PAIRED and its inner content is the design-time sample:
	 *
	 *     <h1>[wp-article field="title"]A sample headline[/wp-article]</h1>
	 *
	 * That shape is deliberate. The stored template is then self-describing —
	 * open it and it reads as a real article — so the editor canvas looks like
	 * the finished thing, and the layout does not collapse when the template is
	 * rendered somewhere with no post in scope. Live, the sample is replaced by
	 * the real value; in the edit preview the replacement is wrapped as a zone
	 * (see render_token) so generated text can't be hand-edited into something
	 * the next post would overwrite.
	 *
	 * `share` and `nav` take their inner content as a per-item TEMPLATE with
	 * {placeholders}, exactly like [wp-posts] does. That is what keeps their
	 * markup authored in the source instead of hardcoded here — and it sidesteps
	 * the one thing a zone wrapper cannot do, which is live inside an attribute:
	 * a URL only ever appears via a placeholder in a template string.
	 *
	 * @param array  $atts   field.
	 * @param string $sample Design-time sample / per-item template.
	 * @return string
	 */
	private static function render_article_field( $atts, $sample ) {
		$field = ! empty( $atts['field'] ) ? sanitize_key( $atts['field'] ) : '';
		$post  = get_post();

		// No post in scope (the template previewed outside a single view, or a
		// stray token on another page): show the sample. Better a plausible
		// placeholder than an empty hole.
		if ( ! $post instanceof WP_Post ) {
			return $sample;
		}

		switch ( $field ) {
			case 'title':
				return esc_html( get_the_title( $post ) );

			case 'category':
				$categories = get_the_category( $post->ID );
				return $categories ? esc_html( $categories[0]->name ) : '';

			case 'date':
				return esc_html( get_the_date( '', $post ) );

			case 'author':
				return esc_html( get_the_author_meta( 'display_name', $post->post_author ) );

			case 'excerpt':
				// The lede an article layout prints under its headline. Without
				// this field a converted article template had nowhere to put it,
				// so the source article's own lede stayed baked into the layout
				// and every post on the site showed it — the wrong summary under
				// the right headline, which reads as real content and so is not
				// obviously a bug. get_the_excerpt() falls back to a trimmed
				// body when a post has no explicit excerpt, so this is never
				// empty for a post that has any content at all.
				return esc_html( get_the_excerpt( $post ) );

			case 'readingtime':
				return esc_html( self::reading_time( $post ) );

			case 'image':
				$url = get_the_post_thumbnail_url( $post, 'full' );
				if ( ! $url ) {
					// A post with no featured image renders no <img> at all
					// rather than a broken one — the frame's own styling
					// (aspect-ratio, background) still holds the space.
					return '';
				}
				// Reuse the SAMPLE tag and swap only its src/alt, the same way
				// the share row reuses its own markup. Emitting a fresh
				// <img src alt> throws away every attribute the design put on
				// that element — and on a modern layout those attributes ARE
				// the layout: a fill-style hero is `class="object-cover"` plus
				// an inline `position:absolute;height:100%;width:100%`, sitting
				// in a fixed-height overflow-hidden frame. Without them the
				// image renders at its natural size, anchored top-left, so
				// every post shows a wrongly cropped hero.
				//
				// Nothing numeric can see it: the frame's height is fixed, so
				// the page height is identical and every pixel gate that skips
				// article pages skips this too. Found by reading converted
				// articles beside their originals — the crop was visibly a
				// different photograph.
				//
				// srcset is dropped: it would still name the SOURCE article's
				// file and, being a candidate list, would win over the src we
				// just rewrote.
				if ( preg_match( '/<img\b[^>]*>/i', (string) $sample, $img ) ) {
					$tag = $img[0];
					$tag = preg_replace( '/\s+srcset=("[^"]*"|\'[^\']*\')/i', '', $tag );
					$tag = preg_replace( '/\bsrc=("[^"]*"|\'[^\']*\')/i', 'src="' . esc_url( $url ) . '"', $tag, 1, $n );
					if ( ! $n ) {
						$tag = preg_replace( '/^<img\b/i', '<img src="' . esc_url( $url ) . '"', $tag, 1 );
					}
					$tag = preg_replace( '/\balt=("[^"]*"|\'[^\']*\')/i', 'alt="' . esc_attr( get_the_title( $post ) ) . '"', $tag, 1, $n );
					if ( ! $n ) {
						$tag = preg_replace( '/^<img\b/i', '<img alt="' . esc_attr( get_the_title( $post ) ) . '"', $tag, 1 );
					}
					return $tag;
				}
				return '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( get_the_title( $post ) ) . '">';

			case 'content':
				// Must go through the_content: without it shortcodes, blocks,
				// embeds and wpautop are all lost and the body renders as one
				// unbroken lump of text.
				return apply_filters( 'the_content', get_the_content( null, false, $post ) );

			case 'share':
				// A share link puts these INSIDE a query string, where HTML
				// escaping is the wrong tool and actively breaks: esc_attr
				// turns & into &amp; (splitting the parameter) and leaves #
				// alone (truncating everything after it). A title like
				// "Reach & Retention: A #1 Problem" would silently produce a
				// broken share. So the encoded forms exist and the template
				// uses them; the plain ones stay for link text.
				// Decoded before encoding, deliberately: get_the_title() runs the
				// title through wptexturize, so "&" arrives here as the HTML
				// entity "&#038;". Percent-encoding that verbatim ships the
				// entity itself into the mail subject, where nothing will turn
				// it back — the reader sees "Reach &#038; Retention".
				$share_title = html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) );
				return self::lock_generated_hrefs(
					$sample,
					strtr(
						$sample,
						array(
							'{url_encoded}'   => rawurlencode( get_permalink( $post ) ),
							'{title_encoded}' => rawurlencode( $share_title ),
							'{url}'           => esc_url( get_permalink( $post ) ),
							'{title}'         => self::wrap_value( esc_html( get_the_title( $post ) ) ),
						)
					)
				);

			case 'nav':
				return self::render_article_nav( $sample );
		}

		return $sample;
	}

	/**
	 * Previous/next links from the per-item template. Each half is dropped
	 * whole when there is no neighbouring post — the first and last article
	 * would otherwise render a link to nowhere.
	 *
	 * @param string $template Markup with a {prev}…{/prev} and/or {next}…{/next}
	 *                         block, each carrying {url} and {title}.
	 * @return string
	 */
	private static function render_article_nav( $template ) {
		$original = $template;
		$preview  = function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview();

		foreach ( array( 'prev' => true, 'next' => false ) as $which => $previous ) {
			$adjacent = get_adjacent_post( false, '', $previous );
			$pattern  = '/\{' . $which . '\}(.*?)\{\/' . $which . '\}/s';

			// The first and last article have no neighbour on one side. Live,
			// that half is dropped whole rather than rendering a link to
			// nowhere. In the EDIT PREVIEW it is kept, with placeholder text:
			// dropping it there would leave the rendered markup one element
			// short of the stored source, which is exactly what breaks the
			// path indexes the editor saves by — and the template is being
			// styled for every article, including the ones that do have both.
			if ( ! $adjacent instanceof WP_Post && ! $preview ) {
				$template = preg_replace( $pattern, '', $template );
				continue;
			}

			$url   = $adjacent instanceof WP_Post ? esc_url( get_permalink( $adjacent ) ) : '#';
			$title = $adjacent instanceof WP_Post
				? esc_html( get_the_title( $adjacent ) )
				: esc_html__( 'No article on this side', 'visual-edit-lite' );

			$replacement = preg_replace_callback(
				$pattern,
				function ( $m ) use ( $url, $title ) {
					return strtr(
						$m[1],
						array(
							'{url}'   => $url,
							'{title}' => self::wrap_value( $title ),
						)
					);
				},
				$template
			);
			$template = null === $replacement ? $template : $replacement;
		}
		return self::lock_generated_hrefs( $original, $template );
	}

	/**
	 * Flag every link whose href was BUILT from a placeholder, so the editor
	 * offers styling but not a URL field. Typing a URL there would replace the
	 * placeholder with one article's address and every other article would
	 * point at it.
	 *
	 * Matched positionally against the untouched template, since after
	 * substitution the generated href looks like any other.
	 *
	 * @param string $template Fragment before substitution.
	 * @param string $rendered Fragment after substitution.
	 * @return string
	 */
	private static function lock_generated_hrefs( $template, $rendered ) {
		// Editor metadata — a visitor's page has no use for it, and the mark
		// only means anything to the canvas.
		if ( ! function_exists( 'clara_ve_is_edit_preview' ) || ! clara_ve_is_edit_preview() ) {
			return $rendered;
		}
		preg_match_all( '/<a\b[^>]*\bhref="([^"]*)"/i', $template, $before );
		if ( empty( $before[1] ) ) {
			return $rendered;
		}
		$index = -1;
		$out   = preg_replace_callback(
			'/<a\b([^>]*)>/i',
			function ( $m ) use ( $before, &$index ) {
				$index++;
				if ( ! isset( $before[1][ $index ] ) || false === strpos( $before[1][ $index ], '{' ) ) {
					return $m[0];
				}
				return '<a data-cve-field-href="generated"' . $m[1] . '>';
			},
			$rendered
		);
		return null === $out ? $rendered : $out;
	}

	/**
	 * @param WP_Post $post
	 * @return string e.g. "5 min read"
	 */
	private static function reading_time( $post ) {
		$words   = str_word_count( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
		$minutes = max( 1, (int) ceil( $words / 200 ) );
		/* translators: %d: estimated reading time in minutes. */
		return sprintf( _n( '%d min read', '%d min read', $minutes, 'visual-edit-lite' ), $minutes );
	}

	/**
	 * Rewrites the form's action to the public submit endpoint and injects
	 * the hidden nonce/honeypot/routing fields; the designed fields
	 * (`$form_html`) are otherwise left byte-untouched.
	 *
	 * @param array  $atts      id, to, redirect.
	 * @param string $form_html The captured <form>…</form> markup, verbatim.
	 * @return string
	 */
	private static function render_form( $atts, $form_html ) {
		$form_id = isset( $atts['id'] ) ? sanitize_key( $atts['id'] ) : 'form';
		$action  = esc_url( rest_url( 'clara-ve/v1/submit' ) );

		if ( preg_match( '/<form\b[^>]*\baction\s*=\s*"[^"]*"/i', $form_html ) ) {
			$form_html = preg_replace( '/(<form\b[^>]*\baction\s*=\s*")[^"]*(")/i', '$1' . $action . '$2', $form_html, 1 );
		} else {
			$form_html = preg_replace( '/<form\b/i', '<form action="' . $action . '"', $form_html, 1 );
		}

		// Deliberately NOT named "_wpnonce" — that field name is reserved by
		// WordPress's own REST cookie-auth check (rest_cookie_check_errors()
		// reads $_REQUEST['_wpnonce'] globally, before any route's own
		// permission_callback runs, and validates it against the 'wp_rest'
		// action) — reusing it here would collide and 403 every submission.
		// The same core method is why the value is a site-scoped token rather
		// than a WP nonce; the full reasoning is on Clara_VE_Forms::origin_field().
		$hidden  = Clara_VE_Forms::origin_field();
		$hidden .= '<input type="hidden" name="form_id" value="' . esc_attr( $form_id ) . '">';
		$hidden .= '<input type="hidden" name="to" value="' . esc_attr( isset( $atts['to'] ) ? $atts['to'] : '' ) . '">';
		$hidden .= '<input type="hidden" name="redirect" value="' . esc_attr( isset( $atts['redirect'] ) ? $atts['redirect'] : '' ) . '">';
		// What this form is FOR. Carried in the markup rather than looked up at
		// submit time because the source is the single place the owner set it,
		// and a lookup would need to know which page the post came from.
		$hidden .= '<input type="hidden" name="form_type" value="' . esc_attr( isset( $atts['type'] ) ? $atts['type'] : 'contact' ) . '">';
		$hidden .= '<input type="hidden" name="list_id" value="' . esc_attr( isset( $atts['list'] ) ? $atts['list'] : '' ) . '">';
		// Honeypot: real visitors never see or fill this field.
		$hidden .= '<input type="text" name="cve_hp" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden" aria-hidden="true">';
		// Time-trap: a signed page-render timestamp. handle_submit() rejects a
		// submission that arrives implausibly fast (a bot posting instantly).
		// Signed so a bot can't forge an old timestamp to look human.
		$hidden .= Clara_VE_Forms::timestamp_field();

		$form_html = preg_replace( '/(<form\b[^>]*>)/i', '$1' . $hidden, $form_html, 1 );

		// The consent note, when the owner has switched it on. Appended INSIDE
		// the form, after the designed fields, so it sits at the point of
		// consent without the design having to make room for it in advance.
		// Coming back from a no-JS submit (see Clara_VE_Forms::respond): say so.
		// With JavaScript this never renders — the page was never left, and the
		// script has already written the same sentence.
		if ( isset( $_GET['cve_sent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$form_html .= '<p class="cve-form-message" role="status">' . esc_html__( 'Thanks — check your inbox.', 'visual-edit-lite' ) . '</p>';
		}

		if ( Clara_VE_Form_Settings::consent_enabled() ) {
			// AFTER the form, not inside it. A designed form is usually a flex
			// row (input + button) with no wrapping, so an injected <p> becomes
			// a third column and shreds the layout no matter what width it
			// asks for — which is what the first attempt did. Outside, it is a
			// paragraph in normal flow and nothing the form does internally can
			// reach it. It still sits directly under the submit control, which
			// is what "at the point of consent" means. Styling lives in
			// assets/forms.css so a theme can overrule it.
			$form_html .= '<p class="cve-consent">' . wp_kses_post( Clara_VE_Form_Settings::consent_text() ) . '</p>';
		}

		return $form_html;
	}

	/**
	 * @param array  $atts     location, submenu-template.
	 * @param string $template Per-item markup with {title} {url} {submenu} placeholders.
	 * @return string
	 */
	private static function render_menu( $atts, $template ) {
		$location  = isset( $atts['location'] ) ? sanitize_key( $atts['location'] ) : '';
		$locations = get_nav_menu_locations();
		if ( '' === $location || empty( $locations[ $location ] ) ) {
			return '';
		}
		$items = wp_get_nav_menu_items( $locations[ $location ] );
		if ( ! $items ) {
			return '';
		}

		$children = array();
		$top      = array();
		foreach ( $items as $item ) {
			$parent = (int) $item->menu_item_parent;
			if ( $parent ) {
				$children[ $parent ][] = $item;
			} else {
				$top[] = $item;
			}
		}

		$submenu_template = isset( $atts['submenu-template'] ) ? $atts['submenu-template'] : '<a href="{url}">{title}</a>';

		$out = '';
		foreach ( $top as $item ) {
			$sub = '';
			if ( ! empty( $children[ $item->ID ] ) ) {
				foreach ( $children[ $item->ID ] as $child ) {
					$sub .= strtr( $submenu_template, array( '{title}' => esc_html( $child->title ), '{url}' => esc_url( $child->url ) ) );
				}
			}
			$out .= strtr( $template, array( '{title}' => esc_html( $item->title ), '{url}' => esc_url( $item->url ), '{submenu}' => $sub ) );
		}
		return $out;
	}
}

Clara_VE_Tokens::init();
