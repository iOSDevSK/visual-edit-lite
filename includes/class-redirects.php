<?php
/**
 * URL parity: keep the addresses the site had before it was WordPress.
 *
 * A conversion changes every URL on the site. `/about.html` becomes `/about/`,
 * and WordPress answers the old address with a 404. Inside the site that is
 * already handled — the bundler rewrites every internal link — but nothing can
 * rewrite the links that live *outside* it: other people's links, everybody's
 * bookmarks, and every URL a search engine has indexed. Since these conversions
 * are replatforms of sites that are already live and already ranking, that
 * breakage lands at precisely the moment the site can least afford it, and it
 * quietly costs more than the entire rest of the SEO work can add back.
 *
 * Served from PHP rather than written into .htaccess on purpose: a rewrite file
 * is invisible to the owner, does not travel with the theme, and does not exist
 * at all on nginx — where the same conversion is just as likely to be hosted.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Redirects {

	/**
	 * Original request path => visual-edit key, as written by
	 * Clara_VE_Bundle_Format::sanitize_redirects().
	 */
	const OPTION = 'clara_ve_redirects';

	/** @var array<string,string> Resolved key => URL, for this request only. */
	private static $resolved = array();

	public static function init() {
		// Priority 0: redirect_canonical() sits at 10 and, on a 404, GUESSES a
		// destination by LIKE-matching post_name across post types — an
		// attachment whose slug echoes the address wins that guess and the map
		// never runs. An explicit entry outranks a guess, so it must go first.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Send a 301 when the request is for an address the static site used to
	 * serve.
	 *
	 * Gated on is_404() deliberately, and it is the whole safety argument: this
	 * only ever acts where WordPress has already decided it has nothing, so no
	 * lookup here can shadow a real page, a real post, a feed or an admin
	 * screen. The map cannot take over a URL that works.
	 *
	 * One deliberate exception: an ATTACHMENT page. A media slug that happens
	 * to echo an old address makes WordPress serve the attachment there, so the
	 * request is not a 404 and the mapped page silently loses to a media
	 * accident. An attachment page is never authored content on a converted
	 * site, so when the map names the address, the page claim wins. Everything
	 * else that resolves still cannot be shadowed.
	 */
	public static function maybe_redirect() {
		if ( ! is_404() && ! is_attachment() ) {
			return;
		}
		$map = get_option( self::OPTION, array() );
		if ( ! is_array( $map ) || ! $map ) {
			return;
		}

		$path = self::request_path();
		if ( '' === $path || ! isset( $map[ $path ] ) ) {
			return;
		}

		$target = self::resolve( $map[ $path ] );
		if ( '' === $target ) {
			// A key with nothing behind it — the bundle lists /404.html, whose
			// key is the theme's 404 template and has no page of its own. The
			// right answer for that request is the 404 it is already getting, so
			// fall through rather than inventing a destination.
			return;
		}

		// Carry the query string over. These are live inbound links, so they
		// arrive with campaign parameters attached, and dropping them turns
		// attributable traffic into direct traffic.
		$query = self::request_query();
		if ( '' !== $query ) {
			$target .= ( false === strpos( $target, '?' ) ? '?' : '&' ) . $query;
		}

		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * The requested path, lower-cased and relative to the WordPress install.
	 *
	 * Made relative because home_url() may sit in a subdirectory, in which case
	 * the raw REQUEST_URI is "/blog/about.html" while the map — written by a
	 * bundler that knows nothing about where the site will live — says
	 * "/about.html".
	 *
	 * @return string
	 */
	private static function request_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( '' === $path ) {
			return '';
		}
		$path = strtolower( rawurldecode( $path ) );

		$base = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$base = strtolower( untrailingslashit( $base ) );
		if ( '' !== $base && 0 === strpos( $path, $base . '/' ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		return $path;
	}

	/**
	 * @return string The raw query string, or ''.
	 */
	private static function request_query() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return (string) wp_parse_url( $uri, PHP_URL_QUERY );
	}

	/**
	 * Where a visual-edit key lives now.
	 *
	 * Resolved per request rather than stored, which is the point of keeping the
	 * map in keys: the owner can rename a page's slug in WordPress and every old
	 * address still lands on it, with no migration step and nothing to keep in
	 * sync.
	 *
	 * A target prefixed "post:" names a blog POST rather than a page. A blog
	 * conversion turns the article pages into posts, so /blog/x.html has to
	 * redirect to a post — and resolving it through the same key indirection is
	 * what keeps the guarantee above true for articles too: retitle a post,
	 * which changes its slug, and the old address still lands on it.
	 *
	 * @param string $key
	 * @return string URL, or '' when this key has nothing behind it.
	 */
	private static function resolve( $key ) {
		if ( isset( self::$resolved[ $key ] ) ) {
			return self::$resolved[ $key ];
		}

		$url = '';
		if ( CLARA_VE_DEFAULT_KEY === $key ) {
			$url = home_url( '/' );
		} elseif ( 0 === strpos( $key, 'post:' ) ) {
			$post = self::find_post_by_key( substr( $key, 5 ) );
			if ( $post && 'publish' === $post->post_status ) {
				$permalink = get_permalink( $post );
				$url       = $permalink ? $permalink : '';
			}
		} else {
			$page = Clara_VE_Source_Store::find_page_by_key( $key );
			if ( $page && 'publish' === $page->post_status ) {
				$permalink = get_permalink( $page );
				$url       = $permalink ? $permalink : '';
			}
		}

		self::$resolved[ $key ] = $url;
		return $url;
	}

	/**
	 * The blog post carrying this visual-edit key, stamped at import.
	 *
	 * Falls back to the slug so a post imported before keys were stamped, or
	 * created by hand to replace one, still resolves — the fallback is exact,
	 * not a guess, and a miss simply leaves the 404 the request already had.
	 *
	 * Public because the menu importer needs the same resolution: a nav group
	 * can deep-link an article, and that article exists here only as a Post
	 * found by its bundle key (its page copy is deliberately excluded).
	 *
	 * @param string $key
	 * @return WP_Post|null
	 */
	public static function find_post_by_key( $key ) {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return null;
		}
		// Scoped to the active theme. Two converted themes on one install can
		// both carry an article keyed "about" or a same-slug post, and an
		// unscoped lookup hands back whichever the query reaches first — so an
		// old article URL 301s into the OTHER theme's post, and a nav
		// deep-link imported through apply_menus() binds to it permanently.
		// Posts the owner wrote carry no theme stamp and stay eligible.
		$scope = function_exists( 'clara_ve_theme_post_scope' ) ? clara_ve_theme_post_scope() : null;
		$found = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'no_found_rows' => true,
				'meta_query'    => $scope // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					? array(
						'relation' => 'AND',
						array(
							'key'   => CLARA_VE_PAGE_KEY_META,
							'value' => $key,
						),
						$scope,
					)
					: array(
						array(
							'key'   => CLARA_VE_PAGE_KEY_META,
							'value' => $key,
						),
					),
			)
		);
		if ( $found ) {
			return $found[0];
		}
		$by_slug = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => 'publish',
				'numberposts'   => 1,
				'name'          => $key,
				'no_found_rows' => true,
				'meta_query'    => $scope ? $scope : array(), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
		return $by_slug ? $by_slug[0] : null;
	}

	/**
	 * Store a map, merging into whatever is already there.
	 *
	 * Merged rather than replaced so a site converted more than once — a second
	 * import after the static source gained pages — keeps the addresses the
	 * first run established. An address that once redirected should not stop.
	 *
	 * Which theme an entry came from is recorded in that theme's registry
	 * record rather than beside the entry itself. The map's shape — a flat
	 * path => key string map — is read in several places and by every install
	 * that already has one; widening it to carry an owner would mean teaching
	 * all of them a second shape to earn nothing the registry cannot hold.
	 *
	 * @param array<string,string> $map
	 * @return int Number of entries added.
	 */
	public static function store( $map ) {
		if ( ! is_array( $map ) || ! $map ) {
			return 0;
		}
		$existing = get_option( self::OPTION, array() );
		$existing = is_array( $existing ) ? $existing : array();
		$added    = array_diff_key( $map, $existing );
		if ( $added ) {
			update_option( self::OPTION, array_merge( $existing, $map ) );
			self::attribute( $added );
		}
		return count( $added );
	}

	/**
	 * Note which theme the newly added addresses belong to, so leaving that
	 * theme can take them out of service and coming back can put them in again.
	 *
	 * Only newly added entries: an address already redirecting belongs to
	 * whoever established it, and store()'s merge deliberately never takes it
	 * away from them.
	 *
	 * @param array<string,string> $added
	 * @return void
	 */
	private static function attribute( array $added ) {
		if ( ! class_exists( 'Clara_VE_Theme_Registry' ) ) {
			return;
		}
		$owner = Clara_VE_Theme_Registry::active_owner();
		if ( '' === $owner ) {
			return;
		}
		$record = Clara_VE_Theme_Registry::get( $owner );
		$mine   = ( is_array( $record ) && isset( $record['redirects'] ) && is_array( $record['redirects'] ) )
			? $record['redirects']
			: array();
		Clara_VE_Theme_Registry::remember( $owner, array( 'redirects' => array_merge( $mine, $added ) ) );
	}
}

Clara_VE_Redirects::init();
