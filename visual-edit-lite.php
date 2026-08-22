<?php
/**
 * Plugin Name: Visual Edit Lite
 * Plugin URI: https://github.com/iOSDevSK/visual-edit-lite
 * Description: Point-and-click visual editing for raw-HTML theme pages — text, links, images, forms, menus, SEO and AI-readiness — keeping the page markup 1:1 with the original design. No builder re-structuring.
 * Version: 1.25.1
 * Requires at least: 6.6
 * Requires PHP: 7.4
 * Author: Filip Dvoran
 * Author URI: https://github.com/iOSDevSK
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: visual-edit-lite
 * Domain Path: /languages
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Visual Edit Pro is active on this install.
 *
 * Lite and Pro share every class, option and REST namespace, and
 * `visual-edit-lite/` sorts BEFORE `visual-edit/`, so Lite loads first and
 * would win a collision it has no business winning. Lite is therefore the one
 * that stands down. The function name carries the `lite` infix deliberately:
 * Pro has no such symbol, so this check cannot collide with the thing it is
 * checking for.
 */
function clara_ve_lite_pro_active() {
	$active = (array) get_option( 'active_plugins', array() );

	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}

	foreach ( $active as $plugin ) {
		if ( 'visual-edit.php' === basename( (string) $plugin ) ) {
			return true;
		}
	}

	return false;
}

if ( clara_ve_lite_pro_active() ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning is-dismissible"><p>';
			echo esc_html__( 'Visual Edit Lite is switched off because Visual Edit Pro is active on this site. Pro already includes everything Lite does — deactivate Lite to clear this notice.', 'visual-edit-lite' );
			echo '</p></div>';
		}
	);
	return;
}

define( 'CLARA_VE_VERSION', '1.25.1' );
// Signals schema-1 generated themes that this plugin delegates every public
// rendering concern to them. Themes generated before that contract ignore the
// signal and continue to receive the complete legacy runtime below.
define( 'CLARA_VE_THEME_RUNTIME_DELEGATION', 1 );
define( 'CLARA_VE_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLARA_VE_URL', plugin_dir_url( __FILE__ ) );

/**
 * Whether the active theme declares the standalone html2wp runtime contract.
 *
 * get_theme_support() returns an outer list of argument sets. Be deliberately
 * tolerant here: schema is the discriminator, not the exact PHP nesting a
 * particular WordPress version chooses to preserve.
 */
function clara_ve_theme_owns_public_runtime() {
	$support = get_theme_support( 'html2wp-runtime' );
	if ( false === $support ) {
		return false;
	}
	foreach ( (array) $support as $entry ) {
		if ( is_array( $entry ) && isset( $entry['schema'] ) && (int) $entry['schema'] >= 1 ) {
			return true;
		}
		if ( is_array( $entry ) && isset( $entry[0] ) && is_array( $entry[0] ) && isset( $entry[0]['schema'] ) && (int) $entry[0]['schema'] >= 1 ) {
			return true;
		}
	}
	return false;
}

/*
 * No licensing, no self-updater.
 *
 * Visual Edit Lite is the free edition: every feature it ships is available to
 * every install, unconditionally and offline. There is no key to enter, no
 * activation call, no licence heartbeat and no bundled update checker — the
 * WordPress.org directory is the only update channel (and bundling an updater
 * is forbidden there anyway).
 *
 * What Lite therefore does NOT contain, rather than merely hiding it: the AI
 * Assistant and AI image/video tools, the AI Settings screen, Cloudflare
 * Turnstile on forms, and Theme Export. History lists the last ten saves plus
 * the Original, which is exactly what an unregistered Pro install shows; the
 * saves themselves are recorded to the same depth either way.
 *
 * Everything else — editing, saving, forms, menus, dynamic tokens, SEO,
 * redirects, AI-readiness/llms.txt, block mode, motion, import — is the full
 * Pro behaviour.
 */

// The unqualified name of the pattern whose rendered HTML is the editable
// front-page source. WordPress namespaces a theme's auto-registered patterns
// with the theme's own slug, so the qualified name is resolved at runtime by
// clara_ve_pattern_name() rather than hardcoded to one theme.
define( 'CLARA_VE_PATTERN_SLUG', 'front-page-original' );
define( 'CLARA_VE_OPTION', 'clara_ve_front_source' );
define( 'CLARA_VE_THEME_URI_TOKEN', '__CLARA_THEME_URI__' );
define( 'CLARA_VE_NAV_LOCATION', 'clara_ve_front_nav' );
define( 'CLARA_VE_PSEUDO_OPTION', 'clara_ve_pseudo_css' );

/**
 * The folder under uploads/ that every imported media file lands in.
 *
 * It is the one internal name a site's VISITORS read: it sits in the address
 * of every photograph on a converted site, and the themes this pipeline builds
 * are sold to people with no connection to the site it was first written for.
 * `ve-import` says which plugin put the file there and nothing else.
 *
 * The old name is still read, and that is not tidiness — it is every site
 * already running. `clara_ve_import_dirs()` is what anything SCANNING must
 * use; only new writes take the constant. An install that migrated years of
 * media under the old prefix keeps managing it, and the same media library can
 * hold both without either half going unrecognised.
 */
define( 'CLARA_VE_IMPORT_DIR', 've-import' );
define( 'CLARA_VE_IMPORT_DIR_LEGACY', 'clara-ve-import' );

/** Every prefix an imported attachment may live under, newest first. */
function clara_ve_import_dirs() {
	return array( CLARA_VE_IMPORT_DIR, CLARA_VE_IMPORT_DIR_LEGACY );
}

/** True when a stored path is one this plugin imported, under either name. */
function clara_ve_is_import_path( $relative ) {
	foreach ( clara_ve_import_dirs() as $dir ) {
		if ( 0 === strpos( (string) $relative, $dir . '/' ) ) {
			return true;
		}
	}
	return false;
}

/** A stored path with whichever import prefix it carries taken off. */
function clara_ve_strip_import_dir( $relative ) {
	foreach ( clara_ve_import_dirs() as $dir ) {
		if ( 0 === strpos( (string) $relative, $dir . '/' ) ) {
			return substr( (string) $relative, strlen( $dir ) + 1 );
		}
	}
	return (string) $relative;
}

// Which theme the stored editable content belongs to. Everything this plugin
// persists — page sources, pseudo styles, history, the page bindings — is
// keyed by page key ALONE, with no theme in the name. That is deliberate and
// correct for the model this plugin is built for (one WordPress site = one
// converted theme = one set of sources), and it is silently destructive the
// moment a SECOND converted theme is activated on the same install: the front
// page renders theme A's HTML through theme B's CSS, B's import is refused as
// a conflict, a save under B writes A's header into B's template part, the
// theme-URI token resolves to B's directory, and restoring history writes A's
// markup into B's pages. Verified against the code, every one of them.
//
// So the data records who owns it, and the plugin refuses to serve or
// overwrite content that belongs to a different theme (see
// clara_ve_foreign_data() and its call sites). That does not make theme
// switching WORK — supporting it needs the stored data namespaced per
// stylesheet, which is a larger change — but it turns a silent hybrid into an
// explicit, reversible state that says what is wrong.
define( 'CLARA_VE_OWNER_OPTION', 'clara_ve_owner_theme' );

// Reserved page key for the front page (which isn't a real WP Page — it's
// the pattern-override mechanism above). Other WP Pages become "visual-edit
// enabled" by carrying this post meta, pointing at their own keyed source.
define( 'CLARA_VE_DEFAULT_KEY', 'front-page' );
define( 'CLARA_VE_PAGE_KEY_META', '_clara_ve_key' );

// Which theme a bound Page belongs to. A page key alone stopped being unique
// the moment two converted themes could share an install — both would name a
// page "about" — so the binding records the theme too and lookups prefer the
// active one. Absent on pages bound before scoping shipped; the migration
// stamps those, and until it runs an unstamped page still resolves.
define( 'CLARA_VE_PAGE_THEME_META', '_clara_ve_theme' );
/**
 * The address a page held before its theme was switched away from — see
 * clara_ve_handover_pages_on_switch(). Present only while parked.
 */
define( 'CLARA_VE_PAGE_SLUG_META', '_clara_ve_slug' );

/**
 * The status a parked page or post held before its theme was deactivated, and
 * the status it holds while parked.
 *
 * Deactivating a converted theme has to leave the site looking as though that
 * theme had never been installed, which a renamed slug alone does not achieve:
 * the pages stay published, listed, searchable and reachable at their new
 * address. A status of its own takes them out of every query at once.
 *
 * Deliberately not `trash`. wp_scheduled_delete() permanently deletes anything
 * trashed longer than EMPTY_TRASH_DAYS ago — 30 by default — so parking into
 * the trash would destroy a site's content a month after someone switched
 * themes, silently, on a timer.
 *
 * Slug parking stays alongside it rather than being replaced by it, because
 * the two solve different problems: wp_unique_post_slug() does not consider
 * post_status at all for hierarchical types, so a parked page still holding
 * `about` makes the next theme's page land on `about-2`.
 */
define( 'CLARA_VE_PARKED_STATUS', 'clara_ve_parked' );
define( 'CLARA_VE_PAGE_STATUS_META', '_clara_ve_status' );

// Reserved keys for the shared header/footer template parts (parts/header.html,
// parts/footer.html) — like the front page, these have no bound WP Page; the
// render target is WordPress's own template-part customization mechanism
// (a wp_template_part post), the same one the Site Editor itself writes to.
define( 'CLARA_VE_HEADER_KEY', 'header' );
define( 'CLARA_VE_FOOTER_KEY', 'footer' );

// The single-post layout (parts/article.html, rendered by templates/single.html
// between the header and footer parts). Same shape as the two above — no bound
// WP Page, mirrored into a wp_template_part — which is exactly why the article
// template is a PART rather than a wp_template: every piece of machinery those
// two already rely on (sync, root selector, shape guard, History, pseudo
// styles) is keyed by name and works for this one unchanged.
define( 'CLARA_VE_ARTICLE_KEY', 'article' );

// The 404 page (parts/404.html, rendered by templates/404.html between the
// header and footer parts), for the same reason as the article layout above.
//
// It has to be a part rather than a template because WordPress renders the 404
// for addresses that DO NOT EXIST — there is no stable URL the editor could
// load to edit it. A theme that instead ships an ordinary "404 preview" Page
// alongside a separate wp_template hands the owner two independent copies of
// the same design in two different markup systems, where editing the one they
// can reach changes nothing about the one visitors see. As a part it is edited
// like any other key, and previewed by opening any address that is not a page.
define( 'CLARA_VE_404_KEY', '404' );

// Delimiters around the article template's typography specimen — the block of
// sample elements whose styles are compiled into every article's body CSS. It
// is editor-only furniture, cut from the published page by
// clara_ve_strip_specimen().
define( 'CLARA_VE_SPECIMEN_START', '<!-- cve-specimen-start -->' );
define( 'CLARA_VE_SPECIMEN_END', '<!-- cve-specimen-end -->' );

require_once CLARA_VE_DIR . 'includes/class-block-gate.php';
require_once CLARA_VE_DIR . 'includes/class-block-supports.php';
require_once CLARA_VE_DIR . 'includes/class-patterns.php';
require_once CLARA_VE_DIR . 'includes/class-motion.php';
require_once CLARA_VE_DIR . 'includes/class-responsive.php';
require_once CLARA_VE_DIR . 'includes/class-block-stamp.php';
require_once CLARA_VE_DIR . 'includes/class-block-patch.php';
require_once CLARA_VE_DIR . 'includes/class-source-store.php';
require_once CLARA_VE_DIR . 'includes/class-pseudo-store.php';
require_once CLARA_VE_DIR . 'includes/class-history.php';
require_once CLARA_VE_DIR . 'includes/class-theme-registry.php';
require_once CLARA_VE_DIR . 'includes/class-theme-park.php';
require_once CLARA_VE_DIR . 'includes/class-theme-purge.php';
require_once CLARA_VE_DIR . 'includes/class-rest.php';
require_once CLARA_VE_DIR . 'includes/class-front-nav.php';
require_once CLARA_VE_DIR . 'includes/class-forms.php';
require_once CLARA_VE_DIR . 'includes/class-lists.php';
require_once CLARA_VE_DIR . 'includes/class-optin.php';
require_once CLARA_VE_DIR . 'includes/class-tokens.php';
require_once CLARA_VE_DIR . 'includes/class-fonts.php';
require_once CLARA_VE_DIR . 'includes/class-editor-page.php';
require_once CLARA_VE_DIR . 'includes/class-media.php';
require_once CLARA_VE_DIR . 'includes/class-form-settings.php';
require_once CLARA_VE_DIR . 'includes/class-mailer.php';
require_once CLARA_VE_DIR . 'includes/class-bundle-format.php';
// After class-bundle-format.php: Clara_VE_SEO normalises through
// Clara_VE_Bundle_Format::sanitize_seo(), the one definition of a record's shape.
require_once CLARA_VE_DIR . 'includes/class-seo.php';
require_once CLARA_VE_DIR . 'includes/class-redirects.php';
require_once CLARA_VE_DIR . 'includes/class-geo.php';
require_once CLARA_VE_DIR . 'includes/class-geo-audit.php';
require_once CLARA_VE_DIR . 'includes/class-seo-settings.php';
require_once CLARA_VE_DIR . 'includes/class-zip.php';
require_once CLARA_VE_DIR . 'includes/class-bundle-writer.php';
require_once CLARA_VE_DIR . 'includes/class-bundle-reader.php';
require_once CLARA_VE_DIR . 'includes/class-import-legacy.php';
require_once CLARA_VE_DIR . 'includes/class-import-plan.php';
require_once CLARA_VE_DIR . 'includes/class-import-page.php';
require_once CLARA_VE_DIR . 'includes/class-parked-page.php';
require_once CLARA_VE_DIR . 'includes/class-page-actions.php';

// Create the history table on the site's first request after install/update —
// dbDelta is idempotent, so this is a cheap no-op once the schema is current.
add_action( 'init', array( 'Clara_VE_History', 'maybe_install' ), 1 );

