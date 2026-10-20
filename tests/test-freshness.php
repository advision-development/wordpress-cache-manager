<?php
/**
 * How old each page was when a clear cleared it: measured once per job, before its first stage,
 * bounded in count, time and storage, and never in the way of the clear.
 *
 * @package ADVCM
 */

$GLOBALS['autoload'] = array();

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ]  = $value;
	$GLOBALS['autoload'][ $name ] = $autoload;

	return true;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'jobs', 'urls', 'probe', 'settings', 'freshness' ) as $class ) {
	load_class( $class );
}

$probed = array();
$clock  = 1000.0;

ADVCM_Freshness::use_clock(
	function () use ( &$clock ) {
		return $clock;
	}
);

/**
 * A prober that answers like a WP Engine page stored 5000 seconds ago, and remembers what it was
 * asked. Nothing leaves the machine.
 */
$answer = function ( $url, $timeout ) use ( &$probed ) {
	$probed[] = array( $url, $timeout );

	return ADVCM_Probe::read( $url, 200, array( 'x-cache' => 'HIT: 3', 'x-cache-ctime' => '1789995000' ), 300, null, 1790000000, "NITROPACK_STATE='STALE'" );
};

ADVCM_Freshness::use_prober( $answer );

/**
 * A job as the runner keeps it, every step pending.
 */
function job( $id, array $urls, $source = 'wp-admin', $scope = 'urls' ) {
	return array(
		'id'     => $id,
		'scope'  => $scope,
		'urls'   => $urls,
		'source' => $source,
		'by'     => 7,
		'steps'  => array( array( 'stage' => ADVCM_Stages::HOST, 'status' => 'pending' ) ),
	);
}

function pages( $n, $prefix = 'p' ) {
	$out = array();

	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = 'https://example.test/' . $prefix . $i . '/';
	}

	return $out;
}

// --------------------------------------------------------------------------- per URL

$job = ADVCM_Freshness::measure( job( 'j1', pages( 3 ) ) );
$row = ADVCM_Freshness::rows();

check( 'a per-URL clear measures each of its pages', 3 === count( $row ) && 3 === count( $probed ), (string) count( $row ) );
check( 'and marks the job measured, nothing left out', ! empty( $job['measured'] ) && 0 === $job['not_measured'] );
check( 'each with the short timeout', ADVCM_Freshness::MEASURE_TIMEOUT === $probed[0][1] );
check( 'a row says which page, which job, who and how', 'https://example.test/p1/' === $row[0]['url'] && 'j1' === $row[0]['job'] && 7 === $row[0]['by'] && 'wp-admin' === $row[0]['source'] && 200 === $row[0]['code'] );
check( 'and how old the copy was, as the layers said', 5000 === $row[0]['age'] && 'exact' === $row[0]['how'] && array( 'host cache' => 5000 ) === $row[0]['ages'] );
check( 'and the NitroPack state when the page carried one', 'STALE' === $row[0]['nitro_state'] );
check( 'a site without NitroPack has no NitroPack copy in the row', null === $row[0]['nitro_copy'] && null === $row[0]['nitro_age'] );
check( 'the row is not autoloaded', false === $GLOBALS['autoload'][ ADVCM_Freshness::OPTION ] );
check( 'and the job finds its own rows', 3 === count( ADVCM_Freshness::for_job( 'j1' ) ) && array() === ADVCM_Freshness::for_job( 'nope' ) );

$probed = array();
ADVCM_Freshness::measure( $job );

check( 'a job measured once is not measured again — a pause, a cursor, a resume', array() === $probed && 3 === count( ADVCM_Freshness::rows() ) );

// ------------------------------------------------------------------------ never these

$GLOBALS['options'] = array();
$probed             = array();

$job = ADVCM_Freshness::measure( job( 'j2', pages( 2 ), 'wp-admin', 'all' ) );

