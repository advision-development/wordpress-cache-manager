<?php
/**
 * Elementor's generated CSS.
 *
 * Clearing is the same operation as Elementor's own Tools → "Regenerate CSS & Data":
 * `files_manager->clear_cache()`, read from Elementor 4.0.2. It deletes
 * the files in `uploads/elementor/css/`, the per-post CSS meta, the element cache and the assets
 * data, and Elementor rebuilds each page's file **on that page's next view** — a page is never
 * served pointing at a file that will not be built, which is why this one is safe to run where
 * Bricks' equivalent is not (see class-adapter-bricks.php).
 *
 * The cost is that the first view of every Elementor page is a render. It is also why this stage
 * runs before every page cache: a render served while the files were being deleted can carry a
 * link to a file that is gone, and the page-cache stages after this one throw that copy away.
 *
 * **Site-wide only.** A per-URL request skips it, because the tool is global and a per-post
 * variant of ours would be a second implementation of Elementor's cache that nobody else tests.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor CSS.
 */
class ADVCM_Adapter_Elementor extends ADVCM_Adapter {

	public function id() {
		return 'elementor';
	}

	public function label() {
		return 'Elementor CSS';
	}

	public function stage() {
		return ADVCM_Stages::BUILDER;
	}

	public function scopes() {
		return array( 'all' );
	}

	public function detect() {
		if ( ! class_exists( '\Elementor\Plugin' ) ) {
			return $this->absent( 'not installed' );
		}

		$plugin = \Elementor\Plugin::$instance;

		if ( ! is_object( $plugin ) || ! isset( $plugin->files_manager ) || ! is_object( $plugin->files_manager ) ) {
			return $this->absent( 'not loaded' );
		}

		if ( ! method_exists( $plugin->files_manager, 'clear_cache' ) ) {
			return $this->absent( 'this Elementor version has no clear_cache()' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();

		return $this->ok( 'generated CSS cleared; each page rebuilds its file on its next view' );
	}

	public function info() {
		return array(
			'version'      => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
			'print method' => (string) get_option( 'elementor_css_print_method', '' ),
		);
	}
}
