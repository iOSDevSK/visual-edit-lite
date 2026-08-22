<?php
/**
 * Git-like save history for edited page source, scoped per page_key
 * (CLARA_VE_DEFAULT_KEY for the front page, an arbitrary key for any other
 * "visual-edit enabled" WP Page — see CLARA_VE_PAGE_KEY_META).
 *
 * Every successful save — and every restore, which is itself just a new save
 * of an old snapshot — is appended as an immutable, content-addressed entry
 * in a dedicated DB table. History is append-only: restoring an old entry
 * never rewrites the log, it reads that entry's content and writes it back as
 * a brand new entry, so the user can always go forward again afterwards, the
 * same way `git revert`/checkout-then-commit behaves.
 *
 * Storage stays small on purpose: each entry is the full page HTML gzip'd
 * (a single-page template runs a few KB compressed), byte-identical no-op
 * saves (within the same page_key) are skipped, and a hard per-page_key row
 * cap prunes the oldest entries so the table can never grow unbounded — a
 * shared global cap would let an actively-edited page silently evict a
 * rarely-touched one's entire history, so the cap is deliberately per-key.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_History {

	// Hard ceiling on stored entries PER page_key. Gzip'd single-page HTML
	// runs roughly 8-20KB per entry, so even at the cap one page's history
	// stays a few MB at most.
	const MAX_ENTRIES = 300;

	// How many entries an UNLICENSED install may see and restore (plus the
	// Original, which is always offered — see visible_entries()). Storage is
	// NOT trimmed to this: the full log keeps accumulating up to MAX_ENTRIES,
	// so activating a licence later reveals the history that was recorded all
	// along rather than a hole.
	const VISIBLE_ENTRIES = 10;   // How many saves the list shows. Everything is still recorded to MAX_ENTRIES.

	const DB_VERSION_OPTION = 'clara_ve_history_db_version';
	const DB_VERSION        = '3';

	/**
	 * @return string Fully-qualified table name (respects the site's prefix).
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'clara_ve_history';
	}

	/**
	 * Idempotent — safe to call on every request. Only touches the DB when the
	 * schema version changed (fresh install or a future plugin update), so
	 * existing sites get the table created/migrated automatically without
	 * needing to deactivate/reactivate the plugin. Adding page_key via
	 * dbDelta backfills every existing row to CLARA_VE_DEFAULT_KEY
	 * automatically (confirmed MySQL/dbDelta behavior for an
	 * ADD COLUMN ... NOT NULL DEFAULT) — no separate migration script needed.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;
		$table           = self::table();
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta is picky about formatting: one column per line, two spaces
		// before PRIMARY KEY, no trailing commas.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			page_key VARCHAR(191) NOT NULL DEFAULT '" . CLARA_VE_DEFAULT_KEY . "',
			content LONGBLOB NOT NULL,
			content_hash CHAR(64) NOT NULL,
			pseudo LONGTEXT NULL,
			responsive LONGTEXT NULL,
			message VARCHAR(255) NULL,
			kind VARCHAR(20) NOT NULL DEFAULT 'save',
			restored_from_id BIGINT UNSIGNED NULL,
			author BIGINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY content_hash (content_hash),
			KEY page_key (page_key)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Append a new entry. No-ops (returns null) when the content is
	 * byte-for-byte identical to the current HEAD for this page_key, so
	 * repeated no-op saves don't spam the log with empty commits.
	 *
	 * @param string      $tokenized_source Tokenized HTML (theme URI already replaced) — same
	 *                                      representation Clara_VE_Source_Store persists.
	 * @param array       $pseudo           The full, resolved pseudo-ornament map at save time.
	 * @param string      $kind             'save' or 'restore'.
	 * @param string|null $message          Optional custom message; null = auto-label in list_entries().
	 * @param int|null    $restored_from_id When $kind is 'restore', the id of the entry that was restored.
	 * @param string      $page_key         Which page this entry belongs to.
	 * @return int|null The new row id, or null if this was a no-op (identical to HEAD).
	 */
	/**
	 * A history row's page_key, scoped to the active theme.
	 *
	 * Two converted themes on one install both have a page called "about", and
	 * an unscoped key made their save histories one pile — so the editor would
	 * offer revisions authored in a different design, and restoring one wrote
	 * that design's markup into this theme's page. Scoping the stored VALUE
	 * rather than adding a column keeps all six queries and their indexes
	 * exactly as they are; the separator is a double underscore because
	 * sanitize_key() strips a slash, and every row goes through this.
	 *
	 * @param string $page_key
	 * @return string
	 */
	private static function scoped_key( $page_key ) {
		$theme = sanitize_key( get_stylesheet() );
		$key   = sanitize_key( $page_key );
		// A block page's content lives in post_content and survives a theme
		// switch; its log has to survive one too. Scoping it by theme would
		// hide every revision of a page whose content never moved anywhere
		// the moment somebody changed themes — and the page would still be
		// sitting there, apparently never edited.
		if ( 0 === strpos( $key, Clara_VE_Source_Store::BLOCK_KEY_PREFIX ) ) {
			return $key;
		}
		// Idempotent on purpose. ensure_baseline() used to scope the key and
		// then hand it to record(), which scoped it AGAIN — every "Original"
		// landed under {theme}__{theme}__{key}, a bucket the history panel
		// never reads, so the one path back an owner has was invisible on
		// every delivered site, and each save re-seeded another dead row.
		// Callers now pass raw keys, and this guard makes the double-scope
		// class impossible rather than merely fixed once.
		if ( 0 === strpos( $key, $theme . '__' ) ) {
			return $key;
		}
		return $theme . '__' . $key;
	}

	/**
	 * The pre-scoping value for a key — what the migration renames from.
	 *
	 * @param string $page_key
	 * @return string
	 */
	public static function legacy_key( $page_key ) {
		return sanitize_key( $page_key );
	}

	/**
	 * One-shot repair for rows the double-scope bug wrote: every
	 * {theme}__{theme}__{key} row is either renamed into the real bucket
	 * (the oldest one — it holds the true imported Original) or deleted
	 * (the younger re-seeds, which are copies of the same dead baseline).
	 *
	 * @return void
	 */
	public static function rekey_double_scoped() {
		if ( '1' === get_option( 'clara_ve_history_rekey_v1', '' ) ) {
			return;
		}
		update_option( 'clara_ve_history_rekey_v1', '1', false );
		global $wpdb;
		$table  = self::table();
		$themes = array_map( 'sanitize_key', array_keys( (array) wp_get_themes() ) );
		if ( class_exists( 'Clara_VE_Theme_Registry' ) ) {
			$themes = array_unique( array_merge( $themes, array_map( 'sanitize_key', array_keys( Clara_VE_Theme_Registry::all() ) ) ) );
		}
		foreach ( $themes as $theme ) {
			$double = $theme . '__' . $theme . '__';
			$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, page_key FROM {$table} WHERE page_key LIKE %s ORDER BY id ASC", $wpdb->esc_like( $double ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
			$seen   = array();
			foreach ( $rows as $row ) {
				$fixed = $theme . '__' . substr( $row->page_key, strlen( $double ) );
				if ( isset( $seen[ $row->page_key ] ) ) {
					// A younger re-seeded "Original" — the same dead baseline
					// recorded again; renaming it too would litter the panel.
					$wpdb->delete( $table, array( 'id' => $row->id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					continue;
				}
				$seen[ $row->page_key ] = true;
				$wpdb->update( $table, array( 'page_key' => $fixed ), array( 'id' => $row->id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}
	}

	public static function record( $tokenized_source, $pseudo, $kind = 'save', $message = null, $restored_from_id = null, $page_key = CLARA_VE_DEFAULT_KEY, $responsive = null ) {
		global $wpdb;
		self::maybe_install();

		$page_key = self::scoped_key( $page_key );
		$hash     = hash( 'sha256', $tokenized_source );
		$head     = self::head( $page_key );
		$rules    = ( is_array( $responsive ) && $responsive ) ? wp_json_encode( $responsive ) : null;

		// Identical content is normally nothing to record. But a page's
		// small-screen rules live outside its content, so changing only those
		// — giving a section less padding on a phone and saving — leaves the
		// markup byte-identical. Skipping on the hash alone would mean that
		// edit was never versioned, and the owner would find nothing to go
		// back to for a change they definitely made.
		$same_rules = ( ( $head && isset( $head->responsive ) ? $head->responsive : null ) === $rules );
		if ( $head && $head->content_hash === $hash && $same_rules && 'restore' !== $kind ) {
			return null;
		}

		$wpdb->insert(
			self::table(),
			array(
				'page_key'         => $page_key,
				'content'          => gzcompress( $tokenized_source, 6 ),
				'content_hash'     => $hash,
				'pseudo'           => ( is_array( $pseudo ) && $pseudo ) ? wp_json_encode( $pseudo ) : null,
				// Small-screen rules travel WITH the version they belong to.
				// A restore that brought a section back without the padding it
				// was given for phones would be telling the owner it had put
				// the page back when it had not.
				'responsive'       => $rules,
				'message'          => $message ? sanitize_text_field( $message ) : null,
				'kind'             => ( 'restore' === $kind ) ? 'restore' : 'save',
				'restored_from_id' => $restored_from_id ? (int) $restored_from_id : null,
				'author'           => get_current_user_id(),
				'created_at'       => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);

		$id = $wpdb->insert_id;
		self::prune( $page_key );
		return $id;
	}

	/**
	 * Guarantee a page's log is never empty: if no entry exists yet for this
	 * page_key, seed one from whatever its CURRENT live source is (the
	 * theme's pristine content for the front page when nothing has ever been
	 * saved; a freshly-created tagged page's initial content) labelled
	 * "Original". Without this, a user whose very first action is an
	 * edit-then-Save would have nothing to restore back to — the pre-edit
	 * state would never have been captured anywhere. Call this BEFORE
	 * overwriting the live source with a new save, or the "baseline" would
	 * wrongly capture the new content instead of the old. No-ops once at
	 * least one entry exists for this page_key.
	 *
	 * @param string $page_key
	 */
	public static function ensure_baseline( $page_key = CLARA_VE_DEFAULT_KEY ) {
		global $wpdb;
		self::maybe_install();
		self::rekey_double_scoped();
		$page_key = self::scoped_key( $page_key );
		$count    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE page_key = %s', $page_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $count > 0 ) {
			return;
		}
		// The RAW key from here on: get_current_source, the pseudo store and
		// record() all scope for themselves — handing them the scoped key is
		// exactly the bug this function shipped with.
		$raw = ( 0 === strpos( $page_key, Clara_VE_Source_Store::BLOCK_KEY_PREFIX ) )
			? $page_key
			: preg_replace( '/^' . preg_quote( sanitize_key( get_stylesheet() ), '/' ) . '__/', '', $page_key );
		$current = Clara_VE_Source_Store::get_current_source( $raw );
		if ( '' === trim( (string) $current ) ) {
			return; // nothing to seed yet (e.g. the pattern isn't registered)
		}
		self::record( Clara_VE_Source_Store::tokenize( $current ), Clara_VE_Pseudo_Store::get( $raw ), 'save', 'Original', null, $raw );
	}

	/**
	 * @param string $page_key
	 * @return object|null {id, content_hash} of the newest entry for this page_key, or null when empty.
	 */
	public static function head( $page_key = CLARA_VE_DEFAULT_KEY ) {
		global $wpdb;
		self::maybe_install();
		$page_key = self::scoped_key( $page_key );
		return $wpdb->get_row( $wpdb->prepare( 'SELECT id, content_hash, responsive FROM ' . self::table() . ' WHERE page_key = %s ORDER BY id DESC LIMIT 1', $page_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * One entry with content/pseudo decoded, ready to write back as the live
	 * source (still tokenized — caller resolves the theme URI). Returns null
	 * if the entry doesn't exist OR belongs to a different page_key than
	 * requested (defense-in-depth: a key=about restore can never resolve a
	 * front-page row by guessing an id).
	 *
	 * @param int    $id
	 * @param string $page_key
	 * @return array{id:int,source:string,pseudo:array}|null
	 */
	public static function get( $id, $page_key = CLARA_VE_DEFAULT_KEY ) {
		global $wpdb;
		self::maybe_install();
		$page_key = self::scoped_key( $page_key );
		$row      = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d AND page_key = %s', $id, $page_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( ! $row ) {
			return null;
		}
		return array(
			'id'         => (int) $row->id,
			'source'     => gzuncompress( $row->content ),
			'pseudo'     => $row->pseudo ? json_decode( $row->pseudo, true ) : array(),
			// Null on every row written before this column existed, which is
			// the honest answer for them: those versions predate the feature.
			'responsive' => isset( $row->responsive ) && $row->responsive
				? json_decode( $row->responsive, true )
				: array(),
		);
	}

	/**
	 * Entries newest-first for this page_key's history panel, each with a
	 * display-ready short hash and an auto-generated label when no custom
	 * message was set.
	 *
	 * @param int    $limit
	 * @param string $page_key
	 * @return array<int,array{id:int,hash:string,message:string,kind:string,isHead:bool,createdAt:string}>
	 */
	public static function list_entries( $limit = 100, $page_key = CLARA_VE_DEFAULT_KEY ) {
		global $wpdb;
		self::maybe_install();
		$page_key = self::scoped_key( $page_key );
		$table    = self::table();
		$rows     = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, content_hash, message, kind, restored_from_id, created_at FROM {$table} WHERE page_key = %s ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$page_key,
				$limit
			)
		);
		if ( ! $rows ) {
			return array();
		}

		// HEAD is whichever row's content matches what's actually live right
		// now for this page_key — NOT necessarily the newest row. A restore
		// writes the live source directly without appending a new entry
		// (only Save does that), so after a restore-without-a-following-save,
		// HEAD correctly snaps back to that older row instead of staying
		// "stuck" on the newest one.
		$current_hash  = null;
		$live_resolved = Clara_VE_Source_Store::get_resolved_source( $page_key );
		if ( is_string( $live_resolved ) && '' !== $live_resolved ) {
			$current_hash = hash( 'sha256', Clara_VE_Source_Store::tokenize( $live_resolved ) );
		}

		// Resolve the short hash of each restore's source entry for the
		// "Restore to <hash>" auto-label, in one query instead of N.
		$restore_sources = array();
		$source_ids      = array_filter( wp_list_pluck( $rows, 'restored_from_id' ) );
		if ( $source_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $source_ids ), '%d' ) );
			$found        = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, content_hash FROM {$table} WHERE id IN ({$placeholders})", $source_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			);
			foreach ( $found as $f ) {
				$restore_sources[ (int) $f->id ] = $f->content_hash;
			}
		}

		$out = array();
		foreach ( $rows as $row ) {
			$label = $row->message;
			if ( ! $label ) {
				if ( 'restore' === $row->kind && $row->restored_from_id && isset( $restore_sources[ (int) $row->restored_from_id ] ) ) {
					$label = 'Restore to ' . substr( $restore_sources[ (int) $row->restored_from_id ], 0, 7 );
				} else {
					$label = ( CLARA_VE_DEFAULT_KEY === $page_key ) ? 'Save index.html' : ( 'Save ' . $page_key );
				}
			}
			$out[] = array(
				'id'        => (int) $row->id,
				'hash'      => substr( $row->content_hash, 0, 7 ),
				'message'   => $label,
				'kind'      => $row->kind,
				'isHead'    => ( null !== $current_hash && $row->content_hash === $current_hash ),
				'createdAt' => $row->created_at,
			);
		}
		return $out;
	}

	/**
	 * What the history panel shows and what restore accepts, licence-aware.
	 *
	 * Licensed: the full list (up to MAX_ENTRIES). Unlicensed: the newest
	 * VISIBLE_ENTRIES plus — always — the oldest entry, because the
	 * Original must stay restorable at every tier. list_entries() returns
	 * newest-first, so the Original lands at the tail of the panel, where
	 * the oldest entry belongs visually anyway.
	 *
	 * This is also the server-side authority for restores: restore goes
	 * through an id allow-list derived from the same function, so hiding
	 * rows in the panel and refusing them on the wire can never disagree.
	 *
	 * @param string $page_key
	 * @return array Same shape as list_entries().
	 */
	public static function visible_entries( $page_key = CLARA_VE_DEFAULT_KEY ) {
		$all = self::list_entries( self::MAX_ENTRIES, $page_key );

		if ( count( $all ) <= self::VISIBLE_ENTRIES ) {
			return $all;
		}

		$visible = array_slice( $all, 0, self::VISIBLE_ENTRIES );
		$oldest  = end( $all );

		if ( $oldest && $oldest['id'] !== $visible[ count( $visible ) - 1 ]['id'] ) {
			$visible[] = $oldest;
		}

		return $visible;
	}

	/**
	 * Whether the given entry may be restored under the current licence.
	 *
	 * @param int    $id
	 * @param string $page_key
	 * @return bool
	 */
	public static function may_restore( $id, $page_key = CLARA_VE_DEFAULT_KEY ) {
		foreach ( self::visible_entries( $page_key ) as $entry ) {
			if ( (int) $entry['id'] === (int) $id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Rename an entry (git-style "reword"). Content/hash are untouched.
	 * No-ops (returns false) if the entry belongs to a different page_key.
	 *
	 * @param int    $id
	 * @param string $message Empty string clears back to the auto-generated label.
	 * @param string $page_key
	 * @return bool
	 */
	public static function rename( $id, $message, $page_key = CLARA_VE_DEFAULT_KEY ) {
		global $wpdb;
		self::maybe_install();
		$page_key = self::scoped_key( $page_key );
		$message  = trim( (string) $message );
		return false !== $wpdb->update(
			self::table(),
			array( 'message' => ( '' === $message ) ? null : sanitize_text_field( $message ) ),
			array(
				'id'       => (int) $id,
				'page_key' => $page_key,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Hard cap on row count FOR THIS PAGE_KEY — deletes that page's oldest
	 * entries beyond MAX_ENTRIES so storage can never grow unbounded
	 * regardless of usage, without evicting any other page's history.
	 *
	 * @param string $page_key
	 */
	private static function prune( $page_key ) {
		global $wpdb;
		$table = self::table();
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE page_key = %s", $page_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $count <= self::MAX_ENTRIES ) {
			return;
		}
		// The very first row is the "Original" baseline — the one restore
		// point the owner must ALWAYS have, at any history depth. Pruning
		// therefore starts at the second-oldest row; without this exclusion
		// entry #301 silently evicted the only path back to the imported
		// design.
		$oldest = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MIN(id) FROM {$table} WHERE page_key = %s", $page_key ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$excess = $count - self::MAX_ENTRIES;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE page_key = %s AND id <> %d ORDER BY id ASC LIMIT %d", $page_key, $oldest, $excess ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
