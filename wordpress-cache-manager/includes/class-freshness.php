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

				$rows[] = self::row( $job, (string) $url );
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

		return array(
			'at'          => $now,
			'url'         => substr( $url, 0, self::URL_CHARS ),
			'job'         => isset( $job['id'] ) ? (string) $job['id'] : '',
			'source'      => isset( $job['source'] ) ? substr( (string) $job['source'], 0, 20 ) : '',
			'by'          => isset( $job['by'] ) ? (int) $job['by'] : 0,
			'code'        => $ok && isset( $reading['code'] ) ? (int) $reading['code'] : 0,
			'age'         => $ok && isset( $reading['age']['seconds'] ) ? (int) $reading['age']['seconds'] : null,
			'how'         => $ok && isset( $reading['age']['how'] ) ? substr( (string) $reading['age']['how'], 0, 20 ) : 'no answer',
			'ages'        => $ok && isset( $reading['ages'] ) && is_array( $reading['ages'] ) ? array_map( 'intval', array_slice( $reading['ages'], 0, 4, true ) ) : array(),
			'nitro_state' => $ok && isset( $reading['nitropack_state'] ) ? (string) $reading['nitropack_state'] : null,
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
