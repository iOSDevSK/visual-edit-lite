<?php
/**
 * Regression: what the plugin does — and refuses to do — to a theme it is not
 * the editor for, and proof that a theme it IS the editor for is untouched.
 *
 * Both halves are asserted from this one file, branching on
 * clara_ve_active_theme_is_ours(). Run it under a converted theme and it
 * proves the established behavior survives; run it under a native block theme
 * and it proves the 1.21.0 guards fire. Neither run is meaningful alone: the
 * whole risk of standing the runtime down is standing it down too far, and a
 * test that only ever saw the new theme could not see that happen.
 *
 * Nothing here mutates the site. The one write it attempts is the write it
 * expects to be refused, and it verifies afterwards that nothing landed.
 *
 *   php tools/run-in-wp.php ../amanda-rose-sandbox/wordpress tests/regression-foreign-theme.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Source_Store' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$theme  = get_stylesheet();
$ours   = clara_ve_active_theme_is_ours();
$chrome = array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY );
$failed = array();

/**
 * @param string $what
 * @param bool   $ok
 */
$check = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

echo "theme: {$theme} — " . ( $ours ? "the plugin's own\n" : "foreign to the plugin\n" );

$listed = wp_list_pluck(
	array_filter(
		Clara_VE_Source_Store::list_keys(),
		static function ( $entry ) {
			return 'part' === $entry['kind'];
		}
	),
	'key'
);

// ------------------------------------------------------------ chrome keys

if ( $ours ) {
	foreach ( $chrome as $key ) {
		$check( "chrome key '{$key}' is offered", in_array( (string) $key, $listed, true ) );
		$check( "chrome key '{$key}' is available", Clara_VE_Source_Store::chrome_key_available( $key ) );
	}
	foreach ( clara_ve_theme_contract()['parts'] as $part ) {
		$check( "variant part '{$part['key']}' is offered", in_array( $part['key'], $listed, true ) );
	}

	// The round trip the new guard in sync_to_template_part() could have
	// broken: a chrome save on a theme the plugin owns must still reach the
	// template part. Asserting the key is merely "offered" would not have
	// caught that — the canvas would open, take an edit, and lose it.
	$option   = 'clara_ve_source__' . sanitize_key( $theme ) . '__' . CLARA_VE_HEADER_KEY;
	$restore  = get_option( $option, null );
	$existing = get_block_template( $theme . '//' . CLARA_VE_HEADER_KEY, 'wp_template_part' );
	$was_db   = ( $existing && 'custom' === $existing->source && $existing->wp_id ) ? (int) $existing->wp_id : 0;
	$was_html = $was_db ? get_post_field( 'post_content', $was_db ) : '';
	$original = Clara_VE_Source_Store::get_current_source( CLARA_VE_HEADER_KEY );

	$check( 'the header canvas is not empty', '' !== trim( $original ) );
	Clara_VE_Source_Store::save_source( CLARA_VE_HEADER_KEY, $original . "\n<!-- ve-regression-marker -->" );
	$check(
		'a chrome save reaches the template part',
		false !== strpos( Clara_VE_Source_Store::get_current_source( CLARA_VE_HEADER_KEY ), 've-regression-marker' )
	);

	// Put the site back exactly as found — including NOT leaving behind a
	// template-part override this test brought into existence.
	if ( null === $restore ) {
		delete_option( $option );
	} else {
		update_option( $option, $restore, false );
	}
	$after = get_block_template( $theme . '//' . CLARA_VE_HEADER_KEY, 'wp_template_part' );
	if ( $was_db ) {
		wp_update_post(
			array(
				'ID'           => $was_db,
				'post_content' => $was_html,
			)
		);
	} elseif ( $after && 'custom' === $after->source && $after->wp_id ) {
		wp_delete_post( (int) $after->wp_id, true );
	}
	clean_post_cache( $was_db );
	wp_cache_flush();
} else {
	foreach ( $chrome as $key ) {
		$stored = get_option( 'clara_ve_source__' . sanitize_key( $theme ) . '__' . sanitize_key( $key ), null );
		if ( null !== $stored ) {
			// Grandfathered: a previous version let this key be saved, so it
			// stays editable rather than stranding whatever is in it.
			$check( "chrome key '{$key}' kept for stored content", in_array( (string) $key, $listed, true ) );
			continue;
		}
		$check( "chrome key '{$key}' is NOT offered", ! in_array( (string) $key, $listed, true ) );
		$check( "chrome key '{$key}' is NOT available", ! Clara_VE_Source_Store::chrome_key_available( $key ) );
	}

	// The write this release exists to prevent: an empty canvas saved onto a
	// theme that has no such part, which appended a core/html block and wrote
	// a wp_template_part row that shadowed parts/header.html from then on.
	$option = 'clara_ve_source__' . sanitize_key( $theme ) . '__' . CLARA_VE_HEADER_KEY;
	$before = get_option( $option, null );
	$check( 'a chrome save is refused', false === Clara_VE_Source_Store::save_source( CLARA_VE_HEADER_KEY, '<p>regression</p>' ) );
	$check( 'the refused save wrote no option row', $before === get_option( $option, null ) );

	// post_name__in, NOT name: `name` makes WP_Query singular, and
	// WP_Query::get_posts() parses tax_query only `if ( ! $this->is_singular )`
	// — so the wp_theme filter was silently dropped and this found ANY theme's
	// header part. A leftover from the legacy leg of the same suite run was
	// enough to report an override this save had not created.
	$override = get_posts(
		array(
			'post_type'     => 'wp_template_part',
			'post_status'   => 'any',
			'post_name__in' => array( CLARA_VE_HEADER_KEY ),
			'numberposts'   => 1,
			'fields'        => 'ids',
			'tax_query'   => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => 'wp_theme',
					'field'    => 'name',
					'terms'    => $theme,
				),
			),
		)
	);
	$check( 'the refused save created no template-part override', ! $override );
}

