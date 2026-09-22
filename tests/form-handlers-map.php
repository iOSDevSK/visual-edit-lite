<?php
/**
 * Which plugin field each of our fields fills — the matching rules alone, no
 * WordPress needed:
 *   php tests/form-handlers-map.php
 */
define( 'ABSPATH', __DIR__ . '/' );
function add_filter() {}
function add_action() {}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
require dirname( __DIR__ ) . '/includes/class-form-handlers.php';

$failures = 0;
$check    = static function ( $ok, $message ) use ( &$failures ) {
	if ( ! $ok ) {
		++$failures;
		fwrite( STDERR, "FAIL: $message\n" );
	} else {
		echo "  ok   $message\n";
	}
};

// Contact Form 7's default form, as form_fields() reads it.
$cf7 = array(
	array( 'name' => 'your-name', 'label' => 'your-name', 'type' => 'text', 'required' => true ),
	array( 'name' => 'your-email', 'label' => 'your-email', 'type' => 'email', 'required' => true ),
	array( 'name' => 'your-subject', 'label' => 'your-subject', 'type' => 'text', 'required' => true ),
	array( 'name' => 'your-message', 'label' => 'your-message', 'type' => 'textarea', 'required' => false ),
);

$m = Clara_VE_Form_Handlers::map_fields(
	array(
		array( 'name' => 'name', 'type' => 'text', 'label' => 'Name' ),
		array( 'name' => 'email', 'type' => 'email', 'label' => 'Email' ),
		array( 'name' => 'subject', 'type' => 'text', 'label' => 'Subject' ),
		array( 'name' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
	),
	$cf7
);
$check( array( 'name' => 'your-name', 'email' => 'your-email', 'subject' => 'your-subject', 'message' => 'your-message' ) === $m['map'], 'the same name, ignoring CF7\'s "your-" prefix' );
$check( array() === $m['unmappedRequired'], 'and nothing required is left unfilled' );

$m = Clara_VE_Form_Handlers::map_fields(
	array(
		array( 'name' => 'contact_address', 'type' => 'email', 'label' => '' ),
		array( 'name' => 'notes', 'type' => 'textarea', 'label' => '' ),
	),
	$cf7
);
$check( 'your-email' === $m['map']['contact_address'] && 'your-message' === $m['map']['notes'], 'the one free field of the same type' );
$check( array( 'your-name', 'your-subject' ) === $m['unmappedRequired'], 'required plugin fields nothing fills are named' );

$m = Clara_VE_Form_Handlers::map_fields(
	array( array( 'name' => 'f1', 'type' => 'text', 'label' => 'Your Subject' ) ),
	$cf7
);
$check( array( 'f1' => 'your-subject' ) === $m['map'], 'the same label' );

$m = Clara_VE_Form_Handlers::map_fields(
	array( array( 'name' => 'full-name', 'type' => 'text', 'label' => '' ) ),
	$cf7
);
$check( array( 'full-name' => 'your-name' ) === $m['map'], 'a name that contains the other' );

$m = Clara_VE_Form_Handlers::map_fields(
	array(
		array( 'name' => 'email', 'type' => 'email', 'label' => '' ),
		array( 'name' => 'name', 'type' => 'text', 'label' => '' ),
	),
	$cf7,
	array( 'email' => '', 'name' => 'your-subject' )
);
$check( array( 'name' => 'your-subject' ) === $m['map'], "the owner's choice wins, and \"Don't send\" sends nothing" );
$check( in_array( 'your-email', $m['unmappedRequired'], true ) && in_array( 'your-name', $m['unmappedRequired'], true ), 'a choice that leaves a required field empty is warned about' );

$m = Clara_VE_Form_Handlers::map_fields(
	array(
		array( 'name' => 'zz', 'type' => 'text', 'label' => '' ),
		array( 'name' => 'qq', 'type' => 'text', 'label' => '' ),
	),
	$cf7
);
$check( array() === $m['map'], 'a plain text field is never matched by type alone' );

$m = Clara_VE_Form_Handlers::map_fields(
	array(
		array( 'name' => 'email', 'type' => 'email', 'label' => '' ),
		array( 'name' => 'work-email', 'type' => 'email', 'label' => '' ),
	),
	$cf7
);
$check( array( 'email' => 'your-email' ) === $m['map'], 'each plugin field is taken once' );

$p = Clara_VE_Form_Handlers::parse( '12|name=your-name,Email=your-email,phone=,bad"=x' );
$check( '12' === $p['form'], 'the token names the plugin form' );
$check( array( 'name' => 'your-name', 'email' => 'your-email', 'phone' => '', 'bad' => 'x' ) === $p['map'], 'and the mapping, keyed the way a submission is (an explicit "not sent" kept)' );
$check( array( 'form' => '', 'map' => array() ) === Clara_VE_Form_Handlers::parse( '' ), 'an empty value is a form not picked yet' );

if ( $failures ) {
	fwrite( STDERR, "FAIL: $failures assertion(s)\n" );
	exit( 1 );
}
echo "PASS: form plugin field mapping — name, type, label, contained name, owner's choice\n";
