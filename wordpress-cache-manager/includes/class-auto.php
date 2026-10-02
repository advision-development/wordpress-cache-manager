<?php
/**
 * Auto-clear: when a post is published, clear the pages that list it.
 *
 * Why it exists. A listing page — an `/analysis/` page, say — is a Page
 * whose content is a query over posts. NitroPack tags a cached page with the posts it rendered
 * (`post:<id>`) and invalidates by those tags when a post is saved, so a page that has never
 * rendered the **new** post carries no tag for it and is not invalidated. With a 30-day expiry it
 * keeps showing the old list until somebody clears it by hand. A rule here says "when a post of
 * this kind is published, these URLs are stale", which is the one fact NitroPack cannot know.
 *
 * The rules this class holds:
 *
 * - **Off until somebody writes a rule.** No rule, or none switched on, and the hook returns
 *   after one option read. Installing the version that has this does nothing by itself.
 * - **The save never waits and never fails because of this.** The hook only marks the rule as
 *   triggered — one `add_option()` — and schedules one event. The clear runs later, in WP-Cron.
 *   The hook goes through `ADVCM_Safe`, so a fault here costs a log line, not the editor's save.
 * - **One clear per window, however many posts.** A rule triggered again while it is already
 *   marked is a no-op: `add_option()` inserts only when the row is absent, so two saves racing
 *   cannot both lose or both double it. Everything marked within WINDOW seconds goes out as one
 *   job, so a burst of twenty posts is one clear of `/analysis/`, not twenty.
 * - **Only the URLs a rule names**, filtered to this site's own like any per-URL clear, and never
 *   the whole site. A rule cannot ask for anything a person pressing "Clear these pages" could not.
 * - **NitroPack is invalidated, not purged, by default.** An auto-clear runs every time the
 *   editorial team publishes; a purge would serve the listing un-optimized after each one. A rule
 *   may ask for a purge.
 * - **Rules are written by administrators** (`advcm_purge_hard`). Anyone who can clear may read
 *   them and test a post against them.
 * - **`wp_after_insert_post`, not `transition_post_status`.** The block editor saves through REST,
 *   which sets a post's terms *after* the status transition, so a rule matching on a category
 *   would miss every new post written in Gutenberg. `wp_after_insert_post` (WordPress 5.6) fires
 *   once terms and meta are saved, for the classic editor, REST, `wp_update_post()` and a
 *   scheduled post going live alike.
 * - **A clear says what triggered it.** The mark carries the posts that set it off — the first
 *   MAX_POSTS of them and a count of the rest — and the job keeps them, so History answers "why
 *   was /analysis/ cleared at 10:42" without anybody reading the editorial log.
 * - **Only the admin form writes `advcm_rules`.** What the cron learns about a rule — when it
 *   last fired — goes to `advcm_rules_last`, a row of its own: a cron writing the rules row while
 *   an administrator edits it would put back a rule they had just deleted.
 * - **One switch pauses every rule** (`advcm_rules_paused`), read only once a rule is on, so a
 *   site with none still pays one option read per save.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rules, the save hook and the deferred clear.
 */
final class ADVCM_Auto {

	/** Where the rules are kept. */
	const RULES = 'advcm_rules';

	/** A triggered rule is a row named this prefix plus its id, until the clear picks it up. */
	const MARK = 'advcm_auto_';

	/** The event the clear runs on. */
	const FLUSH_HOOK = 'advcm_auto_flush';

	/** The admin-post action that writes rules. */
	const SAVE = 'advcm_rules';

	/** The nonce action for testing a post against the rules. */
	const TEST = 'advcm_rules_test';

	/** The job source of an auto-clear, as History shows it. */
	const SOURCE = 'auto';

	/** Seconds between the first save that triggers a rule and the clear. */
	const WINDOW = 60;

	/** How many rules a site may have. */
	const MAX_RULES = 20;

