<?php
/**
 * How old is the cached copy of a page, and which layer answered.
 *
 * The site requests one of its own URLs, once, and reads what the layers in front of it say in
 * their headers. Nothing is cleared and nothing is written; the request is the same GET a visitor
 * makes, so a cold page is built by it like a visitor's would be.
 *
 * **Age is only as good as the headers.** Cloudflare and most Varnish setups send `Age`, and then
 * the answer is exact. WP Engine does not: it sends `x-cache: HIT: <n>` and the length it will
 * keep the page (`x-cacheable: YES:<seconds>`). So the age is reported with where it came from:
 *
 * - `Age` header — exact.
 * - `x-cache-ctime` — exact: WP Engine's Varnish sends the unix time it stored the copy.
 * - `MISS` — 0 seconds; this request just built the copy.
 * - `HIT`, and this plugin cleared that URL or the whole site within the page's lifetime — at most
 *   the time since that clear, because the copy cannot be older than the clear that emptied it.
 * - otherwise unknown, with the most it can be (the lifetime) and how many times it was served.
 *
 * The probe never invents a number. A page shown as "unknown, at most 1 hour" is a page whose
 * layers did not say, and saying so is the point.
 *
 * Same rules as everything else that leaves the site: only this site's own origin (through
 * ADVCM_Urls), no redirects followed, a short timeout, and a user agent that names it.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cache-age probe.
 */
final class ADVCM_Probe {

	/** Seconds the request may take. */
	const TIMEOUT = 15;

	/** Bytes of the page read for NitroPack's state, which it writes near the top. */
	const BODY_READ = 524288;

