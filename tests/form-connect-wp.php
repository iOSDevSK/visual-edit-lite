<?php
/**
 * Another plugin's form, delivered by Visual Edit, against a real WordPress:
 *   php tests/form-connect-wp.php /path/to/wordpress
 * Needs Kadence Blocks active for the live AJAX leg; the hook leg runs without it.
 * Creates two pages and their submissions, and deletes them again.
 */
$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/form-connect-wp.php /path/to/wordpress\n" );
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

$mail = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mail ) { $mail[] = $atts; return true; }, 10, 2 );
$recipients = static function () use ( &$mail ) {
	return array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
};
$stored = static function ( $form_id ) {
	return get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'numberposts' => 10, 'post_status' => 'any', 's' => $form_id ) );
};
$cleanup = static function ( $posts ) {
	foreach ( $posts as $post ) {
		wp_delete_post( $post->ID, true );
	}
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin = $admin ? $admin[0]->ID : 1;
$uid   = 'qa' . wp_generate_password( 6, false, false );
$block = static function ( $delivery ) use ( $uid ) {
	$attrs = array(
		'uniqueID' => $uid,
		'fields'   => array( array( 'label' => 'E-mail address', 'type' => 'email', 'required' => false, 'multiSelect' => false ), array( 'label' => 'Name', 'type' => 'text', 'required' => false, 'multiSelect' => false ) ),
		'actions'  => array(),
	);
	if ( $delivery ) {
		$attrs['claraVe'] = array( 'delivery' => $delivery );
	}
	return '<!-- wp:kadence/form ' . wp_json_encode( $attrs ) . ' --><div class="wp-block-kadence-form kadence-form-' . $uid . ' kb-form-wrap"><form class="kb-form" action="" method="post"></form></div><!-- /wp:kadence/form -->';
};
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA form connect', 'post_author' => $admin, 'post_content' => $block( array( 'connect' => true, 'type' => 'contact', 'recipient' => 'studio@example.invalid' ) ) ) );
$attrs = parse_blocks( get_post( $page )->post_content )[0]['attrs'];
$fields = array( array( 'label' => 'E-mail address', 'type' => 'email', 'value' => 'visitor@example.org' ), array( 'label' => 'Name', 'type' => 'text', 'value' => 'Ana' ) );

echo "--- the hook ---\n";
$check( null === Clara_VE_Form_Connect::delivery( array() ), 'an unconnected block has no delivery' );
$check( 'studio@example.invalid' === Clara_VE_Form_Connect::delivery( $attrs )['recipient'], 'a connected block reads its saved recipient' );

do_action( 'kadence_blocks_form_submission', $attrs, $fields, $uid, $page );
$rows = $stored( 'kadence-' . strtolower( $uid ) );
$check( 1 === count( $rows ), 'a connected submission is stored under Form Submissions' );
$check( $rows && 'visitor@example.org' === get_post_meta( $rows[0]->ID, 'e-mail-address', true ), 'its fields keep their labels as names' );
$check( in_array( 'studio@example.invalid', $recipients(), true ), 'and it is emailed to the address chosen on the block' );
$cleanup( $rows );

$mail = array();
do_action( 'kadence_blocks_form_submission', parse_blocks( $block( null ) )[0]['attrs'], $fields, $uid, $page );
$check( 0 === count( $stored( 'kadence-' . strtolower( $uid ) ) ) && ! $mail, 'an unconnected form is left entirely to its own plugin' );

// Only a page whose author administers the site chooses where answers go.
require_once ABSPATH . 'wp-admin/includes/user.php';
$author = username_exists( 'qa-form-connect-author' ) ?: wp_create_user( 'qa-form-connect-author', wp_generate_password(), 'qa-form-connect-author@example.invalid' );
( new WP_User( $author ) )->set_role( 'author' );
$authored = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'QA form connect author', 'post_author' => $author, 'post_content' => $block( array( 'connect' => true, 'type' => 'contact', 'recipient' => 'author@example.invalid' ) ) ) );
$mail = array();
do_action( 'kadence_blocks_form_submission', parse_blocks( get_post( $authored )->post_content )[0]['attrs'], $fields, $uid, $authored );
$check( ! preg_grep( '~author@example~', $recipients() ), 'an Author cannot point a connected form at their own address' );
$check( in_array( Clara_VE_Form_Settings::recipient( '' ), $recipients(), true ), 'and it falls back to Form Settings' );
$cleanup( $stored( 'kadence-' . strtolower( $uid ) ) );

$http = class_exists( 'KB_Ajax_Form' ) || has_action( 'wp_ajax_nopriv_kb_process_ajax_submit' );
if ( $http ) {
	echo "--- Kadence's own submit, over HTTP ---\n";
	$mail = array();
	$response = wp_remote_post(
		getenv( 'AJAX_URL' ) ?: admin_url( 'admin-ajax.php' ),
		array(
			'timeout' => 20,
			'body'    => array(
				'action'           => 'kb_process_ajax_submit',
				'_kb_form_id'      => $uid,
				'_kb_form_post_id' => $page,
				'kb_field_0'       => 'http-visitor@example.org',
				'kb_field_1'       => 'Bo',
				'_kb_verify_email' => '',
			),
		)
	);
	$body = is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_body( $response );
	$check( ! is_wp_error( $response ) && false !== strpos( $body, 'success' ), 'Kadence accepts the submission (got: ' . substr( $body, 0, 120 ) . ')' );
	$rows = $stored( 'kadence-' . strtolower( $uid ) );
	$check( 1 === count( $rows ) && 'http-visitor@example.org' === get_post_meta( $rows[0]->ID, 'e-mail-address', true ), 'and Visual Edit stored it' );
	$cleanup( $rows );
} else {
	echo "  --   Kadence Blocks is not active: HTTP leg skipped\n";
}

wp_delete_post( $page, true );
wp_delete_post( $authored, true );
wp_delete_user( $author );

if ( $failures ) {
	fwrite( STDERR, "FAIL: $failures assertion(s)\n" );
	exit( 1 );
}
echo "PASS: another plugin's form, delivered by Visual Edit — stored, emailed to the chosen address, author-gated" . ( $http ? ", and over Kadence's own submit" : ' (HTTP leg skipped)' ) . "\n";