	/** How many URLs one rule may name. */
	const MAX_URLS = 10;

	/** When each rule last fired, written by the clear. Never the rules row: see the header. */
	const LAST = 'advcm_rules_last';

	/** Set while every rule is paused; holds when it was paused. */
	const PAUSED = 'advcm_rules_paused';

	/** The post type of a rule that matches every public content type. */
	const ANY = '*';

	/** How many triggering posts a waiting rule names; the rest are a count. */
	const MAX_POSTS = 10;

	/**
	 * How many post ids a waiting rule remembers, to count a post saved twice in the window once.
	 *
	 * Past this the count goes on rising for every save, a re-save included. Five hundred posts in
	 * one minute is not a newsroom, it is an import, and "and 1,200 more" is then near enough.
	 */
	const MAX_IDS = 500;

	/** The filter that may change which post types "any content" leaves out. */
	const EXCLUDED_FILTER = 'advcm_auto_excluded_post_types';

	/**
	 * Post types that are public but are not content a listing shows: media, menus, builder
	 * templates and parts, form and snippet plugins' own records. A trailing `*` is a prefix.
	 *
	 * Elementor's library and Bricks' templates are the ones that matter: both are `public` so
	 * their editors can preview them, and every save of a header template would otherwise clear
	 * every listing on the site.
	 */
	const EXCLUDED = array(
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_navigation',
		'wp_global_styles',
		'elementor_library',
		'e-landing-page',
		'e-floating-buttons',
		'bricks_template',
		'acf-*',
		'wpcode*',
		'wpforms*',
		'tablepress_table',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
	);

	/**
	 * Left out whatever the filter returns. `visible_change()` refuses these already; this keeps a
	 * filter from making "any content" look as if it covered them.
	 */
	const ALWAYS_EXCLUDED = array( 'revision', 'auto-draft', 'nav_menu_item' );

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( 'wp_after_insert_post', array( __CLASS__, 'saved' ), 10, 4 );

