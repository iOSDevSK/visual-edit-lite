<?php
/**
 * The admin "Visual Editor" screen: a full-height iframe of the front page in
 * edit-preview mode, plus the host inspector sidebar.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Editor_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_page() {
		// Named for the PRODUCT, not the screen. Pro names this menu
		// 'Visual Editor' and puts its edition only in the admin bar, which
		// leaves someone with both editions installed unable to tell from
		// wp-admin which one is running. Every surface that names this plugin
		// names it the same way; see tools/verify.sh, which reads both back.
		add_menu_page(
			__( 'Visual Edit Lite', 'visual-edit-lite' ),
			__( 'Visual Edit Lite', 'visual-edit-lite' ),
			'edit_theme_options',
			'visual-edit',
			array( __CLASS__, 'render' ),
			'dashicons-welcome-view-site',
			59
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_visual-edit' !== $hook ) {
			return;
		}

		if ( ! clara_ve_user_can_edit() ) {
			return;
		}

		wp_enqueue_media();
		wp_enqueue_style( 'clara-ve-editor', CLARA_VE_URL . 'assets/editor.css', array(), clara_ve_asset_version( 'assets/editor.css' ) );
		wp_enqueue_script( 'clara-ve-editor', CLARA_VE_URL . 'assets/editor.js', array( 'wp-api-fetch', 'media-editor' ), clara_ve_asset_version( 'assets/editor.js' ), true );

		wp_localize_script(
			'clara-ve-editor',
			'claraVeConfig',
			array(
				'previewUrl'      => add_query_arg(
					array(
						'clara_edit' => '1',
						'_clara_ve'  => wp_create_nonce( 'clara_ve_preview' ),
					),
					home_url( '/' )
				),
				// Reused client-side to build any OTHER visual-edit page's own
				// preview URL from its permalink (fetched via /clara-ve/v1/pages)
				// — the nonce itself isn't page-specific.
				'previewNonce'    => wp_create_nonce( 'clara_ve_preview' ),
				'homeUrl'         => home_url( '/' ),
				'themeUri'        => get_template_directory_uri(),
				'menusUrl'        => admin_url( 'nav-menus.php' ),
				'postsUrl'        => admin_url( 'edit.php' ),
				'submissionsUrl'  => admin_url( 'edit.php?post_type=' . Clara_VE_Forms::CPT ),
				'menuManaged'     => Clara_VE_Front_Nav::is_menu_assigned(),
				// Every key that borrows a URL to preview on and must ride
				// along as ?clara_ve_key: the standard four PLUS every chrome
				// variant part the theme contract declares. This list was a
				// hardcoded trio in editor.js from before variants existed —
				// so header-2 opened its preview page WITHOUT the key, the
				// server scoped nothing, and the editor stamped the page's
				// own content while smoke tests reported the variant
				// editable (school-007, mc-011).
				'chromeKeys'      => array_merge(
					array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ),
					array_map(
						static function ( $part ) { return $part['key']; },
						clara_ve_theme_contract()['parts']
					)
				),
				// Lets the Pages-list "Visual Editor" column link straight into
				// editing that specific page.
				'initialKey'      => isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : CLARA_VE_DEFAULT_KEY, // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				// Where a contact form goes when it carries no per-form "to".
				// Shown as the placeholder in the FORM ZONE "Send to" box so an
				// empty box reads as "the site address" instead of "nowhere".
				'formRecipient'   => Clara_VE_Form_Settings::recipient( '' ),
				// Google fonts the owner has kept, so the picker can offer them
				// immediately (see includes/class-fonts.php).
				'googleFonts'     => Clara_VE_Fonts::selected(),
				'googleFontsCss'  => Clara_VE_Fonts::css_url(),
				'googleFontsMax'  => Clara_VE_Fonts::MAX_FONTS,
			)
		);
	}

	public static function render() {
		if ( ! clara_ve_user_can_edit() ) {
			wp_die( esc_html__( 'You need theme-editing and unfiltered HTML permissions to use the visual editor.', 'visual-edit-lite' ) );
		}
		?>
		<div id="clara-ve-app" class="clara-ve-app">
			<div class="clara-ve-toolbar">
				<strong><?php esc_html_e( 'Visual Edit Lite — Front Page', 'visual-edit-lite' ); ?></strong>
				<select id="clara-ve-page-picker" class="clara-ve-page-picker" title="<?php esc_attr_e( 'Switch page', 'visual-edit-lite' ); ?>"></select>
				<button type="button" id="clara-ve-preview" class="clara-ve-preview-btn" title="<?php esc_attr_e( 'Preview live page in a new tab', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-external"></span></button>
				<button type="button" id="clara-ve-toggle" class="clara-ve-toggle is-off" aria-pressed="false" title="<?php esc_attr_e( 'Toggle edit mode', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-edit"></span></button>
				<div class="clara-ve-devices" role="group" aria-label="<?php esc_attr_e( 'Preview device', 'visual-edit-lite' ); ?>">
					<button type="button" class="clara-ve-device is-active" data-device="desktop" aria-pressed="true" title="<?php esc_attr_e( 'Desktop — full width', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-desktop"></span></button>
					<button type="button" class="clara-ve-device" data-device="tablet" aria-pressed="false" title="<?php esc_attr_e( 'Tablet — 820 × 1180', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-tablet"></span></button>
					<button type="button" class="clara-ve-device" data-device="mobile" aria-pressed="false" title="<?php esc_attr_e( 'Mobile — 390 × 844', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-smartphone"></span></button>
				</div>
				<span class="clara-ve-spacer"></span>
				<span id="clara-ve-status" class="clara-ve-status" aria-live="polite"></span>
				<button type="button" id="clara-ve-seo-toggle" class="clara-ve-history-btn" aria-pressed="false" title="<?php esc_attr_e( 'Search appearance', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-search"></span></button>
					<button type="button" id="clara-ve-history-toggle" class="clara-ve-history-btn" aria-pressed="false" title="<?php esc_attr_e( 'History', 'visual-edit-lite' ); ?>"><span class="dashicons dashicons-backup"></span></button>
				<button type="button" class="button" id="clara-ve-discard" disabled><?php esc_html_e( 'Discard changes', 'visual-edit-lite' ); ?></button>
				<button type="button" class="button button-primary" id="clara-ve-save" disabled><?php esc_html_e( 'Save', 'visual-edit-lite' ); ?></button>
			</div>
			<div class="clara-ve-body">
				<div class="clara-ve-canvas" id="clara-ve-canvas">
					<div class="clara-ve-clip" id="clara-ve-clip">
						<div class="clara-ve-shell" id="clara-ve-shell">
							<iframe id="clara-ve-frame" title="<?php esc_attr_e( 'Front page preview', 'visual-edit-lite' ); ?>"></iframe>
						</div>
					</div>
				</div>
				<aside id="clara-ve-history" class="clara-ve-history">
					<div class="cve-history-head">
						<strong><?php esc_html_e( 'History', 'visual-edit-lite' ); ?></strong>
						<button type="button" class="cve-close" id="clara-ve-history-close">✕</button>
					</div>
					<div class="cve-history-list" id="clara-ve-history-list"></div>
				</aside>
				<?php
				// Search appearance. Four fields and a preview, deliberately — the
				// owner edits raw HTML by clicking, not by writing in Gutenberg,
				// so an SEO plugin's own sidebar is somewhere they will never look
				// (and the editor takeover redirects them out of it anyway). What
				// is NOT here is as considered as what is: no focus keyword, no
				// readability score. Those are Yoast's job, and reimplementing
				// them against a Custom HTML blob would produce advice worth
				// less than nothing.
				?>
				<aside id="clara-ve-seo" class="clara-ve-history clara-ve-seo">
					<div class="cve-history-head">
						<strong><?php esc_html_e( 'Search appearance', 'visual-edit-lite' ); ?></strong>
						<button type="button" class="cve-close" id="clara-ve-seo-close">✕</button>
					</div>
					<div class="cve-seo-body" id="clara-ve-seo-body">
						<p class="cve-note"><?php esc_html_e( 'Loading…', 'visual-edit-lite' ); ?></p>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}

Clara_VE_Editor_Page::init();
