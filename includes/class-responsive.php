<?php
/**
 * Different values on smaller screens.
 *
 * Core blocks store one value per property and no more. There is nowhere in a
 * block's own attributes to say "48px of padding, but 16px on a phone", which
 * is why every page builder that offers it keeps its own store — and why this
 * one has to as well.
 *
 * What is stored is DATA, never CSS: a map of anchor → breakpoint → property →
 * value, in one post meta key, compiled to a stylesheet on the way out. The
 * plugin this was modelled on stores the CSS itself and rewrites its
 * breakpoints with str_replace across the whole string; changing a breakpoint
 * there edits every page's markup. Here a breakpoint is a number in one place.
 *
 * The cost, named plainly because the owner accepted it: this is the one thing
 * in the editor that does not survive the plugin being switched off. The page
 * stays whole and valid — every word, every block, every desktop style — but
 * the small-screen adjustments stop being emitted. Nothing is lost from the
 * content, only from the tuning.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Responsive {

	/** Where the rules live. Not exposed to REST: this editor writes it. */
	const META = '_clara_ve_responsive';

	/** The class that ties a block to its rules. */
	const ANCHOR_PREFIX = 'cve-r-';

	/**
	 * The screens, widest first.
	 *
	 * WordPress's own two, so a page tuned here breaks where the editor's
	 * preview sizes break and where most themes already change their mind.
	 * Emitted widest-first so the narrower rule wins by document order rather
	 * than by anybody counting specificity.
	 */
	const BREAKPOINTS = array(
		'tablet' => 781,
		'mobile' => 600,
	);

	/**
	 * What may differ per screen, and the CSS it becomes.
	 *
	 * Deliberately a short list. Padding, size, alignment and hiding are what
	 * a page actually needs re-tuned on a phone; offering letter-spacing per
	 * breakpoint would triple what has to be verified for a control nobody
	 * asked for. Everything here except `display` is validated by the same
	 * whitelist that guards ordinary block styling, so the two cannot drift.
	 */
	const PROPERTIES = array(
		'spacing.padding.top'    => 'padding-top',
		'spacing.padding.right'  => 'padding-right',
		'spacing.padding.bottom' => 'padding-bottom',
		'spacing.padding.left'   => 'padding-left',
		'spacing.margin.top'     => 'margin-top',
		'spacing.margin.bottom'  => 'margin-bottom',
		'typography.fontSize'    => 'font-size',
		'typography.textAlign'   => 'text-align',
		'dimensions.minHeight'   => 'min-height',
		// The one with no desktop counterpart: a block that is simply not
		// wanted on a phone. Its only value is 'none'.
		'display'                => 'display',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_emit' ) );
	}

	public static function register_meta() {
		register_post_meta(
			'',
			self::META,
			array(
				'type'         => 'string',
				'single'       => true,
				'default'      => '',
				// Written by this editor's own route, which checks the page is
				// one the block driver owns. Nothing about it belongs in the
				// public REST surface.
				'show_in_rest' => false,
				'auth_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * The rules stored against a page.
	 *
	 * @param int $post_id
	 * @return array anchor => breakpoint => path => value
	 */
	public static function rules( $post_id ) {
		$raw = get_post_meta( (int) $post_id, self::META, true );
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}
		$rules = json_decode( $raw, true );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Replace the rules for a page.
	 *
	 * @param int   $post_id
	 * @param array $rules
	 * @return bool
	 */
	public static function save_rules( $post_id, $rules ) {
		$rules = self::clean( $rules );
		if ( ! $rules ) {
			delete_post_meta( (int) $post_id, self::META );
			return true;
		}
		// wp_slash on the way in: update_post_meta unslashes what it is given,
		// and JSON is quotes almost all the way down. Without this a value
		// with a quote in it comes back out broken — the same class of fault
		// as a regex run over already-encoded JSON.
		return (bool) update_post_meta( (int) $post_id, self::META, wp_slash( wp_json_encode( $rules ) ) );
	}

	/**
	 * Set or clear one value.
	 *
	 * @param int    $post_id
	 * @param string $anchor
	 * @param string $breakpoint
	 * @param string $path
	 * @param string $value Empty clears it.
	 * @return true|WP_Error
	 */
	public static function set( $post_id, $anchor, $breakpoint, $path, $value ) {
		// The same fence the rest of the plugin stands behind, repeated here
		// rather than trusted to the caller. A page this editor does not own
		// as blocks has no anchors, no rules and no business growing a meta
		// row — and a store that only refuses when asked politely is one
		// refactor away from not refusing at all.
		if ( '' === Clara_VE_Source_Store::block_key( $post_id ) ) {
			return new WP_Error(
				'clara_ve_not_block',
				__( 'This page is not edited as blocks.', 'visual-edit-lite' ),
				array( 'status' => 400 )
			);
		}
		if ( ! preg_match( '/^' . self::ANCHOR_PREFIX . '[a-z0-9]{4,20}$/', (string) $anchor ) ) {
			return new WP_Error( 'clara_ve_bad_anchor', __( 'That is not a block this editor is tracking.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		if ( ! isset( self::BREAKPOINTS[ $breakpoint ] ) ) {
			return new WP_Error( 'clara_ve_bad_breakpoint', __( 'That is not a screen size this editor offers.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		if ( ! isset( self::PROPERTIES[ $path ] ) ) {
			return new WP_Error( 'clara_ve_bad_property', __( 'That is not a property that can differ by screen.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$value = (string) $value;
		if ( '' !== $value ) {
			$ok = self::accepts( $path, $value );
			if ( ! $ok ) {
				return new WP_Error(
					'clara_ve_bad_style_value',
					sprintf(
						/* translators: %s: a property such as spacing.padding.top. */
						__( 'That is not a value this editor can store for %s.', 'visual-edit-lite' ),
						$path
					),
					array( 'status' => 400 )
				);
			}
		}

		$rules = self::rules( $post_id );
		if ( '' === $value ) {
			unset( $rules[ $anchor ][ $breakpoint ][ $path ] );
		} else {
			$rules[ $anchor ][ $breakpoint ][ $path ] = $value;
		}
		self::save_rules( $post_id, $rules );
		return true;
	}

	/**
	 * Drop everything belonging to a set of anchors.
	 *
	 * Called when a section is removed: without it the rules for a block
	 * nobody can see any more stay in the page's meta for good.
	 *
	 * @param int      $post_id
	 * @param string[] $anchors
	 */
	public static function forget( $post_id, $anchors ) {
		if ( ! $anchors ) {
			return;
		}
		$rules = self::rules( $post_id );
		foreach ( $anchors as $anchor ) {
			unset( $rules[ $anchor ] );
		}
		self::save_rules( $post_id, $rules );
	}

	/**
	 * Copy a set of anchors' rules onto new anchors.
	 *
	 * A duplicated section that lost its small-screen tuning would be a copy
	 * of the wrong thing — somebody duplicating a band they have already
	 * tuned means to duplicate the tuning with it.
	 *
	 * @param int   $post_id
	 * @param array $map old anchor => new anchor.
	 */
	public static function copy( $post_id, $map ) {
		if ( ! $map ) {
			return;
		}
		$rules = self::rules( $post_id );
		foreach ( $map as $from => $to ) {
			if ( isset( $rules[ $from ] ) ) {
				$rules[ $to ] = $rules[ $from ];
			}
		}
		self::save_rules( $post_id, $rules );
	}

	/**
	 * Is this a value this store will keep?
	 *
	 * Ordinary block styling is judged by the block writer's own whitelist, so
	 * the two cannot drift. Two things are added on top, and only here:
	 *
	 * `display: none` has no counterpart in block styling at all — no block
	 * stores "not on phones" — so it is this store's alone to allow.
	 *
	 * And a font size may be one of the theme's own steps written as a preset
	 * token. Block styling refuses that on purpose: a preset size belongs in
	 * the fontSize ATTRIBUTE, and a token in the style attribute would make
	 * markup Gutenberg's save() would not write. Nothing here touches markup,
	 * so the objection does not apply and the theme's scale stays offerable.
	 *
	 * @param string $path
	 * @param string $value
	 * @return bool
	 */
	private static function accepts( $path, $value ) {
		if ( 'display' === $path ) {
			return 'none' === $value;
		}
		if ( 'typography.fontSize' === $path && preg_match( '/^var:preset\|font-size\|[a-z0-9-]{1,40}$/', $value ) ) {
			return true;
		}
		return true === Clara_VE_Block_Supports::accepts( $path, $value );
	}

	/** A fresh anchor class, short enough to read in a class list. */
	public static function new_anchor() {
		return self::ANCHOR_PREFIX . substr( md5( uniqid( '', true ) ), 0, 8 );
	}

	/**
	 * The anchor classes present in a piece of markup.
	 *
	 * @param string $html
	 * @return string[]
	 */
	public static function anchors_in( $html ) {
		preg_match_all( '/\b' . self::ANCHOR_PREFIX . '[a-z0-9]{4,20}\b/', (string) $html, $found );
		return array_values( array_unique( $found[0] ) );
	}

	/**
	 * Turn stored rules into a stylesheet.
	 *
	 * `!important` on every declaration, and not for want of trying otherwise:
	 * a block's own styling is an inline `style` attribute, which beats any
	 * selector however specific. An override that does not carry it simply
	 * does nothing, which is worse than not offering one.
	 *
	 * @param array $rules
	 * @return string
	 */
	public static function compile( $rules ) {
		$css = '';
		// Widest first, so the narrower screen's rule wins by coming later.
		foreach ( self::BREAKPOINTS as $breakpoint => $width ) {
			$body = '';
			foreach ( (array) $rules as $anchor => $screens ) {
				if ( empty( $screens[ $breakpoint ] ) || ! is_array( $screens[ $breakpoint ] ) ) {
					continue;
				}
				$declarations = '';
				foreach ( $screens[ $breakpoint ] as $path => $value ) {
					if ( ! isset( self::PROPERTIES[ $path ] ) ) {
						continue;
					}
					$declarations .= self::PROPERTIES[ $path ] . ':' . self::css_value( $value ) . ' !important;';
				}
				if ( '' !== $declarations ) {
					$body .= '.' . $anchor . '{' . $declarations . '}';
				}
			}
			if ( '' !== $body ) {
				$css .= '@media (max-width:' . (int) $width . 'px){' . $body . '}';
			}
		}
		return $css;
	}

	/**
	 * A stored value as CSS. Presets are tokens until this point.
	 *
	 * @param string $value
	 * @return string
	 */
	private static function css_value( $value ) {
		$value = (string) $value;
		if ( 0 === strpos( $value, 'var:preset|' ) ) {
			$parts = explode( '|', $value );
			return 'var(--wp--preset--' . $parts[1] . '--' . $parts[2] . ')';
		}
		return $value;
	}

	/**
	 * Throw away anything that is not a rule this class would have written.
	 *
	 * @param array $rules
	 * @return array
	 */
	private static function clean( $rules ) {
		$out = array();
		foreach ( (array) $rules as $anchor => $screens ) {
			if ( ! preg_match( '/^' . self::ANCHOR_PREFIX . '[a-z0-9]{4,20}$/', (string) $anchor ) || ! is_array( $screens ) ) {
				continue;
			}
			foreach ( $screens as $breakpoint => $values ) {
				if ( ! isset( self::BREAKPOINTS[ $breakpoint ] ) || ! is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $path => $value ) {
					if ( ! isset( self::PROPERTIES[ $path ] ) || ! is_scalar( $value ) || '' === $value ) {
						continue;
					}
					if ( self::accepts( $path, (string) $value ) ) {
						$out[ $anchor ][ $breakpoint ][ $path ] = (string) $value;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Put the page's own rules on the page.
	 *
	 * Inline rather than a file: the rules belong to one page, they are a few
	 * hundred bytes, and a request for them would cost more than they weigh.
	 */
	public static function maybe_emit() {
		if ( is_admin() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}
		$css = self::compile( self::rules( $post->ID ) );
		if ( '' === $css ) {
			return;
		}
		wp_register_style( 'clara-ve-responsive', false, array(), CLARA_VE_VERSION );
		wp_enqueue_style( 'clara-ve-responsive' );
		wp_add_inline_style( 'clara-ve-responsive', $css );
	}
}

Clara_VE_Responsive::init();
