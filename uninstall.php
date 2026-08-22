<?php
/**
 * Runs when the plugin is DELETED from Plugins → Delete (not on deactivate).
 *
 * Two tiers, because "delete the plugin" and "destroy my data" are different
 * decisions a site owner makes at different times:
 *
 * Always removed — things that are dangerous, useless, or invisible without
 * the plugin: stored secrets (SMTP password, provider API keys — ciphertext
 * at rest, but ciphertext with no reader is pure liability), transients, and
 * scheduled work pointing at callbacks that no longer exist.
 *
 * Removed only when the owner ticked "also delete all stored data" (Form
 * Settings → Uninstall): form submissions, the subscriber table (someone's
 * mailing list is a business asset — never silently destroyed), edit history,
 * edited page sources and every other clara_ve_* row. Off by default, so
 * delete-and-reinstall round-trips with nothing lost.
 *
 * @package VisualEdit
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// ---- Always, and FIRST: take every parked page back out.
//
// Parking is the plugin's own idea — a page put away under the internal
// `clara_ve_parked` status at a `{key}--ve-{theme}` address, restorable
// because this plugin registers that status and remembers what it was. Delete
// the plugin while any theme's content is parked and nothing registers the
// status any more: those pages become unqueryable, invisible in wp-admin and
// on the site, at addresses nobody would guess — and the only code that could
// have brought them back has just been deleted. That is the one piece of
// parking bookkeeping the owner cannot undo by hand, so it is undone here,
// before the opt-in gate, for everyone.
//
// Deliberately plain SQL and no plugin classes: uninstall.php must not depend
// on plugin code loading, and by this point it is not.
$clara_ve_parked_ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'clara_ve_parked'"
);
foreach ( array_map( 'intval', (array) $clara_ve_parked_ids ) as $clara_ve_id ) {
	$clara_ve_was  = (string) get_post_meta( $clara_ve_id, '_clara_ve_status', true );
	$clara_ve_slug = (string) get_post_meta( $clara_ve_id, '_clara_ve_slug', true );
	// 'publish' is what an imported page was, and the safe floor when the
	// remembered status is missing or names something this site no longer has.
	$clara_ve_status = ( '' !== $clara_ve_was && get_post_status_object( $clara_ve_was ) ) ? $clara_ve_was : 'publish';
	$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->posts,
		'' !== $clara_ve_slug
			? array( 'post_status' => $clara_ve_status, 'post_name' => $clara_ve_slug )
			: array( 'post_status' => $clara_ve_status ),
		array( 'ID' => $clara_ve_id )
	);
	clean_post_cache( $clara_ve_id );
	delete_post_meta( $clara_ve_id, '_clara_ve_status' );
	delete_post_meta( $clara_ve_id, '_clara_ve_slug' );
}
// The attachment half of the same idea: a flag whose only reader is gone.
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_clara_ve_parked'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
// And the registry's parked flags, so a reinstall does not believe content is
// put away when it is now live.
$clara_ve_registry = get_option( 'clara_ve_themes', array() );
if ( is_array( $clara_ve_registry ) && $clara_ve_registry ) {
	foreach ( $clara_ve_registry as $clara_ve_slug_key => $clara_ve_record ) {
		if ( is_array( $clara_ve_record ) ) {
			unset( $clara_ve_registry[ $clara_ve_slug_key ]['parked'] );
		}
	}
	update_option( 'clara_ve_themes', $clara_ve_registry, false );
}

// ---- Always: secrets. Enumerated literally rather than by importing the
// plugin's classes — uninstall.php must not depend on plugin code loading.
$clara_ve_secret_options = array(
	'clara_ve_smtp_pass',
	'clara_ve_api_brevo',
	'clara_ve_api_sendgrid',
	'clara_ve_api_postmark',
	'clara_ve_api_mailgun',
);
foreach ( $clara_ve_secret_options as $clara_ve_option ) {
	delete_option( $clara_ve_option );
}

// ---- Always: this plugin's transients, whose names are prefixed.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_clara\_ve\_%' OR option_name LIKE '\_transient\_timeout\_clara\_ve\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// ---- Everything below is opt-in.
if ( '1' !== (string) get_option( 'clara_ve_remove_all_data', '' ) ) {
	return;
}

// Custom tables: page-edit history and the subscriber list.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clara_ve_history" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}clara_ve_optins" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Form submissions (CPT) with their meta.
$clara_ve_submissions = get_posts(
	array(
		'post_type'      => 'clara_ve_submission',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $clara_ve_submissions as $clara_ve_post_id ) {
	wp_delete_post( $clara_ve_post_id, true );
}

// Per-page post meta the plugin stamped onto ordinary Pages/Posts. The Pages
// themselves are the owner's content and stay.
foreach ( array( '_clara_ve_key', '_clara_ve_seo', '_clara_ve_noindex' ) as $clara_ve_meta_key ) {
	delete_post_meta_by_key( $clara_ve_meta_key );
}

// Every remaining clara_ve_* option — includes the per-page clara_ve_source__*
// and clara_ve_pseudo__* rows, which no fixed list can enumerate.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'clara\_ve\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
