<?php
/**
 * Native Gutenberg integration for block themes.
 *
 * Gutenberg already knows how to edit every registered block and, through the
 * Site Editor, every entity that composes a block theme. Rebuilding that stack
 * in the front-end DOM patcher leaves dynamic and third-party blocks behind.
 * This driver therefore lets WordPress own block editing and adds Visual Edit
 * Lite's tools to that editor.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Native_Gutenberg {

	public static function init() {
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'admin_head', array( __CLASS__, 'leave_workspace_frame' ), 0 );

		// Clara_VE_Native_History versions successful native entity saves.
	}

	/**
	 * Native mode is deliberately narrower than the old block driver: it is
	 * for real block themes. A classic theme containing a few blocks keeps the
	 * established preview editor, and converted themes keep raw-HTML mode even
	 * when their templates happen to use WordPress block wrappers.
	 */
	public static function is_native_mode() {
		return function_exists( 'wp_is_block_theme' )
			&& wp_is_block_theme()
			&& ! clara_ve_active_theme_is_ours();
	}

	/** The stable entry point into WordPress's complete site editor. */
	public static function site_editor_url() {
		return admin_url( 'site-editor.php?canvas=edit' );
	}

	public static function workspace_url( $post_id = 0 ) {
		$url = admin_url( 'admin.php?page=visual-edit' );
		return $post_id ? add_query_arg( 'post', (int) $post_id, $url ) : $url;
	}

	/**
	 * An admin screen that is not the workspace, opened inside the workspace
	 * frame, moves itself to the whole window before it paints. The host
	 * script does the same on load for every page; this only avoids the flash
	 * of a second admin bar and menu while the screen loads.
	 */
	public static function leave_workspace_frame() {
		if ( isset( $_GET['clara_ve_workspace'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_print_inline_script_tag( 'try{if(window.top!==window.self&&window.parent.document.querySelector(".cve-workspace-host")){window.top.location.href=window.location.href;}}catch(e){}' );
	}

	/** The host never parses or saves the iframe's document. */
	public static function enqueue_host() {
		// The WordPress admin bar and side menu stay available next to the workspace.
		wp_enqueue_style( 'clara-ve-workspace', CLARA_VE_URL . 'assets/workspace.css', array(), clara_ve_asset_version( 'assets/workspace.css' ) );
		wp_enqueue_script( 'clara-ve-host', CLARA_VE_URL . 'assets/workspace-host.js', array(), clara_ve_asset_version( 'assets/workspace-host.js' ), true );
	}

	public static function render_host() {
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : Clara_VE_Source_Store::block_key_post_id( $key ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = self::site_editor_url();
		if ( $post_id && current_user_can( 'edit_post', $post_id ) ) {
			$url = get_edit_post_link( $post_id, 'raw' ) ?: $url;
		}
		$session = wp_generate_uuid4();
		$runtime = add_query_arg( array( 'clara_ve_workspace' => '1', 'clara_ve_session' => $session ), $url );
		?>
		<div class="cve-workspace-host" data-session="<?php echo esc_attr( $session ); ?>">
			<div class="cve-host-loading" role="status">
				<?php esc_html_e( 'Loading Visual Edit Lite…', 'visual-edit-lite' ); ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open WordPress editor', 'visual-edit-lite' ); ?></a>
			</div>
			<iframe class="cve-workspace-frame" src="<?php echo esc_url( $runtime ); ?>" title="<?php esc_attr_e( 'Visual Edit Lite workspace', 'visual-edit-lite' ); ?>"></iframe>
		</div>
		<?php
	}

	/** Add the VE sidebar and block controls to WordPress's own editor. */
	public static function enqueue() {
		if ( ! self::is_native_mode() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return;
		}
		$is_site        = 'site-editor' === (string) $screen->id;
		$is_post_editor = 'post' === (string) $screen->base
			&& method_exists( $screen, 'is_block_editor' )
			&& $screen->is_block_editor();
		if ( ! $is_site && ! $is_post_editor ) {
			return;
		}
		$workspace = isset( $_GET['clara_ve_workspace'] ) && '1' === $_GET['clara_ve_workspace']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $workspace ) {
			// The host page already shows the admin bar and side menu. Hide the
			// framed editor's own copies from the first paint, before any script runs.
			add_filter( 'admin_body_class', static function ( $classes ) { return $classes . ' cve-workspace-document'; } );
		}
		wp_enqueue_script( 'clara-ve-block-formats', CLARA_VE_URL . 'assets/block-formats.js', array( 'wp-rich-text', 'wp-i18n' ), clara_ve_asset_version( 'assets/block-formats.js' ), true );

		$config = array(
			'isSiteEditor'         => $is_site,
			'siteEditorUrl'        => self::site_editor_url(),
			'pagesUrl'             => admin_url( 'edit.php?post_type=page' ),
			'postsUrl'             => admin_url( 'edit.php' ),
			'seoUrl'               => admin_url( 'admin.php?page=' . Clara_VE_SEO_Settings::PAGE ),
			'formsUrl'             => admin_url( 'edit.php?post_type=' . Clara_VE_Forms::CPT ),
			'nativeSeoPath'        => '/clara-ve/v1/native/seo/',
			'responsiveMeta'       => Clara_VE_Responsive::META,
			'workspace'            => $workspace,
			'session'              => isset( $_GET['clara_ve_session'] ) ? sanitize_text_field( wp_unslash( $_GET['clara_ve_session'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'homeUrl'              => home_url( '/' ),
			'adminUrl'             => admin_url(),
			'stylesheet'           => get_stylesheet(),
			'template'             => get_template(),
			'presets'              => Clara_VE_Editor_Page::block_presets(),
			'googleFonts'          => Clara_VE_Fonts::selected(),
			'googleFontsCss'       => Clara_VE_Fonts::css_url(),
			'googleFontsMax'       => Clara_VE_Fonts::MAX_FONTS,
			'canManageFonts'       => clara_ve_user_can_edit(),
			// False when the theme or the site's SEO settings keep Visual Edit from
			// printing titles and descriptions on the public site (see
			// clara_ve_stand_down_public_seo_on_foreign_theme()).
			'publicSeo'            => false !== has_filter( 'pre_get_document_title', array( 'Clara_VE_SEO', 'filter_document_title' ) ),
			'seoSettingsUrl'       => admin_url( 'admin.php?page=' . Clara_VE_SEO_Settings::PAGE ),
			'formSettingsUrl'      => class_exists( 'Clara_VE_Form_Settings' ) ? admin_url( 'admin.php?page=' . Clara_VE_Form_Settings::PAGE ) : '',
			// The address a form goes to when it names none of its own: shown
			// as the placeholder in "Send to", so an empty box reads as "the
			// site's address" rather than "nowhere".
			'formRecipient'        => class_exists( 'Clara_VE_Form_Settings' ) ? Clara_VE_Form_Settings::recipient( '' ) : '',
			'next40pxDefaultSize'  => version_compare( get_bloginfo( 'version' ), '6.8', '>=' ),
		);
		/**
		 * Filters the configuration the VE workspace scripts receive.
		 *
		 * Extensions (for example Visual Edit Pro) add their own keys here rather
		 * than printing a second global.
		 *
		 * @param array $config    Script configuration.
		 * @param bool  $workspace Whether the VE workspace chrome is active.
		 */
		$config = apply_filters( 'clara_ve_workspace_config', $config, $workspace );
		// Configuration rides on the first script every editor screen loads.
		wp_localize_script( 'clara-ve-block-formats', 'claraVeGutenberg', $config );

		wp_enqueue_script(
			'clara-ve-seo-panel',
			CLARA_VE_URL . 'assets/seo-panel.js',
			array( 'clara-ve-block-formats', 'wp-api-fetch', 'wp-block-editor', 'wp-components', 'wp-data', 'wp-element', 'wp-i18n' ),
			clara_ve_asset_version( 'assets/seo-panel.js' ),
			true
		);

		if ( ! $workspace ) {
			// The ordinary WordPress editor keeps its VE sidebar. The workspace
			// does not load it: its controls would reappear inside the popup's
			// embedded inspector as a second copy of the same settings.
			wp_enqueue_style( 'clara-ve-gutenberg', CLARA_VE_URL . 'assets/gutenberg.css', array( 'wp-components' ), clara_ve_asset_version( 'assets/gutenberg.css' ) );
			wp_enqueue_script(
				'clara-ve-gutenberg',
				CLARA_VE_URL . 'assets/gutenberg.js',
				array( 'clara-ve-seo-panel', 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-plugins' ),
				clara_ve_asset_version( 'assets/gutenberg.js' ),
				true
			);
		} else {
			$native = array( 'wp-api-fetch', 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-compose', 'wp-core-data', 'wp-data', 'wp-editor', 'wp-element', 'wp-hooks', 'wp-html-entities', 'wp-i18n', 'wp-plugins', 'wp-rich-text', 'wp-url' );
			wp_enqueue_style( 'clara-ve-workspace', CLARA_VE_URL . 'assets/workspace.css', array( 'wp-components' ), clara_ve_asset_version( 'assets/workspace.css' ) );
			wp_enqueue_script( 'clara-ve-api', CLARA_VE_URL . 'assets/ve-api.js', array(), clara_ve_asset_version( 'assets/ve-api.js' ), true );
			wp_enqueue_script( 'clara-ve-popup-values', CLARA_VE_URL . 'assets/popup-values.js', array(), clara_ve_asset_version( 'assets/popup-values.js' ), true );
			wp_enqueue_script( 'clara-ve-workspace-model', CLARA_VE_URL . 'assets/workspace-model.js', array(), clara_ve_asset_version( 'assets/workspace-model.js' ), true );
			wp_enqueue_script( 'clara-ve-workspace-history', CLARA_VE_URL . 'assets/workspace-history.js', array_merge( array( 'clara-ve-block-formats' ), $native ), clara_ve_asset_version( 'assets/workspace-history.js' ), true );
			wp_enqueue_script( 'clara-ve-workspace', CLARA_VE_URL . 'assets/workspace.js', array_merge( array( 'clara-ve-api', 'clara-ve-seo-panel', 'clara-ve-popup-values', 'clara-ve-workspace-model', 'clara-ve-workspace-history' ), $native ), clara_ve_asset_version( 'assets/workspace.js' ), true );
		}
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'clara-ve-block-formats', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
			wp_set_script_translations( 'clara-ve-seo-panel', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
			if ( $workspace ) {
				wp_set_script_translations( 'clara-ve-workspace-history', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
				wp_set_script_translations( 'clara-ve-workspace', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
			} else {
				wp_set_script_translations( 'clara-ve-gutenberg', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
			}
		}

		/**
		 * Fires after Visual Edit has enqueued its block-editor assets.
		 *
		 * Enqueue scripts that extend the workspace here, depending on
		 * `clara-ve-api` to use `window.ClaraVE`.
		 *
		 * Also fires on the raw-HTML editor screen, with $mode 'html'.
		 *
		 * @param bool   $workspace Whether the VE workspace chrome is active.
		 * @param bool   $is_site   Whether this is the Site Editor.
		 * @param string $mode      'block' (VE workspace), 'native' (plain WordPress editor) or 'html'.
		 */
		do_action( 'clara_ve_workspace_enqueue', $workspace, $is_site, $workspace ? 'block' : 'native' );
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			'/native/seo/(?P<post>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_seo' ),
					'permission_callback' => array( __CLASS__, 'can_edit_post' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_seo' ),
					'permission_callback' => array( __CLASS__, 'can_edit_post' ),
					'args'                => array(
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'ogImage'     => array( 'type' => 'string' ),
						'noindex'     => array( 'type' => 'boolean' ),
					),
				),
			)
		);
		register_rest_route(
			'clara-ve/v1',
			'/native/render-shortcode',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'render_shortcode' ),
				'permission_callback' => array( __CLASS__, 'can_preview_shortcode' ),
				'args'                => array(
					'text' => array( 'type' => 'string', 'required' => true, 'maxLength' => 2000 ),
					'post' => array( 'type' => 'integer', 'default' => 0 ),
				),
			)
		);
	}

	/**
	 * What a shortcode shows on the page, for the editor canvas. WordPress's shortcode
	 * block only shows its text, so a form placed that way cannot be seen, let alone
	 * styled, while editing. The markup lands in the admin, so it is kses-filtered
	 * with form controls added to the post allowlist: no scripts, no event handlers.
	 */
	public static function render_shortcode( WP_REST_Request $request ) {
		$text = (string) $request->get_param( 'text' );
		$post = get_post( (int) $request->get_param( 'post' ) );
		if ( $post ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- shortcodes read the current post.
			setup_postdata( $post );
		}
		$html = has_shortcode( $text, self::first_shortcode_tag( $text ) ) ? do_shortcode( $text ) : '';
		if ( $post ) {
			wp_reset_postdata();
		}
		return rest_ensure_response( array( 'html' => wp_kses( $html, self::preview_allowed_html() ) ) );
	}

	private static function first_shortcode_tag( $text ) {
		return preg_match( '~\[([a-zA-Z0-9_-]+)~', $text, $match ) ? $match[1] : '';
	}

	private static function preview_allowed_html() {
		$allowed = wp_kses_allowed_html( 'post' );
		$common  = array( 'class' => true, 'id' => true, 'name' => true, 'style' => true, 'title' => true, 'disabled' => true, 'required' => true, 'aria-label' => true, 'aria-describedby' => true, 'aria-required' => true, 'data-*' => true );
		$tags    = array(
			'form'     => array( 'action' => true, 'method' => true, 'novalidate' => true ),
			'input'    => array( 'type' => true, 'value' => true, 'placeholder' => true, 'checked' => true, 'size' => true, 'maxlength' => true, 'min' => true, 'max' => true, 'step' => true, 'autocomplete' => true ),
			'select'   => array( 'multiple' => true, 'size' => true ),
			'option'   => array( 'value' => true, 'selected' => true ),
			'optgroup' => array( 'label' => true ),
			'textarea' => array( 'rows' => true, 'cols' => true, 'placeholder' => true, 'maxlength' => true ),
			'button'   => array( 'type' => true, 'value' => true ),
			'label'    => array( 'for' => true ),
			'fieldset' => array(),
			'legend'   => array(),
		);
		foreach ( $tags as $tag => $attributes ) {
			$allowed[ $tag ] = array_merge( isset( $allowed[ $tag ] ) ? $allowed[ $tag ] : array(), $common, $attributes );
		}
		return $allowed;
	}

	public static function can_preview_shortcode( WP_REST_Request $request ) {
		$post = (int) $request->get_param( 'post' );
		return $post ? current_user_can( 'edit_post', $post ) : current_user_can( 'edit_theme_options' );
	}

	public static function can_edit_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request->get_param( 'post' ) );
		return $post
			&& in_array( $post->post_type, array( 'page', 'post' ), true )
			&& current_user_can( 'edit_post', $post->ID );
	}

	public static function get_seo( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post' );
		$live    = Clara_VE_SEO::effective( $post_id );
		$saved   = Clara_VE_SEO::get( $post_id );
		return rest_ensure_response(
			array(
				'title'         => $live['title'],
				'description'   => $live['description'],
				'ogImage'       => ! empty( $saved['og']['image'] ) ? Clara_VE_Bundle_Format::from_portable( $saved['og']['image'] ) : '',
				'noindex'       => (bool) $live['noindex'],
				'fallbackTitle' => wp_strip_all_tags( get_the_title( $post_id ) . ' – ' . get_bloginfo( 'name' ) ),
				'permalink'     => (string) get_permalink( $post_id ),
				'hostLabel'     => Clara_VE_SEO::host_label(),
			)
		);
	}

	public static function save_seo( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'post' );
		$record  = Clara_VE_SEO::get( $post_id );
		$record['title']       = (string) $request->get_param( 'title' );
		$record['description'] = (string) $request->get_param( 'description' );
		$record['noindex']     = (bool) $request->get_param( 'noindex' );

		$image = trim( (string) $request->get_param( 'ogImage' ) );
		if ( '' === $image ) {
			unset( $record['og']['image'] );
		} else {
			$record['og']['image'] = Clara_VE_Bundle_Format::to_portable( $image );
		}
		Clara_VE_SEO::save( $post_id, $record );
		return self::get_seo( $request );
	}

}

Clara_VE_Native_Gutenberg::init();