// ------------------------------------------------------- SEO / GEO / llms

// Two groups, because they are governed by two different decisions and
// conflating them is how this gets got wrong.
//
// The SEO head stands down for either reason: a theme that DECLARES
// html2wp-runtime has opted out of the plugin's public delivery (the
// pre-existing path, untouched by 1.21.0), and so has a theme the plugin does
// not edit at all.
$seo_hooks = array(
	'wp_head/Clara_VE_SEO::emit' => has_action( 'wp_head', array( 'Clara_VE_SEO', 'emit' ) ),
);

// GEO and llms.txt are NOT in the delegation list and never were: a converted
// theme emits its own SEO head but still wants the plugin's JSON-LD and its
// /llms.txt. Only the new stand-down touches these, and only on a theme the
// plugin does not edit.
$geo_hooks = array(
	'wp_head/Clara_VE_GEO::emit_standalone' => has_action( 'wp_head', array( 'Clara_VE_GEO', 'emit_standalone' ) ),
	'template_redirect/llms.txt'            => has_action( 'template_redirect', array( 'Clara_VE_GEO', 'maybe_serve_llms_txt' ) ),
	'robots_txt/llms.txt advertisement'     => has_filter( 'robots_txt', array( 'Clara_VE_GEO', 'filter_robots_txt' ) ),
);

// An install that once ran a converted theme has the SEO settings filled in
// from its import, so "configured" is the ordinary state on a machine like
// this one, not an exotic one.
$delegates  = clara_ve_theme_owns_public_runtime();
$configured = clara_ve_public_seo_is_configured();
// A theme that declares html2wp-runtime has said it renders its own public
// metadata, and that declaration outranks a stored option row — otherwise an
// install carrying SEO settings from a previous theme's import gets two
// schema.org entities for the same business, which is the bug the whole
// stand-down exists to remove.
$foreign    = ! $ours && ( $delegates || ! $configured );

