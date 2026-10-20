<?php
/**
 * Listings whose filter has matched nothing new for weeks: Elementor widgets read from their
 * documents, their term filters resolved as Elementor stores them (term_taxonomy_ids), and a scan
 * that runs in WP-Cron batches with a known worst case in time and storage.
 *
 * @package ADVCM
 */

$GLOBALS['autoload'] = array();
$GLOBALS['user_id']  = 7;
$GLOBALS['meta']     = array();
$GLOBALS['freed']    = array();

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['options'][ $name ]  = $value;
	$GLOBALS['autoload'][ $name ] = $autoload;

	return true;
}

function get_current_user_id() {
	return $GLOBALS['user_id'];
}

function wp_get_current_user() {
	return (object) array( 'ID' => $GLOBALS['user_id'], 'user_login' => 'ed' );
}

/*
 * Terms by term_taxonomy_id. Deliberately not equal to their term ids: the mistake made by hand
 * while investigating was reading these as term ids.
 */
$GLOBALS['terms'] = array(
	23364 => array( 'term_id' => 911, 'taxonomy' => 'post_tag', 'slug' => 'alabama', 'name' => 'Alabama' ),
	23365 => array( 'term_id' => 912, 'taxonomy' => 'post_tag', 'slug' => 'texas', 'name' => 'Texas' ),
	500   => array( 'term_id' => 23364, 'taxonomy' => 'category', 'slug' => 'betting-buzz', 'name' => 'Betting Buzz' ),
	600   => array( 'term_id' => 60, 'taxonomy' => 'sport', 'slug' => 'nfl', 'name' => 'NFL' ),
);

function get_term_by( $field, $value, $taxonomy = '' ) {
	if ( 'term_taxonomy_id' !== $field || ! isset( $GLOBALS['terms'][ (int) $value ] ) ) {
		return false;
	}

	return (object) ( $GLOBALS['terms'][ (int) $value ] + array( 'term_taxonomy_id' => (int) $value ) );
}

function is_object_in_taxonomy( $type, $taxonomy ) {
	return in_array( $taxonomy, array( 'post' => array( 'post_tag', 'category' ), 'states' => array( 'post_tag' ) )[ $type ] ?? array(), true );
}

function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['meta'][ $id ] ) ? $GLOBALS['meta'][ $id ] : '';
}

function wp_cache_delete( $id, $group = '' ) {
	$GLOBALS['freed'][] = $group . ':' . $id;

	return true;
}

function get_post( $id ) {
	if ( 666 === $id ) {
		throw new Error( 'a filter on get_post died' );
	}

	return (object) array( 'ID' => $id, 'post_title' => 'Page ' . $id, 'post_type' => 9 === $id ? 'elementor_library' : 'page' );
}

function get_permalink( $id ) {
	return 'https://example.test/page-' . $id . '/';
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'log', 'listings' ) as $class ) {
	load_class( $class );
}

$now = 1790000000;
$day = 86400;

/** The newest post each tax query finds, keyed by what the test expects to be asked. */
$GLOBALS['newest'] = array();
$GLOBALS['asked']  = array();

ADVCM_Listings::use_finder(
	function ( $post_type, array $tax_query ) {
		$key                = $post_type . ' ' . wp_json_encode( $tax_query );
		$GLOBALS['asked'][] = $key;

		return isset( $GLOBALS['newest'][ $key ] ) ? $GLOBALS['newest'][ $key ] : null;
	}
);

/**
 * An Elementor widget as _elementor_data holds it.
 */
