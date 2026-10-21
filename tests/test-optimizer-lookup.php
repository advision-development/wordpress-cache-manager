<?php
/**
 * What only NitroPack's API knows — pages waiting to rebuild, the last purge, a page's tags, the
 * pages showing a post — asked only when a person asks, cached ten minutes, ten a minute per
 * person, and an API that fails or is missing is a message, never an error.
 *
 * @package ADVCM
 */

$GLOBALS['transients'] = array();
$GLOBALS['can']        = true;
$GLOBALS['nonce_ok']   = true;

function get_transient( $name ) {
	return isset( $GLOBALS['transients'][ $name ] ) ? $GLOBALS['transients'][ $name ] : false;
}

function set_transient( $name, $value, $ttl = 0 ) {
	$GLOBALS['transients'][ $name ] = $value;
	$GLOBALS['ttl'][ $name ]        = $ttl;

	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['transients'][ $name ] );

	return true;
}

function current_user_can( $cap, $id = 0 ) {
	// Post 13 is private: a person who may clear but not read it.
	return 'read_post' === $cap ? 13 !== (int) $id : $GLOBALS['can'];
}

function check_ajax_referer( $action, $arg = false, $die = true ) {
	return $GLOBALS['nonce_ok'] && 'advcm_optimizer_lookup' === $action;
}

class Sent extends Exception {
	public $ok;
	public $data;
	public $status;

	public function __construct( $ok, $data, $status ) {
		parent::__construct( 'sent' );
		$this->ok     = $ok;
		$this->data   = $data;
		$this->status = $status;
	}
}

function wp_send_json_success( $data = null, $status = null ) {
	throw new Sent( true, $data, $status );
}

