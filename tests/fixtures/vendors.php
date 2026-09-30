<?php
/**
 * Stand-ins for the vendors, loaded only after the suite has checked every adapter reads as
 * absent without them. Every call is recorded so a suite can assert what was — and was not —
 * called.
 *
 * @package ADVCM
 */

$GLOBALS['vendor_calls'] = array();

/**
 * Record a vendor call.
 *
 * @param string $name What was called.
 * @param array  $args With what.
 * @return void
 */
function vendor_called( $name, array $args = array() ) {
	$GLOBALS['vendor_calls'][] = array( $name, $args );
}

// ------------------------------------------------------------------------- NitroPack

$GLOBALS['nitropack_connected'] = true;
$GLOBALS['nitropack_answer']    = true;

function get_nitropack_sdk() {
	return $GLOBALS['nitropack_connected'] ? new stdClass() : null;
}

function nitropack_sdk_purge( $url = null, $tag = null, $reason = null ) {
	vendor_called( 'nitropack_sdk_purge', array( $url ) );

	return is_callable( $GLOBALS['nitropack_answer'] ) ? call_user_func( $GLOBALS['nitropack_answer'], $url ) : $GLOBALS['nitropack_answer'];
}

function nitropack_sdk_invalidate( $url = null, $tag = null, $reason = null ) {
	vendor_called( 'nitropack_sdk_invalidate', array( $url ) );

	return $GLOBALS['nitropack_answer'];
}

// These also invalidate the home page and every archive on each call. Defined so that a
// regression calling them is recorded rather than a fatal the suite would misread.
function nitropack_purge( $url = null, $tag = null, $reason = null ) {
	vendor_called( 'nitropack_purge', array( $url ) );
}

function nitropack_invalidate( $url = null, $tag = null, $reason = null ) {
	vendor_called( 'nitropack_invalidate', array( $url ) );
}

// ------------------------------------------------------------------------- WP Engine

class WpeCommon {

	public static function purge_varnish_cache( $post_id = null, $force = false ) {
		vendor_called( 'WpeCommon::purge_varnish_cache', array( $post_id ) );
	}

	public static function purge_memcached() {
		vendor_called( 'WpeCommon::purge_memcached' );
	}
}

// ------------------------------------------------------------------------- WP Rocket

function rocket_clean_domain() {
	vendor_called( 'rocket_clean_domain' );
}

function rocket_clean_files( $urls ) {
	vendor_called( 'rocket_clean_files', array( $urls ) );
}

function rocket_clean_minify() {
	vendor_called( 'rocket_clean_minify' );
}

// ------------------------------------------------------------------------- object cache

$GLOBALS['ext_object_cache'] = true;

function wp_using_ext_object_cache() {
	return $GLOBALS['ext_object_cache'];
}

function wp_cache_flush() {
	vendor_called( 'wp_cache_flush' );

	return true;
}

function clean_post_cache( $id ) {
	vendor_called( 'clean_post_cache', array( $id ) );
}

// ------------------------------------------------------------------------- Bricks

define( 'BRICKS_VERSION', '2.2' );

require __DIR__ . '/bricks.php';
require __DIR__ . '/elementor.php';