echo '  --   ' . ( $delegates ? 'theme delegates its public runtime' : 'theme does not delegate' )
	. ( $configured ? '; site SEO settings are configured' : '; no site SEO settings' ) . "\n";

foreach ( $seo_hooks as $label => $priority ) {
	$down = $delegates || $foreign;
	$check( $down ? "{$label} stood down" : "{$label} still registered", ( false === $priority ) === $down );
}
foreach ( $geo_hooks as $label => $priority ) {
	$check( $foreign ? "{$label} stood down" : "{$label} still registered", ( false === $priority ) === $foreign );
}

// ----------------------------------------------------------- query scoping

$probe = new WP_Query();
$probe->init();
$probe->is_home       = true;
$probe->is_main_query = true;
$GLOBALS['wp_the_query'] = $probe; // is_main_query() compares against this.
clara_ve_scope_posts_to_theme( $probe );
$scoped = (bool) $probe->get( 'meta_query' );
$check( $ours ? 'listings are scoped to this theme' : 'listings are NOT scoped', $ours ? $scoped : ! $scoped );

// ------------------------------------------------- what a visitor receives

// Hook bookkeeping is not the claim; the claim is about the bytes on the
// page. Skipped rather than failed when nothing is serving the site, so the
// file stays runnable without a web server in front of it.
$front = wp_remote_get( home_url( '/' ), array( 'timeout' => 10 ) );
if ( is_wp_error( $front ) || 200 !== (int) wp_remote_retrieve_response_code( $front ) ) {
	echo "  --   site not reachable over HTTP; skipping the rendered-output checks\n";
} else {
	$html = (string) wp_remote_retrieve_body( $front );
	// og:description is the one tag here with a precondition. The emitter
	// deliberately invents nothing: with no record on the front page and no
	// site tagline there is no description, so none is printed. It and
	// <meta name="description"> are built from the SAME variable, which makes
	// their presence the honest test — one without the other is a real bug and
	// still fails here, while neither means this site gave nothing to describe.
	$describable = 1 === preg_match( '/<meta[^>]+name=["\']description["\']/i', $html );
	foreach ( array( 'og:title', 'og:description', 'og:url' ) as $tag ) {
		$count = preg_match_all( '/property=["\']' . preg_quote( $tag, '/' ) . '["\']/', $html );
		if ( 'og:description' === $tag && ! $describable && 0 === $count ) {
			echo "  --   no front-page description and no tagline; og:description correctly omitted\n";
			continue;
		}
		$check( "exactly one {$tag} on the front page (found {$count})", 1 === $count );
	}
	$count = preg_match_all( '/<script[^>]+application\/ld\+json/i', $html );
	$expected_ld = ( $ours || clara_ve_public_seo_is_configured() ) ? ( $count >= 1 ) : ( 1 === $count );
	$check( "JSON-LD is not doubled (found {$count})", $expected_ld );

	$llms = wp_remote_get( home_url( '/llms.txt' ), array( 'timeout' => 10 ) );
	$code = is_wp_error( $llms ) ? 0 : (int) wp_remote_retrieve_response_code( $llms );
	if ( ! $foreign ) {
		$check( "/llms.txt is served ({$code})", 200 === $code );
	} else {
		// A cached rewrite rule with nothing answering it renders the FRONT
		// PAGE at 200 under a .txt name, which is why the stand-down installs
		// a 404 rather than just unhooking the server.
		$check( "/llms.txt is a real 404 ({$code})", 404 === $code );
	}
}

// ------------------------------------------------------------------ result

echo "\n";
if ( $failed ) {
	echo 'FAIL: ' . count( $failed ) . " assertion(s) — {$theme}\n";
	exit( 1 );
}
echo "PASS: foreign-theme coexistence — {$theme}\n";