/**
 * Editing requires theme-level trust: raw HTML round-trips through the editor.
 */
function clara_ve_user_can_edit() {
	return current_user_can( 'edit_theme_options' ) && current_user_can( 'unfiltered_html' );
}

/**
 * The active theme's declared contract with this plugin — everything the
 * plugin needs to know about markup it cannot know on its own.
 *
 * The plugin converts ARBITRARY sites, so it ships no defaults here: every
 * markup-specific fact (which substrings a save must preserve, which
 * elements are navigation) belongs to the theme that owns the markup. A
 * theme that declares nothing gets the theme-agnostic behavior only — the
 * generic save floor, and menu management visibly OFF (see
 * clara_ve_contract_notice()) rather than silently matching nothing.
 * Hardcoded defaults were tried and are exactly what broke every non-Clara
 * theme; the history is in includes/class-source-store.php.
 *
 * Shape:
 *   'anchors' => array( '<key>' => array( 'substring', … ), … )
 *   'menus'   => array(
 *       array(
 *         'location' => 'theme_nav_1',      // nav menu location slug
 *         'selector' => 'nav.nav-links',    // "tag.class", "tag", or the
 *                                           // generated '[data-ve-nav="1"]'
 *         'label'    => 'Header navigation',
 *         'active'   => '…',                // optional: the class value the
 *         'rest'     => '…',                // design gives the CURRENT page's
 *                                           // nav link / every other link —
 *                                           // lets render restore the per-page
 *                                           // highlight a shared part loses
 *       ), …
 *   )
 *   'parts'   => array(                     // chrome VARIANT template parts
 *       array(                              // beyond the standard header/footer
 *         'key'         => 'header-2',      // part slug = visual-edit key
 *         'area'        => 'header',        // header | footer (the wrapper tag)
 *         'label'       => 'Header (variant 2)',
 *         'preview_key' => 'signin',        // a page key that RENDERS this part
 *       ), …
 *   )
 *
 * Several zones may share one location (a desktop nav and its mobile drawer
 * rendering the same menu).
 *
 * @return array Normalized: all keys always present.
 */
function clara_ve_theme_contract() {
	/**
	 * Declare the theme's contract. Generated themes ship this in
	 * inc/visual-edit.php; see the docblock above for the shape.
	 *
	 * @param array $contract Default empty — the plugin assumes nothing.
	 */
	$contract = apply_filters( 'clara_ve_theme_contract', array() );
	$contract = is_array( $contract ) ? $contract : array();
	$contract += array(
		'anchors' => array(),
		'menus'   => array(),
		'parts'   => array(),
	);

	$menus = array();
	foreach ( (array) $contract['menus'] as $menu ) {
		if ( empty( $menu['location'] ) || empty( $menu['selector'] ) ) {
			continue;
		}
		$menus[] = array(
			'location' => sanitize_key( $menu['location'] ),
			'selector' => (string) $menu['selector'],
			'label'    => isset( $menu['label'] ) ? (string) $menu['label'] : (string) $menu['location'],
			'active'   => isset( $menu['active'] ) ? (string) $menu['active'] : '',
			'rest'     => isset( $menu['rest'] ) ? (string) $menu['rest'] : '',
		);
	}
	$contract['menus']   = $menus;
	$contract['anchors'] = is_array( $contract['anchors'] ) ? $contract['anchors'] : array();

	$parts = array();
	foreach ( (array) $contract['parts'] as $part ) {
		if ( empty( $part['key'] ) || empty( $part['area'] ) ) {
			continue;
		}
		$area = 'footer' === $part['area'] ? 'footer' : 'header';
		$key  = sanitize_key( $part['key'] );
		// The standard keys are built in; redeclaring one would double it in
		// every list the contract parts get appended to.
		if ( in_array( $key, array( CLARA_VE_DEFAULT_KEY, CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ), true ) ) {
			continue;
		}
		$parts[] = array(
			'key'         => $key,
			'area'        => $area,
			'label'       => isset( $part['label'] ) ? (string) $part['label'] : $key,
			'preview_key' => isset( $part['preview_key'] ) ? sanitize_key( $part['preview_key'] ) : '',
		);
	}
	$contract['parts'] = $parts;

	return $contract;
}

/**
 * The contract part entry for a key, or null — the lookup every place that
 * special-cases the built-in chrome keys uses to extend the same treatment
 * to a theme's declared variant parts.
 *
 * @param string $key
 * @return array|null
 */
function clara_ve_contract_part( $key ) {
	$key = sanitize_key( $key );
	foreach ( clara_ve_theme_contract()['parts'] as $part ) {
		if ( $part['key'] === $key ) {
			return $part;
		}
	}
	return null;
}

/**
 * The theme slug the stored editable content belongs to, or '' when nothing
 * has been stored yet.
 *
 * @return string
 */
function clara_ve_data_owner() {
	return (string) get_option( CLARA_VE_OWNER_OPTION, '' );
}

/**
 * The meta_query that keeps one converted theme's posts out of another's.
 *
 * Pages were only half the collision. POSTS are global too, and a `[wp-posts]`
 * listing asks for `post_type=post` with no notion of theme — so on an install
 * carrying three converted themes, every listing, every related-articles strip
 * and every archive showed whichever theme's articles happened to be newest.
 * Measured: Sonic's own /blog/ rendered Kinto's journal entries, and Lumen's
 * article pages recommended them too, because Kinto's imported dates were the
 * most recent on the install.
 *
 * Posts the OWNER wrote have no theme stamp and must keep appearing — only a
 * post imported FOR ANOTHER THEME is excluded.
 *
 * @return array
 */
function clara_ve_theme_post_scope() {
	$mine = array(
		'key'   => CLARA_VE_PAGE_THEME_META,
		'value' => sanitize_key( get_stylesheet() ),
	);
	$unstamped = array(
		'key'     => CLARA_VE_PAGE_THEME_META,
		'compare' => 'NOT EXISTS',
	);

	// Does the active theme have posts of its own?
	$own = get_posts(
		array(
			'post_type'     => 'post',
			'post_status'   => 'publish',
			'numberposts'   => 1,
			'fields'        => 'ids',
			'no_found_rows' => true,
			'meta_query'    => array( $mine ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		)
	);
	if ( ! $own ) {
		// It imported nothing, so it has no claim to press: show everything.
		// This is the ordinary single-theme install, including one whose
		// content predates stamping entirely.
		return array( 'relation' => 'OR', $unstamped, $mine );
	}

	// It has its own. An unstamped post that carries a KEY was imported by
	// SOME theme — a key is only ever written by an import — and since the
	// active theme's own imports are stamped, that theme is not this one. Seen
	// live: a previous theme's ten articles sat in a converted theme's blog
	// listing because an older importer had stamped keys but not themes, and
	// the theme that made them was no longer even installed to be recognised.
	// A post with NO key is the owner's own writing and always belongs.
	return array(
		'relation' => 'OR',
		$mine,
		array(
			'relation' => 'AND',
			$unstamped,
			array(
				'key'     => CLARA_VE_PAGE_KEY_META,
				'compare' => 'NOT EXISTS',
			),
		),
	);
}

/**
 * Apply that scope to the site's own post listings — the blog home, archives,
 * search and feeds. The [wp-posts] token does it for itself; this is for the
 * queries WordPress runs from the theme's templates.
 *
 * @param WP_Query $query
 */
function clara_ve_scope_posts_to_theme( $query ) {
	// Not this plugin's theme, not this plugin's query. The scope exists to
	// stop one converted theme's imported articles surfacing in another's
	// listings; on a theme the plugin does not run, it can only subtract —
	// hiding published posts from a blog page for a reason the owner has no
	// way to see, and adding a meta_query to every archive to do it.
	if ( ! clara_ve_active_theme_is_ours() ) {
		return;
	}
	if ( is_admin() || ! $query->is_main_query() ) {
		return;
	}
	if ( ! ( $query->is_home() || $query->is_archive() || $query->is_search() || $query->is_feed() ) ) {
		return;
	}
	$existing = $query->get( 'meta_query' );
	$scope    = clara_ve_theme_post_scope();
	$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, $scope ) : $scope );
}
add_action( 'pre_get_posts', 'clara_ve_scope_posts_to_theme' );

/**
 * The parked slug a theme's page wears while that theme is not active.
 *
 * @param string $key   Page key.
 * @param string $theme Theme stylesheet.
 * @return string
 */
function clara_ve_parked_slug( $key, $theme ) {
	return sanitize_title( $key . '--ve-' . $theme );
}

/**
 * Whether this plugin has content of its own for a theme — i.e. whether the
 * theme is one of ours rather than an unrelated WordPress theme.
 *
 * @param string $theme Theme stylesheet.
 * @return bool
 */
function clara_ve_theme_is_known( $theme ) {
	if ( '' === $theme ) {
		return false;
	}
	// The registry first: it is the one answer that survives both deactivation
	// and deletion. The two checks below need either the theme's files or its
	// pages to still be findable, and a parked theme whose directory someone
	// removed over FTP has neither — at which point every park, restore and
	// purge decision about it would silently become "not ours", stranding its
	// content on the site with nothing able to claim it.
	if ( class_exists( 'Clara_VE_Theme_Registry' ) && Clara_VE_Theme_Registry::known( $theme ) ) {
		return true;
	}
	// A converter-built theme counts even before it has imported anything —
	// otherwise its very FIRST import is the one that collides, because the
	// previous theme still holds every address while the new theme has no
	// pages yet to prove it is ours. Verified live: a third theme's first
	// import landed on /about-2/, /blog-2/, /contact-2/.
	if ( clara_ve_theme_is_converted( $theme ) ) {
		return true;
	}
	$pages = get_posts(
		array(
			'post_type'     => 'page',
			// Parked included: once a theme is deactivated every one of its
			// pages carries that status, so a list without it answers "this
			// theme has no pages" precisely when it has most.
			'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', CLARA_VE_PARKED_STATUS ),
			'numberposts'   => 1,
			'fields'        => 'ids',
			'no_found_rows' => true,
			'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => CLARA_VE_PAGE_THEME_META,
					'value' => sanitize_key( $theme ),
				),
			),
		)
	);
	return ! empty( $pages );
}

/**
 * Every page this plugin imported for one theme.
 *
 * Parked pages are included: restoring a theme has to find the very pages that
 * parking hid, and both directions go through here.
 *
 * @param string $theme Theme stylesheet.
 * @return WP_Post[]
 */
function clara_ve_theme_pages( $theme ) {
	return get_posts(
		array(
			'post_type'     => 'page',
			'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', CLARA_VE_PARKED_STATUS ),
			'numberposts'   => -1,
			'no_found_rows' => true,
			'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => CLARA_VE_PAGE_THEME_META,
					'value' => sanitize_key( $theme ),
				),
				array(
					'key'     => CLARA_VE_PAGE_KEY_META,
					'compare' => 'EXISTS',
				),
			),
		)
	);
}

/**
 * Hand the site's page ADDRESSES from the outgoing theme to the incoming one.
 *
 * Everything this plugin stores is already scoped per theme — sources, pseudo
 * styles, history. Pages are not, and cannot be: a WP Page is one row with one
 * slug, and WordPress lets exactly one page own `/about/`. So installing a
 * second converted theme and importing its content gives the SECOND theme
 * `/about-2/`, `/blog-7/`, `/pricing-2/` — while its own navigation, its
 * template parts and its stored markup all still link to `/about/`. Every one
 * of those links lands on the FIRST theme's page, which renders that theme's
 * markup inside this theme's CSS. That is the "themes collide and the design
 * breaks" report, and it is not a storage bug: the storage is fine, the
 * addresses are what collided.
 *
 * Measured on a two-theme install before this existed: theme B's own /about/,
 * /blog/ and /pricing/ all served theme A's pages, and the front page kept
 * theme A's title because `page_on_front` is a single global option.
 *
 * So on every switch away from a theme this plugin knows, that theme's whole
 * world is PARKED — see Clara_VE_Theme_Park — and the incoming theme's, if it
 * has one, is taken back out. Nothing is deleted: switch back and everything
 * returns, because the parking is symmetric. The plugin's own lookups are
 * unaffected either way; they resolve by key meta, never by slug or status.
 *
 * This used to run only when the theme being switched TO was also one of
 * ours, so that a converted site moving to Twenty Twenty-Five kept every
 * address working. That was the wrong instinct: it left the site full of a
 * design nobody could see, with its pages still answering, its images still in
 * the library and its front page still set. Leaving now means leaving, and
 * what is held is listed under Visual Edit → Parked content, where it can be
 * brought back or exported.
 *
 * @param string        $new_name  Incoming theme name (unused).
 * @param WP_Theme|null $new_theme Incoming theme.
 * @param WP_Theme|null $old_theme Outgoing theme.
 */
function clara_ve_handover_pages_on_switch( $new_name = '', $new_theme = null, $old_theme = null ) {
	$incoming = get_stylesheet();
	$outgoing = ( $old_theme instanceof WP_Theme ) ? $old_theme->get_stylesheet() : '';
	if ( '' === $outgoing || $outgoing === $incoming ) {
		return;
	}

	// Park first, then claim — the incoming theme cannot take `/about/` while
	// the outgoing theme still holds it, and wp_unique_post_slug() would
	// silently hand it `/about-2/` again. Parking a page does not free its
	// slug on its own: that function does not look at post_status at all for
	// hierarchical types.
	Clara_VE_Theme_Park::park( $outgoing );
	Clara_VE_Theme_Park::restore( $incoming );

	// The data-owner record follows the addresses, or the admin notice keeps
	// warning about a collision that has just been resolved. Only when the
	// incoming theme is one of ours: handing the record to Twenty Twenty-Five
	// would name a theme that owns nothing as the owner of everything.
	if ( clara_ve_theme_is_known( $incoming ) ) {
		update_option( CLARA_VE_OWNER_OPTION, $incoming, false );
	}
	flush_rewrite_rules( false );
}
add_action( 'switch_theme', 'clara_ve_handover_pages_on_switch', 10, 3 );

