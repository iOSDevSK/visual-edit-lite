<?php
/**
 * Regression: a secret is stored exactly as it was given, and comes back the same.
 *
 * The sanitize callback of a password is the one place where "sanitize it" is
 * the wrong instinct. Anything that tidies text — trimming, stripping tags,
 * collapsing whitespace, dropping percent-encoded octets — turns a valid password
 * into one that saves without complaint and never authenticates, and nothing on
 * the site says why. WordPress.org's review found exactly that here: the SMTP
 * password was trimmed.
 *
 * So the password goes in raw and is only encrypted. An API key is the other
 * kind of secret: it is pasted, a stray space or newline is a paste accident
 * rather than part of the key, and trimming it is a repair.
 *
 *   php tools/run-in-wp.php ../wordpress tests/regression-secrets.php
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit( 1 );

$failed = array();
$check  = static function ( $what, $ok ) use ( &$failed ) {
	echo ( $ok ? '  ok   ' : '  FAIL ' ) . $what . "\n";
	if ( ! $ok ) {
		$failed[] = $what;
	}
};

$was = array(
	Clara_VE_Form_Settings::OPT_SMTP_PASS => get_option( Clara_VE_Form_Settings::OPT_SMTP_PASS, null ),
	Clara_VE_Form_Settings::OPT_API_BREVO => get_option( Clara_VE_Form_Settings::OPT_API_BREVO, null ),
);

// Every character class a text sanitizer would touch, in one password.
$password = "  lead and trail \t<b>tag</b> %41%zz \\ \" ' &amp; ž 日本  ";
$stored   = Clara_VE_Form_Settings::sanitize_smtp_pass( $password );
$check( 'the password is not stored in the clear', $stored !== $password && false === strpos( $stored, 'lead and trail' ) );
update_option( Clara_VE_Form_Settings::OPT_SMTP_PASS, $stored, false );
$check( 'and comes back byte for byte: spaces, tags, octets, quotes and all', Clara_VE_Form_Settings::smtp_password() === $password );

// WordPress runs a sanitizer TWICE on a first-ever save (update_option, then
// add_option). The second pass must recognise its own ciphertext.
$twice = Clara_VE_Form_Settings::sanitize_smtp_pass( $stored );
$check( 'a second pass over its own ciphertext leaves it alone', $twice === $stored );

$check( 'a blank submission keeps the stored password', Clara_VE_Form_Settings::sanitize_smtp_pass( '' ) === $stored );
$spaces = Clara_VE_Form_Settings::sanitize_smtp_pass( '   ' );
update_option( Clara_VE_Form_Settings::OPT_SMTP_PASS, $spaces, false );
$check( 'a password made of spaces is a password, not a blank', '   ' === Clara_VE_Form_Settings::smtp_password() );

// An API key is pasted, and the paste accident is repaired.
$key = Clara_VE_Form_Settings::sanitize_api_brevo( "  xkeysib-abc123DEF\n" );
update_option( Clara_VE_Form_Settings::OPT_API_BREVO, $key, false );
$check( 'an API key loses the whitespace a paste stuck to it', 'xkeysib-abc123DEF' === Clara_VE_Form_Settings::api_key( 'brevo' ) );
$check( 'and is not stored in the clear either', false === strpos( $key, 'xkeysib' ) );

foreach ( $was as $option => $value ) {
	if ( null === $value ) {
		delete_option( $option );
	} else {
		update_option( $option, $value, false );
	}
}

echo "\n";
if ( $failed ) {
	echo 'FAILED (' . count( $failed ) . "):\n - " . implode( "\n - ", $failed ) . "\n";
	exit( 1 );
}
echo "PASS: a password is stored exactly as typed and only encrypted; a pasted API key is trimmed\n";
