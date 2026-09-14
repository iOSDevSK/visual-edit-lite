<?php
/** VE save history for canonical Gutenberg entities; restores use native Save. */
defined( 'ABSPATH' ) || exit;

class Clara_VE_Native_History {
	private static $pending = array();
	// A theme can live in a subdirectory; never interpret an ID as a URL.
	const TEMPLATE_ID = '[a-zA-Z0-9_.@ -]+(?:/[a-zA-Z0-9_.@ -]+)?//[a-zA-Z0-9_%/-]+';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		// This filter runs after native permission checks, just before writing.
		add_filter( 'rest_dispatch_request', array( __CLASS__, 'before_save' ), 10, 4 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'after_save' ), 10, 3 );
	}

	public static function routes() {
		$args = array(
			'type' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_key' ),
			'entity' => array( 'type' => 'string', 'required' => true, 'maxLength' => 512 ),
		);
		register_rest_route( 'clara-ve/v1', '/native/history', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( __CLASS__, 'listing' ),
			'permission_callback' => array( __CLASS__, 'permission' ), 'args' => $args,
		) );
		register_rest_route( 'clara-ve/v1', '/native/history/(?P<id>\d+)', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'snapshot' ), 'permission_callback' => array( __CLASS__, 'permission' ), 'args' => $args ),
			array( 'methods' => WP_REST_Server::EDITABLE, 'callback' => array( __CLASS__, 'rename' ), 'permission_callback' => array( __CLASS__, 'permission' ), 'args' => array_merge( $args, array( 'message' => array( 'type' => 'string', 'required' => true, 'maxLength' => 255 ) ) ) ),
		) );
	}

	/** Resolve through the same REST controller/capabilities as native Save. */
	public static function target( $type, $id ) {
		$object = get_post_type_object( $type );
		if ( ! $object || ! $object->show_in_rest || ( 'wp_global_styles' !== $type && ! post_type_supports( $type, 'editor' ) ) ) {
			return new WP_Error( 'clara_ve_history_type', __( 'History is not available for this document type.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		$template = in_array( $type, array( 'wp_template', 'wp_template_part' ), true );
		if ( ! preg_match( $template ? '~^' . self::TEMPLATE_ID . '$~D' : '/^[1-9][0-9]*$/D', (string) $id ) || preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', (string) $id ) ) {
			return new WP_Error( 'clara_ve_history_entity', __( 'Invalid history document.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		$controller = $object->get_rest_controller();
		if ( ! $controller || ! method_exists( $controller, 'update_item_permissions_check' ) ) {
			return new WP_Error( 'clara_ve_history_controller', __( 'This editor does not support history access.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		$path = '/' . ( $object->rest_namespace ?: 'wp/v2' ) . '/' . ( $object->rest_base ?: $type ) . '/' . $id;
		$probe = new WP_REST_Request( 'POST', $path );
		$probe->set_param( 'id', $template ? (string) $id : (int) $id );
		$allowed = $controller->update_item_permissions_check( $probe );
		if ( is_wp_error( $allowed ) ) { return $allowed; }
		if ( ! $allowed ) { return new WP_Error( 'clara_ve_history_forbidden', __( 'You cannot edit this document.', 'visual-edit-lite' ), array( 'status' => 403 ) ); }
		return array( 'type' => $type, 'id' => $template ? (string) $id : (int) $id, 'path' => $path,
			'key' => $template || 'wp_global_styles' === $type ? 'native__' . $type . '-' . substr( hash( 'sha256', (string) $id ), 0, 32 ) : Clara_VE_Source_Store::BLOCK_KEY_PREFIX . $id );
	}

	public static function permission( WP_REST_Request $request ) {
		$target = self::target( $request['type'], $request['entity'] );
		return is_wp_error( $target ) ? $target : true;
	}

	/** Only version content and VE responsive metadata, never status/SEO/ownership. */
	public static function live( $target ) {
		$request = new WP_REST_Request( 'GET', $target['path'] );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		if ( $response->is_error() ) { return $response->as_error(); }
		$data = $response->get_data();
		if ( 'wp_global_styles' === $target['type'] ) {
			$source = wp_json_encode( array( 'styles' => empty( $data['styles'] ) ? new stdClass() : $data['styles'], 'settings' => empty( $data['settings'] ) ? new stdClass() : $data['settings'] ) );
		} else {
			if ( ! isset( $data['content']['raw'] ) || ! is_string( $data['content']['raw'] ) ) {
				return new WP_Error( 'clara_ve_history_content', __( 'The editor did not return editable content.', 'visual-edit-lite' ), array( 'status' => 400 ) );
			}
			// Keep existing page/post history readable, including its URI tokens.
			$source = Clara_VE_Source_Store::tokenize( $data['content']['raw'] );
		}
		$has_responsive = isset( $data['meta'] ) && array_key_exists( Clara_VE_Responsive::META, $data['meta'] );
		$rules = $has_responsive ? json_decode( Clara_VE_Responsive::sanitize_meta( $data['meta'][ Clara_VE_Responsive::META ] ), true ) : array();
		$title = $data['title']['raw'] ?? $data['title']['rendered'] ?? $target['id'];
		return array( 'source' => $source, 'responsive' => $rules, 'hasResponsive' => $has_responsive, 'title' => wp_strip_all_tags( (string) $title ) );
	}

	private static function baseline( $target, $live ) {
		if ( ! Clara_VE_History::head( $target['key'] ) ) {
			Clara_VE_History::record( $live['source'], array(), 'save', 'Original', null, $target['key'], $live['responsive'] );
		}
	}

	public static function listing( WP_REST_Request $request ) {
		$target = self::target( $request['type'], $request['entity'] );
		if ( is_wp_error( $target ) ) { return $target; }
		$live = self::live( $target );
		if ( is_wp_error( $live ) ) { return $live; }
		self::baseline( $target, $live );
		return rest_ensure_response( array( 'entity' => array( 'type' => $target['type'], 'id' => $target['id'], 'title' => $live['title'] ), 'entries' => Clara_VE_History::visible_entries( $target['key'], $live ) ) );
	}

	private static function entry( $target, $id ) {
		if ( ! Clara_VE_History::may_restore( $id, $target['key'], array( 'source' => '', 'responsive' => array() ) ) ) {
			return new WP_Error( 'clara_ve_history_missing', __( 'That version is not available for this document.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}
		$entry = Clara_VE_History::get( $id, $target['key'] );
		if ( ! $entry || ! is_string( $entry['source'] ) ) { return new WP_Error( 'clara_ve_history_missing', __( 'That version could not be read.', 'visual-edit-lite' ), array( 'status' => 404 ) ); }
		return $entry;
	}

	/** Read a snapshot. The browser stages it in core-data; this never publishes. */
	public static function snapshot( WP_REST_Request $request ) {
		$target = self::target( $request['type'], $request['entity'] );
		if ( is_wp_error( $target ) ) { return $target; }
		$entry = self::entry( $target, (int) $request['id'] );
		if ( is_wp_error( $entry ) ) { return $entry; }
		$live = self::live( $target );
		if ( is_wp_error( $live ) ) { return $live; }
		if ( 'wp_global_styles' === $target['type'] ) {
			$decoded = json_decode( $entry['source'] );
			if ( ! is_object( $decoded ) || ! isset( $decoded->styles, $decoded->settings ) || ! is_object( $decoded->styles ) || ! is_object( $decoded->settings ) ) { return new WP_Error( 'clara_ve_history_invalid', __( 'Invalid styles snapshot.', 'visual-edit-lite' ), array( 'status' => 400 ) ); }
			$edits = array( 'styles' => $decoded->styles, 'settings' => $decoded->settings );
		} else {
			$edits = array( 'content' => Clara_VE_Source_Store::untokenize( $entry['source'] ) );
			if ( $live['hasResponsive'] ) { $edits['meta'] = array( Clara_VE_Responsive::META => Clara_VE_Responsive::sanitize_meta( $entry['responsive'] ) ); }
		}
		return rest_ensure_response( array( 'entity' => array( 'type' => $target['type'], 'id' => $target['id'] ), 'id' => (int) $request['id'], 'edits' => $edits ) );
	}

	public static function rename( WP_REST_Request $request ) {
		$target = self::target( $request['type'], $request['entity'] );
		if ( is_wp_error( $target ) ) { return $target; }
		$entry = self::entry( $target, (int) $request['id'] );
		if ( is_wp_error( $entry ) ) { return $entry; }
		if ( ! Clara_VE_History::rename( $request['id'], $request['message'], $target['key'] ) ) { return new WP_Error( 'clara_ve_history_rename', __( 'Could not rename that version.', 'visual-edit-lite' ), array( 'status' => 500 ) ); }
		return rest_ensure_response( array( 'renamed' => true ) );
	}

	/** Match canonical entity endpoints only; autosaves/revisions never match. */
	private static function request_entity( $request ) {
		if ( ! in_array( $request->get_method(), array( 'POST', 'PUT', 'PATCH' ), true ) || ! Clara_VE_Native_Gutenberg::is_native_mode() ) { return null; }
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $type => $object ) {
			if ( 'wp_global_styles' !== $type && ! post_type_supports( $type, 'editor' ) ) { continue; }
			$base = '/' . ( $object->rest_namespace ?: 'wp/v2' ) . '/' . ( $object->rest_base ?: $type );
			$id = in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) ? self::TEMPLATE_ID : '[1-9][0-9]*';
			if ( preg_match( '~^' . preg_quote( $base, '~' ) . '(?:/(' . $id . '))?$~', $request->get_route(), $match ) ) {
				if ( ! $request->has_param( 'content' ) && ! ( in_array( $type, array( 'wp_template', 'wp_template_part' ), true ) && 'theme' === $request['source'] ) && ! ( 'wp_global_styles' === $type && ( $request->has_param( 'styles' ) || $request->has_param( 'settings' ) ) ) && ! ( isset( $request['meta'] ) && array_key_exists( Clara_VE_Responsive::META, (array) $request['meta'] ) ) ) { return null; }
				return array( 'type' => $type, 'id' => $match[1] ?? null );
			}
		}
		return null;
	}

	public static function before_save( $result, $request, $route, $handler ) {
		// Template slugs allow slashes. Check the actual controller too, so an
		// autosave/revision subroute cannot masquerade as a template's slug.
		$callback = $handler['callback'] ?? null;
		if ( ! is_array( $callback ) || ! in_array( $callback[1], array( 'update_item', 'create_item' ), true ) || $callback[0] instanceof WP_REST_Revisions_Controller || $callback[0] instanceof WP_REST_Autosaves_Controller ) { return $result; }
		$entity = self::request_entity( $request );
		if ( null !== $result || ! $entity ) { return $result; }
		$live = null;
		if ( $entity['id'] ) {
			$target = self::target( $entity['type'], $entity['id'] );
			if ( is_wp_error( $target ) ) { return $result; }
			$live = self::live( $target );
			if ( is_wp_error( $live ) ) { return $result; }
		}
		self::$pending[ spl_object_hash( $request ) ] = array( 'entity' => $entity, 'before' => $live );
		return $result;
	}

	public static function after_save( $response, $handler, $request ) {
		$key = spl_object_hash( $request );
		$pending = self::$pending[ $key ] ?? null;
		unset( self::$pending[ $key ] );
		if ( ! $pending || is_wp_error( $response ) ) { return $response; }
		$result = rest_ensure_response( $response );
		if ( $result->get_status() < 200 || $result->get_status() >= 300 ) { return $response; }
		$data = $result->get_data();
		$id = $pending['entity']['id'] ?: ( $data['id'] ?? null );
		$target = self::target( $pending['entity']['type'], $id );
		if ( is_wp_error( $target ) ) { return $response; }
		$live = self::live( $target );
		if ( is_wp_error( $live ) ) { return $response; }
		self::baseline( $target, $pending['before'] ?: $live );
		/* translators: %s: document title. */
		Clara_VE_History::record( $live['source'], array(), 'save', sprintf( __( 'Save %s', 'visual-edit-lite' ), $live['title'] ), null, $target['key'], $live['responsive'] );
		/**
		 * Fires after a successful native editor save has been versioned.
		 *
		 * @param string          $type    Entity type, such as page, wp_template or wp_global_styles.
		 * @param int|string      $id      Entity ID.
		 * @param WP_REST_Request $request The save request.
		 */
		do_action( 'clara_ve_native_entity_saved', $pending['entity']['type'], $id, $request );
		return $response;
	}
}

Clara_VE_Native_History::init();
