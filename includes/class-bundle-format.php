<?php
/**
 * The one definition of the content-bundle format: its folder name, its
 * version string, its portability tokens, and the allowlist of options that
 * may travel in it.
 *
 * Everything that reads or writes a bundle goes through here, so a token
 * string never appears as a literal anywhere else and the writer and the
 * reader cannot drift into disagreeing about what a bundle is.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Bundle_Format {

	/** Folder holding the bundle, at the root of the theme directory. */
	const DIR = 'clara-content';

	/** Written into manifest.json; the reader refuses anything it can't read. */
	const FORMAT = 'clara-content/1';

	/**
	 * Portability tokens. The theme URI already has one
	 * (CLARA_VE_THEME_URI_TOKEN) applied by Clara_VE_Source_Store on save;
	 * these two exist only at the bundle boundary and are never persisted —
	 * see the warning on Clara_VE_Source_Store::tokenize() for why widening
	 * that function instead would break History's HEAD marker.
	 */
	const UPLOADS_TOKEN = '__CLARA_UPLOADS_URI__';
	const HOME_TOKEN    = '__CLARA_HOME_URL__';

	/**
	 * Options that may be exported, and in which mode.
	 *
	 * 'portable' — a design/behaviour choice that means the same thing on any
	 *              site (which fonts, how long the form time-trap waits, the
	 *              wording of a confirmation email). Exported in every mode.
	 * 'site'     — true of THIS site only: a recipient address, an SMTP host,
	 *              a mailing-list provider. Exported in
	 *              'site' mode; withheld from a 'sample' bundle, where it
	 *              would hand the theme's buyer someone else's infrastructure.
	 *
	 * An option absent from this list is not exportable at all — the writer
	 * iterates THIS array rather than scanning the options table, so a new
	 * setting added elsewhere in the plugin is excluded until someone
	 * deliberately lists it. That is the intended failure direction.
	 */
	const OPTIONS = array(
		// Design and content
		'clara_ve_google_fonts'          => 'portable',

		// Form behaviour (not delivery — see 'site' entries below)
		'clara_ve_form_min_seconds'      => 'portable',
		'clara_ve_form_akismet'          => 'portable',
		'clara_ve_form_consent'          => 'portable',
		'clara_ve_form_consent_text'     => 'portable',
		'clara_ve_form_from_name'        => 'portable',

		// Double opt-in copy
		'clara_ve_optin_mode'            => 'portable',
		'clara_ve_optin_confirm_subject' => 'portable',
		'clara_ve_optin_confirm_body'    => 'portable',
		'clara_ve_optin_deliver_subject' => 'portable',
		'clara_ve_optin_deliver_body'    => 'portable',
		'clara_ve_list_doi_template'     => 'portable',
		'clara_ve_list_doi_redirect'     => 'portable',

		// Site-wide SEO identity. Portable because it describes the BUSINESS the
		// theme was designed for, which is exactly what a sample bundle is
		// demonstrating; a buyer overwrites it with their own. The per-page
		// values do not live here — they ride along with each source row.
		'clara_ve_seo_entity_type'       => 'portable',
		'clara_ve_seo_entity_name'       => 'portable',
		'clara_ve_seo_entity_logo'       => 'portable',
		'clara_ve_seo_same_as'           => 'portable',
		'clara_ve_seo_default_og_image'  => 'portable',
		'clara_ve_seo_title_separator'   => 'portable',

		// This site's own wiring
		'clara_ve_form_to'               => 'site',
		'clara_ve_form_from_email'       => 'site',
		'clara_ve_mailer'                => 'site',
		'clara_ve_smtp_host'             => 'site',
		'clara_ve_smtp_port'             => 'site',
		'clara_ve_smtp_encryption'       => 'site',
		'clara_ve_smtp_auth'             => 'site',
		'clara_ve_smtp_user'             => 'site',
		'clara_ve_mailgun_domain'        => 'site',
		'clara_ve_mailgun_region'        => 'site',
		'clara_ve_list_provider'         => 'site',
	);

	/**
	 * Secrets. The allowlist above already excludes every one of these by
	 * simply not listing them; this is the second, redundant check —
	 * a tripwire the writer asserts against its finished output and the
	 * reader asserts against an incoming bundle. Belt and braces on purpose:
	 * the cost of the redundancy is one array_intersect, and the cost of
	 * being wrong once is a customer's API key inside a file they hand to
	 * someone else.
	 */
	const NEVER_EXPORT = array(
		'clara_ve_smtp_pass',
		'clara_ve_api_brevo',
		'clara_ve_api_sendgrid',
		'clara_ve_api_postmark',
		'clara_ve_api_mailgun',
	);

	/**
	 * Shapes of well-known secrets, matched against exported VALUES — the
	 * name-based lists above cannot catch a key someone pasted into the wrong
	 * field, or a future option nobody remembered to classify.
	 */
	const SECRET_VALUE_PATTERN = '/(^sk-|^sk_live|^SG\.|^key-[0-9a-f]{32}|^xkeysib-|^pk_live|BEGIN [A-Z ]*PRIVATE KEY)/';

	/**
	 * The shape of a per-page SEO record — the one definition, so the bundler,
	 * the writer, the reader and the SEO adapter cannot drift.
	 *
	 * `jsonld` holds decoded schema.org structures rather than a raw string, so
	 * the adapter can merge them into a host plugin's own @graph instead of
	 * emitting a second, competing one.
	 */
	const SEO_FIELDS = array( 'title', 'description', 'canonical', 'noindex', 'og', 'twitter', 'jsonld' );

	/**
	 * Normalise an SEO record read from a bundle.
	 *
	 * Written as an allowlist over a fixed shape for the same reason the OPTIONS
	 * list is an allowlist: a bundle is a file that arrived from somewhere else.
	 * Strings are stripped of tags — these end up inside meta content
	 * attributes, where markup has no meaning and is only a way to break out of
	 * the attribute — and nesting is capped at the two levels the format has, so
	 * a hostile bundle cannot hand us an arbitrarily deep structure to walk.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	public static function sanitize_seo( $raw ) {
		$out = array(
			'title'       => '',
			'description' => '',
			'canonical'   => '',
			'noindex'     => false,
			'og'          => array(),
			'twitter'     => array(),
			'jsonld'      => array(),
		);
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		$out['noindex'] = ! empty( $raw['noindex'] );

		foreach ( array( 'title', 'description' ) as $field ) {
			if ( isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ) {
				$out[ $field ] = sanitize_text_field( (string) $raw[ $field ] );
			}
		}
		// Left tokenized: from_portable() runs later, at the point of use, and a
		// token is not a URL yet — esc_url_raw() here would mangle it.
		if ( isset( $raw['canonical'] ) && is_scalar( $raw['canonical'] ) ) {
			$out['canonical'] = self::sanitize_seo_url( (string) $raw['canonical'] );
		}

		foreach ( array( 'og', 'twitter' ) as $bag ) {
			if ( empty( $raw[ $bag ] ) || ! is_array( $raw[ $bag ] ) ) {
				continue;
			}
			foreach ( $raw[ $bag ] as $name => $value ) {
				if ( ! is_scalar( $value ) ) {
					continue;
				}
				$name = preg_replace( '/[^a-z0-9_:.\-]/', '', strtolower( (string) $name ) );
				if ( '' === $name ) {
					continue;
				}
				$out[ $bag ][ $name ] = in_array( $name, array( 'image', 'url', 'image:secure_url' ), true )
					? self::sanitize_seo_url( (string) $value )
					: sanitize_text_field( (string) $value );
			}
		}

		if ( ! empty( $raw['jsonld'] ) && is_array( $raw['jsonld'] ) ) {
			foreach ( $raw['jsonld'] as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				// Re-encoded rather than trusted as-is: a structure that cannot
				// survive a round trip through json_encode is not something we
				// want to print into a <script> tag later.
				$encoded = wp_json_encode( $block );
				$decoded = null === $encoded ? null : json_decode( $encoded, true );
				if ( is_array( $decoded ) ) {
					$out['jsonld'][] = $decoded;
				}
			}
		}

		return $out;
	}

	/**
	 * Normalise a redirect map: original request path => visual-edit key.
	 *
	 * Accepts the bundle's list-of-rows form and returns a lookup map, because
	 * the only thing that ever reads it is "does this path have a target".
	 *
	 * These become live 301s, so the input is treated as hostile. A `from` must
	 * be a site-relative path and nothing else: an absolute URL, a
	 * protocol-relative "//evil.example.com", a scheme, or a "..\" traversal all
	 * get dropped rather than cleaned up, since there is no legitimate bundle
	 * that contains one. Paths are lower-cased so a link someone typed in the
	 * wrong case still lands — static hosts are commonly case-insensitive and
	 * WordPress is not.
	 *
	 * @param mixed $raw
	 * @return array<string,string>
	 */
	public static function sanitize_redirects( $raw ) {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $row ) {
			// A row targets a page ("key") or a post ("post"); one or the other.
			if ( ! is_array( $row ) || empty( $row['from'] ) || ! is_scalar( $row['from'] )
				|| ( empty( $row['key'] ) && empty( $row['post'] ) ) ) {
				continue;
			}
			$from = strtolower( trim( (string) $row['from'] ) );
			if ( '/' !== substr( $from, 0, 1 ) || 0 === strpos( $from, '//' ) ) {
				continue; // not site-relative, or a protocol-relative URL
			}
			if ( false !== strpos( $from, '..' ) || preg_match( '#[\\\\<>"\'\s]#', $from ) ) {
				continue;
			}
			if ( '/' === $from ) {
				continue; // the home page is not something to redirect away from
			}
			// A blog conversion turns article pages into POSTS, and the addresses
			// those articles used to serve still have to land somewhere. Marked
			// with a prefix rather than a second column so the stored map keeps
			// its flat path => target shape and no existing site needs
			// migrating. The prefix cannot be ambiguous: sanitize_key() strips
			// colons, so no page key can ever look like one.
			$is_post = ! empty( $row['post'] );
			$key     = sanitize_key( $is_post ? $row['post'] : $row['key'] );
			if ( '' === $key ) {
				continue;
			}
			$out[ $from ] = $is_post ? 'post:' . $key : $key;
		}
		return $out;
	}

	/**
	 * Resolve an SEO record's tokens against this site, and the inverse.
	 *
	 * Records are stored resolved, matching how post_content and post_excerpt
	 * are already handled: tokens exist at the bundle boundary and nowhere
	 * else. Without this an imported og:image stays
	 * "__CLARA_UPLOADS_URI__/ve-import/front-page/og-image.jpg", which is
	 * not a URL — every social preview on the delivered site would silently
	 * come up blank, and the failure is invisible from inside WordPress.
	 *
	 * jsonld goes through the JSON text so nested values at any depth are
	 * covered; a schema.org block is mostly absolute self-references.
	 *
	 * @param array $seo
	 * @return array
	 */
	public static function seo_from_portable( $seo ) {
		return self::map_seo_strings( $seo, array( __CLASS__, 'from_portable' ) );
	}

	/**
	 * @param array $seo
	 * @return array
	 */
	public static function seo_to_portable( $seo ) {
		return self::map_seo_strings( $seo, array( __CLASS__, 'to_portable' ) );
	}

	/**
	 * @param array    $seo
	 * @param callable $fn Applied to every URL-bearing string in the record.
	 * @return array
	 */
	private static function map_seo_strings( $seo, $fn ) {
		$seo = self::sanitize_seo( $seo );

		$seo['canonical'] = call_user_func( $fn, $seo['canonical'] );
		foreach ( array( 'og', 'twitter' ) as $bag ) {
			foreach ( $seo[ $bag ] as $name => $value ) {
				$seo[ $bag ][ $name ] = call_user_func( $fn, $value );
			}
		}
		if ( ! empty( $seo['jsonld'] ) ) {
			// JSON_UNESCAPED_SLASHES is load-bearing, not cosmetic. json_encode
			// escapes "/" by default, so a URL inside the structure is spelled
			// "https:\/\/example.com" — and to_portable()'s str_replace, which
			// looks for "https://example.com", then matches nothing. The record
			// would come back out with the exporting site's real domain frozen
			// into its schema.org block, and every site delivered from that
			// bundle would publish structured data pointing at somebody else's
			// address. from_portable() happens to survive without this (a token
			// has no slashes to escape), which is exactly what makes the bug
			// one-directional and easy to miss.
			$json    = wp_json_encode( $seo['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$decoded = $json ? json_decode( call_user_func( $fn, $json ), true ) : null;
			if ( is_array( $decoded ) ) {
				$seo['jsonld'] = $decoded;
			}
		}
		return $seo;
	}

	/**
	 * A URL-or-token, kept usable as both. esc_url_raw() would strip the
	 * uploads/home tokens the bundler writes, so a token passes through
	 * untouched and only a real URL is escaped.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function sanitize_seo_url( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$probe = str_replace( array( self::UPLOADS_TOKEN, self::HOME_TOKEN, CLARA_VE_THEME_URI_TOKEN ), 'https://example.com', $value );
		return esc_url_raw( $probe ) === $probe ? $value : '';
	}

	/**
	 * Option names exportable in the given mode.
	 *
	 * @param string $mode 'sample' or 'site'.
	 * @return string[]
	 */
	public static function options_for_mode( $mode ) {
		$out = array();
		foreach ( self::OPTIONS as $name => $scope ) {
			if ( 'portable' === $scope || 'site' === $mode ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * Rewrite this site's uploads and home URLs to tokens.
	 *
	 * Uploads first, deliberately: the uploads base URL normally BEGINS with
	 * the home URL, so replacing home first would leave
	 * "__CLARA_HOME_URL__/wp-content/uploads/..." and the uploads rule would
	 * then never match — media would silently land unportable while looking
	 * fine in the exported file. The theme URI token is left alone; sources
	 * already carry it and Clara_VE_Source_Store owns that one.
	 *
	 * @param string $html
	 * @return string
	 */
	public static function to_portable( $html ) {
		$html = (string) $html;
		foreach ( self::uploads_urls() as $url ) {
			$html = str_replace( $url, self::UPLOADS_TOKEN, $html );
		}
		foreach ( self::home_urls() as $url ) {
			$html = str_replace( $url, self::HOME_TOKEN, $html );
		}
		return $html;
	}

	/**
	 * Resolve bundle tokens against THIS site. The inverse of to_portable(),
	 * in the mirror order (uploads before home) for the same reason.
	 *
	 * CLARA_VE_THEME_URI_TOKEN is NOT resolved here — that one belongs to
	 * Clara_VE_Source_Store, which owns both halves of it. Callers writing a
	 * source back into the site must therefore run
	 * Clara_VE_Source_Store::untokenize() as well; leaving it is not
	 * harmless, because save_source() forwards the same string to the render
	 * targets (a Page's post_content, a wp_template_part), which take it
	 * literally and will happily publish "__CLARA_THEME_URI__/assets/…".
	 *
	 * @param string $html
	 * @return string
	 */
	public static function from_portable( $html ) {
		$uploads = self::uploads_urls();
		$home    = self::home_urls();
		$html    = str_replace( self::UPLOADS_TOKEN, reset( $uploads ), (string) $html );
		$html    = str_replace( self::HOME_TOKEN, reset( $home ), $html );
		// A media file this import had to store under a different path than
		// the bundle names (see set_media_remap) has to be renamed in the
		// markup too, or the page points at a file that is not there — or,
		// worse, at ANOTHER theme's file that happens to be.
		if ( self::$media_remap ) {
			$base = untrailingslashit( reset( $uploads ) );
			foreach ( self::$media_remap as $from => $to ) {
				// Rewrite the ABSOLUTE form only. A bare relative path is a
				// short string that can appear inside a longer one; the URL it
				// became cannot.
				$html = str_replace( $base . '/' . $from, $base . '/' . $to, $html );
			}
		}
		return $html;
	}

	/**
	 * Media paths this import moved, as `from => to` (both relative to the
	 * uploads directory).
	 *
	 * @var array<string,string>
	 */
	private static $media_remap = array();

	/**
	 * Record that a bundled file had to be stored somewhere other than the
	 * path the bundle names, so every later from_portable() follows it.
	 *
	 * Set once by apply_media(), which runs FIRST in the apply sequence — see
	 * Clara_VE_Import_Plan::apply() — so sources, post content, SEO fields and
	 * menu URLs all resolve through it afterwards. Doing it here rather than
	 * at each of those call sites is deliberate: there are seven of them, and
	 * a missed one is an image that silently points at another theme's file.
	 *
	 * @param array<string,string> $map
	 */
	public static function set_media_remap( array $map ) {
		// Longest key first: one path can be a prefix of another, and the
		// shorter one must not consume the longer one's replacement.
		uksort(
			$map,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		self::$media_remap = $map;
	}

	/**
	 * The uploads base URL, canonical form first, plus its scheme-flipped
	 * twin. A site that has moved between http and https keeps both spellings
	 * alive in older stored content, and a bundle that carries one of them
	 * verbatim breaks on the target.
	 *
	 * @return string[]
	 */
	private static function uploads_urls() {
		$dir  = wp_upload_dir();
		$base = untrailingslashit( isset( $dir['baseurl'] ) ? $dir['baseurl'] : '' );
		return self::with_scheme_twin( $base );
	}

	/**
	 * @return string[]
	 */
	private static function home_urls() {
		return self::with_scheme_twin( untrailingslashit( home_url() ) );
	}

	/**
	 * @param string $url
	 * @return string[] The URL, then the same URL under the other scheme.
	 */
	private static function with_scheme_twin( $url ) {
		if ( '' === $url ) {
			return array( '' );
		}
		$twin = ( 0 === strpos( $url, 'https://' ) )
			? 'http://' . substr( $url, 8 )
			: 'https://' . substr( $url, 7 );
		return array( $url, $twin );
	}

	/**
	 * The hash a bundle records for a source, and that the importer compares
	 * against to decide "same content" vs "conflict". Computed on the
	 * PORTABLE form so it is stable across sites — hashing the resolved form
	 * would make every source look changed the moment the domain differs.
	 *
	 * @param string $portable_html
	 * @return string
	 */
	public static function hash_source( $portable_html ) {
		return hash( 'sha256', (string) $portable_html );
	}

	/**
	 * Assert that nothing secret is about to be written. Fails loudly rather
	 * than filtering quietly: a silent filter would let a mistake in the
	 * allowlist ship undetected for as long as nobody looked.
	 *
	 * @param array $options Name => value map bound for options.json.
	 * @return true|string True when clean, else a human-readable reason.
	 */
	public static function assert_no_secrets( array $options ) {
		$named = array_intersect( array_keys( $options ), self::NEVER_EXPORT );
		if ( $named ) {
			return 'Refusing to export: ' . implode( ', ', $named ) . ' is a credential and must never leave this site.';
		}
		foreach ( $options as $name => $value ) {
			if ( is_string( $value ) && preg_match( self::SECRET_VALUE_PATTERN, $value ) ) {
				return 'Refusing to export: the value of ' . $name . ' looks like an API key or private key.';
			}
		}
		return true;
	}

	/**
	 * Skeleton manifest; the writer fills in mode, counts and theme details.
	 *
	 * @return array
	 */
	public static function manifest_defaults() {
		$uploads = wp_upload_dir();
		return array(
			'format'      => self::FORMAT,
			'generator'   => 'Visual Edit ' . CLARA_VE_VERSION,
			'generated_at' => gmdate( 'c' ),
			'mode'        => 'sample',
			'theme'       => array(),
			'source_site' => array(
				'home'            => home_url(),
				'uploads_baseurl' => isset( $uploads['baseurl'] ) ? $uploads['baseurl'] : '',
				'charset'         => get_bloginfo( 'charset' ),
				'wp_version'      => get_bloginfo( 'version' ),
			),
			'tokens'      => array(
				'theme_uri'   => CLARA_VE_THEME_URI_TOKEN,
				'uploads_uri' => self::UPLOADS_TOKEN,
				'home_url'    => self::HOME_TOKEN,
			),
			'contains'    => array(),
			'excluded'    => array( 'secrets', 'submissions', 'subscribers' ),
			'notes'       => 'API keys, SMTP passwords, form submissions and subscriber records are never exported.',
		);
	}
}
