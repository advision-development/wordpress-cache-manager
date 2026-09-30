<?php
/**
 * Plugin Name:       Advision Cache Management
 * Plugin URI:        https://github.com/advision-development/wordpress-cache-manager
 * Description:       Clears every cache layer a site has, in the order that keeps each one from re-caching stale content from the layer beneath it: builder CSS, object cache, page cache plugins, the host's page cache, NitroPack, and last the CDN. Detects what is installed at the moment it runs, skips what is not there and says so, and reports every step on its own.
 * Version:           0.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * Text Domain:       advcm
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADVCM_VERSION', '0.1.0' );
define( 'ADVCM_FILE', __FILE__ );
define( 'ADVCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADVCM_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVCM_SLUG', 'wordpress-cache-manager' );

/** Shared nonce action prefix. */
define( 'ADVCM_NONCE', 'advcm_purge' );

/**
 * Autoload by class name.
 *
 * ADVCM_Stage_Runner -> includes/class-stage-runner.php
 */
spl_autoload_register(
	function ( $class ) {
		if ( 0 !== strpos( $class, 'ADVCM_' ) ) {
			return;
		}

		$name = strtolower( str_replace( '_', '-', substr( $class, strlen( 'ADVCM_' ) ) ) );
		$path = ADVCM_DIR . 'includes/class-' . $name . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

ADVCM_Capabilities::register();

// Registered everywhere, not only in admin: a job continued after a fatal runs from WP-Cron,
// which is neither admin nor front end.
ADVCM_Runner::register();
ADVCM_Controller::register();

// The admin bar menu is drawn on the front end too, where "clear this page" has a page to name.
ADVCM_Screen::register();

// The plugin is not on wordpress.org, so without this the Plugins screen shows no update
// however many releases are published. See class-updater.php: it is the only thing here that
// hands WordPress a URL to download and run.
ADVCM_Updater::register();

/**
 * Deactivation: drop a continuation that has not run yet, and nothing else. The job history
 * stays until uninstall, so reactivating shows what was done.
 */
function advcm_deactivate() {
	// wp_unschedule_hook, not wp_clear_scheduled_hook: each continuation carries its job id as
	// an argument, and the second only clears events whose arguments match the ones it is given.
	wp_unschedule_hook( ADVCM_Runner::CONTINUE_HOOK );
}
register_deactivation_hook( __FILE__, 'advcm_deactivate' );
