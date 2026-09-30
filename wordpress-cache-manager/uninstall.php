<?php
/**
 * Uninstall: remove what this plugin stored, and nothing else.
 *
 * Every option, transient and event this plugin writes is listed here, in the same change that
 * adds it.
 *
 * @package ADVCM
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_site_transient( 'advcm_release' );
delete_option( 'advcm_jobs' );
delete_option( 'advcm_layers' );
wp_unschedule_hook( 'advcm_continue' ); // every job id, not only events with no arguments.

// Per-user notices live for a minute; any left over expire on their own.
