<?php
/**
 * The API a converted theme's runtime calls on this plugin.
 *
 *   php tests/theme-contract-api.php /var/www/html
 *
 * A theme converted from static HTML carries its own copy of the form and
 * source runtime, and every one of those copies delegates to this plugin the
 * moment its class exists — it has no way to know which edition it is talking
 * to. A method missing here is therefore not a missing feature, it is a FATAL
 * on the public page: `Call to undefined method` while rendering the content,
 * so every page holding a [wp-form] token white-screens for visitors.
 *
 * That is what happened on 14 September 2026 with turnstile_enabled(), which
 * Pro has and this edition never did. Nothing in the gate could see it, because
 * the gate has no converted theme to render.
 *
 * @package VisualEditLite
 */

$root = isset( $argv[1] ) ? $argv[1] : '/var/www/html';
require_once rtrim( $root, '/' ) . '/wp-load.php';

$contract = array(
	'Clara_VE_Form_Settings' => array( 'consent_enabled', 'consent_text', 'turnstile_enabled', 'turnstile_site_key', 'recipient', 'min_seconds' ),
	'Clara_VE_Source_Store'  => array( 'find_page_by_key', 'get_current_source' ),
	'Clara_VE_Forms'         => array( 'handle_submit', 'delivery_field' ),
);

$failures = 0;
foreach ( $contract as $class => $methods ) {
	if ( ! class_exists( $class ) ) {
		echo "  FAIL $class does not exist\n";
		++$failures;
		continue;
	}
	foreach ( $methods as $method ) {
		if ( method_exists( $class, $method ) ) {
			echo "  ok   $class::$method()\n";
			continue;
		}
		echo "  FAIL $class::$method() is missing — a converted theme calling it fatals the public page\n";
		++$failures;
	}
}

if ( $failures ) {
	echo "\nFAIL: $failures missing\n";
	exit( 1 );
}
echo "\nPASS: every method a converted theme's runtime delegates to exists\n";
