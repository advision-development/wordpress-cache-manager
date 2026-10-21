<?php
/**
 * The oldest-copies index: a WP-Cron scan of NitroPack's folders in batches, keeping only the 100
 * oldest copies per device, naming them from this site's own URLs, and a worst case in bytes.
 *
 * @package ADVCM
 */

$GLOBALS['autoload'] = array();

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ]  = $value;
	$GLOBALS['autoload'][ $name ] = $autoload;

	return true;
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function get_permalink( $id ) {
	return 'https://example.test/p-' . $id . '/';
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/stubs-nitropack.php';

foreach ( array( 'safe', 'optimizer-nitropack', 'optimizer-index' ) as $class ) {
	load_class( $class );
}

$now = time();

/** The posts this site has, as the naming phase would read them. */
$GLOBALS['posts'] = array();

ADVCM_Optimizer_Index::use_candidates(
	function ( $phase, $after, $limit ) {
		$GLOBALS['asked'][] = $phase;

		if ( 'home' === $phase ) {
			return $after < 1 ? array( 1 => 'https://example.test/' ) : array();
		}

		if ( 'posts' !== $phase ) {
			return array();
		}

		$out = array();

		foreach ( $GLOBALS['posts'] as $id ) {
			if ( $id > $after && count( $out ) < $limit ) {
				$out[ $id ] = get_permalink( $id );
			}
		}

		return $out;
	}
);

/**
 * Run batches until the scan is done, as WP-Cron would. Returns how many runs it took.
 */
function scan_all( $size = 100 ) {
	for ( $runs = 1; $runs <= 500; $runs++ ) {
		ADVCM_Optimizer_Index::run_batch( $size, 60 );

		if ( ADVCM_Optimizer_Index::state()['done'] ) {
			return $runs;
		}
	}

	return -1;
}

// ------------------------------------------------------------------- not on this site

ADVCM_Optimizer_Nitropack::use_sdk( null );

check( 'a site without NitroPack: a scan does not start, and says why', '' !== ADVCM_Optimizer_Index::start( 7 ) && false === wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) );
check( 'nothing to rescue, nothing to run, nothing stored', 0 === ADVCM_Optimizer_Index::rescue() && 0 === ADVCM_Optimizer_Index::run_batch( 100, 2 ) && ! isset( $GLOBALS['options'][ ADVCM_Optimizer_Index::OPTION ] ) );

ADVCM_Optimizer_Index::cron();

check( 'its event, if it fires, does nothing and schedules nothing', ! isset( $GLOBALS['options'][ ADVCM_Optimizer_Index::OPTION ] ) && false === wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) );
check( 'and has no rows', array() === ADVCM_Optimizer_Index::rows( 'desktop' ) );

// ---------------------------------------------------------------------- a cache on disk

$dir = sys_get_temp_dir() . '/advcm-npi-' . getmypid() . '-' . mt_rand();
@mkdir( $dir . '/desktop', 0777, true );
@mkdir( $dir . '/mobile', 0777, true );

ADVCM_Optimizer_Nitropack::use_sdk( new Stub_Nitro_Sdk( $dir ) );

// 250 desktop copies, post i made i minutes before now: the oldest are the highest ids.
for ( $i = 1; $i <= 250; $i++ ) {
	$GLOBALS['posts'][] = $i;
	nitro_copy( $dir, 'desktop', get_permalink( $i ), 'fresh', $now - $i * 60 );
}

// The oldest of all is invalidated, one is a page no URL of the site names, one is stale.
nitro_copy( $dir, 'desktop', get_permalink( 400 ), 'invalidated', $now - 900 * 60 );
nitro_copy( $dir, 'desktop', 'https://example.test/?utm=x', 'fresh', $now - 800 * 60 );
nitro_copy( $dir, 'mobile', 'https://example.test/', 'stale', $now - 30 );
nitro_copy( $dir, 'mobile', get_permalink( 3 ), 'fresh', $now - 120 );
@mkdir( $dir . '/mobile/' . str_repeat( 'e', 32 ), 0777, true );
$GLOBALS['posts'][] = 400;

check( 'a site with NitroPack: never started is put on the schedule when a screen opens', 1 === ADVCM_Optimizer_Index::rescue() && false !== wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) );

wp_clear_scheduled_hook( ADVCM_Optimizer_Index::HOOK );
ADVCM_Optimizer_Index::cron();

