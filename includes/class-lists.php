<?php
/**
 * Mailing lists: where a subscriber goes when a form is set to "Mailing list".
 *
 * Deliberately NOT a subscriber database of our own. Storing addresses is the
 * easy tenth of the job; the other nine tenths — double opt-in, proof of
 * consent, unsubscribe links that work forever, bounce handling, suppression
 * lists, deliverability, and the welcome sequence the whole lead magnet exists
 * to start — are what an email platform is. Reproducing a bad version of that
 * inside a page editor would be worse for the site owner than not offering it.
 *
 * So this is a thin adapter: hand the address to the provider the owner already
 * uses, and let the provider own the compliance. The provider interface is
 * three methods (lists / subscribe / ready) so adding Mailchimp, Flodesk or
 * MailerLite later is one class, not a refactor.
 *
 * A provider is chosen INDEPENDENTLY of the mailer. Sending contact-form mail
 * through Gmail SMTP while pushing subscribers to Brevo is a normal setup, and
 * most transports (Gmail, plain SMTP, Postmark) have no notion of a list at
 * all — see Clara_VE_Form_Settings::mailer().
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Lists {

	const OPT_PROVIDER    = 'clara_ve_list_provider';
	const OPT_DOI_TEMPLATE = 'clara_ve_list_doi_template';
	const OPT_DOI_REDIRECT = 'clara_ve_list_doi_redirect';

	/**
	 * @return array provider slug => human label.
	 */
	public static function providers() {
		return array(
			''      => __( 'None — mailing list forms are unavailable', 'visual-edit-lite' ),
			'brevo' => __( 'Brevo', 'visual-edit-lite' ),
		);
	}

	/**
	 * @return string Selected provider slug, '' when none.
	 */
	public static function provider() {
		$p = (string) get_option( self::OPT_PROVIDER, '' );
		return array_key_exists( $p, self::providers() ) ? $p : '';
	}

	/**
	 * A provider is only usable once it also has credentials. Brevo reuses the
	 * API key already stored for the mailer: it is the same account and the
	 * same key, and asking someone to paste it twice into one settings page is
	 * how you end up with two keys, one of them stale.
	 *
	 * @return bool
	 */
	public static function ready() {
		if ( 'brevo' !== self::provider() ) {
			return false;
		}
		return '' !== Clara_VE_Form_Settings::api_key( 'brevo' );
	}

	/**
	 * The provider's lists, for the editor's picker.
	 *
	 * @return array|WP_Error [ { id, name, count } ]
	 */
	public static function lists() {
		if ( ! self::ready() ) {
			return new WP_Error( 'clara_ve_no_list_provider', __( 'No mailing list provider is connected.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$cached = get_transient( 'clara_ve_lists_brevo' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$res = wp_remote_get(
			'https://api.brevo.com/v3/contacts/lists?limit=50',
			array(
				'timeout' => 15,
				'headers' => array( 'api-key' => Clara_VE_Form_Settings::api_key( 'brevo' ), 'accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return new WP_Error( 'clara_ve_list_provider', self::provider_message( $res ), array( 'status' => 502 ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $res ), true );
		$lists = array();
		foreach ( ( isset( $body['lists'] ) ? $body['lists'] : array() ) as $l ) {
			$lists[] = array(
				'id'    => (string) $l['id'],
				'name'  => (string) $l['name'],
				'count' => isset( $l['totalSubscribers'] ) ? (int) $l['totalSubscribers'] : 0,
			);
		}
		// Short cache: the picker is opened repeatedly while styling a form, and
		// a list created a minute ago should still show up without a flush.
		set_transient( 'clara_ve_lists_brevo', $lists, 5 * MINUTE_IN_SECONDS );
		return $lists;
	}

	/**
	 * Add an address to a list.
	 *
	 * With a double opt-in template configured, the provider sends the
	 * confirmation and only adds the contact once the person clicks it — the
	 * flow EU consent rules expect, and the one that keeps proof of consent
	 * with the provider rather than in a WordPress table nobody exports.
	 * Without a template it is a direct add, which is the right behaviour for a
	 * list the owner explicitly runs single opt-in.
	 *
	 * @param string $list_id
	 * @param string $email
	 * @param array  $fields Other submitted fields, offered as contact attributes.
	 * @return true|WP_Error
	 */
	public static function subscribe( $list_id, $email, $fields = array() ) {
		if ( ! self::ready() ) {
			return new WP_Error( 'clara_ve_no_list_provider', __( 'No mailing list provider is connected.', 'visual-edit-lite' ) );
		}
		$list_id = (int) $list_id;
		if ( ! $list_id || ! is_email( $email ) ) {
			return new WP_Error( 'clara_ve_list_args', __( 'A list and a valid email address are required.', 'visual-edit-lite' ) );
		}

		$attributes = array();
		foreach ( $fields as $key => $value ) {
			if ( 'email' === $key || ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}
			// Brevo attribute names are uppercase; unknown ones are ignored by
			// the API rather than rejected, so passing them through is safe.
			$attributes[ strtoupper( sanitize_key( $key ) ) ] = (string) $value;
		}

		$template = (int) get_option( self::OPT_DOI_TEMPLATE, 0 );
		if ( $template ) {
			$body = array(
				'email'                => $email,
				'includeListIds'       => array( $list_id ),
				'templateId'           => $template,
				'redirectionUrl'       => (string) get_option( self::OPT_DOI_REDIRECT, home_url( '/' ) ),
			);
			if ( $attributes ) {
				$body['attributes'] = $attributes;
			}
			return self::post( 'contacts/doubleOptinConfirmation', $body );
		}

		$body = array(
			'email'         => $email,
			'listIds'       => array( $list_id ),
			'updateEnabled' => true,
		);
		if ( $attributes ) {
			$body['attributes'] = $attributes;
		}
		return self::post( 'contacts', $body );
	}

	/**
	 * @param string $path
	 * @param array  $body
	 * @return true|WP_Error
	 */
	private static function post( $path, $body ) {
		$res = wp_remote_post(
			'https://api.brevo.com/v3/' . $path,
			array(
				'timeout' => 15,
				'headers' => array(
					'api-key'      => Clara_VE_Form_Settings::api_key( 'brevo' ),
					'content-type' => 'application/json',
					'accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		// 201 created, 204 accepted (double opt-in queued), 200 updated.
		if ( in_array( $code, array( 200, 201, 202, 204 ), true ) ) {
			return true;
		}
		// An address already on the list is a success from the visitor's point
		// of view — they asked to be subscribed and they are.
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( isset( $body['code'] ) && 'duplicate_parameter' === $body['code'] ) {
			return true;
		}
		return new WP_Error( 'clara_ve_list_provider', self::provider_message( $res ), array( 'status' => 502 ) );
	}

	/**
	 * The provider's own words when it refuses, so a misconfiguration says what
	 * is wrong instead of "something went wrong".
	 *
	 * @param array $res
	 * @return string
	 */
	private static function provider_message( $res ) {
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$msg  = isset( $body['message'] ) ? (string) $body['message'] : '';
		return sprintf(
			/* translators: 1: HTTP status, 2: provider message. */
			__( 'Brevo said %1$d: %2$s', 'visual-edit-lite' ),
			(int) wp_remote_retrieve_response_code( $res ),
			'' !== $msg ? $msg : __( 'no reason given', 'visual-edit-lite' )
		);
	}
}
