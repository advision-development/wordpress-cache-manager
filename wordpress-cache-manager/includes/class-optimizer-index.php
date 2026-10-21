<?php
/**
 * The copies NitroPack has kept longest, oldest first, per device.
 *
 * Asked for on 2026-10-21 (issue #21): a list, updating itself, of the pages whose optimized copy
 * is oldest — without being heavy. NitroPack keeps no such list and its API has no "oldest" order,
 * so this reads the copies it stores on disk, through ADVCM_Optimizer_Nitropack only.
 *
 * **How it runs.** Never in a page view or a save. A WP-Cron event every EVERY seconds (and a
 * person's refresh, which only schedules it) starts a scan; each run reads up to BATCH folders
 * within BUDGET seconds, a cursor per device, and schedules the next — the listings scan's
 * pattern, its lock and its rescue of a lost event. On BMR on 2026-10-21, 500 folders took 0.68 s.
 *
 * **Naming.** A folder is named by the md5 of a URL, so it says nothing about its page. Once the
 * folders are read, the scan hashes this site's own URLs — the home page, then published posts
 * of viewable types, then public term archives — in batches, until every kept folder is named or
 * the URLs run out; a folder none matches (a URL with a query, a page since deleted) is shown
 * unidentified. The row keeps a reference (`p:<id>`, `t:<term_taxonomy_id>`, `home`), never the
 * URL: the screen asks WordPress for it, so a permalink changed since still shows right.
 *
 * **Bounded.** One row, `advcm_optimizer_index`, not autoloaded: the scan's counters and cursor,
 * and per device at most KEEP rows twice — the last finished list, shown while the next is built,
 * and the one being built. Never a list of every page (27,365 on BMR). Worst case asserted.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Oldest copies.
 */
final class ADVCM_Optimizer_Index {

	/** The scan and its lists. */
	const OPTION = 'advcm_optimizer_index';

	/** Held while a run reads folders; names the folder it is on. */
	const LOCK = 'advcm_lock_optimizer';

	/** The WP-Cron event. */
	const HOOK = 'advcm_optimizer_scan';

	/** Copies kept per device. */
	const KEEP = 100;

	/** Devices kept, whatever the SDK names. */
	const DEVICES_KEEP = 4;

	/** Folders (or URLs to name) and seconds per run. */
	const BATCH       = 1000;
	const BUDGET      = 2;
	const NAME_BATCH  = 500;

	/**
	 * Folders per lock write. The lock names the last folder of the chunk being read, so a run
	 * that dies is stepped over by at most this many folders — and a run is not 1,000 option
	 * writes.
	 */
	const LOCK_EVERY = 50;

	/** Seconds between scans. */
	const EVERY = 21600;

	/** How overdue a run must be before a screen puts it back, and how old a lock is dead. */
	const LATE     = 120;
	const LOCK_TTL = 120;

	/** The naming phases, in order, and the prefix of the reference each leaves. */
	const PHASES = array(
		'home'  => 'home',
		'posts' => 'p:',
		'terms' => 't:',
	);

	/** @var callable|null Replaces the site's URLs, for tests. */
	private static $candidates = null;

	/** @var callable|null Replaces the clock, for tests. */
	private static $clock = null;

	/**
	 * Hook in. Registered on every request: WP-Cron is neither admin nor front end.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( self::HOOK, array( __CLASS__, 'cron' ), 10, 0 );
	}

	/**
	 * Test seam: the site's URLs for a naming phase.
	 *
	 * @param callable|null $candidates function( $phase, $after, $limit ): array id => URL, ids ascending.
	 * @return void
	 */
	public static function use_candidates( $candidates ) {
		self::$candidates = $candidates;
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
	 * The scan, every key present.
	 *
	 * @return array
	 */
	public static function state() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$state  = array();

		foreach ( array( 'started', 'by', 'done', 'finished', 'next_at', 'device', 'name_cursor', 'scanned', 'skipped' ) as $key ) {
			$state[ $key ] = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
		}

		foreach ( array( 'phase' => 'folders', 'cursor' => '', 'name_phase' => 'home' ) as $key => $default ) {
			$state[ $key ] = isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) ? $stored[ $key ] : $default;
		}

