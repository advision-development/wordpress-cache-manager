<?php
/**
 * The warm-up: what it requests, as whom, how far it gets, and that it never leaves the site.
 *
 * @package ADVCM
 */

class WP_Error {
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['requests'] = array();
$GLOBALS['answer']   = function ( $url ) {
	return 200;
};

function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['requests'][] = array( $url, $args['user-agent'] );
	$code                  = call_user_func( $GLOBALS['answer'], $url );

	return 0 === $code ? new WP_Error() : array( 'response' => array( 'code' => $code ) );
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

$GLOBALS['posts'] = array( 11 => 'https://example.test/newest/', 12 => 'https://attacker.test/filtered/', 13 => 'https://example.test/older/' );

function get_posts( $args ) {
	return array_slice( array_keys( $GLOBALS['posts'] ), 0, $args['posts_per_page'] );
}

function get_permalink( $id ) {
	return $GLOBALS['posts'][ $id ];
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'stages', 'adapter', 'urls', 'adapter-warm' ) as $class ) {
	load_class( $class );
}

$warm = new ADVCM_Adapter_Warm();

// --------------------------------------------------------------------------- per URL

$result = $warm->clear( 'urls', array( 'https://example.test/a/', 'https://example.test/b/' ), array( 'warm_batch' => 4 ) );

check( 'each page is requested as desktop and as mobile', 4 === count( $GLOBALS['requests'] ), (string) count( $GLOBALS['requests'] ) );
check( 'because the caches in front keep a copy per device', false !== strpos( $GLOBALS['requests'][1][1], 'iPhone' ) && false === strpos( $GLOBALS['requests'][0][1], 'iPhone' ) );
check( 'and every request names this plugin, for logs, firewalls and scanners', 4 === count( array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r[1], 'AdvisionCacheWarm/' ); } ) ) );
check( 'and it is ok', 'ok' === $result['status'], $result['message'] );

// --------------------------------------------------------------------------- the site

$GLOBALS['requests'] = array();

$warm->clear( 'all', array(), array( 'warm_site' => 3, 'warm_batch' => 2 ) );

$asked = array_values( array_unique( array_map( function ( $r ) { return $r[0]; }, $GLOBALS['requests'] ) ) );

check( 'a site-wide warm-up starts with the home page', 'https://example.test/' === $asked[0], implode( ' ', $asked ) );
check( 'then the most recently updated posts', in_array( 'https://example.test/newest/', $asked, true ) && in_array( 'https://example.test/older/', $asked, true ) );
check( 'and never an address off this site, whatever a permalink filter returned', ! in_array( 'https://attacker.test/filtered/', $asked, true ) );

$result = $warm->clear( 'all', array(), array( 'warm_site' => 0 ) );

check( 'a mode that warms no pages still warms the home page', 'ok' === $result['status'] );

// ------------------------------------------------------------------------ what came back

$GLOBALS['requests'] = array();
$GLOBALS['answer']   = function ( $url ) {
	return false !== strpos( $url, '/gone/' ) ? 404 : ( false !== strpos( $url, '/down/' ) ? 0 : 200 );
};

$result = $warm->clear( 'urls', array( 'https://example.test/a/', 'https://example.test/gone/', 'https://example.test/down/' ), array() );

check( 'a page that answers 404 or not at all makes it partial', 'partial' === $result['status'] );
check( 'and is named', false !== strpos( $result['message'], '/gone/ (404)' ) && false !== strpos( $result['message'], '/down/ (no answer)' ), $result['message'] );

$GLOBALS['answer'] = function ( $url ) {
	return 200;
};

// --------------------------------------------------------------------------- the cursor

$GLOBALS['requests'] = array();

$urls   = array( 'https://example.test/1/', 'https://example.test/2/', 'https://example.test/3/' );
$result = $warm->clear( 'urls', $urls, array( 'warm_batch' => 2, 'warm_budget' => 0 ) );

check( 'past its budget it hands back a cursor instead of running on', 'continue' === $result['status'] && 2 === $result['carry']['cursor'], json_encode( $result ) );
check( 'having done one batch', 2 === count( $GLOBALS['requests'] ) );
check( 'and carrying the list it started with', $urls === $result['carry']['targets'] );

$result = $warm->clear( 'urls', array( 'https://example.test/other/' ), array( 'warm_batch' => 10, 'carry' => $result['carry'] ) );

check( 'the next call picks up at the cursor, from the carried list', 'ok' === $result['status'] && 6 === count( $GLOBALS['requests'] ), (string) count( $GLOBALS['requests'] ) );
check( 'and never re-requests what was done', 2 === count( array_filter( $GLOBALS['requests'], function ( $r ) { return 'https://example.test/1/' === $r[0]; } ) ) );
check( 'or what it was not asked for this time', 0 === count( array_filter( $GLOBALS['requests'], function ( $r ) { return false !== strpos( $r[0], '/other/' ); } ) ) );

$GLOBALS['requests'] = array();
$started             = microtime( true );

$warm->clear( 'urls', array( 'https://example.test/1/', 'https://example.test/2/' ), array( 'warm_batch' => 1, 'warm_delay' => 200 ) );

check( 'batches are spaced by the mode\'s delay, so a rate limit in front does not see a burst', microtime( true ) - $started >= 0.55, sprintf( '%.2fs', microtime( true ) - $started ) );

finish();
