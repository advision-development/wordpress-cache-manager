<?php
/**
 * Listings whose filter has matched nothing new for weeks.
 *
 * Found on BMR on 2026-10-20: the "Alabama Sports Betting News" carousel on /usa/alabama/ showed
 * the same posts for ten months. No cache held it. Its Elementor widget lists posts tagged
 * `alabama`, and nobody had tagged a post `alabama` since 2025-12-31. Every clear rebuilt the same
 * page. This finds such listings so a person can fix the content — it changes nothing itself.
 *
 * **What is read.** Every published document with `_elementor_data`, whatever its post type
 * (templates included: on BMR most listings sit in states, sportsbooks and saved templates). In
 * it, each `posts`, `loop-grid` and `loop-carousel` widget whose query includes terms. Elementor
 * stores those as **term_taxonomy_ids**, not term ids. For each, the newest published post the
 * filter matches, queried as Elementor builds it: IN within a taxonomy, AND across taxonomies,
 * excluded terms NOT IN. A widget with a custom query id is listed but not checked: a hook decides
 * what it shows. A site without Elementor has no such documents, and a scan finishes at once.
 *
 * **How it runs.** Never in a page view or a save. A press on the Freshness tab schedules one
 * WP-Cron event; each run reads SCAN_BATCH documents within SCAN_BUDGET seconds, lets go of each
 * document's meta, and schedules the next. One scan at a time. Where WP-Cron cannot wake, the
 * Freshness tab's tick runs TICK_BATCH documents, and only once the next run is LATE seconds
 * overdue. A document that kills a run is stepped over by the next one: the lock names it.
 *
 * **Bounded.** One row, `advcm_listings`: the scan's counters and cursor (a post id, never a list
 * that grows with the site) and at most LISTINGS_KEEP findings, the worst kept. Green listings
 * are counted, not stored. Every string is cut.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Frozen listings.
 */
final class ADVCM_Listings {

	/** The scan and its findings. */
	const OPTION = 'advcm_listings';

	/** Held while a run reads documents; names the document it is on. */
	const LOCK = 'advcm_lock_listings';

	/** The WP-Cron event that runs the next batch. */
	const HOOK = 'advcm_listings_scan';

	/** The admin-post action and nonce. */
	const ACTION = 'advcm_listings';

	/** Documents and seconds per WP-Cron run. */
	const SCAN_BATCH  = 20;
	const SCAN_BUDGET = 8;

	/** Documents and seconds per tick, and how overdue the next run must be first. */
	const TICK_BATCH  = 5;
	const TICK_BUDGET = 3;
	const LATE        = 120;

	/** A lock older than this belongs to a run that died. */
	const LOCK_TTL = 120;

	/** Days since the newest match: amber from, red over. */
	const STALE_DAYS  = 7;
	const FROZEN_DAYS = 30;

	/** Findings kept. */
	const LISTINGS_KEEP = 300;

	/** Characters kept of a title and of a filter in words. */
	const TEXT_CHARS   = 150;
	const FILTER_CHARS = 200;

	/** The widgets that list posts, and the prefix of their query settings. */
	const WIDGETS = array(
		'posts'         => 'posts_',
		'loop-grid'     => 'post_query_',
		'loop-carousel' => 'post_query_',
	);

	/** Elementor's post type values that are not a post type: no term filter applies. */
	const NOT_TYPES = array( 'by_id', 'current_query', 'related' );

	/** @var callable|null Replaces the newest-post query, for tests. */
	private static $finder = null;

	/** @var callable|null Replaces the documents query, for tests. */
	private static $documents = null;

	/** @var callable|null Replaces the clock, for tests. */
	private static $clock = null;

	/**
	 * Hook in. The event is registered on every request, as the runner's is: WP-Cron is neither
	 * admin nor front end.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( self::HOOK, array( __CLASS__, 'cron' ), 10, 0 );
		ADVCM_Safe::action( 'admin_post_' . self::ACTION, array( __CLASS__, 'guarded_handle' ) );
	}

	/**
	 * Test seam: the newest post a filter matches.
	 *
	 * @param callable|null $finder function( $post_type, array $tax_query ): array|null.
	 * @return void
	 */
	public static function use_finder( $finder ) {
		self::$finder = $finder;
	}

