<?php
/**
 * Persistence for the ::before/::after ornament CSS layer, keyed by page —
 * mirrors Clara_VE_Source_Store's key-naming convention exactly (front page
 * keeps its original option name byte-for-byte; any other key gets its own
 * option), so the pseudo-ornament "convert to editable text" feature works
 * per-page instead of being hardcoded to the front page.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Pseudo_Store {

	/**
	 * @param string $key
	 * @return string wp_options row name for this key's pseudo map.
	 */
	public static function option_name( $key, $theme = null ) {
		$key   = sanitize_key( $key );
		$theme = sanitize_key( null === $theme ? get_stylesheet() : $theme );
		return 'clara_ve_pseudo__' . $theme . '__' . $key;
	}

	/**
	 * The pre-scoping row name — what installs before scoping wrote, and what
	 * the migration reads from. Scoping matters more here than anywhere: these
	 * rules compile to :nth-child selectors against a page's structure, so one
	 * theme's rules applied to another's markup decorate whatever happens to
	 * sit at that index.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function legacy_option_name( $key ) {
		$key = sanitize_key( $key );
		return ( CLARA_VE_DEFAULT_KEY === $key ) ? CLARA_VE_PSEUDO_OPTION : 'clara_ve_pseudo__' . $key;
	}

	/**
	 * @param string $key
	 * @return array The pseudo map for this key (path => {before/after => styles}).
	 */
	public static function get( $key ) {
		// Pseudo rules compile to :nth-child selectors against a page's
		// structure. Stored for a DIFFERENT theme, those paths address
		// whatever element happens to sit at that index in THIS theme —
		// decoration landing on arbitrary elements. Nothing is returned while
		// the data belongs to another theme (see CLARA_VE_OWNER_OPTION).
		$map = get_option( self::option_name( $key ), null );
		if ( is_array( $map ) ) {
			return $map;
		}
		// Unmigrated legacy row, and only when it is not another theme's.
		if ( function_exists( 'clara_ve_foreign_data' ) && '' !== clara_ve_foreign_data() ) {
			return array();
		}
		$legacy = get_option( self::legacy_option_name( $key ), array() );
		return is_array( $legacy ) ? $legacy : array();
	}

	/**
	 * @param string $key
	 * @param array  $map
	 * @return bool
	 */
	public static function save( $key, $map ) {
		return update_option( self::option_name( $key ), is_array( $map ) ? $map : array(), false );
	}
}
