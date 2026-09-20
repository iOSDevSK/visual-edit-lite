<?php
/**
 * Form blocks against a real WordPress with the plugin active:
 *   php tests/form-blocks-wp.php /path/to/wordpress
 * Creates no content: submissions it makes are deleted, settings are only filtered.
 */
$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/form-blocks-wp.php /path/to/wordpress\n" );
	exit( 2 );
}
define( 'WP_USE_THEMES', false );
require $root . '/wp-load.php';
$failures = 0;
$check    = static function ( $ok, $message ) use ( &$failures ) {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL: $message\n" );
	}
};

$registry = WP_Block_Type_Registry::get_instance();
foreach ( array( 'clara-ve/form', 'clara-ve/field', 'clara-ve/textarea', 'clara-ve/select', 'clara-ve/checkbox', 'clara-ve/form-group', 'clara-ve/submit' ) as $name ) {
	$check( $registry->is_registered( $name ), "$name is registered" );
}
$check( isset( $registry->get_registered( 'clara-ve/select' )->attributes['options'] ), 'the choice list stores its options' );

$saved = '<div class="wp-block-clara-ve-form ar-form"><form class="form" data-demo><div class="field"><label for="c-name">Name</label><input id="c-name" name="names" required/></div><button type="submit" class="btn">Send</button></form></div>';
$html  = Clara_VE_Form_Blocks::render_form( array( 'formId' => 'contact', 'redirect' => '/thanks/', 'message' => 'Got it "quoted"' ), $saved );
$check( false !== strpos( $html, 'action="' . esc_url( rest_url( 'clara-ve/v1/form-submit' ) ) . '"' ), 'the form posts to the block endpoint' );
$check( 1 === preg_match( '~name="clara_ve_nonce" value="[a-f0-9]+"~', $html ) && false !== strpos( $html, 'name="cve_ts"' ) && false !== strpos( $html, 'name="cve_hp"' ), 'origin token, time-trap and honeypot are injected' );
$check( false !== strpos( $html, 'name="form_id" value="contact"' ), 'submissions are grouped by the form id' );
$check( false === strpos( $html, 'data-demo' ), 'a theme demo marker is removed' );
$check( false !== strpos( $html, 'data-cve-thanks="Got it &quot;quoted&quot;"' ), 'the form message is carried, escaped' );
$check( false !== strpos( $html, 'name="redirect" value="' . esc_attr( home_url( '/thanks/' ) ) . '"' ), 'a path redirect becomes a same-site address' );
$check( false !== strpos( $html, '<label for="c-name">Name</label>' ), 'the saved fields are untouched' );