	/**
	 * Test seam: the documents.
	 *
	 * @param callable|null $documents function( $after, $limit ): int[] ids, or the count when $limit is null.
	 * @return void
	 */
	public static function use_documents( $documents ) {
		self::$documents = $documents;
	}

	/**
	 * Test seam: the clock the budget is read from.
	 *
	 * @param callable|null $clock function(): float.
	 * @return void
	 */
	public static function use_clock( $clock ) {
		self::$clock = $clock;
	}

	/**
	 * The listing widgets in a document's Elementor data.
	 *
	 * @param mixed $data Decoded `_elementor_data`.
	 * @return array[] id, type, post_type, per_page, include_tt, exclude_tt, query_id.
	 */
	public static function widgets( $data ) {
		$out = array();

		if ( ! is_array( $data ) ) {
			return $out;
		}

		foreach ( $data as $element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			$type = isset( $element['widgetType'] ) ? (string) $element['widgetType'] : '';

			if ( isset( self::WIDGETS[ $type ] ) && isset( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$widget = self::widget( $type, $element );

				if ( null !== $widget ) {
					$out[] = $widget;
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				$out = array_merge( $out, self::widgets( $element['elements'] ) );
			}
		}

		return $out;
	}

	/**
	 * One widget, when it lists posts by term.
	 *
	 * @param string $type    Widget type.
	 * @param array  $element The element.
	 * @return array|null
	 */
	private static function widget( $type, array $element ) {
		$s = $element['settings'];
		$p = self::WIDGETS[ $type ];

		$include = isset( $s[ $p . 'include' ] ) ? (array) $s[ $p . 'include' ] : array();
		$ids     = self::ids( isset( $s[ $p . 'include_term_ids' ] ) ? $s[ $p . 'include_term_ids' ] : array() );
		$type_of = isset( $s[ $p . 'post_type' ] ) && '' !== $s[ $p . 'post_type' ] ? (string) $s[ $p . 'post_type' ] : 'post';

		if ( ! in_array( 'terms', $include, true ) || empty( $ids ) || in_array( $type_of, self::NOT_TYPES, true ) ) {
			return null;
		}

		$exclude = isset( $s[ $p . 'exclude' ] ) ? (array) $s[ $p . 'exclude' ] : array();

		// Each skin has its own count (classic_posts_per_page, cards_…); the loop widgets one.
		$per_page = null;

		foreach ( $s as $key => $value ) {
			if ( is_string( $key ) && 'posts_per_page' === substr( $key, -14 ) && is_numeric( $value ) ) {
				$per_page = (int) $value;
				break;
			}
		}

		return array(
			'id'         => isset( $element['id'] ) ? substr( (string) $element['id'], 0, 40 ) : '',
			'type'       => $type,
			'post_type'  => substr( $type_of, 0, 40 ),
			'per_page'   => $per_page,
			'include_tt' => $ids,
			'exclude_tt' => in_array( 'terms', $exclude, true ) ? self::ids( isset( $s[ $p . 'exclude_term_ids' ] ) ? $s[ $p . 'exclude_term_ids' ] : array() ) : array(),
			'query_id'   => isset( $s[ $p . 'query_id' ] ) ? substr( (string) $s[ $p . 'query_id' ], 0, 60 ) : '',
		);
	}

	/**
	 * Positive integer ids from whatever Elementor stored, at most 50.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	private static function ids( $value ) {
		$out = array();

		foreach ( (array) $value as $id ) {
			if ( is_scalar( $id ) && (int) $id > 0 ) {
				$out[] = (int) $id;
			}
		}

		return array_slice( array_values( array_unique( $out ) ), 0, 50 );
	}

	/**
	 * Judge a listing: its filter in words, the newest post it matches and how old that is.
	 *
	 * @param array $widget From widgets().
	 * @param int   $now    Now.
	 * @return array filter, broken, cannot_match, custom, newest, days, verdict.
	 */
	public static function evaluate( array $widget, $now ) {
		$include = self::resolve( $widget['include_tt'] );
		$exclude = self::resolve( $widget['exclude_tt'] );

		$words = self::words( $include['groups'], $include['missing'] );

		if ( ! empty( $exclude['groups'] ) ) {
			$words .= ', not ' . self::words( $exclude['groups'], array() );
		}

		$out = array(
			'filter'       => self::cut( $words, self::FILTER_CHARS ),
			'broken'       => ! empty( $include['missing'] ),
			'cannot_match' => false,
			'custom'       => '' !== $widget['query_id'],
			'newest'       => null,
			'days'         => null,
			'verdict'      => 'red',
		);

		if ( $out['custom'] ) {
			$out['verdict'] = 'unchecked';

			return $out;
		}

		foreach ( array_keys( $include['groups'] ) as $taxonomy ) {
			if ( ! is_object_in_taxonomy( $widget['post_type'], $taxonomy ) ) {
				$out['cannot_match'] = true;
			}
		}

		if ( $out['broken'] || $out['cannot_match'] ) {
			return $out;
		}

		$tax_query = array();

		foreach ( array( 'IN' => $include['groups'], 'NOT IN' => $exclude['groups'] ) as $operator => $groups ) {
			foreach ( $groups as $taxonomy => $terms ) {
				$tax_query[] = array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_taxonomy_id',
					'terms'    => array_keys( $terms ),
					'operator' => $operator,
				);
			}
		}

		$newest = null === self::$finder ? self::newest( $widget['post_type'], $tax_query ) : call_user_func( self::$finder, $widget['post_type'], $tax_query );

		if ( ! is_array( $newest ) ) {
			return $out;
		}

		$out['newest'] = array(
			'id'    => (int) $newest['id'],
			'title' => self::cut( (string) $newest['title'], self::TEXT_CHARS ),
			'date'  => (int) $newest['date'],
		);
		$out['days']   = (int) floor( max( 0, $now - (int) $newest['date'] ) / DAY_IN_SECONDS );

		if ( $out['days'] > self::FROZEN_DAYS ) {
			$out['verdict'] = 'red';
		} elseif ( $out['days'] >= self::STALE_DAYS ) {
			$out['verdict'] = 'amber';
		} else {
			$out['verdict'] = 'green';
		}

		return $out;
	}

	/**
	 * term_taxonomy_ids to terms, grouped by taxonomy in the order first seen.
	 *
	 * @param int[] $ids term_taxonomy_ids.
	 * @return array groups (taxonomy => tt id => slug), missing (int[]).
	 */
	private static function resolve( array $ids ) {
		$groups  = array();
		$missing = array();

		foreach ( $ids as $id ) {
			$term = get_term_by( 'term_taxonomy_id', $id );

			if ( ! is_object( $term ) || empty( $term->taxonomy ) ) {
				$missing[] = $id;
				continue;
			}

			$groups[ (string) $term->taxonomy ][ $id ] = (string) $term->slug;
		}

		return array(
			'groups'  => $groups,
			'missing' => $missing,
		);
	}

	/**
	 * A filter in words: "tag alabama + category betting-buzz".
	 *
	 * @param array $groups  taxonomy => tt id => slug.
	 * @param int[] $missing Ids that resolve to nothing.
	 * @return string
	 */
	private static function words( array $groups, array $missing ) {
		$parts = array();

		foreach ( $groups as $taxonomy => $terms ) {
			$label   = 'post_tag' === $taxonomy ? 'tag' : $taxonomy;
			$parts[] = $label . ' ' . implode( ' or ', $terms );
		}

		foreach ( $missing as $id ) {
			$parts[] = 'missing term #' . $id;
		}

		return implode( ' + ', $parts );
	}

	/**
	 * The newest published post of a type a tax query matches.
	 *
	 * @param string $post_type Post type.
	 * @param array  $tax_query Tax query.
	 * @return array|null id, title, date (unix, GMT).
	 */
	private static function newest( $post_type, array $tax_query ) {
		$query = new WP_Query(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => 1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'tax_query'              => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one row, indexed, in WP-Cron.
				'fields'                 => 'ids',
				'ignore_sticky_posts'    => true,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'suppress_filters'       => true,
			)
		);

		if ( empty( $query->posts ) ) {
			return null;
		}

		$id = (int) $query->posts[0];

		return array(
			'id'    => $id,
			'title' => get_the_title( $id ),
			'date'  => (int) get_post_time( 'U', true, $id ),
		);
	}

