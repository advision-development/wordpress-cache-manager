<?php
/**
 * WP Rocket's page cache.
 *
 * Common across the sites this plugin serves, and the example the
 * pipeline was specified against: a site that had WP Rocket and removed it still has every
 * other layer cleared, and this stage reads `skipped: not installed`.
 *
 * Its minified files are a separate adapter at the asset stage, because they are a different
 * layer and belong earlier in the order.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Rocket page cache.
 */
class ADVCM_Adapter_Wp_Rocket extends ADVCM_Adapter {

	public function id() {
		return 'wp-rocket';
	}

	public function label() {
		return 'WP Rocket page cache';
	}

	public function stage() {
		return ADVCM_Stages::PAGE;
	}

	public function detect() {
		if ( ! function_exists( 'rocket_clean_domain' ) || ! function_exists( 'rocket_clean_files' ) ) {
			return $this->absent( 'not installed' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		if ( 'all' === $scope ) {
			rocket_clean_domain();

			return $this->ok( 'page cache cleared' );
		}

		rocket_clean_files( $urls );

		return $this->ok( sprintf( 'page cache cleared for %d URL(s)', count( $urls ) ) );
	}

	public function info() {
		return array( 'version' => defined( 'WP_ROCKET_VERSION' ) ? WP_ROCKET_VERSION : '' );
	}
}
