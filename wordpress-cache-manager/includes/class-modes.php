<?php
/**
 * How a job runs: all at once, or in the background with pauses and a warm-up.
 *
 * Three presets, and the numbers live here rather than on a screen. A form where each person
 * picks their own seconds is one where nobody knows what a given clear did, and the tickets this
 * plugin exists to end come back as "it was slow after somebody purged".
 *
 * **Where the pauses go is the design.** Never from builder CSS through the host cache: the moment
 * Elementor's files are deleted, a page still held by a page cache points at CSS that is gone, so
 * everything that stores pages follows straight away. The pauses come **after the object cache**,
 * so memcached refills from real traffic while the page caches are still serving and the database
 * does not take every render at once when they go cold, and **before the CDN**, so NitroPack and
 * the host are serving new copies before the edge lets go. A pause after NitroPack would buy
 * nothing: it rebuilds in its own cloud and tells the site when each page is ready.
 *
 * Background modes need WP-Cron, which is WordPress core and on every site — but a site can turn
 * it off with DISABLE_WP_CRON and never replace it. `available()` says so, and only Fast is then
 * offered: it runs inside the request and needs nothing.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The presets.
 */
final class ADVCM_Modes {

	/** All at once, in the request. */
	const FAST = 'fast';

	/** Background, short pauses, a modest warm-up. */
	const BALANCED = 'balanced';

	/** Background, long pauses, a large warm-up in small batches. */
	const CAREFUL = 'careful';

	/**
	 * Every preset.
	 *
	 * - `inline`        run in the request that pressed the button
	 * - `pause_before`  stage => seconds to wait before that stage starts
	 * - `warm_site`     how many pages a site-wide clear warms, after the home page
	 * - `warm_batch`    how many requests are in flight at once
	 * - `warm_delay`    milliseconds between batches, so a warm-up does not read as a burst to a
	 *                   rate limit in front of the site (BMR's own Cloudflare rule has blocked
	 *                   its own renderer before)
	 *
	 * Filterable as a whole through `advcm_modes`, for the one site that needs other numbers; an
	 * entry that comes back malformed falls back to the shipped one.
	 *
	 * @return array
	 */
	public static function all() {
		$modes = array(
			self::FAST     => array(
				'label'        => __( 'Fast', 'advcm' ),
				'inline'       => true,
				'pause_before' => array(),
				'warm_site'    => 0,
				'warm_batch'   => 4,
				'warm_delay'   => 0,
				'summary'      => __( 'Everything at once, while you wait: seconds. Right for a few pages, which it also requests once so the next visitor does not wait. For the whole site every cache goes cold at the same moment, so the first visitor to each page waits for it to be built and the server takes that load all at once.', 'advcm' ),
			),
			self::BALANCED => array(
				'label'        => __( 'Balanced', 'advcm' ),
				'inline'       => false,
				'pause_before' => array( ADVCM_Stages::BUILDER => 120, ADVCM_Stages::CDN => 60 ),
				'warm_site'    => 20,
				'warm_batch'   => 4,
				'warm_delay'   => 500,
				'summary'      => __( 'In the background, about 4 minutes; you can leave this page. The object cache clears first, then a 2-minute pause while it refills from visitors. Then builder CSS, the page caches, NitroPack and the host cache clear together, the home page and the 20 most recently updated pages are requested once, and after 1 more minute the CDN.', 'advcm' ),
			),
			self::CAREFUL  => array(
				'label'        => __( 'Careful', 'advcm' ),
				'inline'       => false,
				'pause_before' => array( ADVCM_Stages::BUILDER => 300, ADVCM_Stages::CDN => 180 ),
				'warm_site'    => 100,
				'warm_batch'   => 5,
				'warm_delay'   => 1000,
				'summary'      => __( 'In the background, about 10 minutes; you can leave this page. Like Balanced, with 5 minutes for the object cache to refill, the home page and the 100 most recently updated pages warmed five at a time with a pause between batches, and 3 minutes before the CDN. For large sites or busy hours.', 'advcm' ),
			),
		);

		$filtered = apply_filters( 'advcm_modes', $modes );

		if ( ! is_array( $filtered ) ) {
			return $modes;
		}

		foreach ( $modes as $id => $shipped ) {
			$candidate = isset( $filtered[ $id ] ) ? $filtered[ $id ] : null;

			$modes[ $id ] = self::valid( $candidate ) ? array_merge( $shipped, $candidate ) : $shipped;
		}

		return $modes;
	}

