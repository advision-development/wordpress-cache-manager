<?php
/**
 * How old each page was when a clear cleared it.
 *
 * A clear of some pages first asks each page once, as the Cache age tab does, and keeps what the
 * layers said. Read over weeks, that answers what the History cannot: whether a page somebody
 * cleared by hand had been held for hours by a layer, or was fresh and the content itself was
 * old — the second is not a cache problem, and no clear fixes it.
 *
 * **Not a stage.** It changes nothing, it runs before the first stage of a job's first run and
 * never again for that job, and nothing it does can stop the clear: every failure is a row with
 * no answer. A whole-site clear is never measured; it has no list of pages, and measuring every
 * page would be a crawl.
 *
 * **Bounded.** At most MEASURE_MAX pages per job, each with a MEASURE_TIMEOUT, and no probe
 * starts that could end past MEASURE_BUDGET; the pages left out are counted on the job. Rows are
 * kept apart for people (KEEP) and automatic clears (AUTO_KEEP), as the History is, so a busy
 * auto-clear never pushes out the clears somebody pressed. URLs are cut to URL_CHARS.
 *
 * **Generic.** Only the layers a site has answer; a site with no NitroPack, no WP Engine and no
 * Cloudflare gets rows with its HTTP code and, often, an unknown age. Saying so is the point.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache age at clear.
 */
final class ADVCM_Freshness {

	/** Measurements of clears people started. */
	const OPTION = 'advcm_freshness';

	/** Measurements of automatic clears. */
	const OPTION_AUTO = 'advcm_freshness_auto';

	/** Rows kept in each. */
	const KEEP      = 100;
	const AUTO_KEEP = 100;

	/** Pages measured per job, seconds per page, seconds per job. */
	const MEASURE_MAX     = 10;
	const MEASURE_TIMEOUT = 5;
	const MEASURE_BUDGET  = 20;

	/**
	 * What the measurements add up to: per page and per day, in one row of known size. The rows
	 * above keep the last clears; on BMR auto-clear fills them in hours, so a week of data lives
	 * only here.
	 */
	const STATS = 'advcm_freshness_stats';

	/** Pages and days summed. Past them, the page cleared longest ago and the oldest day go. */
	const STATS_PAGES = 200;
	const STATS_DAYS  = 30;

	/**
	 * Characters of a URL kept as a page's key in the sums. Half a row's URL: two hundred keys of
	 * 2,048 characters alone would be 400 KB, and no page anybody clears has a longer address.
	 */
	const STATS_URL_CHARS = 1024;

	/** A copy younger than this, in seconds, was fresh when cleared. */
	const YOUNG = 600;

	/** Characters kept of a URL and of a sentence. */
	const URL_CHARS  = 2048;
	const TEXT_CHARS = 150;

	/** @var callable|null Replaces the probe, for tests. */
	private static $prober = null;

	/** @var callable|null Replaces the clock, for tests. */
	private static $clock = null;

	/**
	 * Test seam: what asks a page.
	 *
	 * @param callable|null $prober function( $url, $timeout ): array|string.
	 * @return void
	 */
	public static function use_prober( $prober ) {
		self::$prober = $prober;
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
	 * Measure a job's pages, once. Never throws.
	 *
	 * @param array $job The job, before its first stage.
	 * @return array The job, marked measured, with not_measured.
	 */
	public static function measure( array $job ) {
		if ( ! empty( $job['measured'] ) ) {
			return $job;
		}

		$job['measured']     = true;
		$job['not_measured'] = 0;

		try {
			if ( ! isset( $job['scope'] ) || 'urls' !== $job['scope'] || empty( $job['urls'] ) || ! ADVCM_Settings::get()['measure_before_clear'] ) {
				return $job;
			}

			// A job that was running when this version arrived: its first stages have cleared, and
			// what a page would say now is not what it held before.
			foreach ( isset( $job['steps'] ) ? (array) $job['steps'] : array() as $step ) {
				if ( isset( $step['status'] ) && 'pending' !== $step['status'] ) {
					return $job;
				}
			}

			$urls  = array_values( (array) $job['urls'] );
			$began = self::now();
			$rows  = array();

			foreach ( $urls as $i => $url ) {
				if ( $i >= self::MEASURE_MAX || self::now() - $began + self::MEASURE_TIMEOUT > self::MEASURE_BUDGET ) {
					$job['not_measured'] = count( $urls ) - $i;
					break;
				}

				$row    = self::row( $job, (string) $url );
				$rows[] = $row;

				// No answer at all: a site that cannot reach itself, or a host that drops the
				// request. The next page would wait its full timeout too, in a person's request.
				if ( 0 === $row['code'] ) {
					$job['not_measured'] = count( $urls ) - $i - 1;
					break;
				}
			}

			self::store( $rows, isset( $job['source'] ) && 'auto' === $job['source'] );
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'freshness', $e );
		}

		return $job;
	}

