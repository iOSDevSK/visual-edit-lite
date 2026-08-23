<?php
/**
 * Regression: Appearance → Menus must not open on a departed theme's menu.
 *
 * The screen does not open on the first entry of its own filtered list. It
 * opens on `nav_menu_recently_edited`, a per-user option holding a bare term
 * id that survives a theme switch untouched, and it resolves that id with
 * is_nav_menu() — wp_get_nav_menu_object(), and so get_term(). A fetch by id
 * goes through neither wp_get_nav_menus nor get_terms_args, so the two filters
 * that hide a parked theme's menus cannot reach it: the dropdown correctly
 * omitted the parked menu while the screen was already sitting on it, showing
 * the old theme's items on every switch.
 *
 * Run inside a disposable WordPress install:
 *   php tools/run-in-wp.php <wordpress-dir> tests/regression-parked-recent-menu.php
 */

defined( 'ABSPATH' ) || exit( 1 );

if ( ! class_exists( 'Clara_VE_Theme_Park' ) ) {
	throw new RuntimeException( 'Visual Edit is not active.' );
}

$active   = sanitize_key( get_stylesheet() );
$departed = 'clara-ve-regression-departed';
$registry = get_option( Clara_VE_Theme_Registry::OPTION, array() );
$user_id  = (int) get_users( array( 'number' => 1, 'fields' => 'ID' ) )[0];
$was      = get_user_meta( $user_id, 'nav_menu_recently_edited', true );

$mine    = wp_create_nav_menu( 'VE regression — active theme menu' );
$theirs  = wp_create_nav_menu( 'VE regression — departed theme menu' );

$cleanup = static function () use ( $mine, $theirs, $registry, $user_id, $was ) {
	foreach ( array( $mine, $theirs ) as $id ) {
		if ( ! is_wp_error( $id ) ) {
			wp_delete_nav_menu( (int) $id );
		}
	}
	update_option( Clara_VE_Theme_Registry::OPTION, $registry, false );
	if ( '' === $was ) {
		delete_user_meta( $user_id, 'nav_menu_recently_edited' );
	} else {
		update_user_meta( $user_id, 'nav_menu_recently_edited', $was );
	}
	Clara_VE_Theme_Park::flush_parked_memo();
};

$assert = static function ( $condition, $message ) use ( $cleanup ) {
	if ( $condition ) {
		return;
	}
	$cleanup();
	throw new RuntimeException( $message );
};

$assert( ! is_wp_error( $mine ) && ! is_wp_error( $theirs ), 'The fixture menus could not be created.' );
$assert( $user_id > 0, 'No user to hold the recently-edited option.' );

update_term_meta( (int) $mine, Clara_VE_Theme_Registry::TERM_META, $active );
update_term_meta( (int) $theirs, Clara_VE_Theme_Registry::TERM_META, $departed );

// A theme that has left: in the registry, flagged parked. parked_themes()
// believes the flag and the content both, and the flag alone is enough here.
$registry_now              = Clara_VE_Theme_Registry::all();
$registry_now[ $departed ] = array( 'parked' => current_time( 'mysql' ) );
update_option( Clara_VE_Theme_Registry::OPTION, $registry_now, false );
Clara_VE_Theme_Park::flush_parked_memo();

$assert(
	in_array( $departed, Clara_VE_Theme_Park::parked_themes(), true ),
	'The fixture theme was not seen as parked, so nothing below tests anything.'
);

// 1. The pointer left behind by the departed theme is not honoured.
update_user_meta( $user_id, 'nav_menu_recently_edited', (int) $theirs );
$assert(
	0 === (int) get_user_option( 'nav_menu_recently_edited', $user_id ),
	'Appearance → Menus would still open on the departed theme\'s menu.'
);

// 2. The active theme's own menu is untouched. parked_themes() never contains
//    the active stylesheet, and hiding a theme's menu from itself is the one
//    answer always wrong.
update_user_meta( $user_id, 'nav_menu_recently_edited', (int) $mine );
$assert(
	(int) $mine === (int) get_user_option( 'nav_menu_recently_edited', $user_id ),
	'The active theme\'s own menu was refused as the recently-edited one.'
);

// 3. A menu belonging to nobody — one the owner made — is left alone.
$loose = wp_create_nav_menu( 'VE regression — unowned menu' );
if ( ! is_wp_error( $loose ) ) {
	update_user_meta( $user_id, 'nav_menu_recently_edited', (int) $loose );
	$kept = (int) get_user_option( 'nav_menu_recently_edited', $user_id );
	wp_delete_nav_menu( (int) $loose );
	$assert( (int) $loose === $kept, 'An unowned menu was refused as the recently-edited one.' );
}

// 4. The dropdown still excludes it — the filter this one supplements.
$listed = wp_list_pluck( wp_get_nav_menus(), 'term_id' );
$assert( ! in_array( (int) $theirs, array_map( 'intval', $listed ), true ), 'hide_parked_menus() stopped working.' );
$assert( in_array( (int) $mine, array_map( 'intval', $listed ), true ), 'The active theme\'s menu vanished from the list.' );

// 5. With nothing parked, the option is returned exactly as stored.
update_option( Clara_VE_Theme_Registry::OPTION, $registry, false );
Clara_VE_Theme_Park::flush_parked_memo();
update_user_meta( $user_id, 'nav_menu_recently_edited', (int) $theirs );
$assert(
	(int) $theirs === (int) get_user_option( 'nav_menu_recently_edited', $user_id ),
	'The option was altered on a site with no parked theme at all.'
);

$cleanup();
echo "PASS: a parked theme's menu is not what Appearance → Menus opens on.\n";