	/**
	 * One preset, or Fast when the id is unknown.
	 *
	 * @param string $id Mode id.
	 * @return array
	 */
	public static function get( $id ) {
		$modes = self::all();

		return isset( $modes[ $id ] ) ? $modes[ $id ] + array( 'id' => $id ) : $modes[ self::FAST ] + array( 'id' => self::FAST );
	}

	/**
	 * The mode a scope starts with selected.
	 *
	 * @param string $scope `all` or `urls`.
	 * @return string
	 */
	public static function recommended( $scope ) {
		// A site that cannot wake its own cron would leave a background clear waiting, so Fast
		// is what it is offered first. The background modes stay available: the status screen
		// moves them while it is open.
		if ( 'urls' === $scope || ! self::background_available() || false === self::loopback_ok() ) {
			return self::FAST;
		}

		return self::BALANCED;
	}

	/**
	 * Whether WP-Cron will run what is scheduled.
	 *
	 * DISABLE_WP_CRON means WordPress will not fire events on page loads. A site that sets it and
	 * calls wp-cron.php from a real cron runs them anyway, which this cannot see — so the answer
	 * here is the cautious one, and `advcm_background_available` lets such a site say so.
	 *
	 * @return bool
	 */
	public static function background_available() {
		$available = ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

		return (bool) apply_filters( 'advcm_background_available', $available );
	}

	/** Where the loopback answer is cached. */
	const LOOPBACK = 'advcm_loopback';

	/**
	 * Whether this site can reach itself, as WordPress needs to for its cron to wake.
	 *
	 * WordPress fires scheduled events by requesting its own wp-cron.php. Where that request is
	 * refused — staging behind HTTP authentication, a firewall blocking the server's own address —
	 * no event ever fires, page loads or not. Measured on a staging install: a background clear
	 * did not move for five minutes. So the answer decides what is recommended.
	 *
	 * Reads the cached answer only. `check_loopback()` refreshes it, and only the status screen
	 * calls that: this is also asked while building the admin bar, on every page, where an HTTP
	 * request would be a cost for nothing.
	 *
	 * @return bool|null True or false when known, null when not checked yet.
	 */
	public static function loopback_ok() {
		$known = get_site_transient( self::LOOPBACK );

		return is_array( $known ) && isset( $known['ok'] ) ? (bool) $known['ok'] : null;
	}

	/**
	 * Ask the site for its own home page and remember whether it answered. An hour when it did,
	 * ten minutes when it did not, so fixing the cause shows up soon.
	 *
	 * @return array array( ok, code )
	 */
	public static function check_loopback() {
		$known = get_site_transient( self::LOOPBACK );

		if ( is_array( $known ) && isset( $known['ok'] ) ) {
			return $known;
		}

		$response = wp_remote_head(
			home_url( '/' ),
			array(
				'timeout'     => 5,
				'redirection' => 0,
				// The same choice WordPress makes for its own cron request.
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		$code  = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$known = array(
			'ok'   => $code >= 200 && $code < 400,
			'code' => $code,
		);

		set_site_transient( self::LOOPBACK, $known, $known['ok'] ? HOUR_IN_SECONDS : 10 * MINUTE_IN_SECONDS );

		return $known;
	}

	/**
	 * The modes a request may use on this site, by id.
	 *
	 * @return string[]
	 */
	public static function available() {
		return self::background_available() ? array( self::FAST, self::BALANCED, self::CAREFUL ) : array( self::FAST );
	}

	/**
	 * Whether a filtered preset can be used.
	 *
	 * @param mixed $mode Candidate.
	 * @return bool
	 */
	private static function valid( $mode ) {
		if ( ! is_array( $mode ) ) {
			return false;
		}

		if ( isset( $mode['pause_before'] ) ) {
			if ( ! is_array( $mode['pause_before'] ) ) {
				return false;
			}

			foreach ( $mode['pause_before'] as $stage => $seconds ) {
				// From builder CSS through the host cache is the one place a pause does harm.
				if ( ! is_int( $seconds ) || $seconds < 0 || $seconds > 3600 || in_array( (int) $stage, ADVCM_Stages::no_pause_before(), true ) ) {
					return false;
				}
			}
		}

		foreach ( array( 'warm_site' => 500, 'warm_batch' => 10, 'warm_delay' => 5000 ) as $key => $max ) {
			if ( isset( $mode[ $key ] ) && ( ! is_int( $mode[ $key ] ) || $mode[ $key ] < 0 || $mode[ $key ] > $max ) ) {
				return false;
			}
		}

		return true;
	}
}
