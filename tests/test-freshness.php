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

// ------------------------------------------------------- what the measurements add up to

// The rows above keep the last 100 clears: on BMR, auto-clear fills that in hours. What a week
// of clears says is kept apart, summed per page and per day, in one row of known size.
$GLOBALS['options'] = array();
$GLOBALS['options']['advcm_rules'] = array(
	'r1' => array( 'post_type' => 'post', 'enabled' => true, 'urls' => array( 'https://example.test/analysis/' ) ),
	'r2' => array( 'post_type' => 'post', 'enabled' => true, 'urls' => array( 'https://example.test/news/' ) ),
);
$GLOBALS['ages'] = array();

load_class( 'auto' );

ADVCM_Freshness::use_prober(
	function ( $url ) {
		$age = array_shift( $GLOBALS['ages'] );

		return ADVCM_Probe::read( $url, 200, null === $age ? array() : array( 'x-cache' => 'HIT: 1', 'x-cache-ctime' => (string) ( time() - $age ) ), 50, null, time() );
	}
);

/**
 * A clear of one page that finds the given age (null: no cache said).
 */
function cleared( $id, $url, $age, $source = 'wp-admin', $rule = '' ) {
	$GLOBALS['ages'] = array( $age );
	$job             = job( $id, array( 'https://example.test' . $url ), $source );

	if ( '' !== $rule ) {
		$job['options'] = array( 'triggers' => array( array( 'rule' => $rule, 'at' => time() ) ) );
	}

	ADVCM_Freshness::measure( $job );
}

cleared( 'h1', '/analysis/', null );
cleared( 'h2', '/analysis/', 100 );
cleared( 'h3', '/analysis/', 900 );
cleared( 'h4', '/usa/alabama/', 20, 'admin-bar' );
cleared( 'h5', '/usa/alabama/', 60 );
cleared( 'h6', '/once/', 5 );
cleared( 'a1', '/analysis/', 5 * HOUR_IN_SECONDS, 'auto', 'r1' );
cleared( 'a2', '/news/', 30, 'auto', 'r2' );
cleared( 'a3', '/news/', 40, 'auto', 'r2' );
cleared( 'a4', '/news/', 50, 'auto', 'r2' );

check( 'an automatic clear\'s row names the rule that set it off', array( 'r2' ) === ADVCM_Freshness::rows( true )[0]['rules'] );

$stats = ADVCM_Freshness::stats();
$page  = $stats['pages']['https://example.test/analysis/'];

check( 'each page sums its clears: by hand and automatic', 4 === $page['n'] && 3 === $page['hand'] && 1 === $page['auto'] );
check( 'how many found no age, how many a copy under 10 minutes, how many one past the target', 1 === $page['unk'] && 1 === $page['young'] && 1 === $page['over'] );
check( 'and the oldest copy, and the sum for the average', 5 * HOUR_IN_SECONDS === $page['max'] && 100 + 900 + 5 * HOUR_IN_SECONDS === $page['sum'] );
check( 'the stats row is not autoloaded', false === $GLOBALS['autoload'][ ADVCM_Freshness::STATS ] );

$today = $stats['days'][ gmdate( 'Y-m-d' ) ];

check( 'and each day sums what it measured', 10 === $today['n'] && 1 === $today['over'] && 1 === $today['unk'] && 7 === $today['young'], json_encode( $today ) );

// ------------------------------------------------------------------------ what it means

$insights = ADVCM_Freshness::insights();

check( 'pages kept past the target are listed, with whether a rule clears them', 1 === count( $insights['over'] ) && 'https://example.test/analysis/' === $insights['over'][0]['url'] && true === $insights['over'][0]['has_rule'] );
check( 'pages whose clears keep finding a fresh copy are listed, most cleared first, with the rules that clear them', 2 === count( $insights['young'] ) && 'https://example.test/news/' === $insights['young'][0]['url'] && array( 'r2' ) === $insights['young'][0]['rules'] && 'https://example.test/usa/alabama/' === $insights['young'][1]['url'] );

$often = $insights['hand'];

check( 'pages cleared by hand twice or more, most often first', 2 === count( $often ) && 'https://example.test/analysis/' === $often[0]['url'] && 3 === $often[0]['times'] && 2 === $often[1]['times'] );
check( 'with the average cache age of those that said one', 500 === $often[0]['avg_age'] && 40 === $often[1]['avg_age'], $often[0]['avg_age'] . ' / ' . $often[1]['avg_age'] );
check( 'and whether a rule already clears it', true === $often[0]['has_rule'] && false === $often[1]['has_rule'] );

