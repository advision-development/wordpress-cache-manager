<?php
/**
 * Options, transients and events that remember, for suites where what was stored matters.
 *
 * Required before bootstrap.php, so these win over wp-stubs.php's forgetful versions — the
 * `function_exists` guards there exist for exactly this.
 *
 * @package ADVCM
 */

$GLOBALS['options']   = array();
$GLOBALS['scheduled'] = array();
$GLOBALS['hooks']     = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ] = $value;

	return true;
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['hooks'][] = $hook;
}

function wp_next_scheduled( $hook, $args = array() ) {
	$key = $hook . wp_json_encode( $args );

	return isset( $GLOBALS['scheduled'][ $key ] ) ? $GLOBALS['scheduled'][ $key ] : false;
}

function wp_schedule_single_event( $time, $hook, $args = array() ) {
	$GLOBALS['scheduled'][ $hook . wp_json_encode( $args ) ] = $time;

	return true;
}
