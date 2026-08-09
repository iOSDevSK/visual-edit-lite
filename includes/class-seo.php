<?php
/**
 * Per-page SEO: one record, and an adapter that speaks whichever SEO plugin the
 * site turns out to have.
 *
 * The conversion pipeline reads every page's <title>, meta description, Open
 * Graph tags and schema.org blocks out of the original static <head> (see
 * headMeta() in tools/dist-to-bundle.mjs). Before this class existed there was
 * nowhere to put them: the theme is a block theme, so WordPress core emits
 * <head> and nothing in the project wrote a single meta tag into it. Sites
 * shipped with their designer's hand-written descriptions thrown away and an
 * empty SEO plugin waiting to be filled in by hand.
 *
 * The design is deliberately not "be an SEO plugin". It is:
 *
 *   1. Keep ONE record per page, in our own meta key, in our own shape.
 *   2. Mirror it into Yoast's or Rank Math's own fields when one of them is
 *      installed, so their UI, their sitemap and their OG output all see it and
 *      the owner can keep working in the tool they already know.
 *   3. Emit the tags ourselves ONLY when neither is installed, so a converted
 *      site is never bare — and bail completely the moment one appears, so
 *      there is never a duplicate tag on the page.
 *
 * Which means the site owner can install Yoast, or not install Yoast, or
 * install it six months later, and their metadata is correct in all three
 * cases without anybody retyping it.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_SEO {

	/**
	 * Our own record. A single meta row holding the whole array rather than a
	 * field per key: it is written and read as a unit, it exports as a unit
	 * (class-bundle-writer.php), and one row cannot half-update.
	 */
	const META = '_clara_ve_seo';

	/**
	 * A companion flag mirroring the record's `noindex`, purely so it can be
	 * QUERIED. The record itself is one serialised array, which no meta_query
	 * can look inside — and "every page the owner has hidden" is exactly the
	 * question the sitemap filter and llms.txt need to ask. Denormalised on
	 * purpose; written only by save(), so it cannot drift.
	 */
	const META_NOINDEX = '_clara_ve_noindex';

	/** Site-wide identity, exported via Clara_VE_Bundle_Format::OPTIONS. */
	const OPT_ENTITY_TYPE     = 'clara_ve_seo_entity_type';
	const OPT_ENTITY_NAME     = 'clara_ve_seo_entity_name';
	const OPT_ENTITY_LOGO     = 'clara_ve_seo_entity_logo';
	const OPT_SAME_AS         = 'clara_ve_seo_same_as';
	const OPT_DEFAULT_OG      = 'clara_ve_seo_default_og_image';
	const OPT_TITLE_SEPARATOR = 'clara_ve_seo_title_separator';

	/**
	 * Which host plugin the stored records were last mirrored into. Site state,
	 * not a design choice, so it is deliberately NOT in
	 * Clara_VE_Bundle_Format::OPTIONS — carrying it in a bundle would tell the
	 * receiving site it had already synced to a plugin it may not even have.
	 */
	const OPT_SYNCED_HOST = 'clara_ve_seo_synced_host';

	/** Set when a bulk fill leaves Yoast's indexable cache behind. */
	const OPT_REINDEX_DUE = 'clara_ve_seo_reindex_due';

	/**
	 * Where each field lands in the host plugin.
	 *
	 * Kept as data, not as a branch per field, because the two plugins differ
	 * only in their key names — and because adding a third one later should be
	 * a row here rather than a new code path.
	 *
	 * Yoast stores an attachment ID alongside the OG image URL and prefers it;
	 * Rank Math stores the URL and derives the rest. Both are handled in
	 * mirror_image().
	 */
	const HOST_KEYS = array(
		'yoast'    => array(
			'title'           => '_yoast_wpseo_title',
			'description'     => '_yoast_wpseo_metadesc',
			'canonical'       => '_yoast_wpseo_canonical',
			'og_title'        => '_yoast_wpseo_opengraph-title',
			'og_description'  => '_yoast_wpseo_opengraph-description',
			'og_image'        => '_yoast_wpseo_opengraph-image',
			'og_image_id'     => '_yoast_wpseo_opengraph-image-id',
			'tw_title'        => '_yoast_wpseo_twitter-title',
			'tw_description'  => '_yoast_wpseo_twitter-description',
			'tw_image'        => '_yoast_wpseo_twitter-image',
			'noindex'         => '_yoast_wpseo_meta-robots-noindex',
		),
		'rankmath' => array(
			'title'           => 'rank_math_title',
			'description'     => 'rank_math_description',
			'canonical'       => 'rank_math_canonical_url',
			'og_title'        => 'rank_math_facebook_title',
			'og_description'  => 'rank_math_facebook_description',
			'og_image'        => 'rank_math_facebook_image',
			'og_image_id'     => 'rank_math_facebook_image_id',
			'tw_title'        => 'rank_math_twitter_title',
			'tw_description'  => 'rank_math_twitter_description',
			'tw_image'        => 'rank_math_twitter_image',
			'noindex'         => 'rank_math_robots',
		),
	);

	/**
	 * Hook the fallback emitter.
	 *
	 * The hooks are registered unconditionally and each callback checks host()
	 * for itself. Testing host() HERE instead would be a duplicate-tag bug
	 * waiting to happen: this file loads when the plugin loads, and WordPress
	 * loads plugins in path order, so "visual-edit" runs before
	 * "wordpress-seo" and WPSEO_VERSION is not defined yet at this moment even
	 * on a site where Yoast is active. We would conclude "no SEO plugin here",
	 * register our own emitter, and every page would then ship two titles, two
	 * descriptions and two canonicals. Deciding at render time cannot be wrong
	 * about load order.
	 */
	public static function init() {
		// Priority 1, right after core's charset/viewport block: this is the
		// document's own identity and belongs above the asset noise. The theme
		// already occupies priorities 0 and 1 with a js-class script and font
		// preloads (inc/enqueue.php); order between those and this does not
		// matter, only that both still run.
		add_action( 'wp_head', array( __CLASS__, 'emit' ), 1 );
		add_filter( 'pre_get_document_title', array( __CLASS__, 'filter_document_title' ) );
		add_action( 'wp_head', array( __CLASS__, 'drop_duplicate_core_title' ), 0 );
		// Robots goes through core's filter rather than a tag of our own. Since
		// WP 5.7 core itself runs `add_action( 'wp_head', 'wp_robots', 1 )` and
		// prints one <meta name="robots"> built from this filter, so printing a
		// second one in emit() would put two robots tags on the same page —
		// merged by crawlers in practice, but a contradiction waiting to happen
		// and a plain mistake to ship.
		add_filter( 'wp_robots', array( __CLASS__, 'filter_robots' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_backfill_host' ) );
		add_action( 'admin_notices', array( __CLASS__, 'reindex_notice' ) );
	}

	/**
	 * Copy every stored record into the host plugin's fields when the host has
	 * changed since the last time we did.
	 *
	 * Without this the whole "Yoast arrives pre-filled" promise is empty on the
	 * sites where it matters most. save() mirrors as it writes, which covers
	 * "Yoast was already installed when the content was imported" — but the
	 * ordinary sequence is the reverse: the owner receives a converted site,
	 * uses it for a while, and installs an SEO plugin later because someone told
	 * them to. At that moment Yoast reads its own empty fields, falls back to
	 * "%%title%% %%sep%% %%sitename%%", and the hand-written descriptions the
	 * pipeline went to the trouble of preserving are invisible.
	 *
	 * Driven by comparing state rather than by hooking `activated_plugin`, and
	 * for the reason the theme's own setup wizard documents (clara-setup.php):
	 * an event hook fires once, in one request, and misses every other route in
	 * — WP-CLI activation, a deploy script, a plugin swapped from Yoast to Rank
	 * Math, a restored database. Comparing "which host did we last sync to"
	 * against "which host is loaded now" is self-healing: it does not matter how
	 * or when the plugin arrived.
	 */
	public static function maybe_backfill_host() {
		$host = self::host();
		if ( get_option( self::OPT_SYNCED_HOST, '' ) === $host ) {
			return;
		}
		// Recorded before the work, not after: on a large site this runs inside
		// one admin request, and a timeout partway through must not leave the
		// option unset and the whole sweep repeating on every subsequent page
		// load. Whatever it misses, a later save() picks up.
		update_option( self::OPT_SYNCED_HOST, $host );
		if ( '' === $host ) {
			return; // No host to fill; our own emitter reads the records directly.
		}

		$ids = get_posts(
			array(
				'post_type'     => array( 'page', 'post' ),
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'meta_key'      => self::META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'no_found_rows' => true,
			)
		);
		foreach ( $ids as $id ) {
			self::mirror_to_host( (int) $id, self::get( (int) $id ), true );
		}

		if ( $ids && 'yoast' === $host ) {
			update_option( self::OPT_REINDEX_DUE, count( $ids ) );
		}
	}

	/**
	 * Tell the owner what was filled in, and the two Yoast-side things only they
	 * can decide.
	 *
	 * Yoast serves head output from its own `indexables` table, which is built
	 * when a post is saved — not when its meta is written behind its back. So the
	 * bulk fill above is correct in the database and not yet visible on the
	 * site. Reindexing is Yoast's own documented remedy and it owns that screen,
	 * so this points at it rather than reaching into their internals to
	 * invalidate rows we do not own.
	 *
	 * The content-analysis suggestion is a suggestion on purpose. Yoast's
	 * readability and keyword analysis read a page's post_content, and on a
	 * converted site that is one Custom HTML block — so the advice it produces is
	 * not merely unhelpful, it is about markup rather than prose. Turning it off
	 * removes most of what makes Yoast feel heavy here. But it is a global
	 * setting in a plugin we do not own, on a site that may have had Yoast
	 * configured for years before we arrived, so we say so and leave the switch
	 * to them. Writing another plugin's settings table silently is how a
	 * conversion tool earns a reputation for breaking things.
	 */
	public static function reindex_notice() {
		$due = (int) get_option( self::OPT_REINDEX_DUE, 0 );
		if ( ! $due || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		delete_option( self::OPT_REINDEX_DUE );

		$filled = sprintf(
			/* translators: %d: number of pages and posts */
			_n(
				'Visual Edit filled in the SEO title and description for %d page from the original design.',
				'Visual Edit filled in the SEO titles and descriptions for %d pages and posts from the original design.',
				$due,
				'visual-edit-lite'
			),
			$due
		);

		printf(
			'<div class="notice notice-info is-dismissible"><p>%s</p><p>%s</p></div>',
			esc_html( $filled . ' ' . __( 'Yoast caches what it shows, so run SEO → Tools → Optimize SEO Data once to make them appear.', 'visual-edit-lite' ) ),
			esc_html__( 'Worth knowing: this theme keeps each page as one block of HTML, so Yoast\'s readability and keyword analysis will grade the markup rather than the writing. You can switch both off under SEO → Settings → Site features.', 'visual-edit-lite' )
		);
	}

	/**
	 * Stop a block theme plus an SEO plugin producing two <title> tags.
	 *
	 * WordPress has two title renderers. Classic themes get
	 * `_wp_render_title_tag`; block themes get `_block_template_render_title_tag`,
	 * added separately and doing the same job. Yoast removes the first and not
	 * the second, so on a block theme — which every site this plugin converts
	 * uses — its own title and core's are both printed, one after the other, on
	 * every page.
	 *
	 * Verified on a real install: Yoast 28.1 + WordPress 7.0.2, two titles on
	 * every page, and still two with this plugin deactivated. So it is not ours
	 * to have caused — but it IS ours to fix, because this plugin is the piece
	 * that knows an SEO plugin has taken over the head, and shipping a converted
	 * site with duplicate titles is a defect whoever's fault it is.
	 *
	 * Only when a host is active. With no SEO plugin, core's block renderer is
	 * exactly what prints OUR title (filter_document_title() feeds it), so
	 * removing it then would leave the page with no title at all.
	 */
	public static function drop_duplicate_core_title() {
		if ( '' === self::host() ) {
			return;
		}
		remove_action( 'wp_head', '_block_template_render_title_tag', 1 );
	}

	/**
	 * Which SEO plugin owns the document head: 'yoast', 'rankmath', or '' for
	 * neither.
	 *
	 * Detected by constant rather than by is_plugin_active(): the constants are
	 * defined by the plugins themselves at load, which is the only signal that
	 * is true exactly when their output is actually running — a plugin can be
	 * "active" in the options table while fatally erroring, and
	 * is_plugin_active() is unavailable on the front end without an explicit
	 * include.
	 *
	 * Yoast is checked first for the deliberate reason that if a site somehow
	 * has both, exactly one of us must yield, and picking a fixed winner is
	 * better than picking a different one per request.
	 *
	 * @return string
	 */
	public static function host() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || defined( 'RANK_MATH_FILE' ) ) {
			return 'rankmath';
		}
		return '';
	}

	/**
	 * The empty record. The one place its shape is spelled out for runtime use;
	 * Clara_VE_Bundle_Format::sanitize_seo() is the same shape at the bundle
	 * boundary.
	 *
	 * @return array
	 */
	public static function blank() {
		return array(
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'noindex'     => false,
			'og'          => array(),
			'twitter'     => array(),
			'jsonld'      => array(),
		);
	}

	/**
	 * Our record for a post, normalised.
	 *
	 * @param int $post_id
	 * @return array
	 */
	public static function get( $post_id ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return self::blank();
		}
		$stored = get_post_meta( $post_id, self::META, true );
		return is_array( $stored )
			? array_merge( self::blank(), Clara_VE_Bundle_Format::sanitize_seo( $stored ) )
			: self::blank();
	}

	/**
	 * Write a record and mirror it into the host plugin.
	 *
	 * @param int   $post_id
	 * @param array $seo
	 * @param bool  $only_if_empty Fill blanks only; never replace a value that
	 *                             is already there. What an import uses — the
	 *                             same rule the rest of the importer follows,
	 *                             so re-running it cannot overwrite the owner's
	 *                             own wording.
	 * @return array The record as stored.
	 */
	public static function save( $post_id, $seo, $only_if_empty = false ) {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return self::blank();
		}

		$incoming = array_merge( self::blank(), Clara_VE_Bundle_Format::sanitize_seo( $seo ) );
		$record   = $incoming;

		if ( $only_if_empty ) {
			$existing = self::get( $post_id );
			foreach ( $existing as $field => $value ) {
				$filled = is_array( $value ) ? ! empty( $value ) : ( '' !== $value && false !== $value );
				if ( $filled ) {
					$record[ $field ] = $value;
				}
			}
		}

		update_post_meta( $post_id, self::META, $record );
		if ( ! empty( $record['noindex'] ) ) {
			update_post_meta( $post_id, self::META_NOINDEX, '1' );
		} else {
			delete_post_meta( $post_id, self::META_NOINDEX );
		}
		self::mirror_to_host( $post_id, $record, $only_if_empty );
		return $record;
	}

	/**
	 * Copy a record into the host plugin's own fields, so its UI shows what we
	 * imported and its sitemap/OG output uses it.
	 *
	 * Writing another plugin's meta keys directly is a real coupling, and it is
	 * the right trade here: these key names are part of how every WordPress
	 * migration tool, CSV importer and staging sync already talks to Yoast and
	 * Rank Math, so they are effectively public and do not move between
	 * versions. The alternative — asking the site owner to retype nineteen
	 * descriptions we already have — is not a trade.
	 *
	 * @param int   $post_id
	 * @param array $seo
	 * @param bool  $only_if_empty
	 */
	private static function mirror_to_host( $post_id, $seo, $only_if_empty ) {
		$host = self::host();
		if ( '' === $host || empty( self::HOST_KEYS[ $host ] ) ) {
			return;
		}
		$keys = self::HOST_KEYS[ $host ];

		$og      = isset( $seo['og'] ) ? (array) $seo['og'] : array();
		$twitter = isset( $seo['twitter'] ) ? (array) $seo['twitter'] : array();

		$values = array(
			'title'          => $seo['title'],
			'description'    => $seo['description'],
			'canonical'      => $seo['canonical'],
			'og_title'       => isset( $og['title'] ) ? $og['title'] : '',
			'og_description' => isset( $og['description'] ) ? $og['description'] : '',
			'tw_title'       => isset( $twitter['title'] ) ? $twitter['title'] : '',
			'tw_description' => isset( $twitter['description'] ) ? $twitter['description'] : '',
		);

		foreach ( $values as $field => $value ) {
			if ( '' === $value || empty( $keys[ $field ] ) ) {
				continue;
			}
			if ( $only_if_empty && '' !== (string) get_post_meta( $post_id, $keys[ $field ], true ) ) {
				continue;
			}
			update_post_meta( $post_id, $keys[ $field ], $value );
		}

		self::mirror_image( $post_id, $keys, $og, $twitter, $only_if_empty );

		// noindex is spelled differently in each: Yoast uses a tri-state string
		// ('0' default, '1' noindex, '2' index) on a single key, Rank Math uses
		// an array of robots directives. Only written when asked for, so an
		// absent flag never argues with a setting the owner made in their own UI.
		if ( empty( $seo['noindex'] ) || empty( $keys['noindex'] ) ) {
			return;
		}
		if ( 'yoast' === $host ) {
			update_post_meta( $post_id, $keys['noindex'], '1' );
			return;
		}
		$robots = get_post_meta( $post_id, $keys['noindex'], true );
		$robots = is_array( $robots ) ? $robots : array();
		if ( ! in_array( 'noindex', $robots, true ) ) {
			$robots[] = 'noindex';
			update_post_meta( $post_id, $keys['noindex'], $robots );
		}
	}

	/**
	 * The social image, which both plugins want as a URL and additionally as an
	 * attachment ID when they can get one.
	 *
	 * The ID matters: given only a URL, Yoast cannot read the image's real
	 * dimensions and omits og:image:width/height, which is what makes a
	 * preview render as a small thumbnail instead of a card on several
	 * networks. The bundle's og:image has just been imported into the media
	 * library by the same run, so the lookup normally succeeds.
	 *
	 * @param int   $post_id
	 * @param array $keys
	 * @param array $og
	 * @param array $twitter
	 * @param bool  $only_if_empty
	 */
	private static function mirror_image( $post_id, $keys, $og, $twitter, $only_if_empty ) {
		$pairs = array(
			array( isset( $og['image'] ) ? $og['image'] : '', 'og_image', 'og_image_id' ),
			array( isset( $twitter['image'] ) ? $twitter['image'] : '', 'tw_image', null ),
		);

		foreach ( $pairs as $pair ) {
			list( $url, $url_field, $id_field ) = $pair;
			if ( '' === $url || empty( $keys[ $url_field ] ) ) {
				continue;
			}
			if ( ! $only_if_empty || '' === (string) get_post_meta( $post_id, $keys[ $url_field ], true ) ) {
				update_post_meta( $post_id, $keys[ $url_field ], $url );
			}
			if ( ! $id_field || empty( $keys[ $id_field ] ) ) {
				continue;
			}
			$attachment = attachment_url_to_postid( $url );
			if ( $attachment && ( ! $only_if_empty || ! (int) get_post_meta( $post_id, $keys[ $id_field ], true ) ) ) {
				update_post_meta( $post_id, $keys[ $id_field ], (int) $attachment );
			}
		}
	}

	/**
	 * What is actually live for a post, host plugin included.
	 *
	 * Our record is the base; where a host plugin holds a non-empty value, that
	 * one wins — because its own UI is a second place the owner can type, and a
	 * panel that shows our stale copy instead of what visitors are served is
	 * worse than no panel. Makes the editor's SEO panel a write-through cache
	 * rather than a competing source of truth.
	 *
	 * @param int $post_id
	 * @return array{title:string,description:string,og_image:string,noindex:bool}
	 */
	public static function effective( $post_id ) {
		$ours = self::get( $post_id );
		$og   = (array) $ours['og'];
		$out  = array(
			'title'       => $ours['title'],
			'description' => $ours['description'],
			'og_image'    => isset( $og['image'] ) ? $og['image'] : '',
			'noindex'     => (bool) $ours['noindex'],
		);

		$host = self::host();
		if ( '' === $host || empty( self::HOST_KEYS[ $host ] ) || (int) $post_id <= 0 ) {
			return $out;
		}
		$keys = self::HOST_KEYS[ $host ];
		foreach ( array( 'title' => 'title', 'description' => 'description', 'og_image' => 'og_image' ) as $field => $host_field ) {
			if ( empty( $keys[ $host_field ] ) ) {
				continue;
			}
			$live = (string) get_post_meta( $post_id, $keys[ $host_field ], true );
			if ( '' !== $live ) {
				$out[ $field ] = $live;
			}
		}
		if ( ! empty( $keys['noindex'] ) ) {
			$robots = get_post_meta( $post_id, $keys['noindex'], true );
			$out['noindex'] = 'yoast' === $host
				? ( '1' === (string) $robots )
				: ( is_array( $robots ) && in_array( 'noindex', $robots, true ) );
		}
		return $out;
	}

	/**
	 * Human name of the active host plugin, for telling the owner which other
	 * screen also edits these values.
	 *
	 * @return string '' when there is none.
	 */
	public static function host_label() {
		switch ( self::host() ) {
			case 'yoast':
				return __( 'Yoast SEO', 'visual-edit-lite' );
			case 'rankmath':
				return __( 'Rank Math', 'visual-edit-lite' );
		}
		return '';
	}

	/**
	 * The post whose SEO record applies to the current request, or 0.
	 *
	 * The front page is resolved through page_on_front rather than assumed to
	 * be a singular query, because that is exactly the page whose SEO used to
	 * be unreachable: it renders from a registered pattern, not from post
	 * content, so nothing about the request looks like "a page" until you ask
	 * WordPress which page is the front one.
	 *
	 * @return int
	 */
	public static function current_post_id() {
		if ( is_front_page() ) {
			$front = Clara_VE_Source_Store::front_anchor_id();
			if ( $front > 0 ) {
				return $front;
			}
		}
		if ( is_singular() ) {
			return (int) get_queried_object_id();
		}
		if ( is_home() ) {
			return (int) get_option( 'page_for_posts' );
		}
		return 0;
	}

	/**
	 * The record for a visual-edit key, or the blank one. Chrome keys
	 * (header/footer/article/404) have no page and therefore no SEO — they are
	 * fragments of every page, not pages.
	 *
	 * @param string $key
	 * @return array
	 */
	public static function for_key( $key ) {
		$post_id = self::post_id_for_key( $key );
		return $post_id ? self::get( $post_id ) : self::blank();
	}

	/**
	 * @param string $key
	 * @return int 0 when this key has no page to hang SEO on.
	 */
	public static function post_id_for_key( $key ) {
		$key = sanitize_key( $key );
		if ( in_array( $key, array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ), true ) ) {
			return 0;
		}
		if ( CLARA_VE_DEFAULT_KEY === $key ) {
			// Via the store, not get_option('page_on_front') directly: that also
			// resolves a front page that has been tagged but whose settings have
			// not been flipped yet, which is the state an import passes through.
			return Clara_VE_Source_Store::front_anchor_id();
		}
		$page = Clara_VE_Source_Store::find_page_by_key( $key );
		return $page ? (int) $page->ID : 0;
	}

	// ------------------------------------------------------- fallback emitter

	/**
	 * Our own <title>, used only when no SEO plugin is installed.
	 *
	 * @param string $title
	 * @return string
	 */
	public static function filter_document_title( $title ) {
		if ( '' !== self::host() ) {
			return $title;
		}
		// esc_html at this boundary is not optional politeness: core only
		// escapes the document title when it BUILDS one itself — a non-empty
		// return from pre_get_document_title is emitted into <title> verbatim
		// (wp_get_document_title() returns early, before its esc_html call).
		// Our stored titles are tag-stripped, but context_title() also carries
		// term names and author display names, which are not ours to vouch for.
		// esc_html does not double-encode existing entities, so a title that is
		// already clean passes through unchanged.
		$seo = self::get( self::current_post_id() );
		if ( '' !== $seo['title'] ) {
			return esc_html( $seo['title'] );
		}
		$own = self::context_title();
		return '' === $own ? $title : esc_html( $own );
	}

	/**
	 * The title for a request that has no SEO record of its own.
	 *
	 * A converted site's pages arrive with real titles, but the URLs WordPress
	 * invents around them do not: a category archive, a search result, a 404. An
	 * SEO plugin gives all of those a sensible title from a template, and without
	 * one they fall back to whatever core happens to produce — which on a search
	 * page is the raw query and on an archive is often just the term.
	 *
	 * Assembled rather than templated with %%variables%%: the separator is the
	 * only part anybody wants to change, and a template language is a
	 * configuration surface that has to be documented, escaped and supported
	 * forever to save one line of code.
	 *
	 * @return string '' to leave core's own title alone.
	 */
	private static function context_title() {
		$name = (string) get_bloginfo( 'name' );
		$sep  = (string) get_option( self::OPT_TITLE_SEPARATOR, '–' );
		$sep  = '' === trim( $sep ) ? '–' : $sep;

		$what = '';
		if ( is_search() ) {
			/* translators: %s: the search term */
			$what = sprintf( __( 'Search results for “%s”', 'visual-edit-lite' ), get_search_query() );
		} elseif ( is_404() ) {
			$what = __( 'Page not found', 'visual-edit-lite' );
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			$what = ( $term && isset( $term->name ) ) ? $term->name : '';
		} elseif ( is_author() ) {
			$what = (string) get_the_author_meta( 'display_name', (int) get_query_var( 'author' ) );
		} elseif ( is_year() ) {
			$what = (string) get_the_date( 'Y' );
		} elseif ( is_month() ) {
			$what = (string) get_the_date( 'F Y' );
		} elseif ( is_day() ) {
			$what = (string) get_the_date();
		} elseif ( is_post_type_archive() ) {
			$what = (string) post_type_archive_title( '', false );
		}

		if ( '' === $what ) {
			return '';
		}

		// Page 2 of anything is a different document and should say so, or every
		// page of an archive competes for the same title.
		$page = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		if ( $page > 1 ) {
			/* translators: %d: page number */
			$what .= ' ' . $sep . ' ' . sprintf( __( 'page %d', 'visual-edit-lite' ), $page );
		}

		return $what . ' ' . $sep . ' ' . $name;
	}

	/**
	 * Robots directives, when nobody else is deciding them.
	 *
	 * Two jobs. The first is the per-page "hide this from search engines" the
	 * owner can tick — a thank-you page or a download-delivery page has no
	 * business in an index.
	 *
	 * The second is cleaning up after the platform change. A static site had
	 * exactly the pages it had; WordPress adds date archives, author archives
	 * and an attachment page per uploaded file, none of which existed before,
	 * none of which the design covers, and all of which are thin duplicates of
	 * real content competing with it. They are not worth a redirect — nothing
	 * links to them — but they should not be indexed. Yoast and Rank Math both
	 * offer this as a setting; when one of them is installed it is theirs to
	 * decide, so this stands down completely.
	 *
	 * @param array $robots
	 * @return array
	 */
	public static function filter_robots( $robots ) {
		if ( '' !== self::host() ) {
			return $robots;
		}

		$thin = is_date() || is_author() || is_attachment();
		if ( ! $thin ) {
			$seo = self::get( self::current_post_id() );
			if ( empty( $seo['noindex'] ) ) {
				return $robots;
			}
		}

		// follow, not nofollow: the page should not be listed, but the links on
		// it still lead to real pages and there is no reason to devalue those.
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['index'], $robots['nofollow'] );
		return $robots;
	}

	/**
	 * A description for a request whose record has none.
	 *
	 * A post's excerpt is the author's own summary, so it is extraction, not
	 * invention. A term's description likewise. Everything else gets nothing —
	 * a search results page has no summary to give, and making one up is the
	 * line this plugin does not cross.
	 *
	 * @param int $post_id
	 * @return string
	 */
	private static function context_description( $post_id ) {
		if ( is_singular() && $post_id ) {
			return trim( wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->description ) ) {
				return trim( wp_strip_all_tags( (string) $term->description ) );
			}
		}
		if ( is_front_page() || is_home() ) {
			return trim( wp_strip_all_tags( (string) get_bloginfo( 'description' ) ) );
		}
		return '';
	}

	/**
	 * The canonical URL for whatever is being requested.
	 *
	 * @return string
	 */
	private static function context_canonical() {
		$page = max( (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );

		// Singular first, and the order matters. Since the front page became a
		// real Page it is BOTH is_front_page() and is_singular(), and core's
		// rel_canonical() already emits one for it — so handling the front page
		// before this bail produced two canonical tags on the home page, which is
		// worse than none.
		if ( is_singular() ) {
			return '';
		}
		if ( is_front_page() ) {
			// Only reachable when the front page shows latest posts and so has no
			// page of its own.
			return $page > 1 ? (string) get_pagenum_link( $page ) : home_url( '/' );
		}
		if ( is_search() ) {
			return (string) get_search_link();
		}
		if ( is_404() ) {
			return '';
		}

		$base = '';
		if ( is_category() || is_tag() || is_tax() ) {
			$link = get_term_link( get_queried_object() );
			$base = is_wp_error( $link ) ? '' : (string) $link;
		} elseif ( is_author() ) {
			$base = (string) get_author_posts_url( (int) get_query_var( 'author' ) );
		} elseif ( is_post_type_archive() ) {
			$link = get_post_type_archive_link( (string) get_query_var( 'post_type' ) );
			$base = $link ? (string) $link : '';
		} elseif ( is_home() ) {
			$posts_page = (int) get_option( 'page_for_posts' );
			$base       = $posts_page ? (string) get_permalink( $posts_page ) : home_url( '/' );
		}
		if ( '' === $base ) {
			return '';
		}
		// A paginated archive is its own document; pointing page 2 at page 1
		// tells search engines to drop it, which is not what anybody wants.
		return $page > 1 ? (string) get_pagenum_link( $page ) : $base;
	}

	/**
	 * Open Graph defaults for this request.
	 *
	 * @param int    $post_id
	 * @param string $description
	 * @param string $canonical
	 * @return array
	 */
	private static function context_og( $post_id, $description, $canonical ) {
		$og = array(
			'locale'    => str_replace( '-', '_', (string) get_bloginfo( 'language' ) ),
			'site_name' => (string) get_bloginfo( 'name' ),
			'type'      => is_singular( 'post' ) ? 'article' : 'website',
			'title'     => wp_get_document_title(),
		);
		if ( '' !== $description ) {
			$og['description'] = $description;
		}

		$url = '' !== $canonical ? $canonical : ( ( is_singular() && $post_id ) ? (string) get_permalink( $post_id ) : '' );
		if ( '' !== $url ) {
			$og['url'] = $url;
		}

		// Featured image first, then the site-wide default. Without a fallback a
		// page shared on social media renders as a bare grey box, which is a
		// worse advert for the site than no share at all.
		$image = ( is_singular() && $post_id ) ? get_the_post_thumbnail_url( $post_id, 'full' ) : '';
		if ( ! $image ) {
			$image = (string) get_option( self::OPT_DEFAULT_OG, '' );
		}
		if ( $image ) {
			$og['image'] = $image;
		}

		// Article timestamps. Publishers and some crawlers read these to decide
		// how current a piece is.
		if ( is_singular( 'post' ) && $post_id ) {
			$og['article:published_time'] = (string) get_post_time( 'c', true, $post_id );
			$og['article:modified_time']  = (string) get_post_modified_time( 'c', true, $post_id );
		}

		return array_filter(
			$og,
			function ( $value ) {
				return '' !== $value && null !== $value;
			}
		);
	}

	/**
	 * The head tags, when nobody else is emitting them.
	 */
	public static function emit() {
		if ( '' !== self::host() ) {
			return;
		}
		$post_id = self::current_post_id();
		$seo     = self::get( $post_id );
		$og      = (array) $seo['og'];
		$twitter = (array) $seo['twitter'];

		$description = '' !== $seo['description'] ? $seo['description'] : self::context_description( $post_id );
		if ( '' !== $description ) {
			printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
		}

		// Core's rel_canonical() only fires on singular queries, so without this
		// an archive, a search page and every page 2 have no canonical at all —
		// which is where duplicate-content trouble actually comes from.
		$canonical = '' !== $seo['canonical'] ? $seo['canonical'] : self::context_canonical();
		if ( '' !== $canonical ) {
			printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
		}

		// Everything a social network needs that a page's own record does not
		// carry. Defaults, not overrides: anything already in the record wins.
		$og = array_merge( self::context_og( $post_id, $description, $canonical ), $og );

		// X reads Open Graph for everything except the card type, so the card is
		// the only twitter tag worth emitting unless the record says otherwise.
		if ( empty( $twitter['card'] ) ) {
			$twitter['card'] = ! empty( $og['image'] ) ? 'summary_large_image' : 'summary';
		}

		foreach ( $og as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			// A key that already carries its own namespace keeps it. The article
			// timestamps are `article:published_time`, NOT
			// `og:article:published_time` — they live in the Open Graph article
			// namespace rather than under og:, and prefixing them produces a
			// property no consumer recognises.
			$property = false === strpos( $name, ':' ) ? 'og:' . $name : $name;
			$is_url   = in_array( $name, array( 'image', 'url' ), true );
			printf(
				"<meta property=\"%s\" content=\"%s\" />\n",
				esc_attr( $property ),
				esc_attr( $is_url ? esc_url_raw( $value ) : $value )
			);
		}
		foreach ( $twitter as $name => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			printf(
				"<meta name=\"twitter:%s\" content=\"%s\" />\n",
				esc_attr( $name ),
				esc_attr( 'image' === $name ? esc_url_raw( $value ) : $value )
			);
		}

		foreach ( (array) $seo['jsonld'] as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$json = wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( ! $json ) {
				continue;
			}
			// wp_json_encode already escapes the payload; the remaining risk in
			// a <script> context is a literal "</script" inside a string value,
			// which the slash escape below neutralises without changing what
			// the JSON parser reads.
			printf(
				"<script type=\"application/ld+json\">%s</script>\n",
				str_replace( '</', '<\/', $json ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
		}
	}
}

Clara_VE_SEO::init();
