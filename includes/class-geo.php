<?php
/**
 * GEO: making the site legible to answer engines as well as to search engines.
 *
 * Everything here is DETERMINISTIC. It reads what the site already says — its
 * pages, its posts, its footer links, the schema.org block its designer wrote —
 * and states it again in a machine-readable form. Nothing is generated, guessed
 * or worded by a model. The rule the whole feature is built on:
 *
 *     EXTRACT, NEVER AUTHOR.
 *
 * Anything that reads existing structure belongs here. Anything that would write
 * a new sentence does not, at any price — a deterministic attempt at prose
 * produces output embarrassing enough to discredit the parts that work.
 *
 * Three things it produces, all locally, all offline:
 *
 *   1. An entity @graph — who this site is, what each page is, how they relate.
 *   2. llms.txt — the page list with titles and descriptions, in plain text.
 *   3. AI-crawler rules in robots.txt, from a list shipped with the plugin.
 *
 * On co-existing with an SEO plugin: two @graph blocks on one page do not error,
 * but duplicate Organization and WebPage entities carrying different @ids create
 * exactly the ambiguity structured data exists to remove, and can cost
 * rich-result eligibility. So when Yoast or Rank Math is present we do not emit
 * a graph at all — we add to theirs, through the extension points they publish
 * for it. Only when neither is installed do we emit our own.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_GEO {

	/** Whether AI-crawler rules are added to robots.txt. One switch, not one per bot. */
	const OPT_AI_CRAWLERS = 'clara_ve_geo_ai_crawlers';

	/** Cache of the extracted FAQ per page, keyed by a hash of its source. */
	const FAQ_CACHE_PREFIX = 'clara_ve_geo_faq_';

	/**
	 * How many entries of each type llms.txt lists. A bound, because the file is
	 * meant to be read in one go — but a bound the file announces rather than
	 * one it hides. See llms_txt().
	 */
	const LLMS_MAX = 200;

	/**
	 * Answer-engine crawlers, as a snapshot shipped with this release.
	 *
	 * A list, not a lookup: what makes it worth maintaining is that it changes —
	 * new agents appear every few months — which is why keeping it current is
	 * the paid tier's job and shipping a usable snapshot is this one's. A site
	 * whose subscription lapses keeps whatever it last synced; a site that never
	 * subscribes still gets this.
	 */
	const AI_CRAWLERS = array(
		'GPTBot',
		'ChatGPT-User',
		'OAI-SearchBot',
		'ClaudeBot',
		'Claude-User',
		'Claude-SearchBot',
		'PerplexityBot',
		'Perplexity-User',
		'Google-Extended',
		'Applebot-Extended',
		'CCBot',
		'Bytespider',
		'meta-externalagent',
	);

	public static function init() {
		// llms.txt is served through the rewrite API rather than a real file, so
		// it cannot drift out of date with the site's actual pages.
		add_action( 'init', array( __CLASS__, 'add_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		// Priority 1, ahead of core's redirect_canonical on the same hook, and
		// the canonical filter below as a second line of defence. Without either,
		// WordPress "corrects" /llms.txt to /llms.txt/ with a 301 — it serves the
		// right content there, but a file URL that only works with a trailing
		// slash is broken for every consumer that fetches it, including the
		// robots.txt line that advertises it.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve_llms_txt' ), 1 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'skip_canonical_redirect' ) );

		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 2 );

		// A page the owner has hidden from search has no business in the sitemap
		// either. Only relevant when no SEO plugin is installed — both of them
		// replace core's sitemap with their own.
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'filter_sitemap_query' ), 10, 2 );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'filter_sitemap_provider' ), 10, 2 );

		// Emission, in the three mutually exclusive ways. Which one runs is
		// decided per request in emit(), not here, because plugin load order
		// makes a decision taken now unreliable — see Clara_VE_SEO::init().
		add_action( 'wp_head', array( __CLASS__, 'emit_standalone' ), 2 );
		add_filter( 'wpseo_schema_graph', array( __CLASS__, 'extend_yoast_graph' ), 10, 1 );
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'extend_rankmath_graph' ), 10, 1 );
	}

	// ---------------------------------------------------------------- entity

	/**
	 * The site-wide entity, as stored.
	 *
	 * Seeded at import from the schema.org block the original page already
	 * carried, which is why the type is not guessed: choosing between
	 * Organization, LocalBusiness and ProfessionalService is a judgement, and on
	 * a converted site somebody has usually already made it by hand. Where they
	 * have, that is extraction. Where they have not, the answer is the safe
	 * general one and the owner can change it — never a guess dressed up as a
	 * fact.
	 *
	 * @return array
	 */
	public static function entity() {
		$name = (string) get_option( Clara_VE_SEO::OPT_ENTITY_NAME, '' );
		return array(
			'type'    => (string) get_option( Clara_VE_SEO::OPT_ENTITY_TYPE, 'Organization' ) ?: 'Organization',
			'name'    => '' !== $name ? $name : (string) get_bloginfo( 'name' ),
			'logo'    => (string) get_option( Clara_VE_SEO::OPT_ENTITY_LOGO, '' ),
			'same_as' => array_values( array_filter( (array) get_option( Clara_VE_SEO::OPT_SAME_AS, array() ) ) ),
			'extra'   => (array) get_option( 'clara_ve_seo_entity_extra', array() ),
		);
	}

	/**
	 * Adopt the schema.org block a converted page brought with it.
	 *
	 * Called once per import for the front page. Only fills what is empty, like
	 * every other import write, so re-running a conversion cannot undo the
	 * owner's corrections.
	 *
	 * @param array $blocks Decoded JSON-LD blocks from the original <head>.
	 * @return bool Whether anything was adopted.
	 */
	public static function seed_from_jsonld( $blocks ) {
		if ( ! is_array( $blocks ) ) {
			return false;
		}
		// Fields that describe the BUSINESS rather than the page, and that
		// schema.org spells the same way wherever they appear. Anything not
		// listed is left in the page's own block rather than promoted to a
		// site-wide fact we would then have to keep correct.
		$carry = array( 'email', 'telephone', 'areaServed', 'address', 'priceRange', 'founder', 'foundingDate' );

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['@type'] ) || is_array( $block['@type'] ) ) {
				continue;
			}
			$type = (string) $block['@type'];
			if ( in_array( $type, array( 'WebSite', 'WebPage', 'BreadcrumbList', 'Article', 'FAQPage' ), true ) ) {
				continue; // per-page furniture, not the entity
			}

			if ( '' === (string) get_option( Clara_VE_SEO::OPT_ENTITY_TYPE, '' ) ) {
				update_option( Clara_VE_SEO::OPT_ENTITY_TYPE, sanitize_text_field( $type ) );
			}
			if ( '' === (string) get_option( Clara_VE_SEO::OPT_ENTITY_NAME, '' ) && ! empty( $block['name'] ) ) {
				update_option( Clara_VE_SEO::OPT_ENTITY_NAME, sanitize_text_field( (string) $block['name'] ) );
			}
			if ( ! get_option( Clara_VE_SEO::OPT_SAME_AS, array() ) && ! empty( $block['sameAs'] ) ) {
				$same = array();
				foreach ( (array) $block['sameAs'] as $url ) {
					$url = esc_url_raw( (string) $url );
					if ( '' !== $url ) {
						$same[] = $url;
					}
				}
				if ( $same ) {
					update_option( Clara_VE_SEO::OPT_SAME_AS, $same );
				}
			}

			$extra = array();
			foreach ( $carry as $field ) {
				if ( isset( $block[ $field ] ) && ( is_scalar( $block[ $field ] ) || is_array( $block[ $field ] ) ) ) {
					// Arrays sanitized recursively, not stored as they arrived.
					// A bundle is a file from somewhere else, and these values
					// are re-emitted not only by our own encoder (which escapes
					// "</" itself) but merged into a host SEO plugin's @graph,
					// whose serialisation flags are theirs, not ours — so no
					// markup may survive storage, at any depth.
					$extra[ $field ] = is_scalar( $block[ $field ] )
						? sanitize_text_field( (string) $block[ $field ] )
						: self::sanitize_tree( $block[ $field ], 4 );
				}
			}
			if ( $extra && ! get_option( 'clara_ve_seo_entity_extra', array() ) ) {
				update_option( 'clara_ve_seo_entity_extra', $extra );
			}
			return true;
		}
		return false;
	}

	/**
	 * Strip markup from every string in a nested structure, bounded.
	 *
	 * @param mixed $value
	 * @param int   $depth Levels remaining; anything deeper is dropped.
	 * @return mixed
	 */
	private static function sanitize_tree( $value, $depth ) {
		if ( is_scalar( $value ) ) {
			return sanitize_text_field( (string) $value );
		}
		if ( ! is_array( $value ) || $depth <= 0 ) {
			return '';
		}
		$out = array();
		$n   = 0;
		foreach ( $value as $key => $item ) {
			if ( ++$n > 20 ) {
				break; // a postal address has a handful of fields, not hundreds
			}
			// schema.org keys legitimately start with "@" (@type, @id), which
			// sanitize_key() would eat — so allow exactly that plus word chars.
			$key = preg_replace( '/[^a-zA-Z0-9_@:.\-]/', '', (string) $key );
			if ( '' === $key ) {
				continue;
			}
			$clean = self::sanitize_tree( $item, $depth - 1 );
			if ( '' !== $clean || is_array( $clean ) ) {
				$out[ $key ] = $clean;
			}
		}
		return $out;
	}

	// ----------------------------------------------------------------- graph

	/** @return string The @id of the site-wide entity. */
	private static function entity_id() {
		return home_url( '/' ) . '#/schema/organization';
	}

	/**
	 * The entity node, in the shape both our own graph and a host plugin's can
	 * take.
	 *
	 * @return array
	 */
	public static function entity_node() {
		$entity = self::entity();
		$node   = array(
			'@type' => $entity['type'],
			'@id'   => self::entity_id(),
			'name'  => $entity['name'],
			'url'   => home_url( '/' ),
		);
		if ( $entity['same_as'] ) {
			$node['sameAs'] = $entity['same_as'];
		}
		if ( '' !== $entity['logo'] ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'@id'   => home_url( '/' ) . '#/schema/logo',
				'url'   => $entity['logo'],
			);
			$node['image'] = array( '@id' => $node['logo']['@id'] );
		}
		foreach ( $entity['extra'] as $field => $value ) {
			$node[ $field ] = $value;
		}
		return $node;
	}

	/**
	 * Our own complete graph, for a site with no SEO plugin.
	 *
	 * @return array
	 */
	public static function graph() {
		$post_id   = Clara_VE_SEO::current_post_id();
		$seo       = Clara_VE_SEO::get( $post_id );
		$permalink = $post_id ? (string) get_permalink( $post_id ) : home_url( '/' );

		$language = str_replace( '_', '-', (string) get_bloginfo( 'language' ) );

		$website = array(
			'@type'     => 'WebSite',
			'@id'       => home_url( '/' ) . '#/schema/website',
			'url'       => home_url( '/' ),
			'name'      => (string) get_bloginfo( 'name' ),
			'publisher' => array( '@id' => self::entity_id() ),
			// The site's own search, declared so an assistant can use it rather
			// than guess at a URL pattern.
			'potentialAction' => array(
				array(
					'@type'       => 'SearchAction',
					'target'      => array(
						'@type'       => 'EntryPoint',
						'urlTemplate' => home_url( '/' ) . '?s={search_term_string}',
					),
					'query-input' => 'required name=search_term_string',
				),
			),
			'inLanguage' => $language,
		);
		$tagline = (string) get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$website['description'] = $tagline;
		}

		// Organization and WebSite are true of every request. A WebPage node is
		// not: it needs a page to describe, and describing one we have no record
		// of is how a graph starts asserting things that are not so.
		//
		// This was not hypothetical. Before the front page had its anchor, this
		// ran with $post_id = 0 on a homepage showing latest posts — where
		// get_the_title( 0 ) does not return "nothing", it returns the title of
		// whatever post the loop is currently on. The homepage therefore
		// published itself to Google as "Show the Work, Not the Trophy", the
		// title of a blog post. Nothing in a unit test can catch that; only a
		// real WordPress loop can.
		$graph = array( self::entity_node(), $website );
		if ( ! $post_id ) {
			return $graph;
		}

		$webpage = array(
			'@type'    => 'WebPage',
			'@id'      => $permalink . '#/schema/webpage',
			'url'      => $permalink,
			'name'     => '' !== $seo['title'] ? $seo['title'] : wp_strip_all_tags( (string) get_the_title( $post_id ) ),
			'isPartOf' => array( '@id' => $website['@id'] ),
			'about'    => array( '@id' => self::entity_id() ),
			'inLanguage' => $language,
		);
		if ( '' !== $seo['description'] ) {
			$webpage['description'] = $seo['description'];
		}

		$crumbs = self::breadcrumb_node( $post_id, $permalink );
		if ( $crumbs ) {
			$webpage['breadcrumb'] = array( '@id' => $crumbs['@id'] );
		}

		$graph[] = $webpage;
		if ( $crumbs ) {
			$graph[] = $crumbs;
		}

		if ( is_singular( 'post' ) ) {
			$graph[] = self::article_node( $post_id, $permalink, $webpage['@id'] );
		}

		$faq = self::faq_node( $permalink );
		if ( $faq ) {
			$graph[] = $faq;
		}

		return $graph;
	}

	/**
	 * A BreadcrumbList from the page's real ancestry. Nothing is rendered — the
	 * design has no breadcrumb and injecting one would break the 1:1 promise;
	 * this only states the hierarchy that already exists.
	 *
	 * @param int    $post_id
	 * @param string $permalink
	 * @return array|null Null on the front page, which is the root and has none.
	 */
	private static function breadcrumb_node( $post_id, $permalink ) {
		if ( ! $post_id || ( is_front_page() && ! is_paged() ) ) {
			return null;
		}
		$trail = array(
			array(
				'@type'    => 'ListItem',
				'position' => 1,
				'name'     => (string) get_bloginfo( 'name' ),
				'item'     => home_url( '/' ),
			),
		);
		foreach ( array_reverse( get_post_ancestors( $post_id ) ) as $ancestor ) {
			$trail[] = array(
				'@type'    => 'ListItem',
				'position' => count( $trail ) + 1,
				'name'     => wp_strip_all_tags( (string) get_the_title( $ancestor ) ),
				'item'     => (string) get_permalink( $ancestor ),
			);
		}
		// The current page carries no `item`, per schema.org's guidance that the
		// last crumb is the page you are already on.
		$trail[] = array(
			'@type'    => 'ListItem',
			'position' => count( $trail ) + 1,
			'name'     => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
		);

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $permalink . '#/schema/breadcrumb',
			'itemListElement' => $trail,
		);
	}

	/**
	 * An Article for a real post — all of it straight out of WordPress's own
	 * fields, which is why blog posts get the richest markup on the site.
	 *
	 * @param int    $post_id
	 * @param string $permalink
	 * @param string $webpage_id
	 * @return array
	 */
	private static function article_node( $post_id, $permalink, $webpage_id ) {
		$node = array(
			'@type'            => 'Article',
			'@id'              => $permalink . '#/schema/article',
			'headline'         => wp_strip_all_tags( (string) get_the_title( $post_id ) ),
			'datePublished'    => (string) get_post_time( 'c', true, $post_id ),
			'dateModified'     => (string) get_post_modified_time( 'c', true, $post_id ),
			'mainEntityOfPage' => array( '@id' => $webpage_id ),
			'isPartOf'         => array( '@id' => $webpage_id ),
			'publisher'        => array( '@id' => self::entity_id() ),
		);

		$excerpt = wp_strip_all_tags( (string) get_the_excerpt( $post_id ) );
		if ( '' !== $excerpt ) {
			$node['description'] = $excerpt;
		}
		$author = (int) get_post_field( 'post_author', $post_id );
		if ( $author ) {
			$node['author'] = array(
				'@type' => 'Person',
				'name'  => (string) get_the_author_meta( 'display_name', $author ),
			);
		}
		$image = get_the_post_thumbnail_url( $post_id, 'full' );
		if ( $image ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $image,
			);
		}
		$terms = get_the_terms( $post_id, 'category' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$node['articleSection'] = wp_list_pluck( $terms, 'name' );
		}
		return $node;
	}

	// ------------------------------------------------------------------- FAQ

	/**
	 * A FAQPage, but ONLY when the page already contains question-and-answer
	 * markup. Extracted, never written.
	 *
	 * This is where the "extract, never author" rule earns its keep. Turning
	 * prose into plausible FAQ entries needs a model and it is exactly the kind
	 * of invention that gets structured data flagged as spam, so a page with no
	 * Q&A structure simply gets no FAQPage. Recognised shapes are the three that
	 * real designs use: <details><summary>, a <dl> of <dt>/<dd>, and a heading
	 * ending in a question mark followed by prose.
	 *
	 * @param string $permalink
	 * @return array|null
	 */
	private static function faq_node( $permalink ) {
		// A single post's questions are in the POST, not in the layout wrapped
		// around it. clara_ve_current_key() answers "article" for every post,
		// because that is the shared template the visual editor edits — so
		// reading the source for that key here was wrong twice over: a post whose
		// body genuinely has a FAQ never got one, and a single question mark left
		// in the template would have published the same FAQ on every article on
		// the site.
		if ( is_singular( 'post' ) ) {
			$post   = get_post();
			$source = $post ? (string) $post->post_content : '';
			$scope  = 'post-' . ( $post ? (int) $post->ID : 0 );
		} else {
			$scope  = (string) clara_ve_current_key();
			$source = '' === $scope ? '' : (string) Clara_VE_Source_Store::get_current_source( $scope );
		}
		if ( '' === $source ) {
			return null;
		}

		// Cached so the parse happens once per edit rather than once per visitor,
		// and self-invalidating: the stored hash of the source is compared with
		// the current one, so a page that changed is simply re-read.
		//
		// Keyed by PAGE, with the hash inside the value — not by a hash of the
		// source, which is where this started. That version was also
		// self-healing, but every edit minted a brand new key and left the old
		// row sitting in wp_options for a week: one session of editing this site
		// left forty of them. One row per page cannot grow.
		$cache_key = self::FAQ_CACHE_PREFIX . md5( $scope );
		$hash      = md5( $source );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) && isset( $cached['hash'], $cached['pairs'] ) && $cached['hash'] === $hash ) {
			$pairs = (array) $cached['pairs'];
		} else {
			$pairs = self::extract_qa( $source );
			set_transient(
				$cache_key,
				array(
					'hash'  => $hash,
					'pairs' => $pairs,
				),
				WEEK_IN_SECONDS
			);
		}
		// Two is the threshold, and it comes from real content. On this site's
		// front page the heuristic found five genuine questions ("Who do you work
		// with?", "What does it cost to work together?") — a real FAQ section. On
		// the about page it found exactly one: "Ready for a clearer strategy?",
		// which is a call to action that happens to end in a question mark. The
		// text was extracted correctly, but publishing it as a FAQPage tells
		// Google the page answers a question it does not answer, and one bad
		// entity is worse than no entity.
		//
		// A section with a single question is not a FAQ in any case, so requiring
		// two costs nothing real and removes the whole class of false positive
		// without needing to judge what a heading MEANS — which is the line this
		// feature does not cross.
		if ( count( $pairs ) < 2 ) {
			return null;
		}

		$items = array();
		foreach ( $pairs as $pair ) {
			$items[] = array(
				'@type'          => 'Question',
				'name'           => $pair['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $pair['a'],
				),
			);
		}
		return array(
			'@type'      => 'FAQPage',
			'@id'        => $permalink . '#/schema/faq',
			'mainEntity' => $items,
		);
	}

	/**
	 * Question/answer pairs present in a fragment of HTML.
	 *
	 * @param string $html
	 * @return array<int,array{q:string,a:string}>
	 */
	public static function extract_qa( $html ) {
		$out = array();

		// <details><summary>Question</summary> answer </details>
		if ( preg_match_all( '#<details\b[^>]*>(.*?)</details>#is', $html, $blocks ) ) {
			foreach ( $blocks[1] as $block ) {
				if ( ! preg_match( '#<summary\b[^>]*>(.*?)</summary>#is', $block, $q ) ) {
					continue;
				}
				$answer = trim( wp_strip_all_tags( preg_replace( '#<summary\b[^>]*>.*?</summary>#is', '', $block ) ) );
				self::push_qa( $out, $q[1], $answer );
			}
		}

		// <dl><dt>Question</dt><dd>Answer</dd></dl>
		if ( preg_match_all( '#<dt\b[^>]*>(.*?)</dt>\s*<dd\b[^>]*>(.*?)</dd>#is', $html, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				self::push_qa( $out, $pair[1], $pair[2] );
			}
		}

		// <h3>Question?</h3><p>Answer</p> — the question mark is the signal, and
		// it is what keeps ordinary headings out of the FAQ.
		if ( preg_match_all( '#<h([2-4])\b[^>]*>([^<]*\?)\s*</h\1>\s*((?:<p\b[^>]*>.*?</p>\s*){1,2})#is', $html, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				self::push_qa( $out, $pair[2], $pair[3] );
			}
		}

		return $out;
	}

	/**
	 * @param array  $out
	 * @param string $question
	 * @param string $answer
	 */
	private static function push_qa( &$out, $question, $answer ) {
		$question = trim( html_entity_decode( wp_strip_all_tags( (string) $question ), ENT_QUOTES, 'UTF-8' ) );
		$answer   = trim( html_entity_decode( wp_strip_all_tags( (string) $answer ), ENT_QUOTES, 'UTF-8' ) );
		$answer   = trim( preg_replace( '/\s+/', ' ', $answer ) );
		$question = trim( preg_replace( '/\s+/', ' ', $question ) );
		if ( '' === $question || '' === $answer ) {
			return;
		}
		// A "question" with no answer text, or an answer of two words, is markup
		// that happens to match the pattern rather than a real FAQ.
		if ( strlen( $answer ) < 20 ) {
			return;
		}
		foreach ( $out as $existing ) {
			if ( $existing['q'] === $question ) {
				return;
			}
		}
		$out[] = array(
			'q' => $question,
			'a' => $answer,
		);
	}

	// -------------------------------------------------------------- emission

	/**
	 * Our own graph, printed only when no SEO plugin owns the head.
	 */
	public static function emit_standalone() {
		if ( '' !== Clara_VE_SEO::host() ) {
			return;
		}
		// A page that does not exist should not describe itself as one, and a
		// search result page is not a thing worth an entry in a knowledge graph.
		if ( is_404() || is_search() ) {
			return;
		}
		$graph = self::graph();
		if ( ! $graph ) {
			return;
		}
		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
		if ( ! $json ) {
			return;
		}
		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			str_replace( '</', '<\/', $json ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		);
	}

	/**
	 * Add to Yoast's graph instead of publishing a competing one.
	 *
	 * Yoast already emits Organization, WebSite, WebPage, BreadcrumbList and
	 * Article, and does them well; what its free version does not carry is the
	 * detail that makes a service business identifiable — sameAs profiles, a
	 * contact address, the area it serves, a more specific @type. (That is what
	 * its paid Local add-on sells.) So this enriches the node Yoast already
	 * built, referencing its @id rather than minting a second organization, and
	 * appends only the pieces Yoast has none of.
	 *
	 * @param array $graph
	 * @return array
	 */
	public static function extend_yoast_graph( $graph ) {
		if ( ! is_array( $graph ) ) {
			return $graph;
		}
		$entity = self::entity();
		$extra  = $entity['extra'];
		$found  = false;

		foreach ( $graph as $i => $piece ) {
			$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
			if ( ! array_intersect( $types, array( 'Organization', 'Corporation', 'LocalBusiness', 'ProfessionalService', 'Person' ) ) ) {
				continue;
			}
			$found = true;
			// A more specific type is additive in schema.org: keeping Yoast's
			// alongside ours stays valid and stays compatible with anything
			// already consuming it.
			if ( 'Organization' !== $entity['type'] && ! in_array( $entity['type'], $types, true ) ) {
				$types[]                 = $entity['type'];
				$graph[ $i ]['@type']    = array_values( array_unique( $types ) );
			}
			foreach ( $extra as $field => $value ) {
				if ( ! isset( $graph[ $i ][ $field ] ) ) {
					$graph[ $i ][ $field ] = $value;
				}
			}
			if ( $entity['same_as'] ) {
				$existing              = isset( $graph[ $i ]['sameAs'] ) ? (array) $graph[ $i ]['sameAs'] : array();
				$graph[ $i ]['sameAs'] = array_values( array_unique( array_merge( $existing, $entity['same_as'] ) ) );
			}
			break; // one entity per site, and Yoast emits one node for it
		}

		// Yoast emits NO Organization or Person node until its own "site
		// representation" is filled in — and on a freshly installed Yoast it is
		// not: verified on a real site where company_or_person was "company"
		// while company_name was empty, so the graph contained WebPage, WebSite
		// and BreadcrumbList and no entity at all. Left alone, installing Yoast
		// would silently DELETE the identity the conversion had carried over from
		// the original design: the business name, its type, its contact address,
		// its social profiles.
		//
		// So when there is no entity to enrich, contribute one — and wire it into
		// the references Yoast left empty, so the result is still a single
		// coherent graph rather than an orphaned node beside one. There is no
		// duplication risk here precisely because we only do this when Yoast has
		// published none.
		if ( ! $found ) {
			$node    = self::entity_node();
			$graph[] = $node;
			foreach ( $graph as $i => $piece ) {
				$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
				if ( in_array( 'WebSite', $types, true ) && empty( $piece['publisher'] ) ) {
					$graph[ $i ]['publisher'] = array( '@id' => $node['@id'] );
				}
				if ( in_array( 'WebPage', $types, true ) && empty( $piece['about'] ) ) {
					$graph[ $i ]['about'] = array( '@id' => $node['@id'] );
				}
			}
		}

		$post_id = Clara_VE_SEO::current_post_id();
		$faq     = self::faq_node( $post_id ? (string) get_permalink( $post_id ) : home_url( '/' ) );
		if ( $faq ) {
			$graph[] = $faq;
		}
		return $graph;
	}

	/**
	 * The same idea through Rank Math's filter, whose payload is keyed rather
	 * than a list.
	 *
	 * @param array $data
	 * @return array
	 */
	public static function extend_rankmath_graph( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$entity = self::entity();
		foreach ( $data as $slug => $piece ) {
			if ( ! is_array( $piece ) || empty( $piece['@type'] ) ) {
				continue;
			}
			$types = (array) $piece['@type'];
			if ( ! array_intersect( $types, array( 'Organization', 'LocalBusiness', 'ProfessionalService', 'Person' ) ) ) {
				continue;
			}
			foreach ( $entity['extra'] as $field => $value ) {
				if ( ! isset( $data[ $slug ][ $field ] ) ) {
					$data[ $slug ][ $field ] = $value;
				}
			}
			if ( $entity['same_as'] ) {
				$existing                 = isset( $data[ $slug ]['sameAs'] ) ? (array) $data[ $slug ]['sameAs'] : array();
				$data[ $slug ]['sameAs'] = array_values( array_unique( array_merge( $existing, $entity['same_as'] ) ) );
			}
			break;
		}

		$post_id = Clara_VE_SEO::current_post_id();
		$faq     = self::faq_node( $post_id ? (string) get_permalink( $post_id ) : home_url( '/' ) );
		if ( $faq ) {
			$data['clara-faq'] = $faq;
		}
		return $data;
	}

	// -------------------------------------------------------------- llms.txt

	public static function add_rewrite() {
		add_rewrite_rule( '^llms\.txt$', 'index.php?clara_ve_llms=1', 'top' );
	}

	/**
	 * @param string[] $vars
	 * @return string[]
	 */
	public static function add_query_var( $vars ) {
		$vars[] = 'clara_ve_llms';
		return $vars;
	}

	/**
	 * Serve llms.txt: the site described in plain text, for a reader that wants
	 * the substance without the markup.
	 *
	 * Generated on request from the same records the pages themselves use, so it
	 * cannot describe a site that no longer exists — which a checked-in static
	 * file eventually always does.
	 */
	/**
	 * Keep WordPress from "correcting" /llms.txt into /llms.txt/.
	 *
	 * @param string|false $redirect
	 * @return string|false
	 */
	public static function skip_canonical_redirect( $redirect ) {
		return get_query_var( 'clara_ve_llms' ) ? false : $redirect;
	}

	public static function maybe_serve_llms_txt() {
		if ( ! get_query_var( 'clara_ve_llms' ) ) {
			return;
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo self::llms_txt(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text, assembled below
		exit;
	}

	/**
	 * @return string
	 */
	public static function llms_txt() {
		$entity = self::entity();
		$lines  = array( '# ' . $entity['name'] );

		$front   = Clara_VE_Source_Store::front_anchor_id();
		$tagline = $front ? Clara_VE_SEO::get( $front )['description'] : '';
		if ( '' === $tagline ) {
			$tagline = (string) get_bloginfo( 'description' );
		}
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		$sections = array(
			'## Pages' => 'page',
			'## Posts' => 'post',
		);
		foreach ( $sections as $heading => $type ) {
			$rows = self::llms_rows( $type );
			if ( ! $rows ) {
				continue;
			}
			$lines[] = '';
			$lines[] = $heading;
			$lines[] = '';
			foreach ( $rows as $row ) {
				$lines[] = $row;
			}

			// Say so when the list stops short. A file that silently ends at the
			// limit reads as a complete description of the site — which is the
			// one thing this file exists to be, so a quiet truncation is worse
			// here than almost anywhere else.
			//
			// Tested against the CAP, not against the published total: rows are
			// also dropped for pages the owner hid from search, so comparing the
			// two counts announced a truncation on any site with a single hidden
			// thank-you page. Only a list that came back exactly full was cut.
			$total = self::published_count( $type );
			if ( count( $rows ) >= self::LLMS_MAX && $total > count( $rows ) ) {
				$lines[] = '';
				$lines[] = sprintf(
					'> Listing the %1$d most recent of %2$d. See %3$s for the rest.',
					count( $rows ),
					$total,
					home_url( '/wp-sitemap.xml' )
				);
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * @param string $post_type
	 * @return string[]
	 */
	private static function llms_rows( $post_type ) {
		$posts = get_posts(
			array(
				'post_type'   => $post_type,
				'post_status' => 'publish',
				'numberposts' => self::LLMS_MAX,
				// Newest first when the list is capped, so what survives the cut
				// is the current material rather than whatever happens to sort
				// first alphabetically.
				'orderby'       => 'post' === $post_type ? 'date' : 'title',
				'order'         => 'post' === $post_type ? 'DESC' : 'ASC',
				'no_found_rows' => true,
			)
		);

		$front = Clara_VE_Source_Store::front_anchor_id();
		$rows  = array();
		foreach ( $posts as $post ) {
			$seo = Clara_VE_SEO::get( $post->ID );
			// A page hidden from search is hidden here too — the two lists should
			// never disagree about what the site is offering.
			if ( ! empty( $seo['noindex'] ) ) {
				continue;
			}
			$title = wp_strip_all_tags( (string) get_the_title( $post ) );
			$url   = ( $front && (int) $post->ID === $front ) ? home_url( '/' ) : (string) get_permalink( $post );
			$desc  = '' !== $seo['description'] ? $seo['description'] : wp_strip_all_tags( (string) get_the_excerpt( $post ) );

			$row = '- [' . $title . '](' . $url . ')';
			if ( '' !== $desc ) {
				$row .= ': ' . trim( preg_replace( '/\s+/', ' ', $desc ) );
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Published items of a type, for deciding whether the list above was cut
	 * short. Counted rather than fetched — the rows themselves are already
	 * capped, so asking the database to hand back thousands of post objects only
	 * to count them would defeat the point of the cap.
	 *
	 * @param string $post_type
	 * @return int
	 */
	private static function published_count( $post_type ) {
		$counts = wp_count_posts( $post_type );
		return $counts && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	// ------------------------------------------------------------ robots.txt

	/**
	 * Add the AI-crawler rules to robots.txt.
	 *
	 * Allow, not disallow. A converted site's owner wants to be findable — the
	 * value of naming these agents explicitly is that it is a decision on the
	 * record rather than a default nobody chose, and it is the hook the switch
	 * hangs on for an owner who wants the opposite.
	 *
	 * Note for Rank Math: it replaces robots.txt with its own editor and this
	 * filter never runs, so what is added here simply will not appear. Detected
	 * and reported by robots_notice() rather than failing quietly.
	 *
	 * @param string $output
	 * @param bool   $public
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		if ( ! $public || 'off' === get_option( self::OPT_AI_CRAWLERS, 'on' ) ) {
			return $output;
		}
		$lines = array( '', '# Answer engines and AI assistants.' );
		foreach ( self::AI_CRAWLERS as $agent ) {
			$lines[] = 'User-agent: ' . $agent;
			$lines[] = 'Allow: /';
			$lines[] = '';
		}
		$llms = home_url( '/llms.txt' );
		$lines[] = '# Plain-text summary of this site: ' . $llms;
		return $output . implode( "\n", $lines ) . "\n";
	}

	// ---------------------------------------------------------------- sitemap

	/**
	 * Keep hidden pages out of core's sitemap.
	 *
	 * Only matters when no SEO plugin is installed — both replace core's sitemap
	 * with their own, and both already honour their own noindex setting. Building
	 * a sitemap of our own would be the third implementation of something two
	 * plugins and WordPress itself already ship.
	 *
	 * @param array  $args
	 * @param string $post_type
	 * @return array
	 */
	public static function filter_sitemap_query( $args, $post_type ) {
		if ( '' !== Clara_VE_SEO::host() ) {
			return $args;
		}
		$hidden = self::hidden_post_ids( $post_type );
		if ( $hidden ) {
			$args['post__not_in'] = array_merge(
				isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(),
				$hidden
			);
		}
		return $args;
	}

	/**
	 * Keep out of the sitemap the URLs we tell robots not to index.
	 *
	 * WordPress ships an author sitemap, and filter_robots() sends `noindex` on
	 * author archives — so out of the box a converted site handed Google a list
	 * of addresses and then, on arrival, told it to ignore each one. Verified on
	 * a real install: /author/admin/ was in wp-sitemap-users-1.xml and served
	 * `noindex, follow`. Nothing breaks, but it is a contradiction, it spends
	 * crawl budget on pages nobody wants found, and it is exactly the kind of
	 * mixed signal an SEO audit tool reports against the site.
	 *
	 * Only when nobody else owns the sitemap; Yoast and Rank Math replace core's
	 * entirely and decide this for themselves.
	 *
	 * @param WP_Sitemaps_Provider|false $provider
	 * @param string                     $name
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function filter_sitemap_provider( $provider, $name ) {
		if ( '' !== Clara_VE_SEO::host() ) {
			return $provider;
		}
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Posts of a type carrying our noindex flag.
	 *
	 * Read from the companion flag rather than by unpacking every record,
	 * because noindex lives inside a serialised array that no meta query can
	 * see. Written alongside the record by Clara_VE_SEO::save().
	 *
	 * @param string $post_type
	 * @return int[]
	 */
	private static function hidden_post_ids( $post_type ) {
		$ids = get_posts(
			array(
				'post_type'     => $post_type,
				'post_status'   => 'publish',
				'numberposts'   => 500,
				'fields'        => 'ids',
				'meta_key'      => Clara_VE_SEO::META_NOINDEX, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'    => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows' => true,
			)
		);
		return array_map( 'intval', $ids );
	}
}

Clara_VE_GEO::init();
