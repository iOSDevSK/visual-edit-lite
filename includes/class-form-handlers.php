<?php
/**
 * A designed form whose submissions another form plugin processes.
 *
 * A [wp-form] keeps the source design; the owner picks in the Visual Edit
 * popup what a submission does. Besides this plugin's own contact form and
 * mailing list, it can be handed to a form plugin that is already running on
 * the site — Contact Form 7 — so that plugin's validation, spam checks, mail
 * and storage (Flamingo) run on it, exactly as if its own form had been sent.
 * The design stays ours; the plugin form is the processing behind it.
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
 * the THEME renders the token and does not sign it; prepare_block() signs a
 * connected token before the theme sees it, so the theme's forwarded request
 * verifies here. A plugin or plugin form that is gone makes the form behave as
 * not connected, with the owner told which — never a 404, never a fatal.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Form_Handlers {

	/** Token `type` values that hand a submission to another plugin, and the plugin's name. */
	const KINDS = array( 'cf7' => 'Contact Form 7' );

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
		return false;
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
			foreach ( (array) $form->scan_form_tags() as $tag ) {
				$type = (string) ( isset( $tag->basetype ) ? $tag->basetype : '' );
				if ( '' === (string) $tag->name || in_array( $type, array( 'submit', 'hidden', 'quiz', 'recaptcha', 'response' ), true ) ) {
					continue;
				}
				$fields[] = array(
					'name'     => (string) $tag->name,
					'label'    => (string) $tag->name,
					'type'     => 'acceptance' === $type ? 'checkbox' : $type,
					'required' => method_exists( $tag, 'is_required' ) && $tag->is_required(),
				);
			}
		}
		return $fields;
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
	 * @param array  $extra   Request values the plugin reads besides fields (a captcha token).
	 * @return array{status:string,errors:array<string,string>,message:string}
	 *   status 'sent'|'invalid'|'spam'|'error'; errors keyed by PLUGIN field name.
	 */
	public static function forward( $kind, $form_id, $values, $extra = array() ) {
		if ( 'cf7' === $kind && self::available( 'cf7' ) ) {
			return self::forward_cf7( $form_id, $values, $extra );
		}
		return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
	}

	/**
	 * Contact Form 7 reads a submission from $_POST, the way its own REST
	 * feedback endpoint receives one: the fields under their tag names plus
	 * the form's id and a unit tag. submit() then runs CF7's validation, its
	 * spam checks (the request is the visitor's own, so are its user agent and
	 * IP; Akismet and the disallowed list apply; a reCAPTCHA v3 token the page
	 * obtained arrives as _wpcf7_recaptcha_response), mail, and Flamingo when
	 * active. $_POST is restored afterwards.
	 *
	 * CF7 checks its own nonce only for a logged-in submitter. This route is
	 * reached anonymously — core drops a cookie without X-WP-Nonce to user 0 —
	 * so a script that ever starts sending X-WP-Nonce here makes CF7 refuse a
	 * logged-in owner's test. A CF7 form set to subscribers_only refuses every
	 * submission this way, for the same reason.
	 *
	 * @param string $form_id
	 * @param array  $values
	 * @param array  $extra
	 * @return array{status:string,errors:array<string,string>,message:string}
	 */
	private static function forward_cf7( $form_id, $values, $extra ) {
		$form = WPCF7_ContactForm::get_instance( (int) $form_id );
		if ( ! $form ) {
			return array( 'status' => 'error', 'errors' => array(), 'message' => '' );
		}
		$saved = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- restored below; the plugin reads the submission from here.
		$_POST = array_merge(
			(array) $extra,
			(array) $values,
			array(
				'_wpcf7'                => (string) $form->id(),
				'_wpcf7_version'        => defined( 'WPCF7_VERSION' ) ? WPCF7_VERSION : '',
				'_wpcf7_locale'         => method_exists( $form, 'locale' ) ? (string) $form->locale() : '',
				'_wpcf7_unit_tag'       => 'wpcf7-f' . $form->id() . '-o1',
				'_wpcf7_container_post' => '0',
			)
		);
		try {
			$result = (array) $form->submit();
		} catch ( Throwable $e ) {
			$result = array( 'status' => 'error', 'message' => '' );
		}
		$_POST = $saved;

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
	 * A submission whose signed delivery names a handler, after
	 * Clara_VE_Forms::handle_submit() has run its own checks (honeypot,
	 * origin, time-trap, rate limit). Nothing is stored under Form
	 * Submissions: the plugin keeps its own record.
	 *
	 * @param string $kind
	 * @param string $list     The signed `list` value (see parse()).
	 * @param array  $params   The request, as received.
	 * @param string $rate_key The rate-limit entry this request set, or ''.
	 * @return array{status:string,errors:array<string,string>,message:string}
	 *   errors keyed by OUR field name.
	 */
	public static function submit( $kind, $list, $params, $rate_key ) {
		$config = self::parse( $list );
		if ( '' !== self::missing( $kind, $config['form'] ) ) {
			return array( 'status' => 'closed', 'errors' => array(), 'message' => __( 'This form isn’t accepting messages right now. Please reach out another way.', 'visual-edit-lite' ) );
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
		$extra = array();
		if ( isset( $params['_wpcf7_recaptcha_response'] ) && is_string( $params['_wpcf7_recaptcha_response'] ) ) {
			$extra['_wpcf7_recaptcha_response'] = sanitize_text_field( $params['_wpcf7_recaptcha_response'] );
		}

		$verdict = self::forward( $kind, $config['form'], $values, $extra );

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
		// and that is not the burst the rate limit is there for.
		if ( 'invalid' === $verdict['status'] && '' !== $rate_key ) {
			delete_transient( $rate_key );
		}
		return array( 'status' => $verdict['status'], 'errors' => $errors, 'message' => $message );
	}

	/**
	 * render_block_core/html, before the token is hydrated: a form connected
	 * to a handler gets what its renderer does not give it.
	 *
	 * - Connected and reachable: the script that sends it (field errors,
	 *   captcha token); on a theme that renders the token itself, also the
	 *   delivery signature the theme does not emit.
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
		$missing = self::missing( $kind, self::parse( $list )['form'] );
		$preview = function_exists( 'clara_ve_is_edit_preview' ) && clara_ve_is_edit_preview();

		if ( '' !== $missing ) {
			$note = current_user_can( 'edit_pages' )
				? '<p class="cve-form-message" data-cve-error="1" role="status">' . esc_html( self::missing_text( $kind, $missing ) ) . '</p>'
				: '';
			return $preview ? str_replace( '[/wp-form]', $note . '[/wp-form]', $m[0] ) : $m[2] . $note;
		}

		if ( ! $preview ) {
			self::enqueue();
		}
		if ( ! function_exists( 'clara_ve_theme_owns_public_runtime' ) || ! clara_ve_theme_owns_public_runtime() ) {
			// Clara_VE_Tokens::render_form() signs it, as it signs every token.
			return $m[0];
		}
		// Signed over exactly what Clara_VE_Tokens::render_form() would sign
		// and what the theme puts in its hidden fields, normalised the way
		// handle_submit() reads them back.
		$form_id   = sanitize_key( isset( $atts['id'] ) ? $atts['id'] : 'form' );
		$to        = trim( (string) ( isset( $atts['to'] ) ? $atts['to'] : '' ) );
		$signature = Clara_VE_Forms::delivery_field( $form_id, $to, $kind, $list );
		$inner     = preg_replace( '/(<form\b[^>]*>)/i', '$1' . $signature, $m[2], 1 );
		return '[wp-form' . $m[1] . ']' . ( null === $inner ? $m[2] : $inner ) . '[/wp-form]';
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
						$chosen[ sanitize_key( $mine ) ] = $theirs;
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