function widget( $type, array $settings, $id = 'w1' ) {
	return array( 'id' => $id, 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
}

function doc( array $widgets ) {
	return wp_json_encode( array( array( 'id' => 's1', 'elType' => 'section', 'settings' => array(), 'elements' => array( array( 'id' => 'c1', 'elType' => 'column', 'settings' => array(), 'elements' => $widgets ) ) ) ) );
}

$alabama = array( 'posts_post_type' => 'post', 'posts_include' => array( 'terms' ), 'posts_include_term_ids' => array( '23364' ), 'classic_posts_per_page' => '6' );

// --------------------------------------------------------------------- finding them

$found = ADVCM_Listings::widgets( json_decode( doc( array( widget( 'posts', $alabama ), widget( 'heading', array() ), widget( 'posts', array( 'posts_post_type' => 'post' ), 'w2' ) ) ), true ) );

check( 'a posts widget with a term filter is a listing', 1 === count( $found ) && 'w1' === $found[0]['id'] && 'posts' === $found[0]['type'] );
check( 'nested in sections and columns', 'post' === $found[0]['post_type'] && array( 23364 ) === $found[0]['include_tt'] );
check( 'and says how many posts it shows', 6 === $found[0]['per_page'] );

$found = ADVCM_Listings::widgets( json_decode( doc( array( widget( 'loop-grid', array( 'post_query_include' => array( 'terms' ), 'post_query_include_term_ids' => array( 500 ), 'post_query_exclude' => array( 'terms' ), 'post_query_exclude_term_ids' => array( '23365' ), 'posts_per_page' => 9 ) ), widget( 'loop-carousel', array( 'post_query_include' => array( 'terms' ), 'post_query_include_term_ids' => array( '23364' ), 'post_query_query_id' => 'my_query' ), 'w3' ) ) ), true ) );

check( 'a loop grid reads the post_query_ settings, post by default', 'post' === $found[0]['post_type'] && array( 500 ) === $found[0]['include_tt'] && array( 23365 ) === $found[0]['exclude_tt'] && 9 === $found[0]['per_page'] );
check( 'a loop carousel too, with its custom query id', 'loop-carousel' === $found[1]['type'] && 'my_query' === $found[1]['query_id'] );

$found = ADVCM_Listings::widgets( json_decode( doc( array( widget( 'posts', array( 'posts_include' => array( 'authors' ), 'posts_include_term_ids' => array( '23364' ) ) ), widget( 'posts', array( 'posts_post_type' => 'by_id', 'posts_include' => array( 'terms' ), 'posts_include_term_ids' => array( '23364' ) ), 'w2' ) ) ), true ) );

check( 'term ids without "terms" in the include, or a hand-picked list, are not a term listing', array() === $found );
check( 'a document that is not Elementor data has none', array() === ADVCM_Listings::widgets( array( 'nope' ) ) );

// --------------------------------------------------------------------- judging them

$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ] = array( 'id' => 77, 'title' => 'Alabama sportsbooks', 'date' => $now - 40 * $day );

$w = ADVCM_Listings::widgets( json_decode( doc( array( widget( 'posts', $alabama ) ) ), true ) )[0];
$e = ADVCM_Listings::evaluate( $w, $now );

check( 'the filter is resolved by term_taxonomy_id and read in words', 'tag alabama' === $e['filter'], $e['filter'] );
check( 'the newest match is found', 77 === $e['newest']['id'] && 40 === $e['days'] );
check( 'over 30 days is red', 'red' === $e['verdict'] );

$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ]['date'] = $now - 10 * $day;

check( 'from 7 days, amber', 'amber' === ADVCM_Listings::evaluate( $w, $now )['verdict'] );

$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ]['date'] = $now - 2 * $day;

check( 'under 7, green', 'green' === ADVCM_Listings::evaluate( $w, $now )['verdict'] );

foreach ( array( 6 => 'green', 7 => 'amber', 30 => 'amber', 31 => 'red' ) as $days => $verdict ) {
	$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ]['date'] = $now - $days * $day;

	check( $days . ' days is ' . $verdict, $verdict === ADVCM_Listings::evaluate( $w, $now )['verdict'] );
}

$e = ADVCM_Listings::evaluate( array_merge( $w, array( 'include_tt' => array( 99999 ) ) ), $now );

check( 'a term that no longer exists is a broken filter, red', $e['broken'] && 'red' === $e['verdict'] );

$e = ADVCM_Listings::evaluate( array_merge( $w, array( 'include_tt' => array( 600 ) ) ), $now );

check( 'a taxonomy the post type does not have cannot match, red', $e['cannot_match'] && 'red' === $e['verdict'] );

