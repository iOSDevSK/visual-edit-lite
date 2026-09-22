<?php
/**
 * A designed [wp-form] handed to Contact Form 7, against a real WordPress:
 *   php tests/form-cf7-wp.php /path/to/wordpress
 * Needs Contact Form 7 active (Flamingo too, for the storage leg). Creates a
 * CF7 form and a page, submits through the same handler a visitor reaches —
 * on this plugin's runtime and through a converted theme's forwarding filter —
 * and deletes everything again.
 */
$root = rtrim( $argv[1] ?? '', '/' );
if ( ! $root || ! is_file( $root . '/wp-load.php' ) ) {
	fwrite( STDERR, "Usage: php tests/form-cf7-wp.php /path/to/wordpress\n" );
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
if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	fwrite( STDERR, "Contact Form 7 is not active.\n" );
	exit( 2 );
}

$mail = array();
add_filter( 'pre_wp_mail', static function ( $short, $atts ) use ( &$mail ) { $mail[] = $atts; return true; }, 10, 2 );
// The time-trap is Clara_VE_Forms' own and has its own tests; here it would
// only make every submission wait.
add_filter( 'pre_option_' . Clara_VE_Form_Settings::OPT_MIN_SECONDS, static function () { return '0'; } );
$_SERVER['HTTP_X_CLARA_VE_INLINE'] = '1';
$_SERVER['REMOTE_ADDR']            = '203.0.113.' . wp_rand( 2, 250 );
// CF7 counts a missing or tiny User-Agent as spam; a visitor's browser sends one.
$_SERVER['HTTP_USER_AGENT']        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15';
$rate_key                          = 'clara_ve_form_rl_' . md5( $_SERVER['REMOTE_ADDR'] );

$cf7 = WPCF7_ContactForm::get_template( array( 'title' => 'QA VE connect' ) );
$cf7->set_properties( array( 'mail' => array_merge( $cf7->prop( 'mail' ), array( 'recipient' => 'cf7-owner@example.invalid' ) ) ) );
$cf7_id = (string) $cf7->save();
$check( (bool) WPCF7_ContactForm::get_instance( (int) $cf7_id ), 'a Contact Form 7 form to connect to' );

$design = '<form class="contact-grid" novalidate><div class="field"><label for="n">Name</label><input id="n" name="name"></div>'
	. '<div class="field"><label for="e">Email</label><input id="e" type="email" name="email"></div>'
	. '<div class="field"><label for="s">Subject</label><input id="s" name="subject"></div>'
	. '<div class="field"><label for="m">Message</label><textarea id="m" name="message"></textarea></div><button type="submit">Send</button></form>';
