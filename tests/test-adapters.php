<?php
/**
 * Each adapter: absent without its vendor, present with it, and calling exactly what it should.
 *
 * The absent half runs first, before any vendor stand-in exists, because a function cannot be
 * undefined again — the only way to show `detect()` answers "not installed" is to ask it while
 * that is true.
 *
 * @package ADVCM
 */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function url_to_postid( $url ) {
	return 'https://example.test/analysis/' === $url ? 12 : 0;
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'stages', 'adapter', 'urls', 'adapter-elementor', 'adapter-bricks', 'adapter-object-cache', 'adapter-wp-engine', 'adapter-nitropack', 'adapter-wp-rocket', 'adapter-wp-rocket-assets' ) as $class ) {
	load_class( $class );
}

$elementor = new ADVCM_Adapter_Elementor();
$bricks    = new ADVCM_Adapter_Bricks();
$object    = new ADVCM_Adapter_Object_Cache();
$wpe       = new ADVCM_Adapter_Wp_Engine();
$nitro     = new ADVCM_Adapter_Nitropack();
$rocket    = new ADVCM_Adapter_Wp_Rocket();
$assets    = new ADVCM_Adapter_Wp_Rocket_Assets();

$all = array( $elementor, $bricks, $object, $wpe, $nitro, $rocket, $assets );

// ------------------------------------------------------------- nothing installed

foreach ( $all as $adapter ) {
	$detected = $adapter->detect();

	check( $adapter->id() . ' reads as absent when its vendor is not here', false === $detected['present'], $detected['reason'] );
}

// ------------------------------------------------------------- the stages are right

check( 'Elementor is a builder stage', ADVCM_Stages::BUILDER === $elementor->stage() );
check( 'WP Rocket\'s files come before any page cache', ADVCM_Stages::ASSETS === $assets->stage() );
check( 'the object cache comes before the page caches', ADVCM_Stages::OBJECT === $object->stage() );
check( 'WP Rocket\'s page cache comes before the host\'s', ADVCM_Stages::PAGE === $rocket->stage() && $rocket->stage() < $wpe->stage() );
check( 'and the host\'s before NitroPack, which reads the origin through it', $wpe->stage() < $nitro->stage() );

// ------------------------------------------------------------- with the vendors

require __DIR__ . '/fixtures/vendors.php';

foreach ( $all as $adapter ) {
	$detected = $adapter->detect();

	check( $adapter->id() . ' is present with its vendor', true === $detected['present'], $detected['reason'] );
}

/**
 * The names of the vendor functions called since the last reset.
 *
 * @return string[]
 */
function called() {
	$names = array_map( function ( $call ) { return $call[0]; }, $GLOBALS['vendor_calls'] );
	$GLOBALS['vendor_calls'] = array();

	return $names;
}

called();

// ------------------------------------------------------------------------ NitroPack

$result = $nitro->clear( 'all', array(), array() );

check( 'a site-wide NitroPack clear invalidates by default', array( 'nitropack_sdk_invalidate' ) === called() && 'ok' === $result['status'] );

$nitro->clear( 'all', array(), array( 'nitropack_mode' => 'purge' ) );

check( 'and purges only when asked', array( 'nitropack_sdk_purge' ) === called() );

$result = $nitro->clear( 'urls', array( 'https://example.test/a/', 'https://example.test/b/' ), array() );
$calls  = $GLOBALS['vendor_calls'];

check( 'a per-URL clear purges each URL', array( 'nitropack_sdk_purge', 'nitropack_sdk_purge' ) === called() );
check( 'with the URL it was given', 'https://example.test/a/' === $calls[0][1][0] );

$every = array();
foreach ( array( array( 'all', array(), array() ), array( 'all', array(), array( 'nitropack_mode' => 'purge' ) ), array( 'urls', array( 'https://example.test/a/' ), array() ) ) as $args ) {
	$nitro->clear( $args[0], $args[1], $args[2] );
	$every = array_merge( $every, called() );
}

check(
	'and never through nitropack_purge() or nitropack_invalidate(), which also invalidate the home page and every archive',
	! in_array( 'nitropack_purge', $every, true ) && ! in_array( 'nitropack_invalidate', $every, true ),
	implode( ',', $every )
);

