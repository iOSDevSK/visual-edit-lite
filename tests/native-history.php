<?php
/** Adapter/storage regression with an in-memory DB and REST controller seam. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'CLARA_VE_DEFAULT_KEY', 'index' );
function __( $text, $domain = '' ) { return $text; }
function add_action( ...$args ) {}
$GLOBALS['clara_ve_actions'] = array();
function do_action( $name, ...$args ) { $GLOBALS['clara_ve_actions'][] = array( $name, $args ); }
function add_filter( ...$args ) {}
function sanitize_key( $text ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $text ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( $text ) ); }
function wp_strip_all_tags( $text ) { return strip_tags( $text ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function wp_list_pluck( $rows, $key ) { return array_map( static function ( $row ) use ( $key ) { return $row->$key; }, $rows ); }
function get_stylesheet() { return $GLOBALS['theme'] ?? 'sailing'; }
function get_option( $key ) { return '3'; }
function get_current_user_id() { return 1; }
function current_time( $format ) { return '2026-09-13 12:00:00'; }
class WP_Error {
	public $code;
	public function __construct( $code, ...$args ) { $this->code = $code; }
}
function is_wp_error( $value ) { return $value instanceof WP_Error; }
class WP_REST_Request extends ArrayObject {
	private $method;
	private $route;
	public function __construct( $method, $route ) { parent::__construct(); $this->method = $method; $this->route = $route; }
	public function get_method() { return $this->method; }
	public function get_route() { return $this->route; }
	public function set_param( $key, $value ) { $this[$key] = $value; }
	public function has_param( $key ) { return isset( $this[$key] ); }
	public function offsetGet( $key ): mixed { return $this->offsetExists( $key ) ? parent::offsetGet( $key ) : null; }
}
class Test_Response {
	private $data;
	private $status;
	public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function is_error() { return $this->status >= 400; }
	public function as_error() { return new WP_Error( 'read_failed' ); }
}
function rest_ensure_response( $data ) { return $data instanceof Test_Response ? $data : new Test_Response( $data ); }
function rest_do_request( $request ) {
	check( 'GET' === $request->get_method(), 'History must never write through native REST' );
	return isset( $GLOBALS['live'][ $request->get_route() ] ) ? new Test_Response( $GLOBALS['live'][ $request->get_route() ] ) : new Test_Response( array(), 404 );
}
class Test_Controller {
	public function update_item_permissions_check( $request ) { return empty( $GLOBALS['forbidden'] ) ? true : new WP_Error( 'forbidden' ); }
}
class WP_REST_Revisions_Controller extends Test_Controller {}
class WP_REST_Autosaves_Controller extends WP_REST_Revisions_Controller {}
class Test_Type {
	public $show_in_rest = true;
	public $rest_namespace = 'wp/v2';
	public $rest_base;
	public function __construct( $base ) { $this->rest_base = $base; }
	public function get_rest_controller() { return new Test_Controller(); }
}
$types = array();
foreach ( array( 'page' => 'pages', 'post' => 'posts', 'wp_template' => 'templates', 'wp_template_part' => 'template-parts', 'wp_navigation' => 'navigation', 'wp_block' => 'blocks', 'wp_global_styles' => 'global-styles' ) as $name => $base ) { $types[$name] = new Test_Type( $base ); }
function get_post_type_object( $type ) { return $GLOBALS['types'][$type] ?? null; }
function get_post_types( ...$args ) { return $GLOBALS['types']; }
function post_type_supports( $type, $feature ) { return 'wp_global_styles' !== $type; }
class Clara_VE_Native_Gutenberg { public static function is_native_mode() { return true; } }
class Clara_VE_Source_Store {
	const BLOCK_KEY_PREFIX = 'block__page-';
	public static function tokenize( $source ) { return str_replace( 'https://site.test', '{{SITE}}', $source ); }
	public static function untokenize( $source ) { return str_replace( '{{SITE}}', 'https://site.test', $source ); }
	public static function get_resolved_source( $key ) { throw new Exception( 'Native history must not use the HTML source resolver' ); }
}
class Clara_VE_Responsive {
	const META = '_clara_ve_responsive';
	public static function sanitize_meta( $value ) { return is_string( $value ) ? $value : json_encode( $value ); }
}
class Test_DB {
	public $prefix = 'wp_';
	public $rows = array();
	public $insert_id = 0;
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_results( $query ) {
		list( $sql, $args ) = $query;
		if ( str_contains( $sql, 'WHERE id = %d AND page_key' ) ) { return array_values( array_filter( $this->rows, static function ( $row ) use ( $args ) { return $row->id === $args[0] && $row->page_key === $args[1]; } ) ); }
		$rows = array_values( array_filter( $this->rows, static function ( $row ) use ( $args ) { return $row->page_key === $args[0]; } ) );
		if ( str_contains( $sql, 'AND id = %d' ) ) { $rows = array_values( array_filter( $rows, static function ( $row ) use ( $args ) { return $row->id === $args[1]; } ) ); }
		if ( str_contains( $sql, 'id DESC' ) ) { $rows = array_reverse( $rows ); }
		if ( str_contains( $sql, 'LIMIT %d' ) ) { $rows = array_slice( $rows, 0, end( $args ) ); }
		return $rows;
	}
	public function get_row( $query ) { return $this->get_results( $query )[0] ?? null; }
	public function get_var( $query ) { $rows = $this->get_results( $query ); return str_contains( $query[0], 'MIN(id)' ) ? $rows[0]->id : count( $rows ); }
	public function insert( $table, $data, $formats ) { $data['id'] = ++$this->insert_id; $this->rows[] = (object) $data; return 1; }
	public function update( $table, $data, $where, ...$args ) {
		foreach ( $this->rows as $row ) { if ( $row->id === $where['id'] && $row->page_key === $where['page_key'] ) { $row->message = $data['message']; return 1; } }
		return 0;
	}
	public function query( $query ) {
		list( $sql, $args ) = $query;
		$removed = 0;
		$this->rows = array_values( array_filter( $this->rows, static function ( $row ) use ( $args, &$removed ) { return ! ( $row->page_key === $args[0] && $row->id !== $args[1] && $removed++ < $args[2] ); } ) );
	}
}
$wpdb = new Test_DB();
require dirname( __DIR__ ) . '/includes/class-history.php';
require dirname( __DIR__ ) . '/includes/class-native-history.php';
function check( $condition, $message ) { if ( ! $condition ) { throw new Exception( $message ); } }
function request( $method, $route, $params = array() ) { $request = new WP_REST_Request( $method, $route ); foreach ( $params as $key => $value ) { $request[$key] = $value; } return $request; }
function document( $source, $rules = '[]' ) { return array( 'content' => array( 'raw' => $source ), 'title' => array( 'raw' => 'Home' ), 'meta' => array( Clara_VE_Responsive::META => $rules, 'seo' => 'keep' ) ); }
function history_request( $type, $entity, $id = null ) { return request( 'GET', '/clara-ve/v1/native/history', array( 'type' => $type, 'entity' => (string) $entity, 'id' => $id ) ); }
function save_document( $route, $params, $next, $status = 200, $controller = null ) {
	$request = request( 'POST', $route, $params );
	$handler = array( 'callback' => array( $controller ?: new Test_Controller(), 'update_item' ) );
	Clara_VE_Native_History::before_save( null, $request, '', $handler );
	if ( $status < 300 ) { $GLOBALS['live'][$route] = $next; }
	Clara_VE_Native_History::after_save( new Test_Response( $next, $status ), $handler, $request );
}
$live['/wp/v2/pages/1'] = document( '<p>Original https://site.test</p>' );
save_document( '/wp/v2/pages/1', array( 'content' => '<p>New</p>' ), document( '<p>New</p>' ) );
$saved_actions = array_values( array_filter( $GLOBALS['clara_ve_actions'], static function ( $action ) { return 'clara_ve_native_entity_saved' === $action[0]; } ) );
check( 1 === count( $saved_actions ) && 'page' === $saved_actions[0][1][0] && 1 === (int) $saved_actions[0][1][1], 'A versioned save announces itself to extensions' );
$list = Clara_VE_Native_History::listing( history_request( 'page', 1 ) )->get_data()['entries'];
check( count( $list ) === 2 && $list[0]['isHead'] && ! $list[1]['isHead'], 'First Save records before and after states' );
$original = $list[1]['id'];
$snapshot = Clara_VE_Native_History::snapshot( history_request( 'page', 1, $original ) )->get_data();
check( $snapshot['edits']['content'] === '<p>Original https://site.test</p>', 'Snapshot untokenizes existing page history' );
check( array_keys( $snapshot['edits']['meta'] ) === array( Clara_VE_Responsive::META ), 'Only VE metadata is restored' );
check( $live['/wp/v2/pages/1']['content']['raw'] === '<p>New</p>', 'Reading Original never changes persisted content' );
save_document( '/wp/v2/pages/1', array( 'content' => '<p>New</p>' ), document( '<p>New</p>' ) );
check( count( $wpdb->rows ) === 2, 'No-op saves are deduplicated' );
$rules = '{"hero":{"mobile":{"display":"none"}}}';
save_document( '/wp/v2/pages/1', array( 'meta' => array( Clara_VE_Responsive::META => $rules ) ), document( '<p>New</p>', $rules ) );
$list = Clara_VE_Native_History::listing( history_request( 'page', 1 ) )->get_data()['entries'];
check( count( $list ) === 3 && $list[0]['isHead'] && ! $list[1]['isHead'], 'Responsive-only changes versioned; current includes responsive state' );
$before = count( $wpdb->rows );
save_document( '/wp/v2/pages/1', array( 'content' => 'bad' ), document( 'bad' ), 500 );
save_document( '/wp/v2/pages/1/autosaves', array( 'content' => 'auto' ), document( 'auto' ) );
check( count( $wpdb->rows ) === $before, 'Failures and autosaves create no versions' );
$forbidden = true;
check( is_wp_error( Clara_VE_Native_History::listing( history_request( 'page', 1 ) ) ), 'Native edit permission enforced' );
$forbidden = false;
$live['/wp/v2/pages/2'] = document( 'Other page' );
check( is_wp_error( Clara_VE_Native_History::snapshot( history_request( 'page', 2, $original ) ) ), 'Cross-entity snapshot denied' );
$rename = history_request( 'page', 2, $original ); $rename['message'] = 'not yours';
check( is_wp_error( Clara_VE_Native_History::rename( $rename ) ), 'Cross-entity rename denied' );
$rename['entity'] = '1'; $rename['message'] = '<b>Before hero redesign</b>';
check( ! is_wp_error( Clara_VE_Native_History::rename( $rename ) ), 'Own version can be renamed' );
check( $wpdb->rows[0]->message === 'Before hero redesign' && $wpdb->rows[0]->content_hash === hash( 'sha256', '<p>Original {{SITE}}</p>' ), 'Rename sanitizes without changing immutable content' );

foreach ( array( 'wp_template' => 'templates', 'wp_template_part' => 'template-parts', 'wp_navigation' => 'navigation', 'wp_block' => 'blocks' ) as $type => $base ) {
	$id = str_starts_with( $type, 'wp_template' ) ? 'vendor/sailing//header' : 22;
	$route = '/wp/v2/' . $base . '/' . $id;
	$live[$route] = document( 'File original' );
	save_document( $route, array( 'content' => 'DB override' ), document( 'DB override' ) );
	$entries = Clara_VE_Native_History::listing( history_request( $type, $id ) )->get_data()['entries'];
	check( count( $entries ) >= 2 && $entries[0]['isHead'], $type . ' uses canonical history' );
	if ( str_starts_with( $type, 'wp_template' ) ) {
		$before = count( $wpdb->rows );
		$live[$route . '/autosaves'] = document( 'Auto original' );
		save_document( $route . '/autosaves', array( 'content' => 'Auto' ), document( 'Auto' ), 200, new WP_REST_Autosaves_Controller() );
		check( count( $wpdb->rows ) === $before, 'Template autosave controller is excluded even with slash slug' );
		check( Clara_VE_Native_History::snapshot( history_request( $type, $id, end( $entries )['id'] ) )->get_data()['edits']['content'] === 'File original', 'File-to-database template retains Original' );
	}
}
$live['/wp/v2/global-styles/50'] = array( 'styles' => array(), 'settings' => array() );
save_document( '/wp/v2/global-styles/50', array( 'styles' => array( 'color' => array( 'text' => '#fff' ) ) ), array( 'styles' => array( 'color' => array( 'text' => '#fff' ) ), 'settings' => array() ) );
$entries = Clara_VE_Native_History::listing( history_request( 'wp_global_styles', 50 ) )->get_data()['entries'];
$edits = Clara_VE_Native_History::snapshot( history_request( 'wp_global_styles', 50, end( $entries )['id'] ) )->get_data()['edits'];
check( is_object( $edits['styles'] ) && is_object( $edits['settings'] ), 'Empty Global Styles remain JSON objects and restorable' );
check( array_keys( $edits ) === array( 'styles', 'settings' ), 'Global Styles snapshots do not restore content/status' );
$live['/wp/v2/pages/3'] = document( 'Unsaved baseline' );
$before = count( $wpdb->rows );
save_document( '/wp/v2/pages/3', array( 'content' => 'failure' ), document( 'failure' ), 400 );
check( count( $wpdb->rows ) === $before, 'Failed first save does not seed a false baseline' );
$empty = Clara_VE_Native_History::target( 'page', 4 );
$live['/wp/v2/pages/4'] = document( '' );
check( count( Clara_VE_Native_History::listing( history_request( 'page', 4 ) )->get_data()['entries'] ) === 1, 'Empty document has a restorable Original' );
for ( $i = 0; $i < 305; $i++ ) { Clara_VE_History::record( 'Version ' . $i, array(), 'save', null, null, $empty['key'], array() ); }
$entries = Clara_VE_Native_History::listing( history_request( 'page', 4 ) )->get_data()['entries'];
check( count( $entries ) === 11 && end( $entries )['message'] === 'Original', 'Ten saves plus the Original are kept, and all of them are listed' );
$unrestorable = array_filter( $entries, function ( $entry ) { return is_wp_error( Clara_VE_Native_History::snapshot( history_request( 'page', 4, $entry['id'] ) ) ); } );
check( array() === $unrestorable, 'Every listed save can be restored' );
$theme = 'other-theme';
check( count( Clara_VE_Native_History::listing( history_request( 'page', 1 ) )->get_data()['entries'] ) === 3, 'Page history survives theme switches' );
check( is_wp_error( Clara_VE_Native_History::target( 'page', '../1' ) ) && is_wp_error( Clara_VE_Native_History::target( 'wp_template', '../sailing//header' ) ), 'Malformed/path traversal IDs rejected' );
echo "Native history: permissions, entity isolation, saves, extension action, snapshots, rename, responsive, templates, globals and retention PASS\n";