$e = ADVCM_Listings::evaluate( array_merge( $w, array( 'include_tt' => array( 23365 ) ) ), $now );

check( 'a filter nothing matches at all is red', null === $e['newest'] && 'red' === $e['verdict'] );

$GLOBALS['asked'] = array();
$e                = ADVCM_Listings::evaluate( array_merge( $w, array( 'query_id' => 'my_query' ) ), $now );

check( 'a custom query is listed, not checked: a hook decides what it shows', $e['custom'] && 'unchecked' === $e['verdict'] && array() === $GLOBALS['asked'] );

$GLOBALS['asked'] = array();
ADVCM_Listings::evaluate( array_merge( $w, array( 'include_tt' => array( 23364, 23365, 500 ), 'exclude_tt' => array( 23365 ) ) ), $now );

check(
	'two tags and a category: IN within a taxonomy, AND across, excluded terms NOT IN — as Elementor builds it',
	array( 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364,23365],"operator":"IN"},{"taxonomy":"category","field":"term_taxonomy_id","terms":[500],"operator":"IN"},{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23365],"operator":"NOT IN"}]' ) === $GLOBALS['asked'],
	implode( ' | ', $GLOBALS['asked'] )
);

$e = ADVCM_Listings::evaluate( array_merge( $w, array( 'include_tt' => array( 23364, 500 ), 'exclude_tt' => array( 23365 ) ) ), $now );

check( 'and says so in words', 'tag alabama + category betting-buzz, not tag texas' === $e['filter'], $e['filter'] );

// ------------------------------------------------------------------------ the scan

/** Documents: id => _elementor_data. 30 of them, every third one a frozen Alabama listing. */
$GLOBALS['docs'] = array();

for ( $i = 1; $i <= 30; $i++ ) {
	$GLOBALS['docs'][ $i * 10 ] = 0 === $i % 3 ? doc( array( widget( 'posts', $alabama ) ) ) : doc( array( widget( 'heading', array() ) ) );
}

$GLOBALS['meta'] = $GLOBALS['docs'];

ADVCM_Listings::use_documents(
	function ( $after, $limit ) {
		$ids = array_values( array_filter( array_keys( $GLOBALS['docs'] ), function ( $id ) use ( $after ) {
			return $id > $after;
		} ) );

		return null === $limit ? count( $ids ) : array_slice( $ids, 0, $limit );
	}
);

$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ]['date'] = $now - 400 * $day;

check( 'a scan starts on a press', '' === ADVCM_Listings::start( 7 ) );

$state = ADVCM_Listings::state();

check( 'it counts the documents it will read, and starts at the first', 30 === $state['total'] && 0 === $state['cursor'] && 0 === $state['done'] && 7 === $state['by'] );
check( 'and runs in WP-Cron, not in the press', false !== wp_next_scheduled( ADVCM_Listings::HOOK ) && 0 === $state['scanned'] );
check( 'a second press while it runs is refused, with its progress', false !== strpos( ADVCM_Listings::start( 7 ), '0 of 30' ) );
check( 'the scan row is not autoloaded', false === $GLOBALS['autoload'][ ADVCM_Listings::OPTION ] );

wp_clear_scheduled_hook( ADVCM_Listings::HOOK );
ADVCM_Listings::run_batch( ADVCM_Listings::SCAN_BATCH, 8 );

$state = ADVCM_Listings::state();

check( 'a batch reads 20 documents and moves its cursor past them', 20 === $state['scanned'] && 200 === $state['cursor'], $state['scanned'] . ' / ' . $state['cursor'] );
check( 'and comes back for the rest', false !== wp_next_scheduled( ADVCM_Listings::HOOK ) && 0 === $state['done'] );
check( 'each document\'s meta is let go of after reading', in_array( 'post_meta:10', $GLOBALS['freed'], true ) && in_array( 'post_meta:200', $GLOBALS['freed'], true ) );
check( 'what it found so far is kept', 6 === count( $state['findings'] ) && 'red' === $state['findings'][0]['verdict'] && 'Page 30' === $state['findings'][0]['title'] );