	/**
	 * The scan, every key present.
	 *
	 * @return array started, by, cursor, done, scanned, total, listings, green, dropped, skipped,
	 *               next_at, findings.
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array();

		foreach ( array( 'started', 'by', 'cursor', 'done', 'scanned', 'total', 'listings', 'green', 'dropped', 'skipped', 'next_at' ) as $key ) {
			$state[ $key ] = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
		}

		$state['findings'] = isset( $stored['findings'] ) && is_array( $stored['findings'] ) ? array_values( array_filter( $stored['findings'], 'is_array' ) ) : array();

		return $state;
	}

	/**
	 * Start a scan, or pick up one whose event was lost.
	 *
	 * @param int $by Who pressed.
	 * @return string Empty when started or resumed, else why not.
	 */
	public static function start( $by ) {
		$state = self::state();

		if ( $state['started'] && ! $state['done'] ) {
			if ( false === wp_next_scheduled( self::HOOK ) && ! self::locked() ) {
				// Its event is gone — a wiped cron table, a host that dropped it. Carry on from the
				// cursor rather than refusing for ever or reading everything again.
				$state['next_at'] = time();
				self::save( $state );
				self::schedule();

				return '';
			}

			/* translators: 1: documents read, 2: documents in all. */
			return sprintf( __( 'A scan is already running: %1$d of %2$d documents read.', 'advcm' ), $state['scanned'], $state['total'] );
		}

		$state = array(
			'started'  => time(),
			'by'       => (int) $by,
			'cursor'   => 0,
			'done'     => 0,
			'scanned'  => 0,
			'total'    => (int) self::documents( 0, null ),
			'listings' => 0,
			'green'    => 0,
			'dropped'  => 0,
			'skipped'  => 0,
			'next_at'  => time(),
			'findings' => array(),
		);

		self::save( $state );
		self::schedule();

		return '';
	}