/**
 * Whether the stored content could plausibly be the ACTIVE theme's own.
 *
 * This exists because the ownership record alone protects nothing on any
 * install that already had content when it shipped — which is every real one.
 * Verified the hard way: a site holding one theme's imported pages, with a
 * different theme activated, rendered the wrong design while the guard sat
 * inert because nothing had claimed the data yet. Treating unclaimed data as
 * "not foreign" was meant to avoid disturbing existing installs; it disabled
 * the protection exactly where it was needed.
 *
 * So when there is no record, the DATA is asked instead, by two signals that
 * need no bookkeeping and work retroactively:
 *
 * 1. Referenced theme assets. A stored source carries
 *    `__CLARA_THEME_URI__/live.css`-style references, which resolve against
 *    whatever theme is active. If EVERY referenced asset is absent from the
 *    active theme's directory, the source was written for a different theme.
 *    All of them, not some: one deleted file is an edit, none of them
 *    existing is a different site.
 * 2. The active theme's own declared anchors. The contract names substrings
 *    this theme's markup must contain; a stored source lacking them is not
 *    this theme's page. Same test validate_shape() applies to saves.
 *
 * Either signal failing is conclusive; either signal PASSING is positive
 * evidence. The distinction matters because ownership is claimed off this
 * answer, and "no evidence either way" must never be read as "yes": a plugin
 * activated while a stock theme was still active would otherwise record that
 * theme as the owner — the stored source has no theme assets to check and a
 * stock theme declares no anchors, so nothing can be evaluated — and the real
 * converted theme is then locked out of its own content the moment it is
 * activated. Verified live: a site reported its pages as belonging to Twenty
 * Twenty-Five while correctly rendering the converted design underneath.
 *
 * @return string 'match' | 'mismatch' | 'unknown'
 */
function clara_ve_data_theme_verdict() {
	// Per-request memo, keyed by what the answer actually depends on — the
	// active theme and the stored source — rather than a bare flag. A single
	// flag is right for one web request and quietly wrong for anything that
	// evaluates more than one state in one process (a test harness, WP-CLI
	// looping over themes), which is exactly where a cached verdict is most
	// misleading.
	static $memo = array();
	$source = get_option( CLARA_VE_OPTION, '' );
	if ( ! is_string( $source ) || '' === trim( $source ) ) {
		return 'unknown'; // nothing stored — nothing to judge, and nothing to claim
	}
	$memo_key = get_stylesheet() . '|' . strlen( $source );
	if ( isset( $memo[ $memo_key ] ) ) {
		return $memo[ $memo_key ];
	}

	// 1. the theme assets the source references, against this theme's files.
	$checked = 0;
	$missing = 0;
	if ( preg_match_all( '#' . preg_quote( CLARA_VE_THEME_URI_TOKEN, '#' ) . '/([^"\'\s>)]+)#', $source, $m ) ) {
		$dir = untrailingslashit( get_template_directory() );
		foreach ( array_unique( $m[1] ) as $rel ) {
			$rel = strtok( $rel, '?#' );
			if ( '' === $rel || false !== strpos( $rel, '..' ) ) {
				continue;
			}
			++$checked;
			if ( ! file_exists( $dir . '/' . $rel ) ) {
				++$missing;
			}
		}
		if ( $checked > 0 && $missing === $checked ) {
			return $memo[ $memo_key ] = 'mismatch';
		}
	}

	// 2. the active theme's declared front-page anchors.
	$contract = clara_ve_theme_contract();
	$anchors  = array_filter( array_map( 'strval', isset( $contract['anchors'][ CLARA_VE_DEFAULT_KEY ] )
		? (array) $contract['anchors'][ CLARA_VE_DEFAULT_KEY ]
		: array() ) );
	foreach ( $anchors as $anchor ) {
		if ( false === strpos( $source, $anchor ) ) {
			return $memo[ $memo_key ] = 'mismatch';
		}
	}

	// Positive evidence: an asset this theme actually has, or an anchor it
	// declared and the source carries. Absent both, no opinion — which must
	// NOT be mistaken for a match, because ownership is claimed off this.
	$matched = ( $checked > 0 && 0 === $missing ) || ! empty( $anchors );
	return $memo[ $memo_key ] = $matched ? 'match' : 'unknown';
}

/**
 * Whether a theme slug looks like a Visual Edit converted theme at all.
 *
 * A stock theme cannot meaningfully own converted content, so an ownership
 * record naming one is junk — written by accident while it happened to be
 * active — and must not lock the real theme out. Judged by artifacts on disk
 * rather than by running the theme's own filters, which is impossible for a
 * theme that is not active.
 *
 * @param string $slug
 * @return bool
 */
function clara_ve_theme_is_converted( $slug ) {
	$slug = (string) $slug;
	if ( '' === $slug || '(unknown)' === $slug ) {
		return false;
	}
	$root = trailingslashit( get_theme_root( $slug ) ) . $slug;
	foreach ( array( '/inc/visual-edit.php', '/patterns/front-page-original.php', '/clara-content' ) as $probe ) {
		if ( file_exists( $root . $probe ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether the ACTIVE theme is one this plugin is the editor FOR.
 *
 * Everything the plugin does to the public site — the four chrome keys, the
 * SEO head, the meta_query on every archive, the activation menu seed — was
 * written for a theme the html2wp converter built, whose pages are raw HTML
 * this plugin owns end to end. A native block theme asked for none of it and
 * got it anyway: every og:* tag and the JSON-LD emitted twice, header and
 * footer canvases that loaded empty and wrote a template-part override the
 * first time they were saved, an extra meta_query on listings it does not
 * need. Measured on amanda-rose-blocks with 1.20.7.
 *
 * This is NOT an on/off switch for the plugin. On a foreign theme it remains
 * a fully working editor, AI chat, history and export; it simply stops
 * behaving like that theme's runtime.
 *
 * Three signals, cheapest first, any one of which is enough:
 *   - the theme declares a contract (anchors, menus or variant parts);
 *   - converter artifacts on disk — the same probe used to judge a theme
 *     that is not active;
 *   - a source row already stored for it, so an install that predates both
 *     of the above keeps every behavior it has today.
 *
 * @return bool
 */
function clara_ve_active_theme_is_ours() {
	static $cache = array();

	$slug = get_stylesheet();

	// The contract arrives from the theme's own functions.php, so an answer
	// taken before the theme has booted would cache "declares nothing" for
	// the rest of the request. Answer early if asked, but only remember once
	// there is something settled to remember.
	$settled = did_action( 'after_setup_theme' ) > 0;
	if ( $settled && isset( $cache[ $slug ] ) ) {
		return $cache[ $slug ];
	}

	$contract = clara_ve_theme_contract();
	$ours     = ! empty( $contract['anchors'] )
		|| ! empty( $contract['menus'] )
		|| ! empty( $contract['parts'] )
		|| clara_ve_theme_is_converted( $slug )
		|| ( class_exists( 'Clara_VE_Source_Store' ) && Clara_VE_Source_Store::theme_has_stored_source() );

	if ( $settled ) {
		$cache[ $slug ] = $ours;
	}
	return $ours;
}

/**
 * The OTHER theme's slug when the stored content belongs to a theme that is
 * not the active one, else ''. This is the single predicate every protection
 * below is written against.
 *
 * With no usable ownership record the data itself is judged (see
 * clara_ve_data_theme_verdict) — and only POSITIVE evidence backfills
 * ownership, so the cheap slug comparison covers every switch from then on.
 * Returns the sentinel '(unknown)' for content that demonstrably belongs
 * elsewhere but never recorded where it came from.
 *
 * A record naming a theme that is not a converted theme at all is treated as
 * absent, and re-pointed at the active theme when the data matches it. That
 * record can only have been written by accident — the plugin was activated
 * while a stock theme was still active — and honouring it would lock the real
 * theme out of its own content, which is exactly the failure this guard
 * exists to prevent, inverted.
 *
 * @return string
 */
/**
 * Whether any UNSCOPED (pre-1.15) stored content still exists.
 *
 * This is what makes the foreign-data guard below meaningful. Since per-theme
 * scoping every source, pseudo style and history row is namespaced
 * (`clara_ve_source__{theme}__{key}`), so the active theme can only ever read
 * its OWN — another theme's content is unreachable by construction and cannot
 * leak into this design. Only a legacy unscoped row, which any theme would
 * read, can.
 *
 * Without this check the guard fires on a state that is now completely normal:
 * a second or third converted theme installed alongside the first, with its
 * own namespace and nothing of its own in it yet. Verified live on a
 * three-theme install — the third theme was refused its own import, told its
 * content "belongs to Sonic", while every stored row on the site was properly
 * scoped and no legacy row existed at all.
 *
 * @return bool
 */
function clara_ve_legacy_data_exists() {
	static $exists = null;
	if ( null !== $exists ) {
		return $exists;
	}
	global $wpdb;
	$found  = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE ( option_name = %s OR ( option_name LIKE %s AND option_name NOT LIKE %s ) )
			 LIMIT 1",
			CLARA_VE_OPTION,
			$wpdb->esc_like( 'clara_ve_source__' ) . '%',
			$wpdb->esc_like( 'clara_ve_source__' ) . '%' . $wpdb->esc_like( '__' ) . '%'
		)
	);
	$exists = ! empty( $found );
	return $exists;
}

function clara_ve_foreign_data() {
	$owner  = clara_ve_data_owner();
	$active = get_stylesheet();
	if ( $owner === $active ) {
		return '';
	}
	// Nothing unscoped left to leak: every row is namespaced per theme, so the
	// active theme reads only its own and a stale ownership record naming some
	// other theme is just bookkeeping. Blocking here is what stopped a third
	// theme from importing its own content on a perfectly healthy install.
	if ( ! clara_ve_legacy_data_exists() ) {
		return '';
	}
	$junk_record = ( '' !== $owner && ! clara_ve_theme_is_converted( $owner ) );
	if ( '' === $owner || $junk_record ) {
		$verdict = clara_ve_data_theme_verdict();
		if ( 'match' === $verdict ) {
			// Positive evidence for the active theme: claim (or re-claim off a
			// junk record) so this resolves once rather than every request.
			update_option( CLARA_VE_OWNER_OPTION, $active, false );
			return '';
		}
		if ( 'unknown' === $verdict ) {
			return ''; // no opinion, and no claim — never block on ignorance
		}
		$owner = $junk_record ? $owner : '(unknown)';
	}
	/**
	 * Bypass the foreign-data guard.
	 *
	 * For a developer who knows both themes share a markup contract and wants
	 * the old behavior. Returning true restores the pre-guard semantics
	 * wholesale, including the destructive ones.
	 *
	 * @param bool   $ignore Default false.
	 * @param string $owner  The theme slug that owns the stored content.
	 */
	return apply_filters( 'clara_ve_ignore_theme_owner', false, $owner ) ? '' : $owner;
}

/**
 * Hand the stored content over to the active theme — the explicit act that
 * ownership will not perform on its own. Only ever reached through the
 * nonced link in the notice below, and deliberately narrow: it re-points the
 * ownership record and nothing else. No data is moved, renamed or deleted,
 * so it is reversible by handing back.
 *
 * It does NOT make the content correct for the new theme: the sources are
 * still the other design's markup. It exists for the one legitimate case —
 * a rebuilt theme with the same markup contract under a new slug — and the
 * notice says so.
 *
 * @return void
 */
function clara_ve_handover_owner() {
	if ( ! current_user_can( 'switch_themes' ) || ! check_admin_referer( 'clara_ve_handover' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'visual-edit-lite' ) );
	}
	// Refused when the content demonstrably is not this theme's. Taking
	// ownership only lifts the guard — it cannot make another design's markup
	// correct — so on a genuine collision it would hand the front page back to
	// the very override that breaks it, which is the failure this whole guard
	// exists to prevent. The notice does not offer the button in that case;
	// this is the same check for a replayed or hand-typed URL.
	if ( 'mismatch' === clara_ve_data_theme_verdict() ) {
		wp_die(
			esc_html__( 'This content was written for a different design — its own assets and markers are missing from the active theme, so taking ownership would only put the wrong page back on the site. Activate the theme it belongs to, or give this theme its own WordPress install.', 'visual-edit-lite' ),
			esc_html__( 'Visual Edit Lite', 'visual-edit-lite' ),
			array( 'back_link' => true )
		);
	}
	update_option( CLARA_VE_OWNER_OPTION, get_stylesheet(), false );
	wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
	exit;
}
add_action( 'admin_post_clara_ve_handover', 'clara_ve_handover_owner' );

/**
 * Say, on every admin screen, that the stored content belongs to a different
 * theme — and what the two ways out are. Unmissable on purpose: while this
 * state lasts the editor shows the active theme's own files rather than the
 * stored content, and saving is refused, so an owner who is not told will
 * conclude the plugin has lost their site.
 */
function clara_ve_foreign_data_notice() {
	$owner = clara_ve_foreign_data();
	if ( '' === $owner || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	$owner_theme = wp_get_theme( $owner );
	$owner_name  = ( '(unknown)' === $owner )
		// Content that predates the ownership record: it demonstrably is not
		// this theme's (its own assets or anchors are missing) but nothing
		// wrote down where it came from.
		? __( 'another theme', 'visual-edit-lite' )
		: ( $owner_theme->exists() ? $owner_theme->get( 'Name' ) : $owner );
	$active_name = wp_get_theme()->get( 'Name' );

	echo '<div class="notice notice-error"><p><strong>'
		. esc_html__( 'Visual Edit: this site\'s editable content belongs to a different theme.', 'visual-edit-lite' )
		. '</strong></p><p>'
		. sprintf(
			/* translators: 1: owning theme name, 2: active theme name */
			esc_html__( 'The stored pages, styles and history were created for %1$s, but %2$s is active. Editing is paused: the plugin will not serve one theme\'s markup through another\'s design, and will not overwrite it either. Nothing has been lost.', 'visual-edit-lite' ),
			'<strong>' . esc_html( $owner_name ) . '</strong>',
			'<strong>' . esc_html( $active_name ) . '</strong>'
		)
		. '</p><p>'
		. sprintf(
			/* translators: %s: owning theme name */
			esc_html__( 'This content predates per-theme storage, so it could not be filed under the theme that made it. Activate %s to carry on editing it — that also files it, and from then on both themes can hold their own content on this install side by side.', 'visual-edit-lite' ),
			'<strong>' . esc_html( $owner_name ) . '</strong>'
		)
		. '</p><p><a class="button" href="' . esc_url( admin_url( 'themes.php' ) ) . '">'
		. esc_html__( 'Themes', 'visual-edit-lite' ) . '</a>';

	// The handover offer is withheld on a genuine collision. It only lifts the
	// guard; it cannot make another design's markup correct — so here it would
	// hand the front page straight back to the override that breaks it. Offered
	// only where the data does NOT contradict this theme, which is the one case
	// it was written for: a rebuilt theme under a new slug, same markup.
	if ( 'mismatch' !== clara_ve_data_theme_verdict() ) {
		echo ' <a class="button button-link" href="'
			. esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clara_ve_handover' ), 'clara_ve_handover' ) ) . '">'
			. sprintf(
				/* translators: %s: active theme name */
				esc_html__( 'This content belongs to %s — take ownership', 'visual-edit-lite' ),
				esc_html( $active_name )
			)
			. '</a>';
	} else {
		echo '</p><p><em>'
			. esc_html__( 'There is no "take ownership" here on purpose: this content carries another design\'s own assets and markers, so claiming it would only put the wrong page back on the site.', 'visual-edit-lite' )
			. '</em>';
	}
	echo '</p></div>';
}
add_action( 'admin_notices', 'clara_ve_foreign_data_notice' );

/**
 * Stamp posts an OLDER import created before content carried a theme.
 *
 * clara_ve_theme_post_scope() excludes another theme's posts and keeps the
 * owner's own, and it tells them apart by the theme stamp. That works for
 * anything imported since stamping existed — and misfiles everything imported
 * before it, because an unstamped post looks exactly like one the owner wrote.
 * Measured on a real site: a converted theme's blog listed the PREVIOUS
 * theme's ten articles alongside its own six, and no amount of scoping could
 * help, because nothing in the database said whose they were.
 *
 * It is recoverable from disk. Every converted theme ships its own
 * `clara-content/posts.json`, so a published post whose slug AND title match
 * an entry there was imported by that theme — that is not a guess, it is the
 * manifest of what that import created. Anything that matches nothing stays
 * unstamped and keeps appearing everywhere, which is the right answer for a
 * post the owner actually wrote.
 *
 * Runs once. A post that already carries a stamp is never touched.
 */
function clara_ve_stamp_legacy_imported_posts() {
	if ( '1' === get_option( 'clara_ve_legacy_post_stamp_version' ) ) {
		return;
	}
	update_option( 'clara_ve_legacy_post_stamp_version', '1', false );

	foreach ( (array) wp_get_themes() as $slug => $theme ) {
		$file = trailingslashit( $theme->get_stylesheet_directory() ) . 'clara-content/posts.json';
		if ( ! is_readable( $file ) ) {
			continue;
		}
		$entries = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $entries ) ) {
			continue;
		}
		foreach ( $entries as $entry ) {
			$slug_of = isset( $entry['slug'] ) ? sanitize_title( $entry['slug'] ) : '';
			$title   = isset( $entry['title'] ) ? trim( (string) $entry['title'] ) : '';
			if ( '' === $slug_of || '' === $title ) {
				continue;
			}
			$found = get_posts(
				array(
					'post_type'     => 'post',
					'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future' ),
					'name'          => $slug_of,
					'numberposts'   => 1,
					'no_found_rows' => true,
				)
			);
			if ( ! $found ) {
				continue;
			}
			$post = $found[0];
			if ( '' !== (string) get_post_meta( $post->ID, CLARA_VE_PAGE_THEME_META, true ) ) {
				continue; // already filed
			}
			if ( trim( $post->post_title ) !== $title ) {
				continue; // same slug, different article — not ours to claim
			}
			update_post_meta( $post->ID, CLARA_VE_PAGE_THEME_META, sanitize_key( $slug ) );
			if ( '' === (string) get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true ) ) {
				update_post_meta(
					$post->ID,
					CLARA_VE_PAGE_KEY_META,
					sanitize_key( isset( $entry['key'] ) && $entry['key'] ? $entry['key'] : $slug_of )
				);
			}
		}
	}

	// Second pass: by TAXONOMY. A site keeps writing after its bundle was
	// made, so its newest articles are in no posts.json — and on a real site
	// four of them were left looking like the owner's own and went on
	// appearing in the next theme's blog. But they sit in the categories that
	// theme's bundle CREATED (`insights`, `strategy`, `case-notes`,
	// `campaigns`), which no other bundle declares. A post filed entirely
	// within one theme's own taxonomy is that theme's.
	//
	// Deliberately narrow: every one of the post's categories must belong to
	// that one theme, and no other installed bundle may claim any of them. A
	// post in a category the owner made themselves matches nothing and is left
	// alone, which is the right answer.
	$by_theme = array();
	foreach ( (array) wp_get_themes() as $slug => $theme ) {
		$file = trailingslashit( $theme->get_stylesheet_directory() ) . 'clara-content/terms.json';
		if ( ! is_readable( $file ) ) {
			continue;
		}
		$terms = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		foreach ( (array) $terms as $term ) {
			if ( isset( $term['slug'] ) && 'category' === ( isset( $term['taxonomy'] ) ? $term['taxonomy'] : 'category' ) ) {
				$by_theme[ sanitize_key( $slug ) ][] = sanitize_title( $term['slug'] );
			}
		}
	}
	if ( count( $by_theme ) < 1 ) {
		return;
	}
	$shared = array();
	foreach ( $by_theme as $a => $slugs ) {
		foreach ( $by_theme as $b => $other ) {
			if ( $a !== $b ) {
				$shared = array_merge( $shared, array_intersect( $slugs, $other ) );
			}
		}
	}
	foreach ( get_posts( array( 'post_type' => 'post', 'numberposts' => -1, 'post_status' => 'publish' ) ) as $post ) {
		if ( '' !== (string) get_post_meta( $post->ID, CLARA_VE_PAGE_THEME_META, true ) ) {
			continue;
		}
		$cats = wp_get_post_terms( $post->ID, 'category', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $cats ) || ! $cats || array_intersect( $cats, $shared ) ) {
			continue;
		}
		foreach ( $by_theme as $theme_slug => $slugs ) {
			if ( ! array_diff( $cats, $slugs ) ) {
				update_post_meta( $post->ID, CLARA_VE_PAGE_THEME_META, $theme_slug );
				break;
			}
		}
	}
}
add_action( 'init', 'clara_ve_stamp_legacy_imported_posts', 3 );

