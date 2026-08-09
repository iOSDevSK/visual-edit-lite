<?php
/**
 * A read-only structural report on how legible this site is to search and
 * answer engines.
 *
 * READ-ONLY IS THE WHOLE DESIGN, not a limitation to be lifted later. This
 * plugin's promise is that a converted site stays byte-for-byte the design it
 * was, and a one-click "fix" that rewrites markup — inserting a heading,
 * inventing alt text, reordering h2s — breaks exactly that promise, silently,
 * on the one thing the owner paid for. So every finding here names a problem and
 * where it is, and stops. Nothing in this file writes to a page's source, and
 * nothing should ever be added that does.
 *
 * Everything is deterministic and reads what is already stored: no model, no
 * network, no scoring anybody has to trust. Same rule as the rest of GEO —
 * extract, never author.
 *
 * @package VisualEdit
 */

defined( 'ABSPATH' ) || exit;

class Clara_VE_GEO_Audit {

	const PAGE = 'visual-edit-geo';

	/** Report cache. Cheap to rebuild, but not on every admin page load. */
	const CACHE_KEY = 'clara_ve_geo_audit';
	const CACHE_TTL = 900;

	/**
	 * A meta description shorter than this says nothing; longer than this gets
	 * cut off. Both are guidance — Google measures pixels, not characters — so
	 * they are reported as warnings and never as errors.
	 */
	const DESC_MIN = 50;
	const DESC_MAX = 160;

	/**
	 * How many posts the report looks at. A bound rather than a truncation
	 * nobody is told about: past this the screen says so out loud, because a
	 * report that quietly stops counting reads as "everything is fine".
	 */
	const MAX_POSTS = 200;