// The callback's return is printed by WordPress, so it is bounded by an
// allowlist at the point of return. What that must NOT do is change a form:
// every field the block saved, and every hidden one the plugin adds, has to
// come out the other side — a stripped honeypot is a spam filter switched off,
// and a stripped <input> is a form nobody can fill in.
$allowed = Clara_VE_Forms::allowed_form_html();
$check( $html === wp_kses( $html, $allowed ), 'the rendered form is already inside the allowlist (filtering it twice changes nothing)' );
$hp = preg_match( '~<input[^>]*name="cve_hp"[^>]*>~', $html, $hp_tag ) ? $hp_tag[0] : '';
$check( false !== strpos( $hp, 'class="cve-hp"' ) && false !== strpos( $hp, 'tabindex="-1"' ) && false !== strpos( $hp, 'autocomplete="off"' ) && false !== strpos( $hp, 'aria-hidden="true"' ), 'the honeypot keeps the attributes that keep people out of it (got: ' . $hp . ')' );
foreach ( array( 'clara_ve_nonce', 'form_id', 'to', 'redirect', 'form_type', 'list_id', 'cve_delivery', 'cve_ts' ) as $hidden_name ) {
	$check( 1 === preg_match( '~<input type="hidden" name="' . $hidden_name . '"~', $html ), "the hidden field $hidden_name survives the allowlist" );
}
$saved_controls = preg_match_all( '~<(input|select|textarea|button|option|label)\b~', $saved );
$out_controls   = preg_match_all( '~<(input|select|textarea|button|option|label)\b~', $html );
$check( $out_controls === $saved_controls + 9, "every saved control is still there, plus the nine the plugin adds (saved $saved_controls, rendered $out_controls)" );
$hostile = Clara_VE_Form_Blocks::render_form( array( 'formId' => 'x' ), '<form><input name="a" onfocus="alert(1)"><script>alert(2)</script><iframe src="https://elsewhere.invalid"></iframe><button type="submit">Go<svg viewBox="0 0 8 8" onload="alert(3)"><path d="M0 0h8"/></svg></button></form>' );
$check( false === stripos( $hostile, 'onfocus' ) && false === stripos( $hostile, 'onload' ) && false === stripos( $hostile, '<script' ) && false === stripos( $hostile, '<iframe' ), 'event handlers, scripts and frames do not come out of the callback' );
$check( false !== strpos( $hostile, '<path d="M0 0h8"' ) && false !== strpos( $hostile, 'name="a"' ), 'while the field and the icon in the button do' );
$foreign = Clara_VE_Form_Blocks::render_form( array( 'redirect' => 'https://elsewhere.invalid/x' ), $saved );
$check( false !== strpos( $foreign, 'name="redirect" value=""' ), 'a redirect to another site is dropped' );
$check( '<p>No form</p>' === Clara_VE_Form_Blocks::render_form( array(), '<p>No form</p>' ), 'markup without a form is returned as is' );

// Submitting: the recipient and the form type are never taken from the request.
$mail = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mail ) { $mail[] = $atts; return true; }, 10, 2 );
add_filter( 'pre_option_' . Clara_VE_Form_Settings::OPT_MIN_SECONDS, static function () { return '0'; } );
$token = new ReflectionMethod( 'Clara_VE_Forms', 'origin_token' );
$token->setAccessible( true );
$request = new WP_REST_Request( 'POST', '/clara-ve/v1/form-submit' );
$request->set_body_params( array( 'clara_ve_nonce' => $token->invoke( null ), 'form_id' => 'qa-form-blocks-test', 'names' => 'Test', 'email' => 'visitor@example.org', 'to' => 'attacker@example.invalid', 'form_type' => 'list', 'list_id' => 'x', 'cve_hp' => '' ) );
$request->set_query_params( array( 'to' => 'attacker2@example.invalid' ) );
$response = Clara_VE_Form_Blocks::submit( $request );
$check( ! is_wp_error( $response ) && 200 === $response->get_status(), 'a valid submission is accepted' );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( in_array( Clara_VE_Form_Settings::recipient( '' ), $recipients, true ), 'the owner is notified at the Form Settings address' );
$check( ! preg_grep( '~attacker~', $recipients ), 'a posted recipient is ignored' );
$stored = get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'numberposts' => 5, 'title' => '', 's' => 'qa-form-blocks-test' ) );
$check( 1 === count( $stored ) && 'Test' === get_post_meta( $stored[0]->ID, 'names', true ), 'the submission is stored with its fields' );
foreach ( $stored as $post ) {
	wp_delete_post( $post->ID, true );
}
// The delivery the OWNER chose travels in the markup, so it is signed: the
// endpoint honours the recipient and the list this site rendered, and nothing
// else. Without that, "Send to" is a mail relay and "List" writes into the
// owner's contacts on request.
$chosen = Clara_VE_Form_Blocks::render_form(
	array( 'formId' => 'qa-form-blocks-test', 'formType' => 'contact', 'recipient' => 'studio@example.invalid' ),
	$saved
);
preg_match_all( '~name="([a-z_]+)" value="([^"]*)"~', $chosen, $pairs );
$fields = array_combine( $pairs[1], $pairs[2] );
$check( ! empty( $fields['cve_delivery'] ), 'the delivery choice is signed into the form' );
$check( 'studio@example.invalid' === ( $fields['to'] ?? '' ), 'and carries the recipient this form names' );