	/**
	 * Probe one URL on this site.
	 *
	 * @param string $input   A URL or a path.
	 * @param int    $timeout Seconds the request may take.
	 * @return array|string The reading, or why it was not made.
	 */
	public static function url( $input, $timeout = self::TIMEOUT ) {
		$parsed = ADVCM_Urls::parse( array( $input ), home_url() );

		if ( empty( $parsed['accepted'] ) ) {
			return __( 'That is not a page on this site.', 'advcm' );
		}

		$url     = $parsed['accepted'][0];
		$started = microtime( true );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => (int) $timeout,
				'redirection' => 0,
				'user-agent'  => 'Mozilla/5.0 (compatible; AdvisionCacheProbe/' . ( defined( 'ADVCM_VERSION' ) ? ADVCM_VERSION : '0' ) . ')',
			)
		);

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'url'     => $url,
				'code'    => 0,
				'ms'      => $elapsed,
				'error'   => $response->get_error_message(),
				'layers'  => array(),
				'age'     => array( 'seconds' => null, 'how' => 'no answer' ),
				'max_ttl' => null,
				'ages'    => array(),
				'hits'    => null,
				'nitropack_state' => null,
			);
		}

		$headers = array();

		// Iterated, never cast: since WordPress 6.2 this is a CaseInsensitiveDictionary object, and
		// (array) on it yields its private property, not the headers.
		$raw = wp_remote_retrieve_headers( $response );

		if ( is_array( $raw ) || $raw instanceof Traversable ) {
			foreach ( $raw as $name => $value ) {
				$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
			}
		}

		$body = substr( (string) wp_remote_retrieve_body( $response ), 0, self::BODY_READ );

		return self::read( $url, (int) wp_remote_retrieve_response_code( $response ), $headers, $elapsed, self::last_cleared( $url ), time(), $body );
	}

	/**
	 * Turn headers into a reading. Pure, so the age rules can be tested without a network.
	 *
	 * @param string   $url     The URL probed.
	 * @param int      $code    HTTP status.
	 * @param array    $headers Lower-cased header => value.
	 * @param int      $ms      How long the answer took.
	 * @param int|null $cleared When this plugin last cleared this URL or the whole site, or null.
	 * @param int      $now     The time of the reading.
	 * @param string   $body    The start of the page, for what a layer writes into it.
	 * @return array
	 */
	public static function read( $url, $code, array $headers, $ms, $cleared, $now, $body = '' ) {
		$layers = array();

		if ( isset( $headers['cf-cache-status'] ) ) {
			$layers['Cloudflare'] = $headers['cf-cache-status'];
		}

		if ( isset( $headers['x-cache'] ) ) {
			$layers['host cache'] = $headers['x-cache'] . ( isset( $headers['x-cacheable'] ) ? ' (' . $headers['x-cacheable'] . ')' : '' );
		}

		if ( isset( $headers['x-nitro-cache'] ) ) {
			$layers['NitroPack'] = $headers['x-nitro-cache'];
		}

		if ( isset( $headers['x-litespeed-cache'] ) ) {
			$layers['LiteSpeed'] = $headers['x-litespeed-cache'];
		}

		$max_ttl = self::max_ttl( $headers );
		$hit     = self::is_hit( $headers );

		if ( isset( $headers['age'] ) && preg_match( '~^\d+$~', trim( $headers['age'] ) ) ) {
			$age = array(
				'seconds' => (int) trim( $headers['age'] ),
				'how'     => 'exact',
				'source'  => 'the Age header',
			);
		} elseif ( isset( $headers['x-cache-ctime'] ) && preg_match( '~^\d+$~', trim( $headers['x-cache-ctime'] ) ) && (int) trim( $headers['x-cache-ctime'] ) <= $now ) {
			// Checked after Age: a layer in front (Cloudflare) answered first, and its copy is the
			// one the visitor got. A ctime ahead of the clock is a clock problem, not an age.
			$age = array(
				'seconds' => $now - (int) trim( $headers['x-cache-ctime'] ),
				'how'     => 'exact',
				'source'  => 'the host cache (x-cache-ctime)',
			);
		} elseif ( false === $hit ) {
			$age = array(
				'seconds' => 0,
				'how'     => 'exact',
				'source'  => 'this request built it (MISS)',
			);
		} elseif ( true === $hit && null !== $cleared && ( null === $max_ttl || $now - $cleared <= $max_ttl ) ) {
			$age = array(
				'seconds' => max( 0, $now - $cleared ),
				'how'     => 'at most',
				'source'  => 'the last clear by this plugin, ' . gmdate( 'Y-m-d H:i:s', $cleared ) . ' UTC',
			);
		} else {
			$age = array(
				'seconds' => null,
				'how'     => 'unknown',
				'source'  => null === $max_ttl ? 'no layer said' : 'no layer said; it can be up to the lifetime below',
			);
		}

		// Each layer that said exactly how old its copy is, apart: the visitor's age above is the
		// front layer's, and a clear needs to know which layer held the old copy. Only layers that
		// answered are here — on most sites that is none, or one.
		$ages = array();

		if ( isset( $headers['age'] ) && preg_match( '~^\d+$~', trim( $headers['age'] ) ) ) {
			$ages[ isset( $headers['cf-cache-status'] ) ? 'Cloudflare' : 'cache in front' ] = (int) trim( $headers['age'] );
		}

		if ( isset( $headers['x-cache-ctime'] ) && preg_match( '~^\d+$~', trim( $headers['x-cache-ctime'] ) ) && (int) trim( $headers['x-cache-ctime'] ) <= $now ) {
			$ages['host cache'] = $now - (int) trim( $headers['x-cache-ctime'] );
		}

		$reading = array(
			'url'     => $url,
			'code'    => (int) $code,
			'ms'      => (int) $ms,
			'layers'  => $layers,
			'age'     => $age,
			'ages'    => $ages,
			'max_ttl' => $max_ttl,
			'hits'    => isset( $headers['x-cache'] ) && preg_match( '~HIT:\s*(\d+)~i', $headers['x-cache'], $m ) ? (int) $m[1] : null,
			// NitroPack writes this into every page it serves, and turns FRESH into STALE when it
			// serves an invalidated copy — the one sign of that a visitor's request carries.
			'nitropack_state' => preg_match( '~NITROPACK_STATE\s*=\s*[\'"](FRESH|STALE)[\'"]~', (string) $body, $n ) ? $n[1] : null,
		);

		if ( null !== $age['seconds'] && null !== $max_ttl ) {
			$reading['left'] = max( 0, $max_ttl - $age['seconds'] );
		}

		return $reading;
	}

	/**
	 * When NitroPack stored its copy of a page, read from the file it keeps, or null when the site
	 * has no NitroPack, NitroPack has no copy, or anything about it fails.
	 *
	 * Only file names are asked of NitroPack's SDK; the files are looked at with file_exists and
	 * filemtime. Not hasCache(): with invalidated copies on, it rewrites and renames the file. The
	 * SDK object may be shared with the rest of the request, so its invalidated switch is put back.
	 * The copy read is the desktop one, the one a request without a phone's user agent gets.
	 *
	 * A stale copy's time is when NitroPack last wrote that file — invalidated or converted — not
	 * when the page was built, so it is shown as stale since at least then.
	 *
	 * @param string $url URL on this site.
	 * @return array|null array( 'at' => unix time, 'state' => 'fresh'|'stale' ).
	 */
	public static function nitropack_copy( $url ) {
		if ( ! function_exists( 'get_nitropack_sdk' ) ) {
			return null;
		}

		try {
			$sdk = get_nitropack_sdk( null, null, $url );

			if ( ! is_object( $sdk ) || ! isset( $sdk->pageCache ) || ! is_object( $sdk->pageCache ) ) {
				return null;
			}

			$cache = $sdk->pageCache;
			$was   = method_exists( $cache, 'getUseInvalidated' ) ? (bool) $cache->getUseInvalidated() : false;

			try {
				$cache->useInvalidated( false );
				$fresh = array( $cache->getCachefilePath(), $cache->getCachefilePath( 'gz' ) );

				$cache->useInvalidated( true );
				$stale = array( $cache->getCachefilePath( 'stale' ), $cache->getCachefilePath( 'stale.gz' ), $cache->getCachefilePath(), $cache->getCachefilePath( 'gz' ) );
			} finally {
				$cache->useInvalidated( $was );
			}

			foreach ( array( 'fresh' => $fresh, 'stale' => $stale ) as $state => $paths ) {
				foreach ( $paths as $path ) {
					if ( is_string( $path ) && '' !== $path && @file_exists( $path ) ) {
						$at = @filemtime( $path );

						if ( false !== $at ) {
							return array(
								'at'    => (int) $at,
								'state' => $state,
							);
						}
					}
				}
			}
		} catch ( Throwable $e ) {
			return null;
		}

		return null;
	}

	/**
	 * Whether any layer said HIT (true), MISS (false), or nothing (null).
	 *
	 * @param array $headers Headers.
	 * @return bool|null
	 */
	private static function is_hit( array $headers ) {
		$said = null;

		foreach ( array( 'x-cache', 'cf-cache-status', 'x-nitro-cache', 'x-litespeed-cache' ) as $name ) {
			if ( ! isset( $headers[ $name ] ) ) {
				continue;
			}

			$value = strtoupper( $headers[ $name ] );

			// Any layer that served it from a copy makes it a hit for the visitor.
			if ( false !== strpos( $value, 'HIT' ) ) {
				return true;
			}

			if ( false !== strpos( $value, 'MISS' ) ) {
				$said = false;
			}
		}

		return $said;
	}

	/**
	 * The longest a layer said it keeps the page, in seconds.
	 *
	 * @param array $headers Headers.
	 * @return int|null
	 */
	private static function max_ttl( array $headers ) {
		if ( isset( $headers['x-cacheable'] ) && preg_match( '~YES:(\d+)~i', $headers['x-cacheable'], $m ) ) {
			return (int) $m[1];
		}

		foreach ( array( 'cache-control', 'x-orig-cache-control' ) as $name ) {
			if ( isset( $headers[ $name ] ) && preg_match( '~s-maxage=(\d+)|max-age=(\d+)~i', $headers[ $name ], $m ) ) {
				return (int) ( '' !== $m[1] ? $m[1] : $m[2] );
			}
		}

		return null;
	}

	/**
	 * When this plugin last cleared a URL: a per-URL job naming it, or a site-wide job, from the
	 * jobs still in the buffer. Only jobs whose host-cache step actually cleared count.
	 *
	 * @param string $url URL.
	 * @return int|null
	 */
	public static function last_cleared( $url ) {
		foreach ( ADVCM_Jobs::all() as $job ) {
			if ( 'all' !== $job['scope'] && ! in_array( $url, (array) $job['urls'], true ) ) {
				continue;
			}

			foreach ( (array) $job['steps'] as $step ) {
				if ( in_array( $step['status'], array( 'ok', 'partial' ), true ) && in_array( (int) $step['stage'], array( ADVCM_Stages::PAGE, ADVCM_Stages::OPTIMIZER, ADVCM_Stages::HOST, ADVCM_Stages::CDN ), true ) ) {
					return ! empty( $job['finished'] ) ? (int) $job['finished'] : (int) $job['created'];
				}
			}
		}

		return null;
	}

	/**
	 * A duration in words a person reads.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		$seconds = (int) $seconds;

		if ( $seconds < 120 ) {
			return $seconds . ' s';
		}

		if ( $seconds < 7200 ) {
			return (int) floor( $seconds / 60 ) . ' min ' . ( $seconds % 60 ) . ' s';
		}

		return (int) floor( $seconds / 3600 ) . ' h ' . (int) floor( ( $seconds % 3600 ) / 60 ) . ' min';
	}
}
