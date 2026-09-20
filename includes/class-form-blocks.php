<?php
/**
 * Editable forms as blocks.
 *
 * A form built from these blocks is part of the page: every label, placeholder,
 * list option and the button text are block attributes, so they are edited like any
 * other content, travel with the page and survive a theme change. The markup is
 * saved as a plain HTML form (the page stays valid and the form stays visible with
 * the plugin switched off); on render the form is connected to the same submission
 * backend as a [wp-form] token — stored in Form Submissions and emailed to the
 * Form Settings recipient.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Form_Blocks {

	const SUBMIT_ROUTE = '/form-submit';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 20 );
		// Not removed by clara_ve_delegate_public_runtime_to_theme(): that stands the
		// plugin down for the THEME's own forms (the theme connects those through
		// html2wp_theme_form_handle). A form block is the plugin's own content.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	private static function text_attributes() {
		return array(
			'name'         => array( 'type' => 'string', 'default' => '' ),
			'inputId'      => array( 'type' => 'string', 'default' => '' ),
			'label'        => array( 'type' => 'string', 'default' => '' ),
			'labelClass'   => array( 'type' => 'string', 'default' => '' ),
			'placeholder'  => array( 'type' => 'string', 'default' => '' ),
			'required'     => array( 'type' => 'boolean', 'default' => false ),
			'wrapperClass' => array( 'type' => 'string', 'default' => 'field' ),
			'inline'       => array( 'type' => 'boolean', 'default' => false ),
			'hint'         => array( 'type' => 'string', 'default' => '' ),
			'hintClass'    => array( 'type' => 'string', 'default' => '' ),
		);
	}

	public static function register() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'clara-ve-form-blocks', CLARA_VE_URL . 'assets/form-blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n', 'wp-data' ), clara_ve_asset_version( 'assets/form-blocks.js' ), true );
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'clara-ve-form-blocks', 'visual-edit-lite', CLARA_VE_DIR . 'languages' );
		}
		// What the editor may offer for a form's delivery. A theme registering
		// these blocks without the plugin prints no such global, and the
		// controls stay hidden there.
		wp_add_inline_script(
			'clara-ve-form-blocks',
			'window.claraVeFormBlocks = ' . wp_json_encode(
				array( 'recipient' => class_exists( 'Clara_VE_Form_Settings' ) ? Clara_VE_Form_Settings::recipient( '' ) : '' )
			) . ';',
			'before'
		);
		if ( ! wp_script_is( 'clara-ve-form-submit', 'registered' ) ) {
			wp_register_script( 'clara-ve-form-submit', CLARA_VE_URL . 'assets/form-submit.js', array(), clara_ve_asset_version( 'assets/form-submit.js' ), array( 'strategy' => 'defer' ) );
			wp_add_inline_script(
				'clara-ve-form-submit',
				'window.claraVeForm = ' . wp_json_encode(
					array(
						'sending' => __( 'Sending…', 'visual-edit-lite' ),
						'sent'    => __( 'Sent!', 'visual-edit-lite' ),
						'thanks'  => __( 'Thanks — check your inbox.', 'visual-edit-lite' ),
						'failed'  => __( 'Something went wrong — please try again.', 'visual-edit-lite' ),
					)
				) . ';',
				'before'
			);
		}
		if ( ! wp_style_is( 'clara-ve-forms', 'registered' ) ) {
			wp_register_style( 'clara-ve-forms', CLARA_VE_URL . 'assets/forms.css', array(), clara_ve_asset_version( 'assets/forms.css' ) );
		}

		$common = array(
			'api_version'           => 3,
			'category'              => 'widgets',
			'editor_script_handles' => array( 'clara-ve-form-blocks' ),
			'supports'              => array( 'html' => false, 'customClassName' => false, 'reusable' => false ),
		);
		register_block_type(
			'clara-ve/form',
			array_merge(
				$common,
				array(
					'attributes'      => array(
						'formId'       => array( 'type' => 'string', 'default' => '' ),
						'formClass'    => array( 'type' => 'string', 'default' => '' ),
						'wrapperClass' => array( 'type' => 'string', 'default' => '' ),
						'redirect'     => array( 'type' => 'string', 'default' => '' ),
						'message'      => array( 'type' => 'string', 'default' => '' ),
						// What the site does with a submission, in the same
						// vocabulary the HTML editor's FORM panel uses: an
						// enquiry is emailed and archived, a signup is handed
						// to the mailing-list provider. Empty 'recipient'
						// means the address in Form Settings.
						'formType'     => array( 'type' => 'string', 'default' => 'contact' ),
						'listId'       => array( 'type' => 'string', 'default' => '' ),
						'recipient'    => array( 'type' => 'string', 'default' => '' ),
					),
					'supports'        => array( 'html' => false, 'customClassName' => true, 'reusable' => false ),
					'render_callback' => array( __CLASS__, 'render_form' ),
				)
			)
		);
		register_block_type( 'clara-ve/field', array_merge( $common, array( 'attributes' => array_merge( self::text_attributes(), array( 'type' => array( 'type' => 'string', 'default' => 'text' ) ) ) ) ) );
		register_block_type( 'clara-ve/textarea', array_merge( $common, array( 'attributes' => array_merge( self::text_attributes(), array( 'rows' => array( 'type' => 'number', 'default' => 0 ) ) ) ) ) );
		register_block_type( 'clara-ve/select', array_merge( $common, array( 'attributes' => array_merge( self::text_attributes(), array( 'options' => array( 'type' => 'array', 'default' => array(), 'items' => array( 'type' => 'string' ) ) ) ) ) ) );
		register_block_type( 'clara-ve/checkbox', array_merge( $common, array( 'attributes' => self::text_attributes() ) ) );
		register_block_type(
			'clara-ve/form-group',
			array_merge( $common, array( 'attributes' => array( 'groupClass' => array( 'type' => 'string', 'default' => '' ) ) ) )
		);
		register_block_type(
			'clara-ve/submit',
			array_merge(
				$common,
				array(
					'attributes' => array(
						'text'      => array( 'type' => 'string', 'default' => '' ),
						'buttonClass' => array( 'type' => 'string', 'default' => '' ),
					),
				)
			)
		);
	}

	/**
	 * Connect the saved form. The saved markup is untouched otherwise, so what the
	 * editor shows is what visitors get.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Saved markup.
	 * @return string
	 */
	public static function render_form( $attributes, $content ) {
		if ( false === stripos( $content, '<form' ) || ! class_exists( 'Clara_VE_Tokens' ) ) {
			return wp_kses( $content, Clara_VE_Forms::allowed_form_html() );
		}
		// A demo marker (a theme script fakes a successful send for it) must not survive
		// on a form that really sends.
		$content = preg_replace( '~(<form\b[^>]*?)\s+data-demo(?:="[^"]*")?~i', '$1', $content );

		$redirect = trim( (string) ( $attributes['redirect'] ?? '' ) );
		if ( '' !== $redirect ) {
			$redirect = 0 === strpos( $redirect, '/' ) ? home_url( $redirect ) : $redirect;
			$redirect = wp_validate_redirect( esc_url_raw( $redirect ), '' );
		}
		$message = trim( (string) ( $attributes['message'] ?? '' ) );
		if ( '' !== $message ) {
			$content = preg_replace( '~<form\b~i', '<form data-cve-thanks="' . esc_attr( $message ) . '"', $content, 1 );
		}
		$form_id = sanitize_key( (string) ( $attributes['formId'] ?? '' ) );

		wp_enqueue_script( 'clara-ve-form-submit' );
		wp_enqueue_style( 'clara-ve-forms' );

		$form_id   = '' !== $form_id ? $form_id : 'form';
		$type      = 'list' === ( $attributes['formType'] ?? '' ) ? 'list' : 'contact';
		$list      = 'list' === $type ? sanitize_text_field( (string) ( $attributes['listId'] ?? '' ) ) : '';
		$recipient = trim( (string) ( $attributes['recipient'] ?? '' ) );
		$recipient = ( 'contact' === $type && is_email( $recipient ) ) ? sanitize_email( $recipient ) : '';

		// Signing is what makes these two values trustworthy on the way back,
		// so signing them is a decision about WHO chose them. Anyone who can
		// publish a page can add a form block, and an Author naming their own
		// address (or the owner's mailing list) would have that choice signed
		// by the site itself. Only a page whose author administers the site
		// carries a delivery choice; everyone else's form is an enquiry to the
		// address in Form Settings, which is the setting they can already see.
		// The block is only attributable to a post author when it came from the
		// post being viewed; a form in a template part belongs to the theme,
		// which already takes edit_theme_options to touch.
		$post = get_post();
		if ( ( '' !== $recipient || '' !== $list ) && $post && has_block( 'clara-ve/form', $post ) ) {
			if ( ! user_can( (int) $post->post_author, 'manage_options' ) ) {
				$recipient = '';
				$list      = '';
			}
		}

		// connect_form() signs the three delivery values into the markup for
		// every form this plugin connects; handle_submit() verifies them.
		$html = Clara_VE_Tokens::connect_form(
			array(
				'id'       => $form_id,
				'to'       => $recipient,
				'redirect' => $redirect,
				'type'     => $type,
				'list'     => $list,
			),
			$content,
			rest_url( 'clara-ve/v1' . self::SUBMIT_ROUTE )
		);

		// The last thing before WordPress prints it. Every value added above is
		// escaped where it is built; this bounds the whole string, the saved
		// markup included, by what a form may consist of. wp_kses_post() cannot
		// do it — a post has no <input> — so the allowlist is the form's own.
		return wp_kses( $html, Clara_VE_Forms::allowed_form_html() );
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			self::SUBMIT_ROUTE,
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'submit' ),
				// Public by design, like the token endpoint: the origin token, honeypot,
				// time-trap, rate limit and Akismet in Clara_VE_Forms::handle_submit() apply.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * The shared handler. A block form keeps its own route because the theme's
	 * route may be the one answering /submit on a converted site; the handler,
	 * the anti-spam layers and the delivery signature are the same either way.
	 */
	public static function submit( WP_REST_Request $request ) {
		return Clara_VE_Forms::handle_submit( $request );
	}
}

Clara_VE_Form_Blocks::init();