/**
 * Read every installed bundle's manifest of a given kind, and return what each
 * identifier is claimed by.
 *
 * The return shape is identifier => [theme, …] rather than identifier => theme
 * on purpose: two bundles claiming the same thing is not an error to resolve,
 * it is a fact the caller has to respect. Everything built on this treats a
 * contested identifier as belonging to nobody.
 *
 * @param string   $relative Path inside clara-content/.
 * @param callable $keys_of  Given one decoded entry, the identifiers it claims.
 * @return array<string,string[]>
 */
function clara_ve_bundle_claims( $relative, $keys_of ) {
	$claims = array();
	foreach ( (array) wp_get_themes() as $slug => $theme ) {
		$file = trailingslashit( $theme->get_stylesheet_directory() ) . 'clara-content/' . $relative;
		if ( ! is_readable( $file ) ) {
			continue;
		}
		$entries = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $entries ) ) {
			continue;
		}
		foreach ( $entries as $entry ) {
			foreach ( (array) call_user_func( $keys_of, $entry ) as $id ) {
				if ( '' !== (string) $id ) {
					$claims[ (string) $id ][] = sanitize_key( $slug );
				}
			}
		}
	}
	return $claims;
}

/**
 * The single theme claiming an identifier, or '' when nobody or more than one
 * does.
 *
 * @param array  $claims From clara_ve_bundle_claims().
 * @param string $id
 * @return string
 */
function clara_ve_sole_claimant( array $claims, $id ) {
	if ( ! isset( $claims[ $id ] ) ) {
		return '';
	}
	$themes = array_values( array_unique( $claims[ $id ] ) );
	return ( 1 === count( $themes ) ) ? $themes[0] : '';
}

/**
 * File the media and pages an import created before anything recorded which
 * theme created it.
 *
 * The sibling pass above does this for blog posts. Media and pages were left
 * out because nothing depended on knowing — until leaving a theme meant taking
 * its content with it, at which point unattributed images and pages are content
 * that can neither be parked nor purged, and would sit on the site forever with
 * no way to find out whose they are.
 *
 * Media is matched by **sha1**, which the bundle records per file and which
 * settles the question exactly rather than plausibly. Where a filename check
 * would have to guess about `1.webp`, identical bytes are identical bytes.
 *
 * The scan is confined to attachments under the import folder — either name,
 * see `clara_ve_import_dirs()` — for two
 * reasons: it is where every import writes, and it keeps a one-time migration
 * from hashing a media library of thousands of the owner's own photographs to
 * conclude, correctly, that none of them belong to a theme. An owner's upload
 * that predates stamping is genuinely unattributable, and saying so is the
 * honest result.
 *
 * An identifier two bundles both claim is left alone. For media that is not a
 * technicality: apply_media() deliberately lets two themes share one attachment
 * when the bytes match, so awarding it to whichever theme was scanned first
 * would mean deleting that theme also deletes an image the other one renders.
 *
 * @return void
 */
function clara_ve_stamp_legacy_media_and_pages() {
	if ( '1' === get_option( 'clara_ve_legacy_media_stamp_version' ) ) {
		return;
	}
	update_option( 'clara_ve_legacy_media_stamp_version', '1', false );

	$upload = wp_upload_dir();
	$base   = untrailingslashit( isset( $upload['basedir'] ) ? $upload['basedir'] : '' );

	$by_sha  = clara_ve_bundle_claims( 'media/index.json', function ( $entry ) {
		return isset( $entry['sha1'] ) ? array( strtolower( (string) $entry['sha1'] ) ) : array();
	} );
	$by_path = clara_ve_bundle_claims( 'media/index.json', function ( $entry ) {
		$paths = isset( $entry['file'] ) ? array( (string) $entry['file'] ) : array();
		foreach ( (array) ( isset( $entry['extra_files'] ) ? $entry['extra_files'] : array() ) as $extra ) {
			$paths[] = (string) $extra;
		}
		return $paths;
	} );

	if ( $by_sha || $by_path ) {
		$attachments = get_posts(
			array(
				'post_type'     => 'attachment',
				'post_status'   => 'any',
				'numberposts'   => -1,
				'fields'        => 'ids',
				'no_found_rows' => true,
			)
		);
		foreach ( $attachments as $id ) {
			if ( '' !== (string) get_post_meta( $id, CLARA_VE_PAGE_THEME_META, true ) ) {
				continue;
			}
			$relative = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( ! clara_ve_is_import_path( $relative ) ) {
				continue;
			}
			$theme = clara_ve_sole_claimant( $by_path, $relative );
			if ( '' === $theme ) {
				// A theme-namespaced path (ve-import/{theme}/…) never
				// matches the bundle's own name for the file, which is exactly
				// the collision case — so ask the bytes.
				$full = $base . '/' . $relative;
				if ( '' !== $base && is_readable( $full ) ) {
					$theme = clara_ve_sole_claimant( $by_sha, strtolower( (string) sha1_file( $full ) ) );
				}
			}
			if ( '' !== $theme ) {
				update_post_meta( $id, CLARA_VE_PAGE_THEME_META, $theme );
			}
		}
	}

	// Menus, by the navigation zone they are assigned to. WordPress keeps
	// nav_menu_locations in theme_mods_{stylesheet}, one row per theme,
	// whether or not that theme is running — so a menu sitting in a theme's
	// own zone is that theme's menu, stated by WordPress rather than inferred.
	// A menu in nobody's zone is left alone: an unassigned menu renders
	// nowhere and belongs to whoever made it.
	foreach ( array_keys( (array) wp_get_themes() ) as $theme_slug ) {
		$theme_slug = sanitize_key( $theme_slug );
		$mods       = (array) get_option( 'theme_mods_' . $theme_slug, array() );
		$locations  = isset( $mods['nav_menu_locations'] ) ? (array) $mods['nav_menu_locations'] : array();
		foreach ( $locations as $menu_id ) {
			$menu_id = (int) $menu_id;
			if ( ! $menu_id || '' !== (string) get_term_meta( $menu_id, Clara_VE_Theme_Registry::TERM_META, true ) ) {
				continue;
			}
			if ( is_nav_menu( $menu_id ) ) {
				update_term_meta( $menu_id, Clara_VE_Theme_Registry::TERM_META, $theme_slug );
			}
		}
	}

	// Categories and tags, by the bundle that declares them. Same contested
	// rule as everywhere else: a slug two bundles both ship — `news` is the
	// obvious one — belongs to neither as far as this is concerned, and a
	// category the owner made themselves matches nothing and is left alone.
	$by_term = clara_ve_bundle_claims( 'terms.json', function ( $entry ) {
		if ( ! isset( $entry['slug'] ) ) {
			return array();
		}
		$taxonomy = isset( $entry['taxonomy'] ) ? $entry['taxonomy'] : 'category';
		return array( $taxonomy . ':' . sanitize_title( $entry['slug'] ) );
	} );
	foreach ( array_keys( $by_term ) as $id ) {
		list( $taxonomy, $slug ) = array_pad( explode( ':', $id, 2 ), 2, '' );
		$theme = clara_ve_sole_claimant( $by_term, $id );
		if ( '' === $theme || '' === $slug ) {
			continue;
		}
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term || is_wp_error( $term ) ) {
			continue;
		}
		if ( '' === (string) get_term_meta( $term->term_id, Clara_VE_Theme_Registry::TERM_META, true ) ) {
			update_term_meta( $term->term_id, Clara_VE_Theme_Registry::TERM_META, $theme );
		}
	}

	// Redirects, by where they point. Each entry's value is a page key, and
	// that key's page already knows which theme it belongs to — so the
	// redirect belongs to the same one. An entry pointing at nothing findable
	// is left unattributed rather than assigned to the active theme, which
	// would hand one theme's old addresses to whichever theme happened to be
	// running when this ran.
	$live  = (array) get_option( Clara_VE_Redirects::OPTION, array() );
	$mine  = array();
	foreach ( $live as $from => $target ) {
		$key   = preg_replace( '/^post:/', '', (string) $target );
		$owner = '';
		foreach ( array( 'page', 'post' ) as $type ) {
			$found = get_posts(
				array(
					'post_type'     => $type,
					'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', CLARA_VE_PARKED_STATUS ),
					'numberposts'   => 1,
					'no_found_rows' => true,
					'meta_key'      => CLARA_VE_PAGE_KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'    => sanitize_key( $key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				)
			);
			if ( $found ) {
				$owner = (string) get_post_meta( $found[0]->ID, CLARA_VE_PAGE_THEME_META, true );
				break;
			}
		}
		if ( '' !== $owner ) {
			$mine[ $owner ][ $from ] = $target;
		}
	}
	foreach ( $mine as $theme_slug => $entries ) {
		$record = Clara_VE_Theme_Registry::get( $theme_slug );
		$known  = ( is_array( $record ) && isset( $record['redirects'] ) ) ? (array) $record['redirects'] : array();
		Clara_VE_Theme_Registry::remember( $theme_slug, array( 'redirects' => array_merge( $known, $entries ) ) );
	}

	// Pages, by the key they are bound to. A key is only decisive when one
	// installed bundle declares it — and "about" is exactly the key two
	// converted sites are most likely to share, so this resolves fewer pages
	// than it looks like it should, and that is the correct outcome rather
	// than a shortfall to work around.
	$by_key = clara_ve_bundle_claims( 'sources/index.json', function ( $entry ) {
		return isset( $entry['key'] ) ? array( sanitize_key( $entry['key'] ) ) : array();
	} );
	if ( ! $by_key ) {
		return;
	}
	$pages = get_posts(
		array(
			'post_type'     => 'page',
			'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'numberposts'   => -1,
			'meta_key'      => CLARA_VE_PAGE_KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'no_found_rows' => true,
		)
	);
	foreach ( $pages as $page ) {
		if ( '' !== (string) get_post_meta( $page->ID, CLARA_VE_PAGE_THEME_META, true ) ) {
			continue;
		}
		$theme = clara_ve_sole_claimant( $by_key, sanitize_key( (string) get_post_meta( $page->ID, CLARA_VE_PAGE_KEY_META, true ) ) );
		if ( '' !== $theme ) {
			update_post_meta( $page->ID, CLARA_VE_PAGE_THEME_META, $theme );
		}
	}
}
// Priority 3, alongside the post pass and before the registry backfill at 4.
add_action( 'init', 'clara_ve_stamp_legacy_media_and_pages', 3 );

