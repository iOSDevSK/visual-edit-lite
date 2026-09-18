<?php
/**
 * Regression: the upsell is ONE plain menu item, last, with one screen behind
 * it — and nothing at all when the paid edition is installed.
 *
 * Three things are being guarded.
 *
 * The first is that it stays one item and stays plain. An item named after a
 * paid feature, greyed and badged, reads as a feature that is present and
 * switched off — which is what the WordPress.org review objected to, whatever
 * the code behind it does. So: exactly one row of this plugin's own that names
 * Pro, no markup in its label, no script added to the admin, and its few style
 * rules loaded on its own screen only.
 *
 * The second is that the screen says what it is meant to say and links where
 * it is meant to link, with the rel that stops the opened tab from reaching
 * back into the admin.
 *
 * The third is the stand-down. Lite switches itself off when Visual Edit Pro
 * is active, and an upsell item next to the real product would be the worst
 * version of this feature. The stand-down is a file-scope return before
 * anything is required, so it cannot be proved inside a WordPress that has
 * already booted: the check below boots a SECOND one, with the paid edition
 * pre-seeded into the active-plugin list, and asserts that this file's class
 * does not even exist there.
 *
 *   php tools/run-in-wp.php ../wordpress tests/regression-get-pro.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Get_Pro' ) ) {
	throw new RuntimeException( 'Visual Edit Lite is not active, or the Get Pro screen is not part of this build.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

// Post types put their own rows in this menu, and they register on init.
if ( ! did_action( 'init' ) ) {
	do_action( 'init' );
}
wp_set_current_user( 1 );
if ( ! did_action( 'admin_menu' ) ) {
	do_action( 'admin_menu' );
}

global $submenu;
$rows  = isset( $submenu['visual-edit'] ) ? array_values( (array) $submenu['visual-edit'] ) : array();
$slugs = array();
$caps  = array();
foreach ( $rows as $row ) {
	$slugs[] = isset( $row[2] ) ? (string) $row[2] : '';
	$caps[]  = isset( $row[1] ) ? (string) $row[1] : '';
}
$position = static function ( $slug ) use ( $slugs ) {
	$at = array_search( $slug, $slugs, true );
	return false === $at ? -1 : (int) $at;
};

$check( 'the Visual Edit menu has rows at all', count( $rows ) > 3 );

// ---------------------------------------------------------- 1. the one item

$labels = array();
foreach ( $rows as $row ) {
	$labels[] = isset( $row[0] ) ? (string) $row[0] : '';
}
$item = $position( Clara_VE_Get_Pro::PAGE );

$check( 'the Visual Edit Pro item is registered', $item >= 0 );
$check( 'it asks for the capability the parent menu asks for', $item >= 0 && 'edit_theme_options' === $caps[ $item ] );
$check( 'it is the last item in the menu', $item === count( $slugs ) - 1 );
$check( 'its label is plain text — no badge, no pill, no markup', $item >= 0 && wp_strip_all_tags( $labels[ $item ] ) === $labels[ $item ] );

$upsell_rows = 0;
foreach ( $slugs as $slug ) {
	if ( 0 === strpos( $slug, 'visual-edit-lite-' ) || false !== strpos( $slug, 'html2wp.dev' ) ) {
		++$upsell_rows;
	}
}
$check( 'it is the ONLY upsell row in the menu', 1 === $upsell_rows );
$check( 'no menu row is an off-site link', array() === preg_grep( '#^https?://#', $slugs ) );

$badged = 0;
foreach ( $labels as $label ) {
	if ( false !== stripos( $label, 'cve-pro-badge' ) || false !== stripos( $label, 'cve-get-pro' ) ) {
		++$badged;
	}
}
$check( 'no row wears a Pro badge', 0 === $badged );

// It may not answer on a slug the paid edition uses for a real screen: two
// plugins under one slug is a collision, not an upsell.
$check(
	'the slug is not one of the paid screens own slugs',
	! in_array( 'visual-edit-ai', $slugs, true ) && ! in_array( 'visual-edit-export', $slugs, true )
);

// ------------------------------------------------------------ 2. the screen

ob_start();
Clara_VE_Get_Pro::render();
$screen = ob_get_clean();

$check( 'the screen names the paid edition', false !== strpos( $screen, 'Visual Edit Pro' ) );
$check( 'the screen says Pro is a separate plugin', false !== strpos( $screen, 'separate' ) );
$check( 'the screen links to the pricing page', false !== strpos( $screen, Clara_VE_Get_Pro::BUY_URL ) );
$check( 'the link opens safely', false !== strpos( $screen, 'rel="noopener noreferrer"' ) );
$check( 'there is exactly one outbound link', 1 === substr_count( $screen, 'href="http' ) );

// Nothing is fetched from anywhere, and nothing is reported anywhere. A src or
// href pointing off-site other than the one button would be both.
$check(
	'the screen loads nothing from outside',
	false === stripos( $screen, '<script' ) && false === stripos( $screen, '<img' ) && false === stripos( $screen, '<link' )
);
$check( 'the link carries no campaign parameters', false === strpos( $screen, 'utm_' ) && false === strpos( Clara_VE_Get_Pro::BUY_URL, 'utm_' ) );

// ---------------------------------------------- 3. nothing on other screens

// The few style rules belong to the screen. On any other admin page this
// class adds no CSS, and it adds no script anywhere.
if ( ! wp_style_is( 'common', 'registered' ) ) {
	wp_register_style( 'common', admin_url( 'css/common.css' ), array(), false );
}
if ( ! wp_script_is( 'common', 'registered' ) ) {
	wp_register_script( 'common', admin_url( 'js/common.js' ), array(), false, true );
}
$inline_css = static function () {
	$after = wp_styles()->get_data( 'common', 'after' );
	return is_array( $after ) ? implode( "\n", $after ) : (string) $after;
};
Clara_VE_Get_Pro::assets( 'index.php' );
Clara_VE_Get_Pro::assets( 'toplevel_page_visual-edit' );
$check( 'no CSS is added to other admin screens', false === strpos( $inline_css(), 'cve-pro-page' ) );
Clara_VE_Get_Pro::assets( 'visual-edit-lite_page_' . Clara_VE_Get_Pro::PAGE );
$check( 'the screen gets its own CSS', false !== strpos( $inline_css(), 'cve-pro-page' ) );
$after_js = wp_scripts()->get_data( 'common', 'after' );
$after_js = is_array( $after_js ) ? implode( "\n", $after_js ) : (string) $after_js;
$check( 'no script is added to the admin at all', false === strpos( $after_js, 'html2wp.dev' ) && false === strpos( $after_js, 'adminmenu' ) );

// ---------------------------------------------------------- 4. the stand-down

if ( ! function_exists( 'shell_exec' ) ) {
	$check( 'the stand-down could be checked at all (shell_exec is available)', false );
} else {
	$probe = tempnam( sys_get_temp_dir(), 'cvegp' );
	$code  = <<<'PROBE'
<?php
// Boot a WordPress that believes Visual Edit Pro is active, without writing a
// single row: pre-seeding $wp_filter is what WordPress itself does for
// filters that must exist before the plugin API loads.
$GLOBALS['wp_filter'] = array(
	'pre_option_active_plugins' => array(
		10 => array(
			'clara_ve_probe' => array(
				'function'       => static function () {
					return array( 'visual-edit/visual-edit.php', 'visual-edit-lite/visual-edit-lite.php' );
				},
				'accepted_args' => 1,
			),
		),
	),
);
define( 'WP_USE_THEMES', false );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . 'wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );
do_action( 'admin_menu' );
global $submenu;
$slugs = array();
foreach ( (array) ( isset( $submenu['visual-edit'] ) ? $submenu['visual-edit'] : array() ) as $row ) {
	$slugs[] = isset( $row[2] ) ? (string) $row[2] : '';
}
echo wp_json_encode(
	array(
		'sees_pro' => function_exists( 'clara_ve_lite_pro_active' ) && clara_ve_lite_pro_active(),
		'class'    => class_exists( 'Clara_VE_Get_Pro' ),
		'slugs'    => $slugs,
	)
);
PROBE;
	file_put_contents( $probe, $code );
	$raw = shell_exec( 'php ' . escapeshellarg( $probe ) . ' ' . escapeshellarg( ABSPATH ) . ' 2>&1' );
	unlink( $probe );
	$json = json_decode( (string) $raw, true );
	if ( ! is_array( $json ) ) {
		$check( 'the second WordPress reported back (got: ' . trim( (string) $raw ) . ')', false );
	} else {
		$upsell = array();
		foreach ( (array) $json['slugs'] as $slug ) {
			if ( 0 === strpos( $slug, 'visual-edit-lite-' ) ) {
				$upsell[] = $slug;
			}
		}
		$check( 'with the paid edition active, Lite sees it', ! empty( $json['sees_pro'] ) );
		$check( 'with the paid edition active, the upsell class is never loaded', empty( $json['class'] ) );
		$check( 'with the paid edition active, no upsell item is registered', array() === $upsell );
	}
}

echo "\n";
if ( $failed ) {
	echo 'FAILED (' . count( $failed ) . "):\n - " . implode( "\n - ", $failed ) . "\n";
	exit( 1 );
}
echo "PASS: one plain upsell item, one honest screen, nothing on any other admin page, and none of it beside the real thing\n";
