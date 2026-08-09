<?php
/**
 * The one place a converted theme's parked content is visible.
 *
 * Deactivating a converted theme makes everything it brought disappear from
 * the site and from every ordinary wp-admin list — which is the point, and
 * which is also alarming if that is the whole story. An owner who deactivates
 * a theme and finds their pages, posts and images gone has no way to learn
 * that none of it was deleted. "It vanished" is a worse experience than "it is
 * put away", even when the second is the truth.
 *
 * So this screen exists to say so, with counts rather than reassurance, and to
 * offer the three things that can be done about it: bring it back, take a copy
 * of it, or destroy it deliberately.
 *
 * It also lists ORPHANS — content held for a theme whose directory is no
 * longer on disk, because someone removed it over SFTP where no hook could
 * fire. Without this screen that content is unreachable forever.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Parked_Page {

	const PAGE = 'visual-edit-parked';

	public static function init() {
		// Priority 20, matching the other submenus: this file is required
		// before class-editor-page.php, so at the default priority the item
		// would be added before the parent menu it hangs off exists.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_post_clara_ve_park_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_clara_ve_park_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'load-themes.php', array( __CLASS__, 'intercept_delete' ) );
	}

	/**
	 * Stand in front of WordPress's own Delete Theme.
	 *
	 * Core asks "are you sure you want to delete this theme?" and means the
	 * folder. For a converted theme it also means nine pages, six articles,
	 * twenty-five images, three menus and every form submission that came in
	 * through it — none of which core knows about and none of which its
	 * question mentions. An owner cannot consent to something they have not
	 * been told, so the request is sent to a screen that says it.
	 *
	 * Only interposed for a theme this plugin is holding content for, and only
	 * once: the confirmation screen calls delete_theme() itself, and the purge
	 * still runs from the hooks either way, so a deletion arriving by WP-CLI or
	 * the bulk action is cleaned up just the same — it simply does not get the
	 * courtesy of being asked.
	 *
	 * @return void
	 */
	public static function intercept_delete() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_GET['action'] ) || 'delete' !== $_GET['action'] || ! isset( $_GET['stylesheet'] ) ) {
			return;
		}
		// phpcs:enable
		$slug = sanitize_key( wp_unslash( $_GET['stylesheet'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$rows = self::rows();
		if ( ! isset( $rows[ $slug ] ) ) {
			return;
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'confirm' => $slug,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Delete the theme and everything it brought. No going back.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'delete_themes' ) ) {
			wp_die( esc_html__( 'You need permission to delete themes.', 'visual-edit-lite' ) );
		}
		check_admin_referer( 'clara_ve_park_delete' );
		$slug = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';
		if ( '' === $slug || sanitize_key( get_stylesheet() ) === $slug ) {
			wp_die( esc_html__( 'The active theme cannot be deleted.', 'visual-edit-lite' ) );
		}

		// The theme's files first, while delete_theme's own hooks can still
		// read clara-content/ — they are what runs the purge. A theme whose
		// folder is already gone never reaches those hooks, so its content is
		// destroyed here instead.
		if ( wp_get_theme( $slug )->exists() ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/theme.php';
			delete_theme( $slug );
		} else {
			Clara_VE_Theme_Purge::purge( $slug, Clara_VE_Theme_Purge::shared_media( $slug ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'purged' => $slug ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * The confirmation. Everything it lists, it lists from the same inventory
	 * the purge acts on, so the warning cannot promise one thing and do
	 * another.
	 *
	 * @param string $slug
	 * @return void
	 */
	private static function render_confirm( $slug ) {
		$rows = self::rows();
		if ( ! isset( $rows[ $slug ] ) ) {
			return;
		}
		$row   = $rows[ $slug ];
		$i     = $row['inventory'];
		$kept  = Clara_VE_Theme_Purge::shared_media( $slug );
		$total = $i['pages'] + $i['posts'] + $i['images'] + $i['menus'] + $i['terms'] + $i['submissions'] + $i['subscribers'];
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( /* translators: %s: theme name */ __( 'Delete %s and everything it brought', 'visual-edit-lite' ), $row['name'] ) ); ?></h1>

			<div class="notice notice-error inline" style="max-width:52em;margin:1em 0">
				<p><strong><?php esc_html_e( 'This cannot be undone.', 'visual-edit-lite' ); ?></strong></p>
				<p>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of items */
							_n(
								'Deleting this theme also destroys the %d item its import created, permanently. Deactivating a theme puts its content away and is reversible; this is not.',
								'Deleting this theme also destroys the %d items its import created, permanently. Deactivating a theme puts its content away and is reversible; this is not.',
								$total,
								'visual-edit-lite'
							),
							$total
						)
					);
					?>
				</p>
			</div>

			<table class="widefat striped" style="max-width:52em">
				<tbody>
				<?php
				$labels = array(
					'pages'       => __( 'Pages', 'visual-edit-lite' ),
					'posts'       => __( 'Blog posts', 'visual-edit-lite' ),
					'images'      => __( 'Images, and their files', 'visual-edit-lite' ),
					'menus'       => __( 'Menus', 'visual-edit-lite' ),
					'terms'       => __( 'Categories and tags', 'visual-edit-lite' ),
					'submissions' => __( 'Form submissions, including the addresses and IPs in them', 'visual-edit-lite' ),
					'subscribers' => __( 'Mailing-list subscribers, and their consent records', 'visual-edit-lite' ),
					'redirects'   => __( 'Redirects from the original site', 'visual-edit-lite' ),
					'history'     => __( 'Saved versions', 'visual-edit-lite' ),
				);
				foreach ( $labels as $field => $label ) :
					if ( ! $i[ $field ] ) {
						continue;
					}
					?>
					<tr>
						<td style="width:32em"><?php echo esc_html( $label ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( $i[ $field ] ) ); ?></strong></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( $kept ) : ?>
					<tr>
						<td><?php esc_html_e( 'Images shared with another installed theme — these are KEPT', 'visual-edit-lite' ); ?></td>
						<td><strong><?php echo esc_html( number_format_i18n( count( $kept ) ) ); ?></strong></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>

			<?php if ( $i['unattributed_submissions'] ) : ?>
				<p class="description" style="max-width:52em">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of submissions */
							_n(
								'%d form submission on this site predates the plugin recording which theme a submission arrived through. It cannot be attributed to this theme or to any other, so it is NOT deleted and stays under Form Submissions.',
								'%d form submissions on this site predate the plugin recording which theme a submission arrived through. They cannot be attributed to this theme or to any other, so they are NOT deleted and stay under Form Submissions.',
								$i['unattributed_submissions'],
								'visual-edit-lite'
							),
							$i['unattributed_submissions']
						)
					);
					?>
				</p>
			<?php endif; ?>

			<p style="max-width:52em">
				<?php esc_html_e( 'Take a copy first if there is any chance you will want it back. The export is a content package that can be imported into any site that has this theme.', 'visual-edit-lite' ); ?>
			</p>

			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'clara_ve_park_export' ); ?>
					<input type="hidden" name="action" value="clara_ve_park_export">
					<input type="hidden" name="theme" value="<?php echo esc_attr( $slug ); ?>">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Export this content first', 'visual-edit-lite' ); ?></button>
				</form>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'Cancel', 'visual-edit-lite' ); ?></a>
			</p>

			<hr>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'clara_ve_park_delete' ); ?>
				<input type="hidden" name="action" value="clara_ve_park_delete">
				<input type="hidden" name="theme" value="<?php echo esc_attr( $slug ); ?>">
				<p>
					<label>
						<input type="checkbox" name="understood" required>
						<?php esc_html_e( 'I understand this permanently deletes the content listed above.', 'visual-edit-lite' ); ?>
					</label>
				</p>
				<p>
					<button type="submit" class="button button-large" style="background:#b32d2e;border-color:#b32d2e;color:#fff">
						<?php echo esc_html( sprintf( /* translators: %s: theme name */ __( 'Delete %s and its content', 'visual-edit-lite' ), $row['name'] ) ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	public static function menu() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! self::rows() && ! isset( $_GET['purged'] ) ) {
			// Nothing is parked, so a menu item leading to an empty page is
			// only a question the owner has to answer for themselves.
			return;
		}
		add_submenu_page(
			'visual-edit',
			__( 'Parked content', 'visual-edit-lite' ),
			__( 'Parked content', 'visual-edit-lite' ),
			'edit_theme_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * One row per theme this plugin is holding content for and which is not
	 * the active one.
	 *
	 * @return array<string,array>
	 */
	public static function rows() {
		$active = sanitize_key( get_stylesheet() );
		$rows   = array();
		foreach ( Clara_VE_Theme_Registry::all() as $slug => $record ) {
			if ( $slug === $active ) {
				continue;
			}
			$theme     = wp_get_theme( $slug );
			$installed = $theme->exists();
			$inventory = Clara_VE_Theme_Park::inventory( $slug );

			// A theme with nothing held is not being held — it is simply
			// installed and switched off, which is ordinary and needs no
			// screen. Excluding it keeps this page about what it is about.
			$held = 0;
			foreach ( array( 'pages', 'posts', 'images', 'menus', 'terms', 'submissions', 'subscribers', 'sources' ) as $k ) {
				$held += $inventory[ $k ];
			}
			if ( ! $held ) {
				continue;
			}

			$rows[ $slug ] = array(
				'name'      => isset( $record['name'] ) ? $record['name'] : $slug,
				'installed' => $installed,
				'parked'    => ! empty( $record['parked'] ),
				'since'     => isset( $record['parked'] ) ? $record['parked'] : '',
				'options'   => isset( $record['before'] ) ? $record['before'] : null,
				'inventory' => $inventory,
			);
		}
		return $rows;
	}

	/**
	 * Package one parked theme, so "you can back it up first" is a fact rather
	 * than a suggestion. Content only: the theme's own files are already on
	 * disk and, for an orphan, already gone.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You need theme-editing permissions to export content.', 'visual-edit-lite' ) );
		}
		check_admin_referer( 'clara_ve_park_export' );
		$slug = isset( $_POST['theme'] ) ? sanitize_key( wp_unslash( $_POST['theme'] ) ) : '';

		$built = Clara_VE_Bundle_Writer::build(
			array(
				'theme'                => $slug,
				'package'              => 'content',
				'mode'                 => 'site',
				'media'                => 'referenced',
				'include_private_data' => true,
			)
		);
		if ( is_wp_error( $built ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'         => self::PAGE,
						'export_error' => rawurlencode( $built->get_error_message() ),
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}
		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename=' . $built['filename'] );
		header( 'Content-Length: ' . filesize( $built['path'] ) );
		readfile( $built['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile

		Clara_VE_Zip::rrmdir( dirname( $built['path'] ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You need theme-editing permissions to see this.', 'visual-edit-lite' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$confirm = isset( $_GET['confirm'] ) ? sanitize_key( wp_unslash( $_GET['confirm'] ) ) : '';
		if ( '' !== $confirm ) {
			self::render_confirm( $confirm );
			return;
		}
		$purged = isset( $_GET['purged'] ) ? sanitize_key( wp_unslash( $_GET['purged'] ) ) : '';
		$error  = isset( $_GET['export_error'] ) ? sanitize_text_field( wp_unslash( $_GET['export_error'] ) ) : '';
		// phpcs:enable
		$rows = self::rows();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Parked content', 'visual-edit-lite' ); ?></h1>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $error ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $purged ) : ?>
				<div class="notice notice-success">
					<p><?php echo esc_html( sprintf( /* translators: %s: theme slug */ __( '%s and everything it brought have been deleted.', 'visual-edit-lite' ), $purged ) ); ?></p>
				</div>
			<?php endif; ?>
			<?php if ( ! $rows ) : ?>
				<p><?php esc_html_e( 'Nothing is being held for any inactive theme.', 'visual-edit-lite' ); ?></p>
			<?php endif; ?>
			<p class="description" style="max-width:52em">
				<?php
				esc_html_e(
					'These themes are not active, and the content their imports created is put away rather than deleted — invisible on the site and in the ordinary admin lists, exactly as if the theme had never been installed. Activating a theme again brings all of it back where it was.',
					'visual-edit-lite'
				);
				?>
			</p>

			<?php foreach ( $rows as $slug => $row ) : ?>
				<?php $i = $row['inventory']; ?>
				<div class="card" style="max-width:52em;padding:1em 1.5em;margin-top:1.5em">
					<h2 style="margin-top:0">
						<?php echo esc_html( $row['name'] ); ?>
						<code style="font-weight:normal;font-size:.7em"><?php echo esc_html( $slug ); ?></code>
					</h2>

					<?php if ( ! $row['installed'] ) : ?>
						<div class="notice notice-warning inline" style="margin:0 0 1em">
							<p>
								<?php
								esc_html_e(
									'This theme is no longer installed — its folder was removed outside WordPress, so nothing was able to tidy up after it. Its content is still here, listed below. Reinstall the theme to bring it back, or delete the content permanently.',
									'visual-edit-lite'
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<table class="widefat striped" style="margin-bottom:1em">
						<tbody>
						<?php
						$labels = array(
							'pages'       => __( 'Pages', 'visual-edit-lite' ),
							'posts'       => __( 'Blog posts', 'visual-edit-lite' ),
							'images'      => __( 'Images', 'visual-edit-lite' ),
							'menus'       => __( 'Menus', 'visual-edit-lite' ),
							'terms'       => __( 'Categories and tags', 'visual-edit-lite' ),
							'submissions' => __( 'Form submissions', 'visual-edit-lite' ),
							'subscribers' => __( 'Mailing-list subscribers', 'visual-edit-lite' ),
							'redirects'   => __( 'Redirects from the original site', 'visual-edit-lite' ),
							'sources'     => __( 'Editable pages and parts', 'visual-edit-lite' ),
							'history'     => __( 'Saved versions', 'visual-edit-lite' ),
						);
						foreach ( $labels as $field => $label ) :
							if ( ! $i[ $field ] ) {
								continue;
							}
							?>
							<tr>
								<td style="width:22em"><?php echo esc_html( $label ); ?></td>
								<td><strong><?php echo esc_html( number_format_i18n( $i[ $field ] ) ); ?></strong></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( null === $row['options'] ) : ?>
						<p class="description">
							<?php
							esc_html_e(
								'This theme was already here before the plugin started recording what a site looked like beforehand, so its front-page and SEO settings cannot be put back to what they were before it. Everything else restores normally.',
								'visual-edit-lite'
							);
							?>
						</p>
					<?php endif; ?>

					<p>
						<?php if ( $row['installed'] ) : ?>
							<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'themes.php?action=activate&stylesheet=' . rawurlencode( $slug ) ), 'switch-theme_' . $slug ) ); ?>">
								<?php esc_html_e( 'Activate and restore', 'visual-edit-lite' ); ?>
							</a>
						<?php endif; ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
							<?php wp_nonce_field( 'clara_ve_park_export' ); ?>
							<input type="hidden" name="action" value="clara_ve_park_export">
							<input type="hidden" name="theme" value="<?php echo esc_attr( $slug ); ?>">
							<button type="submit" class="button"><?php esc_html_e( 'Export this content', 'visual-edit-lite' ); ?></button>
						</form>
						<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'confirm' => $slug ), admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'Delete permanently…', 'visual-edit-lite' ); ?>
						</a>
					</p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}
}

Clara_VE_Parked_Page::init();
