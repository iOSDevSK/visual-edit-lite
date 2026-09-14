<?php
/** Run rendering/security tests with real WP libraries, without a database. */
$wordpress = isset( $argv[1] ) ? realpath( $argv[1] ) : false;
if ( ! $wordpress || ! is_file( $wordpress . '/wp-includes/functions.php' ) ) {
	fwrite( STDERR, "Usage: php tests/standalone-extras.php /path/to/wordpress\n" );
	exit( 1 );
}
define( 'ABSPATH', $wordpress . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_DEBUG', true );
require ABSPATH . WPINC . '/compat.php';
require ABSPATH . WPINC . '/plugin.php';
require ABSPATH . WPINC . '/load.php';
require ABSPATH . WPINC . '/class-wp-error.php';
// Translation loading needs a site's database; messages are not under test.
function __( $text, $domain = 'default' ) { return $text; }
require ABSPATH . WPINC . '/functions.php';
require ABSPATH . WPINC . '/formatting.php';
require ABSPATH . WPINC . '/kses.php';
require ABSPATH . WPINC . '/class-wp-block-type.php';
require ABSPATH . WPINC . '/class-wp-block-type-registry.php';
if ( is_file( ABSPATH . WPINC . '/class-wp-token-map.php' ) ) {
	require ABSPATH . WPINC . '/class-wp-token-map.php';
}
require ABSPATH . WPINC . '/html-api/html5-named-character-references.php';
spl_autoload_register( static function ( $class ) {
	if ( 0 !== strpos( $class, 'WP_HTML_' ) ) { return; }
	$file = ABSPATH . WPINC . '/html-api/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );
add_filter( 'pre_option_blog_charset', static function () { return 'UTF-8'; } );
require dirname( __DIR__ ) . '/includes/class-block-supports.php';
require dirname( __DIR__ ) . '/includes/class-responsive.php';
require dirname( __DIR__ ) . '/includes/class-block-extras.php';
require dirname( __DIR__ ) . '/includes/class-block-patch.php';
WP_Block_Type_Registry::get_instance()->register( 'core/paragraph', array() );
require __DIR__ . '/regression-block-extras.php';
