<?php
/**
 * Whether the site can reach itself, and what that changes.
 *
 * @package ADVCM
 */

class WP_Error {
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

$GLOBALS['heads']       = 0;
$GLOBALS['head_answer'] = 200;

function wp_remote_head( $url, $args = array() ) {
	$GLOBALS['heads']++;

	return 0 === $GLOBALS['head_answer'] ? new WP_Error() : array( 'response' => array( 'code' => $GLOBALS['head_answer'] ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

$GLOBALS['site_transients'] = array();

function get_site_transient( $key ) {
	return isset( $GLOBALS['site_transients'][ $key ] ) ? $GLOBALS['site_transients'][ $key ] : false;
}

function set_site_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['site_transients'][ $key ] = $value;
	$GLOBALS['ttl'][ $key ]             = $ttl;

	return true;
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'stages', 'modes' ) as $class ) {
	load_class( $class );
}

check( 'before any check the answer is unknown', null === ADVCM_Modes::loopback_ok() );
check( 'and asking for it makes no request: the admin bar asks on every page', 0 === $GLOBALS['heads'] );
check( 'unknown is not a reason to steer away from the background', ADVCM_Modes::BALANCED === ADVCM_Modes::recommended( 'all' ) );

$GLOBALS['head_answer'] = 401;
$known                  = ADVCM_Modes::check_loopback();

check( 'a site behind HTTP authentication cannot reach itself', false === $known['ok'] && 401 === $known['code'] );
check( 'and Fast is recommended there for the whole site', ADVCM_Modes::FAST === ADVCM_Modes::recommended( 'all' ) );
check( 'while the background modes stay available, because the screen moves them', 3 === count( ADVCM_Modes::available() ) );
check( 'the refusal is remembered for ten minutes, so fixing it shows up soon', 10 * MINUTE_IN_SECONDS === $GLOBALS['ttl'][ ADVCM_Modes::LOOPBACK ] );

ADVCM_Modes::check_loopback();

check( 'and is not asked again while remembered', 1 === $GLOBALS['heads'] );

$GLOBALS['site_transients'] = array();
$GLOBALS['head_answer']     = 0;

check( 'no answer at all is not reaching itself either', false === ADVCM_Modes::check_loopback()['ok'] );

$GLOBALS['site_transients'] = array();
$GLOBALS['head_answer']     = 301;

check( 'a redirect is an answer: the site is reachable', true === ADVCM_Modes::check_loopback()['ok'] );
check( 'and remembered for an hour', HOUR_IN_SECONDS === $GLOBALS['ttl'][ ADVCM_Modes::LOOPBACK ] );
check( 'and the whole site is Balanced again', ADVCM_Modes::BALANCED === ADVCM_Modes::recommended( 'all' ) );

finish();