$mail    = array();
$signed  = new WP_REST_Request( 'POST', '/clara-ve/v1/form-submit' );
$signed->set_body_params( array_merge( $fields, array( 'names' => 'Test', 'email' => 'visitor@example.org' ) ) );
$response = Clara_VE_Form_Blocks::submit( $signed );
$check( ! is_wp_error( $response ) && 200 === $response->get_status(), 'a form with its own recipient is accepted' );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( in_array( 'studio@example.invalid', $recipients, true ), 'and the notification goes where the form says' );

$mail   = array();
$forged = new WP_REST_Request( 'POST', '/clara-ve/v1/form-submit' );
$forged->set_body_params( array_merge( $fields, array( 'names' => 'Test', 'email' => 'visitor@example.org', 'to' => 'attacker@example.invalid' ) ) );
$response = Clara_VE_Form_Blocks::submit( $forged );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( ! preg_grep( '~attacker~', $recipients ), 'a recipient that does not match the signature is refused' );
$check( in_array( Clara_VE_Form_Settings::recipient( '' ), $recipients, true ), 'and the site address is used instead' );

$signup = Clara_VE_Form_Blocks::render_form(
	array( 'formId' => 'qa-form-blocks-test', 'formType' => 'list', 'listId' => '7' ),
	$saved
);
$check( false !== strpos( $signup, 'name="form_type" value="list"' ) && false !== strpos( $signup, 'name="list_id" value="7"' ), 'a signup form carries its list' );
$check( false === strpos( $signup, 'name="to" value="studio' ), 'a signup form names no recipient' );

foreach ( get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'numberposts' => 20, 's' => 'qa-form-blocks-test' ) ) as $post ) {
	wp_delete_post( $post->ID, true );
}

$bad = new WP_REST_Request( 'POST', '/clara-ve/v1/form-submit' );
$bad->set_body_params( array( 'clara_ve_nonce' => 'nope', 'names' => 'x' ) );
$check( is_wp_error( Clara_VE_Form_Blocks::submit( $bad ) ), 'a submission without the origin token is refused' );

// The same signature on the [wp-form] token path: an HTML theme's form is
// connected by the same renderer, so "Send to" and "List" are equally visible
// in the page source and equally forgeable without it.
$token_form = Clara_VE_Tokens::connect_form(
	array( 'id' => 'qa-form-blocks-test', 'to' => 'studio@example.invalid', 'type' => 'contact' ),
	'<form class="form"><input name="email" type="email"><button type="submit">Send</button></form>'
);
preg_match_all( '~name="([a-z_]+)" value="([^"]*)"~', $token_form, $pairs );
$token_fields = array_combine( $pairs[1], $pairs[2] );
$check( ! empty( $token_fields['cve_delivery'] ), 'a token form signs its delivery choice too' );

$mail  = array();
$sends = static function ( $params ) {
	$request = new WP_REST_Request( 'POST', '/clara-ve/v1/submit' );
	$request->set_body_params( $params );
	return Clara_VE_Forms::handle_submit( $request );
};
$sends( array_merge( $token_fields, array( 'email' => 'visitor@example.org' ) ) );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( in_array( 'studio@example.invalid', $recipients, true ), 'a signed token form reaches the address it names' );

$mail = array();
$sends( array_merge( $token_fields, array( 'email' => 'visitor@example.org', 'to' => 'attacker@example.invalid' ) ) );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( ! preg_grep( '~attacker~', $recipients ), 'a retyped recipient on the token path is refused' );
$check( in_array( Clara_VE_Form_Settings::recipient( '' ), $recipients, true ), 'and falls back to Form Settings' );