check( 'a whole-site clear measures nothing', array() === $probed && ! empty( $job['measured'] ) && array() === ADVCM_Freshness::rows() );

$started                       = job( 'j3', pages( 2 ) );
$started['steps'][0]['status'] = 'ok';

ADVCM_Freshness::measure( $started );

check( 'nor a job that had begun before this version: its pages are already cleared', array() === $probed );

$GLOBALS['options'][ ADVCM_Settings::OPTION ] = array( 'measure_before_clear' => false );

$job = ADVCM_Freshness::measure( job( 'j4', pages( 2 ) ) );

check( 'nor any job when the setting is off', array() === $probed && ! empty( $job['measured'] ) );

unset( $GLOBALS['options'][ ADVCM_Settings::OPTION ] );

check( 'the setting is on by default', true === ADVCM_Settings::get()['measure_before_clear'] );

// ---------------------------------------------------------------------------- bounds

$GLOBALS['options'] = array();
$probed             = array();

$job = ADVCM_Freshness::measure( job( 'j5', pages( 25 ) ) );

check( 'at most 10 pages of a job are measured', ADVCM_Freshness::MEASURE_MAX === count( $probed ) && 10 === count( ADVCM_Freshness::rows() ) );
check( 'and the rest are counted on the job, not stored', 15 === $job['not_measured'] );

$GLOBALS['options'] = array();
$probed             = array();

ADVCM_Freshness::use_prober(
	function ( $url, $timeout ) use ( $answer, &$clock ) {
		$clock += 6;

		return $answer( $url, $timeout );
	}
);

$job = ADVCM_Freshness::measure( job( 'j6', pages( 10 ) ) );

check( 'slow pages stop the measuring before 20 seconds: no probe starts that could end past it', 3 === count( $probed ) && 7 === $job['not_measured'], count( $probed ) . ' / ' . $job['not_measured'] );

ADVCM_Freshness::use_prober( $answer );

$GLOBALS['options'] = array();

for ( $j = 1; $j <= 15; $j++ ) {
	ADVCM_Freshness::measure( job( 'k' . $j, pages( 10, 'k' . $j . '-' ) ) );
}

$rows = ADVCM_Freshness::rows();

check( 'people\'s measurements keep the last 100', ADVCM_Freshness::KEEP === count( $rows ), (string) count( $rows ) );
check( 'newest job first, in the order of its pages', 'k15' === $rows[0]['job'] && 'https://example.test/k15-1/' === $rows[0]['url'] && 'k6' === $rows[99]['job'] );

ADVCM_Freshness::measure( job( 'a1', pages( 2 ), 'auto' ) );

check( 'an automatic clear writes the automatic row only', 2 === count( ADVCM_Freshness::rows( true ) ) && 'k15' === ADVCM_Freshness::rows()[0]['job'] );
check( 'which is not autoloaded either', false === $GLOBALS['autoload'][ ADVCM_Freshness::OPTION_AUTO ] );

$GLOBALS['options'] = array();

ADVCM_Freshness::measure( job( 'long', array( 'https://example.test/' . str_repeat( 'a', 5000 ) ) ) );

check( 'a long URL is stored cut to 2048 characters', ADVCM_Freshness::URL_CHARS === strlen( ADVCM_Freshness::rows()[0]['url'] ) );

$GLOBALS['options'] = array();

for ( $j = 1; $j <= 10; $j++ ) {
	ADVCM_Freshness::measure( job( 'w' . $j, array( 'https://example.test/' . str_repeat( 'b', 5000 ) . $j ), 'auto' ) );
	ADVCM_Freshness::measure( job( 'x' . $j, array_map( function ( $u ) { return $u . str_repeat( 'c', 5000 ); }, pages( 10, 'x' . $j . '-' ) ) ) );
}

$size = strlen( serialize( get_option( ADVCM_Freshness::OPTION ) ) );

check( 'the worst row is under 300 KB', $size < 300 * 1024, round( $size / 1024 ) . ' KB' );