/**
 * Move a pre-scoping install's data into the active theme's profile, once.
 *
 * Everything this plugin stores used to be keyed by page key alone, so one
 * install held one set of content and two converted themes fought over it.
 * Sources, pseudo styles, history rows and page bindings are now scoped by
 * stylesheet; this carries an existing install across, so nothing has to be
 * re-imported and nothing is read from the wrong profile afterwards.
 *
 * Only ever migrates to a theme the data DEMONSTRABLY belongs to — the
 * recorded owner, or the active theme when the data matches it. When the owner
 * cannot be established the legacy rows are left exactly where they are: the
 * readers still fall back to them (gated by the same foreign-data guard), so
 * such a site keeps working unchanged rather than having its content moved
 * under a guess.
 *
 * Renames rather than copies. A copy leaves the original as a second truth,
 * and the next unscoped read would find it again.
 *
 * @return void
 */
function clara_ve_migrate_to_scoped_data() {
	if ( get_option( 'clara_ve_scoped_data_version' ) === '1' ) {
		return;
	}
	global $wpdb;

	$owner = clara_ve_data_owner();
	if ( '' === $owner || ! clara_ve_theme_is_converted( $owner ) ) {
		// No usable record: only claim the active theme when the data itself
		// says so. 'unknown' and 'mismatch' both leave everything alone.
		if ( 'match' !== clara_ve_data_theme_verdict() ) {
			return;
		}
		$owner = get_stylesheet();
	}
	$owner = sanitize_key( $owner );

	$moved = array( 'sources' => 0, 'pseudo' => 0, 'pages' => 0, 'history' => 0 );

	// Sources and pseudo maps: every legacy row, including the two that had
	// their own names rather than the __key suffix.
	$legacy_rows = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		 WHERE option_name = 'clara_ve_front_source'
		    OR option_name = 'clara_ve_pseudo_css'
		    OR option_name LIKE 'clara\\_ve\\_source\\_\\_%'
		    OR option_name LIKE 'clara\\_ve\\_pseudo\\_\\_%'"
	);
	foreach ( (array) $legacy_rows as $name ) {
		if ( 'clara_ve_front_source' === $name ) {
			$key = CLARA_VE_DEFAULT_KEY;
			$new = 'clara_ve_source__' . $owner . '__' . $key;
			$bag = 'sources';
		} elseif ( 'clara_ve_pseudo_css' === $name ) {
			$key = CLARA_VE_DEFAULT_KEY;
			$new = 'clara_ve_pseudo__' . $owner . '__' . $key;
			$bag = 'pseudo';
		} elseif ( 0 === strpos( $name, 'clara_ve_source__' ) ) {
			$key = substr( $name, strlen( 'clara_ve_source__' ) );
			// Already scoped (this migration ran, or a save happened first).
			if ( 0 === strpos( $key, $owner . '__' ) ) {
				continue;
			}
			$new = 'clara_ve_source__' . $owner . '__' . $key;
			$bag = 'sources';
		} else {
			$key = substr( $name, strlen( 'clara_ve_pseudo__' ) );
			if ( 0 === strpos( $key, $owner . '__' ) ) {
				continue;
			}
			$new = 'clara_ve_pseudo__' . $owner . '__' . $key;
			$bag = 'pseudo';
		}
		if ( null !== get_option( $new, null ) ) {
			continue; // the scoped row already holds something — never clobber it
		}
		$value = get_option( $name, null );
		if ( null === $value ) {
			continue;
		}
		update_option( $new, $value, false );
		delete_option( $name );
		++$moved[ $bag ];
	}

	// Page bindings: stamp the owning theme on every bound page.
	$bound = get_posts(
		array(
			'post_type'     => 'page',
			'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
			'numberposts'   => -1,
			'fields'        => 'ids',
			'meta_query'    => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => CLARA_VE_PAGE_KEY_META,
					'compare' => 'EXISTS',
				),
				array(
					'key'     => CLARA_VE_PAGE_THEME_META,
					'compare' => 'NOT EXISTS',
				),
			),
			'no_found_rows' => true,
		)
	);
	foreach ( (array) $bound as $page_id ) {
		update_post_meta( (int) $page_id, CLARA_VE_PAGE_THEME_META, $owner );
		++$moved['pages'];
	}

	// History rows: prefix the stored page_key, skipping any already prefixed.
	$table = $wpdb->prefix . 'clara_ve_history';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		$moved['history'] = (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET page_key = CONCAT(%s, page_key) WHERE page_key NOT LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$owner . '__',
				$wpdb->esc_like( $owner . '__' ) . '%'
			)
		);
	}

	update_option( 'clara_ve_owner_theme', $owner, false );
	update_option( 'clara_ve_scoped_data_version', '1', false );
	update_option(
		'clara_ve_scoped_data_migration',
		array_merge( $moved, array( 'theme' => $owner, 'at' => gmdate( 'c' ) ) ),
		false
	);
}
add_action( 'init', 'clara_ve_migrate_to_scoped_data', 2 );

/**
 * Register one nav menu location per declared menu zone. Nothing declared —
 * nothing registered: an unregistered location cannot be assigned, which is
 * how "menu management is off" stays visible in wp-admin instead of being a
 * location that exists but drives no markup.
 *
 * Priority 11: the theme's functions.php has loaded by after_setup_theme, so
 * its contract filter is in place; 11 keeps this after the theme's own setup.
 */
function clara_ve_register_nav_location() {
	$seen = array();
	foreach ( clara_ve_theme_contract()['menus'] as $zone ) {
		if ( isset( $seen[ $zone['location'] ] ) ) {
			continue;
		}
		$seen[ $zone['location'] ] = true;
		register_nav_menu( $zone['location'], $zone['label'] );
	}
}
add_action( 'after_setup_theme', 'clara_ve_register_nav_location', 11 );

/**
 * Keep the active theme's record current while it can still be read.
 *
 * A theme's contract is editable — a nav zone gets added, a key renamed — and
 * the record is what the plugin will have to work from once the theme is
 * deactivated. Refreshing only at import time would freeze it at whatever was
 * true then, and the discrepancy would surface as a navigation zone that
 * cannot be restored, long after the edit that caused it.
 *
 * admin_init rather than init: this is bookkeeping for the owner's benefit and
 * has no business costing a visitor anything.
 */
add_action( 'admin_init', array( 'Clara_VE_Theme_Registry', 'refresh_active' ) );

// Priority 4: after clara_ve_stamp_legacy_imported_posts() at 3, so a theme
// whose only remaining trace is stamped pages is recognised by the backfill.
add_action( 'init', array( 'Clara_VE_Theme_Registry', 'backfill' ), 4 );

/**
 * The status parked content holds. Registered on every request, front end
 * included: a status WordPress does not know about makes the posts carrying it
 * unqueryable rather than hidden, and restoring them would have nothing to
 * restore from.
 *
 * internal => true is what keeps it out of the Pages and Posts screens: with
 * it, show_in_admin_all_list and show_in_admin_status_list default to false, so
 * the parked posts are subtracted from "All" and no "Parked (9)" link appears
 * beside it. Both are set explicitly anyway — a default that is right today is
 * still a default.
 */
function clara_ve_register_parked_status() {
	register_post_status(
		CLARA_VE_PARKED_STATUS,
		array(
			'label'                     => _x( 'Parked with its theme', 'post status', 'visual-edit-lite' ),
			'public'                    => false,
			'internal'                  => true,
			'exclude_from_search'       => true,
			'show_in_admin_all_list'    => false,
			'show_in_admin_status_list' => false,
		)
	);
}
add_action( 'init', 'clara_ve_register_parked_status', 1 );

// Query filters that make a parked theme's images, categories and menus absent
// rather than merely inert. Registered unconditionally; every one of them is a
// cheap no-op while nothing is parked.
Clara_VE_Theme_Park::init();

// Record what a converted theme's tenure produced, so leaving it can take its
// world with it. Creation only — see stamp_on_create().
add_action( 'transition_post_status', array( 'Clara_VE_Theme_Registry', 'stamp_on_create' ), 10, 3 );
add_action( 'add_attachment', array( 'Clara_VE_Theme_Registry', 'stamp_post' ) );
add_action( 'created_term', array( 'Clara_VE_Theme_Registry', 'stamp_term' ), 10, 3 );

/**
 * Say in wp-admin what the theme did not declare and what is therefore off.
 * Shown exactly where the absence bites: the Menus screen (a menu built here
 * would render nowhere) and the Visual Editor screen.
 */
