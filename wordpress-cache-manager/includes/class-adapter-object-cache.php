<?php
/**
 * The persistent object cache.
 *
 * Site-wide it is `wp_cache_flush()`. Per URL it cleans the post cache of the posts those URLs
 * resolve to, because an object cache is keyed by object, not by address — flushing the whole of
 * memcached to refresh one page would cost every other page its warm cache.
 *
 * Only when a persistent backend is in use. Without one the object cache lives for one request
 * and there is nothing to clear, so the stage is `skipped: no persistent object cache` rather
 * than an `ok` that did nothing.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Object cache.
 */
class ADVCM_Adapter_Object_Cache extends ADVCM_Adapter {

	public function id() {
		return 'object-cache';
	}

	public function label() {
		return 'Object cache';
	}

	public function stage() {
		return ADVCM_Stages::OBJECT;
	}

	public function detect() {
		if ( ! function_exists( 'wp_using_ext_object_cache' ) || ! wp_using_ext_object_cache() ) {
			return $this->absent( 'no persistent object cache' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		if ( 'all' === $scope ) {
			if ( ! wp_cache_flush() ) {
				throw new RuntimeException( 'wp_cache_flush() reported failure' );
			}

			return $this->ok( 'object cache flushed' );
		}

		$ids = ADVCM_Urls::post_ids( $urls );

		foreach ( $ids as $id ) {
			clean_post_cache( $id );
		}

		if ( count( $ids ) < count( $urls ) ) {
			return $this->partial( count( $ids ), count( $urls ), 'the rest are not single posts, and the object cache is keyed by post' );
		}

		return $this->ok( sprintf( 'post cache cleaned for %d post(s)', count( $ids ) ) );
	}

	public function info() {
		$dropin = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/object-cache.php' : '';

		if ( '' === $dropin || ! is_readable( $dropin ) || ! function_exists( 'get_plugin_data' ) ) {
			return array();
		}

		$data = get_plugin_data( $dropin, false, false );

		return array( 'drop-in' => trim( $data['Name'] . ' ' . $data['Version'] ) );
	}
}