// ---------------------------------------------------------------- never in the way

$GLOBALS['options'] = array();

ADVCM_Freshness::use_prober(
	function () {
		throw new Error( 'the network is gone' );
	}
);

$job = ADVCM_Freshness::measure( job( 'e1', pages( 4 ) ) );
$row = ADVCM_Freshness::rows();

check( 'a probe that throws is a row with no answer, and the job goes on', ! empty( $job['measured'] ) && 0 === $row[0]['code'] && null === $row[0]['age'] );
check( 'and the pages after it are not asked: a site that cannot reach itself would wait 5 s on each', 1 === count( $row ) && 3 === $job['not_measured'], count( $row ) . ' / ' . $job['not_measured'] );

ADVCM_Freshness::use_prober(
	function ( $url ) {
		return ADVCM_Probe::read( $url, 301, array( 'x-cache' => 'MISS' ), 40, null, 1790000000 );
	}
);

$GLOBALS['options'] = array();

ADVCM_Freshness::measure( job( 'r1', pages( 1 ) ) );

check( 'a redirect is recorded with its code', 301 === ADVCM_Freshness::rows()[0]['code'] );
check( 'and no age: the copy measured is the redirect\'s, not the page\'s', null === ADVCM_Freshness::rows()[0]['age'] && array() === ADVCM_Freshness::rows()[0]['ages'] );

ADVCM_Freshness::use_prober(
	function () {
		return 'That is not a page on this site.';
	}
);

$GLOBALS['options'] = array();

ADVCM_Freshness::measure( job( 's1', pages( 1 ) ) );

check( 'a refusal is a row with no answer', 0 === ADVCM_Freshness::rows()[0]['code'] );

$GLOBALS['options'][ ADVCM_Freshness::OPTION ] = 'not a list';
ADVCM_Freshness::use_prober( $answer );
ADVCM_Freshness::measure( job( 'b1', pages( 1 ) ) );

check( 'a broken row is started again', 1 === count( ADVCM_Freshness::rows() ) );

// ------------------------------------------------------------ cleared by hand, often

$GLOBALS['options']                       = array();
$GLOBALS['options'][ ADVCM_Freshness::OPTION ] = array(
	array( 'at' => 500, 'url' => 'https://example.test/analysis/', 'job' => 'h4', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => 900, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => 400, 'url' => 'https://example.test/usa/alabama/', 'job' => 'h3', 'source' => 'admin-bar', 'by' => 7, 'code' => 200, 'age' => 60, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => 300, 'url' => 'https://example.test/analysis/', 'job' => 'h2', 'source' => 'wp-admin', 'by' => 9, 'code' => 200, 'age' => 100, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => 200, 'url' => 'https://example.test/analysis/', 'job' => 'h1', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => null, 'how' => 'unknown', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => 100, 'url' => 'https://example.test/usa/alabama/', 'job' => 'h0', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => 20, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => 50, 'url' => 'https://example.test/once/', 'job' => 'h9', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => 5, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
);
$GLOBALS['options']['advcm_rules'] = array( 'r1' => array( 'post_type' => 'post', 'enabled' => true, 'urls' => array( 'https://example.test/analysis/' ) ) );

load_class( 'auto' );

$often = ADVCM_Freshness::often_by_hand();

check( 'pages cleared by hand twice or more, most often first', 2 === count( $often ) && 'https://example.test/analysis/' === $often[0]['url'] && 3 === $often[0]['times'] && 2 === $often[1]['times'] );
check( 'with the latest clear', 500 === $often[0]['last'] && 400 === $often[1]['last'] );
check( 'and the median cache age of those that said one', 500 === $often[0]['median_age'] && 40 === $often[1]['median_age'], $often[0]['median_age'] . ' / ' . $often[1]['median_age'] );
check( 'and whether a rule already clears it', true === $often[0]['has_rule'] && false === $often[1]['has_rule'] );

finish();
