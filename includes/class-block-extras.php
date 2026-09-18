<?php
/** Portable VE extras travel with their owning block, including shared entities. */
defined( 'ABSPATH' ) || exit;

class Clara_VE_Block_Extras {
	const STYLE_HANDLE = 'clara-ve-block-extras';

	private static $styles = array();
	private static $style_batch = 0;

	public static function init() {
		add_filter( 'register_block_type_args', array( __CLASS__, 'register_attribute' ) );
		add_filter( 'render_block', array( __CLASS__, 'render' ), 20, 2 );
	}

	public static function register_attribute( $args ) {
		$args['attributes']['claraVe'] = array( 'type' => 'object' );
		return $args;
	}

	/** Reuse the legacy responsive validator; untrusted CSS is never emitted. */
	public static function clean( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		if ( isset( $value['responsive'] ) && is_array( $value['responsive'] ) ) {
			$rules = Clara_VE_Responsive::sanitize_meta( array( 'cve-r-extra' => $value['responsive'] ) );
			$rules = json_decode( $rules, true );
			if ( ! empty( $rules['cve-r-extra'] ) ) {
				$out['responsive'] = $rules['cve-r-extra'];
			}
		}
		foreach ( array( 'before', 'after' ) as $pseudo ) {
			$ornament = $value['ornaments'][ $pseudo ] ?? null;
			if ( ! is_array( $ornament ) ) {
				continue;
			}
			if ( ! empty( $ornament['hidden'] ) && true === $ornament['hidden'] ) {
				$out['ornaments'][ $pseudo ]['hidden'] = true;
			}
			if ( isset( $ornament['content'] ) && is_string( $ornament['content'] ) ) {
				$out['ornaments'][ $pseudo ]['content'] = sanitize_text_field( $ornament['content'] );
			}
			foreach ( array( 'color', 'font-size', 'font-family', 'font-weight', 'line-height' ) as $property ) {
				$property_value = $ornament[ $property ] ?? '';
				if ( is_string( $property_value ) && '' !== $property_value && ! preg_match( '/[{}<>;]|url\s*\(/i', $property_value ) && safecss_filter_attr( $property . ':' . $property_value ) ) {
					$out['ornaments'][ $pseudo ][ $property ] = $property_value;
				}
			}
		}
		$form = self::clean_form( $value['form'] ?? null );
		if ( $form ) {
			$out['form'] = $form;
		}
		return $out;
	}

	/**
	 * Form styling is written against what every form is made of — labels, fields,
	 * buttons — never against one theme's or plugin's class names, so the same values
	 * restyle a theme's shortcode form, Contact Form 7, WPForms or a hand-written form.
	 * assets/workspace-model.js carries the identical table for the editor preview;
	 * tests/form-css-congruence.mjs keeps the two in step.
	 */
	const FORM_FIELD  = 'input:not([type="submit"],[type="button"],[type="reset"],[type="checkbox"],[type="radio"],[type="file"],[type="hidden"],[type="range"],[type="image"],[type="color"])';
	// .kb-forms-submit: Kadence's form draws its button as an editable <div> inside the
	// editor, so the element selectors alone never reach it there. On the site it is a
	// <button> and matches either way.
	const FORM_BUTTON = 'button[type="submit"], button:not([type]), input[type="submit"], .kb-forms-submit';

	/** @return array<string, array{0: string, 1: string[]}> target => [ selector, properties ] */
	public static function form_targets() {
		return array(
			'label'       => array( ':is(label, legend)', array( 'color', 'font-family', 'font-size', 'font-weight', 'letter-spacing', 'text-transform' ) ),
			'field'       => array( ':is(' . self::FORM_FIELD . ', textarea, select)', array( 'color', 'background-color', 'font-family', 'font-size', 'border', 'border-color', 'border-width', 'border-radius' ) ),
			'focus'       => array( ':is(' . self::FORM_FIELD . ', textarea, select):focus', array( 'border-color' ) ),
			'placeholder' => array( ':is(input, textarea)::placeholder', array( 'color' ) ),
			'button'      => array( ':is(' . self::FORM_BUTTON . ')', array( 'color', 'background-color', 'border-color', 'border-radius', 'font-size', 'letter-spacing', 'text-transform' ) ),
			'buttonHover' => array( ':is(' . self::FORM_BUTTON . '):hover', array( 'color', 'background-color' ) ),
		);
	}

