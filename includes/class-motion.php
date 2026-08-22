<?php
/**
 * Movement on the front of the site — loaded only where there is any.
 *
 * The whole cost of this feature on a page that uses none of it is zero
 * bytes: no stylesheet, no script, no inline anything. That is not an
 * optimisation added afterwards, it is the reason the feature is shaped this
 * way. A page builder that ships an animation framework to every page pays
 * for it on every page; this ships two small files to the pages that asked.
 *
 * What decides is the content itself. A block carries its movement as an
 * ordinary CSS class, so "does this page animate" is answered by looking for
 * the class in the post's own markup — no registry, no option, nothing to
 * fall out of step with what is actually stored.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Motion {

	/** The class prefix every movement token starts with. */
	const PREFIX = 'cve-';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		// The editor canvas is the front end, so it is covered by the above.
		// The BLOCK editor is not, and a person styling a page there should
		// see the hover effects rather than wonder why they do nothing.
		add_action( 'enqueue_block_assets', array( __CLASS__, 'maybe_enqueue_editor' ) );
	}

	/**
	 * Does this markup carry any movement at all?
	 *
	 * @param string $content
	 * @return array{anim:bool,hover:bool}
	 */
	public static function used_in( $content ) {
		$content = (string) $content;
		return array(
			'anim'  => false !== strpos( $content, self::PREFIX . 'anim-' ),
			'hover' => false !== strpos( $content, self::PREFIX . 'hover-' ),
		);
	}

	public static function maybe_enqueue() {
		if ( is_admin() ) {
			return;
		}
		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$used = self::used_in( $post->post_content );
		if ( ! $used['anim'] && ! $used['hover'] ) {
			return;
		}

		self::style();

		// The script exists to undo a hidden starting state. A page with only
		// hover effects has none, so it gets the stylesheet and nothing else.
		if ( $used['anim'] ) {
			wp_enqueue_script(
				'clara-ve-motion',
				CLARA_VE_URL . 'assets/motion.js',
				array(),
				clara_ve_asset_version( 'assets/motion.js' ),
				array(
					// Deferred, not async: it needs the blocks to exist before
					// it can observe them.
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}
	}

	/**
	 * In the block editor, the stylesheet only — the canvas has no scrolling
	 * viewport of its own to reveal against, and a half-revealed block there
	 * would read as broken content rather than as an effect.
	 */
	public static function maybe_enqueue_editor() {
		if ( ! is_admin() ) {
			return;
		}
		self::style();
	}

	private static function style() {
		wp_enqueue_style(
			'clara-ve-motion',
			CLARA_VE_URL . 'assets/motion.css',
			array(),
			clara_ve_asset_version( 'assets/motion.css' )
		);
	}
}

Clara_VE_Motion::init();