		foreach ( array( 'devices', 'work', 'rows' ) as $key ) {
			$state[ $key ] = isset( $stored[ $key ] ) && is_array( $stored[ $key ] ) ? $stored[ $key ] : array();
		}

		return $state;
	}

	/**
	 * A device's last finished list, oldest first, each with its URL ('' when unidentified) and
	 * when it lives until (estimated, from the expiry in force now).
	 *
	 * @param string $device Device.
	 * @return array[] folder, created, invalidated, stale, ref, url, lives_until.
	 */
	public static function rows( $device ) {
		$state = self::state();

		if ( empty( $state['rows'][ $device ] ) || ! is_array( $state['rows'][ $device ] ) ) {
			return array();
		}

		$expire = ADVCM_Optimizer_Nitropack::expire()['expire'];
		$out    = array();

		foreach ( $state['rows'][ $device ] as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['created'] ) ) {
				continue;
			}

			$row['url']         = self::url( isset( $row['ref'] ) ? (string) $row['ref'] : '' );
			$row['lives_until'] = $expire > 0 ? (int) $row['created'] + $expire : null;
			$out[]              = $row;
		}

		return $out;
	}

	/**
	 * Start a scan, or pick up one whose event was lost.
	 *
	 * @param int $by Who asked; 0 for the schedule.
	 * @return string Empty when started or resumed, else why not.
	 */
	public static function start( $by ) {
		if ( ! ADVCM_Optimizer_Nitropack::available() ) {
			return ADVCM_Optimizer_Nitropack::why_not();
		}

		$state = self::state();

		if ( $state['started'] && ! $state['done'] ) {
			if ( false === wp_next_scheduled( self::HOOK ) && ! self::locked() ) {
				// Its event is gone: carry on from the cursor rather than refuse for ever.
				$state['next_at'] = time();
				self::save( $state );
				self::schedule( time() );

				return '';
			}

			/* translators: %d: folders read. */
			return sprintf( __( 'A scan is already running: %d folders read.', 'advcm' ), $state['scanned'] );
		}

		$state = array_merge(
			$state,
			array(
				'started'     => time(),
				'by'          => (int) $by,
				'done'        => 0,
				'next_at'     => time(),
				'phase'       => 'folders',
				'devices'     => array_slice( ADVCM_Optimizer_Nitropack::devices(), 0, self::DEVICES_KEEP ),
				'device'      => 0,
				'cursor'      => '',
				'name_phase'  => 'home',
				'name_cursor' => 0,
				'scanned'     => 0,
				'skipped'     => 0,
				'work'        => array(),
			)
		);

		self::save( $state );

		// The next scan's event, six hours out, is replaced by this one.
		wp_clear_scheduled_hook( self::HOOK );
		self::schedule( time() );

		return '';
	}

	/**
	 * WP-Cron's run: the next batch, or a new scan when none is running.
	 *
	 * @return void
	 */
	public static function cron() {
		// On a site without NitroPack start() refuses and run_batch() reads nothing.
		$state = self::state();

		if ( ! $state['started'] || $state['done'] ) {
			self::start( 0 );
		}

		self::run_batch( self::BATCH, self::BUDGET );
	}

	/**
	 * Put back a lost event: a scan running with no event and none for over LATE seconds, or, on
	 * a site with NitroPack, none ever run or the next one over LATE seconds late. Called when a
	 * screen opens — never in a save or a page view.
	 *
	 * @return int 1 when scheduled, 0 otherwise.
	 */
	public static function rescue() {
		if ( false !== wp_next_scheduled( self::HOOK ) || self::locked() || ! ADVCM_Optimizer_Nitropack::available() ) {
			return 0;
		}

		$state = self::state();

		// next_at is the next batch while a scan runs, and the next scan once one is done.
		if ( $state['started'] && $state['next_at'] > time() - self::LATE ) {
			return 0;
		}

		self::schedule( time() );

		return 1;
	}

	/**
	 * Read up to $size folders, or name up to NAME_BATCH URLs, within $budget seconds.
	 *
	 * @param int $size   Folders.
	 * @param int $budget Seconds.
	 * @return int Folders read or URLs hashed.
	 */
	public static function run_batch( $size, $budget ) {
		$state = self::state();

		if ( ! $state['started'] || $state['done'] || ! ADVCM_Optimizer_Nitropack::available() ) {
			return 0;
		}

		$lock = get_option( self::LOCK, null );

		if ( is_array( $lock ) ) {
			if ( isset( $lock['at'] ) && (int) $lock['at'] > time() - self::LOCK_TTL ) {
				return 0;
			}

			// A run died holding it. The chunk it named is the likeliest cause: step over it — on the
			// device it was reading, which may be past the one the scan last saved.
			$on = isset( $lock['device'] ) ? (int) $lock['device'] : $state['device'];

			if ( ! empty( $lock['folder'] ) && 'folders' === $state['phase'] && ( $on > $state['device'] || ( $on === $state['device'] && strcmp( (string) $lock['folder'], $state['cursor'] ) > 0 ) ) ) {
				$state['device'] = $on;
				$state['cursor'] = (string) $lock['folder'];
				$state['skipped']++;
			}

			delete_option( self::LOCK );
		}

		if ( ! add_option( self::LOCK, array( 'at' => time(), 'folder' => '' ), '', false ) ) {
			return 0;
		}

		$done = 0;

		try {
			$began = self::now();
			$done  = 'names' === $state['phase'] ? self::name( $state, $began, $budget ) : self::read( $state, (int) $size, $began, $budget );

			if ( 'done' === $state['phase'] ) {
				self::finish( $state );
			} else {
				$state['next_at'] = time();
				self::save( $state );
				self::schedule( time() );
			}
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'optimizer_scan', $e );

			// The same batch would throw again on every run, and the list would never refresh:
			// step over what was being read — the chunk the lock names, or the rest of a naming
			// phase — count it, and carry on.
			try {
				if ( 'names' === $state['phase'] ) {
					self::next_phase( $state );
				} else {
					$held = get_option( self::LOCK, null );

					if ( is_array( $held ) && ! empty( $held['folder'] ) ) {
						$state['device'] = isset( $held['device'] ) ? (int) $held['device'] : $state['device'];
						$state['cursor'] = (string) $held['folder'];
					}
				}

				$state['skipped']++;
				$state['next_at'] = time();
				self::save( $state );
				self::schedule( time() );
			} catch ( Throwable $again ) {
				ADVCM_Safe::report( 'optimizer_scan', $again );
			}
		} finally {
			delete_option( self::LOCK );
		}

		return $done;
	}

	/**
	 * Read folders from the cursor, device after device, keeping the oldest copies.
	 *
	 * @param array $state  The scan.
	 * @param int   $size   Folders.
	 * @param float $began  When the run began.
	 * @param int   $budget Seconds.
	 * @return int Folders read.
	 */
	private static function read( array &$state, $size, $began, $budget ) {
		$read = 0;

		while ( $read < $size && $state['device'] < count( $state['devices'] ) ) {
			$device = (string) $state['devices'][ $state['device'] ];
			$dir    = ADVCM_Optimizer_Nitropack::device_dir( $device );
			$names  = '' === $dir ? array() : self::after( $dir, $state['cursor'] );
			$found  = array();
			$out    = false;

			foreach ( $names as $i => $name ) {
				if ( $read >= $size || ( $read > 0 && self::now() - $began >= $budget ) ) {
					$out = true;
					break;
				}

				if ( 0 === $i % self::LOCK_EVERY ) {
					update_option(
						self::LOCK,
						array(
							'at'     => time(),
							'device' => $state['device'],
							'folder' => $names[ min( $i + self::LOCK_EVERY, count( $names ) ) - 1 ],
						),
						false
					);
				}

				$copy = ADVCM_Optimizer_Nitropack::newest( $dir . '/' . $name );

				if ( null !== $copy ) {
					$found[] = array(
						'folder'      => substr( $name, 0, 64 ),
						'created'     => (int) $copy['at'],
						'invalidated' => '_i' === substr( $name, -2 ) ? 1 : 0,
						'stale'       => $copy['stale'] ? 1 : 0,
						'ref'         => '',
					);
				}

				$state['cursor'] = $name;
				$state['scanned']++;
				$read++;
			}

			$kept                     = isset( $state['work'][ $device ] ) ? (array) $state['work'][ $device ] : array();
			$state['work'][ $device ] = self::oldest( array_merge( $kept, $found ) );

			if ( $out ) {
				return $read;
			}

			$state['device']++;
			$state['cursor'] = '';
		}

		if ( $state['device'] >= count( $state['devices'] ) ) {
			$state['phase'] = 'names';
		}

		return $read;
	}

	/**
	 * Hash this site's URLs, phase after phase, naming the kept folders they match.
	 *
	 * @param array $state  The scan.
	 * @param float $began  When the run began.
	 * @param int   $budget Seconds.
	 * @return int URLs hashed.
	 */
	private static function name( array &$state, $began, $budget ) {
		$hashed  = 0;
		$devices = $state['devices'];
		$sample  = isset( $devices[0] ) ? (string) $devices[0] : '';

		while ( isset( self::PHASES[ $state['name_phase'] ] ) ) {
			$unnamed = self::unnamed( $state );

			if ( empty( $unnamed ) || '' === $sample ) {
				break;
			}

			if ( $hashed > 0 && self::now() - $began >= $budget ) {
				return $hashed;
			}

			$phase = $state['name_phase'];
			$urls  = (array) self::candidates( $phase, $state['name_cursor'], self::NAME_BATCH );

			foreach ( $urls as $id => $url ) {
				// The SDK's own folder rule; the device only prefixes the path.
				$hash = basename( ADVCM_Optimizer_Nitropack::url_dir( $sample, ADVCM_Optimizer_Nitropack::normalize( (string) $url ), false ) );

				if ( isset( $unnamed[ $hash ] ) ) {
					self::label( $state, $hash, 'home' === $phase ? 'home' : self::PHASES[ $phase ] . (int) $id );
				}

				$state['name_cursor'] = (int) $id;
				$hashed++;
			}

			if ( count( $urls ) < self::NAME_BATCH ) {
				self::next_phase( $state );
			}
		}

		$state['phase'] = 'done';

		return $hashed;
	}

	/**
	 * On to the next naming phase, or to none.
	 *
	 * @param array $state The scan.
	 * @return void
	 */
	private static function next_phase( array &$state ) {
		$keys = array_keys( self::PHASES );
		$at   = array_search( $state['name_phase'], $keys, true );
		$next = false === $at ? count( $keys ) : $at + 1;

		$state['name_phase']  = isset( $keys[ $next ] ) ? $keys[ $next ] : 'none';
		$state['name_cursor'] = 0;
	}

	/**
	 * The kept folders not yet named, by their hash without the invalidated mark.
	 *
	 * @param array $state The scan.
	 * @return array hash => true.
	 */
	private static function unnamed( array $state ) {
		$out = array();

		foreach ( $state['work'] as $rows ) {
			foreach ( (array) $rows as $row ) {
				if ( '' === $row['ref'] ) {
					$out[ self::hash( $row['folder'] ) ] = true;
				}
			}
		}

		return $out;
	}

	/**
	 * Name every kept folder with a hash, on every device.
	 *
	 * @param array  $state The scan.
	 * @param string $hash  Hash.
	 * @param string $ref   Reference.
	 * @return void
	 */
	private static function label( array &$state, $hash, $ref ) {
		foreach ( $state['work'] as $device => $rows ) {
			foreach ( (array) $rows as $i => $row ) {
				if ( self::hash( $row['folder'] ) === $hash ) {
					$state['work'][ $device ][ $i ]['ref'] = $ref;
				}
			}
		}
	}

	/**
	 * A folder's hash: its name without the invalidated mark.
	 *
	 * @param string $folder Folder.
	 * @return string
	 */
	private static function hash( $folder ) {
		return '_i' === substr( $folder, -2 ) ? substr( $folder, 0, -2 ) : $folder;
	}

	/**
	 * The scan is done: its list replaces the last one, and the next scan is EVERY seconds out.
	 *
	 * @param array $state The scan.
	 * @return void
	 */
	private static function finish( array $state ) {
		$state['done']     = time();
		$state['finished'] = time();
		$state['rows']     = $state['work'];
		$state['work']     = array();
		$state['next_at']  = time() + self::EVERY;

		self::save( $state );

		wp_clear_scheduled_hook( self::HOOK );
		self::schedule( $state['next_at'] );
	}

	/**
	 * The KEEP oldest rows, oldest first. Explicit to the last key: usort is not stable before PHP 8.
	 *
	 * @param array[] $rows Rows.
	 * @return array[]
	 */
	private static function oldest( array $rows ) {
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['created'] === $b['created'] ? strcmp( $a['folder'], $b['folder'] ) : ( $a['created'] < $b['created'] ? -1 : 1 );
			}
		);

		return array_slice( $rows, 0, self::KEEP );
	}

	/**
	 * A device's folder names after a cursor, in order.
	 *
	 * @param string $dir    Device folder.
	 * @param string $cursor Last name read.
	 * @return string[]
	 */
	private static function after( $dir, $cursor ) {
		$out = array();

		$entries = @scandir( $dir );

		foreach ( false === $entries ? array() : $entries as $name ) {
			$name = (string) $name;

			if ( '.' !== $name && '..' !== $name && strcmp( $name, $cursor ) > 0 ) {
				$out[] = $name;
			}
		}

		sort( $out, SORT_STRING );

		return $out;
	}

	/**
	 * This site's URLs for a naming phase after an id.
	 *
	 * @param string $phase home, posts or terms.
	 * @param int    $after Id.
	 * @param int    $limit How many.
	 * @return array id => URL.
	 */
	private static function candidates( $phase, $after, $limit ) {
		if ( null !== self::$candidates ) {
			return call_user_func( self::$candidates, $phase, (int) $after, (int) $limit );
		}

		if ( 'home' === $phase ) {
			return $after < 1 ? array( 1 => home_url( '/' ) ) : array();
		}

		global $wpdb;

		$out = array();

		if ( 'posts' === $phase ) {
			$types = array_values( array_filter( get_post_types( array( 'public' => true ) ), 'is_post_type_viewable' ) );
			$types = array_diff( $types, array( 'attachment' ) );

			if ( empty( $types ) ) {
				return $out;
			}

			$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders -- a cursor over an index; no API pages by id across every type.
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND ID > %d ORDER BY ID ASC LIMIT %d", array_merge( array_values( $types ), array( (int) $after, (int) $limit ) ) ) ) );
			// phpcs:enable

			if ( function_exists( '_prime_post_caches' ) ) {
				_prime_post_caches( $ids, false, false );
			}

			foreach ( $ids as $id ) {
				$out[ $id ] = (string) get_permalink( $id );
			}

			return $out;
		}

		$taxonomies = array_values( get_taxonomies( array( 'public' => true ) ) );

		if ( empty( $taxonomies ) ) {
			return $out;
		}

		$in = implode( ',', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders -- as above.
		$terms = (array) $wpdb->get_results( $wpdb->prepare( "SELECT term_taxonomy_id, term_id, taxonomy FROM {$wpdb->term_taxonomy} WHERE taxonomy IN ($in) AND count > 0 AND term_taxonomy_id > %d ORDER BY term_taxonomy_id ASC LIMIT %d", array_merge( $taxonomies, array( (int) $after, (int) $limit ) ) ) );
		// phpcs:enable

		foreach ( $terms as $term ) {
			$link = get_term_link( (int) $term->term_id, (string) $term->taxonomy );

			$out[ (int) $term->term_taxonomy_id ] = is_string( $link ) ? $link : '';
		}

		return $out;
	}

	/**
	 * A reference's URL now, or '' when unidentified or gone.
	 *
	 * @param string $ref Reference.
	 * @return string
	 */
	private static function url( $ref ) {
		try {
			if ( 'home' === $ref ) {
				$url = home_url( '/' );
			} elseif ( 0 === strpos( $ref, 'p:' ) ) {
				$url = get_permalink( (int) substr( $ref, 2 ) );
			} elseif ( 0 === strpos( $ref, 't:' ) ) {
				$term = get_term_by( 'term_taxonomy_id', (int) substr( $ref, 2 ) );
				$url  = is_object( $term ) ? get_term_link( $term ) : '';
			} else {
				$url = '';
			}
		} catch ( Throwable $e ) {
			$url = '';
		}

		return is_string( $url ) ? substr( $url, 0, 1024 ) : '';
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
	 * An event, unless one is already on the schedule.
	 *
	 * @param int $at When.
	 * @return void
	 */
	private static function schedule( $at ) {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( (int) $at, self::HOOK );
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
	 * Seconds, as a float.
	 *
	 * @return float
	 */
	private static function now() {
		return null === self::$clock ? microtime( true ) : (float) call_user_func( self::$clock );
	}
}