wp_clear_scheduled_hook( ADVCM_Listings::HOOK );
ADVCM_Listings::run_batch( ADVCM_Listings::SCAN_BATCH, 8 );

$state = ADVCM_Listings::state();

check( 'the last batch finishes the scan', 30 === $state['scanned'] && $state['done'] > 0 && false === wp_next_scheduled( ADVCM_Listings::HOOK ) );
check( 'with every listing found', 10 === count( $state['findings'] ) && 10 === $state['listings'] );
check( 'a finished scan can be started again', '' === ADVCM_Listings::start( 7 ) && 0 === ADVCM_Listings::state()['scanned'] );

// The event is lost (a cron table wiped, a host that dropped it): the next press picks it up
// where it was, rather than refusing for ever or starting over.
wp_clear_scheduled_hook( ADVCM_Listings::HOOK );
ADVCM_Listings::run_batch( 5, 8 );
wp_clear_scheduled_hook( ADVCM_Listings::HOOK );

check( 'a scan whose event was lost resumes from its cursor on the next press', '' === ADVCM_Listings::start( 7 ) && 50 === ADVCM_Listings::state()['cursor'] && false !== wp_next_scheduled( ADVCM_Listings::HOOK ) );

// A document that kills the request (memory, a fatal in a filter) must not stop every scan after
// it: the lock names the document it was reading, and the next run steps over it.
$GLOBALS['options'][ ADVCM_Listings::LOCK ] = array( 'at' => time() - 600, 'doc' => 60 );
wp_clear_scheduled_hook( ADVCM_Listings::HOOK );
ADVCM_Listings::run_batch( 1, 8 );

check( 'a document that killed the run is stepped over', 70 === ADVCM_Listings::state()['cursor'] && 1 === ADVCM_Listings::state()['skipped'] );

$GLOBALS['options'][ ADVCM_Listings::LOCK ] = array( 'at' => time(), 'doc' => 0 );
$before                                     = ADVCM_Listings::state()['cursor'];
ADVCM_Listings::run_batch( 5, 8 );

check( 'and a run already going is not doubled', $before === ADVCM_Listings::state()['cursor'] );

unset( $GLOBALS['options'][ ADVCM_Listings::LOCK ] );

// ---------------------------------------------------------------------- the budget

ADVCM_Listings::start( 7 );

$clock = 0.0;
ADVCM_Listings::use_clock(
	function () use ( &$clock ) {
		$clock += 3;

		return $clock;
	}
);

ADVCM_Listings::run_batch( 20, 8 );

check( 'a batch stops at its time budget, wherever it is', ADVCM_Listings::state()['scanned'] < 20 && ADVCM_Listings::state()['scanned'] > 0, (string) ADVCM_Listings::state()['scanned'] );

ADVCM_Listings::use_clock( null );

// ---------------------------------------------------------------------- the tick

$GLOBALS['options'] = array();
ADVCM_Listings::start( 7 );

check( 'the screen\'s tick leaves a scan whose cron is on time alone', 0 === ADVCM_Listings::tick() );

$state            = get_option( ADVCM_Listings::OPTION );
$state['next_at'] = time() - 121;
update_option( ADVCM_Listings::OPTION, $state, false );

check( 'and runs a small batch of one whose cron is over 2 minutes late', ADVCM_Listings::TICK_BATCH === ADVCM_Listings::tick() );

$GLOBALS['options'] = array();

check( 'with no scan there is nothing for it to do', 0 === ADVCM_Listings::tick() );

// ------------------------------------------------------------------- a lost event

$GLOBALS['options'] = array();
ADVCM_Listings::start( 7 );
wp_clear_scheduled_hook( ADVCM_Listings::HOOK );

check( 'a scan whose event was just taken is not touched: its run may be starting', 0 === ADVCM_Listings::rescue() && false === wp_next_scheduled( ADVCM_Listings::HOOK ) );

$state            = get_option( ADVCM_Listings::OPTION );
$state['next_at'] = time() - ADVCM_Listings::LATE - 1;
update_option( ADVCM_Listings::OPTION, $state, false );