	/**
	 * WP-Cron's run.
	 *
	 * @return void
	 */
	public static function cron() {
		self::run_batch( self::SCAN_BATCH, self::SCAN_BUDGET );
	}

	/**
	 * The screen's tick: a small batch, only for a scan WP-Cron has left LATE seconds overdue.
	 *
	 * @return int Documents read.
	 */
	public static function tick() {
		$state = self::state();

		if ( ! $state['started'] || $state['done'] || ! $state['next_at'] || time() - $state['next_at'] <= self::LATE ) {
			return 0;
		}

		return self::run_batch( self::TICK_BATCH, self::TICK_BUDGET );
	}

	/**
	 * Put back a scan whose event was lost: running, no event, no run holding the lock, and its
	 * next run over LATE seconds due. The same loss as an auto-clear's in a plugin update; the
	 * press that would otherwise pick it up may never come.
	 *
	 * @return int 1 when the next run was scheduled, 0 otherwise.
	 */
	public static function rescue() {
		$state = self::state();

		if ( ! $state['started'] || $state['done'] || false !== wp_next_scheduled( self::HOOK ) || self::locked() || time() - $state['next_at'] <= self::LATE ) {
			return 0;
		}

		self::schedule();

		return 1;
	}

	/**
	 * Read up to $size documents within $budget seconds, from the cursor.
	 *
	 * @param int $size   Documents.
	 * @param int $budget Seconds.
	 * @return int Documents read.
	 */
	public static function run_batch( $size, $budget ) {
		$state = self::state();

		if ( ! $state['started'] || $state['done'] ) {
			return 0;
		}

		$lock = get_option( self::LOCK, null );

		if ( is_array( $lock ) ) {
			if ( isset( $lock['at'] ) && (int) $lock['at'] > time() - self::LOCK_TTL ) {
				return 0;
			}

			// A run died holding it. The document it named is the likeliest cause: step over it.
			if ( ! empty( $lock['doc'] ) && (int) $lock['doc'] > $state['cursor'] ) {
				$state['cursor'] = (int) $lock['doc'];
				$state['skipped']++;
			}

			delete_option( self::LOCK );
		}

		if ( ! add_option( self::LOCK, array( 'at' => time(), 'doc' => 0 ), '', false ) ) {
			return 0;
		}

		$read = 0;

		try {
			$ids   = (array) self::documents( $state['cursor'], (int) $size );
			$began = self::now();
			$found = array();

			foreach ( $ids as $id ) {
				if ( $read > 0 && self::now() - $began >= $budget ) {
					break;
				}

				$id = (int) $id;

				update_option( self::LOCK, array( 'at' => time(), 'doc' => $id ), false );

				foreach ( self::document( $id ) as $finding ) {
					$state['listings']++;

					if ( 'green' === $finding['verdict'] ) {
						$state['green']++;
					} else {
						$found[] = $finding;
					}
				}

				$state['cursor'] = $id;
				$state['scanned']++;
				$read++;
			}

			$state['findings'] = self::keep( array_merge( $state['findings'], $found ), $state );

			if ( count( $ids ) < (int) $size && count( $ids ) === $read ) {
				$state['done']    = time();
				$state['next_at'] = 0;
			} else {
				$state['next_at'] = time();
			}

			self::save( $state );

			if ( ! $state['done'] ) {
				self::schedule();
			}
		} finally {
			delete_option( self::LOCK );
		}

		return $read;
	}

