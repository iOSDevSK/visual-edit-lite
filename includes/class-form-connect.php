<?php
/**
 * Forms from other plugins, delivered by Visual Edit.
 *
 * A block theme's form is often not ours: Kadence's form block, say, with
 * its own markup, styling and submit handler. Converting it would lose the
 * look the owner chose, so it is connected where it stands instead. The
 * owner picks, in the Visual Edit popup, what the form does — a contact form
 * to the Form Settings address (or one of their own), or a signup to a
 * mailing list — and that choice is stored on the block as
 * `claraVe.delivery`. The other plugin still renders the form, checks its
 * honeypot and captcha, and shows its thank-you; what happens to the answers
 * is Clara_VE_Forms::deliver(), the same as for every form this plugin
 * renders.
 *
 * The delivery choice is read from the SAVED block — the other plugin loads
 * its form settings from the post, never from the request — so, unlike a
 * form rendered into the page, there is nothing a visitor could retype and
 * nothing to sign. Who saved it still matters, exactly as for our own form
 * block: only a page whose author administers the site can send answers to
 * an address of its choosing or into a list.
 */
defined( 'ABSPATH' ) || exit;

class Clara_VE_Form_Connect {

	/** Block types whose submissions this class can deliver. */
	const BLOCKS = array( 'kadence/form' );

	public static function init() {
		add_action( 'kadence_blocks_form_submission', array( __CLASS__, 'kadence' ), 10, 4 );
	}

	/**
	 * The stored delivery choice, cleaned. Null when the form is not connected.
	 *
	 * @param mixed $attributes Block attributes.
	 * @return array{type:string,recipient:string,list:string}|null
	 */
	public static function delivery( $attributes ) {
		$delivery = is_array( $attributes ) && isset( $attributes['claraVe']['delivery'] ) && is_array( $attributes['claraVe']['delivery'] )
			? $attributes['claraVe']['delivery']
			: null;
		if ( ! $delivery || empty( $delivery['connect'] ) ) {
			return null;
		}
		$type      = isset( $delivery['type'] ) && 'list' === $delivery['type'] ? 'list' : 'contact';
		$recipient = isset( $delivery['recipient'] ) ? sanitize_email( (string) $delivery['recipient'] ) : '';
		$list      = isset( $delivery['listId'] ) ? sanitize_text_field( (string) $delivery['listId'] ) : '';
		return array(
			'type'      => $type,
			'recipient' => 'contact' === $type && is_email( $recipient ) ? $recipient : '',
			'list'      => 'list' === $type ? $list : '',
		);
	}

	/**
	 * kadence_blocks_form_submission: runs after Kadence's own checks and its
	 * own actions. A connected form has had Kadence's sending actions switched
	 * off in the editor, so the owner gets one email, not two.
	 *
	 * @param array      $form_args The block's saved attributes.
	 * @param array      $fields    [ { label, type, value }, … ].
	 * @param string     $form_id   The block's uniqueID.
	 * @param int|string $post_id   The post the form was saved in.
	 * @return void
	 */
	public static function kadence( $form_args, $fields, $form_id, $post_id ) {
		$delivery = self::delivery( $form_args );
		if ( ! $delivery ) {
			return;
		}
		$post = get_post( (int) $post_id );
		if ( ( '' !== $delivery['recipient'] || '' !== $delivery['list'] ) && ( ! $post || ! user_can( (int) $post->post_author, 'manage_options' ) ) ) {
			$delivery = array( 'type' => 'contact', 'recipient' => '', 'list' => '' );
		}

		$clean = array();
		foreach ( (array) $fields as $index => $field ) {
			if ( ! is_array( $field ) || ! isset( $field['value'] ) ) {
				continue;
			}
			$base = sanitize_key( isset( $field['label'] ) && '' !== trim( (string) $field['label'] ) ? str_replace( ' ', '-', strtolower( (string) $field['label'] ) ) : '' );
			if ( '' === $base && isset( $field['type'] ) && 'email' === $field['type'] ) {
				$base = 'email';
			}
			$base = '' !== $base ? $base : 'field-' . ( (int) $index + 1 );
			$key  = $base;
			for ( $n = 2; isset( $clean[ $key ] ); $n++ ) {
				$key = $base . '-' . $n;
			}
			$value         = is_array( $field['value'] ) ? implode( ', ', array_map( 'strval', $field['value'] ) ) : (string) $field['value'];
			$clean[ $key ] = sanitize_textarea_field( $value );
		}
		if ( ! $clean ) {
			return;
		}

		Clara_VE_Forms::deliver(
			sanitize_key( 'kadence-' . $form_id ),
			$clean,
			array(
				'to'        => $delivery['recipient'],
				'form_type' => $delivery['type'],
				'list_id'   => $delivery['list'],
			),
			Clara_VE_Forms::client_ip()
		);
	}
}

Clara_VE_Form_Connect::init();