function wp_send_json_error( $data = null, $status = null ) {
	throw new Sent( false, $data, $status );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function wp_unslash( $value ) {
	return $value;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

function get_post( $id ) {
	$posts = array(
		10 => array( 'Old news', '2026-01-01 10:00:00' ),
		11 => array( 'Fresh news', '2026-10-20 10:00:00' ),
		12 => array( 'This page', '2025-05-05 10:00:00' ),
	);

	return isset( $posts[ $id ] ) ? (object) array( 'ID' => $id, 'post_title' => $posts[ $id ][0], 'post_date_gmt' => $posts[ $id ][1] ) : null;
}

function get_permalink( $id ) {
	return 'https://example.test/p-' . $id . '/';
}

function url_to_postid( $url ) {
	return 'https://example.test/p-11/' === $url ? 11 : 0;
}

function get_term_by( $field, $value, $taxonomy = '' ) {
	return 500 === (int) $value ? (object) array( 'term_id' => 9, 'term_taxonomy_id' => 500, 'taxonomy' => 'post_tag', 'name' => 'Texas' ) : false;
}

function get_term_link( $term, $taxonomy = '' ) {
	return 'https://example.test/tag/texas/';
}

function get_userdata( $id ) {
	return 3 === (int) $id ? (object) array( 'ID' => 3, 'display_name' => 'Ana Writer' ) : false;
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/stubs-nitropack.php';

foreach ( array( 'safe', 'urls', 'capabilities', 'log', 'optimizer-nitropack', 'optimizer-index', 'optimizer' ) as $class ) {
	load_class( $class );
}

/**
 * NitroPack's API as far as these views ask it; each answer a test sets, each call counted.
 */
class Stub_Nitro_Api {
	public $calls = array();
	public $fail  = false;
	public $tags  = array();
	public $pages = array();

	private function call( $name ) {
		$this->calls[] = $name;

		if ( $this->fail ) {
			throw new Exception( 'cURL error 28: Operation timed out' );
		}
	}

	public function getPendingUrlsCount( $priority = null ) {
		$this->call( 'pending' );

		return 6;
	}

	public function getLastCachePurge() {
		$this->call( 'purge' );

		return array( 'timestamp' => 1792500000, 'url' => null, 'tag' => 'pageType:archive', 'reason' => 'Updated Post \'' . str_repeat( 'x', 400 ) . '\'' );
	}

	public function getTags( $url ) {
		$this->call( 'tags ' . $url );

		return $this->tags;
	}

	public function getTaggedUrls( $tag, $page = 1, $limit = 250 ) {
		$this->call( 'tagged ' . $tag . ' ' . $limit );

		return $this->pages;
	}
}

$dir = sys_get_temp_dir() . '/advcm-npl-' . getmypid() . '-' . mt_rand();
@mkdir( $dir . '/desktop', 0777, true );

// ------------------------------------------------------------------- not on this site

ADVCM_Optimizer_Nitropack::use_sdk( null );

$summary = ADVCM_Optimizer::summary();

check( 'a site without NitroPack: the summary says it is not available', false === $summary['available'] && '' !== $summary['why'] );
$look = ADVCM_Optimizer::lookup( 'tags', 'https://example.test/p-12/', 7 );

check( 'and a lookup is refused with why, not counted against the person', false === $look['ok'] && ADVCM_Optimizer_Nitropack::why_not() === $look['error'] && false === get_transient( ADVCM_Optimizer::RATE . '7' ) );

// ---------------------------------------------------------------------------- summary

$sdk      = new Stub_Nitro_Sdk( $dir );
$api      = new Stub_Nitro_Api();
$sdk->api = $api;
ADVCM_Optimizer_Nitropack::use_sdk( $sdk );
$GLOBALS['transients'] = array();

$summary = ADVCM_Optimizer::summary();

check( 'the summary: pages waiting to rebuild', 6 === $summary['pending'] && '' === $summary['error'] );
check( 'the last purge, when, which tag, why', 1792500000 === $summary['purge']['at'] && 'pageType:archive' === $summary['purge']['tag'] && 0 === strpos( $summary['purge']['reason'], 'Updated Post' ) );
check( 'its reason cut to 200 characters', 200 >= mb_strlen( $summary['purge']['reason'] ) );
check( 'the expiry in force and the copies on disk, read locally', 86400 === $summary['expire']['expire'] && isset( $summary['counts']['desktop'] ) );
check( 'cached ten minutes', 600 === $GLOBALS['ttl'][ ADVCM_Optimizer::SUMMARY ] );

$before = count( $api->calls );
ADVCM_Optimizer::summary();

check( 'a second look asks NitroPack nothing', $before === count( $api->calls ) );

$GLOBALS['transients'] = array();
$api->fail             = true;
$summary               = ADVCM_Optimizer::summary();

check( 'an API that fails: the summary says NitroPack did not answer, and why', false !== strpos( $summary['error'], 'NitroPack did not answer' ) && false !== strpos( $summary['error'], 'timed out' ) );
check( 'and still shows what is on disk', isset( $summary['counts']['desktop'] ) && null === $summary['pending'] );
check( 'a failure is kept a minute, not ten', 60 === $GLOBALS['ttl'][ ADVCM_Optimizer::SUMMARY ] );

$api->fail             = false;
$GLOBALS['transients'] = array();
$sdk->api              = null;

check( 'an SDK with no API: a message, no error', '' !== ADVCM_Optimizer::summary()['error'] );

$sdk->api = $api;

// ------------------------------------------------------------------------ a page's tags

$GLOBALS['transients'] = array();
$api->tags             = array( 'post:10', 'single:12', 'pageType:states', 'post:11', 'tax:500', 'author:3', 'post:999', 'something:new' );

$look = ADVCM_Optimizer::lookup( 'tags', 'https://example.test/p-12/', 7 );
$tags = $look['tags'];

check( 'a page\'s tags, asked once', $look['ok'] && 'tags https://example.test/p-12/' === end( $api->calls ) );
check( 'posts first, newest first, as their titles and links', 'post' === $tags[0]['kind'] && 'Fresh news' === $tags[0]['label'] && 'https://example.test/p-11/' === $tags[0]['url'] && 'Old news' === $tags[1]['label'], json_encode( $tags[0] ) );
check( 'a post since deleted says so', 'post' === $tags[2]['kind'] && false !== strpos( $tags[2]['label'], '999' ) );
check( 'single: is this page', 'single' === $tags[3]['kind'] && 'This page' === $tags[3]['label'] );
check( 'tax: is a term by term_taxonomy_id', 'term' === $tags[4]['kind'] && false !== strpos( $tags[4]['label'], 'Texas' ) && 'https://example.test/tag/texas/' === $tags[4]['url'] );
check( 'author: is the author\'s name, pageType: the type', 'author' === $tags[5]['kind'] && 'Ana Writer' === $tags[5]['label'] && 'type' === $tags[6]['kind'] && 'states' === $tags[6]['label'] );
check( 'a tag it does not know is shown as it is', 'other' === $tags[7]['kind'] && 'something:new' === $tags[7]['label'] );

$before = count( $api->calls );

check( 'the same lookup within ten minutes is the cached one', ADVCM_Optimizer::lookup( 'tags', 'https://example.test/p-12/', 7 )['ok'] && $before === count( $api->calls ) && 600 === $GLOBALS['ttl'][ ADVCM_Optimizer::LOOK . md5( 'tags|https://example.test/p-12/' ) ] );

check( 'a URL that is not this site\'s is refused, NitroPack not asked', false === ADVCM_Optimizer::lookup( 'tags', 'https://evil.test/', 7 )['ok'] && $before === count( $api->calls ) );
check( 'nor WordPress\'s own machinery', false === ADVCM_Optimizer::lookup( 'tags', 'https://example.test/wp-admin/', 7 )['ok'] && $before === count( $api->calls ) );
check( 'an unknown kind is refused', false === ADVCM_Optimizer::lookup( 'purge', '1', 7 )['ok'] && $before === count( $api->calls ) );

$api->tags             = array_map(
	function ( $i ) {
		return 'pageType:t' . $i;
	},
	range( 1, 300 )
);
$GLOBALS['transients'] = array();

check( 'at most 100 tags kept', ADVCM_Optimizer::TAGS_KEEP === count( ADVCM_Optimizer::lookup( 'tags', 'https://example.test/many/', 7 )['tags'] ) );

// ----------------------------------------------------------------- pages showing a post

$GLOBALS['transients'] = array();
$api->pages            = array(
	array( 'url' => 'https://example.test/', 'deviceType' => 'desktop', 'lastOptimizedDate' => '2026-10-21 03:03:15', 'tags' => array() ),
	array( 'url' => 'https://example.test/', 'deviceType' => 'mobile', 'lastOptimizedDate' => '2026-10-21 04:00:00', 'tags' => array() ),
	array( 'url' => 'https://example.test/news/', 'deviceType' => 'desktop', 'lastOptimizedDate' => '2026-10-20 00:00:00', 'tags' => array() ),
);

$look = ADVCM_Optimizer::lookup( 'tagged', '11', 7 );

check( 'pages showing a post: asked by its post tag, at most 50', $look['ok'] && 'tagged post:11 50' === end( $api->calls ) );
check( 'one row per page, each device\'s copy time (UTC)', 2 === count( $look['pages'] ) && gmmktime( 3, 3, 15, 10, 21, 2026 ) === $look['pages'][0]['devices']['desktop'] && gmmktime( 4, 0, 0, 10, 21, 2026 ) === $look['pages'][0]['devices']['mobile'] );
check( 'and names the post', 'Fresh news' === $look['post']['title'] );

$GLOBALS['transients'] = array();
ADVCM_Optimizer::lookup( 'tagged', 'https://example.test/p-11/', 7 );

check( 'a post asked by its URL is its id', 'tagged post:11 50' === end( $api->calls ) );
check( 'a URL that is no post is refused', false === ADVCM_Optimizer::lookup( 'tagged', 'https://example.test/nothing/', 7 )['ok'] );

check( 'a post the person may not read is refused, its title not shown', false === ADVCM_Optimizer::lookup( 'tagged', '13', 7 )['ok'] );

$GLOBALS['transients'] = array();
$before                = count( $api->calls );
$look                  = ADVCM_Optimizer::lookup( 'summary', '', 7 );

check( 'the summary is a lookup too, so the tab never waits on NitroPack', $look['ok'] && 6 === $look['pending'] && 'pageType:archive' === $look['purge']['tag'] && false === get_transient( ADVCM_Optimizer::RATE . '7' ) );

// ---------------------------------------------------------------------------- failures

$GLOBALS['transients'] = array();
$api->fail             = true;
$look                  = ADVCM_Optimizer::lookup( 'tags', 'https://example.test/p-12/', 7 );

check( 'an API that fails: NitroPack did not answer, and why', false === $look['ok'] && false !== strpos( $look['error'], 'NitroPack did not answer' ) );
check( 'a failure is not cached', ! isset( $GLOBALS['transients'][ ADVCM_Optimizer::LOOK . md5( 'tags|https://example.test/p-12/' ) ] ) );

$api->fail = false;

// ------------------------------------------------------------------------- the rate

$GLOBALS['transients'] = array();
$asked                 = 0;

for ( $i = 1; $i <= 10; $i++ ) {
	$asked += ADVCM_Optimizer::lookup( 'tags', 'https://example.test/r-' . $i . '/', 7 )['ok'] ? 1 : 0;
}

$before = count( $api->calls );
$look   = ADVCM_Optimizer::lookup( 'tags', 'https://example.test/r-11/', 7 );

check( '10 lookups a minute per person', 10 === $asked && false === $look['ok'] && $before === count( $api->calls ) && '' !== $look['error'] );
check( 'another person has their own', ADVCM_Optimizer::lookup( 'tags', 'https://example.test/r-11/', 8 )['ok'] );
check( 'a cached answer costs nothing against the rate', ADVCM_Optimizer::lookup( 'tags', 'https://example.test/r-1/', 7 )['ok'] );

// ------------------------------------------------------------------------- the handler

$GLOBALS['transients'] = array();
$_POST                 = array( 'kind' => 'tags', 'value' => 'https://example.test/p-12/' );

function answer() {
	try {
		ADVCM_Optimizer::handle_lookup();
	} catch ( Sent $sent ) {
		return $sent;
	}

	return null;
}

$GLOBALS['can'] = false;
$sent           = answer();

check( 'a person who may not clear is refused, 403', false === $sent->ok && 403 === $sent->status );

$GLOBALS['can']      = true;
$GLOBALS['nonce_ok'] = false;
$sent                = answer();

check( 'without the nonce, 403', false === $sent->ok && 403 === $sent->status );

$GLOBALS['nonce_ok'] = true;
$sent                = answer();

check( 'with both, the answer', true === $sent->ok && true === $sent->data['ok'] && ! empty( $sent->data['tags'] ) );
check( 'registered for logged-in people only', in_array( 'wp_ajax_advcm_optimizer_lookup', ( function () {
	$GLOBALS['hooks'] = array();
	ADVCM_Optimizer::register();

	return $GLOBALS['hooks'];
} )(), true ) && ! in_array( 'wp_ajax_nopriv_advcm_optimizer_lookup', $GLOBALS['hooks'], true ) );

check( 'and Refresh now, through admin-post', in_array( 'admin_post_advcm_optimizer_refresh', $GLOBALS['hooks'], true ) );

// ------------------------------------------------------------------------- refresh now

$GLOBALS['transients'][ ADVCM_Optimizer::SUMMARY ] = array( 'at' => 1 );
$GLOBALS['options'][ ADVCM_Log::OPTION ]           = array();

check( 'Refresh now starts the oldest-copies scan and asks the summary again', '' === ADVCM_Optimizer::refresh( 7 ) && false !== wp_next_scheduled( ADVCM_Optimizer_Index::HOOK ) && ! isset( $GLOBALS['transients'][ ADVCM_Optimizer::SUMMARY ] ) );
check( 'and is recorded under the NitroPack tab\'s Last changes', 1 === count( ADVCM_Log::recent( 'optimizer' ) ) );
check( 'pressed again while it runs: refused, not recorded twice', '' !== ADVCM_Optimizer::refresh( 7 ) && 1 === count( ADVCM_Log::recent( 'optimizer' ) ) );

ADVCM_Optimizer_Nitropack::use_sdk( null );

check( 'without NitroPack: refused with why', ADVCM_Optimizer_Nitropack::why_not() === ADVCM_Optimizer::refresh( 7 ) );

exec( 'rm -rf ' . escapeshellarg( $dir ) );

finish();
