<?php
/**
 * Mail transport for the mailer chosen in Form Settings.
 *
 * Two paths, mutually exclusive per the selected mailer:
 * - `smtp`  → configure PHPMailer on `phpmailer_init` so wp_mail() speaks SMTP.
 * - `brevo`/`sendgrid`/`postmark`/`mailgun` → short-circuit wp_mail() via
 *   `pre_wp_mail` and POST to the provider's HTTPS API instead (works even on
 *   hosts that block outbound SMTP ports).
 * `default` leaves WordPress's own PHP mail() untouched.
 *
 * All settings/secrets are read from Clara_VE_Form_Settings (this class holds
 * no options of its own).
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Mailer {

	public static function init() {
		add_action( 'phpmailer_init', array( __CLASS__, 'configure_phpmailer' ) );
		add_filter( 'pre_wp_mail', array( __CLASS__, 'maybe_send_via_api' ), 10, 2 );
	}

	/**
	 * Point PHPMailer at the configured SMTP relay (only for the `smtp` mailer).
	 * Fires for every wp_mail() so the whole site benefits from authenticated,
	 * domain-aligned delivery.
	 *
	 * @param PHPMailer\PHPMailer\PHPMailer $phpmailer
	 * @return void
	 */
	public static function configure_phpmailer( $phpmailer ) {
		if ( ! Clara_VE_Form_Settings::smtp_active() ) {
			return;
		}
		$phpmailer->isSMTP();
		$phpmailer->Host = Clara_VE_Form_Settings::smtp_host();
		$phpmailer->Port = Clara_VE_Form_Settings::smtp_port();

		$enc                    = Clara_VE_Form_Settings::smtp_encryption();
		$phpmailer->SMTPSecure  = ( 'none' === $enc ) ? '' : $enc;
		$phpmailer->SMTPAutoTLS = ( 'none' !== $enc );

		if ( Clara_VE_Form_Settings::smtp_auth() ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = Clara_VE_Form_Settings::smtp_user();
			$phpmailer->Password = Clara_VE_Form_Settings::smtp_password();
		} else {
			$phpmailer->SMTPAuth = false;
		}

		$from = Clara_VE_Form_Settings::from_email();
		if ( $from && is_email( $from ) ) {
			$phpmailer->setFrom( $from, Clara_VE_Form_Settings::from_name(), false );
		}
	}

	/**
	 * `pre_wp_mail` handler: when an HTTP-API provider is selected, send the
	 * message through it and return a bool (short-circuiting wp_mail). Returns
	 * the untouched pre-value otherwise so SMTP/default proceed normally.
	 *
	 * @param null|bool $short  Non-null if another handler already took over.
	 * @param array     $atts   to, subject, message, headers, attachments.
	 * @return null|bool
	 */
	public static function maybe_send_via_api( $short, $atts ) {
		if ( null !== $short ) {
			return $short;
		}
		$mailer = Clara_VE_Form_Settings::mailer();
		if ( ! in_array( $mailer, Clara_VE_Form_Settings::API_MAILERS, true ) ) {
			return null;
		}

		$msg = self::normalize( is_array( $atts ) ? $atts : array() );
		if ( empty( $msg['to'] ) || '' === $msg['from_email'] ) {
			return false;
		}

		switch ( $mailer ) {
			case 'brevo':
				return self::send_brevo( $msg );
			case 'sendgrid':
				return self::send_sendgrid( $msg );
			case 'postmark':
				return self::send_postmark( $msg );
			case 'mailgun':
				return self::send_mailgun( $msg );
		}
		return null;
	}

	/**
	 * Flatten wp_mail()'s $atts into a simple message struct: recipient list,
	 * subject, body, whether it's HTML, and the Reply-To pulled from headers.
	 *
	 * @param array $atts
	 * @return array
	 */
	private static function normalize( $atts ) {
		$to_raw = isset( $atts['to'] ) ? $atts['to'] : array();
		$to     = is_array( $to_raw ) ? $to_raw : preg_split( '/,\s*/', (string) $to_raw );
		$to     = array_values( array_filter( array_map( 'trim', (array) $to ), 'is_email' ) );

		$headers = isset( $atts['headers'] ) ? $atts['headers'] : array();
		if ( ! is_array( $headers ) ) {
			$headers = preg_split( "/\r\n|\r|\n/", (string) $headers );
		}
		$reply   = '';
		$is_html = false;
		foreach ( (array) $headers as $line ) {
			$line = (string) $line;
			if ( 0 === stripos( $line, 'reply-to:' ) ) {
				$reply = trim( substr( $line, strpos( $line, ':' ) + 1 ) );
			} elseif ( 0 === stripos( $line, 'content-type:' ) && false !== stripos( $line, 'text/html' ) ) {
				$is_html = true;
			}
		}
		if ( preg_match( '/<([^>]+)>/', $reply, $mm ) ) {
			$reply = trim( $mm[1] );
		}

		return array(
			'to'         => $to,
			'subject'    => (string) ( isset( $atts['subject'] ) ? $atts['subject'] : '' ),
			'body'       => (string) ( isset( $atts['message'] ) ? $atts['message'] : '' ),
			'is_html'    => $is_html,
			'reply_to'   => is_email( $reply ) ? $reply : '',
			'from_email' => Clara_VE_Form_Settings::from_email(),
			'from_name'  => Clara_VE_Form_Settings::from_name(),
		);
	}

	private static function send_brevo( $m ) {
		$key = Clara_VE_Form_Settings::api_key( 'brevo' );
		if ( '' === $key ) {
			return false;
		}
		$to = array();
		foreach ( $m['to'] as $e ) {
			$to[] = array( 'email' => $e );
		}
		$body = array(
			'sender'  => array( 'email' => $m['from_email'], 'name' => $m['from_name'] ),
			'to'      => $to,
			'subject' => $m['subject'],
		);
		$body[ $m['is_html'] ? 'htmlContent' : 'textContent' ] = $m['body'];
		if ( $m['reply_to'] ) {
			$body['replyTo'] = array( 'email' => $m['reply_to'] );
		}
		return self::ok(
			wp_remote_post(
				'https://api.brevo.com/v3/smtp/email',
				array(
					'timeout' => 15,
					'headers' => array( 'api-key' => $key, 'content-type' => 'application/json', 'accept' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			)
		);
	}

	private static function send_sendgrid( $m ) {
		$key = Clara_VE_Form_Settings::api_key( 'sendgrid' );
		if ( '' === $key ) {
			return false;
		}
		$to = array();
		foreach ( $m['to'] as $e ) {
			$to[] = array( 'email' => $e );
		}
		$body = array(
			'personalizations' => array( array( 'to' => $to ) ),
			'from'             => array( 'email' => $m['from_email'], 'name' => $m['from_name'] ),
			'subject'          => $m['subject'],
			'content'          => array( array( 'type' => $m['is_html'] ? 'text/html' : 'text/plain', 'value' => $m['body'] ) ),
		);
		if ( $m['reply_to'] ) {
			$body['reply_to'] = array( 'email' => $m['reply_to'] );
		}
		return self::ok(
			wp_remote_post(
				'https://api.sendgrid.com/v3/mail/send',
				array(
					'timeout' => 15,
					'headers' => array( 'authorization' => 'Bearer ' . $key, 'content-type' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			)
		);
	}

	private static function send_postmark( $m ) {
		$key = Clara_VE_Form_Settings::api_key( 'postmark' );
		if ( '' === $key ) {
			return false;
		}
		$body = array(
			'From'          => self::rfc_from( $m ),
			'To'            => implode( ',', $m['to'] ),
			'Subject'       => $m['subject'],
			'MessageStream' => 'outbound',
		);
		$body[ $m['is_html'] ? 'HtmlBody' : 'TextBody' ] = $m['body'];
		if ( $m['reply_to'] ) {
			$body['ReplyTo'] = $m['reply_to'];
		}
		return self::ok(
			wp_remote_post(
				'https://api.postmarkapp.com/email',
				array(
					'timeout' => 15,
					'headers' => array( 'x-postmark-server-token' => $key, 'content-type' => 'application/json', 'accept' => 'application/json' ),
					'body'    => wp_json_encode( $body ),
				)
			)
		);
	}

	private static function send_mailgun( $m ) {
		$key    = Clara_VE_Form_Settings::api_key( 'mailgun' );
		$domain = Clara_VE_Form_Settings::mailgun_domain();
		if ( '' === $key || '' === $domain ) {
			return false;
		}
		$base = ( 'eu' === Clara_VE_Form_Settings::mailgun_region() ) ? 'https://api.eu.mailgun.net' : 'https://api.mailgun.net';
		$form = array(
			'from'    => self::rfc_from( $m ),
			'to'      => implode( ',', $m['to'] ),
			'subject' => $m['subject'],
		);
		$form[ $m['is_html'] ? 'html' : 'text' ] = $m['body'];
		if ( $m['reply_to'] ) {
			$form['h:Reply-To'] = $m['reply_to'];
		}
		return self::ok(
			wp_remote_post(
				$base . '/v3/' . rawurlencode( $domain ) . '/messages',
				array(
					'timeout' => 15,
					'headers' => array( 'authorization' => 'Basic ' . base64_encode( 'api:' . $key ) ),
					'body'    => $form,
				)
			)
		);
	}

	/** "Name <email>" when a name is set, else the bare address. */
	private static function rfc_from( $m ) {
		return '' !== $m['from_name'] ? sprintf( '%s <%s>', $m['from_name'], $m['from_email'] ) : $m['from_email'];
	}

	/** True on any 2xx response; a transport/WP error is a failure. */
	private static function ok( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 300;
	}
}

Clara_VE_Mailer::init();