$GLOBALS['options']['advcm_rules']['r3'] = array( 'post_type' => 'post', 'enabled' => false, 'urls' => array( 'https://example.test/usa/alabama/' ) );

check( 'a switched-off rule does not count as clearing a page', false === ADVCM_Freshness::insights()['hand'][1]['has_rule'] );

unset( $GLOBALS['options']['advcm_rules']['r3'] );
check( 'and a summary of the last 30 days', 10 === $insights['summary']['n'] && 1 === $insights['summary']['over'] && 7 === $insights['summary']['young'] && 1 === $insights['summary']['unk'] );

$GLOBALS['options'][ ADVCM_Settings::OPTION ] = array( 'cache_target' => 1 );
cleared( 'h7', '/once/', 2 * HOUR_IN_SECONDS );

check( 'the target is the one in Settings', 1 === ADVCM_Freshness::stats()['pages']['https://example.test/once/']['over'] );
check( 'three hours unless somebody changed it', 3 === ( function () { $s = $GLOBALS['options'][ ADVCM_Settings::OPTION ]; unset( $GLOBALS['options'][ ADVCM_Settings::OPTION ] ); $t = ADVCM_Settings::get()['cache_target']; $GLOBALS['options'][ ADVCM_Settings::OPTION ] = $s; return $t; } )() );

unset( $GLOBALS['options'][ ADVCM_Settings::OPTION ] );

// ------------------------------------------- an "at most" is a bound, not an age (review)

$GLOBALS['options'] = array();

ADVCM_Freshness::use_prober(
	function ( $url ) {
		// A HIT with no Age and no ctime, five hours after this plugin last cleared the page.
		return ADVCM_Probe::read( $url, 200, array( 'x-cache' => 'HIT: 1' ), 50, time() - 5 * HOUR_IN_SECONDS, time() );
	}
);

ADVCM_Freshness::measure( job( 'm1', array( 'https://example.test/bound/' ) ) );

$page = ADVCM_Freshness::stats()['pages']['https://example.test/bound/'];

check( 'an "at most" age is never counted as past the target, nor as the oldest copy', 0 === $page['over'] && 0 === $page['max'] && 0 === $page['sum'] && 1 === $page['n'] );

// ------------------------------------------- the own-page rule clears posts (review)

$GLOBALS['options']['advcm_rules'] = array( 'own-page' => array( 'post_type' => '*', 'enabled' => true, 'own' => true, 'urls' => array() ) );
$GLOBALS['options'][ ADVCM_Freshness::STATS ] = array(
	'pages' => array( 'https://example.test/a-post/' => array( 'n' => 2, 'hand' => 0, 'auto' => 2, 'unk' => 0, 'young' => 0, 'over' => 2, 'max' => 5 * HOUR_IN_SECONDS, 'sum' => 10 * HOUR_IN_SECONDS, 'hsum' => 0, 'hk' => 0, 'last' => time(), 'rules' => array( 'own-page' ) ) ),
	'days'  => array(),
);

check( 'a page each post\'s own page clears is not told it has no rule', true === ADVCM_Freshness::insights()['over'][0]['has_rule'] );

$GLOBALS['options']['advcm_rules']['own-page']['enabled'] = false;

check( 'unless own-page is switched off', false === ADVCM_Freshness::insights()['over'][0]['has_rule'] );

ADVCM_Freshness::use_prober( $answer );

// --------------------------------------------------------------------------- its caps

$GLOBALS['options'] = array();

/**
 * A URL of exactly 2048 characters, the longest kept.
 */
function longest( $i ) {
	$head = 'https://example.test/' . $i . '/';

	return $head . str_repeat( 'p', ADVCM_Freshness::URL_CHARS - strlen( $head ) );
}

$many_rules = array();

for ( $r = 1; $r <= 12; $r++ ) {
	$many_rules[] = array( 'rule' => str_repeat( 'r', 38 ) . sprintf( '%02d', $r ), 'at' => time() );
}

for ( $i = 1; $i <= 260; $i++ ) {
	$GLOBALS['ages'] = array( 10 );
	$big             = job( 'c' . $i, array( longest( $i ) ), 'auto' );
	$big['options']  = array( 'triggers' => $many_rules );
	ADVCM_Freshness::measure( $big );
}

