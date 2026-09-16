<?php
/**
 * Regression: the upsell is three menu items in the right places, one screen,
 * and nothing at all when the paid edition is installed.
 *
 * Three things are being guarded.
 *
 * The first is WHERE the two feature items sit. They stand in for screens the
 * paid edition really has, so they are only honest if they are in the same
 * places: AI Settings straight after Form Submissions, Export Theme straight
 * after SEO & Sharing. Those positions are computed from the menu as it
 * already stands, so one more screen added to Lite would move them — which is
 * precisely the change nothing else would notice.
 *
 * The second is that the screen behind them says what it is meant to say and
 * links where it is meant to link, with the rel that stops the opened tab from
 * reaching back into the admin.
 *
 * The third is the stand-down. Lite switches itself off when Visual Edit Pro
 * is active, and an upsell item next to the real AI Settings would be the
 * worst version of this feature. The stand-down is a file-scope return before
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

// ------------------------------------------------------- 1. the three items

$assistant = $position( Clara_VE_Get_Pro::PAGE_ASSISTANT );
$export    = $position( Clara_VE_Get_Pro::PAGE_EXPORT );
$buy       = $position( Clara_VE_Get_Pro::BUY_URL );

$check( 'the AI Settings item is registered', $assistant >= 0 );
$check( 'the Export Theme item is registered', $export >= 0 );
$check( 'the Get Pro item is registered', $buy >= 0 );
$check( 'Get Pro is a plain link to the pricing page, not a screen', $buy >= 0 && Clara_VE_Get_Pro::BUY_URL === $slugs[ $buy ] );

$check(
	'all three ask for the capability the parent menu asks for',
	$assistant >= 0 && $export >= 0 && $buy >= 0
		&& 'edit_theme_options' === $caps[ $assistant ]
		&& 'edit_theme_options' === $caps[ $export ]
		&& 'edit_theme_options' === $caps[ $buy ]
);

// None of the three may answer on a slug the paid edition uses for the real
// screen: two plugins under one slug is a collision, not an upsell.
$check(
	'the upsell slugs are not the paid screens own slugs',
	! in_array( 'visual-edit-ai', $slugs, true ) && ! in_array( 'visual-edit-export', $slugs, true )
);

// ------------------------------------------------------------- 2. the order

$submissions = $position( 'edit.php?post_type=' . Clara_VE_Forms::CPT );
$sharing     = $position( Clara_VE_SEO_Settings::PAGE );

$check( 'Form Submissions is in the menu to sit after', $submissions >= 0 );
$check( 'SEO and Sharing is in the menu to sit after', $sharing >= 0 );
$check( 'AI Settings comes directly after Form Submissions', $submissions >= 0 && $assistant === $submissions + 1 );
$check( 'Export Theme comes directly after SEO and Sharing', $sharing >= 0 && $export === $sharing + 1 );
$check( 'Get Pro is the last item in the menu', $buy === count( $slugs ) - 1 );

// The badge and the prominent label are what make the three read as what they
// are. They live in the menu TITLE, which is index 0.
$labels = array();
foreach ( $rows as $row ) {
	$labels[] = isset( $row[0] ) ? (string) $row[0] : '';
}
$check( 'AI Settings carries the Pro badge', $assistant >= 0 && false !== strpos( $labels[ $assistant ], 'cve-pro-badge' ) );
$check( 'Export Theme carries the Pro badge', $export >= 0 && false !== strpos( $labels[ $export ], 'cve-pro-badge' ) );
$check( 'Get Pro carries the prominent label', $buy >= 0 && false !== strpos( $labels[ $buy ], 'cve-get-pro' ) );

// ------------------------------------------------------------ 3. the screen

ob_start();
Clara_VE_Get_Pro::render_assistant();
$with_assistant = ob_get_clean();

ob_start();
Clara_VE_Get_Pro::render_export();
$with_export = ob_get_clean();

$check( 'the screen names the paid edition', false !== strpos( $with_assistant, 'Visual Edit Pro' ) );
$check( 'the screen links to the pricing page', false !== strpos( $with_assistant, Clara_VE_Get_Pro::BUY_URL ) );
$check( 'the link opens safely', false !== strpos( $with_assistant, 'rel="noopener noreferrer"' ) );
$check( 'the button says what it does', false !== strpos( $with_assistant, 'Buy Visual Edit Pro' ) );
$check( 'the AI Settings item answers that feature first', false !== strpos( $with_assistant, 'cve-pro-card' ) && false !== strpos( $with_assistant, 'AI Settings' ) );
$check( 'the Export Theme item answers that feature first', false !== strpos( $with_export, 'cve-pro-card' ) && false !== strpos( $with_export, 'Export Theme' ) );
// The menu link itself opens in a new tab: a menu entry cannot carry a
// target, so assets() adds one to that single anchor from an inline script.
if ( ! wp_script_is( 'common', 'registered' ) ) {
	wp_register_script( 'common', admin_url( 'js/common.js' ), array(), false, true );
}
Clara_VE_Get_Pro::assets();
$after = wp_scripts()->get_data( 'common', 'after' );
$js    = is_array( $after ) ? implode( "\n", $after ) : (string) $after;
$check( 'the Get Pro link opens in a new tab', false !== strpos( $js, '_blank' ) && false !== strpos( $js, 'noopener noreferrer' ) && false !== strpos( $js, 'html2wp.dev' ) );

// Nothing is fetched from anywhere, and nothing is reported anywhere. A src or
// href pointing off-site other than the one button would be both.
$check(
	'the screen loads nothing from outside',
	false === stripos( $with_assistant, '<script' ) && false === stripos( $with_assistant, '<img' ) && false === stripos( $with_assistant, '<link' )
);
$check( 'the link carries no campaign parameters', false === strpos( $with_assistant, 'utm_' ) && false === strpos( Clara_VE_Get_Pro::BUY_URL, 'utm_' ) );

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
echo "PASS: three upsell items in the right places, one honest screen, and none of it beside the real thing\n";
