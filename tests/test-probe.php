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

// ------------------------------------------------------------- WP Engine's own clock

$r = ADVCM_Probe::read( $url, 200, $wpe_hit + array( 'x-cache-ctime' => (string) ( $now - 5000 ) ), 580, $now - 68, $now );

check( 'x-cache-ctime is when the host cache stored the copy: the age, exactly', 5000 === $r['age']['seconds'] && 'exact' === $r['age']['how'], (string) $r['age']['seconds'] );
check( 'and says where it came from', false !== strpos( $r['age']['source'], 'x-cache-ctime' ) );

$r = ADVCM_Probe::read( $url, 200, $wpe_hit + array( 'x-cache-ctime' => (string) ( $now + 60 ) ), 580, null, $now );

check( 'a ctime in the future is not believed', 'unknown' === $r['age']['how'] );

$r = ADVCM_Probe::read( $url, 200, $wpe_hit + array( 'x-cache-ctime' => 'soon' ), 580, null, $now );

check( 'nor one that is not a time', 'unknown' === $r['age']['how'] );

$r = ADVCM_Probe::read( $url, 200, $wpe_hit + array( 'age' => '68', 'x-cache-ctime' => (string) ( $now - 5000 ) ), 580, null, $now );

check( 'Age still wins: the layer in front answered first', 68 === $r['age']['seconds'] );

// ---------------------------------------------------------- NitroPack, in the page

$r = ADVCM_Probe::read( $url, 200, array(), 100, null, $now, "<script>window.NITROPACK_STATE='STALE';</script>" );

check( 'a page NitroPack served stale says so in its HTML', 'STALE' === $r['nitropack_state'] );

$r = ADVCM_Probe::read( $url, 200, array(), 100, null, $now, '<script>window.NITROPACK_STATE="FRESH";</script>' );

check( 'and fresh', 'FRESH' === $r['nitropack_state'] );

$r = ADVCM_Probe::read( $url, 200, $wpe_hit, 100, null, $now );

check( 'a site without NitroPack has no NitroPack state', null === $r['nitropack_state'] );

// --------------------------------------------------------- NitroPack, its own copy

check( 'a site without NitroPack has no NitroPack copy', null === ADVCM_Probe::nitropack_copy( $url ) );

/**
 * Stands in for NitroPack's Pagecache: the paths it would use, and a record of every call, so the
 * test can say the probe never asked it to do anything but name a file.
 */
class Stub_Pagecache {
	public $dir;
	public $invalidated = false;
	public $calls       = array();

	public function useInvalidated( $on ) {
		$this->calls[]     = 'useInvalidated';
		$this->invalidated = $on;
	}

	public function getUseInvalidated() {
		return $this->invalidated;
	}

	public function getCachefilePath( $suffix = '' ) {
		$this->calls[] = 'getCachefilePath';

		return $this->dir . ( $this->invalidated ? '_i' : '' ) . '/page.html' . ( $suffix ? '.' . $suffix : '' );
	}

	public function hasCache() {
		$this->calls[] = 'hasCache';

		return true;
	}
}

if ( true ) {
	// Declared here, not at the top, so the line above ran on a site where it does not exist.
	function get_nitropack_sdk( $site = null, $secret = null, $url = null ) {
		if ( 'throw' === $GLOBALS['np_sdk'] ) {
			throw new Error( 'NitroPack is half removed' );
		}

		return $GLOBALS['np_sdk'];
	}
}

$dir = sys_get_temp_dir() . '/advcm-np-' . getmypid();
@mkdir( $dir );
@mkdir( $dir . '_i' );

$cache      = new Stub_Pagecache();
$cache->dir = $dir;

$GLOBALS['np_sdk'] = (object) array( 'pageCache' => $cache );

check( 'NitroPack with no copy of the page: none', null === ADVCM_Probe::nitropack_copy( $url ) );

touch( $dir . '_i/page.html.stale', $now - 900 );

$copy = ADVCM_Probe::nitropack_copy( $url );

check( 'an invalidated copy is read as stale, with its file time', is_array( $copy ) && 'stale' === $copy['state'] && $now - 900 === $copy['at'] );

touch( $dir . '/page.html.gz', $now - 300 );

$copy = ADVCM_Probe::nitropack_copy( $url );

check( 'a fresh copy wins over a stale one', is_array( $copy ) && 'fresh' === $copy['state'] && $now - 300 === $copy['at'] );
check( 'the probe only asks for file names: hasCache() would rename files', ! in_array( 'hasCache', $cache->calls, true ) );
check( 'and leaves NitroPack as it found it', false === $cache->invalidated );

$cache->invalidated = true;
ADVCM_Probe::nitropack_copy( $url );

check( 'even when it found it reading invalidated copies', true === $cache->invalidated );

$cache->invalidated = false;

$GLOBALS['np_sdk'] = 'throw';

check( 'NitroPack throwing is no copy, not an error', null === ADVCM_Probe::nitropack_copy( $url ) );

$GLOBALS['np_sdk'] = null;

check( 'nor is a NitroPack with no SDK yet', null === ADVCM_Probe::nitropack_copy( $url ) );

foreach ( array( '/page.html.gz', '_i/page.html.stale' ) as $file ) {
	@unlink( $dir . $file );
}

@rmdir( $dir );
@rmdir( $dir . '_i' );

// ------------------------------------------------------------------------------ words

check( 'seconds read as seconds', '68 s' === ADVCM_Probe::duration( 68 ) );
check( 'minutes as minutes', '58 min 52 s' === ADVCM_Probe::duration( 3532 ) );
check( 'hours as hours', '2 h 5 min' === ADVCM_Probe::duration( 7500 ) );

finish();
