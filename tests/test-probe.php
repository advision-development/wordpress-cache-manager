<?php
/**
 * How old a page's cache is, from the headers the layers send — and never a number they did not.
 *
 * The headers below are the ones WP Engine sent for /analysis/ on a staging install on
 * 2026-10-01, plus Cloudflare's, which does send Age.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'stages', 'jobs', 'urls', 'probe' ) as $class ) {
	load_class( $class );
}

$now = 1790000000;
$url = 'https://example.test/analysis/';

$wpe_hit  = array( 'x-cache' => 'HIT: 6', 'x-cacheable' => 'YES:3600.000', 'cache-control' => 'max-age=3600, must-revalidate' );
$wpe_miss = array( 'x-cache' => 'MISS', 'x-cacheable' => 'YES:3600.000', 'cache-control' => 'max-age=3600, must-revalidate' );

// ------------------------------------------------------------------------------ exact

$r = ADVCM_Probe::read( $url, 200, $wpe_hit + array( 'age' => '68' ), 580, null, $now );

check( 'an Age header is the age, exactly', 68 === $r['age']['seconds'] && 'exact' === $r['age']['how'] );
check( 'and what is left is the lifetime minus it', 3532 === $r['left'], (string) $r['left'] );
check( 'WP Engine\'s lifetime is read from x-cacheable', 3600 === $r['max_ttl'] );
check( 'and how many times the copy was served', 6 === $r['hits'] );

$r = ADVCM_Probe::read( $url, 200, $wpe_miss, 2410, null, $now );

check( 'a MISS is a copy this request just built: 0 seconds', 0 === $r['age']['seconds'] && 'exact' === $r['age']['how'] );

// ---------------------------------------------------------------------------- at most

$r = ADVCM_Probe::read( $url, 200, $wpe_hit, 580, $now - 68, $now );

check( 'with no Age, a HIT after this plugin cleared the page is at most the time since the clear', 68 === $r['age']['seconds'] && 'at most' === $r['age']['how'] );
check( 'and says that is where the number comes from', false !== strpos( $r['age']['source'], 'last clear by this plugin' ) );

$r = ADVCM_Probe::read( $url, 200, $wpe_hit, 580, $now - 7200, $now );

check( 'but a clear older than the lifetime says nothing about this copy', null === $r['age']['seconds'] && 'unknown' === $r['age']['how'] );

// ---------------------------------------------------------------------------- unknown

$r = ADVCM_Probe::read( $url, 200, $wpe_hit, 580, null, $now );

check( 'a HIT with no Age and no clear on record is unknown, not a guess', null === $r['age']['seconds'] && 'unknown' === $r['age']['how'] );
check( 'and still shows the most it can be', 3600 === $r['max_ttl'] && ! isset( $r['left'] ) );

// ----------------------------------------------------------------------- several layers

$r = ADVCM_Probe::read( $url, 200, array( 'cf-cache-status' => 'HIT', 'age' => '120', 'x-cache' => 'MISS', 'cache-control' => 'max-age=600' ), 90, null, $now );

check( 'each layer that answered is named', isset( $r['layers']['Cloudflare'], $r['layers']['host cache'] ) );
check( 'a HIT at the edge is a hit for the visitor, whatever the host says behind it', 120 === $r['age']['seconds'] );
check( 'and the lifetime comes from Cache-Control when the host does not say', 600 === $r['max_ttl'] );

$r = ADVCM_Probe::read( $url, 200, array( 'x-nitro-cache' => 'HIT' ), 100, null, $now );

check( 'NitroPack\'s own header is read', 'HIT' === $r['layers']['NitroPack'] );

$r = ADVCM_Probe::read( $url, 401, array(), 30, null, $now );

check( 'a page no layer says anything about is unknown', 'unknown' === $r['age']['how'] && array() === $r['layers'] );

// ----------------------------------------------------------------- what counts as a clear

$GLOBALS['options'][ ADVCM_Jobs::OPTION ] = array(
	'j3' => array( 'id' => 'j3', 'scope' => 'urls', 'urls' => array( 'https://example.test/other/' ), 'created' => 300, 'finished' => 301, 'steps' => array( array( 'stage' => ADVCM_Stages::HOST, 'status' => 'ok' ) ) ),
	'j2' => array( 'id' => 'j2', 'scope' => 'urls', 'urls' => array( $url ), 'created' => 200, 'finished' => 201, 'steps' => array( array( 'stage' => ADVCM_Stages::OBJECT, 'status' => 'ok' ), array( 'stage' => ADVCM_Stages::HOST, 'status' => 'failed' ) ) ),
	'j1' => array( 'id' => 'j1', 'scope' => 'all', 'urls' => array(), 'created' => 100, 'finished' => 150, 'steps' => array( array( 'stage' => ADVCM_Stages::HOST, 'status' => 'ok' ) ) ),
);

check( 'the last clear of a page is the newest job that cleared a page cache for it', 150 === ADVCM_Probe::last_cleared( $url ), (string) ADVCM_Probe::last_cleared( $url ) );
check( 'a job for other pages does not count', 301 !== ADVCM_Probe::last_cleared( $url ) );
check( 'nor one whose page caches failed', 201 !== ADVCM_Probe::last_cleared( $url ) );

// ------------------------------------------------------------------------------ words

check( 'seconds read as seconds', '68 s' === ADVCM_Probe::duration( 68 ) );
check( 'minutes as minutes', '58 min 52 s' === ADVCM_Probe::duration( 3532 ) );
check( 'hours as hours', '2 h 5 min' === ADVCM_Probe::duration( 7500 ) );

finish();
