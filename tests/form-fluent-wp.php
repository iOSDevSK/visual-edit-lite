<?php
/**
 * A designed [wp-form] handed to Fluent Forms, against a real WordPress:
 *   php tests/form-fluent-wp.php /path/to/wordpress
 * Needs Fluent Forms active, with its "Contact Form Demo" (created on
 * activation: Name, Email, Subject, Message). Submits through the handler a
 * visitor reaches, on this plugin's runtime and through a converted theme's
 * forwarding filter, and deletes the entries it made.
 */
$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/form-fluent-wp.php /path/to/wordpress\n" );
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
if ( ! Clara_VE_Form_Handlers::available( 'fluentform' ) ) {
	fwrite( STDERR, "Fluent Forms is not active.\n" );
	exit( 2 );
}
global $wpdb;

add_filter( 'pre_wp_mail', '__return_true' );
add_filter( 'pre_option_' . Clara_VE_Form_Settings::OPT_MIN_SECONDS, static function () { return '0'; } );
$_SERVER['HTTP_X_CLARA_VE_INLINE'] = '1';
$_SERVER['REMOTE_ADDR']            = '203.0.113.' . wp_rand( 2, 250 );
$_SERVER['HTTP_USER_AGENT']        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
$rate_key                          = 'clara_ve_form_rl_' . md5( $_SERVER['REMOTE_ADDR'] );

$demo = null;
foreach ( Clara_VE_Form_Handlers::forms( 'fluentform' ) as $form ) {
	if ( 'Contact Form Demo' === $form['title'] ) {
		$demo = $form['id'];
	}
}
$check( null !== $demo, 'Fluent Forms offers its Contact Form Demo' );
if ( null === $demo ) {
	exit( 1 );
}

$design  = '<form class="contact-grid" novalidate><div class="field"><label for="n">Name</label><input id="n" name="name"></div>'
	. '<div class="field"><label for="e">Email</label><input id="e" type="email" name="email"></div>'
	. '<div class="field"><label for="s">Subject</label><input id="s" name="subject"></div>'
	. '<div class="field"><label for="m">Message</label><textarea id="m" name="message"></textarea></div><button type="submit">Send</button></form>';
$ours    = array(
	array( 'name' => 'name', 'type' => 'text', 'label' => 'Name' ),
	array( 'name' => 'email', 'type' => 'email', 'label' => 'Email' ),
	array( 'name' => 'subject', 'type' => 'text', 'label' => 'Subject' ),
	array( 'name' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
);
$mapping = Clara_VE_Form_Handlers::map_fields( $ours, Clara_VE_Form_Handlers::form_fields( 'fluentform', $demo ) );
$matched = $mapping['map'];
ksort( $matched );
$check( array( 'email' => 'email', 'message' => 'message', 'name' => 'names', 'subject' => 'subject' ) === $matched, "our fields match Fluent's, its Name field by label (" . wp_json_encode( $mapping['map'] ) . ')' );
$list = $demo . '|' . implode( ',', array_map( static function ( $k, $v ) { return $k . '=' . $v; }, array_keys( $mapping['map'] ), $mapping['map'] ) );
$page = static function () use ( $design, $list ) {
	return "<!-- wp:html -->\n<!-- clara-ve-key: qa-ff -->\n<section>[wp-form id=\"qa-ff\" type=\"fluentform\" list=\"" . $list . '"]' . $design . "[/wp-form]</section>\n<!-- /wp:html -->";
};
$hidden = static function ( $html ) {
	preg_match( '~<form\b.*?</form>~s', $html, $form );
	preg_match_all( '~<input type="hidden" name="([a-z_]+)" value="([^"]*)"~', $form ? $form[0] : '', $pairs );
	return array_combine( $pairs[1], array_map( 'html_entity_decode', $pairs[2] ) );
};
$send    = static function ( $params ) {
	$request = new WP_REST_Request( 'POST', '/clara-ve/v1/submit' );
	$request->set_body_params( $params );
	return Clara_VE_Forms::handle_submit( $request );
};
$entries = static function () use ( $wpdb, $demo ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fluentform_submissions WHERE form_id = %d", $demo ) );
};
$first   = (int) $wpdb->get_var( "SELECT COALESCE(MAX(id),0) FROM {$wpdb->prefix}fluentform_submissions" );
$visitor = array( 'name' => 'Ana Visitor', 'email' => 'ana@example.org', 'subject' => 'A print', 'message' => 'Hello there, about a print.', 'cve_hp' => '' );
// Fluent keeps its own per-visitor limit; entries this script makes are one visitor.
add_filter( 'fluentform/is_form_submission_restricted', '__return_false' );

$runtime_support = get_theme_support( 'html2wp-runtime' );
$theme_runtime   = false !== $runtime_support;
remove_theme_support( 'html2wp-runtime' );
$render = static function ( $content ) {
	return Clara_VE_Tokens::hydrate( Clara_VE_Form_Handlers::prepare_block( $content, array() ) );
};

echo "--- on this plugin's runtime ---\n";
$fields = $hidden( $render( $page() ) );
$check( 'fluentform' === ( $fields['form_type'] ?? '' ) && ! empty( $fields['cve_delivery'] ), 'the form renders connected, its handoff signed' );
$before = $entries();
$reply  = $send( array_merge( $fields, $visitor ) );
$data   = $reply instanceof WP_REST_Response ? $reply->get_data() : array();
$check( ! empty( $data['ok'] ) && '' !== ( $data['message'] ?? '' ), "Fluent Forms accepts it, and its confirmation is the answer (" . ( $data['message'] ?? ( is_wp_error( $reply ) ? $reply->get_error_message() : '' ) ) . ')' );
$check( $entries() === $before + 1, 'Fluent stored it as an entry' );
$last = json_decode( (string) $wpdb->get_var( "SELECT response FROM {$wpdb->prefix}fluentform_submissions ORDER BY id DESC LIMIT 1" ), true );
$check( 'Ana' === ( $last['names']['first_name'] ?? '' ) && 'Visitor' === ( $last['names']['last_name'] ?? '' ) && 'ana@example.org' === ( $last['email'] ?? '' ), 'our one name field filled its first and last name' );
$check( ! get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'post_status' => 'any', 'numberposts' => 1, 's' => 'qa-ff' ) ), 'and Visual Edit stored nothing of its own' );
delete_transient( $rate_key );

