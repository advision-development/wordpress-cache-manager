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

// Uninstalling must never fail halfway and leave an error in front of the person removing it.
try {
	delete_site_transient( 'advcm_release' );
	delete_site_transient( 'advcm_loopback' );
	delete_option( 'advcm_jobs' );
	delete_option( 'advcm_layers' );
	delete_option( 'advcm_last_error' );
	wp_unschedule_hook( 'advcm_continue' ); // every job id, not only events with no arguments.

	// A job's lock is a row named after the job. Normally released, but a run killed hard enough
	// can leave one behind; the prefix is this plugin's own, so the wildcard reaches nothing else.
	global $wpdb;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'advcm_lock_' ) . '%' ) );
} catch ( Throwable $e ) {
	error_log( '[advcm] uninstall: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- best effort.
}

// Per-user notices live for a minute; any left over expire on their own.
