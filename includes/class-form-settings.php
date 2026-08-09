<?php
/**
 * "Form Settings" admin screen + the mail/anti-spam options the rest of the
 * plugin reads.
 *
 * The whole point of these options is to let a NON-TECHNICAL site owner
 * control where form submissions go, who they appear to be from, how
 * aggressive the anti-spam is, and — crucially — how email actually leaves
 * the site (which provider), all from one screen without touching code or
 * installing a separate SMTP plugin.
 *
 * This class owns the option names, their admin UI, and the getters. The
 * actual mail transport (SMTP via phpmailer_init, or a provider's HTTP API
 * via pre_wp_mail) lives in class-mailer.php, which reads the getters here.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Form_Settings {

	const GROUP = 'clara_ve_form_settings';
	const PAGE  = 'visual-edit-forms';

	const OPT_TO               = 'clara_ve_form_to';
	const OPT_FROM_NAME        = 'clara_ve_form_from_name';
	const OPT_FROM_EMAIL       = 'clara_ve_form_from_email';
	const OPT_MIN_SECONDS      = 'clara_ve_form_min_seconds';
	const OPT_AKISMET          = 'clara_ve_form_akismet';

	// Which transport sends mail: default | smtp | brevo | sendgrid | postmark | mailgun.
	const OPT_MAILER = 'clara_ve_mailer';

	// Generic SMTP.
	const OPT_SMTP_HOST       = 'clara_ve_smtp_host';
	const OPT_SMTP_PORT       = 'clara_ve_smtp_port';
	const OPT_SMTP_ENCRYPTION = 'clara_ve_smtp_encryption';
	const OPT_SMTP_AUTH       = 'clara_ve_smtp_auth';
	const OPT_SMTP_USER       = 'clara_ve_smtp_user';
	const OPT_SMTP_PASS       = 'clara_ve_smtp_pass';

	// Per-provider HTTP-API keys (encrypted at rest).
	const OPT_API_BREVO     = 'clara_ve_api_brevo';
	const OPT_API_SENDGRID  = 'clara_ve_api_sendgrid';
	const OPT_API_POSTMARK  = 'clara_ve_api_postmark';
	const OPT_API_MAILGUN   = 'clara_ve_api_mailgun';
	const OPT_MAILGUN_DOMAIN = 'clara_ve_mailgun_domain';
	const OPT_MAILGUN_REGION = 'clara_ve_mailgun_region';

	const API_MAILERS = array( 'brevo', 'sendgrid', 'postmark', 'mailgun' );

	// Set once the stored secrets have been checked for the double-encryption
	// keep_or_encrypt() used to produce.
	const OPT_SECRETS_REPAIRED = 'clara_ve_form_secrets_repaired';

	// GDPR consent note under connected forms. Optional by design: plenty of
	// forms (an internal enquiry, a site outside the EU) do not want it, and a
	// note nobody chose is a note nobody maintains.
	const OPT_CONSENT      = 'clara_ve_form_consent';
	const OPT_CONSENT_TEXT = 'clara_ve_form_consent_text';

	// Whether uninstalling the plugin also deletes stored content and data
	// (submissions, subscribers, edited page sources, history). Off by
	// default: an uninstall must never silently destroy a subscriber list or
	// the owner's edits — secrets and scheduled work are removed regardless,
	// see uninstall.php.
	const OPT_REMOVE_DATA = 'clara_ve_remove_all_data';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		// After register_settings (same hook, later priority): the repair writes
		// through update_option, so the sanitize filter must already be attached
		// for the guard in keep_or_encrypt to pass the value through untouched.
		add_action( 'admin_init', array( __CLASS__, 'repair_double_encrypted_secrets' ), 20 );
		add_action( 'admin_post_clara_ve_send_test_email', array( __CLASS__, 'handle_test_email' ) );
	}

	/**
	 * Undo the damage keep_or_encrypt() did before it learned to recognise its
	 * own ciphertext: every secret saved for the first time was encrypted twice.
	 * Fixing the sanitizer stops it happening again but cannot un-nest what is
	 * already stored, and the symptom — a provider rejecting a key that the
	 * settings page swears is saved — gives the owner nothing to act on.
	 *
	 * Detection is the same test the guard uses, applied one level deeper: if a
	 * decrypted secret decrypts AGAIN, it was nested, and the inner value is the
	 * correct single-encrypted form. A correctly stored secret decrypts to an API
	 * key, which is not our base64 IV+cipher shape and stops there.
	 *
	 * Runs once per site; the flag is set even when nothing needed fixing.
	 *
	 * @return void
	 */
	public static function repair_double_encrypted_secrets() {
		if ( '1' === (string) get_option( self::OPT_SECRETS_REPAIRED, '' ) ) {
			return;
		}
		foreach ( self::secret_options() as $option ) {
			$stored = (string) get_option( $option, '' );
			if ( '' === $stored ) {
				continue;
			}
			$once = self::decrypt( $stored );
			if ( '' === $once || '' === self::decrypt( $once ) ) {
				continue; // Not nested — either plain ciphertext or not ours.
			}
			update_option( $option, $once, false );
		}
		update_option( self::OPT_SECRETS_REPAIRED, '1', false );
	}

	/**
	 * @return string[] Options holding an encrypted secret.
	 */
	private static function secret_options() {
		return array(
			self::OPT_SMTP_PASS,
			self::OPT_API_BREVO,
			self::OPT_API_SENDGRID,
			self::OPT_API_POSTMARK,
			self::OPT_API_MAILGUN,
		);
	}

	public static function register_page() {
		add_submenu_page(
			'visual-edit',
			__( 'Form Settings', 'visual-edit-lite' ),
			__( 'Form Settings', 'visual-edit-lite' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings() {
		$str   = array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '', 'autoload' => false );
		$email = array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_email_or_empty' ), 'default' => '', 'autoload' => false );

		register_setting( self::GROUP, self::OPT_TO, $email );
		register_setting( self::GROUP, self::OPT_FROM_NAME, $str );
		register_setting( self::GROUP, self::OPT_FROM_EMAIL, $email );
		register_setting( self::GROUP, self::OPT_MIN_SECONDS, array( 'type' => 'integer', 'sanitize_callback' => array( __CLASS__, 'sanitize_min_seconds' ), 'default' => 3, 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_AKISMET, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => '', 'autoload' => false ) );

		register_setting( self::GROUP, self::OPT_MAILER, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_mailer' ), 'default' => 'default', 'autoload' => false ) );

		register_setting( self::GROUP, self::OPT_SMTP_HOST, $str );
		register_setting( self::GROUP, self::OPT_SMTP_PORT, array( 'type' => 'integer', 'sanitize_callback' => array( __CLASS__, 'sanitize_port' ), 'default' => 587, 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_SMTP_ENCRYPTION, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_encryption' ), 'default' => 'tls', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_SMTP_AUTH, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => '1', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_SMTP_USER, $str );
		// Secrets are encrypted at rest; each sanitizer keeps the existing value
		// when its field is submitted blank (so saving other settings never
		// wipes a stored key/password — the UI never echoes them back).
		register_setting( self::GROUP, self::OPT_SMTP_PASS, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_smtp_pass' ), 'default' => '', 'autoload' => false ) );

		register_setting( self::GROUP, self::OPT_API_BREVO, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_api_brevo' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_API_SENDGRID, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_api_sendgrid' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_API_POSTMARK, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_api_postmark' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_API_MAILGUN, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_api_mailgun' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_MAILGUN_DOMAIN, $str );
		// Mailing list: a separate axis from the mailer above. Most transports
		// have no lists at all, and a site can perfectly well send its contact
		// mail through Gmail while its subscribers live in Brevo.
		register_setting( self::GROUP, self::OPT_REMOVE_DATA, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_CONSENT, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_CONSENT_TEXT, array( 'type' => 'string', 'sanitize_callback' => 'wp_kses_post', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Lists::OPT_PROVIDER, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_list_provider' ), 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_MODE, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_optin_mode' ), 'default' => 'off', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_CONFIRM_SUBJECT, $str );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_CONFIRM_BODY, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_DELIVER_SUBJECT, $str );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_DELIVER_BODY, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Optin::OPT_DELIVER_FILE, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Lists::OPT_DOI_TEMPLATE, array( 'type' => 'string', 'sanitize_callback' => 'absint', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, Clara_VE_Lists::OPT_DOI_REDIRECT, array( 'type' => 'string', 'sanitize_callback' => 'esc_url_raw', 'default' => '', 'autoload' => false ) );
		register_setting( self::GROUP, self::OPT_MAILGUN_REGION, array( 'type' => 'string', 'sanitize_callback' => array( __CLASS__, 'sanitize_region' ), 'default' => 'us', 'autoload' => false ) );
	}

	// ---- Sanitizers ----

	public static function sanitize_optin_mode( $raw ) {
		$raw = (string) $raw;
		return in_array( $raw, array( 'off', 'plugin', 'provider' ), true ) ? $raw : 'off';
	}

	public static function sanitize_list_provider( $raw ) {
		$raw = (string) $raw;
		return array_key_exists( $raw, Clara_VE_Lists::providers() ) ? $raw : '';
	}

	public static function sanitize_mailer( $raw ) {
		$raw   = (string) $raw;
		$valid = array_merge( array( 'default', 'smtp' ), self::API_MAILERS );
		return in_array( $raw, $valid, true ) ? $raw : 'default';
	}

	public static function sanitize_port( $raw ) {
		$n = (int) $raw;
		return ( $n >= 1 && $n <= 65535 ) ? $n : 587;
	}

	public static function sanitize_encryption( $raw ) {
		$raw = (string) $raw;
		return in_array( $raw, array( 'none', 'ssl', 'tls' ), true ) ? $raw : 'tls';
	}

	public static function sanitize_region( $raw ) {
		return 'eu' === (string) $raw ? 'eu' : 'us';
	}

	public static function sanitize_email_or_empty( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return '';
		}
		$email = sanitize_email( $raw );
		return is_email( $email ) ? $email : '';
	}

	public static function sanitize_min_seconds( $raw ) {
		$n = (int) $raw;
		return max( 0, min( 60, $n ) );
	}

	public static function sanitize_checkbox( $raw ) {
		return ! empty( $raw ) ? '1' : '';
	}

	// Encrypt-or-keep for each secret (register_setting hands the callback only
	// the value, so each secret needs its own tiny wrapper naming its option).
	private static function keep_or_encrypt( $option, $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return (string) get_option( $option, '' );
		}
		// WordPress runs this sanitizer TWICE on a first-ever save: update_option
		// sanitizes (wp-includes/option.php), finds no existing row, and hands
		// off to add_option, which sanitizes again. The second pass receives the
		// ciphertext the first pass produced, so without this guard the secret is
		// encrypted twice and a single decrypt() later returns the inner base64
		// blob — a value that is present, printable, and completely wrong. Brevo
		// answers "Key not found" and nothing in the site says why.
		//
		// A real API key never decrypts (it isn't our base64 IV+cipher shape), so
		// a value that DOES decrypt cleanly is already ours. Same guard, same
		// reasoning as sanitize_secret() in class-ai-settings.php, which hit this
		// first; this class was written without it.
		if ( '' !== self::decrypt( $raw ) ) {
			return $raw;
		}
		return self::encrypt( $raw );
	}
	public static function sanitize_smtp_pass( $raw ) { return self::keep_or_encrypt( self::OPT_SMTP_PASS, $raw ); }
	public static function sanitize_api_brevo( $raw ) { return self::keep_or_encrypt( self::OPT_API_BREVO, $raw ); }
	public static function sanitize_api_sendgrid( $raw ) { return self::keep_or_encrypt( self::OPT_API_SENDGRID, $raw ); }
	public static function sanitize_api_postmark( $raw ) { return self::keep_or_encrypt( self::OPT_API_POSTMARK, $raw ); }
	public static function sanitize_api_mailgun( $raw ) { return self::keep_or_encrypt( self::OPT_API_MAILGUN, $raw ); }

	// ---- Admin page ----

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need administrator permissions to manage form settings.', 'visual-edit-lite' ) );
		}
		// The file picker below is wp.media; without this it is a dead button.
		wp_enqueue_media();
		$akismet_ready = self::akismet_available();
		$mailer        = self::mailer();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form Settings', 'visual-edit-lite' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Where contact-form submissions are sent, how they are protected from spam, and how email leaves the site. These apply to every [wp-form] on the site; a form can still override the recipient with its own to="" attribute.', 'visual-edit-lite' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_TO ); ?>"><?php esc_html_e( 'Send submissions to', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="<?php echo esc_attr( self::OPT_TO ); ?>" name="<?php echo esc_attr( self::OPT_TO ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_TO, '' ) ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to use the site admin email.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_FROM_NAME ); ?>"><?php esc_html_e( 'From name', 'visual-edit-lite' ); ?></label></th>
						<td><input type="text" class="regular-text" id="<?php echo esc_attr( self::OPT_FROM_NAME ); ?>" name="<?php echo esc_attr( self::OPT_FROM_NAME ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_FROM_NAME, '' ) ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_FROM_EMAIL ); ?>"><?php esc_html_e( 'From address', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="email" class="regular-text" id="<?php echo esc_attr( self::OPT_FROM_EMAIL ); ?>" name="<?php echo esc_attr( self::OPT_FROM_EMAIL ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_FROM_EMAIL, '' ) ); ?>" placeholder="<?php echo esc_attr( self::default_from_email() ); ?>" />
							<p class="description"><?php esc_html_e( 'Must be an address on this site\'s own domain — sending "from" a visitor\'s Gmail fails spam checks. The visitor\'s own address is set as Reply-To so you can reply directly.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( self::OPT_MIN_SECONDS ); ?>"><?php esc_html_e( 'Minimum fill time (seconds)', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="number" min="0" max="60" step="1" id="<?php echo esc_attr( self::OPT_MIN_SECONDS ); ?>" name="<?php echo esc_attr( self::OPT_MIN_SECONDS ); ?>" value="<?php echo esc_attr( (string) self::min_seconds() ); ?>" />
							<p class="description"><?php esc_html_e( 'Submissions sent faster than this after the page loads are treated as bots and rejected. 0 disables the check. 3 is a good default.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Akismet spam filtering', 'visual-edit-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_AKISMET ); ?>" value="1" <?php checked( '1', get_option( self::OPT_AKISMET, '' ) ); ?> <?php disabled( ! $akismet_ready ); ?> /> <?php esc_html_e( 'Check submissions against Akismet', 'visual-edit-lite' ); ?></label>
							<p class="description"><?php echo $akismet_ready ? esc_html__( 'Akismet is installed and configured — recommended.', 'visual-edit-lite' ) : esc_html__( 'Install and configure the free Akismet plugin to enable this.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Email delivery', 'visual-edit-lite' ); ?></h2>
				<p class="description" style="max-width:680px"><?php esc_html_e( 'Pick how the site sends email. WordPress\'s default server mail very often lands in spam — connect a real provider for reliable delivery, no extra plugin needed. The API options send over HTTPS, so they work even on hosts that block outbound SMTP ports. For best results also add SPF/DKIM DNS records for your domain at your provider.', 'visual-edit-lite' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="clara_ve_mailer"><?php esc_html_e( 'Mailer', 'visual-edit-lite' ); ?></label></th>
						<td>
							<select id="clara_ve_mailer" name="<?php echo esc_attr( self::OPT_MAILER ); ?>">
								<option value="default" <?php selected( 'default', $mailer ); ?>><?php esc_html_e( 'Default (WordPress server mail)', 'visual-edit-lite' ); ?></option>
								<option value="smtp" <?php selected( 'smtp', $mailer ); ?>><?php esc_html_e( 'Other SMTP', 'visual-edit-lite' ); ?></option>
								<option value="brevo" <?php selected( 'brevo', $mailer ); ?>><?php esc_html_e( 'Brevo (API)', 'visual-edit-lite' ); ?></option>
								<option value="sendgrid" <?php selected( 'sendgrid', $mailer ); ?>><?php esc_html_e( 'SendGrid (API)', 'visual-edit-lite' ); ?></option>
								<option value="postmark" <?php selected( 'postmark', $mailer ); ?>><?php esc_html_e( 'Postmark (API)', 'visual-edit-lite' ); ?></option>
								<option value="mailgun" <?php selected( 'mailgun', $mailer ); ?>><?php esc_html_e( 'Mailgun (API)', 'visual-edit-lite' ); ?></option>
							</select>
						</td>
					</tr>
				</table>

				<div class="cve-mailer-section" data-mailer="default">
					<p class="description" style="max-width:680px"><?php esc_html_e( 'WordPress sends mail directly from the web server. Fine for a quick test, but it commonly lands in spam or is dropped in production — pick a real provider above.', 'visual-edit-lite' ); ?></p>
				</div>

				<div class="cve-mailer-section" data-mailer="smtp">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_SMTP_HOST ); ?>"><?php esc_html_e( 'SMTP host', 'visual-edit-lite' ); ?></label></th>
							<td><input type="text" class="regular-text" id="<?php echo esc_attr( self::OPT_SMTP_HOST ); ?>" name="<?php echo esc_attr( self::OPT_SMTP_HOST ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_SMTP_HOST, '' ) ); ?>" placeholder="smtp.example.com" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_SMTP_PORT ); ?>"><?php esc_html_e( 'Port', 'visual-edit-lite' ); ?></label></th>
							<td>
								<input type="number" min="1" max="65535" step="1" id="<?php echo esc_attr( self::OPT_SMTP_PORT ); ?>" name="<?php echo esc_attr( self::OPT_SMTP_PORT ); ?>" value="<?php echo esc_attr( (string) self::smtp_port() ); ?>" />
								<p class="description"><?php esc_html_e( '587 for TLS (most common), 465 for SSL, 25 for none.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_SMTP_ENCRYPTION ); ?>"><?php esc_html_e( 'Encryption', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php $enc = self::smtp_encryption(); ?>
								<select id="<?php echo esc_attr( self::OPT_SMTP_ENCRYPTION ); ?>" name="<?php echo esc_attr( self::OPT_SMTP_ENCRYPTION ); ?>">
									<option value="tls" <?php selected( 'tls', $enc ); ?>><?php esc_html_e( 'TLS (recommended)', 'visual-edit-lite' ); ?></option>
									<option value="ssl" <?php selected( 'ssl', $enc ); ?>>SSL</option>
									<option value="none" <?php selected( 'none', $enc ); ?>><?php esc_html_e( 'None', 'visual-edit-lite' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Authentication', 'visual-edit-lite' ); ?></th>
							<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_SMTP_AUTH ); ?>" value="1" <?php checked( '1', get_option( self::OPT_SMTP_AUTH, '1' ) ); ?> /> <?php esc_html_e( 'This server requires a username and password', 'visual-edit-lite' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_SMTP_USER ); ?>"><?php esc_html_e( 'Username', 'visual-edit-lite' ); ?></label></th>
							<td><input type="text" class="regular-text" id="<?php echo esc_attr( self::OPT_SMTP_USER ); ?>" name="<?php echo esc_attr( self::OPT_SMTP_USER ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_SMTP_USER, '' ) ); ?>" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_SMTP_PASS ); ?>"><?php esc_html_e( 'Password', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php self::secret_input( self::OPT_SMTP_PASS ); ?>
								<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the saved password.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="cve-mailer-section" data-mailer="brevo">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_API_BREVO ); ?>"><?php esc_html_e( 'Brevo API key', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php self::secret_input( self::OPT_API_BREVO ); ?>
								<p class="description"><?php esc_html_e( 'Brevo → SMTP & API → API Keys (v3). Stored encrypted; leave blank to keep.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="cve-mailer-section" data-mailer="sendgrid">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_API_SENDGRID ); ?>"><?php esc_html_e( 'SendGrid API key', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php self::secret_input( self::OPT_API_SENDGRID ); ?>
								<p class="description"><?php esc_html_e( 'SendGrid → Settings → API Keys (Mail Send permission). Stored encrypted; leave blank to keep.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="cve-mailer-section" data-mailer="postmark">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_API_POSTMARK ); ?>"><?php esc_html_e( 'Postmark server token', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php self::secret_input( self::OPT_API_POSTMARK ); ?>
								<p class="description"><?php esc_html_e( 'Postmark → your Server → API Tokens. Stored encrypted; leave blank to keep.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="cve-mailer-section" data-mailer="mailgun">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_API_MAILGUN ); ?>"><?php esc_html_e( 'Mailgun API key', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php self::secret_input( self::OPT_API_MAILGUN ); ?>
								<p class="description"><?php esc_html_e( 'Mailgun → Send → API keys. Stored encrypted; leave blank to keep.', 'visual-edit-lite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_MAILGUN_DOMAIN ); ?>"><?php esc_html_e( 'Mailgun domain', 'visual-edit-lite' ); ?></label></th>
							<td><input type="text" class="regular-text" id="<?php echo esc_attr( self::OPT_MAILGUN_DOMAIN ); ?>" name="<?php echo esc_attr( self::OPT_MAILGUN_DOMAIN ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_MAILGUN_DOMAIN, '' ) ); ?>" placeholder="mg.example.com" autocomplete="off" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( self::OPT_MAILGUN_REGION ); ?>"><?php esc_html_e( 'Region', 'visual-edit-lite' ); ?></label></th>
							<td>
								<?php $region = self::mailgun_region(); ?>
								<select id="<?php echo esc_attr( self::OPT_MAILGUN_REGION ); ?>" name="<?php echo esc_attr( self::OPT_MAILGUN_REGION ); ?>">
									<option value="us" <?php selected( 'us', $region ); ?>>US</option>
									<option value="eu" <?php selected( 'eu', $region ); ?>>EU</option>
								</select>
							</td>
						</tr>
					</table>
				</div>

				<script>
				( function () {
					// The opt-in rows that belong to the mode not chosen are
					// noise: three email fields for a flow the site is not
					// running.
					var mode = document.getElementById( 'clara_ve_optin_mode' );
					function syncOptin() {
						var rows = document.querySelectorAll( '.cve-optin-row' );
						for ( var i = 0; i < rows.length; i++ ) {
							rows[ i ].style.display = ( rows[ i ].getAttribute( 'data-optin' ) === mode.value ) ? '' : 'none';
						}
					}
					if ( mode ) {
						mode.addEventListener( 'change', syncOptin );
						syncOptin();
					}
					var pick = document.getElementById( 'cve-pick-file' );
					if ( pick && window.wp && window.wp.media ) {
						pick.addEventListener( 'click', function () {
							var frame = window.wp.media( { title: 'Choose the file', multiple: false } );
							frame.on( 'select', function () {
								var att = frame.state().get( 'selection' ).first().toJSON();
								document.getElementById( 'clara_ve_optin_deliver_file' ).value = att.url;
							} );
							frame.open();
						} );
					}
				} )();
				</script>

				<script>
				( function () {
					var sel = document.getElementById( 'clara_ve_mailer' );
					if ( ! sel ) { return; }
					function sync() {
						var v = sel.value;
						var boxes = document.querySelectorAll( '.cve-mailer-section' );
						for ( var i = 0; i < boxes.length; i++ ) {
							boxes[ i ].style.display = ( boxes[ i ].getAttribute( 'data-mailer' ) === v ) ? '' : 'none';
						}
					}
					sel.addEventListener( 'change', sync );
					sync();
				} )();
				</script>

				<h2><?php esc_html_e( 'Mailing list', 'visual-edit-lite' ); ?></h2>
				<p class="description" style="max-width:680px">
					<?php esc_html_e( 'Where a form set to "Mailing list" sends its subscribers. This is separate from the mailer above — the mailer is how email leaves this site, a mailing list is where contacts live and where the welcome email and the download come from. Most transports have no lists at all, so the two are chosen independently.', 'visual-edit-lite' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Lists::OPT_PROVIDER ); ?>"><?php esc_html_e( 'Provider', 'visual-edit-lite' ); ?></label></th>
						<td>
							<select id="<?php echo esc_attr( Clara_VE_Lists::OPT_PROVIDER ); ?>" name="<?php echo esc_attr( Clara_VE_Lists::OPT_PROVIDER ); ?>">
								<?php foreach ( Clara_VE_Lists::providers() as $slug => $label ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, Clara_VE_Lists::provider() ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php
								if ( 'brevo' === Clara_VE_Lists::provider() && ! Clara_VE_Lists::ready() ) {
									echo '<strong>' . esc_html__( 'Add the Brevo API key above — the list uses the same key as the mailer.', 'visual-edit-lite' ) . '</strong>';
								} else {
									esc_html_e( 'Brevo reuses the API key you already entered above; there is nothing else to paste.', 'visual-edit-lite' );
								}
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Double opt-in', 'visual-edit-lite' ); ?></th>
						<td>
							<select id="<?php echo esc_attr( Clara_VE_Optin::OPT_MODE ); ?>" name="<?php echo esc_attr( Clara_VE_Optin::OPT_MODE ); ?>">
								<option value="off" <?php selected( 'off', Clara_VE_Optin::mode() ); ?>><?php esc_html_e( 'Off — subscribe immediately', 'visual-edit-lite' ); ?></option>
								<option value="plugin" <?php selected( 'plugin', Clara_VE_Optin::mode() ); ?>><?php esc_html_e( 'On, handled here — works with any mailer', 'visual-edit-lite' ); ?></option>
								<option value="provider" <?php selected( 'provider', Clara_VE_Optin::mode() ); ?>><?php esc_html_e( 'On, handled by the provider', 'visual-edit-lite' ); ?></option>
							</select>
							<p class="description" style="max-width:680px">
								<?php esc_html_e( 'Handled here: the address is held, a confirmation email goes out through your normal mailer, and only a click subscribes it and sends the download. Works on plain SMTP, Gmail, SendGrid — anything. The consent record (time, IP, wording) stays in this site. Handled by the provider: Brevo does the same thing with its own template, set below.', 'visual-edit-lite' ); ?>
							</p>
						</td>
					</tr>
					<tr class="cve-optin-row" data-optin="plugin">
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Optin::OPT_CONFIRM_SUBJECT ); ?>"><?php esc_html_e( 'Confirmation email', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="<?php echo esc_attr( Clara_VE_Optin::OPT_CONFIRM_SUBJECT ); ?>" name="<?php echo esc_attr( Clara_VE_Optin::OPT_CONFIRM_SUBJECT ); ?>" value="<?php echo esc_attr( (string) get_option( Clara_VE_Optin::OPT_CONFIRM_SUBJECT, '' ) ); ?>" placeholder="<?php echo esc_attr( Clara_VE_Optin::default_confirm_subject() ); ?>" />
							<p style="margin-top:8px"><textarea class="large-text" rows="5" name="<?php echo esc_attr( Clara_VE_Optin::OPT_CONFIRM_BODY ); ?>" placeholder="<?php echo esc_attr( Clara_VE_Optin::default_confirm_body() ); ?>"><?php echo esc_textarea( (string) get_option( Clara_VE_Optin::OPT_CONFIRM_BODY, '' ) ); ?></textarea></p>
							<p class="description"><?php esc_html_e( 'Must contain {confirm_url} — that is the link. {site} is your From name.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr class="cve-optin-row" data-optin="plugin">
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_SUBJECT ); ?>"><?php esc_html_e( 'Delivery email', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="text" class="large-text" id="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_SUBJECT ); ?>" name="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_SUBJECT ); ?>" value="<?php echo esc_attr( (string) get_option( Clara_VE_Optin::OPT_DELIVER_SUBJECT, '' ) ); ?>" placeholder="<?php echo esc_attr( Clara_VE_Optin::default_deliver_subject() ); ?>" />
							<p style="margin-top:8px"><textarea class="large-text" rows="4" name="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_BODY ); ?>" placeholder="<?php echo esc_attr( Clara_VE_Optin::default_deliver_body() ); ?>"><?php echo esc_textarea( (string) get_option( Clara_VE_Optin::OPT_DELIVER_BODY, '' ) ); ?></textarea></p>
							<p class="description"><?php esc_html_e( 'Sent after they confirm, never before. {download_url} is the file below.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr class="cve-optin-row" data-optin="plugin">
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_FILE ); ?>"><?php esc_html_e( 'The file they get', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="url" class="large-text" id="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_FILE ); ?>" name="<?php echo esc_attr( Clara_VE_Optin::OPT_DELIVER_FILE ); ?>" value="<?php echo esc_attr( (string) get_option( Clara_VE_Optin::OPT_DELIVER_FILE, '' ) ); ?>" />
							<p style="margin-top:8px"><button type="button" class="button" id="cve-pick-file"><?php esc_html_e( 'Choose from Media Library', 'visual-edit-lite' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Upload the PDF to Media and pick it here. Leave blank if the confirmation email is all you send.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr class="cve-optin-row" data-optin="provider">
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_TEMPLATE ); ?>"><?php esc_html_e( 'Provider template id', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="number" min="0" step="1" class="small-text" id="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_TEMPLATE ); ?>" name="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_TEMPLATE ); ?>" value="<?php echo esc_attr( (string) get_option( Clara_VE_Lists::OPT_DOI_TEMPLATE, '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'The id of a double opt-in template at your provider. Only used when the provider handles it.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_REDIRECT ); ?>"><?php esc_html_e( 'After confirming, go to', 'visual-edit-lite' ); ?></label></th>
						<td>
							<input type="url" class="regular-text" id="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_REDIRECT ); ?>" name="<?php echo esc_attr( Clara_VE_Lists::OPT_DOI_REDIRECT ); ?>" value="<?php echo esc_attr( (string) get_option( Clara_VE_Lists::OPT_DOI_REDIRECT, '' ) ); ?>" placeholder="<?php echo esc_attr( home_url( '/' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Where the confirmation link lands. Only used when a double opt-in template is set.', 'visual-edit-lite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Consent line', 'visual-edit-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_CONSENT ); ?>" value="1" <?php checked( '1', get_option( self::OPT_CONSENT, '' ) ); ?> /> <?php esc_html_e( 'Show a consent note under every connected form', 'visual-edit-lite' ); ?></label>
							<p style="margin-top:10px"><input type="text" class="large-text" name="<?php echo esc_attr( self::OPT_CONSENT_TEXT ); ?>" value="<?php echo esc_attr( (string) get_option( self::OPT_CONSENT_TEXT, '' ) ); ?>" placeholder="<?php echo esc_attr( self::default_consent_text() ); ?>" /></p>
							<p class="description" style="max-width:680px">
								<?php esc_html_e( 'Off by default — turn it on if you collect addresses under GDPR and want the note visible at the point of consent. Link to your privacy policy inside the text with normal HTML: <a href="/privacy/">Privacy Policy</a>.', 'visual-edit-lite' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<hr />
				<h2><?php esc_html_e( 'Uninstall', 'visual-edit-lite' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'On plugin deletion', 'visual-edit-lite' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( self::OPT_REMOVE_DATA ); ?>" value="1" <?php checked( '1', get_option( self::OPT_REMOVE_DATA, '' ) ); ?> /> <?php esc_html_e( 'Also delete all stored data — form submissions, subscribers, edit history and edited page sources', 'visual-edit-lite' ); ?></label>
							<p class="description" style="max-width:680px">
								<?php esc_html_e( 'Off: deleting the plugin keeps your data in the database, so reinstalling picks up where you left off. On: deleting the plugin removes everything it ever stored. Saved passwords and API keys are removed on deletion either way.', 'visual-edit-lite' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Send a test email', 'visual-edit-lite' ); ?></h2>
			<?php if ( isset( $_GET['test_sent'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice <?php echo '1' === $_GET['test_sent'] ? 'notice-success' : 'notice-error'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?> inline">
					<p><?php echo '1' === $_GET['test_sent'] // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						? esc_html__( 'Test email sent. Check the inbox (and spam folder).', 'visual-edit-lite' )
						: esc_html__( 'Sending failed — check the mailer credentials above (save first).', 'visual-edit-lite' ); ?></p>
				</div>
			<?php endif; ?>
			<p class="description" style="max-width:680px">
				<?php
				/* translators: %s: recipient email address. */
				printf( esc_html__( 'Sends a test message to %s using the mailer selected above (save first).', 'visual-edit-lite' ), '<strong>' . esc_html( self::recipient( '' ) ) . '</strong>' );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="clara_ve_send_test_email" />
				<?php wp_nonce_field( 'clara_ve_test_email' ); ?>
				<?php submit_button( __( 'Send test email', 'visual-edit-lite' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * A masked password input for a stored secret — never echoes the real value;
	 * a saved secret shows a placeholder and a blank submit keeps it.
	 *
	 * @param string $option
	 * @return void
	 */
	private static function secret_input( $option ) {
		$has = '' !== (string) get_option( $option, '' );
		printf(
			'<input type="password" class="regular-text" id="%1$s" name="%1$s" value="" autocomplete="new-password" placeholder="%2$s" />',
			esc_attr( $option ),
			$has ? esc_attr__( '•••••••• (saved — leave blank to keep)', 'visual-edit-lite' ) : ''
		);
	}

	// ---- Getters (read by class-forms.php, class-tokens.php, class-mailer.php) ----

	public static function recipient( $token_to = '' ) {
		$token_to = trim( (string) $token_to );
		if ( '' !== $token_to && is_email( $token_to ) ) {
			return $token_to;
		}
		$setting = (string) get_option( self::OPT_TO, '' );
		if ( '' !== $setting && is_email( $setting ) ) {
			return $setting;
		}
		return (string) get_option( 'admin_email' );
	}

	public static function from_name() {
		$name = trim( (string) get_option( self::OPT_FROM_NAME, '' ) );
		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	public static function from_email() {
		$email = trim( (string) get_option( self::OPT_FROM_EMAIL, '' ) );
		return ( '' !== $email && is_email( $email ) ) ? $email : self::default_from_email();
	}

	/**
	 * no-reply@<site-domain>, derived from home_url() (port + leading www
	 * stripped). A same-domain From is what SPF/DKIM alignment needs.
	 *
	 * @return string
	 */
	public static function default_from_email() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) ? $host : 'localhost';
		$host = preg_replace( '/^www\./', '', $host );
		return 'no-reply@' . $host;
	}

	public static function min_seconds() {
		return (int) get_option( self::OPT_MIN_SECONDS, 3 );
	}

	public static function akismet_enabled() {
		return '1' === get_option( self::OPT_AKISMET, '' ) && self::akismet_available();
	}

	public static function akismet_available() {
		if ( ! class_exists( 'Akismet' ) || ! method_exists( 'Akismet', 'get_api_key' ) ) {
			return false;
		}
		$key = Akismet::get_api_key();
		return ! empty( $key );
	}

	// ---- Mailer getters ----

	/** @return bool Whether the consent note is switched on. */
	public static function consent_enabled() {
		return '1' === (string) get_option( self::OPT_CONSENT, '' );
	}

	/** @return string The consent note, with the default as a fallback. */
	public static function consent_text() {
		$text = trim( (string) get_option( self::OPT_CONSENT_TEXT, '' ) );
		return '' !== $text ? $text : self::default_consent_text();
	}

	/**
	 * Deliberately plain: it has to be readable at 12px under a form, and the
	 * owner is expected to replace it with their own wording and their own
	 * privacy link.
	 *
	 * @return string
	 */
	public static function default_consent_text() {
		return __( 'By submitting this form you agree to us storing and using the details above to reply to you.', 'visual-edit-lite' );
	}

	public static function mailer() {
		$m     = (string) get_option( self::OPT_MAILER, 'default' );
		$valid = array_merge( array( 'default', 'smtp' ), self::API_MAILERS );
		return in_array( $m, $valid, true ) ? $m : 'default';
	}

	/** SMTP transport is active when selected AND a host is set. */
	public static function smtp_active() {
		return 'smtp' === self::mailer() && '' !== self::smtp_host();
	}

	/** An HTTP-API provider is selected AND its key is present. */
	public static function api_active() {
		$m = self::mailer();
		return in_array( $m, self::API_MAILERS, true ) && '' !== self::api_key( $m );
	}

	public static function smtp_host() {
		return trim( (string) get_option( self::OPT_SMTP_HOST, '' ) );
	}

	public static function smtp_port() {
		return (int) get_option( self::OPT_SMTP_PORT, 587 );
	}

	public static function smtp_encryption() {
		$enc = (string) get_option( self::OPT_SMTP_ENCRYPTION, 'tls' );
		return in_array( $enc, array( 'none', 'ssl', 'tls' ), true ) ? $enc : 'tls';
	}

	public static function smtp_auth() {
		return '1' === get_option( self::OPT_SMTP_AUTH, '1' );
	}

	public static function smtp_user() {
		return (string) get_option( self::OPT_SMTP_USER, '' );
	}

	public static function smtp_password() {
		return self::decrypt( (string) get_option( self::OPT_SMTP_PASS, '' ) );
	}

	/**
	 * The decrypted API key/token for a provider ('brevo'|'sendgrid'|
	 * 'postmark'|'mailgun'), or '' if unset/unknown.
	 *
	 * @param string $provider
	 * @return string
	 */
	public static function api_key( $provider ) {
		$map = array(
			'brevo'    => self::OPT_API_BREVO,
			'sendgrid' => self::OPT_API_SENDGRID,
			'postmark' => self::OPT_API_POSTMARK,
			'mailgun'  => self::OPT_API_MAILGUN,
		);
		return isset( $map[ $provider ] ) ? self::decrypt( (string) get_option( $map[ $provider ], '' ) ) : '';
	}

	public static function mailgun_domain() {
		return trim( (string) get_option( self::OPT_MAILGUN_DOMAIN, '' ) );
	}

	public static function mailgun_region() {
		return 'eu' === (string) get_option( self::OPT_MAILGUN_REGION, 'us' ) ? 'eu' : 'us';
	}

	// ---- Test email ----

	public static function handle_test_email() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You need administrator permissions.', 'visual-edit-lite' ) );
		}
		check_admin_referer( 'clara_ve_test_email' );

		$to      = self::recipient( '' );
		$headers = array( sprintf( 'From: %s <%s>', self::from_name(), self::from_email() ) );
		$ok      = ( $to && is_email( $to ) )
			? wp_mail(
				$to,
				__( 'Visual Edit — test email', 'visual-edit-lite' ),
				__( 'This is a test email. If you received it, your email delivery settings are working.', 'visual-edit-lite' ),
				$headers
			)
			: false;

		// NOT menu_page_url(): it reads the $_parent_pages global, which only
		// exists once the admin menu has been built — and admin-post.php never
		// builds it. It returned an empty string here, so the redirect resolved
		// relative to the current script and dropped the user on a blank
		// admin-post.php?test_sent=0, with the answer they asked for encoded in
		// a URL and rendered nowhere.
		wp_safe_redirect( add_query_arg( 'test_sent', $ok ? '1' : '0', admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	// ---- Secret-at-rest crypto (AES-256-CBC keyed by the site auth salt) ----
	// Protects against a DB-only leak (a backup, read-only DB access), not
	// against full file+DB compromise (the salt lives in wp-config.php).
	//
	// base64 here is binary-safe transport for CIPHERTEXT into a text option
	// column — never encoding used to hide what the code does, which is what
	// the "no obfuscation" rule is about.

	private static function encrypt( $plain ) {
		if ( '' === $plain ) {
			return '';
		}
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$iv     = openssl_random_pseudo_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $cipher ? '' : base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( '' === $stored ) {
			return '';
		}
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 17 ) {
			return '';
		}
		$iv     = substr( $raw, 0, 16 );
		$cipher = substr( $raw, 16 );
		$key    = hash( 'sha256', wp_salt( 'auth' ), true );
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : $plain;
	}
}

Clara_VE_Form_Settings::init();
