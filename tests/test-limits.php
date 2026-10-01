<?php
/**
 * The limits a press runs into: one whole-site clear at a time and two minutes apart, ten page
 * clears a minute per person — and neither depending on the job history, which is a buffer a
 * burst of small clears can empty.
 *
 * @package ADVCM
 */

$GLOBALS['transients'] = array();

function get_transient( $key ) {
	return isset( $GLOBALS['transients'][ $key ] ) ? $GLOBALS['transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['transients'][ $key ] = $value;

	return true;
}

$GLOBALS['me'] = 7;

function get_current_user_id() {
	return $GLOBALS['me'];
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'controller' ) as $class ) {
	load_class( $class );
}

$site = array( 'scope' => 'all' );
$page = array( 'scope' => 'urls' );

check( 'the first whole-site clear goes', '' === ADVCM_Controller::busy( $site ) );
check( 'a second within two minutes does not', '' !== ADVCM_Controller::busy( $site ) );

// Thirty small clears in between push every earlier job out of the history buffer.
$GLOBALS['options'][ ADVCM_Jobs::OPTION ] = array();

check( 'and emptying the job history does not reset it, because the time lives in its own row', '' !== ADVCM_Controller::busy( $site ) );

$GLOBALS['options'][ ADVCM_Controller::SITE_WIDE_AT ] = time() - ADVCM_Controller::SITE_WIDE_GAP - 1;

check( 'after the gap it goes again', '' === ADVCM_Controller::busy( $site ) );

$GLOBALS['options'][ ADVCM_Controller::SITE_WIDE_AT ] = time() - ADVCM_Controller::SITE_WIDE_GAP - 1;
$GLOBALS['options'][ ADVCM_Jobs::OPTION ]             = array( 'r' => array( 'id' => 'r', 'scope' => 'all', 'state' => 'running', 'created' => time(), 'next_at' => time() + 60, 'urls' => array(), 'steps' => array() ) );

check( 'while one is running, another is refused whatever the time', false !== strpos( ADVCM_Controller::busy( $site ), 'still running' ) );
check( 'and the refusal does not take the next start\'s slot', time() - (int) $GLOBALS['options'][ ADVCM_Controller::SITE_WIDE_AT ] > ADVCM_Controller::SITE_WIDE_GAP );

$GLOBALS['options'][ ADVCM_Jobs::OPTION ] = array();

for ( $i = 0; $i < ADVCM_Controller::URL_JOBS_PER_MINUTE; $i++ ) {
	$ok = '' === ADVCM_Controller::busy( $page );
}

check( 'ten page clears in a minute go', $ok );
check( 'the eleventh does not', '' !== ADVCM_Controller::busy( $page ) );

$GLOBALS['me'] = 8;

check( 'and the limit is per person', '' === ADVCM_Controller::busy( $page ) );

finish();