$list    = static function ( $id, $map = 'name=your-name,email=your-email,subject=your-subject,message=your-message' ) {
	return $id . '|' . $map;
};
$page    = static function ( $atts ) use ( $design ) {
	$token = '[wp-form';
	foreach ( $atts as $k => $v ) {
		$token .= ' ' . $k . '="' . $v . '"';
	}
	return "<!-- wp:html -->\n<!-- clara-ve-key: qa-cf7 -->\n<section>" . $token . ']' . $design . "[/wp-form]</section>\n<!-- /wp:html -->";
};
$hidden  = static function ( $html ) {
	preg_match( '~<form\b.*?</form>~s', $html, $form );
	preg_match_all( '~<input type="hidden" name="([a-z_]+)" value="([^"]*)"~', $form ? $form[0] : '', $pairs );
	return array_combine( $pairs[1], array_map( 'html_entity_decode', $pairs[2] ) );
};
// CF7 processes one submission per request (WPCF7_Submission is a
// singleton); a real request is one submission, this script is many.
$fresh   = new ReflectionProperty( 'WPCF7_Submission', 'instance' );
$fresh->setAccessible( true );
$send    = static function ( $params ) use ( $fresh ) {
	$fresh->setValue( null, null );
	$request = new WP_REST_Request( 'POST', '/clara-ve/v1/submit' );
	$request->set_body_params( $params );
	return Clara_VE_Forms::handle_submit( $request );
};
$inbound = static function () {
	return post_type_exists( 'flamingo_inbound' ) ? count( get_posts( array( 'post_type' => 'flamingo_inbound', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) ) : 0;
};
$ours    = static function () {
	return get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'post_status' => 'any', 'numberposts' => -1, 's' => 'qa-cf7' ) );
};
$inbound_ids = static function () {
	// Flamingo's messages and the address-book entries it adds with them.
	return get_posts( array( 'post_type' => array( 'flamingo_inbound', 'flamingo_contact' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) );
};
$inbound_before = $inbound_ids();
$visitor = array( 'name' => 'Ana Visitor', 'email' => 'ana@example.org', 'subject' => 'A print', 'message' => 'Hello there, about a print.', 'cve_hp' => '' );

// The theme a real site runs decides who renders the token; this test renders
// through both, so it declares or withdraws the runtime support itself.
$theme_runtime = current_theme_supports( 'html2wp-runtime' );
$runtime_support = get_theme_support( 'html2wp-runtime' );
remove_theme_support( 'html2wp-runtime' );
$render_plugin = static function ( $content ) {
	return Clara_VE_Tokens::hydrate( Clara_VE_Form_Handlers::prepare_block( $content, array() ) );
};

echo "--- on this plugin's runtime ---\n";
$content = $page( array( 'id' => 'qa-cf7', 'type' => 'cf7', 'list' => $list( $cf7_id ) ) );
$html    = $render_plugin( $content );
$fields  = $hidden( $html );
$check( 'cf7' === ( $fields['form_type'] ?? '' ) && ! empty( $fields['cve_delivery'] ), 'the form renders connected, its handoff signed' );
$check( 1 === substr_count( $html, 'name="cve_delivery"' ), 'with one signature — the plugin runtime signs it once' );
$check( wp_script_is( 'clara-ve-form-handler', 'enqueued' ), 'and the script that sends it is on the page' );

$mail   = array();
$before = $inbound();
$reply  = $send( array_merge( $fields, $visitor ) );
$data   = $reply instanceof WP_REST_Response ? $reply->get_data() : array();
$check( ! is_wp_error( $reply ) && ! empty( $data['ok'] ) && '' !== ( $data['message'] ?? '' ), 'Contact Form 7 accepts it, and its own message is the answer (' . ( $data['message'] ?? ( is_wp_error( $reply ) ? $reply->get_error_message() : '' ) ) . ')' );
$to = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( in_array( 'cf7-owner@example.invalid', $to, true ), "CF7 mailed it to the CF7 form's own recipient" );
$check( $mail && false !== strpos( $mail[0]['subject'], 'A print' ) && false !== strpos( $mail[0]['message'], 'Hello there' ), 'with our fields under its names' );
$check( ! post_type_exists( 'flamingo_inbound' ) || $inbound() === $before + 1, 'Flamingo stored it' . ( post_type_exists( 'flamingo_inbound' ) ? '' : ' (Flamingo not active: skipped)' ) );
$check( ! $ours(), 'and Visual Edit stored nothing of its own — the plugin keeps the record' );
delete_transient( $rate_key );

$mail  = array();
$reply = $send( array_merge( $fields, $visitor, array( 'email' => 'not-an-address', 'name' => '' ) ) );
$check( is_wp_error( $reply ) && 'clara_ve_form_invalid' === $reply->get_error_code() && ! $mail, 'an invalid submission is refused by CF7, nothing sent' );
$errors = is_wp_error( $reply ) ? (array) ( $reply->get_error_data()['errors'] ?? array() ) : array();
$check( ! empty( $errors['email'] ) && ! empty( $errors['name'] ), 'its reasons come back under OUR field names (' . wp_json_encode( $errors ) . ')' );
$check( is_wp_error( $reply ) && 400 === ( $reply->get_error_data()['status'] ?? 0 ), 'as a 400' );
$check( false === get_transient( $rate_key ), 'and the visitor may correct it and send again at once' );

$reply = $send( array_merge( $fields, $visitor ) );
$check( ! is_wp_error( $reply ), 'the corrected submission goes through' );
$reply = $send( array_merge( $fields, $visitor ) );
$check( is_wp_error( $reply ) && 'clara_ve_rate_limited' === $reply->get_error_code(), 'while an accepted one still starts the rate limit' );
delete_transient( $rate_key );

// A reCAPTCHA v3 token the page obtained reaches CF7 where it reads one.
$seen = null;
$spy  = static function ( $spam ) use ( &$seen ) {
	$seen = $_POST['_wpcf7_recaptcha_response'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	return $spam;
};
add_filter( 'wpcf7_spam', $spy, 1 );
$_POST = array( 'untouched' => '1' );
$send( array_merge( $fields, $visitor, array( '_wpcf7_recaptcha_response' => 'token-abc' ) ) );
remove_filter( 'wpcf7_spam', $spy, 1 );
$check( 'token-abc' === $seen, 'a reCAPTCHA token is passed to CF7 as _wpcf7_recaptcha_response' );
$check( array( 'untouched' => '1' ) === $_POST, 'and the request is left as it was' );
$_POST = array();
delete_transient( $rate_key );

// A required CF7 field none of ours fills: CF7's reason cannot sit under a
// field, so it joins the message.
$unfilled = $hidden( $render_plugin( $page( array( 'id' => 'qa-cf7', 'type' => 'cf7', 'list' => $list( $cf7_id, 'name=your-name,email=your-email,message=your-message' ) ) ) ) );
$reply    = $send( array_merge( $unfilled, $visitor ) );
$check( is_wp_error( $reply ) && 'clara_ve_form_invalid' === $reply->get_error_code() && array() === (array) $reply->get_error_data()['errors'], 'a required plugin field nothing fills is refused, with no field of ours to blame' );
delete_transient( $rate_key );

// Retyped on the way back: the signature no longer holds, and the delivery
// falls back to Form Settings exactly as a retyped list or recipient does.
$mail  = array();
$reply = $send( array_merge( $fields, $visitor, array( 'list_id' => $list( $cf7_id, 'name=your-message' ) ) ) );
$to    = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
$check( ! is_wp_error( $reply ) && in_array( Clara_VE_Form_Settings::recipient( '' ), $to, true ) && ! in_array( 'cf7-owner@example.invalid', $to, true ), 'a retyped mapping is not honoured: Form Settings gets it instead' );
foreach ( get_posts( array( 'post_type' => Clara_VE_Forms::CPT, 'post_status' => 'any', 'numberposts' => 5, 'orderby' => 'ID', 'order' => 'DESC' ) ) as $row ) {
	wp_delete_post( $row->ID, true );
}
delete_transient( $rate_key );

echo "--- a plugin form that is gone ---\n";
$gone     = $page( array( 'id' => 'qa-cf7', 'type' => 'cf7', 'list' => $list( '999999' ) ) );
wp_set_current_user( 0 );
$as_guest = $render_plugin( $gone );
$check( false === strpos( $as_guest, 'clara_ve_nonce' ) && false !== strpos( $as_guest, 'class="contact-grid"' ), 'the form renders as not connected, its design intact' );
$check( false === strpos( $as_guest, 'Visible only to you' ), 'a visitor is not told why' );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) );
wp_set_current_user( $admins ? (int) $admins[0] : 1 );
$check( false !== strpos( $render_plugin( $gone ), 'no longer exists' ), 'the owner is, under the form' );
wp_set_current_user( 0 );
// A page cached while it was connected still posts a signed handoff.
$stale = $hidden( $render_plugin( $page( array( 'id' => 'qa-cf7', 'type' => 'cf7', 'list' => $list( $cf7_id ) ) ) ) );
$stale['list_id']      = $list( '999999' );
$stale['cve_delivery'] = Clara_VE_Forms::delivery_signature( 'qa-cf7', '', 'cf7', $stale['list_id'] );
$mail  = array();
$reply = $send( array_merge( $stale, $visitor ) );
$check( is_wp_error( $reply ) && 'clara_ve_form_closed' === $reply->get_error_code() && 503 === $reply->get_error_data()['status'] && ! $mail && ! $ours(), 'a submission to it is declined politely — nothing sent, nothing stored, no fatal' );
delete_transient( $rate_key );