check( 'its event starts a scan, by nobody', 0 === ADVCM_Optimizer_Index::state()['by'] && ADVCM_Optimizer_Index::state()['started'] > 0 );

$GLOBALS['options'] = array();

check( 'a person starts one', '' === ADVCM_Optimizer_Index::start( 7 ) && 7 === ADVCM_Optimizer_Index::state()['by'] && false !== wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) );
check( 'not twice', '' !== ADVCM_Optimizer_Index::start( 7 ) );

check( 'a batch reads at most its size', 100 === ADVCM_Optimizer_Index::run_batch( 100, 60 ) && 100 === ADVCM_Optimizer_Index::state()['scanned'] );
check( 'and schedules the next', false !== wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) && 0 === ADVCM_Optimizer_Index::state()['done'] );
check( 'nothing is shown before a scan finishes', array() === ADVCM_Optimizer_Index::rows( 'desktop' ) );

$runs = scan_all();

check( 'the scan finishes in batches', $runs > 1 && ADVCM_Optimizer_Index::state()['done'] > 0, (string) $runs );
check( 'every folder read once', 255 === ADVCM_Optimizer_Index::state()['scanned'], (string) ADVCM_Optimizer_Index::state()['scanned'] );

$rows = ADVCM_Optimizer_Index::rows( 'desktop' );

check( 'only the 100 oldest copies of a device are kept', ADVCM_Optimizer_Index::KEEP === count( $rows ), (string) count( $rows ) );
check( 'oldest first', $now - 900 * 60 === $rows[0]['created'] && $now - 800 * 60 === $rows[1]['created'] && $now - 250 * 60 === $rows[2]['created'] );
check( 'and none younger than the 100th oldest', $now - 153 * 60 === $rows[99]['created'], (string) ( ( $now - $rows[99]['created'] ) / 60 ) );
check( 'an invalidated copy says so', 1 === $rows[0]['invalidated'] && 0 === $rows[2]['invalidated'] );
check( 'each lives until created + the expiry in force', $rows[2]['created'] + 86400 === $rows[2]['lives_until'] );
check( 'named from this site\'s own URLs', 'https://example.test/p-400/' === $rows[0]['url'] && 'https://example.test/p-250/' === $rows[2]['url'], $rows[0]['url'] . ' ' . $rows[2]['url'] );
check( 'a folder no URL of the site names is unidentified', '' === $rows[1]['url'] );

$mobile = ADVCM_Optimizer_Index::rows( 'mobile' );

check( 'each device on its own; an empty folder is no copy', 2 === count( $mobile ) && 'https://example.test/p-3/' === $mobile[0]['url'] && 'https://example.test/' === $mobile[1]['url'] && 1 === $mobile[1]['stale'] );
check( 'a device with no folder has no rows, and no error', array() === ADVCM_Optimizer_Index::rows( 'tablet' ) );

$state = ADVCM_Optimizer_Index::state();

check( 'a finished scan schedules the next one 6 hours on', abs( wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) - ( time() + 6 * HOUR_IN_SECONDS ) ) <= 2 && $state['finished'] > 0 );
check( 'and keeps no working list', array() === $state['work'] );
check( 'the row is not autoloaded', false === $GLOBALS['autoload'][ ADVCM_Optimizer_Index::OPTION ] );
check( 'nothing is left locked', ! isset( $GLOBALS['options'][ ADVCM_Optimizer_Index::LOCK ] ) );

// ---------------------------------------------------------------- the next scan

check( 'a finished scan can be started again', '' === ADVCM_Optimizer_Index::start( 7 ) );

ADVCM_Optimizer_Index::run_batch( 100, 60 );

check( 'while it runs, the last finished list is still shown', ADVCM_Optimizer_Index::KEEP === count( ADVCM_Optimizer_Index::rows( 'desktop' ) ) );

// The event is lost mid-scan: the next press, or a screen opening late, picks up from the cursor.
$cursor = ADVCM_Optimizer_Index::state()['cursor'];
wp_clear_scheduled_hook( ADVCM_Optimizer_Index::HOOK );

check( 'a scan whose event was just taken is not touched', 0 === ADVCM_Optimizer_Index::rescue() );

$state            = get_option( ADVCM_Optimizer_Index::OPTION );
$state['next_at'] = time() - ADVCM_Optimizer_Index::LATE - 1;
update_option( ADVCM_Optimizer_Index::OPTION, $state, false );

