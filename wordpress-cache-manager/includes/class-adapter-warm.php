<?php
/**
 * Warm-up: request the cleared pages once, so the next visitor is not the one who waits.
 *
 * Every URL comes from this site and goes to this site: the URLs a per-URL job was given (already
 * filtered to this origin), or for a site-wide job the home page and the most recently updated
 * public posts and pages, which are the ones most likely to have just changed. Nothing here
 * fetches an address it was handed from outside the site.
 *
 * Each page is requested twice, as a desktop and a mobile browser, because the page caches in
 * front of these sites keep separate copies per device and warming one leaves the other cold.
 *
 * The user agent names this plugin — `AdvisionCacheWarm/<version>` — so a request log, a
 * firewall or a security scanner can tell these requests apart from a crawler.
 *
 * Bounded: a time budget per run, batches of a few requests with a pause between them, and a run
 * that reaches its budget hands back a cursor so the runner continues in a fresh request, rather
 * than holding one PHP process until the host kills it.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warm-up.
 */
class ADVCM_Adapter_Warm extends ADVCM_Adapter {

	/** Seconds a single run may spend requesting pages. */
	const BUDGET = 40;

	/** Seconds one request may take. */
	const TIMEOUT = 15;

	public function id() {
		return 'warm';
	}

	public function label() {
		return 'Warm-up';
	}

	public function stage() {
		return ADVCM_Stages::WARM;
	}

	public function detect() {
		return $this->present();
	}

	/**
	 * The user agents a page is requested as.
	 *
	 * @return array
	 */
	public static function agents() {
		$tag = 'AdvisionCacheWarm/' . ( defined( 'ADVCM_VERSION' ) ? ADVCM_VERSION : '0' );

		return array(
			'desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36 ' . $tag,
			'mobile'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1 ' . $tag,
		);
	}

	/**
	 * Request the pages, picking up where the last run stopped.
	 *
	 * A large warm-up does not fit one request, so this returns `continue` with a cursor when it
	 * reaches its budget, and the runner calls it again in a fresh one. The list of pages is
	 * fixed on the first call and carried, so a post edited in between does not shift what the
	 * cursor points at.
	 *
	 * @param string $scope   `all` or `urls`.
	 * @param array  $urls    URLs for a per-URL job.
	 * @param array  $options Mode options, plus `carry` from the previous call.
	 * @return array
	 */
	public function clear( $scope, array $urls, array $options ) {
		$carry   = isset( $options['carry'] ) && is_array( $options['carry'] ) ? $options['carry'] : array();
		$targets = isset( $carry['targets'] ) && is_array( $carry['targets'] )
			? $carry['targets']
			: ( 'urls' === $scope ? $urls : self::site_targets( isset( $options['warm_site'] ) ? (int) $options['warm_site'] : 0 ) );

		if ( empty( $targets ) ) {
			return $this->not_applicable( 'nothing to warm in this mode' );
		}

		$requests = array();

		foreach ( $targets as $url ) {
			foreach ( self::agents() as $agent ) {
				$requests[] = array( $url, $agent );
			}
		}

		$batch  = max( 1, isset( $options['warm_batch'] ) ? (int) $options['warm_batch'] : 4 );
		$delay  = isset( $options['warm_delay'] ) ? max( 0, (int) $options['warm_delay'] ) : 0;
		$cursor = isset( $carry['cursor'] ) ? max( 0, (int) $carry['cursor'] ) : 0;
		$bad    = isset( $carry['bad'] ) && is_array( $carry['bad'] ) ? $carry['bad'] : array();
		$total  = count( $requests );

		// `warm_budget` exists for the test suite, which needs to see a cursor handed back without
		// waiting forty seconds. Nothing in the plugin sets it.
		$budget  = isset( $options['warm_budget'] ) ? (float) $options['warm_budget'] : (float) self::BUDGET;
		$started = microtime( true );
		$first   = true;

		while ( $cursor < $total ) {
			if ( ! $first && microtime( true ) - $started >= $budget ) {
				return array(
					'status'  => 'continue',
					'message' => sprintf( '%d of %d requested so far', $cursor, $total ),
					'carry'   => array( 'targets' => $targets, 'cursor' => $cursor, 'bad' => $bad ),
				);
			}

			if ( ! $first && $delay > 0 ) {
				usleep( $delay * 1000 );
			}

			$first = false;
			$chunk = array_slice( $requests, $cursor, $batch );

			foreach ( self::fetch( $chunk ) as $i => $code ) {
				if ( $code < 200 || $code >= 400 ) {
					$bad[ $chunk[ $i ][0] ] = $code;
				}
			}

			$cursor += count( $chunk );
		}

		if ( ! empty( $bad ) ) {
			$named = array();

			foreach ( $bad as $url => $code ) {
				$named[] = $url . ' (' . ( $code ? $code : 'no answer' ) . ')';
			}

			return $this->partial( count( $targets ) - count( $bad ), count( $targets ), 'did not answer with a page: ' . implode( ', ', array_slice( $named, 0, 5 ) ) );
		}

		return $this->ok( sprintf( 'requested %d page(s) as desktop and mobile', count( $targets ) ) );
	}