echo "--- contact and list stay as they were ---\n";
foreach ( array( array( 'id' => 'qa-c', 'type' => 'contact', 'to' => 'x@example.invalid' ), array( 'id' => 'qa-l', 'type' => 'list', 'list' => '7' ), array() ) as $atts ) {
	$content = $atts ? $page( $atts ) : "<!-- wp:html -->\n<!-- clara-ve-key: qa -->\n" . $design . "\n<!-- /wp:html -->";
	$check( Clara_VE_Form_Handlers::prepare_block( $content, array() ) === $content, 'untouched before hydration: ' . ( $atts ? $atts['type'] : 'unconnected' ) );
}
$contact = $hidden( $render_plugin( $page( array( 'id' => 'qa-cf7', 'type' => 'contact' ) ) ) );
$reply   = $send( array_merge( $contact, $visitor ) );
$check( $reply instanceof WP_REST_Response && array( 'ok' => true, 'redirect' => '' ) === $reply->get_data(), 'a contact form answers exactly { ok, redirect }' );
foreach ( $ours() as $row ) {
	wp_delete_post( $row->ID, true );
}
delete_transient( $rate_key );

if ( $theme_runtime ) {
	echo "--- on a converted theme's own runtime ---\n";
	add_theme_support( 'html2wp-runtime', ...( is_array( $runtime_support ) ? $runtime_support : array() ) );
	$html   = do_blocks( $page( array( 'id' => 'qa-cf7', 'type' => 'cf7', 'list' => $list( $cf7_id ) ) ) );
	$fields = $hidden( $html );
	$check( 'cf7' === ( $fields['form_type'] ?? '' ) && ! empty( $fields['cve_delivery'] ) && 1 === substr_count( $html, 'name="cve_delivery"' ), 'the theme renders the token, and the signature it does not emit is already in the form' );
	$mail   = array();
	$before = $inbound();
	$params = array_merge( $fields, $visitor );
	$fresh->setValue( null, null );
	$reply  = clara_ve_enhance_theme_form( null, array( 'form_id' => 'qa-cf7', 'fields' => array(), 'params' => $params, 'ip' => $_SERVER['REMOTE_ADDR'] ) );
	$to     = array_map( static function ( $atts ) { return is_array( $atts['to'] ) ? implode( ',', $atts['to'] ) : $atts['to']; }, $mail );
	$check( $reply instanceof WP_REST_Response && in_array( 'cf7-owner@example.invalid', $to, true ), "the theme's forwarded submission goes through CF7" );
	$check( ! post_type_exists( 'flamingo_inbound' ) || $inbound() === $before + 1, 'and Flamingo stored it' );
	delete_transient( $rate_key );
	$fresh->setValue( null, null );
	$reply = clara_ve_enhance_theme_form( null, array( 'form_id' => 'qa-cf7', 'fields' => array(), 'params' => array_merge( $params, array( 'email' => 'nope' ) ), 'ip' => '' ) );
	$check( is_wp_error( $reply ) && ! empty( ( (array) $reply->get_error_data()['errors'] )['email'] ), 'and its refusal comes back with the field it is about' );
	delete_transient( $rate_key );
} else {
	echo "  --   the active theme has no runtime of its own: theme leg skipped\n";
}