// Signing a delivery choice is the site vouching for it, so only a page whose
// author administers the site carries one: an Author could otherwise publish a
// form that mails its answers to them, or writes into the owner's list.
require_once ABSPATH . 'wp-admin/includes/user.php';
$author_id = username_exists( 'qa-form-blocks-author' );
if ( ! $author_id ) {
	$author_id = wp_insert_user( array( 'user_login' => 'qa-form-blocks-author', 'user_pass' => wp_generate_password(), 'role' => 'author' ) );
}
$cap_content = '<!-- wp:clara-ve/form {"formId":"qa-cap","recipient":"author@example.invalid"} -->' . $saved . '<!-- /wp:clara-ve/form -->';
$rendered_by = static function ( $user_id ) use ( $cap_content ) {
	$pid = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'qa-form-blocks-cap', 'post_content' => $cap_content, 'post_author' => $user_id ) );
	global $post, $wp_query;
	$post = get_post( $pid ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
	setup_postdata( $post );
	$wp_query->queried_object    = $post;
	$wp_query->queried_object_id = $pid;
	$html = do_blocks( $cap_content );
	wp_reset_postdata();
	wp_delete_post( $pid, true );
	return $html;
};
$check( false === strpos( $rendered_by( $author_id ), 'author@example.invalid' ), "an Author's page does not carry a signed recipient" );
$check( false !== strpos( $rendered_by( 1 ), 'author@example.invalid' ), "an administrator's does" );
wp_delete_user( $author_id );

// A converted theme (html2wp_theme_form_handle) forwards the RAW request body,
// so a recipient in it is not the theme's decision — it is whatever the browser
// sent. The same signature check therefore runs for that path too, and the
// filter is no longer a way around it.
$mail = array();
$delegated = new WP_REST_Request( 'POST', '/clara-ve/v1/submit' );
$delegated->set_body_params( array( 'clara_ve_nonce' => $token->invoke( null ), 'form_id' => 'qa-form-blocks-test', 'email' => 'visitor@example.org', 'to' => 'attacker@example.invalid', 'cve_hp' => '' ) );
// The filter only answers for a theme that owns the public runtime, so the
// test declares that support for the length of this one call.
add_theme_support( 'html2wp-runtime', array( 'schema' => 1 ) );
clara_ve_enhance_theme_form( null, array( 'form_id' => 'qa-form-blocks-test', 'params' => $delegated->get_body_params() ) );
remove_theme_support( 'html2wp-runtime' );
$recipients = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( ! preg_grep( '~attacker~', $recipients ), 'an unsigned recipient through the theme filter is refused' );
$check( in_array( Clara_VE_Form_Settings::recipient( '' ), $recipients, true ), 'and the submission still goes out, to Form Settings' );

// And the theme's own three-part cve_ts — "<time>.<flag>.<sig>", signed with
// the same wp_salt( 'auth' ) — verifies here. It did not, once, and every
// submission a converted theme forwarded was silently discarded before storage.
$stamp     = (string) ( time() - 30 );
$signed    = $stamp . '.1';
$theme_ts  = $signed . '.' . hash_hmac( 'sha256', $signed, wp_salt( 'auth' ) );
$verify    = new ReflectionMethod( 'Clara_VE_Forms', 'verify_timestamp' );
$verify->setAccessible( true );
$read = $verify->invoke( null, $theme_ts );
$check( is_array( $read ) && $read['elapsed'] >= 30, "a converted theme's three-part timestamp verifies" );
$own  = (string) ( time() - 10 );
$mine = $own . '.' . hash_hmac( 'sha256', $own, wp_salt( 'auth' ) );
$check( is_array( $verify->invoke( null, $mine ) ), "and the plugin's own two-part one still does" );
$check( false === $verify->invoke( null, $signed . '.deadbeef' ), 'a forged signature does not' );

foreach ( get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'numberposts' => 20, 's' => 'qa-form-blocks-test' ) ) as $post ) {
	wp_delete_post( $post->ID, true );
}

// With the plugin gone the saved form still shows, unconnected.
$content = '<!-- wp:clara-ve/form {"formId":"contact"} -->' . $saved . '<!-- /wp:clara-ve/form -->';
$registry->unregister( 'clara-ve/form' );
$plain = do_blocks( $content );
$check( false !== strpos( $plain, '<form class="form"' ) && false === strpos( $plain, 'action=' ), 'an unregistered form block renders its saved form' );

if ( $failures ) {
	exit( 1 );
}
echo "PASS: form blocks — registration, connected render, signed delivery, safe submit, stored submission and plugin-off markup\n";