	/**
	 * Words allowed in the paragraph that answers a question-shaped heading.
	 *
	 * This is the one genuinely GEO-specific check here. An answer engine quotes
	 * the passage directly under the heading that matches the question; if that
	 * passage opens with three sentences of scene-setting, there is nothing
	 * concise to quote and the page loses the citation to somebody who got to the
	 * point. Only applied under headings that actually ask something, which is
	 * what keeps it from nagging about ordinary prose.
	 */
	const ANSWER_MAX_WORDS = 60;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );

		// The report describes the site as it is now, so anything that changes
		// the site has to drop it. Without this it is a snapshot: an owner fixes
		// a description, comes back, and is still told about the problem they
		// just fixed — which teaches them to distrust the whole screen.
		add_action( 'clara_ve_source_saved', array( __CLASS__, 'flush' ) );
		add_action( 'save_post', array( __CLASS__, 'flush' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush' ) );
		add_action( 'untrashed_post', array( __CLASS__, 'flush' ) );
		// Site-wide facts the report checks — entity name, logo, crawler switch —
		// live in options, and changing one changes the report.
		add_action( 'update_option_clara_ve_seo_entity_name', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_clara_ve_seo_entity_logo', array( __CLASS__, 'flush' ) );
		add_action( 'update_option_clara_ve_seo_same_as', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Drop the cached report. Cheap — it is rebuilt on the next visit to the
	 * screen, not here, so a save never pays for a full re-scan.
	 */
	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	public static function register_page() {
		// "SEO" is in the label for one unglamorous reason: it is the word people
		// look for. Before this, the admin of a site with complete SEO did not
		// contain the string "SEO" anywhere — so an owner told to "check your
		// SEO" searched, found nothing, and reasonably concluded the site had
		// none. The next thing they do is install a plugin they do not need.
		add_submenu_page(
			'visual-edit',
			__( 'SEO &amp; AI Readiness', 'visual-edit-lite' ),
			__( 'SEO &amp; AI Readiness', 'visual-edit-lite' ) . self::menu_badge(),
			'edit_theme_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A count bubble on the menu item, the way WordPress marks comments waiting
	 * for moderation.
	 *
	 * The point is that a converted site does not stay converted. The owner adds
	 * a page next month, and nothing about writing a page reminds anybody that it
	 * has no description — the readiness screen knows, but only if somebody
	 * thinks to look. A number on the menu is the difference between a report
	 * that is available and one that is seen.
	 *
	 * Read from the CACHE ONLY, never computed here: this runs on every single
	 * admin page load, and scanning every page's markup to decorate a menu label
	 * would be a tax on the whole admin. So the badge appears once the screen has
	 * been opened, and after any change that drops the cache it stays absent
	 * until the next visit rebuilds it. A number that is sometimes missing is a
	 * fair trade for an admin that is never slow.
	 *
	 * @return string
	 */
	private static function menu_badge() {
		$report = get_transient( self::CACHE_KEY );
		if ( ! is_array( $report ) || empty( $report['counts']['error'] ) ) {
			return '';
		}
		$count = (int) $report['counts']['error'];
		return sprintf(
			' <span class="awaiting-mod"><span class="pending-count">%d</span></span>',
			$count
		);
	}

	// ------------------------------------------------------------------- run

	/**
	 * Every finding, grouped by page, plus the site-wide ones.
	 *
	 * @param bool $fresh Skip the cache.
	 * @return array{site:array,pages:array,counts:array}
	 */
	public static function run( $fresh = false ) {
		if ( ! $fresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$pages        = array();
		$descriptions = array();
		$titles       = array();

		foreach ( self::collect_items() as $item ) {
			$seo = $item['post_id'] ? Clara_VE_SEO::get( $item['post_id'] ) : Clara_VE_SEO::blank();

			// The description that will ACTUALLY be emitted, not just the one
			// stored. A post normally has no SEO record — its description comes
			// from the excerpt at render time — so judging it on the record alone
			// would report every well-written post as having no description.
			$effective = '' !== $seo['description'] ? $seo['description'] : $item['fallback_description'];

			$findings = array_merge(
				// A post's h1 comes from the layout, not from its body.
				self::check_headings( $item['html'], 'post' === $item['kind'] ),
				self::check_images( $item['html'] ),
				self::check_answers( $item['html'] ),
				self::check_description( $seo, $effective, $item['kind'] )
			);

			if ( '' !== $effective ) {
				$descriptions[ strtolower( $effective ) ][] = $item['label'];
			}
			if ( '' !== $seo['title'] ) {
				$titles[ strtolower( $seo['title'] ) ][] = $item['label'];
			}

			$pages[] = array(
				'key'      => $item['key'],
				'label'    => $item['label'],
				'kind'     => $item['kind'],
				'edit_url' => $item['edit_url'],
				'findings' => $findings,
			);
		}

		// Duplicates can only be seen across the whole set, so they are attached
		// afterwards rather than found per page.
		// "elsewhere", not "on another page": posts are in this set now, so the
		// other thing sharing the description is often not a page at all.
		self::attach_duplicates( $pages, $descriptions, 'error', __( 'This description is used elsewhere too: %s. Two pages claiming the same summary compete with each other.', 'visual-edit-lite' ) );
		self::attach_duplicates( $pages, $titles, 'warning', __( 'This title is used elsewhere too: %s.', 'visual-edit-lite' ) );

		$report = array(
			'site'   => self::check_site(),
			'pages'  => $pages,
			'counts' => self::tally( $pages ),
		);
		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );
		return $report;
	}

	/**
	 * Everything on the site that has a search appearance of its own.
	 *
	 * Pages AND posts. Posts were missing at first, and the omission was worse
	 * than a gap: blog posts are the content an owner adds most often and the
	 * only content they add without going through the visual editor at all, so
	 * the one screen that says "here is what needs attention" was silent about
	 * exactly the pages most likely to need it — and a badge reading zero is a
	 * promise, not an absence of information.
	 *
	 * @return array<int,array{key:string,label:string,kind:string,html:string,post_id:int,fallback_description:string,edit_url:string}>
	 */
	private static function collect_items() {
		$items = array();

		foreach ( Clara_VE_Source_Store::list_keys() as $entry ) {
			// Header, footer and the article template are fragments of every
			// page rather than pages, so "does it have one h1" is not a question
			// about them.
			if ( 'part' === $entry['kind'] ) {
				continue;
			}
			$html = (string) Clara_VE_Source_Store::get_current_source( $entry['key'] );
			if ( '' === trim( $html ) ) {
				continue;
			}
			$post_id = isset( $entry['post_id'] ) ? (int) $entry['post_id'] : 0;
			$items[] = array(
				'key'                  => $entry['key'],
				'label'                => $entry['title'],
				'kind'                 => 'page',
				'html'                 => $html,
				'post_id'              => $post_id,
				'fallback_description' => $post_id ? trim( wp_strip_all_tags( (string) get_the_excerpt( $post_id ) ) ) : '',
				'edit_url'             => admin_url( 'admin.php?page=visual-edit&key=' . rawurlencode( $entry['key'] ) ),
			);
		}

		$posts = get_posts(
			array(
				'post_type'     => 'post',
				'post_status'   => array( 'publish', 'future', 'draft', 'pending' ),
				'numberposts'   => self::MAX_POSTS,
				'orderby'       => 'date',
				'order'         => 'DESC',
				'no_found_rows' => true,
			)
		);
		foreach ( $posts as $post ) {
			$items[] = array(
				'key'     => 'post-' . $post->ID,
				'label'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
				'kind'    => 'post',
				// A post's own body, not the shared article layout wrapped around
				// it — the layout is one template checked once, not once per post.
				'html'    => (string) $post->post_content,
				'post_id' => (int) $post->ID,
				// This is where a post's description normally comes from, so it is
				// the value to judge rather than the empty record.
				'fallback_description' => trim( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) ),
				// Posts open in the normal WordPress editor. They are not
				// visual-edit keys and the Visual Editor cannot show them.
				'edit_url' => (string) get_edit_post_link( $post->ID, 'raw' ),
			);
		}

		return $items;
	}

	/**
	 * @param array $pages
	 * @return array{error:int,warning:int,note:int}
	 */
	private static function tally( $pages ) {
		$counts = array(
			'error'   => 0,
			'warning' => 0,
			'note'    => 0,
		);
		foreach ( $pages as $page ) {
			foreach ( $page['findings'] as $finding ) {
				if ( isset( $counts[ $finding['level'] ] ) ) {
					++$counts[ $finding['level'] ];
				}
			}
		}
		return $counts;
	}

	/**
	 * @param string $level 'error' | 'warning' | 'note'
	 * @param string $text
	 * @return array
	 */
	private static function finding( $level, $text ) {
		return array(
			'level' => $level,
			'text'  => $text,
		);
	}

	// ---------------------------------------------------------------- checks

	/**
	 * Headings: exactly one h1, and no skipped level.
	 *
	 * @param string $html
	 * @return array
	 */
	private static function check_headings( $html, $implicit_h1 = false ) {
		$out = array();

		preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $html, $matches, PREG_SET_ORDER );
		$levels = array();
		foreach ( $matches as $match ) {
			$levels[] = (int) $match[1];
		}

		// A post's body has no h1 and should not: the article template renders
		// the post title as the h1, so the finished page has exactly one while
		// post_content has none. Checking the body for one reported every single
		// post on the site as broken — nine of them here — which is how a report
		// stops being read. Verified against a rendered post: one h1 on the page,
		// zero in the content, body headings starting at h2.
		$h1 = count( array_keys( $levels, 1, true ) );
		if ( $implicit_h1 ) {
			if ( $h1 > 0 ) {
				$out[] = self::finding( 'warning', __( 'This post has its own h1, on top of the one the layout makes from the title. That is two.', 'visual-edit-lite' ) );
			}
			// Seeded to 1 so a body that opens at h3 is still reported as
			// skipping a level — the h1 above it is real, it just is not here.
			$previous = 1;
		} elseif ( 0 === $h1 ) {
			$out[]    = self::finding( 'warning', __( 'No h1. Nothing on the page states what it is about in one line.', 'visual-edit-lite' ) );
			$previous = 0;
		} else {
			$previous = 0;
			if ( $h1 > 1 ) {
				$out[] = self::finding(
					'error',
					sprintf(
						/* translators: %d: number of h1 elements */
						__( '%d h1 headings. A page can only be about one thing; more than one leaves it ambiguous.', 'visual-edit-lite' ),
						$h1
					)
				);
			}
		}

		// A jump — h2 straight to h4 — breaks the outline a parser builds from
		// the levels. Reported once, with the first place it happens, rather
		// than once per occurrence. $previous is seeded above, deliberately: for
		// a post it starts at 1 for the h1 the layout supplies, and resetting it
		// here would throw that away.
		foreach ( $levels as $level ) {
			if ( $previous && $level > $previous + 1 ) {
				$out[] = self::finding(
					'warning',
					sprintf(
						/* translators: 1: heading level jumped from, 2: heading level jumped to */
						__( 'Heading levels jump from h%1$d straight to h%2$d, so the outline of the page has a gap in it.', 'visual-edit-lite' ),
						$previous,
						$level
					)
				);
				break;
			}
			$previous = $level;
		}

		return $out;
	}

	/**
	 * Images with no alt text. An image without one is a blank space to anything
	 * reading the page rather than looking at it.
	 *
	 * @param string $html
	 * @return array
	 */
	private static function check_images( $html ) {
		$missing = 0;
		foreach ( self::tags( $html, 'img' ) as $tag ) {
			// A deliberately empty alt="" is a real, correct choice for
			// decoration — it says "skip me" — so only a MISSING attribute
			// counts. Guessing which images are decorative is not something a
			// lint can do, and it should not try.
			if ( ! preg_match( '#\balt\s*=#i', $tag ) ) {
				++$missing;
			}
		}
		if ( ! $missing ) {
			return array();
		}
		return array(
			self::finding(
				'warning',
				sprintf(
					/* translators: %d: number of images */
					_n(
						'%d image has no alt attribute.',
						'%d images have no alt attribute.',
						$missing,
						'visual-edit-lite'
					),
					$missing
				)
			),
		);
	}

	/**
	 * Question-shaped headings whose answer does not come first.
	 *
	 * @param string $html
	 * @return array
	 */
	private static function check_answers( $html ) {
		$out = array();
		if ( ! preg_match_all( '#<h([2-4])\b[^>]*>([^<]*\?)\s*</h\1>(.*?)(?=<h[2-4]\b|$)#is', $html, $matches, PREG_SET_ORDER ) ) {
			return $out;
		}
		foreach ( $matches as $match ) {
			if ( ! preg_match( '#<p\b[^>]*>(.*?)</p>#is', $match[3], $para ) ) {
				continue;
			}
			$words = str_word_count( wp_strip_all_tags( $para[1] ) );
			if ( $words <= self::ANSWER_MAX_WORDS ) {
				continue;
			}
			$out[] = self::finding(
				'note',
				sprintf(
					/* translators: 1: the heading text, 2: word count */
					__( '“%1$s” is answered by a %2$d-word paragraph. Answer engines quote the passage right under the question, so a shorter opening sentence is what gets cited.', 'visual-edit-lite' ),
					trim( wp_strip_all_tags( $match[2] ) ),
					$words
				)
			);
			if ( count( $out ) >= 3 ) {
				break; // enough to make the point; a list of twenty is noise
			}
		}
		return $out;
	}

	/**
	 * @param array $seo
	 * @return array
	 */
	private static function check_description( $seo, $effective = null, $kind = 'page' ) {
		$description = null === $effective
			? ( isset( $seo['description'] ) ? (string) $seo['description'] : '' )
			: (string) $effective;

		if ( '' === $description ) {
			if ( ! empty( $seo['noindex'] ) ) {
				return array(); // hidden on purpose; it needs no summary
			}
			// The fix is in a different place for each, so the advice has to be
			// too: a post's description comes from its excerpt, and telling its
			// author to open the Visual Editor would send them somewhere a post
			// cannot even be opened.
			$text = 'post' === $kind
				? __( 'No excerpt, so this post has no description. Search results and AI answers will quote whatever sentence they happen to find. Add one in the post editor, under Excerpt.', 'visual-edit-lite' )
				: __( 'No description. Search results and AI answers will quote whatever sentence they happen to find.', 'visual-edit-lite' );
			return array( self::finding( 'error', $text ) );
		}

		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $description ) : strlen( $description );
		if ( $length < self::DESC_MIN ) {
			return array(
				self::finding(
					'warning',
					sprintf(
						/* translators: %d: character count */
						__( 'The description is only %d characters — too short to say what the page offers.', 'visual-edit-lite' ),
						$length
					)
				),
			);
		}
		if ( $length > self::DESC_MAX ) {
			return array(
				self::finding(
					'warning',
					sprintf(
						/* translators: %d: character count */
						__( 'The description is %d characters, so the end of it will be cut off.', 'visual-edit-lite' ),
						$length
					)
				),
			);
		}
		return array();
	}

	/**
	 * Site-wide facts, which are either set or not.
	 *
	 * @return array
	 */
	private static function check_site() {
		$out    = array();
		$entity = Clara_VE_GEO::entity();

		if ( '' === (string) get_option( Clara_VE_SEO::OPT_ENTITY_NAME, '' ) ) {
			$out[] = self::finding( 'warning', __( 'No business or personal name is set, so the site name is being used instead.', 'visual-edit-lite' ) );
		}
		if ( ! $entity['same_as'] ) {
			$out[] = self::finding( 'note', __( 'No social or directory profiles are listed. These are how an answer engine confirms the site belongs to a real, identifiable business.', 'visual-edit-lite' ) );
		}
		if ( '' === $entity['logo'] ) {
			$out[] = self::finding( 'note', __( 'No logo is set for the site\'s structured data.', 'visual-edit-lite' ) );
		}
		if ( 'off' === get_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'on' ) ) {
			$out[] = self::finding( 'note', __( 'AI crawler rules are switched off, so robots.txt does not mention answer engines at all.', 'visual-edit-lite' ) );
		}
		if ( ! (int) get_option( 'page_on_front' ) ) {
			$out[] = self::finding( 'error', __( 'The front page is not set as a static page, so it cannot carry its own title or description.', 'visual-edit-lite' ) );
		}
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			$out[] = self::finding( 'error', __( 'Permalinks are still set to plain, which breaks the links between imported pages.', 'visual-edit-lite' ) );
		}

		// Rank Math replaces robots.txt with its own editor, so the AI-crawler
		// rules this plugin adds through the robots_txt filter never appear.
		// Said out loud, because the alternative is a switch that reports success
		// and does nothing.
		if ( 'rankmath' === Clara_VE_SEO::host() ) {
			$out[] = self::finding(
				'warning',
				__( 'Rank Math manages robots.txt itself, so the AI crawler rules here will not appear in it. Copy them into Rank Math → General Settings → Edit robots.txt.', 'visual-edit-lite' )
			);
		}

		return $out;
	}

	// --------------------------------------------------------------- helpers

	/**
	 * Every opening tag of one element, as raw strings.
	 *
	 * @param string $html
	 * @param string $tag
	 * @return string[]
	 */
	private static function tags( $html, $tag ) {
		preg_match_all( '#<' . preg_quote( $tag, '#' ) . '\b[^>]*>#i', $html, $matches );
		return isset( $matches[0] ) ? $matches[0] : array();
	}

	/**
	 * Turn a map of value => page labels into a finding on each page that shares
	 * a value with another.
	 *
	 * @param array  $pages    Passed by reference; findings are appended.
	 * @param array  $map      value => list of page labels.
	 * @param string $level
	 * @param string $template sprintf template taking the other page names.
	 */
	private static function attach_duplicates( array &$pages, array $map, $level, $template ) {
		foreach ( $map as $labels ) {
			if ( count( $labels ) < 2 ) {
				continue;
			}
			foreach ( $labels as $label ) {
				$others = array_values( array_diff( $labels, array( $label ) ) );
				foreach ( $pages as $i => $page ) {
					if ( $page['label'] === $label ) {
						$pages[ $i ]['findings'][] = self::finding( $level, sprintf( $template, implode( ', ', $others ) ) );
						break;
					}
				}
			}
		}
	}

	// ---------------------------------------------------------------- render

	public static function render() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'visual-edit-lite' ) );
		}

		$fresh  = isset( $_GET['refresh'] ) && check_admin_referer( 'clara_ve_geo_refresh' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$report = self::run( $fresh );
		$counts = $report['counts'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SEO &amp; AI Readiness', 'visual-edit-lite' ); ?></h1>

			<p class="description" style="max-width:44em;">
				<?php esc_html_e( 'What this site tells search engines and AI assistants about itself. Everything here is a report — nothing on this screen changes a page, because the pages are meant to stay exactly as they were designed. Fix anything you want to act on in the Visual Editor.', 'visual-edit-lite' ); ?>
			</p>

			<?php self::render_status( $report ); ?>

			<h2><?php esc_html_e( 'Worth your attention', 'visual-edit-lite' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: error count, 2: warning count, 3: note count */
					esc_html__( '%1$d worth fixing, %2$d worth a look, %3$d suggestions.', 'visual-edit-lite' ),
					(int) $counts['error'],
					(int) $counts['warning'],
					(int) $counts['note']
				);
				?>
				<a class="button" style="margin-left:.6em;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&refresh=1' ), 'clara_ve_geo_refresh' ) ); ?>">
					<?php esc_html_e( 'Re-check', 'visual-edit-lite' ); ?>
				</a>
			</p>

			<?php if ( $report['site'] ) : ?>
				<h2><?php esc_html_e( 'The site as a whole', 'visual-edit-lite' ); ?></h2>
				<?php self::render_findings( $report['site'] ); ?>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Clara_VE_SEO_Settings::PAGE ) ); ?>">
						<?php esc_html_e( 'Change these under SEO &amp; Sharing', 'visual-edit-lite' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Page by page', 'visual-edit-lite' ); ?></h2>
			<table class="widefat striped" style="max-width:60em;">
				<thead>
					<tr>
						<th style="width:16em;"><?php esc_html_e( 'Page', 'visual-edit-lite' ); ?></th>
						<th><?php esc_html_e( 'Findings', 'visual-edit-lite' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $report['pages'] as $page ) : ?>
					<?php $is_post = isset( $page['kind'] ) && 'post' === $page['kind']; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $page['label'] ); ?></strong>
							<?php if ( $is_post ) : ?>
								<span class="description">— <?php esc_html_e( 'post', 'visual-edit-lite' ); ?></span>
							<?php endif; ?>
							<br />
							<a href="<?php echo esc_url( isset( $page['edit_url'] ) ? $page['edit_url'] : '' ); ?>">
								<?php
								// A post has no visual-edit key, so "open in editor"
								// would be a dead end — its excerpt is edited in the
								// normal post editor.
								echo $is_post
									? esc_html__( 'Edit post', 'visual-edit-lite' )
									: esc_html__( 'Open in editor', 'visual-edit-lite' );
								?>
							</a>
						</td>
						<td>
							<?php if ( ! $page['findings'] ) : ?>
								<span style="color:#008a20;">&#10003; <?php esc_html_e( 'Nothing to report.', 'visual-edit-lite' ); ?></span>
							<?php else : ?>
								<?php self::render_findings( $page['findings'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$post_count = (int) wp_count_posts( 'post' )->publish;
			if ( $post_count > self::MAX_POSTS ) :
				?>
				<p class="description">
					<?php
					printf(
						/* translators: 1: posts checked, 2: total posts */
						esc_html__( 'Showing the %1$d most recent of %2$d posts. Older ones are not checked.', 'visual-edit-lite' ),
						(int) self::MAX_POSTS,
						$post_count
					);
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * What the site already does, before what is wrong with it.
	 *
	 * The screen used to open with "3 worth fixing" and nothing else, which
	 * answers a question the owner did not ask and never answers the one they
	 * did: is any of this handled at all? A converted site's SEO is invisible by
	 * nature — it lives in a <head> nobody reads and in files nobody visits — so
	 * an owner with a perfectly optimised site had no way to know, and the
	 * obvious next move is to install a plugin they do not need.
	 *
	 * Every line is read from live state and every claim is a link the owner can
	 * click to see the actual thing. Nothing here is asserted on faith.
	 *
	 * @param array $report
	 */
	private static function render_status( array $report ) {
		$host    = Clara_VE_SEO::host_label();
		$entity  = Clara_VE_GEO::entity();
		$crawl   = 'off' !== get_option( Clara_VE_GEO::OPT_AI_CRAWLERS, 'on' );
		$redirects = count( (array) get_option( Clara_VE_Redirects::OPTION, array() ) );

		// Coverage, counted from the report that was just built rather than
		// re-queried: a page is "described" when nothing told it otherwise.
		$total = count( $report['pages'] );
		$blank = 0;
		foreach ( $report['pages'] as $page ) {
			foreach ( $page['findings'] as $finding ) {
				if ( 'error' === $finding['level'] && false !== strpos( $finding['text'], 'description' ) ) {
					++$blank;
					break;
				}
			}
		}
		$described = $total - $blank;

		$rows = array(
			array(
				__( 'Titles and descriptions', 'visual-edit-lite' ),
				$host
					/* translators: %s: SEO plugin name */
					? sprintf( __( 'Handled by %s, filled in from the original design.', 'visual-edit-lite' ), $host )
					: __( 'Handled here — no SEO plugin needed.', 'visual-edit-lite' ),
				sprintf(
					/* translators: 1: pages described, 2: total pages */
					__( '%1$d of %2$d pages and posts have a description', 'visual-edit-lite' ),
					$described,
					$total
				),
				'',
			),
			array(
				__( 'Structured data', 'visual-edit-lite' ),
				sprintf(
					/* translators: 1: schema.org type, 2: business name */
					__( 'This site describes itself as a %1$s called “%2$s”.', 'visual-edit-lite' ),
					$entity['type'],
					$entity['name']
				),
				__( 'Every page carries it, blog posts as articles', 'visual-edit-lite' ),
				'https://search.google.com/test/rich-results?url=' . rawurlencode( home_url( '/' ) ),
			),
			array(
				__( 'AI assistants', 'visual-edit-lite' ),
				$crawl
					? __( 'Named in robots.txt and pointed at a plain-text summary of the site.', 'visual-edit-lite' )
					: __( 'Switched off — robots.txt does not mention them.', 'visual-edit-lite' ),
				'llms.txt',
				home_url( '/llms.txt' ),
			),
			array(
				__( 'Old addresses', 'visual-edit-lite' ),
				$redirects
					? __( 'Addresses from before the conversion still work, so existing links and search results are not lost.', 'visual-edit-lite' )
					: __( 'No redirects are stored. If this site replaced an older one, its old addresses now return "not found".', 'visual-edit-lite' ),
				sprintf(
					/* translators: %d: number of redirects */
					_n( '%d address redirected', '%d addresses redirected', $redirects, 'visual-edit-lite' ),
					$redirects
				),
				'',
			),
			array(
				__( 'Sitemap', 'visual-edit-lite' ),
				__( 'Generated by WordPress; pages you hide from search are left out.', 'visual-edit-lite' ),
				'wp-sitemap.xml',
				home_url( '/wp-sitemap.xml' ),
			),
		);
		?>
		<h2><?php esc_html_e( 'What this site already does', 'visual-edit-lite' ); ?></h2>
		<table class="widefat striped" style="max-width:60em;margin-bottom:2em;">
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td style="width:12em;"><strong><?php echo esc_html( $row[0] ); ?></strong></td>
					<td><?php echo esc_html( $row[1] ); ?></td>
					<td style="width:16em;">
						<?php if ( $row[3] ) : ?>
							<a href="<?php echo esc_url( $row[3] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $row[2] ); ?></a>
						<?php else : ?>
							<span class="description"><?php echo esc_html( $row[2] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array $findings
	 */
	private static function render_findings( array $findings ) {
		$colours = array(
			'error'   => '#b32d2e',
			'warning' => '#bd8600',
			'note'    => '#646970',
		);
		echo '<ul style="margin:0;">';
		foreach ( $findings as $finding ) {
			$colour = isset( $colours[ $finding['level'] ] ) ? $colours[ $finding['level'] ] : '#646970';
			printf(
				'<li style="margin:0 0 .4em;"><span style="color:%1$s;font-weight:600;">&bull;</span> %2$s</li>',
				esc_attr( $colour ),
				esc_html( $finding['text'] )
			);
		}
		echo '</ul>';
	}
}

Clara_VE_GEO_Audit::init();