	/**
	 * The home page and the most recently updated public posts and pages.
	 *
	 * Read from the database rather than a sitemap: every site has posts, not every site has the
	 * same sitemap, and an HTTP fetch of one is a request whose answer this would have to trust.
	 *
	 * @param int $count How many after the home page.
	 * @return string[]
	 */
	public static function site_targets( $count ) {
		$urls = array( trailingslashit( home_url() ) );

		if ( $count > 0 && function_exists( 'get_posts' ) ) {
			$ids = get_posts(
				array(
					'post_type'        => array( 'post', 'page' ),
					'post_status'      => 'publish',
					'has_password'     => false,
					'orderby'          => 'modified',
					'order'            => 'DESC',
					'posts_per_page'   => $count,
					'fields'           => 'ids',
					'no_found_rows'    => true,
					'suppress_filters' => true,
				)
			);

			foreach ( (array) $ids as $id ) {
				$link = get_permalink( $id );

				if ( is_string( $link ) && '' !== $link ) {
					$urls[] = $link;
				}
			}
		}

		// Through the same filter as anything a person types, so nothing off this origin is ever
		// requested, whatever a permalink filter on the site returned.
		$parsed = ADVCM_Urls::parse( $urls, home_url() );

		return $parsed['accepted'];
	}

	/**
	 * Request a batch, in parallel where WordPress's bundled HTTP library allows it.
	 *
	 * @param array $chunk array( url, user agent ) pairs.
	 * @return int[] Status code per request, 0 when there was no answer.
	 */
	private static function fetch( array $chunk ) {
		$parallel = self::parallel( $chunk );

		if ( null !== $parallel ) {
			return $parallel;
		}

		$codes = array();

		foreach ( $chunk as $pair ) {
			$response = wp_remote_get(
				$pair[0],
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 0,
					'user-agent'  => $pair[1],
				)
			);

			$codes[] = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		}

		return $codes;
	}

	/**
	 * The same batch through Requests::request_multiple, or null when it is not available.
	 *
	 * WordPress 6.2 namespaced the library; older versions carry the global class.
	 *
	 * @param array $chunk Pairs.
	 * @return int[]|null
	 */
	private static function parallel( array $chunk ) {
		$class = class_exists( '\WpOrg\Requests\Requests' ) ? '\WpOrg\Requests\Requests' : ( class_exists( 'Requests' ) ? 'Requests' : '' );

		if ( '' === $class || ! method_exists( $class, 'request_multiple' ) ) {
			return null;
		}

		$requests = array();

		foreach ( $chunk as $pair ) {
			$requests[] = array(
				'url'     => $pair[0],
				// No Cache-Control: no-cache. The point is for the caches in front to store
				// what this request renders, and some of them skip storing a no-cache request.
				'headers' => array( 'User-Agent' => $pair[1] ),
				'type'    => 'GET',
			);
		}

		$responses = call_user_func(
			array( $class, 'request_multiple' ),
			$requests,
			array(
				'timeout'          => self::TIMEOUT,
				'follow_redirects' => false,
			)
		);

		$codes = array();

		foreach ( array_keys( $requests ) as $i ) {
			$r       = isset( $responses[ $i ] ) ? $responses[ $i ] : null;
			$codes[] = ( is_object( $r ) && isset( $r->status_code ) ) ? (int) $r->status_code : 0;
		}

		return $codes;
	}
}
