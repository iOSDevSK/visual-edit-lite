<?php
/**
 * Double opt-in that belongs to this site rather than to an email platform.
 *
 * The flow has two halves. Sending a confirmation email is something ANY
 * transport can do — SMTP, Gmail, SendGrid, Brevo, whatever wp_mail is wired
 * to. Holding the address until the person clicks, and only then handing it on,
 * needs somewhere to keep the pending state. Brevo does both, which is why the
 * first version simply delegated; but that made a compliance flow that half the
 * plugin's users could not have. SendGrid removed double opt-in from its
 * marketing API entirely, and a site on plain SMTP has no platform at all.
 *
 * So this owns the second half: a signed link, a pending row, one endpoint. It
 * is deliberately NOT a subscriber database. What is stored is the interval
 * between asking and confirming, plus the record of consent — who, when, from
 * which address, and the exact wording they agreed to. Campaigns, sequences,
 * unsubscribe management and sender reputation stay with the platform, because
 * those are the parts worth paying someone else to be good at.
 *
 * One consequence worth stating: the proof of consent ends up here, in a table
 * the owner can export, rather than in a provider's log they cannot. Under an
 * audit that is the stronger position.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Optin {

	const DB_VERSION_OPTION = 'clara_ve_optin_db_version';
	// 2: added `theme`, so a subscriber can be told from the design that was
	// live when they signed up. Rows written before it stay empty and are
	// deliberately never guessed at — see the theme lifecycle in
	// Clara_VE_Theme_Registry.
	const DB_VERSION = 2;

	// off | plugin | provider
	const OPT_MODE            = 'clara_ve_optin_mode';
	const OPT_CONFIRM_SUBJECT = 'clara_ve_optin_confirm_subject';
	const OPT_CONFIRM_BODY    = 'clara_ve_optin_confirm_body';
	const OPT_DELIVER_SUBJECT = 'clara_ve_optin_deliver_subject';
	const OPT_DELIVER_BODY    = 'clara_ve_optin_deliver_body';
	const OPT_DELIVER_FILE    = 'clara_ve_optin_deliver_file';

	// A link nobody clicks is not consent withheld, it is a link that got lost.
	// Pending rows are swept rather than kept forever.
	const PENDING_DAYS = 14;

	const PAGE     = 'visual-edit-subscribers';
	const PER_PAGE = 50;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_install' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		// Priority 20, not the default: this class is required before
		// class-editor-page.php, so at default priority its submenu would be
		// added BEFORE the parent menu it hangs off exists — WordPress then
		// registers no page hook for it and answers the URL with a 403, while
		// still drawing the link in the sidebar. A menu item that is visible
		// and unopenable is a particularly confusing way to fail.
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_post_clara_ve_export_optins', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_clara_ve_delete_optin', array( __CLASS__, 'handle_delete' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'visual-edit',
			__( 'Subscribers', 'visual-edit-lite' ),
			__( 'Subscribers', 'visual-edit-lite' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Who asked, who confirmed, and what they agreed to.
	 *
	 * Deliberately a plain table rather than a contact manager: the list a site
	 * actually mails lives at the provider, and duplicating its UI here would
	 * invite people to edit the wrong copy. What this screen is for is the two
	 * questions the provider cannot answer — "did this person really consent,
	 * and to what wording" and "delete everything you hold about me".
	 *
	 * @return void
	 */
	public static function render() {
		global $wpdb;
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need administrator permissions.', 'visual-edit-lite' ) );
		}

		$page   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$offset = ( $page - 1 ) * self::PER_PAGE;
		$total  = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() ); // phpcs:ignore
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', self::PER_PAGE, $offset ) ); // phpcs:ignore
		$pages  = (int) ceil( $total / self::PER_PAGE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Subscribers', 'visual-edit-lite' ); ?></h1>
			<p class="description" style="max-width:760px">
				<?php esc_html_e( 'Everyone who asked to join a list through a form on this site, and whether they confirmed. This is the consent record — the mailing list itself lives at your provider. Unconfirmed rows are deleted automatically after two weeks.', 'visual-edit-lite' ); ?>
			</p>

			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Deleted.', 'visual-edit-lite' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:14px 0">
				<input type="hidden" name="action" value="clara_ve_export_optins" />
				<?php wp_nonce_field( 'clara_ve_export_optins' ); ?>
				<?php submit_button( __( 'Export CSV', 'visual-edit-lite' ), 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-left:10px">
					<?php
					/* translators: %d: number of records. */
					printf( esc_html__( '%d records', 'visual-edit-lite' ), (int) $total );
					?>
				</span>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:24%"><?php esc_html_e( 'Email', 'visual-edit-lite' ); ?></th>
						<th style="width:10%"><?php esc_html_e( 'Status', 'visual-edit-lite' ); ?></th>
						<th style="width:8%"><?php esc_html_e( 'List', 'visual-edit-lite' ); ?></th>
						<th style="width:14%"><?php esc_html_e( 'Asked', 'visual-edit-lite' ); ?></th>
						<th style="width:14%"><?php esc_html_e( 'Confirmed', 'visual-edit-lite' ); ?></th>
						<th><?php esc_html_e( 'Consent given to', 'visual-edit-lite' ); ?></th>
						<th style="width:8%"></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'Nobody yet. A form set to "Mailing list" adds people here.', 'visual-edit-lite' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $row->email ); ?></strong><br /><span class="description"><?php echo esc_html( $row->ip ); ?></span></td>
						<td>
							<?php if ( 'confirmed' === $row->status ) : ?>
								<span style="color:#1a7f37;font-weight:600"><?php esc_html_e( 'Confirmed', 'visual-edit-lite' ); ?></span>
							<?php else : ?>
								<span style="color:#8a6d3b"><?php esc_html_e( 'Waiting', 'visual-edit-lite' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row->list_id ); ?></td>
						<td><?php echo esc_html( $row->created_at ); ?></td>
						<td><?php echo esc_html( $row->confirmed_at ? $row->confirmed_at : '—' ); ?></td>
						<td class="description"><?php echo esc_html( $row->consent_text ? $row->consent_text : __( '(no consent line was shown)', 'visual-edit-lite' ) ); ?></td>
						<td>
							<a class="button-link delete"
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clara_ve_delete_optin&id=' . (int) $row->id ), 'clara_ve_delete_optin_' . (int) $row->id ) ); ?>"
								onclick="return confirm( '<?php echo esc_js( __( 'Delete this record? The consent evidence goes with it.', 'visual-edit-lite' ) ); ?>' );">
								<?php esc_html_e( 'Delete', 'visual-edit-lite' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<p style="margin-top:14px">
					<?php
					echo wp_kses_post(
						paginate_links(
							array(
								'base'    => add_query_arg( 'paged', '%#%' ),
								'format'  => '',
								'current' => $page,
								'total'   => $pages,
							)
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The whole table as CSV — the export an audit asks for, and the one a
	 * migration to another provider needs.
	 *
	 * @return void
	 */
	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need administrator permissions.', 'visual-edit-lite' ) );
		}
		check_admin_referer( 'clara_ve_export_optins' );

		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT email,status,list_id,form_id,ip,created_at,confirmed_at,consent_text FROM ' . self::table() . ' ORDER BY id DESC', ARRAY_A ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

		// A streamed download, not a file on disk: WP_Filesystem has no
		// equivalent for writing to php://output, which is the whole point
		// here — the CSV is never stored anywhere.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fputcsv( $out, array( 'email', 'status', 'list', 'form', 'ip', 'asked', 'confirmed', 'consent_text' ) );
		foreach ( $rows as $row ) {
			fputcsv( $out, $row );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * Erasure, for when someone asks for it.
	 *
	 * @return void
	 */
	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need administrator permissions.', 'visual-edit-lite' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'clara_ve_delete_optin_' . $id );

		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore

		wp_safe_redirect( add_query_arg( 'deleted', '1', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'clara_ve_optins';
	}

	public static function maybe_install() {
		if ( (string) self::DB_VERSION === (string) get_option( self::DB_VERSION_OPTION, '' ) ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(191) NOT NULL,
				list_id varchar(64) NOT NULL DEFAULT '',
				form_id varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				token_hash char(64) NOT NULL,
				consent_text text NULL,
				ip varchar(100) NOT NULL DEFAULT '',
				theme varchar(191) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				confirmed_at datetime NULL,
				PRIMARY KEY (id),
				KEY status_created (status, created_at),
				KEY email_idx (email),
				KEY theme_idx (theme)
			) {$charset};"
		);
		update_option( self::DB_VERSION_OPTION, (string) self::DB_VERSION, false );
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			'/confirm',
			array(
				'methods'             => WP_REST_Server::READABLE,
				// Public by necessity: this is the link in the confirmation
				// email, opened by someone who is not logged in. The signed
				// token is the authorisation.
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'handle_confirm' ),
				'args'                => array(
					'id' => array( 'type' => 'integer', 'required' => true ),
					't'  => array( 'type' => 'string', 'required' => true ),
				),
			)
		);
	}

	/**
	 * @return string off | plugin | provider
	 */
	public static function mode() {
		$mode = (string) get_option( self::OPT_MODE, 'off' );
		return in_array( $mode, array( 'off', 'plugin', 'provider' ), true ) ? $mode : 'off';
	}

	/**
	 * Record the request and email the confirmation link.
	 *
	 * @param string $email
	 * @param string $list_id
	 * @param string $form_id
	 * @param string $ip
	 * @return true|WP_Error
	 */
	public static function start( $email, $list_id, $form_id, $ip ) {
		global $wpdb;
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'clara_ve_optin_email', __( 'A valid email address is required.', 'visual-edit-lite' ) );
		}
		self::sweep();

		// The raw token never touches the database — only its hash, so a leaked
		// table cannot be used to confirm anyone.
		$token = wp_generate_password( 32, false, false );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'email'        => $email,
				'list_id'      => (string) $list_id,
				'form_id'      => (string) $form_id,
				'status'       => 'pending',
				'token_hash'   => hash( 'sha256', $token ),
				'consent_text' => Clara_VE_Form_Settings::consent_enabled() ? Clara_VE_Form_Settings::consent_text() : '',
				'ip'           => (string) $ip,
				'theme'        => Clara_VE_Theme_Registry::active_owner(),
				'created_at'   => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( ! $inserted ) {
			return new WP_Error( 'clara_ve_optin_store', __( 'Could not record the request.', 'visual-edit-lite' ) );
		}

		$url = add_query_arg(
			array( 'id' => (int) $wpdb->insert_id, 't' => $token ),
			rest_url( 'clara-ve/v1/confirm' )
		);

		$sent = wp_mail(
			$email,
			self::text( self::OPT_CONFIRM_SUBJECT, self::default_confirm_subject() ),
			strtr(
				self::text( self::OPT_CONFIRM_BODY, self::default_confirm_body() ),
				array(
					'{confirm_url}' => $url,
					'{site}'        => Clara_VE_Form_Settings::from_name(),
				)
			),
			array( sprintf( 'From: %s <%s>', Clara_VE_Form_Settings::from_name(), Clara_VE_Form_Settings::from_email() ) )
		);

		return $sent ? true : new WP_Error( 'clara_ve_optin_mail', __( 'Could not send the confirmation email.', 'visual-edit-lite' ) );
	}

	/**
	 * The confirmation link. Marks the record confirmed, hands the address to
	 * the provider when one is connected, sends whatever was promised, and puts
	 * the person somewhere that says it worked.
	 *
	 * @param WP_REST_Request $request
	 * @return void
	 */
	public static function handle_confirm( WP_REST_Request $request ) {
		global $wpdb;
		$id  = (int) $request->get_param( 'id' );
		$raw = (string) $request->get_param( 't' );

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) ); // phpcs:ignore

		// One landing page for every outcome, deliberately. A link opened twice
		// (the second time from a mail client's prefetch, or a week later out of
		// curiosity) must not read as a failure, and a wrong token must not
		// confirm anything — so both end on the same page and only the first
		// one does any work.
		if ( ! $row || ! hash_equals( (string) $row->token_hash, hash( 'sha256', $raw ) ) ) {
			self::land();
		}

		if ( 'confirmed' !== $row->status ) {
			$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				self::table(),
				array( 'status' => 'confirmed', 'confirmed_at' => current_time( 'mysql' ) ),
				array( 'id' => $id ),
				array( '%s', '%s' ),
				array( '%d' )
			);

			// Now, and only now, does the address reach the platform.
			if ( Clara_VE_Lists::ready() && '' !== (string) $row->list_id ) {
				Clara_VE_Lists::subscribe( $row->list_id, $row->email, array() );
			}

			self::deliver( $row->email );
		}

		self::land();
	}

	/**
	 * The email carrying what was promised. Sent on confirmation rather than on
	 * submission, which is the entire point: nobody receives the download until
	 * they have proved the address is theirs.
	 *
	 * @param string $email
	 * @return void
	 */
	private static function deliver( $email ) {
		$body = self::text( self::OPT_DELIVER_BODY, self::default_deliver_body() );
		$file = (string) get_option( self::OPT_DELIVER_FILE, '' );
		if ( '' === trim( $body ) && '' === $file ) {
			return;
		}
		wp_mail(
			$email,
			self::text( self::OPT_DELIVER_SUBJECT, self::default_deliver_subject() ),
			strtr(
				$body,
				array(
					'{download_url}' => $file,
					'{site}'         => Clara_VE_Form_Settings::from_name(),
				)
			),
			array( sprintf( 'From: %s <%s>', Clara_VE_Form_Settings::from_name(), Clara_VE_Form_Settings::from_email() ) )
		);
	}

	/**
	 * @return void Never returns — redirects.
	 */
	private static function land() {
		$url = (string) get_option( Clara_VE_Lists::OPT_DOI_REDIRECT, '' );
		wp_safe_redirect( $url ? $url : home_url( '/' ) );
		exit;
	}

	/**
	 * Drop pending rows nobody confirmed. Confirmed ones stay: they are the
	 * consent record.
	 *
	 * @return void
	 */
	private static function sweep() {
		global $wpdb;
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery, phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->prepare(
				'DELETE FROM ' . self::table() . " WHERE status = 'pending' AND created_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				gmdate( 'Y-m-d H:i:s', time() - ( self::PENDING_DAYS * DAY_IN_SECONDS ) )
			)
		);
	}

	/**
	 * @param string $option
	 * @param string $fallback
	 * @return string
	 */
	private static function text( $option, $fallback ) {
		$value = trim( (string) get_option( $option, '' ) );
		return '' !== $value ? $value : $fallback;
	}

	public static function default_confirm_subject() {
		return __( 'Please confirm your email address', 'visual-edit-lite' );
	}

	public static function default_confirm_body() {
		return __( "Thanks for signing up. Please confirm this is your address by opening the link below:\n\n{confirm_url}\n\nIf you didn't ask for this, ignore this email and nothing further will happen.", 'visual-edit-lite' );
	}

	public static function default_deliver_subject() {
		return __( 'Here it is', 'visual-edit-lite' );
	}

	public static function default_deliver_body() {
		return __( "Thanks for confirming — here's what you signed up for:\n\n{download_url}", 'visual-edit-lite' );
	}
}

Clara_VE_Optin::init();