	/**
	 * The stored measurements, newest first.
	 *
	 * @param bool $auto The automatic clears' row rather than people's.
	 * @return array[]
	 */
	public static function rows( $auto = false ) {
		$rows = get_option( $auto ? self::OPTION_AUTO : self::OPTION, array() );

		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	/**
	 * One job's measurements, from whichever row holds them.
	 *
	 * @param string $id Job id.
	 * @return array[]
	 */
	public static function for_job( $id ) {
		$out = array();

		foreach ( array_merge( self::rows(), self::rows( true ) ) as $row ) {
			if ( isset( $row['job'] ) && (string) $id === $row['job'] ) {
				$out[] = $row;
			}
		}

		return $out;
	}

	/**
	 * A URL's path, trailing slash and all, for comparing a rule's URL with a measured one.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );

		return '/' . trim( $path, '/' );
	}

	/**
	 * Ask one page. A failure is a row with no answer.
	 *
	 * @param array  $job The job.
	 * @param string $url The page.
	 * @return array
	 */
	private static function row( array $job, $url ) {
		$copy    = null;
		$reading = null;

		try {
			// NitroPack's copy first: the request below can make it build a new one.
			$copy    = ADVCM_Probe::nitropack_copy( $url );
			$reading = null === self::$prober ? ADVCM_Probe::url( $url, self::MEASURE_TIMEOUT ) : call_user_func( self::$prober, $url, self::MEASURE_TIMEOUT );
		} catch ( Throwable $e ) {
			$reading = null;
		}

		$ok    = is_array( $reading );
		$now   = time();
		$rules = array();

		// The rules that set an automatic clear off, so a rule that keeps clearing fresh pages
		// can be named.
		foreach ( isset( $job['options']['triggers'] ) && is_array( $job['options']['triggers'] ) ? $job['options']['triggers'] : array() as $trigger ) {
			if ( is_array( $trigger ) && isset( $trigger['rule'] ) && count( $rules ) < 10 ) {
				$rules[] = substr( (string) $trigger['rule'], 0, 40 );
			}
		}

		// An age only for a page: a redirect or an error page has its own cached copy, and its age
		// says nothing about the page the clear was for.
		$page = $ok && isset( $reading['code'] ) && (int) $reading['code'] >= 200 && (int) $reading['code'] < 300;

		return array(
			'at'          => $now,
			'url'         => substr( $url, 0, self::URL_CHARS ),
			'job'         => isset( $job['id'] ) ? (string) $job['id'] : '',
			'source'      => isset( $job['source'] ) ? substr( (string) $job['source'], 0, 20 ) : '',
			'by'          => isset( $job['by'] ) ? (int) $job['by'] : 0,
			'code'        => $ok && isset( $reading['code'] ) ? (int) $reading['code'] : 0,
			'age'         => $page && isset( $reading['age']['seconds'] ) ? (int) $reading['age']['seconds'] : null,
			'how'         => $page && isset( $reading['age']['how'] ) ? substr( (string) $reading['age']['how'], 0, 20 ) : ( $ok ? 'not a page' : 'no answer' ),
			'ages'        => $page && isset( $reading['ages'] ) && is_array( $reading['ages'] ) ? array_map( 'intval', array_slice( $reading['ages'], 0, 4, true ) ) : array(),
			'nitro_state' => $page && isset( $reading['nitropack_state'] ) ? (string) $reading['nitropack_state'] : null,
			'nitro_copy'  => null === $copy ? null : $copy['state'],
			'nitro_age'   => null === $copy ? null : max( 0, $now - (int) $copy['at'] ),
			'rules'       => array_values( array_unique( $rules ) ),
		);
	}

	/**
	 * Put a job's rows at the top of their row and cut it to its cap.
	 *
	 * @param array[] $rows New rows, in page order.
	 * @param bool    $auto Automatic.
	 * @return void
	 */
	private static function store( array $rows, $auto ) {
		if ( empty( $rows ) ) {
			return;
		}

		$name = $auto ? self::OPTION_AUTO : self::OPTION;
		$all  = array_slice( array_merge( $rows, self::rows( $auto ) ), 0, $auto ? self::AUTO_KEEP : self::KEEP );

		update_option( $name, $all, false );

		self::add_up( $rows, $auto );
	}

	/**
	 * Add measurements to the per-page and per-day sums.
	 *
	 * Not atomic: two clears writing at once can each miss the other's rows. The sums are then
	 * a little low, never wrong in kind.
	 *
	 * @param array[] $rows Rows just measured.
	 * @param bool    $auto Automatic.
	 * @return void
	 */
	private static function add_up( array $rows, $auto ) {
		$stats  = self::stats();
		$target = (int) ADVCM_Settings::get()['cache_target'] * HOUR_IN_SECONDS;

		foreach ( $rows as $row ) {
			$url = substr( (string) $row['url'], 0, self::STATS_URL_CHARS );
			$age = $row['age'];
			$day = gmdate( 'Y-m-d', (int) $row['at'] );

			if ( ! isset( $stats['pages'][ $url ] ) ) {
				$stats['pages'][ $url ] = array( 'n' => 0, 'hand' => 0, 'auto' => 0, 'unk' => 0, 'young' => 0, 'over' => 0, 'max' => 0, 'sum' => 0, 'hsum' => 0, 'hk' => 0, 'last' => 0, 'rules' => array() );
			}

			if ( ! isset( $stats['days'][ $day ] ) ) {
				$stats['days'][ $day ] = array( 'n' => 0, 'unk' => 0, 'young' => 0, 'over' => 0, 'max' => 0 );
			}

			$page = &$stats['pages'][ $url ];
			$page['n']++;
			$page[ $auto ? 'auto' : 'hand' ]++;
			$page['last'] = max( $page['last'], (int) $row['at'] );
			$page['rules'] = array_slice( array_values( array_unique( array_merge( $page['rules'], (array) $row['rules'] ) ) ), 0, 5 );

			$stats['days'][ $day ]['n']++;

			if ( null === $age ) {
				$page['unk']++;
				$stats['days'][ $day ]['unk']++;
			} elseif ( 'exact' !== $row['how'] ) {
				// "At most" is a bound — the time since this plugin last cleared the page — not an
				// age: the copy may be minutes old. It proves a young copy, never an old one.
				if ( $age < self::YOUNG ) {
					$page['young']++;
					$stats['days'][ $day ]['young']++;
				}
			} else {
				$page['sum'] += $age;
				$page['max']  = max( $page['max'], $age );

				if ( ! $auto ) {
					$page['hsum'] += $age;
					$page['hk']++;
				}

				foreach ( array( 'young' => $age < self::YOUNG, 'over' => $age > $target ) as $key => $hit ) {
					if ( $hit ) {
						$page[ $key ]++;
						$stats['days'][ $day ][ $key ]++;
					}
				}

				$stats['days'][ $day ]['max'] = max( $stats['days'][ $day ]['max'], $age );
			}

			unset( $page );

			// Over the cap, the page cleared longest ago goes — never the one just written.
			if ( count( $stats['pages'] ) > self::STATS_PAGES ) {
				$oldest = null;

				foreach ( $stats['pages'] as $known => $sums ) {
					if ( $known !== $url && ( null === $oldest || $sums['last'] < $stats['pages'][ $oldest ]['last'] ) ) {
						$oldest = $known;
					}
				}

				unset( $stats['pages'][ $oldest ] );
			}
		}

		// The last 30 calendar days, not the last 30 days that had a clear: on a quiet site the
		// latter would read months as a month.
		$oldest = gmdate( 'Y-m-d', time() - ( self::STATS_DAYS - 1 ) * DAY_IN_SECONDS );

		foreach ( array_keys( $stats['days'] ) as $day ) {
			if ( $day < $oldest ) {
				unset( $stats['days'][ $day ] );
			}
		}

		ksort( $stats['days'] );

		update_option( self::STATS, $stats, false );
	}

	/**
	 * The sums, every key present.
	 *
	 * @return array pages (url => sums), days (Y-m-d => sums).
	 */
	public static function stats() {
		$stats = get_option( self::STATS, array() );

		return array(
			'pages' => isset( $stats['pages'] ) && is_array( $stats['pages'] ) ? $stats['pages'] : array(),
			'days'  => isset( $stats['days'] ) && is_array( $stats['days'] ) ? $stats['days'] : array(),
		);
	}

	/**
	 * What the sums ask for.
	 *
	 * - over: pages a cache kept past the target, with whether a rule clears them — without one,
	 *   a rule is the fix.
	 * - young: pages whose clears keep finding a fresh copy (2 or more, three in four under 10
	 *   minutes) — the page looked old for another reason, or a rule clears it more than needed.
	 * - hand: pages cleared by hand twice or more, with the average age those clears found.
	 * - summary: the days kept, added up.
	 *
	 * @return array
	 */
	public static function insights() {
		$stats = self::stats();
		$ruled = self::ruled();
		$out   = array(
			'summary' => array( 'n' => 0, 'over' => 0, 'young' => 0, 'unk' => 0, 'days' => count( $stats['days'] ) ),
			'over'    => array(),
			'young'   => array(),
			'hand'    => array(),
		);

		foreach ( $stats['days'] as $day ) {
			foreach ( array( 'n', 'over', 'young', 'unk' ) as $key ) {
				$out['summary'][ $key ] += isset( $day[ $key ] ) ? (int) $day[ $key ] : 0;
			}
		}

		$own = self::own_on();

		foreach ( $stats['pages'] as $url => $page ) {
			// Named by an enabled rule, or a post the own-page rule has been clearing.
			$has_rule = isset( $ruled[ self::path( $url ) ] ) || ( $own && in_array( ADVCM_Auto::OWN, (array) $page['rules'], true ) );

			if ( $page['over'] > 0 ) {
				$out['over'][] = array( 'url' => $url, 'over' => $page['over'], 'n' => $page['n'], 'max' => $page['max'], 'has_rule' => $has_rule );
			}

			if ( $page['n'] >= 2 && $page['young'] * 4 >= $page['n'] * 3 ) {
				$out['young'][] = array( 'url' => $url, 'n' => $page['n'], 'young' => $page['young'], 'rules' => $page['rules'] );
			}

			if ( $page['hand'] >= 2 ) {
				$out['hand'][] = array( 'url' => $url, 'times' => $page['hand'], 'last' => $page['last'], 'avg_age' => $page['hk'] ? (int) round( $page['hsum'] / $page['hk'] ) : null, 'has_rule' => $has_rule );
			}
		}

		$sort = function ( $first, $second ) {
			return function ( $a, $b ) use ( $first, $second ) {
				if ( $a[ $first ] !== $b[ $first ] ) {
					return $b[ $first ] - $a[ $first ];
				}

				return $b[ $second ] - $a[ $second ];
			};
		};

		usort( $out['over'], $sort( 'over', 'max' ) );
		usort( $out['young'], $sort( 'n', 'young' ) );
		usort( $out['hand'], $sort( 'times', 'last' ) );

		return $out;
	}

	/**
	 * Whether "each post's own page" is switched on.
	 *
	 * @return bool
	 */
	private static function own_on() {
		$rules = ADVCM_Auto::rules();

		return isset( $rules[ ADVCM_Auto::OWN ] ) && $rules[ ADVCM_Auto::OWN ]['enabled'];
	}

	/**
	 * The paths enabled rules name, as keys.
	 *
	 * @return array
	 */
	private static function ruled() {
		$ruled = array();

		foreach ( ADVCM_Auto::rules() as $rule ) {
			if ( $rule['enabled'] && ! $rule['own'] ) {
				foreach ( $rule['urls'] as $rule_url ) {
					$ruled[ self::path( $rule_url ) ] = true;
				}
			}
		}

		return $ruled;
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
