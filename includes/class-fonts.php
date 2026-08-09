<?php
/**
 * Google Fonts for the visual editor.
 *
 * The editor's font picker ships a short hardcoded list — whatever the
 * converted site already used, plus the web-safe families. This adds the rest
 * of Google Fonts on the owner's terms: they browse the full catalogue, keep a
 * handful (MAX_FONTS) and those become available on every element of every
 * page, exactly like the built-in ones.
 *
 * Two deliberate limits:
 *
 * - The catalogue is read from Google's own public metadata endpoint, which
 *   needs NO API key (the documented Web Fonts Developer API does, and this
 *   plugin is meant to work without the owner registering for anything). It's
 *   ~2.7 MB, so it's fetched server-side, cut down to the three fields the
 *   picker actually uses, and cached for a week.
 *
 * - Only a few families may be kept at once. Every selected family is a real
 *   stylesheet on every page load, so this is a page-weight budget, not an
 *   arbitrary cap.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Fonts {

	// Selected families: a list of array( 'family' => string, 'category' => string ).
	const OPTION = 'clara_ve_google_fonts';

	// Each family costs a stylesheet and a webfont download on every page.
	const MAX_FONTS = 5;

	const CATALOG_TRANSIENT = 'clara_ve_google_font_catalog';
	const CATALOG_TTL       = WEEK_IN_SECONDS;
	const CATALOG_URL       = 'https://fonts.google.com/metadata/fonts';

	// Weights requested per family — the ones a design realistically uses.
	// Asking for every weight a family offers would multiply the download for
	// faces nobody selects in the panel.
	const WEIGHTS = array( 300, 400, 500, 600, 700 );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Front end AND the edit preview (which is a front-end request too), so
		// a page renders in the chosen fonts whether it's being edited or read.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_selected' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			'/google-fonts',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => 'clara_ve_user_can_edit',
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'rest_save' ),
					'permission_callback' => 'clara_ve_user_can_edit',
					'args'                => array(
						'families' => array( 'type' => 'array', 'required' => true ),
					),
				),
			)
		);
	}

	/**
	 * @return array<int,array{family:string,category:string}>
	 */
	public static function selected() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$out = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['family'] ) ) {
				$out[] = array(
					'family'   => (string) $entry['family'],
					'category' => isset( $entry['category'] ) ? (string) $entry['category'] : 'sans-serif',
				);
			}
		}
		return array_slice( $out, 0, self::MAX_FONTS );
	}

	/**
	 * The picker's data: the whole catalogue plus what's currently kept.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_get() {
		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		return rest_ensure_response(
			array(
				'catalog'  => $catalog,
				'selected' => self::selected(),
				'max'      => self::MAX_FONTS,
			)
		);
	}

	/**
	 * Replace the kept set. Families are validated against the catalogue, so
	 * only real Google families can end up in a stylesheet URL.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save( WP_REST_Request $request ) {
		$requested = (array) $request->get_param( 'families' );
		if ( count( $requested ) > self::MAX_FONTS ) {
			return new WP_Error(
				'clara_ve_fonts_too_many',
				/* translators: %d: maximum number of fonts */
				sprintf( __( 'You can keep at most %d Google fonts.', 'visual-edit-lite' ), self::MAX_FONTS ),
				array( 'status' => 400 )
			);
		}

		$catalog = self::catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}
		$known = array();
		foreach ( $catalog as $item ) {
			$known[ $item['family'] ] = $item['category'];
		}

		$clean = array();
		$seen  = array();
		foreach ( $requested as $entry ) {
			$family = is_array( $entry ) ? ( $entry['family'] ?? '' ) : $entry;
			$family = trim( (string) $family );
			if ( '' === $family || isset( $seen[ $family ] ) || ! isset( $known[ $family ] ) ) {
				continue; // unknown or duplicate — silently dropped.
			}
			$seen[ $family ] = true;
			$clean[]         = array(
				'family'   => $family,
				'category' => $known[ $family ],
			);
		}

		update_option( self::OPTION, $clean, false );
		return rest_ensure_response(
			array(
				'selected' => $clean,
				'cssUrl'   => self::css_url( $clean ),
			)
		);
	}

	/**
	 * Google's family list, trimmed to what the picker needs and cached.
	 *
	 * @param bool $fresh Bypass the cache.
	 * @return array<int,array{family:string,category:string}>|WP_Error
	 */
	public static function catalog( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CATALOG_TRANSIENT );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$response = wp_remote_get( self::CATALOG_URL, array( 'timeout' => 25 ) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'clara_ve_fonts_http', $response->get_error_message(), array( 'status' => 502 ) );
		}
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'clara_ve_fonts_http', __( 'The Google Fonts list could not be loaded.', 'visual-edit-lite' ), array( 'status' => 502 ) );
		}

		$body = wp_remote_retrieve_body( $response );
		// Google prefixes this JSON with an anti-JSON-hijacking guard.
		$brace = strpos( $body, '{' );
		if ( false !== $brace && $brace > 0 ) {
			$body = substr( $body, $brace );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data['familyMetadataList'] ) ) {
			return new WP_Error( 'clara_ve_fonts_bad', __( 'The Google Fonts list was not in the expected format.', 'visual-edit-lite' ), array( 'status' => 502 ) );
		}

		$catalog = array();
		foreach ( $data['familyMetadataList'] as $item ) {
			if ( empty( $item['family'] ) ) {
				continue;
			}
			$catalog[] = array(
				'family'   => (string) $item['family'],
				// Normalized to a CSS generic so the picker can offer a sane
				// fallback without a second lookup.
				'category' => self::css_generic( isset( $item['category'] ) ? (string) $item['category'] : '' ),
			);
		}

		set_transient( self::CATALOG_TRANSIENT, $catalog, self::CATALOG_TTL );
		return $catalog;
	}

	/**
	 * Google's human category ("Sans Serif", "Display", …) as the CSS generic
	 * to fall back to when the webfont hasn't loaded.
	 *
	 * @param string $category
	 * @return string
	 */
	private static function css_generic( $category ) {
		$c = strtolower( str_replace( array( ' ', '-' ), '', $category ) );
		if ( 'serif' === $c ) {
			return 'serif';
		}
		if ( 'handwriting' === $c ) {
			return 'cursive';
		}
		if ( 'monospace' === $c ) {
			return 'monospace';
		}
		return 'sans-serif'; // sans serif and display both read best as sans.
	}

	/**
	 * The fonts.googleapis.com stylesheet for a set of families, or '' if none.
	 *
	 * @param array|null $families Defaults to the kept set.
	 * @return string
	 */
	public static function css_url( $families = null ) {
		$families = null === $families ? self::selected() : $families;
		if ( empty( $families ) ) {
			return '';
		}
		$parts = array();
		foreach ( $families as $entry ) {
			$family = isset( $entry['family'] ) ? $entry['family'] : '';
			if ( '' === $family ) {
				continue;
			}
			// css2 wants the family name with '+' for spaces, and weights as an
			// ascending, semicolon-separated axis list.
			$parts[] = 'family=' . str_replace( '%20', '+', rawurlencode( $family ) ) . ':wght@' . implode( ';', self::WEIGHTS );
		}
		if ( empty( $parts ) ) {
			return '';
		}
		return 'https://fonts.googleapis.com/css2?' . implode( '&', $parts ) . '&display=swap';
	}

	/**
	 * Load the kept families on the site itself. Registered for the front end,
	 * which covers ordinary visitors, every subpage, and the editor's own
	 * preview iframe alike.
	 *
	 * @return void
	 */
	public static function enqueue_selected() {
		$url = self::css_url();
		if ( '' === $url ) {
			return;
		}
		wp_enqueue_style( 'clara-ve-google-fonts', $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a Google CSS URL is already versioned by its query.
	}
}

Clara_VE_Fonts::init();
