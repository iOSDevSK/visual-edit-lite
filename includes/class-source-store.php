<?php
/**
 * Persistence for edited page source, keyed by page (CLARA_VE_DEFAULT_KEY
 * for the front page, an arbitrary string for any other "visual-edit
 * enabled" WP Page — see CLARA_VE_PAGE_KEY_META).
 *
 * The keyed option is the single source of truth for a page's current
 * source; a non-front-page key's bound WP Page row is kept in sync as a
 * render target (real header/footer via templates/page.html), never read
 * from directly except as a first-load fallback before anything has been
 * explicitly saved yet. The front page has no such Page row at all — it's
 * the pattern-override mechanism in visual-edit.php, untouched.
 *
 * The source is stored with the theme URI replaced by a portable token so it
 * survives moving between environments. Full save history (git-like, with
 * restore) lives in Clara_VE_History — this class only tracks the current
 * live value.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_Source_Store {

	/**
	 * Replace the theme URI with a portable token.
	 *
	 * Do NOT add further substitutions here (uploads URLs, home URL) to make
	 * exported content more portable — Clara_VE_History::list_entries() marks
	 * HEAD by comparing hash( 'sha256', tokenize( get_resolved_source( $key ) ) )
	 * against stored content_hash values, an identity that only holds while
	 * tokenize( untokenize( $x ) ) === $x for every row already in the table. A
	 * new rule silently breaks HEAD for every entry written before it, and the
	 * damage is invisible until someone opens the History panel. Portability
	 * beyond the theme URI belongs at the export/import boundary
	 * (Clara_VE_Bundle_Format), never in the stored representation.
	 *
	 * @param string $source Raw page HTML with absolute theme URIs.
	 * @return string
	 */
	public static function tokenize( $source ) {
		return str_replace( get_template_directory_uri(), CLARA_VE_THEME_URI_TOKEN, $source );
	}

	/**
	 * Resolve a tokenized source back to absolute theme URIs for this site.
	 *
	 * @param string $tokenized Tokenized page HTML.
	 * @return string
	 */
	public static function untokenize( $tokenized ) {
		return str_replace( CLARA_VE_THEME_URI_TOKEN, get_template_directory_uri(), $tokenized );
	}

	/**
	 * The wp_options row name backing a page key FOR A GIVEN THEME.
	 *
	 * Scoped by stylesheet, because the unscoped names this plugin shipped with
	 * gave one WordPress install exactly one set of page sources, which two
	 * converted themes then had to fight over: activating the second served the
	 * first theme's markup through the second's design, refused the second's
	 * import as a conflict, and wrote one theme's header into the other's
	 * template part. Scoping is what lets both live here, each with its own
	 * content, and makes switching themes switch profiles instead of colliding.
	 *
	 * The legacy unscoped names are still READ (see legacy_option_name and the
	 * fallback in get_stored) until the one-time migration moves them, so an
	 * install that upgrades keeps working with no migration required to be
	 * successful first.
	 *
	 * @param string      $key
	 * @param string|null $theme Stylesheet slug; defaults to the active theme.
	 * @return string
	 */
	private static function option_name( $key, $theme = null ) {
		$key   = sanitize_key( $key );
		$theme = sanitize_key( null === $theme ? get_stylesheet() : $theme );
		return 'clara_ve_source__' . $theme . '__' . $key;
	}

	/**
	 * The pre-scoping row name for a key — what installs before this shipped
	 * wrote, and what the migration reads from.
	 *
	 * @param string $key
	 * @return string
	 */
	public static function legacy_option_name( $key ) {
		$key = sanitize_key( $key );
		return ( CLARA_VE_DEFAULT_KEY === $key ) ? CLARA_VE_OPTION : 'clara_ve_source__' . $key;
	}

	/**
	 * The raw stored value for a key: this theme's scoped row, falling back to
	 * the legacy unscoped row while an install is still unmigrated.
	 *
	 * The fallback is gated on the foreign-data guard, and that gate is the
	 * whole point: an unscoped row can belong to another theme, so reading it
	 * blindly would reintroduce exactly the bug scoping removes.
	 *
	 * @param string $key
	 * @return string|null
	 */
	private static function get_stored( $key ) {
		$scoped = get_option( self::option_name( $key ), null );
		if ( is_string( $scoped ) && '' !== $scoped ) {
			return $scoped;
		}
		if ( function_exists( 'clara_ve_foreign_data' ) && '' !== clara_ve_foreign_data() ) {
			return null;
		}
		$legacy = get_option( self::legacy_option_name( $key ), null );
		return ( is_string( $legacy ) && '' !== $legacy ) ? $legacy : null;
	}

	/**
	 * What is stored for one key under a NAMED theme, untokenized.
	 *
	 * The scoped row only, with no fallback of any kind. get_current_source()
	 * falls back to the theme's own pattern file or template part, and both of
	 * those come from WordPress registries that describe the theme currently
	 * loaded — so asking it about a parked theme returns the ACTIVE theme's
	 * markup wearing the parked theme's name. Here, a key with nothing stored
	 * returns '' and the caller decides what to say about it.
	 *
	 * @param string $theme Stylesheet.
	 * @param string $key
	 * @return string
	 */
	public static function get_stored_for( $theme, $key ) {
		$stored = get_option( self::option_name( $key, $theme ), null );
		if ( is_string( $stored ) && '' !== $stored ) {
			return self::untokenize( $stored );
		}

		// Nothing stored means nobody ever edited this key, and for a chrome
		// part that is the ordinary case — WordPress renders it straight from
		// parts/{key}.html and the plugin has nothing to add. Reading the file
		// is what get_block_template() would do for the active theme, done by
		// hand because it can only ever answer about the theme now loaded.
		// Without this a parked theme's package would silently be missing its
		// header, footer and article layout: present on the site, absent from
		// the backup taken to protect it.
		if ( ! self::is_chrome_key( $key ) ) {
			return '';
		}
		$file = trailingslashit( wp_get_theme( $theme )->get_stylesheet_directory() ) . 'parts/' . $key . '.html';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		return self::untokenize( self::extract_marked_block( (string) file_get_contents( $file ), $key ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Stored source with the theme URI token resolved, or null when no edit
	 * has ever been saved for this key.
	 *
	 * @param string $key
	 * @return string|null
	 */
	public static function get_resolved_source( $key = CLARA_VE_DEFAULT_KEY ) {
		// Content stored for a DIFFERENT theme is not this theme's content.
		// Serving it renders one design's markup through another's CSS and
		// template — the single most visible symptom of two converted themes
		// on one install (see CLARA_VE_OWNER_OPTION). Reporting "no saved
		// edit" instead makes every caller fall back to the ACTIVE theme's
		// own files, which is the correct thing to show, and the admin notice
		// explains why editing is paused.
		// With sources scoped per theme this is now only reachable for LEGACY
		// unscoped data whose owner could not be determined — get_stored()
		// applies the same gate to its own legacy fallback. A scoped read can
		// never be another theme's by construction.
		$stored = self::get_stored( $key );
		if ( null === $stored ) {
			return null;
		}
		return self::untokenize( $stored );
	}

	/**
	 * Save a new source (given with absolute theme URIs) for the given key,
	 * tokenizing the theme URI before persisting. For any key other than the
	 * front page, also mirrors the source into that key's bound WP Page so
	 * WordPress's normal rendering (real shared header/footer) shows it.
	 *
	 * @param string $key
	 * @param string $source Raw page HTML.
	 * @return bool
	 */
	public static function save_source( $key, $source ) {
		$key = sanitize_key( $key );
		// Refuse to write while the stored content belongs to another theme.
		// Both directions are destructive: the incoming markup is the ACTIVE
		// theme's (get_resolved_source() reports nothing saved, so the editor
		// loaded theme files), and writing it would overwrite the other
		// theme's real content — while sync_to_template_part() below would
		// stamp it into the active theme's parts. Nothing is saved and the
		// admin notice says why.
		if ( function_exists( 'clara_ve_foreign_data' ) && '' !== clara_ve_foreign_data() ) {
			return false;
		}
		// A save writes its OWN scoped row and claims nothing else. It used to
		// claim ownership of the whole install for the active theme, which the
		// migration then obeyed — so one save under theme B filed every
		// unattributed page of theme A under B. Caught on a live site: four
		// pages of one design moved into another's profile because a single
		// page was saved while the other theme happened to be active.
		// Attribution of pre-scoping data comes from evidence
		// (clara_ve_data_theme_verdict) or an explicit act, never from the fact
		// that somebody pressed Save.
		$saved = update_option( self::option_name( $key ), self::tokenize( $source ), false );
		if ( $saved && self::is_chrome_key( $key ) ) {
			self::sync_to_template_part( $key, $source );
		} elseif ( $saved && CLARA_VE_DEFAULT_KEY !== $key ) {
			self::sync_to_page( $key, $source );
		}
		if ( $saved ) {
			/**
			 * A page's markup changed.
			 *
			 * Added because there was no way to know. Anything derived from a
			 * page's content — the FAQ pulled out of it, the readiness report,
			 * any cache — was correct at conversion and had no signal that the
			 * owner had since rewritten the page. Which turned a live feature
			 * into a snapshot of the day it was installed.
			 *
			 * @param string $key    Visual-edit key.
			 * @param string $source The saved markup, with absolute theme URIs.
			 */
			do_action( 'clara_ve_source_saved', $key, $source );
		}
		return $saved;
	}

	/**
	 * The post the front page's own settings and SEO belong to, or 0.
	 *
	 * The front page renders from a registered pattern, not from post content,
	 * so for most of this plugin's life it had no post at all — which made it
	 * the one page on the site with nowhere to keep a meta description, a
	 * canonical or a schema.org block. This is the anchor that fixes that: a
	 * Page that exists to be pointed at, and is never rendered.
	 *
	 * An owner-chosen static front page wins over our tagged one, because
	 * WordPress's answer to "which page is the front page" is the only answer
	 * that matters — `templates/front-page.html` wins the template hierarchy
	 * whichever Page that is, so the design renders either way and the SEO
	 * should follow the page the visitor actually lands on.
	 *
	 * @return int
	 */
	public static function front_anchor_id() {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$chosen = (int) get_option( 'page_on_front' );
			if ( $chosen > 0 && 'page' === get_post_type( $chosen ) ) {
				return $chosen;
			}
		}
		$tagged = self::find_page_by_key( CLARA_VE_DEFAULT_KEY );
		return $tagged ? (int) $tagged->ID : 0;
	}

	/**
	 * Make sure the front page has its anchor, and that WordPress is pointed at
	 * it. Idempotent.
	 *
	 * Creation and the two option writes happen together on purpose. Split
	 * apart — create the Page here, flip the settings in the importer — a run
	 * that stops in between leaves a stray, publicly reachable empty page at
	 * /home/, which is exactly the failure mode create_or_update_page() already
	 * documents for header/footer/article. Done in one breath, the Page is
	 * either the front page or it does not exist.
	 *
	 * @param string $title Used only when creating.
	 * @return int Anchor post ID, or 0 if it could not be created.
	 */
	public static function ensure_front_anchor( $title = '' ) {
		$existing = self::front_anchor_id();
		if ( $existing ) {
			return $existing;
		}

		$title   = '' !== trim( (string) $title ) ? $title : __( 'Home', 'visual-edit-lite' );
		$post_id = wp_insert_post(
			array(
				'post_type' => 'page',
				'post_title' => $title,
				'post_name' => 'home',
				// Stays empty forever: save_source() skips sync_to_page() for
				// this key (see :99) and templates/front-page.html has no
				// wp:post-content, so there is nothing that could render it.
				'post_content' => '',
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return 0;
		}

		update_post_meta( $post_id, CLARA_VE_PAGE_KEY_META, CLARA_VE_DEFAULT_KEY );
		update_post_meta( $post_id, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', (int) $post_id );
		return (int) $post_id;
	}

	/**
	 * @param string $key
	 * @return bool True for the shared template-part keys (header, footer,
	 *              and the single-post article layout).
	 */
	private static function is_chrome_key( $key ) {
		if ( in_array( sanitize_key( $key ), array( CLARA_VE_HEADER_KEY, CLARA_VE_FOOTER_KEY, CLARA_VE_ARTICLE_KEY, CLARA_VE_404_KEY ), true ) ) {
			return true;
		}
		// A theme keeping several header/footer designs declares the extra
		// parts in its contract — each is a template part editable exactly
		// like the standard pair (same sync, same guard, same history).
		return null !== clara_ve_contract_part( $key );
	}

	/**
	 * The current canonical source with absolute URIs for the given key —
	 * the saved edit when one exists; otherwise, for the front page, the
	 * theme pattern's rendered HTML (unchanged existing behavior), or for
	 * any other key, whatever its bound Page's post_content currently holds
	 * (the state immediately after create_visual_page, before a first
	 * explicit save).
	 *
	 * @param string $key
	 * @return string
	 */
	public static function get_current_source( $key = CLARA_VE_DEFAULT_KEY ) {
		$key   = sanitize_key( $key );
		$saved = self::get_resolved_source( $key );
		if ( null !== $saved ) {
			return $saved;
		}

		// Every fallback below is untokenized on the way out, the same way
		// get_resolved_source() treats a saved edit. That matters for content
		// restored from History, or written by a caller that still holds the
		// token; on content without one it is a no-op.
		//
		// It does NOT make the token safe to put in a theme's static
		// parts/*.html. This read path belongs to the editor. A shared header
		// or footer is normally rendered by WordPress itself, straight from
		// the theme file, and nothing on that path resolves anything — so a
		// token in parts/footer.html is published verbatim and the site ships
		// <img src="__CLARA_THEME_URI__/assets/…">, 404 for every visitor.
		// (Tried, shipped, caught by the end-to-end test, reverted.) Theme
		// files use real paths; the token is for stored sources only.
		if ( CLARA_VE_DEFAULT_KEY === $key ) {
			$registry     = WP_Block_Patterns_Registry::get_instance();
			$pattern_name = clara_ve_pattern_name();
			if ( ! $registry->is_registered( $pattern_name ) ) {
				return '';
			}
			$pattern = $registry->get_registered( $pattern_name );
			return self::untokenize( clara_ve_extract_pattern_html( $pattern['content'] ) );
		}

		if ( self::is_chrome_key( $key ) ) {
			// get_block_template() already resolves "DB override if one
			// exists, else the theme file" on its own — the same fallback
			// wp_options-vs-Page-post_content dance the branches above do
			// by hand, WordPress does natively for template parts.
			$template = get_block_template( get_stylesheet() . '//' . $key, 'wp_template_part' );
			if ( ! $template ) {
				return '';
			}
			return self::untokenize( self::extract_marked_block( $template->content, $key ) );
		}

		$page = self::find_page_by_key( $key );
		if ( ! $page ) {
			return '';
		}
		return self::untokenize( self::strip_wp_html_wrapper( $page->post_content, $key ) );
	}

	/**
	 * Discard the saved edit for the given key. For the front page this
	 * restores the theme pattern file as source of truth (unchanged existing
	 * behavior). For any other key there is no equivalent "original file" —
	 * callers wanting "reset a tagged page" should restore its History
	 * baseline entry instead; this method just clears the keyed option.
	 *
	 * @param string $key
	 * @return void
	 */
	public static function reset( $key = CLARA_VE_DEFAULT_KEY ) {
		delete_option( self::option_name( $key ) );
		// A reset must also clear an unmigrated legacy row, or the next read
		// falls back to it and the page appears to come back from the dead.
		delete_option( self::legacy_option_name( $key ) );
	}

	/**
	 * Structural shape guard, shared by class-rest.php::save_source(), the AI
	 * Assistant's edit tool, and the ZIP importer so the rules live in exactly
	 * one place.
	 *
	 * Two layers, and the split matters:
	 *
	 * 1. A THEME-AGNOSTIC floor, applied to every key unconditionally. It
	 *    catches the failure this guard exists for — a save that would leave
	 *    the page empty or gutted — without knowing anything about the markup.
	 *
	 * 2. Per-key anchors the THEME declares, via the theme contract
	 *    (`clara_ve_theme_contract`, 'anchors' key; the older
	 *    `clara_ve_required_anchors` filter still feeds in as a shim).
	 *    These are an optional tightening, not the only guard.
	 *
	 * Layer 2 used to be a hardcoded list, and the list was one specific site's
	 * markup: 'class="hero"' for the front page, 'site-header', 'site-footer',
	 * 'utility'. In a plugin that converts ANY site that is not a default, it
	 * is a bug with the shape of a feature — every generated theme whose markup
	 * differs (which is all of them) had its front page silently refused at
	 * import, leaving a stored source of zero bytes. The page still rendered,
	 * because a generated theme also ships its front page as a static pattern,
	 * so nothing looked wrong; it simply could not be edited, which is the
	 * entire product. Verified live on two generated themes.
	 *
	 * So the default is now empty and the knowledge lives where the markup
	 * does. The converter emits a declaration into every theme it generates;
	 * the Clara Hayes theme declares its own in inc/theme-setup.php. A theme
	 * that declares nothing still gets layer 1.
	 *
	 * @param string $key
	 * @param string $source
	 * @return true|string True if valid, else a human-readable error message.
	 */
	public static function validate_shape( $key, $source ) {
		$source = (string) $source;
		if ( '' === trim( $source ) ) {
			return 'Refusing to save an empty page.';
		}
		$key = sanitize_key( $key );

		// --- layer 1: the theme-agnostic floor ------------------------------
		// Markup with no element at all is text that lost its structure, not a
		// page. Cheap, and true for every theme ever generated.
		if ( ! preg_match( '/<[a-z][a-z0-9-]*(\s|>|\/)/i', $source ) ) {
			return 'Refusing to save: this content has no HTML elements left — save aborted to protect the site.';
		}

		// A save that drops most of the page is the destructive case the
		// anchors were really guarding against, and it is detectable without
		// knowing a single class name. Deliberately generous: deleting half a
		// page is a legitimate edit, deleting nine tenths of it in one save is
		// not something anyone does on purpose in a click-to-edit surface.
		// History can still restore it either way; this only forces the
		// deletion to be explicit rather than a side effect of a broken save.
		// get_resolved_source(), not get_current_source(): only an actual saved
		// edit is a baseline worth measuring against. The fallbacks the latter
		// walks (a bound Page's post_content, a theme pattern) are a different
		// document, and comparing against one would refuse a legitimate first
		// save on a key that has never been saved.
		$existing = self::get_resolved_source( $key );
		if ( is_string( $existing ) && strlen( $existing ) > 2000
			&& strlen( $source ) < (int) ( strlen( $existing ) * 0.1 ) ) {
			return 'Refusing to save: this would remove over 90% of the page — save aborted to protect the site. '
				. 'Delete the sections you mean to remove individually if this was intended.';
		}

		// The canonical declaration surface is the theme contract
		// (clara_ve_theme_contract() in visual-edit.php); anchors are one
		// key of it. Substrings, matched literally against the saved source
		// — pick them from a class, id or data-attribute the layout depends
		// on, never from editorial copy, which the owner is entitled to
		// rewrite.
		$contract = clara_ve_theme_contract();
		$declared = isset( $contract['anchors'][ $key ] ) ? (array) $contract['anchors'][ $key ] : array();

		/**
		 * Back-compat shim, folded into the contract: themes generated before
		 * the contract existed declare anchors through this filter alone, so
		 * it still runs — with the contract's anchors as its default. New
		 * themes should declare via clara_ve_theme_contract instead.
		 *
		 * @param string[] $anchors The contract's anchors for this key.
		 * @param string   $key     The visual-edit key being saved.
		 */
		$required_anchors = (array) apply_filters( 'clara_ve_required_anchors', $declared, $key );

		foreach ( $required_anchors as $anchor ) {
			$anchor = (string) $anchor;
			if ( '' !== $anchor && false === strpos( $source, $anchor ) ) {
				return 'The page is missing an expected section (' . $anchor . ') — save aborted to protect the site.';
			}
		}
		return true;
	}

	/**
	 * Find-or-create the WP Page bound to $key, then save $content as its
	 * source (establishes/updates the keyed option, mirrors into
	 * post_content, seeds a History baseline if this key has no history
	 * yet). The front-page key is special-cased: there's no Page row for it
	 * at all, just a save_source() call. Shared by the AI Assistant's
	 * create_visual_page tool and the ZIP importer so "make this key's page
	 * exist with this content" has exactly one implementation instead of
	 * two drifting copies.
	 *
	 * @param string $key
	 * @param string $title   Only used if a new Page needs to be created.
	 * @param string $slug    Only used if a new Page needs to be created; falls back to sanitize_title( $title ).
	 * @param string $content
	 * @return int|true|WP_Error Post ID for a tagged page (new or existing), `true` for the front-page key (no post ID involved), or WP_Error if wp_insert_post() failed.
	 */
	public static function create_or_update_page( $key, $title, $slug, $content ) {
		$key = sanitize_key( $key );

		// header/footer/article render from a wp_template_part, not from a WP
		// Page — save_source() already routes them there. Without this branch
		// they fall through to the tagged-page path below and each one ALSO
		// gets a real, publicly reachable Page: /header/, /footer/,
		// /article-template/, serving raw chrome markup to visitors. The front
		// page has always been special-cased here; these three were not, and
		// every caller that could reach them (an import, the AI Assistant's
		// create_visual_page) created the stray page.
		if ( self::is_chrome_key( $key ) ) {
			Clara_VE_History::ensure_baseline( $key );
			self::save_source( $key, $content );
			Clara_VE_History::ensure_baseline( $key );
			return true;
		}

		if ( CLARA_VE_DEFAULT_KEY === $key ) {
			// BEFORE the write, not after: ensure_baseline() seeds the log from
			// whatever is live RIGHT NOW, so on a key that has no history yet
			// (a first-ever import, a first AI-created page) calling it after
			// save_source() would capture the incoming content as the
			// "Original" and lose the pre-write state for good. It no-ops once
			// any entry exists, so the trailing call below stays correct for
			// the genuinely-new-page case where there is nothing to capture
			// yet. See the ensure_baseline() docblock.
			Clara_VE_History::ensure_baseline( $key );
			self::save_source( $key, $content );
			Clara_VE_History::ensure_baseline( $key );
			// The front page still renders from the pattern override, not from a
			// post — this only gives it something to hang its settings and SEO
			// on. Return stays `true` rather than the ID: callers treat the front
			// key as "no post involved" and switching that would change the
			// contract for every one of them.
			self::ensure_front_anchor( $title );
			return true;
		}

		$page = self::find_page_by_key( $key );
		if ( $page ) {
			if ( 'trash' === $page->post_status ) {
				wp_untrash_post( $page->ID );
				wp_update_post(
					array(
						'ID'          => $page->ID,
						'post_status' => 'publish',
					)
				);
			} elseif ( CLARA_VE_PARKED_STATUS === $page->post_status ) {
				// The active theme's own page, still put away. Restoring it
				// properly — status, slug and the bookkeeping — rather than
				// just publishing it, or it would come back at the parked
				// address `about--ve-sonic` and stay there.
				Clara_VE_Theme_Park::restore( get_stylesheet() );
				$page = self::find_page_by_key( $key );
				if ( ! $page ) {
					return false;
				}
			}
			Clara_VE_History::ensure_baseline( $key );
			self::save_source( $key, $content );
			Clara_VE_History::ensure_baseline( $key );
			return $page->ID;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_title'   => $title,
				'post_name'    => $slug ? sanitize_title( $slug ) : sanitize_title( $title ),
				'post_content' => '', // filled in by save_source() below, once the meta lookup key exists
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, CLARA_VE_PAGE_KEY_META, $key );
		update_post_meta( $post_id, CLARA_VE_PAGE_THEME_META, sanitize_key( get_stylesheet() ) );
		self::save_source( $key, $content );
		Clara_VE_History::ensure_baseline( $key );
		return $post_id;
	}

	/**
	 * Every visual-edit key on this site, in the order the editor's page
	 * picker shows them: the front page, each tagged WP Page by title, then
	 * the shared header/footer and the article template.
	 *
	 * Structural only — no preview URLs, no "is there a post to preview this
	 * on" filtering. Consumers that need either (the page picker in
	 * Clara_VE_REST::list_visual_pages(), the AI Assistant's list_pages tool,
	 * the exporter) build it on top of this, so the enumeration itself lives
	 * in exactly one place. A second, drifting copy of it has already cost
	 * real debugging once: the AI tool's own list silently omitted
	 * header/footer, so the model had no way to discover it could edit the
	 * nav or the footer links at all.
	 *
	 * @return array<int,array{key:string,kind:string,post_id:int|null,title:string,slug:string}>
	 *         kind is 'front' (the pattern-override front page), 'page' (a
	 *         tagged WP Page) or 'part' (a wp_template_part-backed key).
	 *         post_id is null for 'part' keys and for a front page that has no
	 *         anchor Page yet; it is NOT a signal that a key is renderable as a
	 *         post — branch on kind for that.
	 */
	public static function list_keys( $theme = null ) {
		$theme  = sanitize_key( null === $theme ? get_stylesheet() : $theme );
		$active = ( sanitize_key( get_stylesheet() ) === $theme );

		$out = array(
			array(
				'key'     => CLARA_VE_DEFAULT_KEY,
				'kind'    => 'front',
				'post_id' => null,
				'title'   => __( 'Front Page', 'visual-edit-lite' ),
				'slug'    => '',
			),
		);

		$query = array(
			'post_type'     => 'page',
			'post_status'   => array( 'publish', 'draft' ),
			'numberposts'   => -1,
			'meta_key'      => CLARA_VE_PAGE_KEY_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'       => 'title',
			'order'         => 'ASC',
			'no_found_rows' => true,
		);
		// Listing another theme's keys — which is what exporting a parked theme
		// needs — means asking for pages that are not the active theme's and
		// are, by then, parked out of every ordinary status. Untouched for the
		// active theme, so the editor and the pickers behave exactly as before.
		if ( ! $active ) {
			$query['post_status'] = array( 'publish', 'draft', CLARA_VE_PARKED_STATUS );
			$query['meta_query']  = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => CLARA_VE_PAGE_THEME_META,
					'value' => $theme,
				),
			);
		}
		$pages = get_posts( $query );
		foreach ( $pages as $page ) {
			$key = get_post_meta( $page->ID, CLARA_VE_PAGE_KEY_META, true );
			if ( ! $key ) {
				continue;
			}
			// The front page is already the first entry above, and since it
			// gained an anchor Page it now also matches this query. Letting it
			// through would list it twice — and not merely as a cosmetic
			// duplicate in the picker: Clara_VE_Bundle_Writer walks this same
			// list, so an export would write sources/front-page.html twice and
			// ship two index rows with the same key. Its post_id is filled in on
			// the existing entry instead, keeping kind 'front' — every consumer
			// branches on kind, and one of them (Clara_VE_REST at :242) picks the
			// chrome preview URL from the first 'page' entry, which must never
			// be the self-contained front page.
			if ( CLARA_VE_DEFAULT_KEY === $key ) {
				$out[0]['post_id'] = (int) $page->ID;
				continue;
			}
			$out[] = array(
				'key'     => $key,
				'kind'    => 'page',
				'post_id' => (int) $page->ID,
				// Decoded, not raw: get_the_title() returns the title with HTML
				// entities in it, and every consumer here writes it as TEXT — a
				// <select> option, a panel heading, a confirm dialog. Handing
				// those an entity shows the entity: "Terms &#038; Conditions"
				// in the page picker.
				'title'   => html_entity_decode( get_the_title( $page ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'slug'    => (string) $page->post_name,
			);
		}

		$parts = array(
			CLARA_VE_HEADER_KEY  => __( 'Header', 'visual-edit-lite' ),
			CLARA_VE_FOOTER_KEY  => __( 'Footer', 'visual-edit-lite' ),
			CLARA_VE_ARTICLE_KEY => __( 'Article template', 'visual-edit-lite' ),
			CLARA_VE_404_KEY     => __( 'Page not found (404)', 'visual-edit-lite' ),
		);
		// Chrome variant parts the theme declares (a site whose pages use
		// several header/footer designs keeps them all) — every one is an
		// editable key with its own canvas, listed after the standard four.
		foreach ( clara_ve_theme_contract()['parts'] as $extra ) {
			$parts[ $extra['key'] ] = $extra['label'];
		}
		foreach ( $parts as $key => $label ) {
			$out[] = array(
				// Cast, because PHP silently turns a numeric-looking array KEY
				// into an int: '404' above arrives here as int 404, and every
				// `CLARA_VE_404_KEY === $entry['key']` downstream then fails a
				// strict comparison against the string constant. Everything
				// consuming this list treats keys as strings.
				'key'     => (string) $key,
				'kind'    => 'part',
				'post_id' => null,
				'title'   => $label,
				'slug'    => '',
			);
		}

		return $out;
	}

	/**
	 * @param string $key
	 * @return WP_Post|null The WP Page carrying this visual-edit key, or
	 *                       null if none exists yet.
	 */
	public static function find_page_by_key( $key ) {
		// 'any' excludes 'trash' — the statuses are listed explicitly, or a
		// page trashed (deliberately or accidentally) becomes invisible to
		// this lookup and every subsequent save/import spawns a duplicate
		// page instead of updating the original. Parked is here for the same
		// reason: the first query below is scoped to the ACTIVE theme, whose
		// own pages should never be parked, so finding one means a restore
		// left something behind — and quietly creating a second page beside it
		// is the worst available answer.
		$base = array(
			'post_type'     => 'page',
			'post_status'   => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash', CLARA_VE_PARKED_STATUS ),
			'numberposts'   => 1,
			'no_found_rows' => true,
		);
		// This theme's own page first. Two converted themes on one install
		// both have an "about", so the key alone no longer identifies one
		// page — and returning the other theme's would make a save write into
		// it. Pages bound before scoping carry no theme meta; those are
		// accepted as a fallback (and the migration stamps them), which is
		// what keeps an upgrading install working.
		$posts = get_posts(
			array_merge(
				$base,
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => CLARA_VE_PAGE_KEY_META,
							'value' => sanitize_key( $key ),
						),
						array(
							'key'   => CLARA_VE_PAGE_THEME_META,
							'value' => sanitize_key( get_stylesheet() ),
						),
					),
				)
			)
		);
		if ( $posts ) {
			return $posts[0];
		}
		$unscoped = get_posts(
			array_merge(
				$base,
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => CLARA_VE_PAGE_KEY_META,
							'value' => sanitize_key( $key ),
						),
						array(
							'key'     => CLARA_VE_PAGE_THEME_META,
							'compare' => 'NOT EXISTS',
						),
					),
				)
			)
		);
		return $unscoped ? $unscoped[0] : null;
	}

	/**
	 * Mirror the given (untokenized) source into its bound WP Page's own
	 * post_content, so WordPress's normal rendering pipeline shows it to
	 * visitors. The keyed option — not this Page row — remains the single
	 * source of truth; this is purely a render target kept in sync on every
	 * save. Silently no-ops if the current user lacks unfiltered_html (this
	 * writes raw HTML/attributes verbatim) or if no Page is bound to this
	 * key yet.
	 *
	 * @param string $key
	 * @param string $source
	 * @return void
	 */
	private static function sync_to_page( $key, $source ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}
		$page = self::find_page_by_key( $key );
		if ( ! $page ) {
			return;
		}

		$content = "<!-- wp:html -->\n<!-- clara-ve-key: {$key} -->\n{$source}\n<!-- /wp:html -->";

		// Plugin-driven saves (including every AI Assistant edit) shouldn't
		// spam WordPress's native Revisions UI with internal churn — the
		// plugin's own History table already gives full point-in-time
		// versioning for this content. A human hand-editing the Custom HTML
		// block directly in wp-admin still gets a normal revision, since
		// that path never calls this function.
		remove_action( 'post_updated', 'wp_save_post_revision' );
		wp_update_post(
			array(
				'ID'           => $page->ID,
				'post_content' => $content,
			)
		);
		add_action( 'post_updated', 'wp_save_post_revision' );
	}

	/**
	 * Mirror the given (untokenized) source into the header/footer template
	 * part's own WordPress-native override — a wp_template_part post keyed
	 * by post_name + the "wp_theme" taxonomy, exactly what the Site Editor's
	 * own "Save" button writes when a template part is customized. Once such
	 * a post exists, get_block_template() prefers it over the theme file
	 * automatically; no separate render-time hook is needed the way the
	 * front page's pattern override requires. Silently no-ops if the current
	 * user lacks unfiltered_html (this writes raw HTML verbatim).
	 *
	 * @param string $key CLARA_VE_HEADER_KEY or CLARA_VE_FOOTER_KEY.
	 * @param string $source
	 * @return void
	 */
	private static function sync_to_template_part( $key, $source ) {
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			return;
		}
		$theme    = get_stylesheet();
		$template = get_block_template( $theme . '//' . $key, 'wp_template_part' );

		// parts/header.html carries a SECOND, non-editable wp:html block (the
		// mobile drawer — see the theme file's own comment) that must survive
		// every save untouched; a naive whole-file overwrite would silently
		// delete it the first time this key is ever saved. Re-parsing the
		// template's current blocks and only replacing the one this key owns
		// is what makes that safe regardless of how many other blocks share
		// the file — footer.html, with only one block, is just the trivial
		// case of the same code path.
		$content = self::replace_marked_block( $template ? $template->content : '', $key, $source );

		if ( $template && 'custom' === $template->source && $template->wp_id ) {
			remove_action( 'post_updated', 'wp_save_post_revision' );
			wp_update_post(
				array(
					'ID'           => $template->wp_id,
					'post_content' => $content,
				)
			);
			add_action( 'post_updated', 'wp_save_post_revision' );
			return;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'wp_template_part',
				'post_name'    => $key,
				'post_title'   => ucfirst( $key ),
				'post_content' => $content,
				'post_status'  => 'publish',
				'tax_input'    => array( 'wp_theme' => array( $theme ) ),
			),
			true
		);
		if ( ! is_wp_error( $post_id ) ) {
			// tax_input above requires 'assign_terms' on the (unregistered-
			// for-this-cap) wp_theme taxonomy to actually take during
			// wp_insert_post() — setting it explicitly here is the reliable
			// path regardless of that capability wiring.
			wp_set_post_terms( $post_id, array( $theme ), 'wp_theme' );
		}
	}

	/**
	 * Find the core/html block in $full_content whose content carries
	 * "<!-- clara-ve-key: {$key} -->" and return just its own inner HTML,
	 * marker comment stripped. Any other block in the file (e.g. the
	 * drawer's own separate wp:html block) is left untouched/ignored.
	 *
	 * @param string $full_content A template part's full block markup.
	 * @param string $key
	 * @return string
	 */
	private static function extract_marked_block( $full_content, $key ) {
		$marker = '<!-- clara-ve-key: ' . $key . ' -->';
		foreach ( parse_blocks( (string) $full_content ) as $block ) {
			if ( 'core/html' === $block['blockName'] && false !== strpos( (string) $block['innerHTML'], $marker ) ) {
				return trim( str_replace( $marker, '', (string) $block['innerHTML'] ) );
			}
		}
		return '';
	}

	/**
	 * Re-parse $full_content's blocks and replace ONLY the core/html block
	 * marked for $key with $new_source, re-serializing every other block
	 * (e.g. the drawer's) byte-for-byte untouched. Appends a fresh marked
	 * block if $full_content doesn't carry one yet (first-ever save).
	 *
	 * @param string $full_content
	 * @param string $key
	 * @param string $new_source
	 * @return string
	 */
	private static function replace_marked_block( $full_content, $key, $new_source ) {
		$marker    = '<!-- clara-ve-key: ' . $key . ' -->';
		$new_inner = "\n{$marker}\n{$new_source}\n";
		$blocks    = parse_blocks( (string) $full_content );
		$found     = false;

		foreach ( $blocks as &$block ) {
			if ( 'core/html' === $block['blockName'] && false !== strpos( (string) $block['innerHTML'], $marker ) ) {
				$block['innerHTML']    = $new_inner;
				$block['innerContent'] = array( $new_inner );
				$found                 = true;
				break;
			}
		}
		unset( $block );

		if ( ! $found ) {
			$blocks[] = array(
				'blockName'    => 'core/html',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => $new_inner,
				'innerContent' => array( $new_inner ),
			);
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Strip the wp:html block wrapper and the leading clara-ve-key comment
	 * from a tagged page's raw post_content, leaving just the source HTML.
	 *
	 * @param string $content
	 * @param string $key
	 * @return string
	 */
	private static function strip_wp_html_wrapper( $content, $key ) {
		$content = preg_replace( '/^\s*<!--\s*wp:html\s*-->/', '', $content );
		$content = preg_replace( '/<!--\s*\/wp:html\s*-->\s*$/', '', $content );
		$content = preg_replace( '/^\s*<!--\s*clara-ve-key:\s*' . preg_quote( $key, '/' ) . '\s*-->/', '', $content, 1 );
		return trim( $content );
	}
}