function clara_ve_contract_notice() {
	if ( ! function_exists( 'get_current_screen' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}
	// Appearance → Menus is deliberately NOT the only surface, and cannot be
	// the main one: with no contract the theme registers no menu locations, so
	// WordPress core short-circuits that screen with its own "theme does not
	// support navigation menus" message before admin_notices ever fires
	// (verified live on a block theme). The screens that do reach the owner are
	// the plugin's own pages and the post-activation theme screens — which is
	// also where they are when they first wonder why menus do nothing.
	$screen_id = (string) $screen->id;
	$relevant  = 'nav-menus' === $screen_id
		|| 'themes' === $screen_id
		|| false !== strpos( $screen_id, 'visual-edit' )
		|| false !== strpos( $screen_id, '-setup' );
	if ( ! $relevant || ! current_user_can( 'edit_theme_options' ) ) {
		return;
	}
	if ( clara_ve_theme_contract()['menus'] ) {
		return;
	}

	// On a block theme this is not a misconfiguration, it is the correct
	// state: menus there are core/navigation blocks, edited in the Site
	// Editor, and this plugin deliberately does not touch them — navigation
	// is one of the block types it refuses to address at all. Warning about a
	// filter such a theme has no reason to implement sends the owner to fix
	// something that is not broken, and hides where menus actually live. So
	// the block-theme message points at the Site Editor and does not shout.
	if ( ! clara_ve_active_theme_is_ours() ) {
		if ( false === strpos( $screen_id, 'visual-edit' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Menus are part of this theme.', 'visual-edit-lite' ),
			esc_html__( 'This theme keeps its navigation in WordPress\'s own navigation blocks rather than in markup this editor owns, so menus are edited alongside the rest of the theme.', 'visual-edit-lite' ),
			esc_url( admin_url( 'site-editor.php' ) ),
			esc_html__( 'Open the Site Editor', 'visual-edit-lite' )
		);
		return;
	}

	printf(
		'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
		esc_html__( 'Visual Edit: menu management is off.', 'visual-edit-lite' ),
		esc_html__( 'The active theme does not declare its navigation zones (the clara_ve_theme_contract filter), so the plugin cannot know which markup is a menu — a menu created here would not appear on the site. Themes generated by the converter declare this in inc/visual-edit.php.', 'visual-edit-lite' )
	);
}
add_action( 'admin_notices', 'clara_ve_contract_notice' );

/**
 * The active theme's front-page pattern, fully qualified.
 *
 * Prefers "{stylesheet}/front-page-original", which is what a theme following
 * WordPress's own namespacing convention registers. Falls back to scanning the
 * registry for any pattern whose name ends in the same slug, so a theme that
 * namespaces its patterns differently from its directory name still resolves —
 * and, more to the point, so this plugin is not welded to one theme slug.
 *
 * Only cached once a real match is found: called before init:9 (when WordPress
 * auto-registers theme patterns) nothing is registered yet, and caching a miss
 * would freeze the wrong answer for the rest of the request.
 *
 * @return string
 */
function clara_ve_pattern_name() {
	static $resolved = null;
	if ( null !== $resolved ) {
		return $resolved;
	}

	$registry  = WP_Block_Patterns_Registry::get_instance();
	$preferred = get_stylesheet() . '/' . CLARA_VE_PATTERN_SLUG;
	if ( $registry->is_registered( $preferred ) ) {
		$resolved = $preferred;
		return $resolved;
	}

	$suffix = '/' . CLARA_VE_PATTERN_SLUG;
	foreach ( $registry->get_all_registered() as $pattern ) {
		$name = isset( $pattern['name'] ) ? (string) $pattern['name'] : '';
		if ( $name && substr( $name, -strlen( $suffix ) ) === $suffix ) {
			$resolved = $name;
			return $resolved;
		}
	}

	return $preferred;
}

/**
 * Override the theme's front-page pattern with the edited source from the
 * database (when present), then swap the hardcoded nav for the WP menu
 * (when one is assigned). Runs after theme pattern auto-registration.
 */
function clara_ve_override_front_pattern() {
	// The stored front source belongs to another theme — leave this theme's
	// own pattern registered exactly as it ships. This is the protection that
	// matters most: without it the home page is the first and worst casualty
	// of two converted themes on one install, rendering theme A's complete
	// inline markup inside theme B's template and stylesheet.
	if ( '' !== clara_ve_foreign_data() ) {
		return;
	}

	$registry     = WP_Block_Patterns_Registry::get_instance();
	$pattern_name = clara_ve_pattern_name();

	if ( ! $registry->is_registered( $pattern_name ) ) {
		return;
	}

	$pattern = $registry->get_registered( $pattern_name );
	$html    = Clara_VE_Source_Store::get_resolved_source();

	if ( null === $html ) {
		// No edits saved yet — the theme pattern file stays the source of truth,
		// but the nav swap still applies to it. Untokenized on the way out for
		// the same reason Clara_VE_Source_Store::get_current_source()'s
		// fallbacks are: a theme file may legitimately carry the theme-URI
		// token, and this is the parallel read path that would otherwise render
		// it literally. A no-op on content that has no token.
		$html = Clara_VE_Source_Store::untokenize( clara_ve_extract_pattern_html( $pattern['content'] ) );
	}

	$html = Clara_VE_Front_Nav::apply( $html );

	// The key marker is what Clara_VE_Tokens (and the header nav swap) look for
	// before doing any work — without it the front page was the one place where
	// [wp-posts] and friends silently rendered as literal text, because every
	// other key gets its marker from sync_to_page()/sync_to_template_part() and
	// the front page goes through neither. Added at registration rather than
	// stored in the source, so it cannot be edited away.
	$pattern['content'] = "<!-- wp:html -->\n<!-- clara-ve-key: " . CLARA_VE_DEFAULT_KEY . " -->\n" . $html . "\n<!-- /wp:html -->";
	register_block_pattern( $pattern_name, $pattern );
}
add_action( 'init', 'clara_ve_override_front_pattern', 20 );

/**
 * Strip the wp:html wrapper from registered pattern content, leaving raw HTML.
 *
 * @param string $content Pattern content.
 * @return string
 */
function clara_ve_extract_pattern_html( $content ) {
	$content = preg_replace( '/^\s*<!--\s*wp:html\s*-->/', '', $content );
	// The key marker too: registered pattern content is re-extracted on the
	// no-edits-saved path in get_current_source(), and without this the marker
	// would be folded into whatever gets saved as the page's own source.
	$content = preg_replace( '/^\s*<!--\s*clara-ve-key:[^>]*-->/', '', $content );
	$content = preg_replace( '/<!--\s*\/wp:html\s*-->\s*$/', '', $content );
	return trim( $content );
}

/**
 * Register the post meta that marks a WP Page as "visual-edit enabled" and
 * points it at its own keyed source (see includes/class-source-store.php).
 * Not exposed over the public REST API — it's purely an internal lookup key.
 */
function clara_ve_register_page_key_meta() {
	register_post_meta(
		'page',
		CLARA_VE_PAGE_KEY_META,
		array(
			'single'       => true,
			'type'         => 'string',
			'show_in_rest' => false,
		)
	);
}
add_action( 'init', 'clara_ve_register_page_key_meta' );

/**
 * Which visual-edit key (if any) applies to the currently queried object —
 * CLARA_VE_DEFAULT_KEY for the front page, a tagged Page's own key, or null
 * for anything else (a normal Gutenberg page, post, archive, etc.). Shared
 * by the bridge enqueue and the pseudo-CSS printer so both agree on which
 * page is being edited and which root selector applies to it.
 *
 * The header/footer template parts have no page/URL of their own to preview
 * on — editing them previews on a REAL tagged page's URL instead (see
 * class-rest.php::list_visual_pages()), with ?clara_ve_key=header|footer
 * overriding what would otherwise be resolved from that URL. Only honored
 * inside an authorized edit-mode preview — never affects a real visitor.
 *
 * @return string|null
 */
function clara_ve_current_key() {
	// Block mode: the key IS the post. None of the raw-HTML keys below exists
	// on such a theme — the front-page pattern is not registered, there is no
	// article template and no 404 canvas — and answering with one anyway is
	// what used to put an empty canvas in front of an owner on a page where
	// nothing could ever have been saved to it.
	if ( ! clara_ve_active_theme_is_ours() ) {
		$queried = get_queried_object();
		if ( ! ( $queried instanceof WP_Post ) ) {
			return null;
		}
		$block_key = Clara_VE_Source_Store::block_key( $queried );
		return '' !== $block_key ? $block_key : null;
	}

	if ( clara_ve_is_edit_preview() && isset( $_GET['clara_ve_key'] ) ) {
		$override = sanitize_key( wp_unslash( $_GET['clara_ve_key'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $override, array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ), true )
			|| clara_ve_contract_part( $override ) ) {
			return $override;
		}
	}
	if ( is_front_page() ) {
		return CLARA_VE_DEFAULT_KEY;
	}
	// Checked before the page/post branches below because a 404 IS the absence
	// of any of them: nothing was queried, so there is no post to identify the
	// key from — the key comes from the response being a 404 at all.
	if ( is_404() ) {
		return CLARA_VE_404_KEY;
	}
	// EVERY single post resolves to the one shared article template. Unlike a
	// tagged Page — where the URL identifies the thing being edited — a post's
	// URL identifies content the editor never touches: what is editable is the
	// layout wrapped around it, which is the same layout for all posts. So
	// opening any article in the preview edits the template, and it genuinely
	// does not matter which article you opened. This is also what lets a click
	// on an article link keep editing instead of dead-ending on "you left the
	// page you were editing".
	if ( is_singular( 'post' ) ) {
		return CLARA_VE_ARTICLE_KEY;
	}
	if ( is_page() ) {
		$queried = get_queried_object();
		if ( $queried instanceof WP_Post ) {
			$key = get_post_meta( $queried->ID, CLARA_VE_PAGE_KEY_META, true );
			if ( $key ) {
				return sanitize_key( $key );
			}
		}
	}
	return null;
}

/**
 * bridge.js's path-stamping root selector for a given key. The front page
 * starts at the site-blocks wrapper; bridge.js falls back to body when core
 * closes that wrapper after a shared template part and emits the pattern as
 * body-level siblings. A tagged page owns only its content area — the theme's
 * shared header/footer template parts must never become page-source paths.
 * Editing a template part itself scopes the root to that one wrapper.
 *
 * @param string $key
 * @return string
 */
function clara_ve_root_selector_for_key( $key ) {
	if ( CLARA_VE_DEFAULT_KEY === $key ) {
		return '.wp-site-blocks';
	}
	if ( CLARA_VE_HEADER_KEY === $key || CLARA_VE_FOOTER_KEY === $key ) {
		// wp:template-part wraps its own content in an auto-generated outer
		// element (tagName="header"/"footer", class "wp-block-template-part")
		// — our own <header class="site-header">/<footer class="site-footer">
		// sits INSIDE that as its one child. The stored source is that whole
		// inner element (outer tag included), so the root must be the WP
		// wrapper, one level up — matching "source == root's innerHTML" the
		// same way every other key's root/source pairing already works.
		$tag = ( CLARA_VE_HEADER_KEY === $key ) ? 'header' : 'footer';
		return '.wp-site-blocks > ' . $tag . '.wp-block-template-part, body > ' . $tag . '.wp-block-template-part';
	}
	if ( CLARA_VE_ARTICLE_KEY === $key || CLARA_VE_404_KEY === $key ) {
		// Same reasoning as the header/footer branch above — both the article
		// layout and the 404 are template parts too, so the root is
		// WordPress's own wrapper element and the stored source is its
		// innerHTML. They share a selector safely because they never render
		// on the same request: one is a single post, the other is the absence
		// of any post.
		return '.wp-site-blocks > main.wp-block-template-part, body > main.wp-block-template-part';
	}
	// A chrome VARIANT part the theme declares (header-2, footer-2, …) —
	// same shape as the standard pair: theme.json declares its area, so
	// WordPress wraps it in that area's tag.
	$part = clara_ve_contract_part( $key );
	if ( $part ) {
		return '.wp-site-blocks > ' . $part['area'] . '.wp-block-template-part, body > ' . $part['area'] . '.wp-block-template-part';
	}
	// Core can render a template part inside .wp-site-blocks but place the
	// following main block beside it. Keep the conventional nested selector
	// first, then fall back to the semantic post-content wrapper so a converted
	// page remains editable in both valid block-template DOM shapes.
	return '.wp-site-blocks .wp-block-post-content, main .wp-block-post-content';
}

/**
 * True when this request is an authorized edit-mode preview of the front page.
 */
function clara_ve_is_edit_preview() {
	if ( ! isset( $_GET['clara_edit'] ) || '1' !== $_GET['clara_edit'] ) {
		return false;
	}
	if ( ! clara_ve_user_can_edit() ) {
		return false;
	}
	return wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_clara_ve'] ?? '' ) ), 'clara_ve_preview' );
}

/**
 * Fully suppress the admin bar in the edit preview. `show_admin_bar( false )`
 * from wp_enqueue_scripts runs too late — `_wp_admin_bar_init` has already
 * hooked the `html { margin-top: 32px }` bump onto wp_head, leaving a bar-height
 * gap of body background at the top of the preview even though the bar itself is
 * hidden. Filtering on template_redirect (before priority 0) prevents the bump.
 */
function clara_ve_suppress_admin_bar_in_preview() {
	if ( clara_ve_is_edit_preview() ) {
		add_filter( 'show_admin_bar', '__return_false' );
	}
}
add_action( 'template_redirect', 'clara_ve_suppress_admin_bar_in_preview', -1 );

/**
 * Cache-busting version for one of this plugin's own asset files.
 *
 * CLARA_VE_VERSION alone was wrong for this, and wrong in the way that wastes
 * an afternoon: the constant only moves when someone remembers to move it, so
 * three consecutive fixes to bridge.js and editor.js all shipped behind
 * `?ver=1.13.0` and every browser that had already loaded the page kept running
 * the old files. The bug looks exactly like a bug in the fix — it reproduces
 * for the person who has the editor open, and not in a fresh profile, which is
 * where it tends to get tested.
 *
 * The file's own mtime cannot be forgotten. Falls back to the constant if the
 * file is unreadable (a packaging accident should not take the assets down).
 *
 * @param string $relative Path under the plugin directory, e.g. 'assets/editor.js'.
 * @return string
 */
function clara_ve_asset_version( $relative ) {
	$path = CLARA_VE_DIR . ltrim( $relative, '/' );
	$time = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	return $time ? (string) $time : CLARA_VE_VERSION;
}

/**
 * Inject the edit bridge into the front page when previewing in edit mode.
 * The bridge is deferred and enqueued at the earliest priority so it stamps
 * source paths on the pristine DOM before any of the page's own deferred
 * scripts mutate it.
 */
function clara_ve_enqueue_bridge() {
	$key = clara_ve_current_key();
	if ( null === $key || ! clara_ve_is_edit_preview() ) {
		return;
	}

	show_admin_bar( false );

	wp_enqueue_style( 'clara-ve-bridge', CLARA_VE_URL . 'assets/bridge.css', array(), clara_ve_asset_version( 'assets/bridge.css' ) );
	wp_enqueue_script( 'clara-ve-bridge', CLARA_VE_URL . 'assets/bridge.js', array(), clara_ve_asset_version( 'assets/bridge.js' ), array( 'strategy' => 'defer', 'in_footer' => false ) );
	// The declared zones travel to the bridge so it can mark menu-managed
	// markup on ANY page and report WHICH zone (and so which location) a
	// clicked link belongs to. Only zones whose location actually has a menu
	// assigned are sent: an unassigned zone's links are still the page's own
	// editable markup, not menu items.
	$menu_zones = array();
	foreach ( Clara_VE_Front_Nav::zones() as $zone ) {
		if ( Clara_VE_Front_Nav::is_menu_assigned( $zone['location'] ) ) {
			$menu_zones[] = array(
				'selector' => $zone['selector'],
				'location' => $zone['location'],
			);
		}
	}
	$block_post = Clara_VE_Source_Store::block_key_post_id( $key );

	wp_add_inline_script(
		'clara-ve-bridge',
		'window.claraVeBridgeConfig = ' . wp_json_encode(
			array(
				'menuManaged'  => Clara_VE_Front_Nav::is_menu_assigned(),
				'menuZones'    => $menu_zones,
				'pageKey'      => $key,
				'rootSelector' => clara_ve_root_selector_for_key( $key ),
				// On a block page the bridge must not stamp positional paths
				// of its own: the addresses are already on the elements, put
				// there by the server, and an element the server did not stamp
				// is one no patch can reach. Telling the bridge which mode it
				// is in is what stops it inventing addresses for the rest.
				'blockMode'    => (bool) $block_post,
				'blockPost'    => $block_post ? $block_post : null,
			)
		) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_bridge', 1 );

/**
 * Drive "play automatically on scroll" videos (the `scroll-video` class, set
 * via the editor's video panel) on the PUBLIC site — a small, generic
 * IntersectionObserver play-once behavior so the option works on any page,
 * independent of whatever script the original static site did or didn't ship.
 * Skipped in the edit preview (the script itself also bails on clara_edit=1);
 * harmless if a theme already animates the same class (play() is idempotent).
 */
function clara_ve_enqueue_scroll_video() {
	if ( is_admin() || clara_ve_is_edit_preview() ) {
		return;
	}
	wp_enqueue_script( 'clara-ve-scroll-video', CLARA_VE_URL . 'assets/scroll-video.js', array(), clara_ve_asset_version( 'assets/scroll-video.js' ), array( 'strategy' => 'defer' ) );
}
add_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_scroll_video', 5 );

/**
 * Drive the "Load more" button under a [wp-posts] listing.
 *
 * Enqueued site-wide, like scroll-video.js above and for the same reason: the
 * page that needs it is not known at wp_enqueue_scripts time, and enqueueing
 * later — during block rendering, when the button is finally in hand — is too
 * late for a handle to be printed at all. The script is a few kilobytes and
 * does nothing on a page with no button, which is a far better trade than a
 * feature that silently fails to load.
 *
 * Skipped in the edit preview. The button stays visible there so it can be
 * moved and restyled, but pressing it would append cards the stored source
 * does not have, shifting every following element's path under the editor.
 */
function clara_ve_enqueue_load_more() {
	if ( is_admin() || clara_ve_is_edit_preview() ) {
		return;
	}
	wp_enqueue_script( 'clara-ve-load-more', CLARA_VE_URL . 'assets/load-more.js', array(), clara_ve_asset_version( 'assets/load-more.js' ), array( 'strategy' => 'defer' ) );
	wp_add_inline_script(
		'clara-ve-load-more',
		'window.claraVeLoadMore = ' . wp_json_encode( array( 'endpoint' => rest_url( 'clara-ve/v1/posts' ) ) ) . ';',
		'before'
	);
}
add_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_load_more', 5 );

/**
 * Inline submit for connected forms — see assets/form-submit.js.
 *
 * Enqueued site-wide for the same reason load-more is: which page carries a
 * connected form is not known at wp_enqueue_scripts time, and enqueueing during
 * render is too late for the handle to print at all.
 *
 * Skipped in the edit preview: submitting a form there would post a real
 * submission (and subscribe a real address) from what is meant to be a canvas.
 */
function clara_ve_enqueue_form_submit() {
	if ( is_admin() || clara_ve_is_edit_preview() ) {
		return;
	}
	wp_enqueue_style( 'clara-ve-forms', CLARA_VE_URL . 'assets/forms.css', array(), clara_ve_asset_version( 'assets/forms.css' ) );
	wp_enqueue_script( 'clara-ve-form-submit', CLARA_VE_URL . 'assets/form-submit.js', array(), clara_ve_asset_version( 'assets/form-submit.js' ), array( 'strategy' => 'defer' ) );
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
add_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_form_submit', 5 );

/**
 * Print the ::before ornament CSS layer on the front page. Rules are keyed by
 * structural element paths and compiled to :nth-child selectors, so the saved
 * markup itself stays byte-identical.
 */
function clara_ve_print_pseudo_css() {
	$key = clara_ve_current_key();
	if ( null === $key ) {
		return;
	}
	$map = Clara_VE_Pseudo_Store::get( $key );
	if ( ! $map ) {
		return;
	}
	$root = clara_ve_root_selector_for_key( $key );
	$css  = '';
	foreach ( $map as $id => $pseudos ) {
		if ( ! preg_match( '/^path(-\d+)+$/', (string) $id ) || ! is_array( $pseudos ) ) {
			continue;
		}
		$selector = $root;
		foreach ( explode( '-', substr( (string) $id, 5 ) ) as $index ) {
			$selector .= ' > *:nth-child(' . ( (int) $index + 1 ) . ')';
		}
		foreach ( array( 'before', 'after' ) as $which ) {
			if ( empty( $pseudos[ $which ] ) || ! is_array( $pseudos[ $which ] ) ) {
				continue;
			}
			$body = '';
			foreach ( $pseudos[ $which ] as $key => $value ) {
				$css_name = strtolower( preg_replace( '/([A-Z])/', '-$1', (string) $key ) );
				$body    .= $css_name . ':' . $value . ' !important;';
			}
			if ( '' !== $body ) {
				$css .= $selector . '::' . $which . '{' . $body . "}\n";
			}
		}
	}
	if ( $css ) {
		// Not esc_html(): entities are not decoded inside <style>, so a `>`
		// child selector would render as a literal &gt; and stop matching.
		echo '<style id="clara-ve-pseudo-css">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', 'clara_ve_print_pseudo_css', 60 );

/**
 * Compile the article template's typography specimen into real CSS for every
 * article's body.
 *
 * The problem this solves: the post body is authored in WordPress, so its
 * paragraphs, headings, quotes and lists are not in the template and cannot be
 * clicked. Styling them still has to be possible, and it has to apply to every
 * article at once.
 *
 * The specimen is the answer, and it needs no new editing machinery at all. The
 * template carries one sample of each element, marked `data-cve-specimen="p"`
 * and so on. Those samples are ordinary editable elements, and the editor
 * already persists typography changes as inline `style` attributes in the
 * saved source (bridge.js -> el.style.setProperty). So this function just reads
 * those inline styles back out and re-emits them as `.article-body p { … }`.
 * Style one sample paragraph, every article's paragraphs follow.
 *
 * The specimen itself never reaches a visitor — clara_ve_strip_specimen()
 * removes it outside the edit preview.
 *
 * Modelled on clara_ve_print_pseudo_css() above, including its property-name
 * normalisation, and hardened the same way: only well-formed declarations
 * survive, so an edited source can't inject a rule or escape the block.
 */
function clara_ve_print_article_css() {
	if ( ! is_singular( 'post' ) ) {
		return;
	}
	$source = Clara_VE_Source_Store::get_current_source( CLARA_VE_ARTICLE_KEY );
	if ( ! $source ) {
		return;
	}

	// Parsing the template on every article view would be wasteful, and the
	// source only changes on save. Cached under ONE fixed row that carries the
	// hash it was built from — keying the row name by hash instead would leave
	// a dead transient behind for every edit ever made.
	$hash   = md5( $source );
	$cached = get_transient( 'clara_ve_article_css' );
	if ( is_array( $cached ) && isset( $cached['hash'], $cached['css'] ) && $cached['hash'] === $hash ) {
		$css = $cached['css'];
	} else {
		$css = clara_ve_compile_specimen_css( $source );
		set_transient( 'clara_ve_article_css', array( 'hash' => $hash, 'css' => $css ), WEEK_IN_SECONDS );
	}

	if ( '' !== $css ) {
		// See the note above: escaping would break selectors inside <style>.
		echo '<style id="clara-ve-article-css">' . wp_strip_all_tags( $css ) . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
add_action( 'wp_head', 'clara_ve_print_article_css', 61 );

/**
 * @param string $source Article template HTML.
 * @return string CSS, or '' when the template has no styled specimen.
 */
function clara_ve_compile_specimen_css( $source ) {
	if ( false === strpos( $source, 'data-cve-specimen' ) ) {
		return '';
	}
	if ( ! preg_match_all( '/<([a-z0-9]+)\b[^>]*\bdata-cve-specimen="([a-z0-9]+)"[^>]*>/i', $source, $matches, PREG_SET_ORDER ) ) {
		return '';
	}

	$css = '';
	foreach ( $matches as $match ) {
		$element = strtolower( $match[2] );
		// The value names the element the rule targets, so it must look like
		// one — never a selector fragment out of an edited source.
		if ( ! preg_match( '/^(p|h2|h3|h4|blockquote|ul|ol|li|a|strong|em)$/', $element ) ) {
			continue;
		}
		if ( ! preg_match( '/\bstyle="([^"]*)"/i', $match[0], $style_attr ) ) {
			continue;
		}

		// The source is HTML, so a font stack arrives escaped —
		// `font-family: &quot;Noto Serif Display&quot;, Georgia, serif`. Its
		// entities carry SEMICOLONS, which the split below would read as
		// declaration ends: without decoding first, picking any quoted font in
		// the specimen compiled to the dead rule `font-family:&quot`. Decoding
		// before the guard below is also the safe order — an entity-encoded
		// brace becomes a real one here and is then rejected, rather than
		// slipping through as text.
		$declarations = html_entity_decode( $style_attr[1], ENT_QUOTES, get_bloginfo( 'charset' ) );

		$body = '';
		foreach ( explode( ';', $declarations ) as $declaration ) {
			if ( false === strpos( $declaration, ':' ) ) {
				continue;
			}
			list( $property, $value ) = array_map( 'trim', explode( ':', $declaration, 2 ) );
			$property                 = strtolower( $property );
			if ( ! preg_match( '/^[a-z-]+$/', $property ) || '' === $value ) {
				continue;
			}
			// Values go into a stylesheet verbatim, so anything that could end
			// the declaration, open a new rule, or pull in a remote resource is
			// dropped rather than escaped.
			if ( preg_match( '/[{}<>;]|@import|expression\s*\(|url\s*\(/i', $value ) ) {
				continue;
			}
			$body .= $property . ':' . $value . ';';
		}

		if ( '' !== $body ) {
			// !important so the compiled rule beats subpages.css's own
			// .article-body declarations, which are more specific than a bare
			// element selector and would otherwise always win.
			$css .= '.article-body ' . $element . '{' . str_replace( ';', ' !important;', rtrim( $body, ';' ) ) . " !important}\n";
		}
	}
	return $css;
}

/**
 * Keep the typography specimen out of the published article. It exists so the
 * body's styles can be edited by clicking; to a visitor it would just be a
 * duplicate paragraph, heading and quote sitting under the real article.
 */
function clara_ve_strip_specimen( $block_content, $block ) {
	if ( false === strpos( $block_content, CLARA_VE_SPECIMEN_START ) ) {
		return $block_content;
	}
	if ( clara_ve_is_edit_preview() ) {
		return $block_content;
	}
	// Bounded by explicit comment markers rather than by matching the wrapper's
	// own closing tag: the specimen is meant to be edited, so its nesting depth
	// is not ours to assume, and a tag-counting regex would quietly cut the
	// wrong amount the moment someone adds a wrapper inside it.
	$start = strpos( $block_content, CLARA_VE_SPECIMEN_START );
	$end   = strpos( $block_content, CLARA_VE_SPECIMEN_END, $start );
	if ( false === $end ) {
		return $block_content;
	}
	return substr( $block_content, 0, $start ) . substr( $block_content, $end + strlen( CLARA_VE_SPECIMEN_END ) );
}
add_filter( 'render_block_core/html', 'clara_ve_strip_specimen', 8, 2 );

/**
 * Admin-bar shortcut: "Visual Edit" next to Edit Site, shown on the front end
 * and in wp-admin for users who can edit.
 */
function clara_ve_admin_bar_link( $wp_admin_bar ) {
	if ( ! clara_ve_user_can_edit() ) {
		return;
	}
	$wp_admin_bar->add_node(
		array(
			'id'    => 'clara-visual-edit',
			'title' => '<span class="ab-icon dashicons dashicons-edit" style="top:2px"></span>' . esc_html__( 'Visual Edit Lite', 'visual-edit-lite' ),
			'href'  => admin_url( 'admin.php?page=visual-edit' ),
			'meta'  => array( 'title' => __( 'Open the front page in the visual editor', 'visual-edit-lite' ) ),
		)
	);
}
add_action( 'admin_bar_menu', 'clara_ve_admin_bar_link', 41 );

/**
 * Pages-list admin column showing each Page's visual-edit key, so an admin
 * can identify at a glance which pages are raw-HTML-editable through the
 * Visual Editor rather than opening each one to check.
 */
function clara_ve_add_pages_column( $columns ) {
	$columns['clara_ve_key'] = __( 'Visual Edit Lite', 'visual-edit-lite' );
	return $columns;
}
add_filter( 'manage_pages_columns', 'clara_ve_add_pages_column' );

function clara_ve_render_pages_column( $column, $post_id ) {
	if ( 'clara_ve_key' !== $column ) {
		return;
	}
	$key = get_post_meta( $post_id, CLARA_VE_PAGE_KEY_META, true );
	if ( ! $key ) {
		echo '&#8212;';
		return;
	}
	$edit_url = add_query_arg( 'key', rawurlencode( $key ), admin_url( 'admin.php?page=visual-edit' ) );
	echo '<a href="' . esc_url( $edit_url ) . '">' . esc_html( $key ) . '</a>';
}
add_action( 'manage_pages_custom_column', 'clara_ve_render_pages_column', 10, 2 );

/**
 * Escape hatch next to the "Visual Editor" column: opens the real block
 * editor on a tagged Page (its post_content is valid wp:html block markup,
 * so the block editor genuinely can open it), bypassing the takeover below.
 */
function clara_ve_add_bypass_row_action( $actions, $post ) {
	$key = get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true );
	if ( ! $key ) {
		return $actions;
	}
	$edit_url = add_query_arg( 'clara_ve_bypass', '1', get_edit_post_link( $post->ID, 'raw' ) );

	// The label names the SEO plugin when there is one, because this row action
	// is the ONLY route to its editor sidebar — the takeover below redirects the
	// normal edit screen into the Visual Editor, and nobody hunting for "where
	// do I set the Yoast description" reads "Edit HTML block" as the answer.
	// (The Visual Editor's own Search appearance panel links here too, so this
	// is the second of two doors, not the only one.)
	$host  = class_exists( 'Clara_VE_SEO' ) ? Clara_VE_SEO::host_label() : '';
	$label = $host
		/* translators: %s: SEO plugin name, e.g. Yoast SEO */
		? sprintf( __( 'Advanced (HTML block &amp; %s)', 'visual-edit-lite' ), $host )
		: __( 'Edit HTML block', 'visual-edit-lite' );

	$actions['clara_ve_bypass'] = '<a href="' . esc_url( $edit_url ) . '">' . wp_kses( $label, array() ) . '</a>';
	return $actions;
}
add_filter( 'page_row_actions', 'clara_ve_add_bypass_row_action', 10, 2 );

/**
 * Editor takeover: opening a tagged Page's normal edit screen redirects
 * straight into the Visual Editor instead of the block editor — the raw
 * markup inside its wp:html block isn't meant to be hand-edited there day to
 * day. ?clara_ve_bypass=1 (the row action above) escapes this for the rare
 * case someone actually needs the real block editor.
 */
function clara_ve_maybe_takeover_editor( $replace_editor, $post ) {
	if ( $replace_editor || ! ( $post instanceof WP_Post ) || 'page' !== $post->post_type ) {
		return $replace_editor;
	}
	if ( ! clara_ve_user_can_edit() ) {
		return $replace_editor;
	}
	if ( isset( $_GET['clara_ve_bypass'] ) && '1' === $_GET['clara_ve_bypass'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return $replace_editor;
	}

	// Only the request that actually RENDERS the editor. `replace_editor` is
	// applied twice: once by post.php inside `case 'edit'`, and once by
	// WP_Screen::get() for EVERY post.php request carrying ?post=ID, whatever
	// its action. Redirecting on the second swallowed the ones that are not
	// about editing at all — Trash, Restore and Delete Permanently returned
	// this editor instead of doing anything, so a page could only be removed
	// by switching the plugin off. `action=editpost` went the same way, which
	// is the save the "Edit HTML block" row action posts, so that door opened
	// and would not shut.
	//
	// post.php:130 is the only case that renders an editor; every other action
	// has to reach its own. An absent action is left alone deliberately:
	// post-new.php applies this filter with none, and a new page has no key.
	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' !== $action && 'edit' !== $action ) {
		return $replace_editor;
	}

	$key = get_post_meta( $post->ID, CLARA_VE_PAGE_KEY_META, true );
	if ( ! $key ) {
		return $replace_editor;
	}
	wp_safe_redirect( add_query_arg( 'key', rawurlencode( $key ), admin_url( 'admin.php?page=visual-edit' ) ) );
	exit;
}
add_filter( 'replace_editor', 'clara_ve_maybe_takeover_editor', 10, 2 );

/**
 * On standalone generated themes this plugin is an editor, not a runtime.
 * Remove only public delivery hooks; source/history/REST editing, the preview
 * bridge and every admin surface stay active. Old themes declare no support,
 * so their established behavior is byte-for-byte unchanged.
 */
function clara_ve_delegate_public_runtime_to_theme() {
	if ( ! clara_ve_theme_owns_public_runtime() ) {
		return;
	}

	remove_filter( 'render_block_core/html', array( 'Clara_VE_Front_Nav', 'maybe_apply_to_block' ), 9 );
	remove_filter( 'render_block_core/html', array( 'Clara_VE_Tokens', 'maybe_hydrate_block' ), 10 );
	remove_filter( 'render_block_core/html', 'clara_ve_strip_specimen', 8 );
	remove_action( 'rest_api_init', array( 'Clara_VE_Forms', 'register_routes' ) );
	remove_action( 'after_setup_theme', 'clara_ve_register_nav_location', 11 );
	remove_action( 'init', 'clara_ve_override_front_pattern', 20 );
	remove_action( 'pre_get_posts', 'clara_ve_scope_posts_to_theme' );
	remove_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_scroll_video', 5 );
	remove_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_load_more', 5 );
	remove_action( 'wp_enqueue_scripts', 'clara_ve_enqueue_form_submit', 5 );
	// NOT the Google fonts. Everything else on this list is delivery the theme
	// does for itself — its own stylesheets, its own scripts, its own
	// metadata — so the plugin standing down prevents a double emission. A
	// typeface added through this plugin's picker is the opposite case: the
	// theme has never heard of it and cannot possibly load it. Removing this
	// left the family registered in theme.json, so it appeared in the Font
	// menu and put its class on the block, while the font FILE was never
	// requested and every browser quietly fell back to sans-serif. The font
	// looked chosen and was not there.
	remove_action( 'wp_head', 'clara_ve_print_pseudo_css', 60 );
	remove_action( 'wp_head', 'clara_ve_print_article_css', 61 );
	remove_action( 'template_redirect', array( 'Clara_VE_Redirects', 'maybe_redirect' ), 0 );
	remove_action( 'wp_head', array( 'Clara_VE_SEO', 'emit' ), 1 );
	remove_filter( 'pre_get_document_title', array( 'Clara_VE_SEO', 'filter_document_title' ) );
	remove_action( 'wp_head', array( 'Clara_VE_SEO', 'drop_duplicate_core_title' ), 0 );
	remove_filter( 'wp_robots', array( 'Clara_VE_SEO', 'filter_robots' ) );
}
add_action( 'after_setup_theme', 'clara_ve_delegate_public_runtime_to_theme', 12 );

/**
 * Has anyone deliberately configured the site-level SEO/GEO identity?
 *
 * The stand-down below removes DEFAULT output, never explicit configuration.
 * Per-page SEO cannot exist on a contract-less theme — it keys off
 * _clara_ve_key, which such a theme's pages never carry — but the SEO and GEO
 * settings screens register on every theme, so somebody may have filled them
 * in on purpose and expects the tags.
 *
 * Judged on non-DEFAULT rather than non-empty. Opening either screen and
 * pressing Save writes every registered field at once, so entity_type lands
 * as 'Organization', the separator as an en dash and ai_crawlers as 'on'
 * whether or not the owner touched them; treating those as configuration
 * would grandfather the duplicate tags back in after a single stray save.
 *
 * @return bool
 */
function clara_ve_public_seo_is_configured() {
	$fields = array(
		Clara_VE_SEO::OPT_ENTITY_NAME     => '',
		Clara_VE_SEO::OPT_ENTITY_LOGO     => '',
		Clara_VE_SEO::OPT_DEFAULT_OG      => '',
		Clara_VE_SEO::OPT_ENTITY_TYPE     => 'Organization',
		Clara_VE_SEO::OPT_TITLE_SEPARATOR => '–',
		Clara_VE_GEO::OPT_AI_CRAWLERS     => 'on',
	);
	foreach ( $fields as $option => $default ) {
		$value = trim( (string) get_option( $option, $default ) );
		if ( '' !== $value && $value !== $default ) {
			return true;
		}
	}
	// sameAs is the one list; any surviving entry is an address somebody typed.
	return array() !== array_filter( array_map( 'trim', array_map( 'strval', (array) get_option( Clara_VE_SEO::OPT_SAME_AS, array() ) ) ) );
}

/**
 * A theme that never asked for a public runtime does not get one.
 *
 * clara_ve_delegate_public_runtime_to_theme() above stands the same delivery
 * down for a theme that OPTS OUT by declaring html2wp-runtime. This handles
 * the other half of the problem: a theme that declares nothing at all, has no
 * converter artifacts and has never been edited here — an ordinary WordPress
 * theme somebody activated with the plugin still installed. It emits its own
 * <title>, description and og:* tags, and so did this plugin, on top: measured
 * as two of every tag and two JSON-LD graphs on the same page.
 *
 * Kept deliberately narrow. Only public metadata delivery stands down; the
 * editor, the AI, history, export, redirects and the admin screens are
 * untouched, and a converted theme reaches none of this code.
 */
function clara_ve_stand_down_public_seo_on_foreign_theme() {
	if ( clara_ve_active_theme_is_ours() ) {
		return;
	}
	// A theme that declares html2wp-runtime has said, in so many words, that
	// it renders its own public metadata. That declaration outranks a stored
	// option: measured on the reference block theme, an install carrying SEO
	// settings from a PREVIOUS theme's import got two schema.org entities for
	// the same business — the theme's, inside its own graph, and this
	// plugin's beside it. The grandfather clause exists so nobody loses
	// output they configured, not so a theme's own declaration can be
	// overridden by a row it never wrote.
	//
	// Unreachable for a converted theme, which is always "ours" above.
	if ( ! clara_ve_theme_owns_public_runtime() && clara_ve_public_seo_is_configured() ) {
		return;
	}

	remove_action( 'wp_head', array( 'Clara_VE_SEO', 'emit' ), 1 );
	remove_filter( 'pre_get_document_title', array( 'Clara_VE_SEO', 'filter_document_title' ) );
	remove_action( 'wp_head', array( 'Clara_VE_SEO', 'drop_duplicate_core_title' ), 0 );
	remove_filter( 'wp_robots', array( 'Clara_VE_SEO', 'filter_robots' ) );

	// All three of GEO's mutually exclusive emission paths, since which one
	// would have run is decided per request rather than here.
	remove_action( 'wp_head', array( 'Clara_VE_GEO', 'emit_standalone' ), 2 );
	remove_filter( 'wpseo_schema_graph', array( 'Clara_VE_GEO', 'extend_yoast_graph' ), 10 );
	remove_filter( 'rank_math/json_ld', array( 'Clara_VE_GEO', 'extend_rankmath_graph' ), 10 );

	// llms.txt: the ROUTE stays registered on purpose. Its rewrite rule is
	// cached in the rewrite_rules option and outlives this decision by however
	// long it takes something to flush it, so unregistering the rule or its
	// query var would not make the URL disappear. Serving is replaced rather
	// than merely removed for the same reason: with the rule still matching
	// and nothing answering it, /llms.txt renders the FRONT PAGE at 200 —
	// measured, and worse than what it replaced. robots.txt stops advertising
	// a file that is no longer there.
	remove_action( 'template_redirect', array( 'Clara_VE_GEO', 'maybe_serve_llms_txt' ), 1 );
	add_action( 'template_redirect', 'clara_ve_404_stood_down_llms_txt', 1 );
	remove_filter( 'robots_txt', array( 'Clara_VE_GEO', 'filter_robots_txt' ), 10 );
}
add_action( 'after_setup_theme', 'clara_ve_stand_down_public_seo_on_foreign_theme', 12 );

/**
 * Answer the still-cached /llms.txt rewrite rule with a real 404 while the
 * feature is stood down, so the address behaves like the missing file it is
 * instead of quietly serving the site's front page under a .txt name.
 */
function clara_ve_404_stood_down_llms_txt() {
	if ( ! get_query_var( 'clara_ve_llms' ) ) {
		return;
	}
	global $wp_query;
	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}

/**
 * Preserve the plugin's optional form archive, provider, anti-spam and mailer
 * features without making the public form depend on them. The theme validates
 * and provides wp_mail first; returning a response here opts into the richer
 * plugin path when the editor is active.
 */
function clara_ve_enhance_theme_form( $handled, $context ) {
	if ( ! clara_ve_theme_owns_public_runtime() || ! class_exists( 'Clara_VE_Forms' ) || ! is_array( $context ) || empty( $context['params'] ) ) {
		return $handled;
	}
	$request = new WP_REST_Request( 'POST', '/clara-ve/v1/submit' );
	$request->set_body_params( (array) $context['params'] );
	return Clara_VE_Forms::handle_submit( $request );
}
add_filter( 'html2wp_theme_form_handle', 'clara_ve_enhance_theme_form', 10, 2 );

/**
 * Activation: seed whatever menus the THEME declares via clara_ve_seed_menus
 * (nothing by default — the plugin knows no site's pages) so menu management
 * is available out of the box on a theme that ships seed data.
 */
function clara_ve_activate() {
	// Drop the cached rewrite rules so /llms.txt resolves. Deleted rather than
	// regenerated for the reason apply_settings() spells out: rebuilding rules
	// needs every post type and taxonomy registered, which is not guaranteed
	// during activation, whereas the next front-end request has a fully booted
	// WordPress and rebuilds them correctly.
	delete_option( 'rewrite_rules' );

	// Don't scaffold a menu the theme is about to supply a real one for.
	// seed_menu() builds a generic menu and claims the nav location; when the
	// active theme ships a content bundle, that bundle carries the site's OWN
	// menu, whose links resolve to the pages the import creates. Seeding first
	// means the import finds the location taken, treats its menu as a conflict
	// and — correctly, by the never-overwrite rule — leaves the scaffolding in
	// place instead. Skipping the seed here is what lets the real menu land.
	if ( Clara_VE_Bundle_Reader::theme_bundle_dir() ) {
		return;
	}
	// And don't scaffold one into a theme this plugin is not the editor for
	// at all. Empirically this was already a no-op on a native block theme —
	// the seed claims a nav location such a theme does not register — but a
	// no-op by accident is one register_nav_menus() call away from an
	// unexplained menu appearing in somebody's site.
	if ( ! clara_ve_active_theme_is_ours() ) {
		return;
	}
	Clara_VE_Front_Nav::seed_menu();
}
register_activation_hook( __FILE__, 'clara_ve_activate' );

/**
 * Deactivation: unschedule the AI-job cron events (their args vary per job,
 * so the hook is cleared wholesale) and drop cached rewrite rules so the
 * /llms.txt route disappears with the plugin instead of 404-ing oddly.
 * Data — options, tables, submissions — is deliberately untouched here;
 * deactivate/reactivate must round-trip losslessly. Deletion is handled by
 * uninstall.php, on its own terms.
 */
function clara_ve_deactivate() {
	delete_option( 'rewrite_rules' );
}
register_deactivation_hook( __FILE__, 'clara_ve_deactivate' );
