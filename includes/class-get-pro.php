<?php
/**
 * The one place Lite names the paid edition: a single menu item, last in the
 * Visual Edit Lite menu, with one screen behind it.
 *
 * Why this is allowed in the WordPress.org directory, since the whole file is
 * an upgrade prompt and a reviewer will ask:
 *
 * - Guideline 11 (hijacking the admin): one plain item inside this plugin's
 *   OWN menu, and its own screen. No dashboard widget, no banner on anybody
 *   else's page, no notice, no nag, no badge, no dismiss-state to remember,
 *   and nothing added to any other admin screen — the few style rules below
 *   load on this screen only.
 * - Guideline 8 (no executable code from outside): nothing here loads from a
 *   remote host. No image, no font, no stylesheet, no script. The only outside
 *   reference is a plain link a person chooses to click.
 * - Guideline 7 (no tracking without consent): this screen sends nothing
 *   anywhere. No ping on render, no campaign parameters on the link, no
 *   cookie, no option written, no count of who looked.
 * - Guideline 5 (no trialware, nothing disabled to force an upgrade): the
 *   screen is text about a separate plugin. None of that plugin's code is
 *   shipped here, no item in the menu stands in for a feature this plugin does
 *   not have, and there is nothing a payment would unlock. Everything Lite
 *   installs, it runs.
 *
 * Registered on the same stand-down path as the rest of Lite: when Visual Edit
 * Pro is active, visual-edit-lite.php returns before this file is required, so
 * the item can never appear beside the product it describes.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Get_Pro {

	/**
	 * The screen's slug. Deliberately not one of the paid edition's own
	 * (`visual-edit-ai`, `visual-edit-export`): if both plugins were ever
	 * loaded at once, two screens under one slug would be a collision. Lite
	 * stands down before that can happen, and this slug means it cannot happen
	 * even if the stand-down were removed.
	 */
	const PAGE = 'visual-edit-lite-pro';

	/** Where the button goes. No campaign parameters — see guideline 7 above. */
	const BUY_URL = 'https://html2wp.dev/pricing/#visualedit';

	public static function init() {
		// Priority 30, not 20. Five of Lite's own screens register at exactly 20
		// (Subscribers, SEO & Sharing, SEO & AI Readiness, Import Content,
		// Parked content). This item goes last, so it has to run after every
		// sibling has put itself there.
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	// ------------------------------------------------------------- the menu

	/** One item, appended: a plain label, like every other row in this menu. */
	public static function register_page() {
		add_submenu_page(
			'visual-edit',
			__( 'Visual Edit Pro', 'visual-edit-lite' ),
			__( 'Visual Edit Pro', 'visual-edit-lite' ),
			'edit_theme_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * The screen's own styling, on the screen itself and nowhere else.
	 *
	 * Inline, through core's always-present `common` stylesheet: a few rules
	 * do not deserve a file.
	 *
	 * @param string $hook The current admin screen's hook suffix.
	 */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_add_inline_style(
			'common',
			'.cve-pro-page { max-width: 760px; }
.cve-pro-page ul { list-style: disc; margin-left: 20px; }
.cve-pro-page .cve-pro-buy { margin: 28px 0 8px; }'
		);
	}

	// ----------------------------------------------------------- the screen

	/**
	 * What the paid edition adds, and one button.
	 *
	 * Nothing is fetched, stored or reported here. The screen is text.
	 */
	public static function render() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this screen.', 'visual-edit-lite' ) );
		}
		?>
		<div class="wrap cve-pro-page">
			<h1><?php esc_html_e( 'Visual Edit Pro', 'visual-edit-lite' ); ?></h1>

			<p><?php esc_html_e( 'Visual Edit Pro is a separate, paid plugin from the same author. Everything in Visual Edit Lite works without it, and stays exactly as it is if you never install it.', 'visual-edit-lite' ); ?></p>

			<h2><?php esc_html_e( 'What Pro adds', 'visual-edit-lite' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'An assistant that edits pages, sections, the header and footer, menus and site styles from a chat, using your own API key.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'AI image editing and AI video generation, with the same key.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'Cloudflare Turnstile for your forms, as an extra anti-spam layer.', 'visual-edit-lite' ); ?></li>
				<li><?php esc_html_e( 'One-click theme export: the whole site as an installable package.', 'visual-edit-lite' ); ?></li>
			</ul>

			<p><?php esc_html_e( 'Pro and Lite store content and settings under the same names: install Pro over Lite and nothing has to be migrated.', 'visual-edit-lite' ); ?></p>

			<p class="cve-pro-buy">
				<a class="button button-primary"
					href="<?php echo esc_url( self::BUY_URL ); ?>"
					target="_blank" rel="noopener noreferrer">
					<?php esc_html_e( 'See Visual Edit Pro', 'visual-edit-lite' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

Clara_VE_Get_Pro::init();