	/** One value grammar per property; nothing outside it reaches a stylesheet. */
	private static function form_pattern( $property ) {
		$color  = '~^(#[0-9a-f]{3,8}|transparent|currentcolor|var:preset\|color\|[a-z0-9-]{1,40}|rgba?\([0-9., %]{1,40}\))$~i';
		$length = '~^([0-9.]{1,8}(px|rem|em|%)?)$~i';
		$map    = array(
			'color'            => $color,
			'background-color' => $color,
			'border-color'     => $color,
			'font-family'      => '~^(var:preset\|font-family\|[a-z0-9-]{1,40}|[a-z0-9 ,\'"-]{1,200})$~i',
			'font-size'        => '~^([0-9.]{1,8}(px|rem|em|%)?|var:preset\|font-size\|[a-z0-9-]{1,40})$~i',
			'font-weight'      => '~^([1-9]00|normal|bold)$~',
			'letter-spacing'   => '~^(-?[0-9.]{1,8}(px|rem|em)?|normal)$~i',
			'text-transform'   => '~^(none|uppercase|lowercase|capitalize)$~',
			'border'           => '~^(underline|box|none)$~',
			'border-width'     => $length,
			'border-radius'    => $length,
		);
		return $map[ $property ];
	}

	public static function clean_form( $form ) {
		if ( ! is_array( $form ) ) {
			return array();
		}
		$out = array();
		foreach ( self::form_targets() as $target => $definition ) {
			if ( empty( $form[ $target ] ) || ! is_array( $form[ $target ] ) ) {
				continue;
			}
			foreach ( $definition[1] as $property ) {
				$value = $form[ $target ][ $property ] ?? null;
				if ( is_string( $value ) && '' !== $value && preg_match( self::form_pattern( $property ), $value ) ) {
					$out[ $target ][ $property ] = $value;
				}
			}
		}
		return $out;
	}

	/** var:preset|color|ink → var(--wp--preset--color--ink), as the style engine expands it. */
	private static function form_value( $value ) {
		return preg_replace( '~^var:preset\|([a-z-]+)\|([a-z0-9-]+)$~', 'var(--wp--preset--$1--$2)', $value );
	}

	public static function form_css( $selector, $form ) {
		$form = self::clean_form( $form );
		$css  = '';
		foreach ( self::form_targets() as $target => $definition ) {
			$values = $form[ $target ] ?? array();
			$body   = '';
			foreach ( $definition[1] as $property ) {
				if ( ! isset( $values[ $property ] ) ) {
					continue;
				}
				$value = $values[ $property ];
				if ( 'border-width' === $property ) {
					if ( empty( $values['border'] ) ) {
						$body .= 'border-width:' . $value . ' !important;';
					}
					continue;
				}
				if ( 'border' === $property ) {
					$width = $values['border-width'] ?? '1px';
					$body .= 'none' === $value ? 'border-width:0 !important;' : 'border-style:solid !important;border-width:' . ( 'underline' === $value ? '0 0 ' . $width . ' 0' : $width ) . ' !important;';
					// Underlined fields are often unpadded at the sides; inside a box the text would touch it.
					$body .= 'box' === $value ? 'padding-left:.75em !important;padding-right:.75em !important;' : '';
					continue;
				}
				if ( 'border-color' === $property && 'none' === ( $values['border'] ?? '' ) ) {
					continue;
				}
				$body .= $property . ':' . self::form_value( $value ) . ' !important;';
			}
			if ( '' !== $body ) {
				$css .= $selector . ' ' . $definition[0] . '{' . $body . '}';
			}
		}
		return $css;
	}

