<?php
/**
 * REST endpoints for the visual editor host.
 *
 * Most routes accept an optional `key` param identifying which page is being
 * edited (CLARA_VE_DEFAULT_KEY for the front page — the default, so every
 * pre-multi-page client call keeps working unchanged — or a tagged WP
 * Page's own key). `/menu-item` stays front-page-only by design: tagged
 * pages get the theme's own real, independent nav menu via the header
 * template part, unrelated to this mechanism.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$key_arg = array(
			'type'     => 'string',
			'required' => false,
			'default'  => CLARA_VE_DEFAULT_KEY,
		);

		register_rest_route(
			'clara-ve/v1',
			'/source',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_source' ),
					'permission_callback' => 'clara_ve_user_can_edit',
					'args'                => array( 'key' => $key_arg ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_source' ),
					'permission_callback' => 'clara_ve_user_can_edit',
					'args'                => array(
						'key'    => $key_arg,
						'source' => array(
							'type'     => 'string',
							'required' => true,
						),
						'pseudo' => array(
							'type'     => 'array',
							'required' => false,
						),
					),
				),
			)
		);

		// Per-page SEO. Same capability gate as /source: whoever may rewrite the
		// page's markup may certainly rewrite its meta description.
		register_rest_route(
			'clara-ve/v1',
			'/seo',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_seo' ),
					'permission_callback' => 'clara_ve_user_can_edit',
					'args'                => array( 'key' => $key_arg ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'save_seo' ),
					'permission_callback' => 'clara_ve_user_can_edit',
					'args'                => array(
						'key'         => $key_arg,
						'title'       => array( 'type' => 'string' ),
						'description' => array( 'type' => 'string' ),
						'ogImage'     => array( 'type' => 'string' ),
						'noindex'     => array( 'type' => 'boolean' ),
					),
				),
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'reset_source' ),
				'permission_callback' => 'clara_ve_user_can_edit',
			)
		);

		// The only PUBLIC route in this file, and deliberately so: it serves the
		// next page of a listing to any visitor who presses "Load more", the
		// same published posts the page below the fold would have shown anyway.
		// Nothing about the response is caller-controlled except which stored
		// page and which page number — the card markup comes from the site's
		// own source (see Clara_VE_Tokens::render_posts_page).
		register_rest_route(
			'clara-ve/v1',
			'/posts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_posts_page' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'key'  => $key_arg,
					'page' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		// Editor-only: the mailing lists the connected provider offers, for the
		// FORM panel's picker. Behind the edit capability, not public — a list
		// name is not visitor-facing information.
		register_rest_route(
			'clara-ve/v1',
			'/lists',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_lists' ),
				'permission_callback' => 'clara_ve_user_can_edit',
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/pages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'list_visual_pages' ),
				'permission_callback' => 'clara_ve_user_can_edit',
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/history',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'get_history' ),
				'permission_callback' => 'clara_ve_user_can_edit',
				'args'                => array( 'key' => $key_arg ),
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/history/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( __CLASS__, 'rename_history' ),
				'permission_callback' => 'clara_ve_user_can_edit',
				'args'                => array(
					'key'     => $key_arg,
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/history/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'restore_history' ),
				'permission_callback' => 'clara_ve_user_can_edit',
				'args'                => array( 'key' => $key_arg ),
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/menu-item',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'update_menu_item' ),
				'permission_callback' => 'clara_ve_user_can_edit',
				'args'                => array(
					'originalTitle' => array( 'type' => 'string', 'required' => true ),
					'originalUrl'   => array( 'type' => 'string', 'required' => true ),
					'title'         => array( 'type' => 'string', 'required' => true ),
					'url'           => array( 'type' => 'string', 'required' => true ),
					'blank'         => array( 'type' => 'boolean', 'required' => false ),
					'location'      => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			'clara-ve/v1',
			'/import-image',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'import_image' ),
				'permission_callback' => 'clara_ve_user_can_edit',
				'args'                => array(
					'src' => array( 'type' => 'string', 'required' => true ),
					'alt' => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	/**
	 * Copy an image the design points at on SOMEONE ELSE'S server into this
	 * site's Media Library, and hand back the local URL.
	 *
	 * A converted design routinely references a CDN or a stock-photo host, so
	 * the site depends on someone else's server staying up and serving the
	 * picture. One click copies it into this site's own Media Library and
	 * repoints the markup.
	 *
	 * This endpoint fetches a caller-supplied remote URL, which is a way to
	 * read arbitrary files and to probe the network from inside the server, so
	 * the difference has to be paid for:
	 *
	 *  - it is capability-gated to someone who may already edit raw theme HTML;
	 *  - wp_http_validate_url() rejects a private, loopback or non-HTTP target,
	 *    which is the SSRF guard core itself uses;
	 *  - download_url() goes through the safe HTTP API, honouring that
	 *    validation on redirects too;
	 *  - the file must actually BE one of the four image types, judged by
	 *    wp_check_filetype_and_ext() on the downloaded bytes rather than by the
	 *    URL's extension or the server's content-type, both of which the remote
	 *    host controls;
	 *  - and the size ceiling is 16 MB.
	 *
	 * The remote copy is left alone; the markup is repointed by the editor's
	 * ordinary set-image patch, so the change is one the owner can undo.
	 */
	public static function import_image( WP_REST_Request $request ) {
		$src = trim( (string) $request->get_param( 'src' ) );
		if ( '' === $src || ! preg_match( '#^https?://#i', $src ) ) {
			return new WP_Error( 'clara_ve_import_scheme', __( 'Only an http(s) image address can be imported.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		if ( ! wp_http_validate_url( $src ) ) {
			return new WP_Error( 'clara_ve_import_url', __( 'That address cannot be fetched from this server.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		// wp_http_validate_url() is not enough on its own: it let both
		// "localhost" and 169.254.169.254 through in testing, and they only
		// failed because nothing answered. On a cloud host that address is the
		// instance metadata service and it answers — credentials and all. So
		// resolve the host and refuse any private, reserved, loopback or
		// link-local target outright. Nothing is returned to the caller either
		// way (a non-image is rejected below and its bytes deleted), so the
		// residual exposure is whether a fetch succeeded; this closes the part
		// that matters. A host that resolves differently between this check and
		// the fetch (DNS rebinding) is not addressed here and would need the
		// HTTP layer to pin the address.
		$host = (string) wp_parse_url( $src, PHP_URL_HOST );
		$ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return new WP_Error(
				'clara_ve_import_private',
				__( 'That address points inside this server\'s own network, so it will not be fetched.', 'visual-edit-lite' ),
				array( 'status' => 400 )
			);
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = download_url( $src, 30 );
		if ( is_wp_error( $tmp ) ) {
			return new WP_Error( 'clara_ve_import_fetch', $tmp->get_error_message(), array( 'status' => 400 ) );
		}

		$size = (int) @filesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size > Clara_VE_Media::MAX_SOURCE_BYTES ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'clara_ve_import_big', __( 'That image is too large to import (max 16 MB).', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		// What the FILE is, not what the URL or the remote server claims.
		$base  = basename( wp_parse_url( $src, PHP_URL_PATH ) ?: 'image' );
		$check = wp_check_filetype_and_ext( $tmp, $base );
		$ext   = $check['ext'] ? strtolower( $check['ext'] ) : '';
		if ( ! in_array( $ext, Clara_VE_Media::ALLOWED_IMAGE_EXT, true ) ) {
			wp_delete_file( $tmp );
			return new WP_Error( 'clara_ve_import_type', __( 'Only PNG, JPG, WebP or GIF images can be imported.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $tmp );
		if ( false === $bytes || '' === $bytes ) {
			return new WP_Error( 'clara_ve_import_read', __( 'The downloaded image could not be read.', 'visual-edit-lite' ), array( 'status' => 500 ) );
		}

		$alt        = sanitize_text_field( (string) $request->get_param( 'alt' ) );
		$attachment = Clara_VE_Media::sideload(
			$bytes,
			$ext,
			$check['type'] ? $check['type'] : 'image/' . ( 'jpg' === $ext ? 'jpeg' : $ext ),
			'imported',
			'' !== $alt ? $alt : __( 'Imported image', 'visual-edit-lite' )
		);
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( '' !== $alt && ! empty( $attachment['id'] ) ) {
			update_post_meta( (int) $attachment['id'], '_wp_attachment_image_alt', $alt );
		}

		return rest_ensure_response(
			array(
				'url' => $attachment['url'],
				'id'  => isset( $attachment['id'] ) ? (int) $attachment['id'] : 0,
			)
		);
	}

	/**
	 * Update the WP menu item matching the clicked nav link (by its current
	 * title + URL), so menu edits work straight from the visual editor.
	 */
	public static function update_menu_item( WP_REST_Request $request ) {
		$locations = get_nav_menu_locations();

		// The menus to search: the zone the bridge says was clicked first,
		// then every other declared zone's location — a site can have several
		// menus, and the item must be found in the one that actually renders
		// where the click happened. The legacy single location stays as a
		// last resort for pre-contract installs.
		$candidates = array();
		$requested  = sanitize_key( (string) $request->get_param( 'location' ) );
		if ( $requested ) {
			$candidates[] = $requested;
		}
		foreach ( Clara_VE_Front_Nav::zones() as $zone ) {
			$candidates[] = $zone['location'];
		}
		$candidates[] = CLARA_VE_NAV_LOCATION;
		$candidates   = array_values( array_unique( $candidates ) );

		$menu_ids = array();
		foreach ( $candidates as $location ) {
			if ( ! empty( $locations[ $location ] ) ) {
				$menu_ids[ (int) $locations[ $location ] ] = true;
			}
		}
		if ( ! $menu_ids ) {
			return new WP_Error( 'clara_ve_no_menu', __( 'No menu is assigned to any declared menu location.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}

		$normalize = function ( $url ) {
			return untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ?: $url );
		};

		$original_title = trim( (string) $request->get_param( 'originalTitle' ) );
		$original_url   = $normalize( (string) $request->get_param( 'originalUrl' ) );
		$match          = null;
		$menu_id        = 0;

		foreach ( array_keys( $menu_ids ) as $candidate_menu ) {
			$items = wp_get_nav_menu_items( $candidate_menu );
			foreach ( (array) $items as $item ) {
				if ( trim( $item->title ) === $original_title && $normalize( $item->url ) === $original_url ) {
					$match   = $item;
					$menu_id = $candidate_menu;
					break 2;
				}
			}
		}

		if ( ! $match ) {
			return new WP_Error( 'clara_ve_item_not_found', __( 'Could not find a matching menu item.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}

		// PRESERVE EVERYTHING NOT BEING CHANGED.
		//
		// wp_update_nav_menu_item() does not merge with the item's existing
		// row: it runs the supplied array through wp_parse_args() over core's
		// defaults and WRITES the result. Every field left out is therefore
		// actively overwritten with a default — `menu-item-position` => 0,
		// `menu-item-type` => 'custom', `menu-item-object-id` => 0,
		// description/classes/xfn/attr-title => ''.
		//
		// Position 0 is not "leave it alone": core reads it as "append", and
		// recomputes the item to the END of the menu. So renaming a nav item
		// in the editor silently moved it to last AND cut its page binding,
		// turning a permalink-following page item into a frozen custom link.
		// Reproduced on a clean install: renaming "Product" took it from
		// position 1 to 5 and from post_type/page/44 to custom/custom.
		//
		// The binding is kept unless the URL actually changed to a different
		// target — retargeting a link to something else is the one case where
		// becoming a custom item is right.
		$retargeted = $normalize( (string) $request->get_param( 'url' ) ) !== $normalize( (string) $match->url );
		$args       = array(
			'menu-item-title'       => sanitize_text_field( (string) $request->get_param( 'title' ) ),
			'menu-item-url'         => esc_url_raw( (string) $request->get_param( 'url' ) ),
			'menu-item-target'      => $request->get_param( 'blank' ) ? '_blank' : '',
			'menu-item-status'      => 'publish',
			'menu-item-parent-id'   => (int) $match->menu_item_parent,
			// max(1,…) because a legacy row may still carry 0 from an import
			// made before positions were written explicitly, and passing 0 back
			// is exactly what moves the item to the end.
			'menu-item-position'    => max( 1, (int) $match->menu_order ),
			'menu-item-description' => (string) $match->description,
			'menu-item-attr-title'  => (string) $match->attr_title,
			'menu-item-classes'     => implode( ' ', (array) $match->classes ),
			'menu-item-xfn'         => (string) $match->xfn,
		);
		if ( ! $retargeted && 'custom' !== $match->type ) {
			$args['menu-item-type']      = (string) $match->type;
			$args['menu-item-object']    = (string) $match->object;
			$args['menu-item-object-id'] = (int) $match->object_id;
			// A page-bound item derives its own URL; passing one turns it
			// back into a stored literal that stops following the permalink.
			unset( $args['menu-item-url'] );
		}

		$result = wp_update_nav_menu_item( $menu_id, $match->db_id, $args );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * The front page plus every WP Page tagged with CLARA_VE_PAGE_KEY_META,
	 * for the Visual Editor's page picker.
	 */
	public static function list_visual_pages() {
		// The enumeration itself lives in Clara_VE_Source_Store::list_keys();
		// everything this method adds on top is preview-URL resolution, which
		// is the picker's own concern and nothing else's.
		$keys = Clara_VE_Source_Store::list_keys();

		// The shared header/footer have no page of their own to preview in —
		// borrow the first tagged page's URL as the canvas they render on
		// (the front page can't be used: it's self-contained and never
		// shows this shared chrome at all).
		$chrome_preview_url = home_url( '/' );
		foreach ( $keys as $entry ) {
			if ( 'page' === $entry['kind'] && $entry['post_id'] ) {
				$chrome_preview_url = get_permalink( $entry['post_id'] );
				break;
			}
		}

		// The article template previews on a real post, because the whole
		// point is seeing the layout with actual content in it. Which post is
		// irrelevant — every one of them renders through this same template,
		// so the newest is as good as any. With no published post there is
		// nothing to preview and nothing to style, so the entry is left out
		// entirely rather than offered as a dead end.
		$latest_post = get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'suppress_filters' => false,
			)
		);

		$out = array();
		foreach ( $keys as $entry ) {
			if ( CLARA_VE_ARTICLE_KEY === $entry['key'] ) {
				if ( ! $latest_post ) {
					continue;
				}
				$url = get_permalink( $latest_post[0] );
			} elseif ( CLARA_VE_404_KEY === $entry['key'] ) {
				// The 404 is the one key with no page of its own to preview
				// on, by definition — so preview it on an address that is
				// guaranteed not to resolve, which is exactly the state it
				// exists to render.
				$url = home_url( '/clara-ve-404-preview-' . wp_generate_password( 6, false ) . '/' );
			} elseif ( 'front' === $entry['kind'] ) {
				$url = home_url( '/' );
			} elseif ( 'part' === $entry['kind'] ) {
				// A chrome VARIANT part must preview on a page that actually
				// renders it — the borrowed first tagged page shows the
				// MAJORITY chrome, which for a variant is the wrong part
				// entirely. The theme contract names such a page.
				$url           = $chrome_preview_url;
				$contract_part = clara_ve_contract_part( $entry['key'] );
				if ( $contract_part && $contract_part['preview_key'] ) {
					$preview_page = Clara_VE_Source_Store::find_page_by_key( $contract_part['preview_key'] );
					if ( $preview_page ) {
						$url = get_permalink( $preview_page );
					}
				}
			} else {
				$url = get_permalink( $entry['post_id'] );
			}

			$out[] = array(
				'key'   => $entry['key'],
				'label' => $entry['title'],
				'url'   => $url,
			);
		}

		return rest_ensure_response( $out );
	}

	public static function get_source( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		return rest_ensure_response(
			array(
				'source'      => Clara_VE_Source_Store::get_current_source( $key ),
				'hasEdits'    => null !== Clara_VE_Source_Store::get_resolved_source( $key ),
				'menuManaged' => Clara_VE_Front_Nav::is_menu_assigned(),
			)
		);
	}

	/**
	 * A page's SEO, as it is actually live.
	 *
	 * Returns "not editable" rather than an error for the chrome keys: the
	 * header, footer and article template are fragments of every page, not pages,
	 * so they have no title or description of their own. The panel explains that
	 * instead of showing empty fields that silently discard what is typed in
	 * them.
	 *
	 * @param WP_REST_Request $request key.
	 * @return WP_REST_Response
	 */
	public static function get_seo( WP_REST_Request $request ) {
		$key     = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$post_id = Clara_VE_SEO::post_id_for_key( $key );

		if ( ! $post_id ) {
			return rest_ensure_response(
				array(
					'key'      => $key,
					'editable' => false,
					'reason'   => __( 'This is shared across every page, so it has no title or description of its own.', 'visual-edit-lite' ),
				)
			);
		}

		$live = Clara_VE_SEO::effective( $post_id );
		$host = Clara_VE_SEO::host();

		return rest_ensure_response(
			array(
				'key'         => $key,
				'editable'    => true,
				'postId'      => $post_id,
				'title'       => $live['title'],
				'description' => $live['description'],
				'ogImage'     => $live['og_image'] ? Clara_VE_Bundle_Format::from_portable( $live['og_image'] ) : '',
				'noindex'     => $live['noindex'],
				// What WordPress would put in <title> with no override, so the
				// preview can show the real thing rather than an empty line.
				'fallbackTitle' => wp_strip_all_tags( get_the_title( $post_id ) . ' – ' . get_bloginfo( 'name' ) ),
				'permalink'   => (string) get_permalink( $post_id ),
				'host'        => $host,
				'hostLabel'   => Clara_VE_SEO::host_label(),
				// Where the host plugin's own richer UI lives. The block editor is
				// exactly where its sidebar is, and ?clara_ve_bypass=1 is the
				// existing escape hatch out of this editor's takeover.
				'hostEditUrl' => $host ? add_query_arg( 'clara_ve_bypass', '1', (string) get_edit_post_link( $post_id, 'raw' ) ) : '',
			)
		);
	}

	/**
	 * Write a page's SEO. Goes into our own record, and
	 * Clara_VE_SEO::save() mirrors it into the host plugin's fields.
	 *
	 * only_if_empty is FALSE here, unlike an import: this is a person typing
	 * into a field on purpose, and their edit must win over whatever is there.
	 *
	 * @param WP_REST_Request $request key, title, description, ogImage, noindex.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_seo( WP_REST_Request $request ) {
		$key     = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$post_id = Clara_VE_SEO::post_id_for_key( $key );
		if ( ! $post_id ) {
			return new WP_Error( 'clara_ve_seo_no_page', 'This key has no page to hold SEO.', array( 'status' => 400 ) );
		}

		// Merged onto the stored record rather than replacing it: the panel edits
		// three fields, and a bare overwrite would drop the canonical, the
		// twitter tags and the schema.org blocks the conversion carried in.
		$record = Clara_VE_SEO::get( $post_id );
		$record['title']       = (string) $request->get_param( 'title' );
		$record['description'] = (string) $request->get_param( 'description' );
		$record['noindex']     = (bool) $request->get_param( 'noindex' );

		$image = trim( (string) $request->get_param( 'ogImage' ) );
		if ( '' === $image ) {
			unset( $record['og']['image'] );
		} else {
			$record['og']['image'] = Clara_VE_Bundle_Format::to_portable( $image );
		}

		$saved = Clara_VE_SEO::save( $post_id, $record );
		$live  = Clara_VE_SEO::effective( $post_id );

		return rest_ensure_response(
			array(
				'saved'       => true,
				'title'       => $live['title'],
				'description' => $live['description'],
				'ogImage'     => isset( $saved['og']['image'] ) ? Clara_VE_Bundle_Format::from_portable( $saved['og']['image'] ) : '',
				'noindex'     => $live['noindex'],
			)
		);
	}

	/**
	 * Page 2, 3, … of a listing, for the "Load more" button.
	 *
	 * @param WP_REST_Request $request key, page.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_posts_page( WP_REST_Request $request ) {
		$key  = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$page = (int) $request->get_param( 'page' );

		// Page 1 is what the page itself already rendered; asking for it here
		// would duplicate every card on screen. The upper bound is a plain
		// runaway guard — a listing nobody could scroll to in a lifetime.
		if ( $page < 2 || $page > 500 ) {
			return new WP_Error( 'clara_ve_bad_page', 'Page must be between 2 and 500.', array( 'status' => 400 ) );
		}

		$result = Clara_VE_Tokens::render_posts_page( $key, $page );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( $result );
	}

	/**
	 * @return WP_REST_Response { ready, provider, lists } — never a WP_Error for
	 *         "no provider", which is a normal state the panel explains rather
	 *         than an error it should show as a failure.
	 */
	public static function get_lists() {
		if ( ! Clara_VE_Lists::ready() ) {
			return rest_ensure_response( array( 'ready' => false, 'provider' => Clara_VE_Lists::provider(), 'lists' => array() ) );
		}
		$lists = Clara_VE_Lists::lists();
		if ( is_wp_error( $lists ) ) {
			return rest_ensure_response( array( 'ready' => true, 'provider' => Clara_VE_Lists::provider(), 'lists' => array(), 'error' => $lists->get_error_message() ) );
		}
		return rest_ensure_response( array( 'ready' => true, 'provider' => Clara_VE_Lists::provider(), 'lists' => $lists ) );
	}

	public static function save_source( WP_REST_Request $request ) {
		$key    = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$source = (string) $request->get_param( 'source' );

		$shape_check = Clara_VE_Source_Store::validate_shape( $key, $source );
		if ( true !== $shape_check ) {
			return new WP_Error( 'clara_ve_shape', $shape_check, array( 'status' => 400 ) );
		}

		// If history is still empty, capture the PRE-edit live state as an
		// "Original" baseline before it gets overwritten below — otherwise a
		// user whose first action is edit-then-Save would have nothing to
		// ever restore back to.
		Clara_VE_History::ensure_baseline( $key );

		$saved = Clara_VE_Source_Store::save_source( $key, $source );

		// ::before ornament styles live in a separate CSS layer keyed by the
		// element's structural path — the markup itself stays untouched.
		$map    = Clara_VE_Pseudo_Store::get( $key );
		$pseudo = $request->get_param( 'pseudo' );
		if ( is_array( $pseudo ) ) {
			foreach ( $pseudo as $entry ) {
				if ( empty( $entry['id'] ) || empty( $entry['styles'] ) || ! is_array( $entry['styles'] ) ) {
					continue;
				}
				$id = (string) $entry['id'];
				if ( ! preg_match( '/^path(-\d+)+$/', $id ) ) {
					continue;
				}
				$which = ( isset( $entry['pseudo'] ) && 'after' === $entry['pseudo'] ) ? 'after' : 'before';
				$clean = array();
				foreach ( $entry['styles'] as $style_key => $value ) {
					if ( preg_match( '/^[a-zA-Z]+$/', (string) $style_key ) && is_string( $value ) && ! preg_match( '/[{};]/', $value ) ) {
						$clean[ $style_key ] = trim( $value );
					}
				}
				if ( $clean ) {
					if ( empty( $map[ $id ] ) || ! is_array( $map[ $id ] ) ) {
						$map[ $id ] = array();
					}
					$existing              = ( isset( $map[ $id ][ $which ] ) && is_array( $map[ $id ][ $which ] ) ) ? $map[ $id ][ $which ] : array();
					$map[ $id ][ $which ] = array_merge( $existing, $clean );
				}
			}
			Clara_VE_Pseudo_Store::save( $key, $map );
		}

		// A history entry is a full point-in-time snapshot (source + the
		// complete pseudo map), not just what this particular request
		// changed — mirrors a git commit capturing the whole tree.
		if ( $saved ) {
			Clara_VE_History::record( Clara_VE_Source_Store::tokenize( $source ), $map, 'save', null, null, $key );
		}

		return rest_ensure_response( array( 'saved' => (bool) $saved ) );
	}

	public static function reset_source() {
		// Front-page-only: "reset" means falling back to the theme pattern
		// file, which only exists for the front page. No product decision
		// yet for what "reset" should mean for a tagged page (its own
		// History baseline is the natural equivalent) — left untouched
		// rather than generalized on a guess.
		Clara_VE_Source_Store::reset( CLARA_VE_DEFAULT_KEY );
		return rest_ensure_response( array( 'reset' => true ) );
	}

	public static function get_history( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		// Seed the "Original" baseline on first view too, not just on first
		// Save — so simply opening the History panel already shows something
		// restorable, even before the user has saved anything themselves.
		Clara_VE_History::ensure_baseline( $key );
		return rest_ensure_response( Clara_VE_History::visible_entries( $key ) );
	}

	public static function rename_history( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$id  = (int) $request->get_param( 'id' );
		$ok  = Clara_VE_History::rename( $id, (string) $request->get_param( 'message' ), $key );
		if ( ! $ok ) {
			return new WP_Error( 'clara_ve_rename_failed', __( 'Could not rename that save.', 'visual-edit-lite' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'renamed' => true ) );
	}

	public static function restore_history( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) ) ?: CLARA_VE_DEFAULT_KEY;
		$id  = (int) $request->get_param( 'id' );
		// Enforced on the wire, not just in the panel: the history panel offers
		// the newest ten saves and the Original, and restore accepts exactly
		// those — an id from further back is refused rather than served.
		if ( ! Clara_VE_History::may_restore( $id, $key ) ) {
			return new WP_Error( 'clara_ve_history_out_of_range', __( 'That save is outside the history this editor keeps. The last ten saves and the Original remain available.', 'visual-edit-lite' ), array( 'status' => 403 ) );
		}
		$entry = Clara_VE_History::get( $id, $key );
		if ( ! $entry ) {
			return new WP_Error( 'clara_ve_not_found', __( 'That save no longer exists.', 'visual-edit-lite' ), array( 'status' => 404 ) );
		}

		// Write it back as the live source/pseudo — same values a normal save
		// would produce — but do NOT log a history entry here. A commit is
		// only ever created by an explicit Save; restore just checks the old
		// version out as the live/editing state. list_entries() figures out
		// which row is HEAD by comparing content hashes, not row order, so
		// the dot correctly lands back on this entry even though nothing new
		// was appended.
		Clara_VE_Source_Store::save_source( $key, Clara_VE_Source_Store::untokenize( $entry['source'] ) );
		Clara_VE_Pseudo_Store::save( $key, is_array( $entry['pseudo'] ) ? $entry['pseudo'] : array() );

		return rest_ensure_response(
			array(
				'source'  => Clara_VE_Source_Store::untokenize( $entry['source'] ),
				'pseudo'  => $entry['pseudo'],
				'history' => Clara_VE_History::visible_entries( $key ),
			)
		);
	}
}

Clara_VE_REST::init();
