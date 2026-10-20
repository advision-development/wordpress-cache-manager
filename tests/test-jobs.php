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

/**
 * A finished automatic clear.
 *
 * @param string $id Id.
 * @return array
 */
function auto_job( $id ) {
	return array_merge( job( $id, false ), array( 'source' => 'auto' ) );
}

for ( $i = 1; $i <= 25; $i++ ) {
	ADVCM_Jobs::save( job( 'manual-' . $i, true ) );
}

$ids = array_keys( ADVCM_Jobs::all() );

check( 'manual clears are kept to the last 20', 20 === ADVCM_Jobs::KEEP && ADVCM_Jobs::KEEP === count( $ids ), (string) count( $ids ) );
check( 'newest first', 'manual-25' === $ids[0] && 'manual-6' === $ids[19] );

for ( $i = 1; $i <= 25; $i++ ) {
	ADVCM_Jobs::save( auto_job( 'auto-' . $i ) );
}

$auto   = isset( $GLOBALS['options'][ ADVCM_Jobs::OPTION_AUTO ] ) ? $GLOBALS['options'][ ADVCM_Jobs::OPTION_AUTO ] : array();
$manual = $GLOBALS['options'][ ADVCM_Jobs::OPTION ];

check( 'automatic clears have a row of their own, kept to the last 20', 20 === ADVCM_Jobs::AUTO_KEEP && ADVCM_Jobs::AUTO_KEEP === count( $auto ), (string) count( $auto ) );
check( 'and never push a manual clear out', 20 === count( $manual ) && array_key_exists( 'manual-25', $manual ) && ! array_key_exists( 'auto-25', $manual ) );
check( 'a job is found in either row', 'auto' === ADVCM_Jobs::get( 'auto-25' )['source'] && is_array( ADVCM_Jobs::get( 'manual-25' ) ) );

$GLOBALS['options'] = array();

ADVCM_Jobs::save( array_merge( job( 'older', true ), array( 'created' => 100 ) ) );
ADVCM_Jobs::save( array_merge( auto_job( 'middle' ), array( 'created' => 200 ) ) );
ADVCM_Jobs::save( array_merge( job( 'newer', true ), array( 'created' => 300 ) ) );

check( 'all() is both rows, newest first', array( 'newer', 'middle', 'older' ) === array_keys( ADVCM_Jobs::all() ), implode( ',', array_keys( ADVCM_Jobs::all() ) ) );

$GLOBALS['options'] = array();

for ( $i = 1; $i <= 30; $i++ ) {
	ADVCM_Jobs::save( job( 'waiting-' . $i, false ) );
}

check( 'until Hawkeye exists, a job it has not confirmed is no reason to keep more than the cap', ADVCM_Jobs::KEEP === count( ADVCM_Jobs::all() ), (string) count( ADVCM_Jobs::all() ) );

$GLOBALS['options'] = array();

$running          = auto_job( 'background' );
$running['state'] = 'running';
ADVCM_Jobs::save( $running );

for ( $i = 1; $i <= 30; $i++ ) {
	ADVCM_Jobs::save( auto_job( 'burst-' . $i ) );
}

check( 'a running job is never pushed out of its row, however many clears follow it', array_key_exists( 'background', ADVCM_Jobs::all() ) );

// ------------------------------------------------------------ the row of 0.3.2

$GLOBALS['options'] = array(
	ADVCM_Jobs::OPTION => array(
		'm2'  => job( 'm2', false ),
		'a2'  => auto_job( 'a2' ),
		'm1'  => job( 'm1', false ),
		'a1r' => array_merge( auto_job( 'a1r' ), array( 'state' => 'running' ) ),
	),
);

ADVCM_Jobs::save( job( 'm3', false ) );

$manual = array_keys( $GLOBALS['options'][ ADVCM_Jobs::OPTION ] );
$auto   = array_keys( $GLOBALS['options'][ ADVCM_Jobs::OPTION_AUTO ] );

check( 'automatic clears saved by 0.3.2 move to their own row on the next save', array( 'm3', 'm2', 'm1' ) === $manual, implode( ',', $manual ) );
check( 'each once, a running one included', array( 'a2', 'a1r' ) === $auto, implode( ',', $auto ) );

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
