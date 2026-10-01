<?php
/**
 * Which URLs a per-URL request may name.
 *
 * These reach NitroPack's API and WP Rocket's cache paths, so every refusal here is a URL that
 * would otherwise be this site asking a vendor to act on somebody else's address.
 *
 * @package ADVCM
 */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['postids'] = array(
	'https://www.example.test/analysis/'      => 12,
	'https://www.example.test/nfl/draft/'     => 34,
);

function url_to_postid( $url ) {
	return isset( $GLOBALS['postids'][ $url ] ) ? $GLOBALS['postids'][ $url ] : 0;
}

function home_url() {
	return 'https://www.example.test';
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'urls' );

$home = 'https://www.example.test';

/**
 * Parse one entry and return what it became, or null when refused.
 *
 * @param string $line Entry.
 * @return string|null
 */
function one( $line ) {
	$parsed = ADVCM_Urls::parse( $line, 'https://www.example.test' );

	return empty( $parsed['accepted'] ) ? null : $parsed['accepted'][0];
}

// ---------------------------------------------------------------------------- accepted

check( 'a path is made absolute on this site', 'https://www.example.test/analysis/' === one( '/analysis/' ), (string) one( '/analysis/' ) );
check( 'an absolute URL on this site is kept', 'https://www.example.test/analysis/' === one( 'https://www.example.test/analysis/' ) );
check( 'the bare host is the same site', 'https://www.example.test/analysis/' === one( 'https://example.test/analysis/' ), (string) one( 'https://example.test/analysis/' ) );
check( 'and http is rewritten to the site\'s own scheme', 'https://www.example.test/a/' === one( 'http://www.example.test/a/' ) );
check( 'a query is kept, because it can be a different cached page', 'https://www.example.test/odds/?sport=nfl' === one( '/odds/?sport=nfl' ), (string) one( '/odds/?sport=nfl' ) );
check( 'a fragment is dropped, because no cache keys on it', 'https://www.example.test/a/' === one( '/a/#top' ), (string) one( '/a/#top' ) );
check( 'the home page is a path too', 'https://www.example.test/' === one( '/' ) );

$on_port = ADVCM_Urls::parse( "/a/\nhttp://localhost:8089/b/\nhttp://localhost/c/\nhttp://localhost:9999/d/", 'http://localhost:8089' );

check( 'a site on a port keeps its port on a path', 'http://localhost:8089/a/' === $on_port['accepted'][0], $on_port['accepted'][0] );
check( 'and on an absolute URL naming it', 'http://localhost:8089/b/' === $on_port['accepted'][1], $on_port['accepted'][1] );
check( 'a URL naming no port is taken as the site\'s, like one naming no scheme', 'http://localhost:8089/c/' === $on_port['accepted'][2], $on_port['accepted'][2] );
check( 'and the same host on another explicit port is refused', array( 'http://localhost:9999/d/' ) === $on_port['refused'], implode( ' ', $on_port['refused'] ) );

// ---------------------------------------------------------------------------- refused

check( 'another site is refused', null === one( 'https://attacker.test/analysis/' ) );
check( 'including one that ends with this host', null === one( 'https://notexample.test/' ) );
check( 'and one that contains it', null === one( 'https://www.example.test.attacker.test/' ) );
check( 'a protocol-relative URL is not a path', null === one( '//attacker.test/x' ) );
check( 'a scheme other than http(s) is refused', null === one( 'javascript:alert(1)' ) );
check( 'and ftp', null === one( 'ftp://www.example.test/a' ) );
check( 'credentials in a URL are refused', null === one( 'https://user:pass@www.example.test/a/' ) );
check( 'a word is not a URL', null === one( 'analysis' ) );

// ------------------------------------------------------------------------- dot segments

check( 'dot segments never reach a vendor', 'https://www.example.test/b/' === one( '/a/../b/' ), (string) one( '/a/../b/' ) );
check( 'including a trailing one', 'https://www.example.test/' === one( '/a/..' ), (string) one( '/a/..' ) );
check( 'and one that climbs past the root stays on the site', 'https://www.example.test/etc/' === one( '/../../etc/' ), (string) one( '/../../etc/' ) );
check( 'and a single dot', 'https://www.example.test/a/b' === one( '/a/./b' ), (string) one( '/a/./b' ) );

// ------------------------------------------------------------------------------ a list

$parsed = ADVCM_Urls::parse( "/a/\n\n  /b/  \r\nhttps://attacker.test/\n/a/", $home );

check( 'blank lines are ignored and whitespace trimmed', 2 === count( $parsed['accepted'] ), implode( ' ', $parsed['accepted'] ) );
check( 'a duplicate is cleared once', 2 === count( array_unique( $parsed['accepted'] ) ) );
check( 'and a refusal is named, not dropped', array( 'https://attacker.test/' ) === $parsed['refused'] );

$many   = implode( "\n", array_map( function ( $i ) { return '/p' . $i . '/'; }, range( 1, ADVCM_Urls::MAX + 3 ) ) );
$parsed = ADVCM_Urls::parse( $many, $home );

check( 'a list longer than the limit is cut to it', ADVCM_Urls::MAX === count( $parsed['accepted'] ) );
check( 'and what was cut is named', 3 === count( $parsed['refused'] ) && false !== strpos( $parsed['refused'][0], 'over the limit' ), $parsed['refused'][0] );

$long   = '/' . str_repeat( 'a', ADVCM_Urls::MAX_LENGTH );
$parsed = ADVCM_Urls::parse( $long, $home );

check( 'a URL longer than any page has is refused', array() === $parsed['accepted'] && false !== strpos( $parsed['refused'][0], 'too long' ) );
check( 'and stored short', strlen( $parsed['refused'][0] ) < 120 );

$parsed = ADVCM_Urls::parse( implode( "\n", array_fill( 0, 500, 'https://attacker.test/x' ) ), $home );

check( 'refusals are kept to a bounded few, with a count of the rest', ADVCM_Urls::MAX_REFUSED + 1 === count( $parsed['refused'] ) && false !== strpos( end( $parsed['refused'] ), '480 more' ), end( $parsed['refused'] ) );

// ------------------------------------------------------------------------ post ids

$GLOBALS['options']['page_on_front'] = 99;

$ids = ADVCM_Urls::post_ids(
	array(
		'https://www.example.test/analysis/',
		'https://www.example.test/category/nfl/',
		'https://www.example.test/',
		'https://www.example.test/analysis/',
	)
);

check( 'a URL resolves to its post', in_array( 12, $ids, true ) );
check( 'an archive resolves to nothing', ! in_array( 0, $ids, true ) );
check( 'the home page resolves to the page set as the front page', in_array( 99, $ids, true ) );
check( 'and a post named twice is one id', 2 === count( $ids ), implode( ',', $ids ) );

finish();