$reply  = $send( array_merge( $fields, $visitor, array( 'email' => 'not-an-address', 'message' => '' ) ) );
$errors = is_wp_error( $reply ) ? (array) ( $reply->get_error_data()['errors'] ?? array() ) : array();
$check( is_wp_error( $reply ) && 'clara_ve_form_invalid' === $reply->get_error_code() && ! empty( $errors['email'] ) && ! empty( $errors['message'] ), 'an invalid one is refused, its reasons under OUR field names (' . wp_json_encode( $errors ) . ')' );
$check( false === get_transient( $rate_key ), 'and the visitor may correct it and send again at once' );

// hCaptcha set up in Fluent (the global autoload, keys verified): the form
// names it for the page, and the page's token reaches Fluent, which checks it
// with hCaptcha — answered here by a stub, as siteverify would.
$saved_options = array();
foreach ( array( '_fluentform_global_form_settings', '_fluentform_hCaptcha_details', '_fluentform_hCaptcha_keys_status' ) as $option ) {
	$saved_options[ $option ] = get_option( $option, null );
}
$global         = (array) get_option( '_fluentform_global_form_settings', array() );
$global['misc'] = array_merge( (array) ( $global['misc'] ?? array() ), array( 'autoload_captcha' => true, 'captcha_type' => 'hcaptcha' ) );
update_option( '_fluentform_global_form_settings', $global );
update_option( '_fluentform_hCaptcha_details', array( 'siteKey' => 'qa-site-key', 'secretKey' => 'qa-secret' ) );
update_option( '_fluentform_hCaptcha_keys_status', true );
$hcaptcha = static function ( $pre, $args, $url ) {
	if ( false === strpos( (string) $url, 'hcaptcha.com/siteverify' ) ) {
		return $pre;
	}
	$good = isset( $args['body']['response'] ) && 'token-good' === $args['body']['response'];
	return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'body' => wp_json_encode( array( 'success' => $good ) ) );
};
add_filter( 'pre_http_request', $hcaptcha, 10, 3 );
$html = $render( $page() );
$check( false !== strpos( html_entity_decode( $html ), '"provider":"hcaptcha","siteKey":"qa-site-key"' ) && false === strpos( $html, 'qa-secret' ), 'the form names the captcha Fluent checks — its public key only' );
delete_transient( $rate_key );
$before = $entries();
$reply  = $send( array_merge( $fields, $visitor, array( 'cve_captcha' => 'token-good' ) ) );
$check( ! is_wp_error( $reply ) && $entries() === $before + 1, "the page's token passes Fluent's hCaptcha check" . ( is_wp_error( $reply ) ? ' (' . $reply->get_error_message() . ')' : '' ) );
delete_transient( $rate_key );
$reply = $send( array_merge( $fields, $visitor, array( 'cve_captcha' => 'token-bad' ) ) );
$check( is_wp_error( $reply ) && 'clara_ve_form_refused' === $reply->get_error_code() && $entries() === $before + 1, 'a token hCaptcha rejects is refused, nothing stored' . ( is_wp_error( $reply ) ? ' (' . $reply->get_error_message() . ')' : '' ) );
delete_transient( $rate_key );
remove_filter( 'pre_http_request', $hcaptcha, 10 );
foreach ( $saved_options as $option => $value ) {
	null === $value ? delete_option( $option ) : update_option( $option, $value );
}

if ( $theme_runtime ) {
	echo "--- on a converted theme's own runtime ---\n";
	add_theme_support( 'html2wp-runtime', ...( is_array( $runtime_support ) ? $runtime_support : array() ) );
	$html   = do_blocks( $page() );
	$fields = $hidden( $html );
	$check( 1 === substr_count( $html, 'name="cve_delivery"' ) && 'fluentform' === ( $fields['form_type'] ?? '' ), 'the theme renders the token, signed exactly once' );
	$before = $entries();
	$reply  = clara_ve_enhance_theme_form( null, array( 'form_id' => 'qa-ff', 'fields' => array(), 'params' => array_merge( $fields, $visitor ), 'ip' => $_SERVER['REMOTE_ADDR'] ) );
	$check( $reply instanceof WP_REST_Response && $entries() === $before + 1, "the theme's forwarded submission becomes a Fluent entry" );
	delete_transient( $rate_key );
} else {
	echo "  --   the active theme has no runtime of its own: theme leg skipped\n";
}

$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fluentform_submissions WHERE id > %d", $first ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}fluentform_entry_details WHERE submission_id > %d", $first ) );

if ( $failures ) {
	fwrite( STDERR, "FAIL: $failures assertion(s)\n" );
	exit( 1 );
}
echo 'PASS: a designed form handed to Fluent Forms — validated and stored by Fluent, errors under our fields, its hCaptcha answered' . ( $theme_runtime ? ", and through a converted theme's runtime" : '' ) . "\n";
