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
		// Late, so everything else has already registered what it wants and
		// enqueue_selected can see whether the theme has beaten it to these.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_selected' ), 100 );
		// And inside the block editor's CANVAS, which is the part that was
		// missing: since WordPress 6.3 the canvas is an iframe of its own, and
		// enqueue_block_editor_assets reaches the editor's chrome but not the
		// document the page is actually drawn in. A font enqueued there is
		// loaded where the sidebar lives and absent where the words are, so
		// the block editor rendered a fallback while the site was correct.
		// enqueue_block_assets is the hook WordPress puts INTO the iframe.
		add_action( 'enqueue_block_assets', array( __CLASS__, 'enqueue_in_block_editor' ) );
		// The THEME layer, not the user one. A typeface installed here behaves
		// exactly like one the theme shipped — it is meant to be available
		// everywhere, all the time — and WordPress treats the two layers very
		// differently where it matters: when it builds the stylesheet for the
		// block editor's canvas it generates a `.has-{slug}-font-family` rule
		// for every THEME preset and none for the user's. The variable was
		// defined, the class was on the block, and no rule connected them, so
		// the editor drew the theme's font over the chosen one while the site
		// itself was correct.
		add_filter( 'wp_theme_json_data_theme', array( __CLASS__, 'merge_into_theme_json' ) );
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

		// The merge into theme.json runs through a filter whose result
		// WordPress caches. Without dropping that cache the panel's own
		// refresh — same request — reads the family list from before this
		// save, so a font the owner just kept appears missing.
		//
		// wp_clean_theme_json_cache(), not the resolver's own
		// clean_cached_data(): the resolver only resets its static merge,
		// while wp_get_global_settings() answers from the `theme_json` object
		// cache, which survives it. Measured — the resolver call alone left
		// the panel showing the old list.
		if ( function_exists( 'wp_clean_theme_json_cache' ) ) {
			wp_clean_theme_json_cache();
		} elseif ( class_exists( 'WP_Theme_JSON_Resolver' ) ) {
			WP_Theme_JSON_Resolver::clean_cached_data();
		}

		return rest_ensure_response(
			array(
				'selected' => $clean,
				'cssUrl'   => self::css_url( $clean ),
				// The recomputed typeface presets, so the block-mode panel can
				// refresh its dropdown from the answer instead of guessing at
				// what the slug will turn out to be.
				'presets'  => self::family_presets(),
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
	 * Has something else already asked for these faces?
	 *
	 * Matched on the family names rather than the exact URL: the theme builds
	 * its own request, with its own weights and its own order, and comparing
	 * strings would call two requests for one font different.
	 *
	 * @return bool
	 */
	private static function already_enqueued() {
		$styles = wp_styles();
		if ( ! $styles ) {
			return false;
		}
		$wanted = array();
		foreach ( self::selected() as $entry ) {
			if ( ! empty( $entry['family'] ) ) {
				$wanted[] = str_replace( '%20', '+', rawurlencode( (string) $entry['family'] ) );
			}
		}
		if ( ! $wanted ) {
			return false;
		}

		foreach ( (array) $styles->queue as $handle ) {
			if ( 'clara-ve-google-fonts' === $handle || empty( $styles->registered[ $handle ]->src ) ) {
				continue;
			}
			$src = (string) $styles->registered[ $handle ]->src;
			if ( false === strpos( $src, 'fonts.googleapis.com' ) ) {
				continue;
			}
			// Every family we would ask for has to be there, or a theme
			// loading one of several would silence the rest.
			$covers = true;
			foreach ( $wanted as $family ) {
				$covers = $covers && false !== strpos( $src, $family );
			}
			if ( $covers ) {
				return true;
			}
		}
		return false;
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
		// Unless the theme is already loading them. A theme generated by the
		// converter reads this plugin's own list and publishes the faces
		// itself; a block theme has never heard of them and cannot. Asking
		// what is actually on the page tells the two apart without either of
		// them having to declare anything — and without the owner paying for
		// the same stylesheet twice.
		if ( self::already_enqueued() ) {
			return;
		}
		wp_enqueue_style( 'clara-ve-google-fonts', $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a Google CSS URL is already versioned by its query.
	}

	/**
	 * The site's typeface presets — the theme's own plus whatever has been
	 * kept here — in the shape the block-mode styling panel expects.
	 *
	 * Read back out of WordPress rather than assembled from the option, so
	 * the slugs are the ones the generated CSS actually uses. A family kept
	 * here reaches the `custom` origin through merge_into_theme_json().
	 *
	 * @return array<int,array{slug:string,name:string,value:string}>
	 */
	public static function family_presets() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$group = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		$out   = array();
		$seen  = array();
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( isset( $group[ $origin ] ) ? $group[ $origin ] : array() ) as $preset ) {
				if ( empty( $preset['slug'] ) || isset( $seen[ $preset['slug'] ] ) ) {
					continue;
				}
				$seen[ $preset['slug'] ] = true;
				$out[]                   = array(
					'slug'  => (string) $preset['slug'],
					'name'  => isset( $preset['name'] ) ? (string) $preset['name'] : (string) $preset['slug'],
					'value' => isset( $preset['fontFamily'] ) ? (string) $preset['fontFamily'] : '',
				);
			}
		}
		return $out;
	}

	/**
	 * The same stylesheet inside the block editor's canvas.
	 *
	 * Without it, a font kept here renders on the site and NOT in the editor,
	 * so the page looks wrong in the one place somebody is looking at it while
	 * deciding whether it looks right.
	 *
	 * @return void
	 */
	public static function enqueue_in_block_editor() {
		// enqueue_block_assets fires on the front end too, where
		// enqueue_selected has already done this.
		if ( ! is_admin() ) {
			return;
		}
		$url = self::css_url();
		if ( '' === $url ) {
			return;
		}
		wp_enqueue_style( 'clara-ve-google-fonts-editor', $url, array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- a Google CSS URL is already versioned by its query.
	}

	/**
	 * Offer the kept families to WordPress's own font pickers.
	 *
	 * Merged into theme.json's user layer rather than written into any theme
	 * file: the families are a site-level option, they follow a theme switch,
	 * and nothing on disk is touched. The effect is that Gutenberg's typography
	 * panel and this plugin's own dropdown offer the same list, instead of two
	 * pickers that disagree about what the site has.
	 *
	 * @param WP_Theme_JSON_Data $data
	 * @return WP_Theme_JSON_Data
	 */
	public static function merge_into_theme_json( $data ) {
		$families = self::selected();
		if ( empty( $families ) || ! is_object( $data ) || ! method_exists( $data, 'update_with' ) ) {
			return $data;
		}

		$entries = array();
		foreach ( $families as $entry ) {
			$family = isset( $entry['family'] ) ? (string) $entry['family'] : '';
			if ( '' === $family ) {
				continue;
			}
			$entries[] = array(
				'fontFamily' => '"' . $family . '", ' . self::fallback_for( $entry ),
				'name'       => $family,
				'slug'       => sanitize_title( $family ),
			);
		}
		if ( ! $entries ) {
			return $data;
		}

		// APPENDED to what the theme already declares, never handed over on
		// their own. A preset list is REPLACED by a merge rather than added
		// to, so passing only these erased the theme's own typefaces — every
		// heading on the site would have lost the face it was designed in,
		// and the panel would have offered exactly one font.
		// The data handed to this filter keys its presets by ORIGIN —
		// ['theme' => [ … ]] — while what update_with() takes back is a flat
		// list. Reading one level too high finds a single unnamed element,
		// which is what quietly swapped the theme's three typefaces for one.
		$existing = array();
		if ( method_exists( $data, 'get_data' ) ) {
			$current = $data->get_data();
			$found   = $current['settings']['typography']['fontFamilies'] ?? array();
			$existing = isset( $found['theme'] ) ? $found['theme'] : $found;
		}
		$mine = array_column( $entries, 'slug' );
		$kept = array_values( array_filter(
			(array) $existing,
			static function ( $family ) use ( $mine ) {
				// A theme that already ships a family of the same slug keeps
				// its own: it knows where its font files are, and this does
				// not.
				return empty( $family['slug'] ) || ! in_array( $family['slug'], $mine, true );
			}
		) );

		return $data->update_with(
			array(
				'version'  => 2,
				'settings' => array(
					'typography' => array(
						'fontFamilies' => array_merge( $kept, $entries ),
					),
				),
			)
		);
	}

	/**
	 * The generic family a kept font should fall back to, from whatever the
	 * picker recorded about it.
	 *
	 * @param array $entry
	 * @return string
	 */
	private static function fallback_for( $entry ) {
		$category = isset( $entry['category'] ) ? (string) $entry['category'] : '';
		return self::css_generic( $category );
	}
}

Clara_VE_Fonts::init();
