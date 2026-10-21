<?php
/**
 * The NitroPack reader: what NitroPack keeps for a page, per device, read only from the paths its
 * own SDK names, and never an error where something is missing.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/stubs-nitropack.php';

foreach ( array( 'safe', 'stages', 'jobs', 'urls', 'probe', 'optimizer-nitropack' ) as $class ) {
	load_class( $class );
}

$now = time();
$url = 'https://example.test/usa/texas/';

// ------------------------------------------------------------------- not on this site

check( 'a site without NitroPack: not available, and says so', false === ADVCM_Optimizer_Nitropack::available() && '' !== ADVCM_Optimizer_Nitropack::why_not() );
check( 'and has no copies, no counts, no devices', array() === ADVCM_Optimizer_Nitropack::copies( $url ) && array() === ADVCM_Optimizer_Nitropack::folder_counts() && array() === ADVCM_Optimizer_Nitropack::devices() );

// ---------------------------------------------------------------------- a cache on disk

$dir = sys_get_temp_dir() . '/advcm-np-' . getmypid() . '-' . mt_rand();
@mkdir( $dir . '/desktop', 0777, true );
@mkdir( $dir . '/mobile', 0777, true );

ADVCM_Optimizer_Nitropack::use_sdk( new Stub_Nitro_Sdk( $dir ) );

check( 'with an SDK and its cache directory: available', true === ADVCM_Optimizer_Nitropack::available(), ADVCM_Optimizer_Nitropack::why_not() );
check( 'the devices are the SDK\'s own', array( 'mobile', 'tablet', 'desktop' ) === ADVCM_Optimizer_Nitropack::devices() );
check( 'and the expiry is the config\'s', array( 'expire' => 86400, 'stale' => 300 ) === ADVCM_Optimizer_Nitropack::expire() );

nitro_copy( $dir, 'desktop', $url, 'fresh', $now - 3600 );
nitro_copy( $dir, 'mobile', $url, 'stale', $now - 7200 );

$copies = ADVCM_Optimizer_Nitropack::copies( $url );

check( 'a fresh copy: created at its file\'s time, lives until created + expiry', 'fresh' === $copies['desktop']['state'] && $now - 3600 === $copies['desktop']['created'] && $now - 3600 + 86400 === $copies['desktop']['lives_until'] );
check( 'a stale copy is stale', 'stale' === $copies['mobile']['state'] && $now - 7200 === $copies['mobile']['created'] );
check( 'a device with no folder, or no copy, is none', 'none' === $copies['tablet']['state'] && null === $copies['tablet']['created'] );

nitro_copy( $dir, 'tablet', $url, 'invalidated', $now - 60 );

check( 'an invalidated copy (the _i folder) is invalidated', 'invalidated' === ADVCM_Optimizer_Nitropack::copies( $url )['tablet']['state'] );
check( 'the folder is the SDK\'s md5 of the normalized URL', is_dir( $dir . '/desktop/' . md5( $url ) ) );

// A newer .stale beside an older page in the same folder: NitroPack serves the page.
nitro_copy( $dir, 'desktop', $url, 'stale', $now - 60 );
// And one named so it is listed first (a cookie variant), newer still.
$variant = dirname( nitro_copy( $dir, 'desktop', $url, 'fresh', $now - 3600 ) ) . '/aaa-variant.html.stale';
file_put_contents( $variant, 'x' );
touch( $variant, $now - 30 );

check( 'a fresh page outranks a newer stale file in its folder', 'fresh' === ADVCM_Optimizer_Nitropack::copies( $url )['desktop']['state'] && $now - 3600 === ADVCM_Optimizer_Nitropack::copies( $url )['desktop']['created'] );

$counts = ADVCM_Optimizer_Nitropack::folder_counts();

check( 'folder counts per device, invalidated apart', 1 === $counts['desktop']['folders'] && 1 === $counts['tablet']['invalidated'] && 0 === $counts['tablet']['folders'], json_encode( $counts ) );

// Only gzipped copies in a folder: NitroPack keeps .gz beside its pages, and a copy is a copy.
$gz = $dir . '/desktop/' . md5( 'https://example.test/gz/' );
@mkdir( $gz, 0777, true );
file_put_contents( $gz . '/ssl-0.html.gz', 'x' );
touch( $gz . '/ssl-0.html.gz', $now - 500 );
$gzs = $dir . '/mobile/' . md5( 'https://example.test/gz/' );
@mkdir( $gzs, 0777, true );
file_put_contents( $gzs . '/ssl-0.html.stale.gz', 'x' );
touch( $gzs . '/ssl-0.html.stale.gz', $now - 600 );

$copies = ADVCM_Optimizer_Nitropack::copies( 'https://example.test/gz/' );

check( 'a gzipped copy is a copy, a gzipped stale one stale', 'fresh' === $copies['desktop']['state'] && $now - 500 === $copies['desktop']['created'] && 'stale' === $copies['mobile']['state'] );

$sdir = sys_get_temp_dir() . '/advcm-np2-' . getmypid() . '-' . mt_rand();
@mkdir( $sdir . '/desktop', 0777, true );
ADVCM_Optimizer_Nitropack::use_sdk( new Stub_Nitro_Sdk( $sdir ) );
$c2 = ADVCM_Optimizer_Nitropack::folder_counts();

check( 'a device with no folder is said to be missing, not counted', 'missing' === $c2['mobile']['state'] && 0 === $c2['mobile']['folders'] && 'ok' === $c2['desktop']['state'] && 0 === $c2['desktop']['folders'], json_encode( $c2 ) );

exec( 'rm -rf ' . escapeshellarg( $sdir ) );
ADVCM_Optimizer_Nitropack::use_sdk( new Stub_Nitro_Sdk( $dir ) );

// ------------------------------------------------------------- what the probe keeps using

check( 'the probe\'s NitroPack copy goes through the reader: desktop, fresh', array( 'at' => $now - 3600, 'state' => 'fresh' ) === ADVCM_Probe::nitropack_copy( $url ) );

// ------------------------------------------------------------------- broken in every way

$broken = new Stub_Nitro_Sdk( $dir . '/nope' );
ADVCM_Optimizer_Nitropack::use_sdk( $broken );

check( 'a cache directory that does not exist: not available, says which', false === ADVCM_Optimizer_Nitropack::available() && false !== stripos( ADVCM_Optimizer_Nitropack::why_not(), 'cache' ) );
check( 'and copies answer nothing rather than fail', array() === ADVCM_Optimizer_Nitropack::copies( $url ) );

ADVCM_Optimizer_Nitropack::use_sdk(
	new class() {
		public function getCacheDir() {
			throw new Error( 'NitroPack half removed' );
		}

		public function getConfig() {
			throw new Error( 'NitroPack half removed' );
		}
	}
);

check( 'an SDK that throws: not available, no error out', false === ADVCM_Optimizer_Nitropack::available() && array() === ADVCM_Optimizer_Nitropack::copies( $url ) && array() === ADVCM_Optimizer_Nitropack::folder_counts() );

ADVCM_Optimizer_Nitropack::use_sdk( new stdClass() );

check( 'an SDK without the methods it needs: not available, and says why', false === ADVCM_Optimizer_Nitropack::available() && false !== strpos( ADVCM_Optimizer_Nitropack::why_not(), 'does not say where' ) );

$version3 = new Stub_Nitro_Sdk( $dir, 86400, 300, 3 );
ADVCM_Optimizer_Nitropack::use_sdk( $version3 );

check( 'a URL path version it does not know: not available, rather than reading the wrong folders', false === ADVCM_Optimizer_Nitropack::available() && false !== stripos( ADVCM_Optimizer_Nitropack::why_not(), 'version' ) );

ADVCM_Optimizer_Nitropack::use_sdk( null );

exec( 'rm -rf ' . escapeshellarg( $dir ) );

finish();