		// Always registered: a scheduled event nothing answers is what a scanner reports as an
		// orphaned cron event, and it would also never clear.
		ADVCM_Safe::action( self::FLUSH_HOOK, array( __CLASS__, 'flush' ) );
		ADVCM_Safe::action( 'admin_post_' . self::SAVE, array( __CLASS__, 'guarded_save' ) );
	}

	/**
	 * Every stored rule, by id. Anything malformed in the row is dropped on the read.
	 *
	 * @return array
	 */
	public static function rules() {
		$stored = get_option( self::RULES, array() );
		$rules  = array();

		if ( ! is_array( $stored ) ) {
			return $rules;
		}

		foreach ( $stored as $id => $rule ) {
			if ( is_array( $rule ) && isset( $rule['post_type'], $rule['urls'] ) && is_array( $rule['urls'] ) && '' !== (string) $id ) {
				$rules[ (string) $id ] = array(
					'id'        => (string) $id,
					'enabled'   => ! empty( $rule['enabled'] ),
					'post_type' => (string) $rule['post_type'],
					'taxonomy'  => isset( $rule['taxonomy'] ) ? (string) $rule['taxonomy'] : '',
					'term'      => isset( $rule['term'] ) ? (int) $rule['term'] : 0,
					'term_name' => isset( $rule['term_name'] ) ? (string) $rule['term_name'] : '',
					'urls'      => array_values( array_map( 'strval', $rule['urls'] ) ),
					'nitropack' => isset( $rule['nitropack'] ) && 'purge' === $rule['nitropack'] ? 'purge' : 'invalidate',
				);
			}
		}

		return $rules;
	}

	/**
	 * The save hook. Marks every rule this post triggers and makes sure a clear is scheduled.
	 *
	 * @param int          $post_id     Post id.
	 * @param WP_Post      $post        The post as saved.
	 * @param bool         $update      Whether it existed before.
	 * @param WP_Post|null $post_before The post before this save, on an update.
	 * @return void
	 */
	public static function saved( $post_id, $post, $update = false, $post_before = null ) {
		$rules = self::rules();

		// The cost of this feature on a site that does not use it: the read above.
		if ( ! self::any_enabled( $rules ) || ! is_object( $post ) ) {
			return;
		}

		if ( ! self::visible_change( $post, $post_before ) ) {
			return;
		}

		// Read only here, after a rule is known to be on and the save to be one a visitor sees:
		// a site with no rule still pays one read, and a draft saved still pays two.
		if ( self::paused() ) {
			return;
		}

		$entry  = self::post_entry( $post, self::change_kind( $post, $post_before ) );
		$marked = 0;

		foreach ( self::matching( $rules, $post ) as $rule ) {
			self::mark( $rule['id'], $entry );
			$marked++;
		}

		if ( $marked > 0 ) {
			self::schedule();
		}
	}

	/**
	 * Mark a rule as triggered by this post.
	 *
	 * @param string $id    Rule id.
	 * @param array  $entry The post, as post_entry() gives it.
	 * @return void
	 */
	private static function mark( $id, array $entry ) {
		$name = self::MARK . $id;

		// Inserts only when absent: a rule already waiting for its clear stays one entry, and two
		// saves racing for the first mark cannot both lose it or both make one.
		if ( add_option( $name, self::appended( null, $entry ), '', false ) ) {
			return;
		}

		$mark = get_option( $name, false );

		// The clear took the mark between the two calls. The post was saved before it ran, so the
		// clear covered it; only its line in History is lost, and writing the row back would make
		// a second clear for a change already cleared.
		if ( false === $mark ) {
			return;
		}

		// Best effort, and not atomic: two saves appending at once can each write the list without
		// the other's post, and a clear taking the mark between the read and this write leaves the
		// row behind for one more clear a minute later. Either costs a line in History or one
		// clear too many, never a clear missed — the mark the clear runs on was already there.
		update_option( $name, self::appended( $mark, $entry ), false );
	}

	/**
	 * A mark with one more triggering post on it.
	 *
	 * A post already on the list is counted once. Its change keeps what it was unless the new one
	 * says more: published then updated is still "published", but published then taken down is
	 * "unpublished".
	 *
	 * @param mixed $mark  The stored mark, or null for a new one.
	 * @param array $entry The post.
	 * @return array
	 */
	public static function appended( $mark, array $entry ) {
		$mark = self::mark_from( null === $mark ? array( 'at' => time() ) : $mark );

		if ( in_array( $entry['id'], $mark['ids'], true ) ) {
			foreach ( $mark['posts'] as $i => $known ) {
				if ( $known['id'] === $entry['id'] ) {
					$mark['posts'][ $i ] = array(
						'id'     => $entry['id'],
						'title'  => $entry['title'],
						'type'   => $entry['type'],
						'change' => 'updated' === $entry['change'] ? $known['change'] : $entry['change'],
					);
				}
			}

			return $mark;
		}

		if ( count( $mark['ids'] ) < self::MAX_IDS ) {
			$mark['ids'][] = $entry['id'];
		}

		$mark['total']++;

		if ( count( $mark['posts'] ) < self::MAX_POSTS ) {
			$mark['posts'][] = $entry;
		}

		return $mark;
	}

	/**
	 * A stored mark in its current shape. A mark from 0.3.0 is a bare timestamp: it still clears,
	 * and says no posts.
	 *
	 * @param mixed $raw Stored value.
	 * @return array at, posts, ids, total.
	 */
	public static function mark_from( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array( 'at' => (int) $raw, 'posts' => array(), 'ids' => array(), 'total' => 0 );
		}

		$posts = array();

		foreach ( isset( $raw['posts'] ) && is_array( $raw['posts'] ) ? $raw['posts'] : array() as $post ) {
			if ( is_array( $post ) && isset( $post['id'] ) && count( $posts ) < self::MAX_POSTS ) {
				$posts[] = array(
					'id'     => (int) $post['id'],
					'title'  => isset( $post['title'] ) ? (string) $post['title'] : '',
					'type'   => isset( $post['type'] ) ? (string) $post['type'] : '',
					'change' => isset( $post['change'] ) && in_array( $post['change'], array( 'published', 'updated', 'unpublished' ), true ) ? $post['change'] : 'updated',
				);
			}
		}

		$ids = isset( $raw['ids'] ) && is_array( $raw['ids'] ) ? array_values( array_map( 'intval', $raw['ids'] ) ) : array();

		return array(
			'at'    => isset( $raw['at'] ) ? (int) $raw['at'] : 0,
			'posts' => $posts,
			'ids'   => array_slice( $ids, 0, self::MAX_IDS ),
			'total' => max( isset( $raw['total'] ) ? (int) $raw['total'] : 0, count( $posts ) ),
		);
	}

	/**
	 * What this save did, as History says it.
	 *
	 * Only called once visible_change() has said it was published before or is now.
	 *
	 * @param object      $post        After.
	 * @param object|null $post_before Before.
	 * @return string published, updated or unpublished.
	 */
	public static function change_kind( $post, $post_before ) {
		$now    = isset( $post->post_status ) && 'publish' === $post->post_status;
		$before = is_object( $post_before ) && isset( $post_before->post_status ) && 'publish' === $post_before->post_status;

		if ( $now ) {
			return $before ? 'updated' : 'published';
		}

		return 'unpublished';
	}

	/**
	 * The post as a mark keeps it: enough to name it in History, and nothing a viewer of History
	 * could not already see in the post list.
	 *
	 * @param object $post   Post.
	 * @param string $change What happened.
	 * @return array
	 */
	private static function post_entry( $post, $change ) {
		$title = isset( $post->post_title ) ? trim( (string) $post->post_title ) : '';

		// A title is a few words; this only bounds a row that a pasted article could otherwise
		// grow, and cuts on a character, not a byte.
		$title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 150 ) : substr( $title, 0, 150 );

		return array(
			'id'     => isset( $post->ID ) ? (int) $post->ID : 0,
			'title'  => $title,
			'type'   => isset( $post->post_type ) ? (string) $post->post_type : '',
			'change' => $change,
		);
	}

	/**
	 * Whether every rule is paused.
	 *
	 * @return bool
	 */
	public static function paused() {
		return (int) get_option( self::PAUSED, 0 ) > 0;
	}

	/**
	 * Whether this save changes what a visitor can see: a post that is published now, or was
	 * published before this save (unpublished, trashed). A draft saved, a revision, an autosave
	 * changes nothing on a listing.
	 *
	 * @param object      $post        After.
	 * @param object|null $post_before Before.
	 * @return bool
	 */
	public static function visible_change( $post, $post_before ) {
		if ( ! isset( $post->post_type ) || in_array( $post->post_type, array( 'revision', 'auto-draft', 'nav_menu_item' ), true ) ) {
			return false;
		}

		$now    = isset( $post->post_status ) ? $post->post_status : '';
		$before = is_object( $post_before ) && isset( $post_before->post_status ) ? $post_before->post_status : '';

		return 'publish' === $now || 'publish' === $before;
	}

	/**
	 * Whether any rule is switched on.
	 *
	 * @param array $rules Rules.
	 * @return bool
	 */
	public static function any_enabled( array $rules ) {
		foreach ( $rules as $rule ) {
			if ( $rule['enabled'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The switched-on rules a post triggers.
	 *
	 * @param array  $rules Rules.
	 * @param object $post  Post.
	 * @return array
	 */
	public static function matching( array $rules, $post ) {
		$out = array();

		foreach ( $rules as $rule ) {
			if ( ! $rule['enabled'] || ! isset( $post->post_type ) || ! self::type_matches( $rule['post_type'], (string) $post->post_type ) ) {
				continue;
			}

			if ( '' !== $rule['taxonomy'] && $rule['term'] > 0 && ! has_term( self::terms_of( $rule ), $rule['taxonomy'], $post ) ) {
				continue;
			}

			$out[] = $rule;
		}

		return $out;
	}

	/**
	 * Whether a rule's post type covers a post's.
	 *
	 * @param string $want The rule's: a post type, or ANY.
	 * @param string $type The post's.
	 * @return bool
	 */
	public static function type_matches( $want, $type ) {
		if ( $want === $type ) {
			return true;
		}

		return self::ANY === $want && self::is_content_type( $type );
	}

	/**
	 * Whether "any content" covers a post type: one a visitor can view, registered public, and not
	 * one of the types that only exist to build or run the site.
	 *
	 * Viewable and public both: a type that is publicly queryable but not public is a plugin's
	 * endpoint more often than an article, and a rule that wants one can name it.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function is_content_type( $type ) {
		if ( '' === $type || ! function_exists( 'is_post_type_viewable' ) || ! is_post_type_viewable( $type ) ) {
			return false;
		}

		$object = get_post_type_object( $type );

		if ( ! is_object( $object ) || empty( $object->public ) ) {
			return false;
		}

		foreach ( self::excluded_types() as $excluded ) {
			$prefix = '*' === substr( $excluded, -1 );

			if ( $prefix ? 0 === strpos( $type, substr( $excluded, 0, -1 ) ) : $type === $excluded ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The post types "any content" leaves out, after the filter.
	 *
	 * A filter may add a type or take one off. What it returns is held to the shape of a post type
	 * name — anything else is dropped, a non-list is ignored, a filter that throws is reported and
	 * ignored — and ALWAYS_EXCLUDED is added back whatever it said.
	 *
	 * @return string[]
	 */
	public static function excluded_types() {
		$list = self::EXCLUDED;

		try {
			/**
			 * Filter the post types an "any content" rule does not fire on.
			 *
			 * @param string[] $types Post type names; a trailing `*` matches a prefix.
			 */
			$filtered = apply_filters( self::EXCLUDED_FILTER, $list );
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( self::EXCLUDED_FILTER, $e );
			$filtered = $list;
		}

		if ( is_array( $filtered ) ) {
			$list = array();

			foreach ( $filtered as $type ) {
				// A lone "*" would leave out every type, which reads as a rule that is on and
				// never fires; refused with everything else that is not a post type name.
				if ( is_string( $type ) && 1 === preg_match( '~^[a-z0-9_\-]{1,20}\*?$~', $type ) ) {
					$list[] = $type;
				}
			}
		}

		return array_values( array_unique( array_merge( $list, self::ALWAYS_EXCLUDED ) ) );
	}

	/**
	 * The terms a rule's term stands for: itself, and in a hierarchical taxonomy every term under
	 * it. A post filed only under a subcategory is on the parent's listing, since WordPress's own
	 * category archive includes children; the rule has to agree with the page it clears.
	 *
	 * @param array $rule Rule.
	 * @return int[]
	 */
	public static function terms_of( array $rule ) {
		$terms = array( (int) $rule['term'] );

		if ( function_exists( 'is_taxonomy_hierarchical' ) && is_taxonomy_hierarchical( $rule['taxonomy'] ) ) {
			// One read of the taxonomy's `_children` option, which WordPress autoloads.
			$children = get_term_children( (int) $rule['term'], $rule['taxonomy'] );

			if ( is_array( $children ) ) {
				$terms = array_merge( $terms, array_map( 'intval', $children ) );
			}
		}

		return array_values( array_unique( $terms ) );
	}

	/**
	 * A rule's condition, in words.
	 *
	 * @param array $rule Rule.
	 * @return string
	 */
	public static function describe( array $rule ) {
		$line = self::ANY === $rule['post_type'] ? __( 'any content is published', 'advcm' ) : sprintf( __( 'a %s is published', 'advcm' ), $rule['post_type'] );

		if ( '' !== $rule['taxonomy'] && $rule['term'] > 0 ) {
			$term  = '' !== $rule['term_name'] ? $rule['term_name'] : '#' . $rule['term'];
			$line .= ' ' . ( function_exists( 'is_taxonomy_hierarchical' ) && is_taxonomy_hierarchical( $rule['taxonomy'] )
				/* translators: 1: taxonomy, 2: term. */
				? sprintf( __( 'in %1$s "%2$s" or under it', 'advcm' ), $rule['taxonomy'], $term )
				/* translators: 1: taxonomy, 2: term. */
				: sprintf( __( 'in %1$s "%2$s"', 'advcm' ), $rule['taxonomy'], $term ) );
		}

		return $line;
	}

	/**
	 * When each rule last fired, by rule id.
	 *
	 * @return array id => array( at, job, state, total )
	 */
	public static function last() {
		$stored = get_option( self::LAST, array() );
		$out    = array();

		foreach ( is_array( $stored ) ? $stored : array() as $id => $entry ) {
			if ( is_array( $entry ) && isset( $entry['at'] ) ) {
				$out[ (string) $id ] = array(
					'at'    => (int) $entry['at'],
					'job'   => isset( $entry['job'] ) ? (string) $entry['job'] : '',
					'state' => isset( $entry['state'] ) ? (string) $entry['state'] : '',
					'total' => isset( $entry['total'] ) ? (int) $entry['total'] : 0,
				);
			}
		}

		return $out;
	}

	/**
	 * Record a clear against each rule that fired it.
	 *
	 * @param array $triggers The job's triggers.
	 * @param array $job      The job as it ended.
	 * @param array $rules    The rules that exist now.
	 * @return void
	 */
	private static function record_last( array $triggers, array $job, array $rules ) {
		$last = array_intersect_key( self::last(), $rules );

		foreach ( $triggers as $trigger ) {
			$last[ $trigger['rule'] ] = array(
				'at'    => time(),
				'job'   => isset( $job['id'] ) ? (string) $job['id'] : '',
				'state' => isset( $job['state'] ) ? (string) $job['state'] : '',
				'total' => $trigger['total'],
			);
		}

		// Kept to the rules that exist, so a rule deleted while its clear ran leaves no row behind.
		update_option( self::LAST, $last, false );
	}

	/**
	 * Schedule the clear, once, WINDOW seconds from now.
	 *
	 * @return void
	 */
	private static function schedule() {
		if ( false !== wp_next_scheduled( self::FLUSH_HOOK ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::WINDOW, self::FLUSH_HOOK );
	}

	/**
	 * When the next clear is due, or 0.
	 *
	 * @return int
	 */
	public static function next_flush() {
		$at = wp_next_scheduled( self::FLUSH_HOOK );

		return false === $at ? 0 : (int) $at;
	}

	/**
	 * The rules waiting for the next clear.
	 *
	 * @return string[] Rule ids.
	 */
	public static function waiting() {
		$out = array();

		foreach ( array_keys( self::rules() ) as $id ) {
			if ( false !== get_option( self::MARK . $id, false ) ) {
				$out[] = $id;
			}
		}

		return $out;
	}

	/**
	 * The deferred clear: every marked rule's URLs, as one per-URL job.
	 *
	 * Run inline (Fast) because this already is the background — a cron request nobody waits on.
	 * A rule switched off or deleted while it was waiting clears nothing, and while every rule is
	 * paused nothing does: the marks are dropped rather than kept for the resume, which would
	 * otherwise clear on the first publish after it for posts saved hours before.
	 *
	 * @return array|null The job, or null when there was nothing to clear.
	 */
	public static function flush() {
		$urls     = array();
		$purge    = false;
		$fired    = array();
		$triggers = array();
		$enabled  = self::rules();
		$paused   = self::paused();

		foreach ( array_keys( $enabled ) as $id ) {
			$mark = get_option( self::MARK . $id, false );

			if ( false === $mark ) {
				continue;
			}

			// Taken off before the clear runs: a post published while it runs marks the rule again
			// and schedules the next clear, rather than being lost into this one.
			delete_option( self::MARK . $id );

			$rule = $enabled[ $id ];

			if ( $paused || ! $rule['enabled'] ) {
				continue;
			}

			$mark       = self::mark_from( $mark );
			$fired[]    = $id;
			$purge      = $purge || 'purge' === $rule['nitropack'];
			$triggers[] = array(
				'rule'  => $id,
				'line'  => self::describe( $rule ),
				'posts' => $mark['posts'],
				'total' => $mark['total'],
			);

			foreach ( $rule['urls'] as $url ) {
				$urls[ $url ] = $url;
			}
		}

		if ( empty( $urls ) ) {
			return null;
		}

		// Validated again: a rule was checked when it was written, but the site's address can
		// change after that, and the clear only ever names this site's own pages.
		$parsed = ADVCM_Urls::parse( array_values( $urls ), home_url() );

		if ( empty( $parsed['accepted'] ) ) {
			return null;
		}

		$job = ADVCM_Runner::start(
			array(
				'scope'   => 'urls',
				'urls'    => $parsed['accepted'],
				'refused' => $parsed['refused'],
				'mode'    => ADVCM_Modes::FAST,
				'source'  => self::SOURCE,
				'by'      => 0,
				'options' => array(
					'nitropack_mode' => $purge ? 'purge' : 'invalidate',
					'rules'          => $fired,
					'triggers'       => $triggers,
				),
			)
		);

		self::record_last( $triggers, $job, $enabled );

		return $job;
	}

	/**
	 * Build a rule from form input, or say why not.
	 *
	 * @param array $input Unslashed input.
	 * @return array|string
	 */
	public static function rule_from( array $input ) {
		$type = isset( $input['post_type'] ) && is_string( $input['post_type'] ) ? $input['post_type'] : '';
		// Compared before sanitize_key(), which would strip the `*` to an empty name.
		$type = self::ANY === $type ? self::ANY : sanitize_key( $type );

		if ( '' === $type || ( self::ANY !== $type && ! post_type_exists( $type ) ) ) {
			return __( 'Choose a post type that exists on this site.', 'advcm' );
		}

		$taxonomy  = '';
		$term      = 0;
		$term_name = '';
		$raw_term  = isset( $input['term'] ) && is_string( $input['term'] ) ? trim( $input['term'] ) : '';

		if ( '' !== $raw_term ) {
			$taxonomy = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? sanitize_key( $input['taxonomy'] ) : '';

			if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
				return __( 'Choose the taxonomy the term belongs to.', 'advcm' );
			}

			$found = get_term_by( 'slug', sanitize_title( $raw_term ), $taxonomy );

			if ( ! is_object( $found ) ) {
				$found = get_term_by( 'name', $raw_term, $taxonomy );
			}

			if ( ! is_object( $found ) ) {
				return sprintf( __( 'There is no term "%1$s" in %2$s.', 'advcm' ), $raw_term, $taxonomy );
			}

			$term      = (int) $found->term_id;
			$term_name = (string) $found->name;
		}

		$raw = isset( $input['urls'] ) && is_string( $input['urls'] ) ? $input['urls'] : '';

		if ( strlen( $raw ) > self::MAX_URLS * ( ADVCM_Urls::MAX_LENGTH + 2 ) ) {
			return sprintf( __( 'Up to %d URLs per rule.', 'advcm' ), self::MAX_URLS );
		}

		$parsed = ADVCM_Urls::parse( $raw, home_url() );

		if ( ! empty( $parsed['refused'] ) ) {
			return sprintf( __( 'Not on this site, so a rule cannot clear them: %s', 'advcm' ), implode( ', ', $parsed['refused'] ) );
		}

		if ( empty( $parsed['accepted'] ) ) {
			return __( 'Name at least one URL on this site for the rule to clear.', 'advcm' );
		}

		if ( count( $parsed['accepted'] ) > self::MAX_URLS ) {
			return sprintf( __( 'Up to %d URLs per rule.', 'advcm' ), self::MAX_URLS );
		}

		return array(
			'enabled'   => ! empty( $input['enabled'] ),
			'post_type' => $type,
			'taxonomy'  => $taxonomy,
			'term'      => $term,
			'term_name' => $term_name,
			'urls'      => $parsed['accepted'],
			'nitropack' => isset( $input['nitropack'] ) && 'purge' === $input['nitropack'] ? 'purge' : 'invalidate',
		);
	}

	/**
	 * Apply one change to the rules: add, switch on or off, delete, pause or resume them all. Or say
	 * why not.
	 *
	 * @param array $input Unslashed input.
	 * @return string Empty on success, else the refusal.
	 */
	public static function change( array $input ) {
		$op    = isset( $input['op'] ) && is_string( $input['op'] ) ? $input['op'] : '';
		$rules = self::rules();
		$id    = isset( $input['rule'] ) && is_string( $input['rule'] ) ? $input['rule'] : '';

		// Its own row, and the rules row left alone: pausing changes no rule, so resuming gives back
		// exactly the set that was switched on.
		if ( 'pause' === $op ) {
			update_option( self::PAUSED, time(), false );

			return '';
		}

		if ( 'resume' === $op ) {
			delete_option( self::PAUSED );

			return '';
		}

		if ( 'add' === $op ) {
			if ( count( $rules ) >= self::MAX_RULES ) {
				return sprintf( __( 'This site already has %d rules, the most it may have.', 'advcm' ), self::MAX_RULES );
			}

			$rule = self::rule_from( $input );

			if ( is_string( $rule ) ) {
				return $rule;
			}

			$rules[ bin2hex( random_bytes( 4 ) ) ] = $rule;
		} elseif ( ( 'enable' === $op || 'disable' === $op || 'delete' === $op ) && isset( $rules[ $id ] ) ) {
			if ( 'delete' === $op ) {
				unset( $rules[ $id ] );
				delete_option( self::MARK . $id );

				$last = self::last();

				if ( isset( $last[ $id ] ) ) {
					unset( $last[ $id ] );
					update_option( self::LAST, $last, false );
				}
			} else {
				$rules[ $id ]['enabled'] = 'enable' === $op;
			}
		} else {
			return __( 'That rule no longer exists.', 'advcm' );
		}

		$stored = array();

		foreach ( $rules as $key => $rule ) {
			unset( $rule['id'] );
			$stored[ $key ] = $rule;
		}

		// Not autoloaded: read on a post save and on this screen, not on every page.
		update_option( self::RULES, $stored, false );

		return '';
	}

	/**
	 * The rules form's handler, guarded.
	 *
	 * @return void
	 */
	public static function guarded_save() {
		try {
			self::save();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::SAVE, $e );
			self::back( __( 'The rule could not be saved because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Handle the rules form.
	 *
	 * @return void
	 */
	public static function save() {
		if ( ! current_user_can( ADVCM_Capabilities::HARD ) ) {
			wp_die( esc_html__( 'Only administrators can change auto-clear rules.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::SAVE );

		self::back( self::change( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	}

	/**
	 * Back to the Auto-clear tab, with a refusal if there was one.
	 *
	 * @param string $notice Refusal, or empty.
	 * @return void
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( ADVCM_Screen::tab_url( 'auto' ) );
		exit;
	}
}
