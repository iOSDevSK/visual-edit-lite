<?php
/**
 * "Import Content" admin screen.
 *
 * One screen, one shape of ZIP: a theme ZIP (or bare folder) carrying a
 * clara-content/ bundle, taken through the reviewed path — classify
 * everything first, show what will and will not happen, then apply only what
 * is new. Nothing on this screen can overwrite the owner's work.
 *
 * A ZIP of flat built HTML is recognised only to say so plainly. That import
 * still exists as a mechanism (Clara_VE_Import_Legacy, reached via the
 * CLARA_VE_ALLOW_STATIC_IMPORT constant — see handle_upload) because the
 * conversion pipeline re-runs against a changed static source, but it has no
 * UI: it applies immediately and overwrites, and a screen a site owner opens
 * looking for their content is the wrong place to keep that.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Import_Page {

	const PAGE   = 'visual-edit-import';
	const REPORT = 'clara_ve_import_report_';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_post_clara_ve_import_upload', array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_clara_ve_import_theme_bundle', array( __CLASS__, 'handle_theme_bundle' ) );
		add_action( 'admin_post_clara_ve_import_apply', array( __CLASS__, 'handle_apply' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'visual-edit',
			__( 'Import Content', 'visual-edit-lite' ),
			__( 'Import Content', 'visual-edit-lite' ),
			'edit_theme_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	private static function guard() {
		if ( ! clara_ve_user_can_edit() ) {
			wp_die( esc_html__( 'You need theme-editing and unfiltered HTML permissions to import content.', 'visual-edit-lite' ) );
		}
	}

	public static function render() {
		self::guard();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view routing.
		$plan_id = isset( $_GET['plan'] ) ? sanitize_key( wp_unslash( $_GET['plan'] ) ) : '';
		$done    = isset( $_GET['imported'] );
		$error   = isset( $_GET['import_error'] ) ? sanitize_text_field( wp_unslash( $_GET['import_error'] ) ) : '';
		// phpcs:enable

		echo '<div class="wrap"><h1>' . esc_html__( 'Import Content', 'visual-edit-lite' ) . '</h1>';

		if ( '' !== $error ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		if ( $done ) {
			self::render_report();
		}

		if ( '' !== $plan_id ) {
			self::render_plan( $plan_id );
		} else {
			self::render_forms();
		}

		echo '</div>';
	}

	private static function render_report() {
		$report = get_transient( self::REPORT . get_current_user_id() );
		delete_transient( self::REPORT . get_current_user_id() );
		if ( ! is_array( $report ) ) {
			return;
		}
		echo '<div class="notice notice-' . ( empty( $report['errors'] ) ? 'success' : 'warning' ) . '"><p><strong>'
			. esc_html__( 'Import finished', 'visual-edit-lite' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( (array) $report['lines'] as $line ) {
			echo '<li>' . wp_kses_post( $line ) . '</li>';
		}
		echo '</ul></div>';
	}

	private static function render_forms() {
		$theme_bundle = Clara_VE_Bundle_Reader::theme_bundle_dir();
		?>
		<?php if ( $theme_bundle ) : ?>
			<?php
			$bundle = Clara_VE_Bundle_Reader::read( $theme_bundle );
			$name   = ( ! is_wp_error( $bundle ) && isset( $bundle['manifest']['theme']['name'] ) ) ? $bundle['manifest']['theme']['name'] : '';
			?>
			<div class="card" style="max-width:46em;">
				<h2><?php esc_html_e( 'Content that came with this theme', 'visual-edit-lite' ); ?></h2>
				<?php if ( is_wp_error( $bundle ) ) : ?>
					<p><?php echo esc_html( $bundle->get_error_message() ); ?></p>
				<?php else : ?>
					<p>
						<?php
						printf(
							/* translators: 1: theme name, 2: pages, 3: posts, 4: images */
							esc_html__( '%1$s ships with %2$d pages, %3$d blog posts and %4$d images. Nothing you have already edited will be touched.', 'visual-edit-lite' ),
							'<strong>' . esc_html( $name ) . '</strong>',
							count( $bundle['sources'] ),
							count( $bundle['posts'] ),
							count( $bundle['media'] )
						);
						?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'clara_ve_import_theme_bundle' ); ?>
						<input type="hidden" name="action" value="clara_ve_import_theme_bundle" />
						<?php submit_button( __( 'Review what this would add', 'visual-edit-lite' ), 'primary' ); ?>
					</form>
				<?php endif; ?>
			</div>
			<br />
		<?php endif; ?>

		<div class="card" style="max-width:46em;">
			<h2><?php esc_html_e( 'Import from a ZIP', 'visual-edit-lite' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Either kind of export works here: a full theme ZIP, or a content-only ZIP for an install that already has the theme. Only the content is read in both cases — importing never installs or changes a theme.', 'visual-edit-lite' ); ?>
			</p>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'clara_ve_import_upload' ); ?>
				<input type="hidden" name="action" value="clara_ve_import_upload" />
				<p><input type="file" name="clara_ve_import_zip" accept=".zip" required /></p>
				<p class="description">
					<?php
					printf(
						/* translators: %s: server upload limit */
						esc_html__( 'This server accepts uploads up to %s. A larger bundle can be unzipped into wp-content/themes/ over SFTP instead — it then appears above.', 'visual-edit-lite' ),
						esc_html( size_format( wp_max_upload_size() ) )
					);
					?>
				</p>
				<?php submit_button( __( 'Review this ZIP', 'visual-edit-lite' ) ); ?>
			</form>
		</div>

		<?php
	}

	/**
	 * @param string $plan_id
	 */
	private static function render_plan( $plan_id ) {
		$stored = Clara_VE_Import_Plan::fetch( $plan_id );
		if ( ! $stored ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That import has expired. Start again.', 'visual-edit-lite' ) . '</p></div>';
			self::render_forms();
			return;
		}

		$counts = $stored['plan']['counts'];
		$labels = array(
			'source'     => __( 'Pages', 'visual-edit-lite' ),
			'post'       => __( 'Blog posts', 'visual-edit-lite' ),
			'term'       => __( 'Categories', 'visual-edit-lite' ),
			'menu'       => __( 'Menus', 'visual-edit-lite' ),
			'media'      => __( 'Images', 'visual-edit-lite' ),
			'option'     => __( 'Settings', 'visual-edit-lite' ),
			'submission' => __( 'Form submissions', 'visual-edit-lite' ),
			'subscriber' => __( 'Subscribers', 'visual-edit-lite' ),
		);
		$badges = array(
			'new'       => array( __( 'will be added', 'visual-edit-lite' ), '#008a20' ),
			'identical' => array( __( 'already identical', 'visual-edit-lite' ), '#787c82' ),
			'conflict'  => array( __( 'kept as it is', 'visual-edit-lite' ), '#bd8600' ),
			'blocked'   => array( __( 'cannot be imported', 'visual-edit-lite' ), '#d63638' ),
		);
		?>
		<p>
			<?php
			printf(
				/* translators: 1: new count, 2: skipped count */
				esc_html__( '%1$d items will be added. %2$d are already here or have been edited by you, and are left untouched.', 'visual-edit-lite' ),
				(int) $counts['new'],
				(int) ( $counts['identical'] + $counts['conflict'] )
			);
			?>
		</p>
		<?php if ( $counts['blocked'] ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				printf(
					/* translators: %d: blocked count */
					esc_html__( '%d items cannot be imported at all — see the reasons below.', 'visual-edit-lite' ),
					(int) $counts['blocked']
				);
				?>
			</p></div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr>
				<th style="width:10em;"><?php esc_html_e( 'Type', 'visual-edit-lite' ); ?></th>
				<th><?php esc_html_e( 'Item', 'visual-edit-lite' ); ?></th>
				<th style="width:12em;"><?php esc_html_e( 'What happens', 'visual-edit-lite' ); ?></th>
				<th><?php esc_html_e( 'Why', 'visual-edit-lite' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $stored['plan']['items'] as $item ) : ?>
				<?php list( $badge, $colour ) = $badges[ $item['status'] ]; ?>
				<tr>
					<td><?php echo esc_html( isset( $labels[ $item['type'] ] ) ? $labels[ $item['type'] ] : $item['type'] ); ?></td>
					<td><strong><?php echo esc_html( $item['label'] ); ?></strong> <code><?php echo esc_html( $item['id'] ); ?></code></td>
					<td><span style="color:<?php echo esc_attr( $colour ); ?>;font-weight:600;"><?php echo esc_html( $badge ); ?></span></td>
					<td class="description"><?php echo esc_html( $item['detail'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:1.5em;">
			<?php wp_nonce_field( 'clara_ve_import_apply_' . $plan_id ); ?>
			<input type="hidden" name="action" value="clara_ve_import_apply" />
			<input type="hidden" name="plan_id" value="<?php echo esc_attr( $plan_id ); ?>" />
			<?php submit_button( __( 'Add the new items', 'visual-edit-lite' ), 'primary', 'submit', false, $counts['new'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'visual-edit-lite' ); ?></a>
		</form>
		<?php
	}

	// ---------------------------------------------------------------- actions

	public static function handle_upload() {
		self::guard();
		check_admin_referer( 'clara_ve_import_upload' );

		if ( empty( $_FILES['clara_ve_import_zip']['tmp_name'] ) ) {
			self::fail( __( 'No file was uploaded.', 'visual-edit-lite' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$upload = wp_handle_upload(
			$_FILES['clara_ve_import_zip'], // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			array(
				'test_form' => false,
				'mimes'     => array( 'zip' => 'application/zip' ),
			)
		);
		if ( isset( $upload['error'] ) ) {
			self::fail( $upload['error'] );
		}

		$extract = Clara_VE_Zip::scratch_dir( CLARA_VE_IMPORT_DIR . '-tmp' );
		if ( is_wp_error( $extract ) ) {
			wp_delete_file( $upload['file'] );
			self::fail( $extract->get_error_message() );
		}
		$result = Clara_VE_Zip::extract( $upload['file'], $extract );
		wp_delete_file( $upload['file'] );

		if ( is_wp_error( $result ) ) {
			Clara_VE_Zip::rrmdir( $extract );
			self::fail( $result->get_error_message() );
		}

		$bundle_dir = Clara_VE_Bundle_Reader::locate( $extract );
		if ( $bundle_dir ) {
			self::start_plan( $bundle_dir, true );
		}

		// The raw static-HTML path has no UI: it applies immediately and
		// overwrites whatever it matches, which is the conversion pipeline's
		// business and nobody else's. A site owner who reaches this screen is
		// looking for their content, and an unreviewed overwrite is not an
		// answer to that — so it is off unless a developer turns it on in
		// wp-config.php, which no delivered site ever has:
		//
		//   define( 'CLARA_VE_ALLOW_STATIC_IMPORT', true );
		//
		// A constant rather than a hidden form field, because a field only
		// hides the door; the constant means the door is not built.
		$static_dir = Clara_VE_Import_Legacy::locate( $extract );

		if ( $static_dir && defined( 'CLARA_VE_ALLOW_STATIC_IMPORT' ) && CLARA_VE_ALLOW_STATIC_IMPORT ) {
			$report = Clara_VE_Import_Legacy::import_all( $static_dir );
			Clara_VE_Zip::rrmdir( $extract );
			self::finish( $report['lines'], $report['errors'] );
		}

		Clara_VE_Zip::rrmdir( $extract );
		self::fail(
			$static_dir
				? __( 'This is a built static site, not a content bundle. Export the site from a WordPress install that already has it, and import that ZIP instead.', 'visual-edit-lite' )
				: __( 'This ZIP contains no clara-content bundle and no HTML pages.', 'visual-edit-lite' )
		);
	}

	public static function handle_theme_bundle() {
		self::guard();
		check_admin_referer( 'clara_ve_import_theme_bundle' );

		$dir = Clara_VE_Bundle_Reader::theme_bundle_dir();
		if ( ! $dir ) {
			self::fail( __( 'The active theme carries no content bundle.', 'visual-edit-lite' ) );
		}
		// Not disposable: this bundle lives inside the theme and must survive,
		// so the owner can re-run the import later.
		self::start_plan( $dir, false );
	}

	/**
	 * @param string $bundle_dir
	 * @param bool   $disposable Whether the directory is scratch and may be deleted after apply.
	 */
	private static function start_plan( $bundle_dir, $disposable ) {
		$bundle = Clara_VE_Bundle_Reader::read( $bundle_dir );
		if ( is_wp_error( $bundle ) ) {
			if ( $disposable ) {
				Clara_VE_Zip::rrmdir( dirname( $bundle_dir ) );
			}
			self::fail( $bundle->get_error_message() );
		}

		$plan = Clara_VE_Import_Plan::build( $bundle );
		$id   = Clara_VE_Import_Plan::store( $bundle_dir, $plan );
		set_transient( 'clara_ve_import_scrap_' . $id, $disposable ? $bundle_dir : '', Clara_VE_Import_Plan::TTL );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&plan=' . $id ) );
		exit;
	}

	public static function handle_apply() {
		self::guard();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		$plan_id = isset( $_POST['plan_id'] ) ? sanitize_key( wp_unslash( $_POST['plan_id'] ) ) : '';
		check_admin_referer( 'clara_ve_import_apply_' . $plan_id );

		set_time_limit( 0 );
		$result = Clara_VE_Import_Plan::apply( $plan_id );

		$scrap = get_transient( 'clara_ve_import_scrap_' . $plan_id );
		delete_transient( 'clara_ve_import_scrap_' . $plan_id );
		if ( is_string( $scrap ) && '' !== $scrap ) {
			Clara_VE_Zip::rrmdir( dirname( $scrap ) );
		}

		if ( is_wp_error( $result ) ) {
			self::fail( $result->get_error_message() );
		}

		$lines = $result['lines'];
		if ( ! $lines ) {
			$lines[] = __( 'Nothing new to add — this site already has everything in the bundle.', 'visual-edit-lite' );
		}
		self::finish( $lines, $result['errors'] );
	}

	/**
	 * @param string[] $lines
	 * @param bool     $errors
	 */
	private static function finish( array $lines, $errors ) {
		set_transient(
			self::REPORT . get_current_user_id(),
			array(
				'lines'  => $lines,
				'errors' => $errors,
			),
			MINUTE_IN_SECONDS
		);
		// The theme's setup prompt has served its purpose once content landed.
		// An action rather than a direct option write: the theme owns its own
		// notice and its own option name, so it listens for this and dismisses
		// itself (inc/clara-setup.php) — the plugin never reaches into another
		// component's namespace, and a theme with no setup wizard simply has
		// no listener.
		do_action( 'clara_ve_content_imported' );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&imported=1' ) );
		exit;
	}

	/**
	 * @param string $message
	 */
	private static function fail( $message ) {
		wp_safe_redirect(
			add_query_arg(
				'import_error',
				rawurlencode( $message ),
				admin_url( 'admin.php?page=' . self::PAGE )
			)
		);
		exit;
	}
}

Clara_VE_Import_Page::init();
