<?php
/**
 * The buffer: how many jobs WordPress keeps, what survives the trim, and the layer summary.
 *
 * The point of this class is that it stays small. Each assertion on a count is a bound on the
 * size of one row in wp_options.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'jobs' );

/**
 * A finished job.
 *
 * @param string $id   Id.
 * @param bool   $sent Whether Hawkeye has it.
 * @return array
 */
function job( $id, $sent ) {
	return array( 'id' => $id, 'created' => time(), 'finished' => time(), 'by' => 1, 'scope' => 'all', 'steps' => array(), 'sent' => $sent );
}

// --------------------------------------------------------------------------- the trim

for ( $i = 1; $i <= 15; $i++ ) {
	ADVCM_Jobs::save( job( 'sent-' . $i, true ) );
}

$ids = array_keys( ADVCM_Jobs::all() );

check( 'sent jobs are kept to the last ten', ADVCM_Jobs::KEEP === count( $ids ) && 10 === ADVCM_Jobs::KEEP, (string) count( $ids ) );
check( 'newest first', 'sent-15' === $ids[0] && 'sent-6' === $ids[9] );

$GLOBALS['options'] = array();

ADVCM_Jobs::save( job( 'waiting-1', false ) );
ADVCM_Jobs::save( job( 'waiting-2', false ) );

for ( $i = 1; $i <= 12; $i++ ) {
	ADVCM_Jobs::save( job( 'sent-' . $i, true ) );
}

$ids = array_keys( ADVCM_Jobs::all() );

check( 'a job Hawkeye has not confirmed survives past the last ten', in_array( 'waiting-1', $ids, true ) && in_array( 'waiting-2', $ids, true ), implode( ',', $ids ) );
check( 'while sent ones past the last ten go', ! in_array( 'sent-1', $ids, true ) && ! in_array( 'sent-2', $ids, true ) );
check( 'so the buffer is the ten plus the two waiting', 12 === count( $ids ), (string) count( $ids ) );

ADVCM_Jobs::mark_sent( 'waiting-1' );

check( 'once confirmed, it is let go', ! array_key_exists( 'waiting-1', ADVCM_Jobs::all() ) );

$GLOBALS['options'] = array();

for ( $i = 1; $i <= ADVCM_Jobs::UNSENT_CAP + 20; $i++ ) {
	ADVCM_Jobs::save( job( 'down-' . $i, false ) );
}

check( 'a Hawkeye that never confirms cannot grow the row past its cap', ADVCM_Jobs::UNSENT_CAP === count( ADVCM_Jobs::all() ), (string) count( ADVCM_Jobs::all() ) );
check( 'and what the cap drops is the oldest', ! array_key_exists( 'down-1', ADVCM_Jobs::all() ) && array_key_exists( 'down-' . ( ADVCM_Jobs::UNSENT_CAP + 20 ), ADVCM_Jobs::all() ) );

$GLOBALS['options'] = array();

$running          = job( 'background', true );
$running['state'] = 'running';
ADVCM_Jobs::save( $running );

for ( $i = 1; $i <= ADVCM_Jobs::UNSENT_CAP + 5; $i++ ) {
	ADVCM_Jobs::save( job( 'burst-' . $i, false ) );
}

check( 'a running job is never pushed out of the buffer, however many clears follow it', array_key_exists( 'background', ADVCM_Jobs::all() ) );

// -------------------------------------------------------------- what goes to Hawkeye

$GLOBALS['options'] = array();

ADVCM_Jobs::save( job( 'old', false ) );
ADVCM_Jobs::save( job( 'confirmed', true ) );
$running             = job( 'running', false );
$running['finished'] = 0;
ADVCM_Jobs::save( $running );
ADVCM_Jobs::save( job( 'new', false ) );

check( 'unsent jobs are sent oldest first, and a running one is not sent', array( 'old', 'new' ) === array_keys( ADVCM_Jobs::unsent() ), implode( ',', array_keys( ADVCM_Jobs::unsent() ) ) );

$again = ADVCM_Jobs::get( 'confirmed' );
unset( $again['sent'] );
ADVCM_Jobs::save( $again );

check( 'rewriting a job without the flag keeps what it was', true === ADVCM_Jobs::get( 'confirmed' )['sent'] );

// ------------------------------------------------------------------- the layer summary

$GLOBALS['options'] = array();

$j = array( 'id' => 'j1', 'by' => 3, 'scope' => 'all' );

ADVCM_Jobs::record_layer( $j, array( 'id' => 'nitropack', 'status' => 'ok', 'message' => 'invalidated' ) );
$first = ADVCM_Jobs::layers()['nitropack'];

check( 'a clear is recorded against its layer', 'ok' === $first['status'] && 3 === $first['by'] && 'j1' === $first['job'] );
check( 'and a success is its last success', $first['at'] === $first['ok_at'] );

ADVCM_Jobs::record_layer( $j, array( 'id' => 'nitropack', 'status' => 'skipped', 'message' => 'not connected' ) );
ADVCM_Jobs::record_layer( $j, array( 'id' => 'nitropack', 'status' => 'held', 'message' => '' ) );

check( 'a skip or a hold does not overwrite it, because nothing was cleared', 'ok' === ADVCM_Jobs::layers()['nitropack']['status'] );

$GLOBALS['options'][ ADVCM_Jobs::LAYERS ]['nitropack']['ok_at'] = 1000;

ADVCM_Jobs::record_layer( array( 'id' => 'j2', 'by' => 4, 'scope' => 'urls' ), array( 'id' => 'nitropack', 'status' => 'failed', 'message' => 'refused' ) );
$after = ADVCM_Jobs::layers()['nitropack'];

check( 'a failure is the last attempt', 'failed' === $after['status'] && 'j2' === $after['job'] );
check( 'and does not erase the last success', 1000 === $after['ok_at'] );

$sizes = strlen( serialize( ADVCM_Jobs::layers() ) );

check( 'the summary stays in the hundreds of bytes per layer', $sizes < 600, $sizes . ' bytes' );

finish();
