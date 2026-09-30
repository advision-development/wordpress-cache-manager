<?php
/**
 * WP Engine's page cache.
 *
 * Through `WpeCommon`, the class WP Engine's own mu-plugin exposes and its Caching screen calls:
 * `purge_varnish_cache()` for everything and `purge_varnish_cache( $post_id )` for one post
 * (read from WP Engine's `mu-plugins/wpengine-common/plugin.php`, 2026-09-30).
 *
 * Its memcached purge is **not** called here. `WpeCommon::purge_memcached()` is
 * `wp_cache_flush()` behind a check, which the object-cache stage has already done — calling it
 * again would flush a cache that has started refilling for no gain.
 *
 * Per URL it can only target single posts; an archive or a query-string URL is not something
 * `purge_varnish_cache()` takes. Those are reported as not reached rather than escalated to a
 * full purge nobody asked for.
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

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		if ( 'all' === $scope ) {
			WpeCommon::purge_varnish_cache();

			return $this->ok( 'page cache purged' );
		}

		$ids = ADVCM_Urls::post_ids( $urls );

		foreach ( $ids as $id ) {
			WpeCommon::purge_varnish_cache( $id );
		}

		if ( count( $ids ) < count( $urls ) ) {
			return $this->partial( count( $ids ), count( $urls ), 'WP Engine purges by post, and the rest are not single posts' );
		}

		return $this->ok( sprintf( 'page cache purged for %d post(s)', count( $ids ) ) );
	}

	public function info() {
		$config = get_option( 'wpe_cache_config', array() );
		$info   = array();

		if ( is_array( $config ) ) {
			if ( isset( $config['page_cache_expires_value'] ) ) {
				$info['page cache length'] = (string) $config['page_cache_expires_value'] . ' s';
			}

			// This setting raised the TTL to six months on pages not edited for four
			// weeks, and purging could not help while it was on. Worth one line on the screen.
			$info['Smarter Cache'] = ! empty( $config['smarter_cache_enabled'] ) ? 'on' : 'off';
		}

		$cleared = get_option( 'wpe_cache_last_cleared', '' );

		if ( '' !== $cleared ) {
			$info['last cleared (WP Engine)'] = (string) $cleared;
		}

		return $info;
	}
}