check( 'one with no event for over 2 minutes is put back', 1 === ADVCM_Optimizer_Index::rescue() && false !== wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) );
check( 'once', 0 === ADVCM_Optimizer_Index::rescue() );

wp_clear_scheduled_hook( ADVCM_Optimizer_Index::HOOK );

check( 'and a press resumes it from its cursor', '' === ADVCM_Optimizer_Index::start( 7 ) && $cursor === ADVCM_Optimizer_Index::state()['cursor'] && 100 === ADVCM_Optimizer_Index::state()['scanned'] );

// A folder that kills the request must not stop every scan after it: the lock names it.
$names = array_values( array_diff( scandir( $dir . '/desktop' ), array( '.', '..' ) ) );
$GLOBALS['options'][ ADVCM_Optimizer_Index::LOCK ] = array( 'at' => time() - 600, 'folder' => $names[150] );
ADVCM_Optimizer_Index::run_batch( 1, 60 );

check( 'a folder that killed the run is stepped over', $names[151] === ADVCM_Optimizer_Index::state()['cursor'] && 1 === ADVCM_Optimizer_Index::state()['skipped'], ADVCM_Optimizer_Index::state()['cursor'] );

$GLOBALS['options'][ ADVCM_Optimizer_Index::LOCK ] = array( 'at' => time(), 'folder' => '' );
$before = ADVCM_Optimizer_Index::state()['cursor'];

check( 'a run already going is not doubled', 0 === ADVCM_Optimizer_Index::run_batch( 100, 60 ) && $before === ADVCM_Optimizer_Index::state()['cursor'] );

unset( $GLOBALS['options'][ ADVCM_Optimizer_Index::LOCK ] );

// NitroPack removed mid-scan: the run stops quietly, the scan is not lost.
ADVCM_Optimizer_Nitropack::use_sdk( null );

check( 'NitroPack gone mid-scan: no error, nothing read', 0 === ADVCM_Optimizer_Index::run_batch( 100, 60 ) && $before === ADVCM_Optimizer_Index::state()['cursor'] );

ADVCM_Optimizer_Nitropack::use_sdk( new Stub_Nitro_Sdk( $dir ) );

// ---------------------------------------------------------------------- the budget

$GLOBALS['options'] = array();
ADVCM_Optimizer_Index::start( 7 );

$clock = 0.0;
ADVCM_Optimizer_Index::use_clock(
	function () use ( &$clock ) {
		$clock += 0.5;

		return $clock;
	}
);

$read = ADVCM_Optimizer_Index::run_batch( 1000, 2 );

check( 'a batch stops at its time budget, wherever it is', $read > 0 && $read < 10, (string) $read );

ADVCM_Optimizer_Index::use_clock( null );

// ---------------------------------------------------------------------- the caps

// Every device full, every copy named by a post whose id is as long as an id gets, and a scan
// half done beside the finished one: the most the row ever holds.
exec( 'rm -rf ' . escapeshellarg( $dir ) );
$GLOBALS['posts']   = array();
$GLOBALS['options'] = array();

foreach ( array( 'desktop', 'mobile', 'tablet' ) as $device ) {
	for ( $i = 1; $i <= 150; $i++ ) {
		$id                 = 9223372036854775000 + $i;
		$GLOBALS['posts'][] = $id;
		nitro_copy( $dir, $device, get_permalink( $id ), 1 === $i % 2 ? 'invalidated' : 'stale', $now - $i );
	}
}

ADVCM_Optimizer_Index::start( 7 );
$GLOBALS['asked'] = array();
scan_all( 1000 );

check( 'naming stops once every kept folder is named', ! in_array( 'terms', $GLOBALS['asked'], true ), implode( ',', array_unique( $GLOBALS['asked'] ) ) );
ADVCM_Optimizer_Index::start( 7 );

while ( ADVCM_Optimizer_Index::state()['scanned'] < 400 ) {
	ADVCM_Optimizer_Index::run_batch( 100, 60 );
}

$size = strlen( serialize( get_option( ADVCM_Optimizer_Index::OPTION ) ) );

check( 'each device keeps 100, finished and in progress alike', ADVCM_Optimizer_Index::KEEP === count( ADVCM_Optimizer_Index::rows( 'tablet' ) ) );
check( 'the full row stays under 150 KB', $size < 150 * 1024, round( $size / 1024 ) . ' KB' );

ADVCM_Optimizer_Nitropack::use_sdk( null );
exec( 'rm -rf ' . escapeshellarg( $dir ) );

finish();
