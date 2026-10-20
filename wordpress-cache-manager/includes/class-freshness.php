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
	 * Pages people cleared by hand more than once, from the measurements kept: how many times,
	 * the latest, the median cache age at those clears, and whether a rule already names the page.
	 * A page here with no rule is one a rule could clear instead; one whose copies were young when
	 * cleared had old content, not an old cache.
	 *
	 * @param int $min Clears at least.
	 * @return array[] url, times, last, median_age (int|null), has_rule.
	 */
	public static function often_by_hand( $min = 2 ) {
		$pages = array();

		foreach ( self::rows() as $row ) {
			if ( empty( $row['url'] ) ) {
				continue;
			}

			$url = (string) $row['url'];

			if ( ! isset( $pages[ $url ] ) ) {
				$pages[ $url ] = array( 'url' => $url, 'times' => 0, 'last' => 0, 'ages' => array() );
			}

			$pages[ $url ]['times']++;
			$pages[ $url ]['last'] = max( $pages[ $url ]['last'], isset( $row['at'] ) ? (int) $row['at'] : 0 );

			if ( isset( $row['age'] ) && null !== $row['age'] ) {
				$pages[ $url ]['ages'][] = (int) $row['age'];
			}
		}

		$ruled = array();

		foreach ( ADVCM_Auto::rules() as $rule ) {
			if ( $rule['enabled'] && ! $rule['own'] ) {
				foreach ( $rule['urls'] as $rule_url ) {
					$ruled[ self::path( $rule_url ) ] = true;
				}
			}
		}

		$out = array();

		foreach ( $pages as $page ) {
			if ( $page['times'] < $min ) {
				continue;
			}

			sort( $page['ages'] );
			$n = count( $page['ages'] );

			$out[] = array(
				'url'        => $page['url'],
				'times'      => $page['times'],
				'last'       => $page['last'],
				'median_age' => 0 === $n ? null : ( 1 === $n % 2 ? $page['ages'][ ( $n - 1 ) / 2 ] : (int) round( ( $page['ages'][ $n / 2 - 1 ] + $page['ages'][ $n / 2 ] ) / 2 ) ),
				'has_rule'   => isset( $ruled[ self::path( $page['url'] ) ] ),
			);
		}

		usort(
			$out,
			function ( $a, $b ) {
				return $a['times'] !== $b['times'] ? $b['times'] - $a['times'] : $b['last'] - $a['last'];
			}
		);

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

		$ok  = is_array( $reading );
		$now = time();

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
