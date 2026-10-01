<?php
/**
 * WP Engine's page cache.
 *
 * Through `WpeCommon::purge_varnish_cache()`, the method WP Engine's own mu-plugin exposes and its
 * Caching screen calls. Three things about it, read from WP Engine's
 * `mu-plugins/wpengine-common/plugin.php` on 2026-09-30, shape this adapter:
 *
 * - **It purges at most three times per request** unless `$force` is passed, and then returns
 *   `false` without a word. A loop calling it once per post therefore stopped at the third URL and
 *   reported the rest as purged. So a per-URL clear is **one** call carrying every path.
 * - **The paths come through `wpe_purge_varnish_cache_paths`**, which receives `['.*']` for a full
 *   purge. Replacing that list with ours purges exactly those paths — archives, categories and
 *   query strings included, which a post id could never name. NitroPack's own WP Engine
 *   integration does the same, and this mirrors it, with one difference: the paths are regular
 *   expressions to WP Engine, so ours are escaped. NitroPack's are not, and a `?` in a query
 *   string is a regex operator.
 * - **`false` means it did not purge**: purging disabled (`WPE_DISABLE_CACHE_PURGING`), a
 *   snapshot environment, or the per-request limit. That is a failure, not an `ok`.
 *
 * Its memcached purge is **not** called here. `WpeCommon::purge_memcached()` is
 * `wp_cache_flush()` behind a check, which the object-cache stage has already done.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Engine page cache.
 */
class ADVCM_Adapter_Wp_Engine extends ADVCM_Adapter {

	public function id() {
		return 'wp-engine';
	}

	public function label() {
		return 'WP Engine page cache';
	}

	public function stage() {
		return ADVCM_Stages::HOST;
	}

	public function detect() {
		if ( ! class_exists( 'WpeCommon' ) ) {
			return $this->absent( 'not on WP Engine' );
		}

		if ( ! method_exists( 'WpeCommon', 'purge_varnish_cache' ) ) {
			return $this->absent( 'WpeCommon has no purge_varnish_cache()' );
		}

		if ( defined( 'WPE_DISABLE_CACHE_PURGING' ) && WPE_DISABLE_CACHE_PURGING ) {
			return $this->absent( 'purging is disabled on this install (WPE_DISABLE_CACHE_PURGING)' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		if ( 'all' === $scope ) {
			$this->expect( WpeCommon::purge_varnish_cache( null, true ) );

			return $this->ok( 'page cache purged' );
		}

		$paths   = self::paths( $urls );
		$handler = function ( $current ) use ( $paths ) {
			// Only a full purge is replaced. Anything else is a purge somebody else asked for in
			// this request, and it is theirs.
			return ( is_array( $current ) && array( '.*' ) === array_values( $current ) ) ? $paths : $current;
		};

		// A plain add_filter, not ADVCM_Safe: it has to be removed by the same callable straight
		// after, and the whole call already runs inside the runner's guard.
		add_filter( 'wpe_purge_varnish_cache_paths', $handler );

		try {
			$status = WpeCommon::purge_varnish_cache( null, true );
		} finally {
			// Removed whatever happened, or a later full purge in this request would be narrowed
			// to our paths.
			remove_filter( 'wpe_purge_varnish_cache_paths', $handler );
		}

		$this->expect( $status );

		return $this->ok( sprintf( 'page cache purged for %d path(s)', count( $paths ) ) );
	}

	/**
	 * The WP Engine path patterns for a list of URLs.
	 *
	 * Each is anchored and escaped: `/analysis/` purges `/analysis` and `/analysis/` with any
	 * query string, and nothing that merely starts with it. A URL with a query purges that query
	 * exactly.
	 *
	 * @param string[] $urls Absolute URLs on this site.
	 * @return string[]
	 */
	public static function paths( array $urls ) {
		$paths = array();

		foreach ( $urls as $url ) {
			$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
			$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
			$base  = preg_quote( rtrim( '' === $path ? '/' : $path, '/' ), '' );

			$paths[] = '' === $query
				? '^' . $base . '/?(\?.*)?$'
				: '^' . $base . '/?\?' . preg_quote( $query, '' ) . '$';
		}

		return array_values( array_unique( $paths ) );
	}

	public function info() {
		$config = get_option( 'wpe_cache_config', array() );
		$info   = array();

		if ( is_array( $config ) ) {
			if ( isset( $config['page_cache_expires_value'] ) ) {
				$info['page cache length'] = (string) $config['page_cache_expires_value'] . ' s';
			}

			// This setting raised the TTL to six months on pages not edited for four weeks, and
			// purging could not help while it was on. Worth one line on the screen.
			$info['Smarter Cache'] = ! empty( $config['smarter_cache_enabled'] ) ? 'on' : 'off';
		}

		$cleared = get_option( 'wpe_cache_last_cleared', '' );

		if ( '' !== $cleared ) {
			$info['last cleared (WP Engine)'] = (string) $cleared;
		}

		return $info;
	}

	/**
	 * WP Engine answers false when it did not purge.
	 *
	 * @param mixed $status What purge_varnish_cache() returned.
	 * @return void
	 */
	private function expect( $status ) {
		if ( false === $status ) {
			throw new RuntimeException( 'WP Engine did not purge (purging disabled, a snapshot, or its per-request limit)' );
		}
	}
}
