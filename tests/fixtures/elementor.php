<?php
/**
 * Elementor's plugin instance and files manager, recorded.
 *
 * @package ADVCM
 */

namespace Elementor;

class Files_Manager_Stub {

	public function clear_cache() {
		vendor_called( 'Elementor files_manager->clear_cache' );
	}
}

class Plugin {

	public static $instance;

	public $files_manager;
}

Plugin::$instance                = new Plugin();
Plugin::$instance->files_manager = new Files_Manager_Stub();
