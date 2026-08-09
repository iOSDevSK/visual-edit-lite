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
