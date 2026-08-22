<?php
/**
 * Backend for [wp-form] tokens (see class-tokens.php): a public, dependency-
 * free submit endpoint a plain HTML <form> can post straight to — no JS
 * required. Stores each submission as its own CPT entry and emails it.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Forms {

	const CPT = 'clara_ve_submission';

	/**
	 * Signing domain for the origin token (see origin_field). Kept as the old
	 * nonce action so a token issued before that change still verifies.
	 */
	const ORIGIN_ACTION = 'clara_ve_submit';

	/** verify_origin() outcomes. */
	const ORIGIN_VALID   = 'valid';
	const ORIGIN_STALE   = 'stale';
	const ORIGIN_INVALID = 'invalid';

	/**
	 * How far back a token is still RECOGNISED as ours — not accepted, only
	 * recognised, so an expired one can say "reload the page" instead of
	 * "security check failed". ~7 days at the default nonce_life.
	 *
	 * The difference matters because of who hits it: someone who left the form
	 * open overnight, or a page served from a long-lived cache. Telling them
	 * their submission failed a security check invites the same bug report
	 * this whole change exists to stop.
	 */
	const ORIGIN_STALE_TICKS = 14;

	/**
	 * Cloudflare's published edge ranges (cloudflare.com/ips). Only a request
	 * arriving from one of these may claim a different visitor IP via
	 * CF-Connecting-IP — see is_trusted_proxy(). Extend via the
	 * clara_ve_trusted_proxies filter rather than editing this list; these
	 * change rarely but they do change.
	 */
	const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_cpt() {
		register_post_type(
			self::CPT,
			array(
				'label'        => __( 'Form Submissions', 'visual-edit-lite' ),
				'labels'       => array(
					'name'          => __( 'Form Submissions', 'visual-edit-lite' ),
					'singular_name' => __( 'Submission', 'visual-edit-lite' ),
				),
				'public'       => false,
				'show_ui'      => true,
				'show_in_menu' => 'visual-edit',
				'supports'     => array( 'title', 'custom-fields' ),
			)
		);
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			'/submit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_submit' ),
				// Public by design (any visitor can submit a form); the real
				// protection is the nonce/honeypot/rate-limit checks below.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_submit( WP_REST_Request $request ) {
		$params   = $request->get_params();
		$redirect = ! empty( $params['redirect'] ) ? esc_url_raw( $params['redirect'] ) : '';

		// 1. Honeypot — silent success so a bot never learns it was caught.
		if ( ! empty( $params['cve_hp'] ) ) {
			return self::respond( $redirect );
		}

		// 2. Origin token — proof the submitter fetched a page from this site
		// first. NOT CSRF protection: this endpoint is anonymous, so there is
		// no session to ride and nothing to protect in that sense. It is a
		// cost signal that cheaply rejects spray bots, and it is the same
		// value for every visitor, so one can be scraped and reused. The
		// honeypot, time-trap, rate limit and Akismet layers are
		// what actually stop spam. See origin_field() for why it is
		// site-scoped rather than a WP nonce.
		//
		// This check is unconditional and never combined with the timestamp
		// check below — it is what bounds how long any single scraped token
		// stays usable. If it is ever removed, the timestamp needs its own
		// maximum age that same day, because on its own it replays forever.
		$origin = empty( $params['clara_ve_nonce'] ) ? self::ORIGIN_INVALID : self::verify_origin( $params['clara_ve_nonce'] );
		if ( self::ORIGIN_STALE === $origin ) {
			return new WP_Error( 'clara_ve_stale_form', __( 'This page has been open too long — please reload it and send the form again.', 'visual-edit-lite' ), array( 'status' => 403 ) );
		}
		if ( self::ORIGIN_VALID !== $origin ) {
			return new WP_Error( 'clara_ve_invalid_nonce', __( 'Security check failed — please reload the page and try again.', 'visual-edit-lite' ), array( 'status' => 403 ) );
		}

		// 3. Time-trap — a real person can't read and fill a form in a
		// fraction of a second; a bot posts instantly. Silent success on
		// failure (same reasoning as the honeypot). Skipped when the setting
		// is 0. NOTE: on a fully page-cached form this is weaker (the stamp is
		// baked into the cache) — the honeypot/Akismet layers still apply.
		// Read once: the same signed field carries the fill delay AND whether
		// this particular form asked for a challenge.
		$stamp       = self::verify_timestamp( isset( $params['cve_ts'] ) ? (string) $params['cve_ts'] : '' );
		$min_seconds = Clara_VE_Form_Settings::min_seconds();
		if ( $min_seconds > 0 ) {
			if ( false === $stamp || $stamp['elapsed'] < $min_seconds ) {
				return self::respond( $redirect );
			}
		}

		$ip = self::client_ip();

		// 4. Rate-limit, keyed on the visitor's REAL IP (Cloudflare-aware, see
		// client_ip) so one CDN edge IP doesn't collapse everyone into a single
		// shared bucket.
		$rate_key = 'clara_ve_form_rl_' . md5( $ip );
		if ( $ip && get_transient( $rate_key ) ) {
			return new WP_Error( 'clara_ve_rate_limited', __( 'Please wait a moment before submitting again.', 'visual-edit-lite' ), array( 'status' => 429 ) );
		}
		if ( $ip ) {
			set_transient( $rate_key, 1, 60 );
		}

		$form_id = isset( $params['form_id'] ) ? sanitize_key( $params['form_id'] ) : 'form';
		$skip    = array( 'clara_ve_nonce', 'form_id', 'to', 'redirect', 'cve_hp', 'cve_ts', 'form_type', 'list_id' );

		$fields = array();
		foreach ( $params as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$fields[ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
				continue;
			}
			// A checkbox GROUP or a <select multiple> conventionally shares one
			// name ending in "[]" so the browser (and PHP after it) delivers
			// every checked value instead of just the last one — PHP has
			// already turned that into a real array by the time it gets here.
			// Storage stays single-value-per-key (every downstream reader —
			// the notification email, Akismet, the mailing-list flow — expects
			// a string, not an array), so the selected values are sanitized
			// individually and joined, same as a person would read them off a
			// paper form. Only a genuinely FLAT array is trusted: anything
			// nested (`x[][y]=1`, not a shape this UI or the AI would ever
			// produce) is silently skipped exactly like before, rather than
			// risking an unexpected structure in stored data.
			if ( is_array( $value ) && array_reduce( $value, function ( $flat, $item ) {
				return $flat && is_scalar( $item );
			}, true ) && ! empty( $value ) ) {
				$clean = array_map( function ( $item ) {
					return sanitize_textarea_field( (string) $item );
				}, $value );
				$fields[ sanitize_key( $key ) ] = implode( ', ', $clean );
			}
		}

		// 5. Akismet — spam is still STORED (flagged) but not emailed, and the
		// caller still sees success so a bot learns nothing.
		$is_spam = self::is_spam_akismet( $fields, $ip );

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::CPT,
				/* translators: 1: form id, 2: submission date. */
				'post_title'  => sprintf( __( '%1$s — %2$s', 'visual-edit-lite' ), $form_id, current_time( 'mysql' ) ),
				'post_status' => 'publish',
				'meta_input'  => array_merge(
					$fields,
					array(
						'_clara_ve_form_ip' => $ip,
						'_clara_ve_spam'    => $is_spam ? '1' : '',
					)
				),
			)
		);

		if ( $is_spam ) {
			return self::respond( $redirect );
		}

		// A mailing-list form hands the address to the provider instead of
		// emailing it to the owner: the subscriber wants the confirmation and
		// the download, and the owner wants a contact in their list, not a
		// notification per signup. The submission is still stored either way —
		// it is the site's own record that someone asked, independent of
		// whatever the provider later does with the address.
		if ( 'list' === ( isset( $params['form_type'] ) ? sanitize_key( $params['form_type'] ) : '' ) ) {
			$list_id = isset( $params['list_id'] ) ? $params['list_id'] : '';
			$email   = self::guess_email( $fields );

			// Double opt-in run here rather than at the provider: the address
			// is held, not subscribed, until the person opens the link. Works
			// on any transport, including the ones with no notion of a list.
			if ( 'plugin' === Clara_VE_Optin::mode() ) {
				$started = Clara_VE_Optin::start( $email, $list_id, $form_id, $ip );
				if ( is_wp_error( $started ) && ! is_wp_error( $post_id ) ) {
					update_post_meta( $post_id, '_clara_ve_list_error', $started->get_error_message() );
				}
				return self::respond( $redirect );
			}

			$subscribed = Clara_VE_Lists::subscribe( $list_id, $email, $fields );
			if ( is_wp_error( $subscribed ) && ! is_wp_error( $post_id ) ) {
				// Recorded rather than shown: the visitor did their part, and a
				// provider outage is the owner's problem to see, not theirs.
				update_post_meta( $post_id, '_clara_ve_list_error', $subscribed->get_error_message() );
			}
			return self::respond( $redirect );
		}

		self::send_notification( $form_id, $fields, $params, $post_id );

		return self::respond( $redirect );
	}

	/**
	 * Proof that this submission came from a form this site rendered.
	 *
	 * This deliberately does NOT use wp_create_nonce(), and the reason is a
	 * bug that only ever hit the site owner. A WordPress nonce is bound to the
	 * current user ID and session token. The form is rendered on the front end
	 * — where the owner is usually logged in — so the nonce was created for
	 * uid=1. The submission then goes to a REST route, and core's
	 * rest_cookie_check_errors() does:
	 *
	 *     if ( null === $nonce ) { wp_set_current_user( 0 ); return true; }
	 *
	 * A logged-in cookie with no X-WP-Nonce header (which a plain form post
	 * has no reason to send) makes the REST layer deliberately drop to
	 * anonymous. So the nonce was created as uid=1 and verified as uid=0, and
	 * could not match. Logged-out visitors were fine; the owner testing their
	 * own contact form got "Security check failed" and reasonably concluded
	 * the form was broken.
	 *
	 * Sending an X-WP-Nonce header would fix that case, but it makes a
	 * logged-in submit depend on a second, unrelated nonce being fresh, and a
	 * stale one is rejected by core before this route runs — trading a bug
	 * that always happens for one that happens on cached or long-open pages,
	 * with an error message we do not control.
	 *
	 * So the token is site-scoped instead of user-scoped. Nothing is lost:
	 * for logged-out visitors — every real visitor — WordPress already issued
	 * the identical nonce to everyone, so the per-user binding was never doing
	 * any work here. Its job is and was "this came from a form on this site",
	 * a light CSRF/hotlink signal. The honeypot, signed time-trap, rate limit,
	 * the rate limit and Akismet are what actually stop spam.
	 *
	 * Same two-tick window as a WP nonce (a full nonce_life, in two halves) so
	 * a form left open stays valid exactly as long as it used to.
	 *
	 * @return string
	 */
	public static function origin_field() {
		return '<input type="hidden" name="clara_ve_nonce" value="' . esc_attr( self::origin_token() ) . '">';
	}

	/**
	 * @param int|null $tick Which half-life window to sign for.
	 * @return string
	 */
	private static function origin_token( $tick = null ) {
		if ( null === $tick ) {
			$tick = self::origin_tick();
		}
		// wp_salt('nonce'), not the auth salt the timestamp field uses: two
		// different signed fields on the same form should not share a key.
		return substr( hash_hmac( 'sha256', $tick . '|' . self::ORIGIN_ACTION, wp_salt( 'nonce' ) ), 0, 32 );
	}

	/**
	 * @return int
	 */
	private static function origin_tick() {
		$life = (int) apply_filters( 'nonce_life', DAY_IN_SECONDS );
		if ( $life < 2 ) {
			$life = DAY_IN_SECONDS;
		}
		return (int) ceil( time() / ( $life / 2 ) );
	}

	/**
	 * @param string $value
	 * @return string One of the ORIGIN_* constants.
	 */
	private static function verify_origin( $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			return self::ORIGIN_INVALID;
		}
		$tick = self::origin_tick();
		foreach ( array( $tick, $tick - 1 ) as $t ) {
			if ( hash_equals( self::origin_token( $t ), $value ) ) {
				return self::ORIGIN_VALID;
			}
		}
		// A page rendered before this change — cached, or still open in
		// someone's tab across the update — carries a real WP nonce. Accept
		// it so the update does not reject forms that were valid a moment
		// before it landed. Nobody can mint new ones now that the field is no
		// longer rendered, and existing ones carry WP's own 12-24h expiry, so
		// this cannot widen the replay window. Deletable in the next release.
		if ( wp_verify_nonce( $value, self::ORIGIN_ACTION ) ) {
			return self::ORIGIN_VALID;
		}
		// Ours, but too old. Recognising it is what lets the caller say
		// something the visitor can act on. This is a strictly cosmetic
		// distinction — a stale token is still refused.
		for ( $back = 2; $back <= self::ORIGIN_STALE_TICKS; $back++ ) {
			if ( hash_equals( self::origin_token( $tick - $back ), $value ) ) {
				return self::ORIGIN_STALE;
			}
		}
		return self::ORIGIN_INVALID;
	}

	/**
	 * Hidden signed page-render timestamp for the time-trap (see
	 * handle_submit step 3). The HMAC — keyed by the site's auth salt — stops
	 * a bot from forging an old timestamp to fake a human fill delay. Public
	 * because class-tokens.php injects it into every rendered [wp-form].
	 *
	 * KNOWN GAP, not yet closed: the signature does not bind the form it was
	 * issued for. A bot can lift a stamp from a form with the challenge off
	 * and replay it against one with the challenge on, so the least protected
	 * form on a site sets the floor for all of them. Closing it means folding
	 * the form ID into this HMAC and checking the flag against the signed
	 * value rather than the submitted form_id. Predates the per-form switch
	 * only in the sense that there was nothing to downgrade to before it.
	 *
	 * @return string
	 */
	public static function timestamp_field() {
		$ts    = (string) time();
		$sig   = hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) );
		$value = $ts . '.' . $sig;
		return '<input type="hidden" name="cve_ts" value="' . esc_attr( $value ) . '">';
	}

	/**
	 * @param string $value The submitted cve_ts field.
	 * @return array{elapsed:int}|false Seconds since the form was rendered, or
	 *                   false if the value is missing or does not verify.
	 */
	private static function verify_timestamp( $value ) {
		$parts = explode( '.', (string) $value );
		if ( 2 !== count( $parts ) ) {
			return false;
		}
		list( $ts, $sig ) = $parts;

		if ( '' === $ts || ! ctype_digit( $ts ) ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $ts, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $sig ) ) {
			return false;
		}
		return array( 'elapsed' => max( 0, time() - (int) $ts ) );
	}

	/**
	 * Build the notification email with proper From (site domain, for
	 * SPF/DKIM alignment) and Reply-To (the visitor's own address, so the
	 * owner can just hit reply). Recipient resolves token to="" → admin
	 * setting → admin_email. A failed send is flagged on the stored entry so
	 * the owner isn't left thinking nothing arrived.
	 *
	 * @param string   $form_id
	 * @param array    $fields
	 * @param array    $params
	 * @param int|WP_Error $post_id
	 * @return void
	 */
	private static function send_notification( $form_id, $fields, $params, $post_id ) {
		$to = Clara_VE_Form_Settings::recipient( isset( $params['to'] ) ? (string) $params['to'] : '' );
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}

		$headers = array( sprintf( 'From: %s <%s>', Clara_VE_Form_Settings::from_name(), Clara_VE_Form_Settings::from_email() ) );
		$reply   = self::guess_email( $fields );
		if ( $reply ) {
			$headers[] = 'Reply-To: ' . $reply;
		}

		$body = '';
		foreach ( $fields as $field_key => $field_value ) {
			$body .= $field_key . ': ' . $field_value . "\n";
		}

		/* translators: %s: form id. */
		$sent = wp_mail( $to, sprintf( __( 'New form submission: %s', 'visual-edit-lite' ), $form_id ), $body, $headers );

		if ( ! $sent && ! is_wp_error( $post_id ) ) {
			update_post_meta( $post_id, '_clara_ve_mail_failed', '1' );
		}

		self::send_confirmation( $reply );
	}

	/**
	 * Tell the person who submitted that it arrived.
	 *
	 * Someone who fills in a form and sees the page reload has no way to know
	 * whether anything happened — the owner's notification lands in the owner's
	 * inbox, not theirs. One short acknowledgement closes that loop, and it is
	 * also the cheapest deliverability signal there is: a message the recipient
	 * was expecting.
	 *
	 * Skipped when the visitor's own address can't be identified (a form with no
	 * email field), and when it would only be talking to the site itself.
	 *
	 * @param string $to The visitor's address, as guessed from the fields.
	 * @return void
	 */
	private static function send_confirmation( $to ) {
		if ( ! $to || ! is_email( $to ) ) {
			return;
		}
		if ( strtolower( $to ) === strtolower( Clara_VE_Form_Settings::recipient( '' ) ) ) {
			return;
		}

		$name    = Clara_VE_Form_Settings::from_name();
		$headers = array( sprintf( 'From: %s <%s>', $name, Clara_VE_Form_Settings::from_email() ) );

		wp_mail(
			$to,
			/* translators: %s: site or sender name. */
			sprintf( __( 'Thanks — we got your message (%s)', 'visual-edit-lite' ), $name ),
			sprintf(
				/* translators: %s: site or sender name. */
				__( "Thanks for getting in touch — your message reached %s and someone will read it shortly.\n\nThis is an automatic confirmation; there's no need to reply to it.", 'visual-edit-lite' ),
				$name
			),
			$headers
		);
	}

	/**
	 * The visitor's own email, for the Reply-To header: a field whose key
	 * mentions "email" first, else the first value that IS a valid email.
	 *
	 * @param array $fields
	 * @return string
	 */
	private static function guess_email( $fields ) {
		foreach ( $fields as $key => $value ) {
			if ( false !== strpos( (string) $key, 'email' ) && is_email( $value ) ) {
				return $value;
			}
		}
		foreach ( $fields as $value ) {
			if ( is_email( $value ) ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * @param array  $fields
	 * @param string $ip
	 * @return bool True only when Akismet is enabled AND classifies this as
	 *              spam. Any error/uncertainty fails OPEN (not spam) — a form
	 *              must never be blocked by an Akismet hiccup.
	 */
	private static function is_spam_akismet( $fields, $ip ) {
		if ( ! Clara_VE_Form_Settings::akismet_enabled() ) {
			return false;
		}
		if ( ! method_exists( 'Akismet', 'http_post' ) ) {
			return false;
		}

		$body = '';
		foreach ( $fields as $value ) {
			$body .= $value . "\n";
		}

		$query = array(
			'blog'                 => home_url( '/' ),
			'user_ip'              => $ip,
			'user_agent'           => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
			'referrer'             => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
			'comment_type'         => 'contact-form',
			'comment_author_email' => self::guess_email( $fields ),
			'comment_content'      => $body,
		);

		$response = Akismet::http_post( http_build_query( $query ), 'comment-check' );
		return is_array( $response ) && isset( $response[1] ) && 'true' === trim( (string) $response[1] );
	}

	private static function respond( $redirect ) {
		// A fetch submit gets the answer as data and stays put; a plain form
		// POST — no JavaScript, or the script failed to load — gets the
		// redirect it has always got. The endpoint has to serve both, because
		// the no-JS path is the one that must never break: it is the form
		// working with nothing but HTML.
		if ( self::wants_json() ) {
			return new WP_REST_Response( array( 'ok' => true, 'redirect' => $redirect ), 200 );
		}
		if ( $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}
		// "Stay on this page" with no JavaScript: without this the browser
		// lands on the endpoint itself and the visitor reads {"ok":true}. That
		// was survivable while the only way to get here was a hand-written
		// token with no redirect; it is not survivable now that "Stay on this
		// page" is an option someone can pick in a panel. Back where they came
		// from, with a marker the form uses to say it worked.
		$back = wp_get_referer();
		if ( $back ) {
			wp_safe_redirect( add_query_arg( 'cve_sent', '1', $back ) );
			exit;
		}
		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/**
	 * Whether this submission came from the inline script rather than a browser
	 * form POST. Keyed on an explicit header the script sets, not on Accept:
	 * some browsers send a generous Accept on a normal navigation, and guessing
	 * wrong means a visitor with no JavaScript staring at raw JSON.
	 *
	 * @return bool
	 */
	private static function wants_json() {
		return isset( $_SERVER['HTTP_X_CLARA_VE_INLINE'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The visitor's real IP for rate-limiting. Behind Cloudflare, REMOTE_ADDR
	 * is a Cloudflare edge IP — the SAME for every visitor, which would
	 * collapse the whole site into one rate-limit bucket (either blocking
	 * everyone at once or being useless). CF-Connecting-IP is set by
	 * Cloudflare itself and carries the real client. Only trusted headers are
	 * consulted; an arbitrary X-Forwarded-For (spoofable from outside) is
	 * deliberately NOT used.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		// CF-Connecting-IP is only meaningful if Cloudflare set it. Any client
		// can send that header, so trusting it unconditionally lets a bot
		// claim a fresh IP on every request and walk straight through the rate
		// limit below — which was measurable, not theoretical: three posts in
		// a row succeeded inside a window that permits one. It is honoured
		// only when the connection itself came from a Cloudflare edge.
		if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::is_trusted_proxy( $remote ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) );
			if ( rest_is_ip_address( $ip ) ) {
				return $ip;
			}
		}
		return $remote;
	}

	/**
	 * @param string $remote The address the request actually arrived from.
	 * @return bool
	 */
	private static function is_trusted_proxy( $remote ) {
		/**
		 * CIDR ranges allowed to speak for a visitor via a forwarding header.
		 *
		 * Defaults to Cloudflare's published ranges. Add your own here if the
		 * site sits behind a different CDN or reverse proxy — and add only
		 * ranges you control, because anything in this list can set any
		 * visitor's apparent IP.
		 *
		 * @param string[] $ranges
		 */
		$ranges = (array) apply_filters( 'clara_ve_trusted_proxies', self::CLOUDFLARE_RANGES );
		foreach ( $ranges as $cidr ) {
			if ( self::ip_in_cidr( $remote, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Binary CIDR match, IPv4 and IPv6, without assuming any extension beyond
	 * inet_pton.
	 *
	 * @param string $ip
	 * @param string $cidr
	 * @return bool
	 */
	private static function ip_in_cidr( $ip, $cidr ) {
		if ( '' === $ip || false === strpos( $cidr, '/' ) ) {
			return false;
		}
		list( $subnet, $bits ) = explode( '/', $cidr, 2 );
		$ip_bin  = @inet_pton( $ip );      // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$sub_bin = @inet_pton( $subnet );  // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		// Different lengths means different families — never a match.
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits = (int) $bits;
		if ( $bits < 0 || $bits > strlen( $sub_bin ) * 8 ) {
			return false;
		}
		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $whole > 0 && substr( $ip_bin, 0, $whole ) !== substr( $sub_bin, 0, $whole ) ) {
			return false;
		}
		if ( $rest > 0 ) {
			$mask = chr( ( 0xFF << ( 8 - $rest ) ) & 0xFF );
			if ( ( $ip_bin[ $whole ] & $mask ) !== ( $sub_bin[ $whole ] & $mask ) ) {
				return false;
			}
		}
		return true;
	}
}

Clara_VE_Forms::init();
