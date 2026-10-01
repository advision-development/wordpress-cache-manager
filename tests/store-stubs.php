<?php
/**
 * Options, transients, hooks and events that remember, for suites where what was stored matters.
 *
 * Required before bootstrap.php, so these win over wp-stubs.php's forgetful versions. Each is
 * guarded too, so a suite that needs a richer one — real filter callbacks, a cron that can be
 * told what is due — declares its own before requiring this file.
 *
 * @package ADVCM
 */

$GLOBALS['options']   = isset( $GLOBALS['options'] ) ? $GLOBALS['options'] : array();
$GLOBALS['scheduled'] = isset( $GLOBALS['scheduled'] ) ? $GLOBALS['scheduled'] : array();
$GLOBALS['hooks']     = isset( $GLOBALS['hooks'] ) ? $GLOBALS['hooks'] : array();
$GLOBALS['spawned']   = 0;

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'add_option' ) ) {
	// Inserts only when absent, like the real one: the job lock relies on exactly that.
	function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
		if ( array_key_exists( $name, $GLOBALS['options'] ) ) {
			return false;
		}

		$GLOBALS['options'][ $name ] = $value;

		return true;
	}
}

if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $name ) {
		unset( $GLOBALS['options'][ $name ] );

		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hooks'][] = $hook;
		$GLOBALS['callbacks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
		$GLOBALS['hooks'][] = $hook;
		$GLOBALS['callbacks'][ $hook ][] = $callback;
	}
}

if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook, $args = array() ) {
		$key = $hook . wp_json_encode( $args );

		return isset( $GLOBALS['scheduled'][ $key ] ) ? $GLOBALS['scheduled'][ $key ] : false;
	}
}

if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $time, $hook, $args = array() ) {
		$GLOBALS['scheduled'][ $hook . wp_json_encode( $args ) ] = $time;

		return true;
	}
}

if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook, $args = array() ) {
		unset( $GLOBALS['scheduled'][ $hook . wp_json_encode( $args ) ] );

		return 1;
	}
}

if ( ! function_exists( 'spawn_cron' ) ) {
	function spawn_cron() {
		$GLOBALS['spawned']++;
	}
}

/**
 * The event WP-Cron would fire next for a job, as the suite's stand-in for WP-Cron itself.
 *
 * @param string $hook Hook.
 * @param string $id   Job id.
 * @return int|false
 */
function scheduled_at( $hook, $id ) {
	return wp_next_scheduled( $hook, array( $id ) );
}

/**
 * Fire a job's scheduled event, the way WP-Cron does: take it off the schedule, then run it.
 *
 * @param string $hook Hook.
 * @param string $id   Job id.
 * @return bool Whether one was due.
 */
function fire( $hook, $id ) {
	if ( false === wp_next_scheduled( $hook, array( $id ) ) ) {
		return false;
	}

	wp_clear_scheduled_hook( $hook, array( $id ) );
	ADVCM_Runner::run( $id );

	return true;
}
