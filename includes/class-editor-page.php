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

	/**
	 * Which key the editor opens on when nothing else says.
	 *
	 * On a raw-HTML site that is the front-page key, as it has always been.
	 * On a block site the front page is an ordinary WordPress page, so its
	 * block key is the answer — and when the site has no static front page,
	 * the first page the picker would list, because opening on a canvas that
	 * cannot exist is how Phase 0's empty-canvas bug looked from the outside.
	 *
	 * @return string
	 */
	private static function default_key() {
		if ( clara_ve_active_theme_is_ours() ) {
			return CLARA_VE_DEFAULT_KEY;
		}
		$front = Clara_VE_Source_Store::block_key( (int) get_option( 'page_on_front' ) );
		if ( '' !== $front ) {
			return $front;
		}
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 20, 'orderby' => 'ID' ) ) as $page ) {
			$key = Clara_VE_Source_Store::block_key( $page );
			if ( '' !== $key ) {
				return $key;
			}
		}
		return CLARA_VE_DEFAULT_KEY;
	}

	/**
	 * The theme's own design tokens, as the styling panel's choices.
	 *
	 * Each entry carries the VALUE as well as the slug, because the panel
	 * previews a change live in the frame before anything is saved, and a
	 * preview needs a colour rather than the name of one. What is stored is
	 * always the slug: a block attribute naming a preset follows the theme
	 * when the theme's palette changes, which a copied hex does not.
	 *
	 * @return array
	 */
	/**
	 * What each editable block type can actually be styled for.
	 *
	 * The panel is built from this rather than from a list written by hand,
	 * so a control is never offered for something the server would refuse:
	 * core/column has padding but no margin, core/spacer has neither colour
	 * nor typography. One source of truth — the block registry — read once
	 * here and once in Clara_VE_Block_Supports when the change comes back.
	 *
	 * @return array block name => list of style paths it supports.
	 */
	private static function block_supports() {
		if ( clara_ve_active_theme_is_ours() || ! class_exists( 'Clara_VE_Block_Supports' ) ) {
			return array();
		}
		$paths = array(
			'typography.fontSize', 'typography.lineHeight', 'typography.textAlign',
			'typography.fontFamily', 'typography.fontWeight', 'typography.fontStyle',
			'typography.textTransform', 'typography.textDecoration', 'typography.letterSpacing',
			'color.text', 'color.background', 'color.gradient',
			'spacing.padding', 'spacing.margin', 'spacing.blockGap',
			'border.radius', 'border.width', 'border.style', 'border.color',
			'shadow',
			'dimensions.minHeight', 'dimensions.aspectRatio',
			'position.type',
		);
		$out = array();
		foreach ( array_keys( Clara_VE_Block_Stamp::CAPABILITY ) as $name ) {
			$supported = array();
			foreach ( $paths as $path ) {
				// The support for a spacing side is declared one level up, so
				// asking about `.top` answers for the whole group.
				// A spacing support is declared for the box, not per side.
				$probe = ( 0 === strpos( $path, 'spacing.p' ) || 0 === strpos( $path, 'spacing.m' ) )
					? $path . '.top'
					: $path;
				if ( Clara_VE_Block_Supports::supports( $name, $probe ) ) {
					$supported[] = $path;
				}
			}
			$out[ $name ] = $supported;
		}
		return $out;
	}

	/**
	 * The theme's own colours, whichever panel is running.
	 *
	 * block_presets() answers nothing for a theme of ours, and rightly: block
	 * mode is not what such a theme uses, and its controls pick from that list
	 * INSTEAD of writing CSS. The gradient builder needs the palette for
	 * something weaker — a handful of suggested pairs to click, which it then
	 * writes out as ordinary CSS either way. Refusing it there would leave the
	 * raw-HTML panel with two empty colour pickers where the block panel has
	 * six ready-made gradients, for no reason a person could see.
	 *
	 * `theme` and `custom` only, never `default` — same rule as block_presets:
	 * a site offered someone else's palette stops looking like itself.
	 *
	 * @return array
	 */
	private static function theme_colors() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$group = wp_get_global_settings( array( 'color', 'palette' ) );
		$out   = array();
		$seen  = array();
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( (array) ( isset( $group[ $origin ] ) ? $group[ $origin ] : array() ) as $preset ) {
				if ( empty( $preset['slug'] ) || isset( $seen[ $preset['slug'] ] ) ) {
					continue;
				}
				$seen[ $preset['slug'] ] = true;
				$out[]                   = array(
					'slug'  => (string) $preset['slug'],
					'name'  => isset( $preset['name'] ) ? (string) $preset['name'] : (string) $preset['slug'],
					'value' => isset( $preset['color'] ) ? (string) $preset['color'] : '',
				);
			}
		}
		return $out;
	}

	private static function block_presets() {
		if ( clara_ve_active_theme_is_ours() || ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$settings = wp_get_global_settings();

		/**
		 * Presets from the theme AND from the owner's own additions.
		 *
		 * wp_get_global_settings() returns each group keyed by origin —
		 * `default` (WordPress's factory presets), `theme` (theme.json), and
		 * `custom` (the user layer). Two of those three are wanted:
		 *
		 * `custom` is where a Google font kept in this plugin lands, because
		 * Clara_VE_Fonts merges through wp_theme_json_data_user — which is
		 * why the font picker's families never reached this panel while it
		 * read `theme` alone. It is also where Global Styles edits live, and
		 * those are the owner's design decisions too.
		 *
		 * `default` is deliberately left out: WordPress's own palette and
		 * type scale belong to no design in particular, and offering them
		 * here is how a site stops looking like itself.
		 *
		 * @param string[] $path      Settings path WITHOUT the origin key.
		 * @param string   $value_key Which field carries the CSS value.
		 * @return array
		 */
		$read = static function ( $path, $value_key ) use ( $settings ) {
			$group = $settings;
			foreach ( $path as $step ) {
				$group = isset( $group[ $step ] ) ? $group[ $step ] : array();
			}

			$out  = array();
			$seen = array();
			foreach ( array( 'theme', 'custom' ) as $origin ) {
				foreach ( (array) ( isset( $group[ $origin ] ) ? $group[ $origin ] : array() ) as $preset ) {
					if ( empty( $preset['slug'] ) || isset( $seen[ $preset['slug'] ] ) ) {
						continue;
					}
					$seen[ $preset['slug'] ] = true;
					$out[]                   = array(
						'slug'  => (string) $preset['slug'],
						'name'  => isset( $preset['name'] ) ? (string) $preset['name'] : (string) $preset['slug'],
						'value' => isset( $preset[ $value_key ] ) ? (string) $preset[ $value_key ] : '',
					);
				}
			}
			return $out;
		};

		/** As $read, but including WordPress's own defaults. */
		$read_with_default = static function ( $path, $value_key ) use ( $settings ) {
			$group = $settings;
			foreach ( $path as $step ) {
				$group = isset( $group[ $step ] ) ? $group[ $step ] : array();
			}
			$out  = array();
			$seen = array();
			foreach ( array( 'default', 'theme', 'custom' ) as $origin ) {
				foreach ( (array) ( isset( $group[ $origin ] ) ? $group[ $origin ] : array() ) as $preset ) {
					if ( empty( $preset['slug'] ) || isset( $seen[ $preset['slug'] ] ) ) {
						continue;
					}
					$seen[ $preset['slug'] ] = true;
					$out[]                   = array(
						'slug'  => (string) $preset['slug'],
						'name'  => isset( $preset['name'] ) ? (string) $preset['name'] : (string) $preset['slug'],
						'value' => isset( $preset[ $value_key ] ) ? (string) $preset[ $value_key ] : '',
					);
				}
			}
			return $out;
		};

		return array(
			'colors'     => $read( array( 'color', 'palette' ), 'color' ),
			'fontSizes'  => $read( array( 'typography', 'fontSizes' ), 'size' ),
			'fontFamily' => $read( array( 'typography', 'fontFamilies' ), 'fontFamily' ),
			'gradients'  => $read( array( 'color', 'gradients' ), 'gradient' ),
			// The one place `default` is read, and deliberately. WordPress's
			// five shadows are not a palette that makes a site look like
			// nothing in particular — they ARE the shadows Gutenberg's own
			// panel offers, and almost no theme declares its own. Excluding
			// them the way colours are excluded would leave the control
			// permanently empty.
			'shadow'     => $read_with_default( array( 'shadow', 'presets' ), 'shadow' ),
			// Spacing has no slug attribute of its own on a block — presets
			// and hand-typed values both live in style.spacing.* — so the
			// panel needs the steps in order to offer them at all.
			'spacing'    => $read( array( 'spacing', 'spacingSizes' ), 'size' ),
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
				// Filtered through the same availability test list_keys() uses,
				// or the editor would keep building preview URLs for keys the
				// picker no longer offers on a theme that has no such parts.
				'chromeKeys'      => array_merge(
					array_values(
						array_filter(
							array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ),
							array( 'Clara_VE_Source_Store', 'chrome_key_available' )
						)
					),
					array_map(
						static function ( $part ) { return $part['key']; },
						clara_ve_theme_contract()['parts']
					)
				),
				// On a block site nothing is saved as a document: the editor
				// sends addressed patches instead, and several panels that
				// describe raw HTML have nothing to describe.
				'blockMode'       => ! clara_ve_active_theme_is_ours(),
				// What the theme actually offers. In block mode the styling
				// controls pick from these instead of writing arbitrary CSS —
				// the site keeps one type scale and one palette, and the
				// client cannot leave the brand by accident.
				'blockPresets'    => self::block_presets(),
				// The palette on its own, for the gradient builder's suggested
				// pairs — which both panels offer, so both need it.
				'themeColors'     => self::theme_colors(),
				'blockSupports'   => self::block_supports(),
				// Lets the Pages-list "Visual Editor" column link straight into
				// editing that specific page. On a block site the front page's
				// own block key is where the editor opens — the raw-HTML front
				// key names a canvas such a theme does not have.
				'initialKey'      => isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : self::default_key(), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				'formRecipient'   => Clara_VE_Form_Settings::recipient( '' ),
				// Google fonts the owner has kept, so the picker can offer them
				// immediately (see includes/class-fonts.php).
				'googleFonts'     => Clara_VE_Fonts::selected(),
				'googleFontsCss'  => Clara_VE_Fonts::css_url(),
				'googleFontsMax'  => Clara_VE_Fonts::MAX_FONTS,
				// Empty when unlicensed — the AI Settings page is not
				// registered then, and a link to a page WordPress refuses to
				// serve reads as a bug, not as an upsell. The editor shows a
				// licence hint instead of a dead link when this is ''.
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
					<button type="button" id="clara-ve-duplicate" class="clara-ve-history-btn" title="<?php esc_attr_e( 'Duplicate this page', 'visual-edit-lite' ); ?>" disabled><span class="dashicons dashicons-admin-page"></span></button>
					<button type="button" id="clara-ve-trash" class="clara-ve-history-btn" title="<?php esc_attr_e( 'Move this page to the trash', 'visual-edit-lite' ); ?>" disabled><span class="dashicons dashicons-trash"></span></button>
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
				<?php
				// Copying a page. Two fields because a copy with no address of
				// its own is not much use — WordPress would hand it `about-2`
				// and you would find out later. Prefilled from the original so
				// the common case is still one click and Enter.
				?>
				<aside id="clara-ve-duplicate-panel" class="clara-ve-history clara-ve-duplicate-panel">
					<div class="cve-history-head">
						<strong><?php esc_html_e( 'Duplicate page', 'visual-edit-lite' ); ?></strong>
						<button type="button" class="cve-close" id="clara-ve-duplicate-close">✕</button>
					</div>
					<div class="cve-seo-body">
						<p class="cve-note" id="clara-ve-duplicate-note"></p>
						<?php
						// .cve-seo-field, NOT the block panel's .cve-field-label +
						// .cve-text: those are built for an inline row — label left,
						// borderless control right — and standalone they run the
						// label and the box together on one line.
						?>
						<div class="cve-seo-field">
							<label for="clara-ve-duplicate-title"><?php esc_html_e( 'Title', 'visual-edit-lite' ); ?></label>
							<input type="text" id="clara-ve-duplicate-title" />
						</div>
						<div class="cve-seo-field">
							<label for="clara-ve-duplicate-slug"><?php esc_html_e( 'Address (slug)', 'visual-edit-lite' ); ?></label>
							<input type="text" id="clara-ve-duplicate-slug" />
						</div>
						<p class="cve-note"><?php esc_html_e( 'The copy is created as a draft, so it is not live until you publish it. Everything on the page comes with it — pictures, settings and search appearance.', 'visual-edit-lite' ); ?></p>
						<button type="button" id="clara-ve-duplicate-go" class="cve-btn cve-btn-save"><?php esc_html_e( 'Duplicate', 'visual-edit-lite' ); ?></button>
					</div>
				</aside>
			</div>
		</div>
		<?php
	}
}

Clara_VE_Editor_Page::init();