$stats = ADVCM_Freshness::stats();

$key = function ( $url ) {
	return substr( $url, 0, ADVCM_Freshness::STATS_URL_CHARS );
};

check( 'at most 200 pages are summed: the one cleared longest ago goes first', ADVCM_Freshness::STATS_PAGES === count( $stats['pages'] ) && ! isset( $stats['pages'][ $key( longest( 1 ) ) ] ) && isset( $stats['pages'][ $key( longest( 260 ) ) ] ) );
check( 'each keyed by at most 1024 characters of its URL, with at most 5 rules', ADVCM_Freshness::STATS_URL_CHARS === strlen( (string) array_keys( $stats['pages'] )[0] ) && 5 === count( $stats['pages'][ $key( longest( 260 ) ) ]['rules'] ) );

// The worst row: every page at the longest URL and five 40-character rules, every counter at
// ten digits, and 30 days likewise. Measured, not estimated.
$worst = get_option( ADVCM_Freshness::STATS );

foreach ( $worst['pages'] as $url => $page ) {
	foreach ( $page as $key => $value ) {
		if ( ! is_array( $value ) ) {
			$worst['pages'][ $url ][ $key ] = 9999999999;
		}
	}
}

for ( $d = 0; $d < ADVCM_Freshness::STATS_DAYS; $d++ ) {
	$worst['days'][ gmdate( 'Y-m-d', time() - $d * DAY_IN_SECONDS ) ] = array( 'n' => 9999999999, 'unk' => 9999999999, 'young' => 9999999999, 'over' => 9999999999, 'max' => 9999999999 );
}

$size = strlen( serialize( $worst ) );

check( 'the stats row\'s worst case stays under 500 KB, with room to spare', $size < 480 * 1024, round( $size / 1024 ) . ' KB' );

// Times apart, so "longest ago" is a real choice: page 1 was cleared last, page 2 first.
$seed = array();

for ( $i = 1; $i <= 200; $i++ ) {
	$seed[ 'https://example.test/s' . $i . '/' ] = array( 'n' => 1, 'hand' => 1, 'auto' => 0, 'unk' => 0, 'young' => 1, 'over' => 0, 'max' => 10, 'sum' => 10, 'hsum' => 10, 'hk' => 1, 'last' => 1 === $i ? time() : 1000 + $i, 'rules' => array() );
}

$GLOBALS['options'][ ADVCM_Freshness::STATS ] = array( 'pages' => $seed, 'days' => array() );
$GLOBALS['ages'] = array( 10 );
ADVCM_Freshness::measure( job( 'e1', array( 'https://example.test/new/' ) ) );

$pages = ADVCM_Freshness::stats()['pages'];

check( 'the page that goes is the one cleared longest ago, not the first one in the row', ! isset( $pages['https://example.test/s2/'] ) && isset( $pages['https://example.test/s1/'] ) && isset( $pages['https://example.test/new/'] ) );

$GLOBALS['options'][ ADVCM_Freshness::STATS ]['days'] = array(
	gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS ) => array( 'n' => 5, 'over' => 5, 'young' => 0, 'unk' => 0, 'max' => 0 ),
	gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS )  => array( 'n' => 1, 'over' => 0, 'young' => 0, 'unk' => 0, 'max' => 0 ),
);
$GLOBALS['ages'] = array( 10 );
ADVCM_Freshness::measure( job( 'd0', pages( 1 ) ) );

check( 'a day older than 30 calendar days goes, however few days had clears since', array( gmdate( 'Y-m-d', time() - 2 * DAY_IN_SECONDS ), gmdate( 'Y-m-d' ) ) === array_keys( ADVCM_Freshness::stats()['days'] ) );

$days = array();

for ( $d = 0; $d < 40; $d++ ) {
	$days[ gmdate( 'Y-m-d', time() - $d * DAY_IN_SECONDS ) ] = array( 'n' => 1, 'over' => 0, 'young' => 0, 'unk' => 0, 'max' => 0 );
}

$GLOBALS['options'][ ADVCM_Freshness::STATS ]['days'] = $days;
$GLOBALS['ages'] = array( 10 );
ADVCM_Freshness::measure( job( 'd1', pages( 1 ) ) );

check( 'and the last 30 days', ADVCM_Freshness::STATS_DAYS === count( ADVCM_Freshness::stats()['days'] ) );

finish();
