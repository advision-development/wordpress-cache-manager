<?php
/**
 * The change log: who changed what, capped per section so a busy section never pushes another
 * out, and never more than a row of known size.
 *
 * @package ADVCM
 */

$GLOBALS['user_id']  = 7;
$GLOBALS['logins']   = array( 7 => 'ed', 9 => 'admin' );
$GLOBALS['names']    = array( 7 => 'Ed Itor', 9 => 'Site Admin' );
$GLOBALS['autoload'] = array();

function get_current_user_id() {
	return $GLOBALS['user_id'];
}

function wp_get_current_user() {
	$id = $GLOBALS['user_id'];

	return (object) array( 'ID' => $id, 'user_login' => isset( $GLOBALS['logins'][ $id ] ) ? $GLOBALS['logins'][ $id ] : '', 'display_name' => isset( $GLOBALS['names'][ $id ] ) ? $GLOBALS['names'][ $id ] : '' );
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ]  = $value;
	$GLOBALS['autoload'][ $name ] = $autoload;

	return true;
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'safe' );
load_class( 'log' );

// --------------------------------------------------------------------------- the cap

for ( $i = 1; $i <= 25; $i++ ) {
	ADVCM_Log::add( 'clear', 'started', 'clear ' . $i );
}

$clears = ADVCM_Log::recent( 'clear', 100 );

check( 'a section keeps its last 20 records', ADVCM_Log::PER_SECTION === count( $clears ), (string) count( $clears ) );
check( 'newest first', 'clear 25' === $clears[0]['summary'] && 'clear 6' === $clears[19]['summary'], $clears[0]['summary'] . ' … ' . $clears[19]['summary'] );
check( 'and a tab shows 10', ADVCM_Log::SHOW === count( ADVCM_Log::recent( 'clear' ) ) );

$GLOBALS['options'] = array();

for ( $i = 1; $i <= 3; $i++ ) {
	ADVCM_Log::add( 'settings', 'changed', 'setting ' . $i );
}

for ( $i = 1; $i <= 25; $i++ ) {
	ADVCM_Log::add( 'clear', 'started', 'clear ' . $i );
}

check( 'a busy section never evicts another', 3 === count( ADVCM_Log::recent( 'settings' ) ) );

// ---------------------------------------------------------------------- one record

$GLOBALS['options'] = array();

ADVCM_Log::add( 'auto', 'rule_added', 'a picks is published', 'off', 'on' );

$record = ADVCM_Log::recent( 'auto' )[0];

check( 'a record names the person and when', 7 === $record['user'] && 'Ed Itor' === $record['name'] && abs( time() - $record['at'] ) < 5 );
check( 'and what changed, before and after', 'rule_added' === $record['action'] && 'off' === $record['before'] && 'on' === $record['after'] );
check( 'the row is not autoloaded', false === $GLOBALS['autoload'][ ADVCM_Log::OPTION ] );

unset( $GLOBALS['logins'][7], $GLOBALS['names'][7] );

check( 'a person deleted since still reads by the name they had', 'Ed Itor' === ADVCM_Log::recent( 'auto' )[0]['name'] );

$GLOBALS['names'][7] = 'Ed Itor';

ADVCM_Log::add( 'settings', 'changed', 'long', str_repeat( 'é', 500 ), 'short' );

$long = ADVCM_Log::recent( 'settings' )[0]['before'];

check( 'a long value is cut to 200 characters, on a character, marked as cut', ADVCM_Log::MAX_CHARS === mb_strlen( $long ) && '…' === mb_substr( $long, -1 ), (string) mb_strlen( $long ) );

$before = $GLOBALS['options'];
ADVCM_Log::add( 'nowhere', 'x', 'y' );

check( 'a section the screen does not have is not written', $before === $GLOBALS['options'] );

// -------------------------------------------------------------------- once a while

$GLOBALS['options'] = array();
$GLOBALS['user_id'] = 9;

ADVCM_Log::add_once( 'check', 'probe', 'https://example.test/a/', 600 );
ADVCM_Log::add_once( 'check', 'probe', 'https://example.test/a/', 600 );

check( 'the same probe reloaded within ten minutes is recorded once', 1 === count( ADVCM_Log::recent( 'check' ) ) );

ADVCM_Log::add_once( 'check', 'probe', 'https://example.test/b/', 600 );

check( 'another URL is another record', 2 === count( ADVCM_Log::recent( 'check' ) ) );

$GLOBALS['options'][ ADVCM_Log::OPTION ]['check'][1]['at'] -= 601;
ADVCM_Log::add_once( 'check', 'probe', 'https://example.test/a/', 600 );

check( 'and the same one after ten minutes is recorded again', 3 === count( ADVCM_Log::recent( 'check' ) ) );

$GLOBALS['user_id'] = 7;
ADVCM_Log::add_once( 'check', 'probe', 'https://example.test/a/', 600 );

check( 'as is the same one by somebody else', 4 === count( ADVCM_Log::recent( 'check' ) ) );

// ------------------------------------------------------------------ a broken row

$GLOBALS['options'][ ADVCM_Log::OPTION ] = 'not a list';
ADVCM_Log::add( 'clear', 'started', 'after a broken row' );

check( 'a broken row is started again rather than failing', 1 === count( ADVCM_Log::recent( 'clear' ) ) );

// -------------------------------------------------- no login names shown (security)

$GLOBALS['options'] = array();
$GLOBALS['user_id'] = 7;
ADVCM_Log::add( 'auto', 'x', 'y' );

$record = ADVCM_Log::recent( 'auto' )[0];

check( 'a record keeps the person\'s display name, not their login: editors read this log', 'Ed Itor' === $record['name'] && ! isset( $record['login'] ) );

finish();
