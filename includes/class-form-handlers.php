<?php
/**
 * A designed form whose submissions another form plugin processes.
 *
 * A [wp-form] keeps the source design; the owner picks in the Visual Edit
 * popup what a submission does. Besides this plugin's own contact form and
 * mailing list, it can be handed to a form plugin that is already running on
 * the site — Contact Form 7 or Fluent Forms — so that plugin's validation,
 * spam checks and captcha, mail and storage (Flamingo, Fluent's entries) run
 * on it, exactly as if its own form had been sent. The design stays ours; the
 * plugin form is the processing behind it.
 *
 * Where the choice lives. The token carries it: type="cf7", and in `list` the
 * plugin form's id plus which plugin field each of our fields fills —
 * `list="12|name=your-name,email=your-email,phone="` (an empty right side is
 * "Don't send"). The `list` attribute is reused on purpose: every renderer of
 * the token already emits it as the list_id hidden field and the delivery
 * signature already covers it, so a retyped id or mapping fails the signature
 * and falls back to Form Settings like any other retyped delivery value.
 *
 * The mapping is resolved in the editor, where our fields' types and labels
 * are known (a submission only carries names), by map_fields() below — the
 * same rules the html2wp Gutenberg target uses for its form block.
 *
 * On a converted theme that runs its own public runtime (html2wp-runtime),
 * the THEME renders the token. A theme built before it learnt to sign
 * delivery does not; prepare_block() signs a connected token before the theme
 * sees it — and adds nothing to a form that already carries a signature — so
 * the theme's forwarded request verifies here either way. A plugin or plugin
 * form that is gone makes the form behave as not connected, with the owner
 * told which — never a 404, never a fatal.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Form_Handlers {

	/** Token `type` values that hand a submission to another plugin, and the plugin's name. */
	const KINDS = array(
		'cf7'        => 'Contact Form 7',
		'fluentform' => 'Fluent Forms',
	);

	/** The request field a page's captcha token travels in (see captcha()). */
	const CAPTCHA_FIELD = 'cve_captcha';

	/** The query argument a plain (no-JavaScript) post comes back with. */
	const RESULT_ARG = 'cve_result';

	public static function init() {
		// Before the token is hydrated (priority 10) — by this plugin or by a
		// converted theme's own runtime, which both read the same raw token.
		add_filter( 'render_block_core/html', array( __CLASS__, 'prepare_block' ), 9, 2 );
		// The editor's view, registered on its own: a theme that owns the
		// public runtime stands Clara_VE_Forms' routes down, not this one.
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Whether the plugin behind a handler is running.
	 *
	 * @param string $kind
	 * @return bool
	 */
	public static function available( $kind ) {
		if ( 'cf7' === $kind ) {
			return class_exists( 'WPCF7_ContactForm' );
		}
		if ( 'fluentform' === $kind ) {
			return class_exists( 'FluentForm\\App\\Models\\Form' ) && class_exists( 'FluentForm\\App\\Services\\Form\\SubmissionHandlerService' );
		}
		return false;
	}

	/**
	 * A Fluent Forms form, or null.
	 *
	 * @param string|int $id
	 * @return object|null
	 */
	private static function fluent_form( $id ) {
		if ( ! self::available( 'fluentform' ) || (int) $id < 1 ) {
			return null;
		}
		$form = \FluentForm\App\Models\Form::find( (int) $id );
		return $form ? $form : null;
	}

	/**
	 * The handlers the editor may offer: the ones whose plugin runs.
	 *
	 * @return array<int,array{value:string,label:string}>
	 */
	public static function offered() {
		$out = array();
		foreach ( self::KINDS as $kind => $label ) {
			if ( self::available( $kind ) ) {
				$out[] = array( 'value' => $kind, 'label' => $label );
			}
		}
		return $out;
	}

	/**
	 * Read the token's `list` value for a handler.
	 *
	 * @param string $list `12|name=your-name,email=`.
	 * @return array{form:string,map:array<string,string>}
	 */
	public static function parse( $list ) {
		$parts = explode( '|', (string) $list, 2 );
		$map   = array();
		foreach ( isset( $parts[1] ) ? explode( ',', $parts[1] ) : array() as $pair ) {
			$pair = explode( '=', $pair, 2 );
			$mine = sanitize_key( $pair[0] );
			if ( '' !== $mine ) {
				$map[ $mine ] = isset( $pair[1] ) ? preg_replace( '/[^A-Za-z0-9_:.\-]/', '', $pair[1] ) : '';
			}
		}
		return array(
			'form' => preg_replace( '/[^0-9]/', '', $parts[0] ),
			'map'  => $map,
		);
	}

	/**
	 * Whether a handler can run a form right now, and if not, what is missing.
	 *
	 * @param string $kind
	 * @param string $form Plugin form id.
	 * @return string '' when it can; 'plugin' or 'form' otherwise.
	 */
	public static function missing( $kind, $form ) {
		if ( ! self::available( $kind ) ) {
			return 'plugin';
		}
		return '' !== (string) $form && self::form_exists( $kind, $form ) ? '' : 'form';
	}

	/**
	 * What the owner reads when a connected form cannot reach its plugin.
	 *
	 * @param string $kind
	 * @param string $missing See missing().
	 * @return string
	 */
	public static function missing_text( $kind, $missing ) {
		$name = isset( self::KINDS[ $kind ] ) ? self::KINDS[ $kind ] : $kind;
		if ( 'plugin' === $missing ) {
			/* translators: %s: form plugin name, e.g. Contact Form 7. */
			return sprintf( __( 'Visible only to you: this form is connected to %s, which is not active — until it is, the form is not connected and sends nothing.', 'visual-edit-lite' ), $name );
		}
		/* translators: %s: form plugin name, e.g. Contact Form 7. */
		return sprintf( __( 'Visible only to you: the %s form this form was connected to no longer exists — pick another in Visual Edit (click the form). Until then it sends nothing.', 'visual-edit-lite' ), $name );
	}

	/**
	 * The plugin forms the editor offers.
	 *
	 * @param string $kind
	 * @return array<int,array{id:string,title:string}>
	 */
	public static function forms( $kind ) {
		$out = array();
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			foreach ( (array) WPCF7_ContactForm::find( array( 'posts_per_page' => 100 ) ) as $form ) {
				$out[] = array( 'id' => (string) $form->id(), 'title' => (string) $form->title() );
			}
		}
		if ( 'fluentform' === $kind && self::available( 'fluentform' ) ) {
			foreach ( \FluentForm\App\Models\Form::select( array( 'id', 'title' ) )->where( 'status', 'published' )->orderBy( 'id', 'ASC' )->limit( 100 )->get() as $form ) {
				$out[] = array( 'id' => (string) $form->id, 'title' => (string) $form->title );
			}
		}
		return $out;
	}

	/**
	 * Whether the plugin form a handler names still exists.
	 *
	 * @param string $kind
	 * @param string $id
	 * @return bool
	 */
	public static function form_exists( $kind, $id ) {
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			return (bool) WPCF7_ContactForm::get_instance( (int) $id );
		}
		if ( 'fluentform' === $kind ) {
			return (bool) self::fluent_form( $id );
		}
		return false;
	}

	/**
	 * One plugin form's fields, normalised: name (what the plugin reads),
	 * label, type (our vocabulary), required. Empty when the form does not
	 * exist.
	 *
	 * @param string $kind
	 * @param string $id
	 * @return array<int,array{name:string,label:string,type:string,required:bool}>
	 */
	public static function form_fields( $kind, $id ) {
		$fields = array();
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			$form = WPCF7_ContactForm::get_instance( (int) $id );
			if ( ! $form ) {
				return array();
			}
			// CF7 tags carry no label; its templates put one before the tag
			// inside <label> ("<label> Your name [text* your-name] </label>").
			$template = method_exists( $form, 'prop' ) ? (string) $form->prop( 'form' ) : '';
			foreach ( (array) $form->scan_form_tags() as $tag ) {
				$type = (string) ( isset( $tag->basetype ) ? $tag->basetype : '' );
				if ( '' === (string) $tag->name || in_array( $type, array( 'submit', 'hidden', 'quiz', 'recaptcha', 'response' ), true ) || preg_match( '/-response$/', (string) $tag->name ) ) {
					continue;
				}
				$label    = preg_match( '/<label[^>]*>\s*([^<\[]*?)\s*(?:<br\s*\/?>\s*)?\[[a-z_]+\*?\s+' . preg_quote( (string) $tag->name, '/' ) . '(?=[\s\]])/i', $template, $m ) && '' !== trim( $m[1] ) ? trim( $m[1] ) : (string) $tag->name;
				$fields[] = array(
					'name'     => (string) $tag->name,
					'label'    => $label,
					'type'     => 'acceptance' === $type ? 'checkbox' : $type,
					'required' => method_exists( $tag, 'is_required' ) && $tag->is_required(),
				);
			}
		}
		if ( 'fluentform' === $kind && ( $form = self::fluent_form( $id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			$inputs = \FluentForm\App\Modules\Form\FormFieldsParser::getInputs( $form, array( 'element', 'label', 'rules' ) );
			$fields = self::fluent_fields( is_array( $inputs ) ? $inputs : array() );
		}
		return $fields;
	}

	/**
	 * Fluent Forms inputs (FormFieldsParser::getInputs: name => element,
	 * label, rules) as a field list. A Name field ("names", with sub-inputs
	 * "names[first_name]"…) is offered once, as itself: one value of ours
	 * fills it, split at the first space. Captcha, layout and hidden elements
	 * are not fields a visitor of this form fills.
	 *
	 * @param array $inputs
	 * @return array
	 */
	private static function fluent_fields( $inputs ) {
		$types  = array(
			'input_email'         => 'email',
			'textarea'            => 'textarea',
			'phone'               => 'tel',
			'input_url'           => 'url',
			'input_number'        => 'number',
			'select'              => 'select',
			'input_radio'         => 'radio',
			'input_checkbox'      => 'checkbox',
			'terms_and_condition' => 'checkbox',
			'gdpr_agreement'      => 'checkbox',
		);
		$skip   = array( 'recaptcha', 'hcaptcha', 'input_hidden', 'custom_html', 'section_break', 'container', 'input_file', 'input_image', 'input_password', 'shortcode' );
		$fields = array();
		foreach ( $inputs as $name => $input ) {
			$name    = (string) $name;
			$element = isset( $input['element'] ) ? (string) $input['element'] : '';
			// A captcha's answer ("…-response") is not a field a visitor fills.
			if ( '' === $name || in_array( $element, $skip, true ) || false !== strpos( $name, '[' ) || preg_match( '/-response$/', $name ) ) {
				continue;
			}
			$required = ! empty( $input['rules']['required']['value'] );
			$label    = trim( isset( $input['label'] ) ? (string) $input['label'] : '' );
			if ( 'input_name' === $element ) {
				foreach ( $inputs as $sub => $sub_input ) {
					if ( 0 === strpos( (string) $sub, $name . '[' ) && ! empty( $sub_input['rules']['required']['value'] ) ) {
						$required = true;
					}
				}
				$label = '' !== $label ? $label : 'Name';
			}
			$fields[] = array(
				'name'     => $name,
				'label'    => '' !== $label ? $label : $name,
				'type'     => 'input_name' === $element ? 'name' : ( isset( $types[ $element ] ) ? $types[ $element ] : 'text' ),
				'required' => $required,
			);
		}
		return $fields;
	}

	/**
	 * The captcha the plugin checks this form's submissions with, or null.
	 *
	 * Put on the form (public site key only) so assets/form-handler.js can
	 * load the provider and send a token with the submission (CAPTCHA_FIELD);
	 * forward() puts it where the plugin reads it, and the plugin verifies it
	 * with its own secret, as for its own forms. The same answers as the
	 * html2wp Gutenberg target's h2wp_gb_handler_captcha().
	 *
	 * @param string $kind
	 * @param string $form_id
	 * @return array{provider:string,siteKey:string,field:string,action?:string}|null
	 *   provider 'recaptcha-v3'|'recaptcha-v2'|'recaptcha-v2-invisible'|'hcaptcha'.
	 *
	 * Lite answers reCAPTCHA and hCaptcha only. A plugin form protected by
	 * another challenge gets no token from this page, and its plugin refuses
	 * the submission with its own message.
	 */
	public static function captcha( $kind, $form_id ) {
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			// CF7 checks it on every form while its integration is set up.
			if ( class_exists( 'WPCF7_RECAPTCHA' ) && ( $service = WPCF7_RECAPTCHA::get_instance() ) && $service->is_active() && $service->get_sitekey() ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
				return array( 'provider' => 'recaptcha-v3', 'siteKey' => (string) $service->get_sitekey(), 'field' => '_wpcf7_recaptcha_response', 'action' => 'contactform' );
			}
			return null;
		}
		if ( 'fluentform' === $kind && ( $form = self::fluent_form( $form_id ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.Found
			// A form checks the captcha it contains, or the one the global
			// "autoload captcha" setting adds to every form — which Fluent
			// applies only once that provider's keys were verified.
			$global   = get_option( '_fluentform_global_form_settings' );
			$auto     = is_array( $global ) && ! empty( $global['misc']['autoload_captcha'] ) && isset( $global['misc']['captcha_type'] ) ? (string) $global['misc']['captcha_type'] : '';
			$verified = array( 'recaptcha' => '_fluentform_reCaptcha_keys_status', 'hcaptcha' => '_fluentform_hCaptcha_keys_status' );
			$has      = static function ( $element ) use ( $form, $auto, $verified ) {
				return ( $auto === $element && isset( $verified[ $element ] ) && get_option( $verified[ $element ], false ) ) || \FluentForm\App\Modules\Form\FormFieldsParser::hasElement( $form, $element );
			};
			if ( $has( 'recaptcha' ) ) {
				$keys     = (array) get_option( '_fluentform_reCaptcha_details' );
				$version  = isset( $keys['api_version'] ) ? (string) $keys['api_version'] : 'v2_visible';
				$provider = 'v3_invisible' === $version ? 'recaptcha-v3' : ( 'v2_invisible' === $version ? 'recaptcha-v2-invisible' : 'recaptcha-v2' );
				return ! empty( $keys['siteKey'] ) ? array( 'provider' => $provider, 'siteKey' => (string) $keys['siteKey'], 'field' => 'g-recaptcha-response', 'action' => 'submit' ) : null;
			}
			if ( $has( 'hcaptcha' ) ) {
				$keys = (array) get_option( '_fluentform_hCaptcha_details' );
				return ! empty( $keys['siteKey'] ) ? array( 'provider' => 'hcaptcha', 'siteKey' => (string) $keys['siteKey'], 'field' => 'h-captcha-response' ) : null;
			}
		}
		return null;
	}

	/**
	 * Which plugin field each of our fields fills.
	 *
	 * An owner's choice ($chosen: ours => theirs, '' for "not sent") wins; the
	 * rest is matched in order of confidence, each plugin field taken once:
	 * the same name (ignoring case, punctuation and CF7's "your-" prefix),
	 * then the one field of the same type (email, textarea, tel, url, number,
	 * select, checkbox), then the same label, then a name that contains the
	 * other ("message" ↔ "your-message"). Same rules as the html2wp Gutenberg
	 * target's h2wp_gb_map_form_fields(), so a form maps the same either way.
	 *
	 * @param array $ours   [ { name, type, label }, … ]
	 * @param array $theirs form_fields()
	 * @param array $chosen ours => theirs
	 * @return array{map:array<string,string>,unmappedRequired:string[]}
	 */
	public static function map_fields( $ours, $theirs, $chosen = array() ) {
		$norm   = static function ( $s ) {
			$s = strtolower( (string) $s );
			$s = preg_replace( '/^your[-_]?/', '', $s );
			return preg_replace( '/[^a-z0-9]/', '', $s );
		};
		$theirs = array_values(
			array_filter(
				(array) $theirs,
				static function ( $f ) {
					return isset( $f['name'] ) && '' !== $f['name'];
				}
			)
		);
		$used   = array();
		$map    = array();
		foreach ( (array) $chosen as $mine => $name ) {
			if ( ! is_string( $mine ) || ! is_string( $name ) ) {
				continue;
			}
			$map[ $mine ] = $name;
			if ( '' !== $name ) {
				$used[ $name ] = true;
			}
		}
		$free  = static function () use ( &$theirs, &$used ) {
			return array_values(
				array_filter(
					$theirs,
					static function ( $f ) use ( &$used ) {
						return empty( $used[ $f['name'] ] );
					}
				)
			);
		};
		$field = static function ( $o, $key ) {
			return isset( $o[ $key ] ) ? (string) $o[ $key ] : '';
		};
		$rules = array(
			static function ( $o, $t ) use ( $norm, $field ) {
				return '' !== $norm( $field( $o, 'name' ) ) && $norm( $field( $o, 'name' ) ) === $norm( $t['name'] );
			},
			null, // Same type, when exactly one free field has it.
			static function ( $o, $t ) use ( $norm, $field ) {
				return '' !== $norm( $field( $o, 'label' ) ) && $norm( $field( $o, 'label' ) ) === $norm( $t['label'] );
			},
			static function ( $o, $t ) use ( $norm, $field ) {
				$a = $norm( $field( $o, 'name' ) );
				$b = $norm( $t['name'] );
				return '' !== $a && '' !== $b && ( false !== strpos( $b, $a ) || false !== strpos( $a, $b ) );
			},
		);
		foreach ( $rules as $rule ) {
			foreach ( (array) $ours as $o ) {
				$mine = $field( $o, 'name' );
				if ( '' === $mine || array_key_exists( $mine, $map ) ) {
					continue;
				}
				$candidates = $free();
				$hit        = null;
				if ( null === $rule ) {
					$type = '' !== $field( $o, 'type' ) ? $field( $o, 'type' ) : 'text';
					if ( 'text' === $type ) {
						continue;
					}
					$same = array_values(
						array_filter(
							$candidates,
							static function ( $t ) use ( $type ) {
								return isset( $t['type'] ) && $t['type'] === $type;
							}
						)
					);
					$hit  = 1 === count( $same ) ? $same[0] : null;
				} else {
					foreach ( $candidates as $t ) {
						if ( $rule( $o, $t ) ) {
							$hit = $t;
							break;
						}
					}
				}
				if ( $hit ) {
					$map[ $mine ]         = $hit['name'];
					$used[ $hit['name'] ] = true;
				}
			}
		}
		$unmapped = array();
		foreach ( $theirs as $t ) {
			if ( ! empty( $t['required'] ) && empty( $used[ $t['name'] ] ) ) {
				$unmapped[] = '' !== $t['label'] ? $t['label'] : $t['name'];
			}
		}
		return array(
			'map'              => array_filter( $map, 'strlen' ),
			'unmappedRequired' => $unmapped,
		);
	}

	/**
	 * Hand one submission to the plugin and read its verdict back.
	 *
	 * @param string $kind
	 * @param string $form_id Plugin form id.
	 * @param array  $values  Plugin field name => string|string[].
	 * @param string $captcha The page's captcha token, '' when none — put where
	 *                        the plugin reads it (see captcha()).
	 * @return array{status:string,errors:array<string,string>,message:string,redirect?:string}
	 *   status 'sent'|'invalid'|'spam'|'error'; errors keyed by PLUGIN field
	 *   name; redirect where the plugin's own confirmation sends the visitor.
	 */
	public static function forward( $kind, $form_id, $values, $captcha = '' ) {
		$spec = self::captcha( $kind, $form_id );
		if ( $spec && '' !== $captcha ) {
			$values[ $spec['field'] ] = $captcha;
		}
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			$verdict = self::forward_cf7( $form_id, $values );
		} elseif ( 'fluentform' === $kind && self::available( 'fluentform' ) ) {
			$verdict = self::forward_fluentform( $form_id, $values );
		} else {
			return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
		}
		// A failed captcha is not a field the visitor can correct (Fluent
		// reports it as a validation error): it is a refusal.
		if ( $spec && isset( $verdict['errors'][ $spec['field'] ] ) ) {
			$verdict['message'] = trim( $verdict['message'] . ' ' . $verdict['errors'][ $spec['field'] ] );
			unset( $verdict['errors'][ $spec['field'] ] );
			$verdict['status'] = 'spam';
		}
		return $verdict;
	}

	/**
	 * Contact Form 7 reads a submission from $_POST, the way its own REST
	 * feedback endpoint receives one: the fields under their tag names plus
	 * the form's id and a unit tag. submit() then runs CF7's validation, its
	 * spam checks (the request is the visitor's own, so are its user agent and
	 * IP; Akismet and the disallowed list apply; reCAPTCHA v3 with the token
	 * the page obtained), mail, and Flamingo when active.
	 * $_POST is restored afterwards.
	 *
	 * CF7 checks its own nonce only for a logged-in submitter. This route is
	 * reached anonymously — core drops a cookie without X-WP-Nonce to user 0 —
	 * so a script that ever starts sending X-WP-Nonce here makes CF7 refuse a
	 * logged-in owner's test. A CF7 form set to subscribers_only refuses every
	 * submission this way, for the same reason.
	 *
	 * @param string $form_id
	 * @param array  $values
	 * @return array{status:string,errors:array<string,string>,message:string}
	 */
	private static function forward_cf7( $form_id, $values ) {
		$form = WPCF7_ContactForm::get_instance( (int) $form_id );
		if ( ! $form ) {
			return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
		}
		$saved = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- restored below; the plugin reads the submission from here.
		// Contact Form 7 reads its own endpoint's $_POST, which PHP has slashed,
		// and wp_unslash()es it. The values here have already been unslashed by
		// the REST server, so they are slashed again to arrive the same way —
		// without this, every backslash a visitor typed was lost on the way in.
		$_POST = wp_slash(
			array_merge(
				(array) $values,
				array(
					'_wpcf7'                => (string) $form->id(),
					'_wpcf7_version'        => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
					'_wpcf7_locale'         => method_exists( $form, 'locale' ) ? (string) $form->locale() : '',
					'_wpcf7_unit_tag'       => 'wpcf7-f' . $form->id() . '-o1',
					'_wpcf7_container_post' => '0',
				)
			)
		);
		// Only the signed mapping reaches the form. Contact Form 7 reads file
		// uploads straight from $_FILES, which the mapping never carries, so an
		// upload posted under a name that happens to match one of its [file]
		// tags would otherwise be processed outside the owner's choice.
		$files  = $_FILES;
		$_FILES = array();
		try {
			$result = (array) $form->submit();
		} catch ( Throwable $e ) {
			$result = array( 'status' => 'error', 'message' => '' );
		}
		$_POST  = $saved;
		$_FILES = $files;

		$errors = array();
		foreach ( (array) ( isset( $result['invalid_fields'] ) ? $result['invalid_fields'] : array() ) as $name => $field ) {
			$errors[ (string) $name ] = wp_strip_all_tags( (string) ( is_array( $field ) ? ( isset( $field['reason'] ) ? $field['reason'] : '' ) : $field ) );
		}
		$statuses = array( 'mail_sent' => 'sent', 'validation_failed' => 'invalid', 'spam' => 'spam' );
		$status   = isset( $result['status'] ) ? (string) $result['status'] : '';
		return array(
			'status'  => isset( $statuses[ $status ] ) ? $statuses[ $status ] : 'error',
			'errors'  => $errors,
			'message' => wp_strip_all_tags( (string) ( isset( $result['message'] ) ? $result['message'] : '' ) ),
		);
	}

	/**
	 * Fluent Forms submits through its own SubmissionHandlerService — the
	 * service its AJAX endpoint uses — which validates (rules, captcha, its
	 * own too-many-requests guard), checks spam (Akismet/CleanTalk), stores
	 * the entry, sends the form's notifications and answers with the form's
	 * confirmation. Validation failures arrive as its ValidationException
	 * (`errors` keyed by input name, one message per rule). A Name field takes
	 * our single value split at the first space; a checkbox group a list.
	 *
	 * @param string $form_id
	 * @param array  $values
	 * @return array{status:string,errors:array<string,string>,message:string,redirect?:string}
	 */
	private static function forward_fluentform( $form_id, $values ) {
		$form = self::fluent_form( $form_id );
		if ( ! $form ) {
			return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
		}
		$inputs = \FluentForm\App\Modules\Form\FormFieldsParser::getInputs( $form, array( 'element' ) );
		$data   = array();
		foreach ( $values as $name => $value ) {
			$element = isset( $inputs[ $name ]['element'] ) ? (string) $inputs[ $name ]['element'] : '';
			if ( 'input_name' === $element ) {
				$parts         = preg_split( '/\s+/', trim( is_array( $value ) ? implode( ' ', $value ) : (string) $value ), 2 );
				$data[ $name ] = array( 'first_name' => isset( $parts[0] ) ? $parts[0] : '', 'last_name' => isset( $parts[1] ) ? $parts[1] : '' );
			} elseif ( 'input_checkbox' === $element ) {
				$data[ $name ] = is_array( $value ) ? $value : ( '' === $value ? array() : array( (string) $value ) );
			} elseif ( in_array( $element, array( 'terms_and_condition', 'gdpr_agreement' ), true ) ) {
				if ( '' !== $value && array() !== $value ) {
					$data[ $name ] = 'on';
				}
			} else {
				$data[ $name ] = is_array( $value ) ? implode( ', ', $value ) : $value;
			}
		}
		// Its nonce check is off by default. When a site turns it on, this
		// request has already passed this plugin's own origin check.
		// Fluent Forms expects its own nonce, minted by the page that shows the
		// form. This request never saw that page: it is the one this plugin
		// already accepted — origin token, honeypot, time-trap and rate limit
		// all passed in handle_submit() before anything reaches here — so the
		// nonce is minted now, for the same anonymous context Fluent would
		// mint it in. It stands in for a check this plugin has already done,
		// not for one it skipped.
		$data[ '_fluentform_' . (int) $form_id . '_fluentformnonce' ] = wp_create_nonce( 'fluentform-submit-form' );
		try {
			$result = ( new \FluentForm\App\Services\Form\SubmissionHandlerService() )->handleSubmission( $data, (int) $form_id );
		} catch ( Throwable $e ) {
			if ( ! method_exists( $e, 'errors' ) ) {
				return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
			}
			$errors = array();
			$loose  = array();
			$all    = (array) $e->errors();
			foreach ( (array) ( isset( $all['errors'] ) ? $all['errors'] : $all ) as $name => $messages ) {
				$text = trim( wp_strip_all_tags( (string) ( is_array( $messages ) ? reset( $messages ) : $messages ) ) );
				if ( '' === $text ) {
					continue;
				}
				// A sub-input's error ("names[first_name]") belongs to its field.
				$base = preg_replace( '/\[.*$/', '', (string) $name );
				if ( isset( $values[ $base ] ) ) {
					$errors[ $base ] = isset( $errors[ $base ] ) ? $errors[ $base ] : $text;
				} else {
					$loose[ $base ] = $text;
				}
			}
			// Kept under the plugin's name so forward() can recognise its
			// captcha; submit() moves the rest into the message.
			return array( 'status' => $errors || $loose || in_array( (int) $e->getCode(), array( 422, 423 ), true ) ? 'invalid' : 'error', 'errors' => array_merge( $loose, $errors ), 'message' => '' );
		}
		$answer = isset( $result['result'] ) && is_array( $result['result'] ) ? $result['result'] : array();
		return array(
			'status'   => 'sent',
			'errors'   => array(),
			'message'  => trim( wp_strip_all_tags( (string) ( isset( $answer['message'] ) ? $answer['message'] : '' ) ) ),
			'redirect' => esc_url_raw( (string) ( isset( $answer['redirectUrl'] ) ? $answer['redirectUrl'] : '' ) ),
		);
	}

	/**
	 * A submission whose signed delivery names a handler, after
	 * Clara_VE_Forms::handle_submit() has run its own checks (honeypot,
	 * origin, time-trap, rate limit). Nothing is stored under Form
	 * Submissions: the plugin keeps its own record.
	 *
	 * @param string $kind
	 * @param string $list     The signed `list` value (see parse()).
	 * @param array  $params   The request, as received.
	 * @param string $rate_key The rate-limit entry this request set, or ''.
	 * @return array{status:string,errors:array<string,string>,message:string,redirect:string,values:array}
	 *   errors keyed by OUR field name; redirect the plugin's own confirmation
	 *   target; values what the visitor sent, under our names.
	 */
	public static function submit( $kind, $list, $params, $rate_key ) {
		$config = self::parse( $list );
		if ( '' !== self::missing( $kind, $config['form'] ) ) {
			return array( 'status' => 'closed', 'errors' => array(), 'message' => __( 'This form isn’t accepting messages right now. Please reach out another way.', 'visual-edit-lite' ), 'redirect' => '', 'values' => array() );
		}

		// Our fields under the names Clara_VE_Forms stores them by: the same
		// sanitize_key the editor used when it wrote the mapping. A checkbox
		// group stays a list — the plugin validates its options one by one.
		$ours = array();
		foreach ( (array) $params as $key => $value ) {
			$name = sanitize_key( (string) $key );
			if ( '' === $name || 0 === strpos( $name, '_' ) ) {
				continue;
			}
			if ( is_scalar( $value ) ) {
				$ours[ $name ] = sanitize_textarea_field( (string) $value );
			} elseif ( is_array( $value ) && $value && array_filter( $value, 'is_scalar' ) === $value ) {
				$ours[ $name ] = array_map( 'sanitize_textarea_field', array_map( 'strval', array_values( $value ) ) );
			}
		}
		$values = array();
		foreach ( $config['map'] as $mine => $theirs ) {
			if ( '' !== $theirs && array_key_exists( $mine, $ours ) ) {
				$values[ $theirs ] = $ours[ $mine ];
			}
		}
		$captcha = isset( $params[ self::CAPTCHA_FIELD ] ) && is_string( $params[ self::CAPTCHA_FIELD ] ) ? sanitize_text_field( $params[ self::CAPTCHA_FIELD ] ) : '';

		$verdict = self::forward( $kind, $config['form'], $values, $captcha );

		// Errors come back under the plugin's names; the page knows ours.
		$back   = array();
		$loose  = array();
		$errors = array();
		foreach ( $config['map'] as $mine => $theirs ) {
			if ( '' !== $theirs ) {
				$back[ $theirs ] = $mine;
			}
		}
		foreach ( $verdict['errors'] as $name => $message ) {
			if ( isset( $back[ $name ] ) ) {
				$errors[ $back[ $name ] ] = $message;
			} elseif ( '' !== $message ) {
				$loose[] = $message;
			}
		}
		$message = $verdict['message'];
		if ( $loose ) {
			$message = trim( $message . ' ' . implode( ' ', $loose ) );
		}
		// A visitor correcting what the plugin refused sends again at once,
		// and that is not the burst the rate limit is there for. The limit is
		// shortened rather than removed: without JavaScript every refused post
		// writes a result row (see result_url()), and a limit that vanished on
		// each refusal would let one address write them as fast as it can post.
		// Five seconds is a person retyping a field; it is not a loop.
		if ( 'invalid' === $verdict['status'] && '' !== $rate_key ) {
			set_transient( $rate_key, 1, 5 );
		}
		return array(
			'status'   => $verdict['status'],
			'errors'   => $errors,
			'message'  => $message,
			'redirect' => isset( $verdict['redirect'] ) ? (string) $verdict['redirect'] : '',
			'values'   => array_intersect_key( $ours, $config['map'] ),
		);
	}

	/**
	 * render_block_core/html, before the token is hydrated: a form connected
	 * to a handler gets what its renderer does not give it.
	 *
	 * - Connected and reachable: the script that sends it (field errors,
	 *   captcha token), the captcha the plugin checks, a plain post's verdict
	 *   when it came back with one, and — on a theme that renders the token
	 *   itself and did not sign it — the delivery signature.
	 * - Its plugin or plugin form gone: the token is taken off, so the form
	 *   is simply not connected — the theme's and this plugin's own handling
	 *   of an unconnected form applies — and someone who can edit the page is
	 *   told why under it. The edit preview keeps the token: the editor still
	 *   has to find the connection to change it.
	 *
	 * @param string $block_content
	 * @param array  $block
	 * @return string
	 */
	public static function prepare_block( $block_content, $block ) {
		if ( false === strpos( (string) $block_content, 'clara-ve-key:' ) || false === strpos( (string) $block_content, '[wp-form' ) ) {
			return $block_content;
		}
		// A comment that mentions a token is documentation, not a token —
		// masked the same way Clara_VE_Tokens::hydrate() masks them.
		$comments = array();
		$masked   = preg_replace_callback(
			'/<!--.*?-->/s',
			static function ( $m ) use ( &$comments ) {
				$placeholder              = '<!--cve-h' . count( $comments ) . '-->';
				$comments[ $placeholder ] = $m[0];
				return $placeholder;
			},
			$block_content
		);
		if ( null === $masked ) {
			return $block_content;
		}
		$out = preg_replace_callback( '/\[wp-form\b([^\]]*)\](.*?)\[\/wp-form\]/s', array( __CLASS__, 'prepare_token' ), $masked );
		if ( null === $out ) {
			return $block_content;
		}
		return $comments ? strtr( $out, $comments ) : $out;
	}

	/**
	 * @param array $m [0] token, [1] raw attributes, [2] inner form markup.
	 * @return string
	 */
	private static function prepare_token( $m ) {
		$atts = shortcode_parse_atts( $m[1] );
		$atts = is_array( $atts ) ? $atts : array();
		$kind = sanitize_key( (string) ( isset( $atts['type'] ) ? $atts['type'] : '' ) );
		if ( ! isset( self::KINDS[ $kind ] ) ) {
			return $m[0];
		}
		$list    = (string) ( isset( $atts['list'] ) ? $atts['list'] : '' );
		$config  = self::parse( $list );
		$missing = self::missing( $kind, $config['form'] );
		$preview = function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview();
		$form_id = sanitize_key( isset( $atts['id'] ) ? $atts['id'] : 'form' );

		if ( '' !== $missing ) {
			$note = current_user_can( 'edit_pages' )
				? '<p class="cve-form-message" data-cve-error="1" role="status">' . esc_html( self::missing_text( $kind, $missing ) ) . '</p>'
				: '';
			return $preview ? str_replace( '[/wp-form]', $note . '[/wp-form]', $m[0] ) : $m[2] . $note;
		}
		if ( $preview ) {
			return $m[0];
		}

		self::enqueue();
		$inner = $m[2];

		// A designed form rarely says method="post" — its app never
		// submitted it — and without one the browser's own submit (no
		// JavaScript) is a GET the submit endpoint does not answer.
		if ( preg_match( '/<form\b[^>]*>/i', $inner, $open ) && ! preg_match( '/\smethod\s*=/i', $open[0] ) ) {
			$inner = preg_replace( '/<form\b/i', '<form method="post"', $inner, 1 );
		}

		// The plugin's captcha, for the script to answer (public key only).
		$spec = self::captcha( $kind, $config['form'] );
		if ( $spec ) {
			$inner = preg_replace( '/<form\b/i', '<form data-cve-captcha="' . esc_attr( wp_json_encode( $spec ) ) . '"', $inner, 1 );
		}

		// A plain post of this form came back with its verdict.
		$result = self::result_for( $form_id );
		if ( $result ) {
			$shown = self::show_result( $inner, $result );
			if ( $shown['replace'] ) {
				// The design's thank-you takes the form's place: nothing
				// left to connect.
				return $shown['html'];
			}
			$inner = $shown['html'];
		}

		// Signed over exactly what Clara_VE_Tokens::render_form() signs and
		// what a converted theme puts in its hidden fields, normalised the way
		// handle_submit() reads them back. Only where the THEME renders the
		// token (this plugin's renderer signs it itself), and only when the
		// markup does not already carry a signature, so there is never a
		// second one.
		if ( function_exists( 'clara_ve_theme_owns_public_runtime' ) && clara_ve_theme_owns_public_runtime() && false === strpos( $inner, 'name="' . Clara_VE_Forms::DELIVERY_FIELD . '"' ) ) {
			$to     = trim( (string) ( isset( $atts['to'] ) ? $atts['to'] : '' ) );
			$signed = preg_replace( '/(<form\b[^>]*>)/i', '$1' . Clara_VE_Forms::delivery_field( $form_id, $to, $kind, $list ), $inner, 1 );
			$inner  = null === $signed ? $inner : $signed;
		}
		// What this method GENERATES — the owner-only note, the captcha
		// attribute, the signature field, the shown result — is escaped where
		// it is built. $inner is the rest of the block's own content, already
		// rendered by WordPress and passed through unchanged, as a
		// render_block_core/html filter does; escaping it again would break the
		// form it exists to hand over.
		return '[wp-form' . $m[1] . ']' . $inner . '[/wp-form]';
	}

	/**
	 * Where a plain (no-JavaScript) post goes back to: its page, with a key
	 * to the verdict kept for ten minutes. The key is random and known only
	 * to whoever submitted, since what the visitor typed is kept with it so
	 * the form can be filled in again.
	 *
	 * @param string $back    The page the post came from.
	 * @param string $form_id
	 * @param array  $verdict submit()'s answer.
	 * @return string
	 */
	public static function result_url( $back, $form_id, $verdict ) {
		$key = strtolower( wp_generate_password( 20, false, false ) );
		// What is kept for the page to show is bounded: an anonymous post
		// decides its own size, and this row lives in wp_options for ten
		// minutes. A field is cut at 4000 characters and at most 60 fields are
		// kept — more than any designed form has, and less than a payload
		// meant to fill a table.
		$values = array();
		if ( 'sent' !== $verdict['status'] ) {
			foreach ( array_slice( (array) $verdict['values'], 0, 60, true ) as $name => $value ) {
				$values[ $name ] = is_scalar( $value ) ? mb_substr( (string) $value, 0, 4000 ) : '';
			}
		}
		$errors = array();
		foreach ( array_slice( (array) $verdict['errors'], 0, 60, true ) as $name => $error ) {
			$errors[ $name ] = mb_substr( (string) $error, 0, 500 );
		}
		set_transient(
			'clara_ve_form_result_' . $key,
			array(
				'form'    => (string) $form_id,
				'status'  => (string) $verdict['status'],
				'errors'  => $errors,
				'message' => mb_substr( (string) $verdict['message'], 0, 1000 ),
				'values'  => $values,
			),
			10 * MINUTE_IN_SECONDS
		);
		return add_query_arg( self::RESULT_ARG, $key, remove_query_arg( array( self::RESULT_ARG, 'cve_sent' ), $back ) );
	}

	/**
	 * The verdict a plain post of this form came back with, or null.
	 *
	 * @param string $form_id
	 * @return array|null
	 */
	private static function result_for( $form_id ) {
		$key = isset( $_GET[ self::RESULT_ARG ] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) wp_unslash( $_GET[ self::RESULT_ARG ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to [a-z0-9] by the preg_replace on this line; a lookup key for a page view, nothing is processed.
		if ( '' === $key ) {
			return null;
		}
		$result = get_transient( 'clara_ve_form_result_' . $key );
		return is_array( $result ) && isset( $result['form'] ) && $result['form'] === $form_id ? $result : null;
	}

	/**
	 * The form's markup with a verdict shown in it, the way the script shows
	 * one: a thank-you after the form (the design's recorded one first), or
	 * each reason under its field in the design's own error style, the
	 * summary after the form, and what the visitor typed filled back in.
	 *
	 * @param string $inner  The form markup inside the token.
	 * @param array  $result result_for().
	 * @return array{html:string,replace:bool} replace: the design's recorded
	 *   success takes the form's place, and html is that alone.
	 */
	private static function show_result( $inner, $result ) {
		if ( ! preg_match( '/<form\b[^>]*>/i', $inner, $open ) ) {
			return array( 'html' => $inner, 'replace' => false );
		}
		$attr = static function ( $name ) use ( $open ) {
			return preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*"([^"]*)"/i', $open[0], $v ) ? html_entity_decode( $v[1], ENT_QUOTES ) : '';
		};
		$close = strripos( $inner, '</form>' );
		$after = false === $close ? strlen( $inner ) : $close + 7;

		if ( 'sent' === $result['status'] ) {
			$recorded = json_decode( $attr( 'data-spa-success' ), true );
			if ( is_array( $recorded ) && ! empty( $recorded['html'] ) ) {
				$html = wp_kses_post( (string) $recorded['html'] );
				if ( isset( $recorded['kind'] ) && 'replace' === $recorded['kind'] ) {
					return array( 'html' => $html, 'replace' => true );
				}
				return array( 'html' => substr( $inner, 0, $after ) . $html . substr( $inner, $after ), 'replace' => false );
			}
			$text = '' !== $attr( 'data-cve-thanks' ) ? $attr( 'data-cve-thanks' ) : ( '' !== $result['message'] ? $result['message'] : __( 'Thanks — check your inbox.', 'visual-edit-lite' ) );
			return array( 'html' => substr( $inner, 0, $after ) . '<p class="cve-form-message" data-cve-handler-note="" role="status">' . esc_html( $text ) . '</p>' . substr( $inner, $after ), 'replace' => false );
		}

		// The design's own error look, when it recorded one.
		$class = 'cve-form-message';
		if ( preg_match( '/<[a-z0-9]+\b[^>]*\bdata-spa-invalid\b[^>]*>/i', $inner, $look ) && preg_match( '/\sclass\s*=\s*"([^"]*)"/i', $look[0], $c ) ) {
			$class = html_entity_decode( $c[1], ENT_QUOTES );
		}
		$line = static function ( $text ) use ( $class ) {
			return '<p class="' . esc_attr( $class ) . '" data-cve-handler-note="" data-cve-error="1" role="alert">' . esc_html( $text ) . '</p>';
		};

		$errors = (array) $result['errors'];
		$values = (array) $result['values'];
		$placed = array();
		$form   = substr( $inner, 0, $after );
		// One pass per control: fill it back in, then put its reason after it.
		$form = preg_replace_callback(
			'/<(input)\b([^>]*)>|<(textarea|select)\b([^>]*)>(.*?)<\/\3>/is',
			static function ( $c ) use ( $errors, $values, $line, &$placed ) {
				$tag   = '' !== $c[1] ? 'input' : strtolower( $c[3] );
				$attrs = '' !== $c[1] ? $c[2] : $c[4];
				if ( ! preg_match( '/\sname\s*=\s*"([^"]*)"/i', $attrs, $n ) ) {
					return $c[0];
				}
				$name = sanitize_key( preg_replace( '/\[\]$/', '', html_entity_decode( $n[1], ENT_QUOTES ) ) );
				$out  = $c[0];
				if ( isset( $values[ $name ] ) && is_scalar( $values[ $name ] ) ) {
					$value = (string) $values[ $name ];
					if ( 'input' === $tag ) {
						$type = preg_match( '/\stype\s*=\s*"([^"]*)"/i', $attrs, $t ) ? strtolower( $t[1] ) : 'text';
						if ( ! in_array( $type, array( 'checkbox', 'radio', 'hidden', 'submit', 'button', 'password', 'file', 'image', 'reset' ), true ) ) {
							$bare = preg_replace( '/\svalue\s*=\s*"[^"]*"/i', '', $attrs );
							$out  = '<input' . rtrim( $bare, '/ ' ) . ' value="' . esc_attr( $value ) . '">';
						}
					} elseif ( 'textarea' === $tag ) {
						$out = '<textarea' . $attrs . '>' . esc_textarea( $value ) . '</textarea>';
					}
				}
				if ( isset( $errors[ $name ] ) && '' !== $errors[ $name ] && empty( $placed[ $name ] ) ) {
					$placed[ $name ] = true;
					$out            .= $line( $errors[ $name ] );
				}
				return $out;
			},
			$form
		);
		$left = array_diff_key( array_filter( $errors, 'strlen' ), $placed );
		$text = trim( ( '' !== $result['message'] ? $result['message'] : __( 'Please check the fields marked below.', 'visual-edit-lite' ) ) . ' ' . implode( ' ', $left ) );
		return array( 'html' => ( null === $form ? substr( $inner, 0, $after ) : $form ) . $line( $text ) . substr( $inner, $after ), 'replace' => false );
	}

	/**
	 * The script that sends a handler-connected form: it listens ahead of
	 * whichever runtime would otherwise send it (see assets/form-handler.js).
	 *
	 * @return void
	 */
	private static function enqueue() {
		if ( wp_script_is( 'clara-ve-form-handler', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style( 'clara-ve-forms', CLARA_VE_URL . 'assets/forms.css', array(), clara_ve_asset_version( 'assets/forms.css' ) );
		wp_enqueue_script( 'clara-ve-form-handler', CLARA_VE_URL . 'assets/form-handler.js', array(), clara_ve_asset_version( 'assets/form-handler.js' ), array( 'in_footer' => true ) );
		wp_add_inline_script(
			'clara-ve-form-handler',
			'window.claraVeFormHandler = ' . wp_json_encode(
				array(
					'sending' => __( 'Sending…', 'visual-edit-lite' ),
					'sent'    => __( 'Sent!', 'visual-edit-lite' ),
					'thanks'  => __( 'Thanks — check your inbox.', 'visual-edit-lite' ),
					'failed'  => __( 'Something went wrong — please try again.', 'visual-edit-lite' ),
					'verify'  => __( 'Please complete the verification first.', 'visual-edit-lite' ),
					'kinds'   => array_keys( self::KINDS ),
				)
			) . ';',
			'before'
		);
	}

	public static function register_routes() {
		register_rest_route(
			'clara-ve/v1',
			'/form-handlers',
			array(
				'methods'             => array( 'GET', 'POST' ),
				'callback'            => array( __CLASS__, 'rest_handlers' ),
				'permission_callback' => 'clara_ve_user_can_edit',
			)
		);
	}

	/**
	 * The editor's view of what a form can be handed to: the running
	 * handlers, each one's forms, and — given our fields and a plugin form —
	 * the mapping, the plugin form's fields and what it requires that nothing
	 * fills.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function rest_handlers( WP_REST_Request $request ) {
		$out = array( 'handlers' => self::offered(), 'forms' => array() );
		foreach ( self::offered() as $handler ) {
			$out['forms'][ $handler['value'] ] = self::forms( $handler['value'] );
		}
		$kind = sanitize_key( (string) $request->get_param( 'handler' ) );
		$form = preg_replace( '/[^0-9]/', '', (string) $request->get_param( 'form' ) );
		if ( isset( self::KINDS[ $kind ] ) && '' !== $form ) {
			$out['missing'] = self::missing( $kind, $form );
			if ( '' === $out['missing'] ) {
				$ours   = array();
				foreach ( (array) $request->get_param( 'fields' ) as $field ) {
					$field  = is_array( $field ) ? $field : array();
					$ours[] = array(
						'name'  => sanitize_key( isset( $field['name'] ) ? (string) $field['name'] : '' ),
						'type'  => sanitize_key( isset( $field['type'] ) ? (string) $field['type'] : 'text' ),
						'label' => sanitize_text_field( isset( $field['label'] ) ? (string) $field['label'] : '' ),
					);
				}
				$chosen = array();
				foreach ( (array) $request->get_param( 'fieldMap' ) as $mine => $theirs ) {
					if ( is_string( $mine ) && is_string( $theirs ) ) {
						// The same characters parse() accepts when the token is read
						// back, so what the editor writes is what the page will use.
						$chosen[ sanitize_key( $mine ) ] = preg_replace( '/[^A-Za-z0-9_:.\-]/', '', (string) $theirs );
					}
				}
				$theirs         = self::form_fields( $kind, $form );
				$out['fields']  = $theirs;
				$out['mapping'] = self::map_fields( $ours, $theirs, $chosen );
			}
		}
		return new WP_REST_Response( $out, 200, array( 'Cache-Control' => 'no-store' ) );
	}
}

Clara_VE_Form_Handlers::init();