$GLOBALS['nitropack_answer'] = function ( $url ) {
	return 'https://example.test/b/' !== $url;
};

$result = $nitro->clear( 'urls', array( 'https://example.test/a/', 'https://example.test/b/' ), array() );
called();

check( 'a URL NitroPack refuses makes the step partial, and is named', 'partial' === $result['status'] && false !== strpos( $result['message'], '/b/' ), $result['message'] );

$GLOBALS['nitropack_answer'] = false;

$threw = false;
try {
	$nitro->clear( 'all', array(), array() );
} catch ( RuntimeException $e ) {
	$threw = true;
}
called();

check( 'an SDK answer of false is a failure, not an ok', $threw );

$GLOBALS['nitropack_answer']    = true;
$GLOBALS['nitropack_connected'] = false;

$detected = $nitro->detect();

check( 'a NitroPack that is installed but not connected is absent, and says so', false === $detected['present'] && 'not connected' === $detected['reason'], $detected['reason'] );

$GLOBALS['nitropack_connected'] = true;

// ------------------------------------------------------------------------ WP Engine

$wpe->clear( 'all', array(), array() );
$names = called();

check( 'WP Engine site-wide purges its page cache', array( 'WpeCommon::purge_varnish_cache' ) === $names, implode( ',', $names ) );
check( 'and does not flush memcached a second time after the object-cache stage', ! in_array( 'WpeCommon::purge_memcached', $names, true ) );

$result = $wpe->clear( 'urls', array( 'https://example.test/analysis/', 'https://example.test/category/x/' ), array() );
$calls  = $GLOBALS['vendor_calls'];
called();

check( 'per URL it purges the post each URL resolves to', 1 === count( $calls ) && 12 === $calls[0][1][0] );
check( 'and a URL that is not a post is reported, not escalated to a full purge', 'partial' === $result['status'], $result['message'] );

// ------------------------------------------------------------------------ object cache

$object->clear( 'all', array(), array() );

check( 'the object cache flushes site-wide', array( 'wp_cache_flush' ) === called() );

$object->clear( 'urls', array( 'https://example.test/analysis/' ), array() );

check( 'and per URL cleans only that post, rather than flushing every page\'s cache', array( 'clean_post_cache' ) === called() );

$GLOBALS['ext_object_cache'] = false;

check( 'with no persistent backend there is nothing to clear, and it says so', 'no persistent object cache' === $object->detect()['reason'] );

$GLOBALS['ext_object_cache'] = true;

// ------------------------------------------------------------------------ Elementor

$elementor->clear( 'all', array(), array() );

check( 'Elementor clears through its own tool\'s operation', array( 'Elementor files_manager->clear_cache' ) === called() );
check( 'and is site-wide only', array( 'all' ) === $elementor->scopes() );

// ------------------------------------------------------------------------ Bricks

$result = $bricks->clear( 'all', array(), array() );
$calls  = called();

check( 'Bricks is reported, not regenerated', 'skipped' === $result['status'], $result['status'] );
check(
	'and nothing reaches Bricks\' regenerate, whose first file deletes all the others',
	! in_array( 'Bricks\Assets_Files::regenerate_css_files', $calls, true ) && ! in_array( 'Bricks\Assets_Files::regenerate_css_file', $calls, true )
);

$GLOBALS['options']['bricks_global_settings']                   = array( 'cssLoading' => 'file' );
$GLOBALS['options']['bricks_css_files_last_generated_timestamp'] = 1790009279;

$info = $bricks->info();

check( 'its CSS loading method is reported', 'file' === $info['CSS loading'] );
check( 'and when it was last regenerated in full', '2026-09-21 16:47 UTC' === $info['last full regenerate'], $info['last full regenerate'] );

// ------------------------------------------------------------------------ WP Rocket

$rocket->clear( 'all', array(), array() );

check( 'WP Rocket site-wide cleans the domain', array( 'rocket_clean_domain' ) === called() );

$rocket->clear( 'urls', array( 'https://example.test/a/' ), array() );

check( 'and per URL only those files', array( 'rocket_clean_files' ) === called() );

$assets->clear( 'all', array(), array() );

check( 'its minified files are a separate, earlier step', array( 'rocket_clean_minify' ) === called() );

finish();
