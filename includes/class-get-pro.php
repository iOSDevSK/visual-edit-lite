<?php
/**
 * The one place Lite names the paid edition: two grey menu items with one
 * screen behind them, and a Get Pro link straight to the pricing page.
 *
 * Why this is allowed in the WordPress.org directory, since the whole file is
 * an upgrade prompt and a reviewer will ask:
 *
 * - Guideline 11 (hijacking the admin): the prompt lives inside this plugin's
 *   OWN menu and on its own screen. It adds no dashboard widget, no banner on
 *   anybody else's page, no notice, no nag and no dismiss-state to remember.
 *   Get Pro is a plain link in that same menu, opening the pricing page in a
 *   new tab — no redirect, no interstitial, nothing in between.
 * - Guideline 8 (no executable code from outside): nothing here loads from a
 *   remote host. No image, no font, no stylesheet, no script. The only outside
 *   reference is a plain link a person chooses to click.
 * - Guideline 7 (no tracking without consent): this screen sends nothing
 *   anywhere. No ping on render, no campaign parameters on the link, no
 *   cookie, no option written, no count of who looked.
 * - Guideline 5 (no trialware, nothing disabled to force an upgrade): the two
 *   feature items are LINKS to the text below, not switched-off features. The
 *   paid code is not shipped here at all, so there is nothing in this plugin a
 *   payment would unlock. Everything Lite installs, it runs.
 *
 * Registered on the same stand-down path as the rest of Lite: when Visual Edit
 * Pro is active, visual-edit-lite.php returns before this file is required, so
 * an upsell item can never appear beside the real screen it advertises.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Get_Pro {

	/**
	 * The two screen slugs. Both render the same screen; they differ so that
	 * the item a person clicked can be answered first. Get Pro has no slug of
	 * its own: its menu entry IS the pricing URL.
	 *
	 * Deliberately distinct from the paid edition's own slugs
	 * (`visual-edit-ai`, `visual-edit-export`): if both plugins were ever
	 * loaded at once, two screens under one slug would be a collision. Lite
	 * stands down before that can happen, and these slugs mean it cannot
	 * happen even if the stand-down were removed.
	 */
	const PAGE_ASSISTANT = 'visual-edit-lite-pro-ai';
	const PAGE_EXPORT    = 'visual-edit-lite-pro-export';

	/** Where the button and the Get Pro item go. No campaign parameters — see guideline 7 above. */
	const BUY_URL = 'https://html2wp.dev/pricing/#visualedit';

	public static function init() {
		// Priority 30, not 20. Five of Lite's own screens register at exactly
		// 20 (Subscribers, SEO & Sharing, SEO & AI Readiness, Import Content,
		// Parked content), and at equal priority the order is the order the
		// files happened to be required. The positions below are read from the
		// menu as it already stands, so this has to run after every sibling
		// has put itself there.
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	// ------------------------------------------------------------- the menu

	/**
	 * Three items, placed where the paid edition puts the real ones.
	 *
	 * The positions are computed, never hardcoded: Form Submissions is a post
	 * type, so its row arrives from core, and one more screen added to Lite
	 * would silently shift a fixed index. Each insertion moves everything
	 * after it, so the second anchor is looked up again once the first item is
	 * in. A missing anchor falls back to appending rather than guessing.
	 */
	public static function register_pages() {
		$submissions = self::position_of( 'edit.php?post_type=' . Clara_VE_Forms::CPT );
		add_submenu_page(
			'visual-edit',
			__( 'Visual Edit Pro', 'visual-edit-lite' ),
			self::pro_label( __( 'AI Settings', 'visual-edit-lite' ) ),
			'edit_theme_options',
			self::PAGE_ASSISTANT,
			array( __CLASS__, 'render_assistant' ),
			null === $submissions ? null : $submissions + 1
		);

		$sharing = self::position_of( Clara_VE_SEO_Settings::PAGE );
		add_submenu_page(
			'visual-edit',
			__( 'Visual Edit Pro', 'visual-edit-lite' ),
			self::pro_label( __( 'Export Theme', 'visual-edit-lite' ) ),
			'edit_theme_options',
			self::PAGE_EXPORT,
			array( __CLASS__, 'render_export' ),
			null === $sharing ? null : $sharing + 1
		);

		// Last, with no position of its own: the one item meant to be found.
		// Its slug IS the pricing URL, so WordPress renders a plain link and no
		// screen sits behind it; assets() makes that link open in a new tab.
		add_submenu_page(
			'visual-edit',
			__( 'Visual Edit Pro', 'visual-edit-lite' ),
			'<span class="cve-get-pro">' . esc_html__( 'Get Pro', 'visual-edit-lite' ) . '</span>',
			'edit_theme_options',
			self::BUY_URL
		);
	}

	/**
	 * Where a slug sits in the Visual Edit submenu, counted from the top.
	 *
	 * An ORDINAL, not an array key: add_submenu_page() slices the array by
	 * offset, and the two are not the same number once anything has been
	 * inserted in the middle.
	 *
	 * @param string $slug Menu slug to find.
	 * @return int|null Ordinal position, or null when the item is not there.
	 */
	private static function position_of( $slug ) {
		global $submenu;

		if ( empty( $submenu['visual-edit'] ) || ! is_array( $submenu['visual-edit'] ) ) {
			return null;
		}
		$at = 0;
		foreach ( $submenu['visual-edit'] as $row ) {
			if ( isset( $row[2] ) && $slug === (string) $row[2] ) {
				return $at;
			}
			++$at;
		}
		return null;
	}

	/**
	 * A feature name with the small grey Pro badge after it.
	 *
	 * HTML in a menu label is how this admin already marks a screen that wants
	 * attention — see Clara_VE_Geo_Audit::menu_badge(), which puts core's own
	 * `awaiting-mod` bubble in the same place.
	 *
	 * @param string $label Already-translated feature name.
	 * @return string
	 */
	private static function pro_label( $label ) {
		return esc_html( $label ) . ' <span class="cve-pro-badge">' . esc_html__( 'Pro', 'visual-edit-lite' ) . '</span>';
	}

	/**
	 * The menu styling, and the screen's own.
	 *
	 * Inline, through core's always-present `common` stylesheet: a few rules
	 * do not deserve a file, and a plugin in the directory may not fetch a
	 * stylesheet from anywhere else.
	 */
	public static function assets() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}
		$css = '