check( 'one with no event and nothing running for over 2 minutes is put back on the schedule', 1 === ADVCM_Listings::rescue() && false !== wp_next_scheduled( ADVCM_Listings::HOOK ) );
check( 'once', 0 === ADVCM_Listings::rescue() );

$GLOBALS['options'] = array();

check( 'with no scan, nothing', 0 === ADVCM_Listings::rescue() );

// ---------------------------------------------------------------------- the caps

$GLOBALS['docs'] = array();
$GLOBALS['meta'] = array();
$many            = array();

for ( $k = 1; $k <= 40; $k++ ) {
	$many[] = widget( 'posts', array_merge( $alabama, array( 'posts_include_term_ids' => array( (string) ( 23364 + ( $k % 2 ) ) ) ) ), 'w' . $k );
}

for ( $i = 1; $i <= 10; $i++ ) {
	$GLOBALS['docs'][ $i ] = doc( $many );
}

$GLOBALS['meta'] = $GLOBALS['docs'];
$GLOBALS['meta'][1] = doc( array( widget( 'posts', $alabama, 'green' ) ) );

$GLOBALS['newest'][ 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' ] = array( 'id' => 5, 'title' => str_repeat( 'Ж', 400 ), 'date' => time() - 1 * $day );

ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

$state = ADVCM_Listings::state();

check( 'green listings are counted, not kept', 181 === $state['green'] && 180 === count( $state['findings'] ), $state['green'] . ' / ' . count( $state['findings'] ) );

// Every listing red now, with a long title: 360 findings for a cap of 300.
$GLOBALS['newest'] = array();
$GLOBALS['meta']   = $GLOBALS['docs'];
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

$state = ADVCM_Listings::state();

check( 'more findings than the cap keep 300 and count the rest', ADVCM_Listings::LISTINGS_KEEP === count( $state['findings'] ) && 100 === $state['dropped'], count( $state['findings'] ) . ' / ' . $state['dropped'] );

$GLOBALS['newest'] = array( 'post [{"taxonomy":"post_tag","field":"term_taxonomy_id","terms":[23364],"operator":"IN"}]' => array( 'id' => 5, 'title' => str_repeat( 'Ж', 400 ), 'date' => time() - 100 * $day ) );
$GLOBALS['meta'][1] = doc( array( widget( 'posts', $alabama, 'long' ) ) );
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 1, 8 );

$long = ADVCM_Listings::state()['findings'][0]['newest']['title'];

check( 'titles are cut to 150 characters, on a character', ADVCM_Listings::TEXT_CHARS === mb_strlen( $long ), (string) mb_strlen( $long ) );

$GLOBALS['meta'] = $GLOBALS['docs'];
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

$size = strlen( serialize( get_option( ADVCM_Listings::OPTION ) ) );

check( 'the full row stays under 350 KB', $size < 350 * 1024, round( $size / 1024 ) . ' KB' );

// ---------------------------------------------------------------- a site without it

$GLOBALS['docs'] = array();
$GLOBALS['meta'] = array();
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

$state = ADVCM_Listings::state();

check( 'a site with no Elementor documents finishes at once with nothing found', $state['done'] > 0 && 0 === $state['total'] && array() === $state['findings'] );

$GLOBALS['meta'] = array( 666 => doc( array( widget( 'posts', $alabama ) ) ), 667 => doc( array( widget( 'posts', $alabama ) ) ) );
$GLOBALS['docs'] = $GLOBALS['meta'];
$GLOBALS['newest'] = array();
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

check( 'a document that throws an Error is read past, and the next one still read', ADVCM_Listings::state()['done'] > 0 && 2 === ADVCM_Listings::state()['scanned'] && 1 === count( ADVCM_Listings::state()['findings'] ) );

$GLOBALS['meta'] = array( 5 => '{not json' );
$GLOBALS['docs'] = $GLOBALS['meta'];
ADVCM_Listings::start( 7 );
ADVCM_Listings::run_batch( 20, 8 );

check( 'a document whose data does not parse is read past', ADVCM_Listings::state()['done'] > 0 && 1 === ADVCM_Listings::state()['scanned'] );

finish();