echo "--- the editor's view ---\n";
wp_set_current_user( 0 );
$route = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/clara-ve/v1/form-handlers' ) );
$check( in_array( $route->get_status(), array( 401, 403 ), true ), 'closed to visitors' );
wp_set_current_user( $admins ? (int) $admins[0] : 1 );
$request = new WP_REST_Request( 'POST', '/clara-ve/v1/form-handlers' );
$request->set_body_params(
	array(
		'handler' => 'cf7',
		'form'    => $cf7_id,
		'fields'  => array( array( 'name' => 'name', 'type' => 'text', 'label' => 'Name' ), array( 'name' => 'mail', 'type' => 'email', 'label' => 'Email' ) ),
	)
);
$view = rest_get_server()->dispatch( $request )->get_data();
$check( in_array( 'cf7', wp_list_pluck( $view['handlers'] ?? array(), 'value' ), true ) && in_array( $cf7_id, wp_list_pluck( $view['forms']['cf7'] ?? array(), 'id' ), true ), 'an editor sees Contact Form 7 and its forms' );
$check( array( 'name' => 'your-name', 'mail' => 'your-email' ) === ( $view['mapping']['map'] ?? null ), 'and our fields matched to its fields' );
$check( array( 'your-subject' ) === ( $view['mapping']['unmappedRequired'] ?? null ), 'with the required field nothing fills named' );
wp_set_current_user( 0 );

wp_delete_post( (int) $cf7_id, true );
foreach ( array_diff( $inbound_ids(), $inbound_before ) as $id ) {
	wp_delete_post( $id, true );
}
if ( $theme_runtime ) {
	add_theme_support( 'html2wp-runtime', ...( is_array( $runtime_support ) ? $runtime_support : array() ) );
}

if ( $failures ) {
	fwrite( STDERR, "FAIL: $failures assertion(s)\n" );
	exit( 1 );
}
echo 'PASS: a designed form handed to Contact Form 7 — validated, mailed and stored by CF7, errors under our fields, gone plugin form declined' . ( $theme_runtime ? ", and through a converted theme's runtime" : '' ) . "\n";
