<?php
/**
 * Plugin Name:       CacheManager
 * Plugin URI:        https://github.com/advision-development/wordpress-cache-manager
 * Description:       Clears every cache layer a site has, in the order that keeps each one from re-caching stale content from the layer beneath it: object cache, builder CSS, page cache plugins, NitroPack, the host's page cache, a warm-up, and last the CDN. Detects what is installed at the moment it runs, skips what is not there and says so, and reports every step on its own.
 * Version:           0.3.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * Text Domain:       advcm
 * Update URI:        https://github.com/advision-development/wordpress-cache-manager
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADVCM_VERSION', '0.3.1' );
define( 'ADVCM_FILE', __FILE__ );
define( 'ADVCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'ADVCM_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVCM_SLUG', 'wordpress-cache-manager' );

/** Shared nonce action prefix. */
define( 'ADVCM_NONCE', 'advcm_purge' );

/*
 * Nothing below may take a site down. On a PHP older than the floor, or if anything throws while
 * the plugin registers itself — a file missing from a half-finished update, a parse error — the
 * plugin registers nothing, says so to administrators, and the site runs as if it were not
 * installed. WordPress checks "Requires PHP" on activation; this is for a server whose PHP was
 * changed afterwards.
 */
if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>CacheManager needs PHP 7.4 or newer and is not running.</p></div>';
			}
		}
	);

	return;
}

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

try {
	// Every hook goes through ADVCM_Safe (class-safe.php): a callback that throws costs a log
	// line, never a page.
	ADVCM_Capabilities::register();

	// Registered everywhere, not only in admin: a background job runs from WP-Cron, which is
	// neither admin nor front end.
	ADVCM_Runner::register();
	ADVCM_Controller::register();

	// Off until an administrator writes a rule; with none, a post save costs one option read.
	ADVCM_Auto::register();

	// The admin bar menu is drawn on the front end too, where "clear this page" has a page to name.
	ADVCM_Screen::register();

	// The plugin is not on wordpress.org, so without this the Plugins screen shows no update
	// however many releases are published. See class-updater.php: it is the only thing here that
	// hands WordPress a URL to download and run.
	ADVCM_Updater::register();
} catch ( Throwable $advcm_boot_error ) {
	error_log( '[advcm] boot: ' . get_class( $advcm_boot_error ) . ': ' . $advcm_boot_error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the plugin is not running and this is the only place that can say why.

	add_action(
		'admin_notices',
		function () use ( $advcm_boot_error ) {
			if ( current_user_can( 'activate_plugins' ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html( 'CacheManager could not start and is not running: ' . $advcm_boot_error->getMessage() ) . '</p></div>';
			}
		}
	);
}

/**
 * Deactivation: drop a continuation that has not run yet, and nothing else. The job history
 * stays until uninstall, so reactivating shows what was done.
 */
function advcm_deactivate() {
	try {
		// wp_unschedule_hook, not wp_clear_scheduled_hook: each continuation carries its job id
		// as an argument, and the second only clears events whose arguments match.
		wp_unschedule_hook( 'advcm_continue' );
		wp_unschedule_hook( 'advcm_auto_flush' );
	} catch ( Throwable $e ) {
		error_log( '[advcm] deactivate: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- deactivating must never fail.
	}
}
register_deactivation_hook( __FILE__, 'advcm_deactivate' );
