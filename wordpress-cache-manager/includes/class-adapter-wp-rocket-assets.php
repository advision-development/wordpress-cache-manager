<?php
/**
 * WP Rocket's minified and combined files.
 *
 * Cleared at the asset stage, before any page cache, so a page stored afterwards references
 * files that exist. Site-wide only: the files are shared between pages.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP Rocket asset files.
 */
class ADVCM_Adapter_Wp_Rocket_Assets extends ADVCM_Adapter {

	public function id() {
		return 'wp-rocket-assets';
	}

	public function label() {
		return 'WP Rocket minified files';
	}

	public function stage() {
		return ADVCM_Stages::ASSETS;
	}

	public function scopes() {
		return array( 'all' );
	}

	public function detect() {
		if ( ! function_exists( 'rocket_clean_minify' ) ) {
			return $this->absent( 'not installed' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		rocket_clean_minify();

		return $this->ok( 'minified files cleared' );
	}
}