	/**
	 * One document's listings, judged. A document that fails is read past.
	 *
	 * @param int $id Post id.
	 * @return array[]
	 */
	private static function document( $id ) {
		$out = array();

		try {
			$raw  = get_post_meta( $id, '_elementor_data', true );
			$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;

			// The data is a few hundred KB on a big page; let it go before the next document so a
			// batch's memory stays flat on a site with a persistent object cache.
			wp_cache_delete( $id, 'post_meta' );

			$widgets = self::widgets( $data );

			if ( empty( $widgets ) ) {
				return $out;
			}

			$post = get_post( $id );
			$type = is_object( $post ) ? (string) $post->post_type : '';
			$now  = time();

			foreach ( $widgets as $pos => $widget ) {
				$out[] = array_merge(
					self::evaluate( $widget, $now ),
					array(
						'doc'       => $id,
						'pos'       => $pos,
						'title'     => self::cut( is_object( $post ) ? (string) $post->post_title : '', self::TEXT_CHARS ),
						'doc_type'  => substr( $type, 0, 40 ),
						// A saved template has no page of its own; it is shown by title.
						'url'       => 'elementor_library' === $type ? '' : substr( (string) get_permalink( $id ), 0, 2048 ),
						'widget'    => $widget['id'],
						'type'      => $widget['type'],
						'post_type' => $widget['post_type'],
						'per_page'  => $widget['per_page'],
					)
				);
			}
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'listings', $e );
		}