#adminmenu .wp-submenu a[href$="page=' . self::PAGE_ASSISTANT . '"],
#adminmenu .wp-submenu a[href$="page=' . self::PAGE_EXPORT . '"] { opacity: .62; }
#adminmenu .wp-submenu a[href$="page=' . self::PAGE_ASSISTANT . '"]:hover,
#adminmenu .wp-submenu a[href$="page=' . self::PAGE_EXPORT . '"]:hover { opacity: 1; }
#adminmenu .wp-submenu .cve-pro-badge {
	display: inline-block; margin-left: 6px; padding: 0 5px;
	border-radius: 9px; background: #8c8f94; color: #fff;
	font-size: 9px; font-weight: 600; line-height: 16px;
	text-transform: uppercase; letter-spacing: .4px; vertical-align: 1px;
}
#adminmenu .wp-submenu .cve-get-pro {
	display: inline-block; padding: 1px 10px; border-radius: 11px;
	background: #00a32a; color: #fff; font-weight: 700;
}
#adminmenu .wp-submenu a:hover .cve-get-pro,
#adminmenu .wp-submenu a:focus .cve-get-pro,
#adminmenu .wp-submenu li.current a .cve-get-pro { background: #008a20; color: #fff; }
.cve-pro-page { max-width: 760px; }
.cve-pro-page .cve-pro-card {
	margin: 20px 0; padding: 16px 20px; background: #fff;
	border: 1px solid #c3c4c7; border-left: 4px solid #00a32a;
}
.cve-pro-page .cve-pro-card h2 { margin-top: 0; }
.cve-pro-page ul { list-style: disc; margin-left: 20px; }
.cve-pro-page .cve-pro-buy { margin: 28px 0 8px; }
';
		wp_add_inline_style( 'common', $css );

		// A menu entry cannot carry a target, and the pricing page is not this
		// site: open it in a new tab so the admin stays where it was.
		$selector = '#adminmenu a[href="' . self::BUY_URL . '"]';
		wp_add_inline_script(
			'common',
			sprintf(
				'document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector(%s);if(a){a.target="_blank";a.rel="noopener noreferrer";}});',
				wp_json_encode( $selector )
			)
		);
	}

	// ----------------------------------------------------------- the screen

	/** The AI Settings item opens the screen with that feature answered first. */
	public static function render_assistant() {
		self::render( 'assistant' );
	}

	/** The Export Theme item, likewise. */
	public static function render_export() {
		self::render( 'export' );
	}

	/**
	 * What the paid edition adds, and one button.
	 *
	 * Nothing is fetched, stored or reported here. The screen is text.
	 *
	 * @param string $feature 'assistant' or 'export'.
	 */
	private static function render( $feature ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'visual-edit-lite' ) );
		}
		?>
		<div class="wrap cve-pro-page">
			<h1><?php esc_html_e( 'Visual Edit Pro', 'visual-edit-lite' ); ?></h1>

			<p><?php esc_html_e( 'Visual Edit Pro is the paid edition of this plugin. Everything you already use stays exactly as it is; Pro adds the screens below.', 'visual-edit-lite' ); ?></p>

			<?php if ( 'assistant' === $feature ) : ?>
				<div class="cve-pro-card">
					<h2><?php esc_html_e( 'AI Settings', 'visual-edit-lite' ); ?></h2>
					<p><?php esc_html_e( 'The screen where you enter your own provider key, so the assistant can edit pages, sections, the header and footer, menus and site styles for you from a chat. It is part of Visual Edit Pro.', 'visual-edit-lite' ); ?></p>
				</div>
			<?php elseif ( 'export' === $feature ) : ?>
				<div class="cve-pro-card">
					<h2><?php esc_html_e( 'Export Theme', 'visual-edit-lite' ); ?></h2>
					<p><?php esc_html_e( 'Download the whole site as an installable WordPress theme package, in one click, ready to hand over or move to another host. It is part of Visual Edit Pro.', 'visual-edit-lite' ); ?></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'What Pro adds', 'visual-edit-lite' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'An assistant that edits pages, sections, the header and footer, menus and site styles from a chat, using your own API key.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'AI image editing and AI video generation, with the same key.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'Cloudflare Turnstile for your forms, as an extra anti-spam layer.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'One-click theme export: the whole site as an installable package.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'A history panel with 300 restore points per page instead of ten.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'One-click updates, straight from the Plugins screen.', 'visual-edit-lite' ); ?></li>
			</ul>

			<p><?php esc_html_e( 'Pro and Lite store content, history and settings under the same names: install Pro over Lite and nothing is lost.', 'visual-edit-lite' ); ?></p>

			<p class="cve-pro-buy">
				<a class="button button-primary button-hero"
					href="<?php echo esc_url( self::BUY_URL ); ?>"
					target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'Buy Visual Edit Pro', 'visual-edit-lite' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

Clara_VE_Get_Pro::init();