	/**
	 * A form usually has no element of its own at render time: a shortcode block is
	 * still "[form]" here (shortcodes expand after blocks), and an HTML block may be
	 * several siblings. Such output gets a wrapper to carry the class.
	 */
	private static function form_needs_wrapper( $content, $name ) {
		if ( in_array( $name, array( 'core/shortcode', 'core/html' ), true ) ) {
			return true;
		}
		$trimmed = ltrim( $content );
		if ( '[' === substr( $trimmed, 0, 1 ) || preg_match( '~^<p>\s*\[~', $trimmed ) ) {
			return true;
		}
		$html = new WP_HTML_Tag_Processor( $content );
		return ! $html->next_tag() || in_array( $html->get_tag(), array( 'STYLE', 'SCRIPT', 'LINK', 'NOSCRIPT', 'TEMPLATE', 'META' ), true );
	}

	public static function render( $content, $block ) {
		$extras = self::clean( $block['attrs']['claraVe'] ?? null );
		if ( ! $extras || '' === trim( $content ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
			return $content;
		}
		// Content-addressed classes make native copy/paste and duplication safe:
		// equal extras share a rule; editing either copy produces a new class.
		$class = 'cve-r-' . substr( md5( wp_json_encode( $extras ) ), 0, 16 );
		if ( ! empty( $extras['form'] ) && self::form_needs_wrapper( $content, (string) ( $block['blockName'] ?? '' ) ) ) {
			$updated = '<div class="' . esc_attr( $class ) . '">' . $content . '</div>';
		} else {
			$html = new WP_HTML_Tag_Processor( $content );
			if ( ! $html->next_tag() ) {
				return $content;
			}
			$html->add_class( $class );
			$updated = $html->get_updated_html();
		}
		$css = Clara_VE_Responsive::compile( array( $class => $extras['responsive'] ?? array() ) );
		foreach ( $extras['ornaments'] ?? array() as $pseudo => $values ) {
			if ( ! empty( $values['hidden'] ) ) {
				$css .= '.' . $class . '::' . $pseudo . '{content:"" !important;display:none !important;}';
				continue;
			}
			$declarations = '';
			foreach ( $values as $property => $value ) {
				if ( 'content' === $property ) {
					$value = '"' . str_replace( array( '\\', '"', "\n", "\r", '<', '>' ), array( '\\\\', '\\"', ' ', ' ', '\\3c ', '\\3e ' ), $value ) . '"';
				}
				$declarations .= $property . ':' . $value . ' !important;';
			}
			$css .= '.' . $class . '::' . $pseudo . '{' . $declarations . '}';
		}
		if ( ! empty( $extras['form'] ) ) {
			$css .= self::form_css( '.' . $class, $extras['form'] );
		}
		self::enqueue_css( $class, $css );
		return $updated;
	}

	/**
	 * Hand one block's rules to WordPress as inline CSS on a source-less handle.
	 *
	 * The rules are only known once the block renders, and that happens on
	 * either side of the head: a block theme renders its template before
	 * wp_head(), a classic theme renders post content after it, and a footer
	 * part renders later still. Core prints a style enqueued after the head in
	 * the footer on its own — but inline CSS added to a handle that has ALREADY
	 * been printed is never printed at all. So the handle is numbered, and a
	 * block that renders after its batch went out opens the next one.
	 *
	 * Every declaration passed the CSS allowlist and content escaping above,
	 * and every one is !important, so where in the queue a batch lands does not
	 * decide whether it applies.
	 *
	 * @param string $scope Content-addressed class the rules are scoped to.
	 * @param string $css   Compiled rules for that class.
	 */
	private static function enqueue_css( $scope, $css ) {
		if ( '' === $css ) {
			return;
		}
		if ( 0 === self::$style_batch || wp_style_is( self::handle(), 'done' ) ) {
			++self::$style_batch;
			self::$styles = array();
			wp_register_style( self::handle(), false, array(), CLARA_VE_VERSION );
			wp_enqueue_style( self::handle() );
		}
		// Equal copies share a class, and one rule is enough for all of them.
		if ( isset( self::$styles[ $scope ] ) ) {
			return;
		}
		self::$styles[ $scope ] = true;
		wp_add_inline_style( self::handle(), wp_strip_all_tags( $css ) );
	}

	/** The handle of the batch currently being filled. */
	private static function handle() {
		return self::STYLE_HANDLE . '-' . self::$style_batch;
	}
}

Clara_VE_Block_Extras::init();