		return $out;
	}

	/**
	 * The worst LISTINGS_KEEP findings: red, then amber, then unchecked; the longest without a new
	 * post first. The rest are counted.
	 *
	 * @param array[] $findings Findings.
	 * @param array   $state    The scan, for its dropped count.
	 * @return array[]
	 */
	private static function keep( array $findings, array &$state ) {
		usort( $findings, array( __CLASS__, 'compare' ) );

		if ( count( $findings ) > self::LISTINGS_KEEP ) {
			$state['dropped'] += count( $findings ) - self::LISTINGS_KEEP;
			$findings          = array_slice( $findings, 0, self::LISTINGS_KEEP );
		}

		return $findings;
	}

	/**
	 * Order of findings. Explicit to the last key: usort is not stable before PHP 8.
	 *
	 * @param array $a Finding.
	 * @param array $b Finding.
	 * @return int
	 */
	public static function compare( $a, $b ) {
		$rank = array(
			'red'       => 0,
			'amber'     => 1,
			'unchecked' => 2,
		);

		$keys = array(
			array( isset( $rank[ $a['verdict'] ] ) ? $rank[ $a['verdict'] ] : 3, isset( $rank[ $b['verdict'] ] ) ? $rank[ $b['verdict'] ] : 3 ),
			// No match at all is the longest; then by days, most first.
			array( null === $b['days'] ? PHP_INT_MAX : $b['days'], null === $a['days'] ? PHP_INT_MAX : $a['days'] ),
			array( (int) $a['doc'], (int) $b['doc'] ),
			array( (int) $a['pos'], (int) $b['pos'] ),
		);

		foreach ( $keys as $pair ) {
			if ( $pair[0] !== $pair[1] ) {
				return $pair[0] < $pair[1] ? -1 : 1;
			}
		}

		return 0;
	}

	/**
	 * Published documents with Elementor data after an id, or how many there are.
	 *
	 * @param int      $after Post id.
	 * @param int|null $limit How many, or null for the count.
	 * @return int[]|int
	 */
	private static function documents( $after, $limit ) {
		if ( null !== self::$documents ) {
			return call_user_func( self::$documents, (int) $after, $limit );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- a cursor over an index; no API pages by post id across every type.
		if ( null === $limit ) {
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND p.ID > %d", $after ) );
		}

		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_status = 'publish' AND p.ID > %d ORDER BY p.ID ASC LIMIT %d", $after, $limit ) ) );
		// phpcs:enable
	}

	/**
	 * Whether a live run holds the lock.
	 *
	 * @return bool
	 */
	private static function locked() {
		$lock = get_option( self::LOCK, null );

		return is_array( $lock ) && isset( $lock['at'] ) && (int) $lock['at'] > time() - self::LOCK_TTL;
	}

	/**
	 * The next run, unless one is already on the schedule.
	 *
	 * @return void
	 */
	private static function schedule() {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time(), self::HOOK );
		}
	}

	/**
	 * Write the scan.
	 *
	 * @param array $state The scan.
	 * @return void
	 */
	private static function save( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * A string cut to a length, on a character, marked as cut.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Characters.
	 * @return string
	 */
	private static function cut( $text, $chars ) {
		$text = wp_strip_all_tags( (string) $text );

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $chars ? mb_substr( $text, 0, $chars - 1 ) . '…' : $text;
		}

		return strlen( $text ) > $chars ? substr( $text, 0, $chars ) : $text;
	}

	/**
	 * Seconds, as a float.
	 *
	 * @return float
	 */
	private static function now() {
		return null === self::$clock ? microtime( true ) : (float) call_user_func( self::$clock );
	}

	/**
	 * The scan button's handler, guarded.
	 *
	 * @return void
	 */
	public static function guarded_handle() {
		try {
			self::handle();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::ACTION, $e );
			self::back( __( 'The scan could not start because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Start a scan: anybody who may clear, with a nonce. Reading changes nothing.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( ADVCM_Capabilities::PURGE ) ) {
			wp_die( esc_html__( 'You are not allowed to scan listings.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		$was     = self::state();
		$refusal = self::start( get_current_user_id() );

		if ( '' === $refusal ) {
			$resumed = $was['started'] && ! $was['done'];

			ADVCM_Log::add( 'freshness', $resumed ? 'scan_resumed' : 'scan', $resumed ? __( 'Listings scan picked up where it stopped', 'advcm' ) : __( 'Listings scan started', 'advcm' ) );
		}

		self::back( $refusal );
	}

	/**
	 * Back to the Freshness tab, with the refusal if there was one.
	 *
	 * @param string $notice Refusal.
	 * @return void
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( ADVCM_Screen::tab_url( 'freshness' ) );
		exit;
	}
}
