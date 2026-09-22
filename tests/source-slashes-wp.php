<?php
/**
 * A page keeps every backslash it was saved with, against a real WordPress:
 *   php tests/source-slashes-wp.php /path/to/wordpress
 * wp_insert_post() and wp_update_post() unslash what they are given. A
 * converted form's recorded messages are JSON inside an attribute
 * (data-spa-success="{&quot;html&quot;:&quot;<p class=\&quot;…\&quot;>…"), and
 * a page mirror written without wp_slash() lost those backslashes on every
 * save and import, leaving JSON nothing could read. Creates a page and its
 * copy, and deletes both.
 */
$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/source-slashes-wp.php /path/to/wordpress\n" );
	exit( 2 );
}
define( 'WP_USE_THEMES', false );
require $root . '/wp-load.php';
$failures = 0;
$check    = static function ( $ok, $message ) use ( &$failures ) {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL: $message\n" );
	} else {
		echo "  ok   $message\n";
	}
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
wp_set_current_user( $admins ? (int) $admins[0] : 1 );

$recorded = '{&quot;kind&quot;:&quot;inline&quot;,&quot;html&quot;:&quot;&lt;p class=\\&quot;thanks\\&quot;&gt;Thanks \\u2014 soon.&lt;/p&gt;&quot;}';
$markup   = '<section><form data-spa-success="' . $recorded . '"><input name="email"></form><script>var re = /\\d+\\.\\d+/;</script></section>';
$key      = 'qa-slashes-' . strtolower( wp_generate_password( 6, false, false ) );

$page = Clara_VE_Source_Store::create_or_update_page( $key, 'QA slashes', $key, $markup );
$check( is_int( $page ) && $page > 0, 'a page for the key' );
$stored = (string) get_post( $page )->post_content;
$check( false !== strpos( $stored, $recorded ), 'the recorded JSON reaches the page with its backslashes' );
$check( false !== strpos( $stored, '/\\d+\\.\\d+/' ), 'and so does a script\'s regular expression' );
$decoded = json_decode( html_entity_decode( preg_match( '/data-spa-success="([^"]*)"/', $stored, $m ) ? $m[1] : '' ), true );
$check( is_array( $decoded ) && '<p class="thanks">Thanks — soon.</p>' === ( $decoded['html'] ?? null ), 'which still decodes to the recorded message' );

Clara_VE_Source_Store::save_source( $key, $markup . '<p>edited</p>' );
$check( false !== strpos( (string) get_post( $page )->post_content, $recorded ), 'a later save keeps them too' );

$copy = Clara_VE_Page_Actions::duplicate( $page, 'QA slashes copy', $key . '-copy' );
$copy_id = is_array( $copy ) ? (int) $copy['id'] : 0;
$check( $copy_id && false !== strpos( (string) get_post( $copy_id )->post_content, $recorded ), 'and a duplicate of the page' );

foreach ( array( $page, $copy_id ) as $id ) {
	if ( $id ) {
		$id_key = (string) get_post_meta( $id, CLARA_VE_PAGE_KEY_META, true );
		wp_delete_post( $id, true );
		if ( '' !== $id_key ) {
			Clara_VE_Source_Store::reset( $id_key );
		}
	}
}

if ( $failures ) {
	fwrite( STDERR, "FAIL: $failures assertion(s)\n" );
	exit( 1 );
}
echo "PASS: page content keeps its backslashes — create, save and duplicate\n";
