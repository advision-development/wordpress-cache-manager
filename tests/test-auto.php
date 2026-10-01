<?php
/**
 * Auto-clear: which saves mark a rule, that a burst is one clear, what the clear asks for, who may
 * write a rule and what a rule may name.
 *
 * @package ADVCM
 */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function sanitize_key( $key ) {
	return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $key ) );
}

function sanitize_title( $title ) {
	return trim( preg_replace( '~[^a-z0-9]+~', '-', strtolower( (string) $title ) ), '-' );
}

function taxonomy_exists( $taxonomy ) {
	return in_array( $taxonomy, array( 'category', 'post_tag' ), true );
}

$GLOBALS['stub_terms'] = array(
	'category' => array( 'analysis' => array( 7, 'Analysis' ), 'news' => array( 8, 'News' ) ),
);

function get_term_by( $field, $value, $taxonomy ) {
	foreach ( isset( $GLOBALS['stub_terms'][ $taxonomy ] ) ? $GLOBALS['stub_terms'][ $taxonomy ] : array() as $slug => $term ) {
		if ( ( 'slug' === $field && $slug === $value ) || ( 'name' === $field && $term[1] === $value ) ) {
			return (object) array( 'term_id' => $term[0], 'name' => $term[1] );
		}
	}

	return false;
}

// Terms by post id. A post the suite marks as "throws" makes has_term() throw, to show the save
// survives a fault in the hook.
$GLOBALS['post_terms'] = array();

function has_term( $term, $taxonomy, $post ) {
	if ( ! empty( $GLOBALS['has_term_throws'] ) ) {
		throw new RuntimeException( 'has_term broke' );
	}

	return in_array( $term, isset( $GLOBALS['post_terms'][ $post->ID ] ) ? $GLOBALS['post_terms'][ $post->ID ] : array(), true );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'auto' ) as $class ) {
	load_class( $class );
}

/**
 * A layer that records what each clear asked of it.
 */
class Recording_Adapter extends ADVCM_Adapter {

	public $asked = array();

	public function id() {
		return 'nitropack';
	}

	public function label() {
		return 'NitroPack';
	}

	public function stage() {
		return ADVCM_Stages::OPTIMIZER;
	}

	public function detect() {
		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		$this->asked[] = array( $scope, $urls, $options );

		return $this->ok( '' );
	}
}

$layer = new Recording_Adapter();
ADVCM_Runner::use_adapters( array( $layer ) );

/**
 * A post.
 *
 * @param int    $id     Id.
 * @param string $status Status.
 * @param string $type   Type.
 * @param int[]  $terms  Term ids.
 * @return object
 */
function post( $id, $status = 'publish', $type = 'post', array $terms = array() ) {
	$GLOBALS['post_terms'][ $id ] = $terms;

	return (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => $type );
}

/**
 * Store rules as the screen would, by id.
 *
 * @param array $rules id => rule.
 * @return void
 */
function rules( array $rules ) {
	update_option( ADVCM_Auto::RULES, $rules );
}

/**
 * The marks waiting, by rule id.
 *
 * @return string[]
 */
function marks() {
	$out = array();

	foreach ( array_keys( $GLOBALS['options'] ) as $name ) {
		if ( 0 === strpos( $name, ADVCM_Auto::MARK ) ) {
			$out[] = substr( $name, strlen( ADVCM_Auto::MARK ) );
		}
	}

	sort( $out );

	return $out;
}

/**
 * When the clear is scheduled, or false.
 *
 * @return int|false
 */
function flush_at() {
	return wp_next_scheduled( ADVCM_Auto::FLUSH_HOOK );
}

/**
 * Back to nothing waiting.
 *
 * @return void
 */
function reset_waiting() {
	foreach ( marks() as $id ) {
		delete_option( ADVCM_Auto::MARK . $id );
	}

	wp_clear_scheduled_hook( ADVCM_Auto::FLUSH_HOOK );
}

$analysis = array(
	'enabled'   => true,
	'post_type' => 'post',
	'taxonomy'  => 'category',
	'term'      => 7,
	'term_name' => 'Analysis',
	'urls'      => array( 'https://example.test/analysis/' ),
	'nitropack' => 'invalidate',
);

// ------------------------------------------------------------------- nothing until a rule

ADVCM_Auto::saved( 1, post( 1, 'publish', 'post', array( 7 ) ), false, null );

check( 'with no rules a publish marks nothing and schedules nothing', array() === marks() && false === flush_at() );

rules( array( 'a1' => array_merge( $analysis, array( 'enabled' => false ) ) ) );
ADVCM_Auto::saved( 1, post( 1, 'publish', 'post', array( 7 ) ), false, null );

check( 'nor with a rule that is switched off', array() === marks() && false === flush_at() );

// ---------------------------------------------------------------- which saves mark a rule

rules( array( 'a1' => $analysis ) );

ADVCM_Auto::saved( 1, post( 1, 'draft', 'post', array( 7 ) ), false, null );
check( 'a draft saved marks nothing: no listing shows it', array() === marks() );

ADVCM_Auto::saved( 1, post( 1, 'publish', 'revision', array( 7 ) ), false, null );
check( 'nor a revision', array() === marks() );

ADVCM_Auto::saved( 1, post( 1, 'publish', 'page', array( 7 ) ), false, null );
check( 'nor a post of another type', array() === marks() );

ADVCM_Auto::saved( 1, post( 1, 'publish', 'post', array( 8 ) ), false, null );
check( 'nor a post outside the rule\'s term', array() === marks() );

$before = time();
ADVCM_Auto::saved( 1, post( 1, 'publish', 'post', array( 7 ) ), false, null );
$first = flush_at();

check( 'a post published in the term marks the rule', array( 'a1' ) === marks() );
check( 'and schedules one clear, a window from now, never in the save', false !== $first && $first >= $before + ADVCM_Auto::WINDOW && $first <= time() + ADVCM_Auto::WINDOW, (string) $first );

ADVCM_Auto::saved( 2, post( 2, 'publish', 'post', array( 7 ) ), false, null );
ADVCM_Auto::saved( 3, post( 3, 'publish', 'post', array( 7 ) ), true, post( 3, 'publish', 'post', array( 7 ) ) );

check( 'more posts in the window are the same mark and the same clear, not one each', array( 'a1' ) === marks() && $first === flush_at() );

reset_waiting();
ADVCM_Auto::saved( 4, post( 4, 'trash', 'post', array( 7 ) ), true, post( 4, 'publish', 'post', array( 7 ) ) );
check( 'a published post taken down marks it too: the listing still shows it', array( 'a1' ) === marks() );

reset_waiting();
ADVCM_Auto::saved( 5, post( 5, 'draft', 'post', array( 7 ) ), true, post( 5, 'draft', 'post', array( 7 ) ) );
check( 'a draft updated as a draft does not', array() === marks() );

rules( array( 'any' => array_merge( $analysis, array( 'taxonomy' => '', 'term' => 0 ) ) ) );
ADVCM_Auto::saved( 6, post( 6, 'publish', 'post', array() ), false, null );
check( 'a rule with no term matches every post of its type', array( 'any' ) === marks() );
reset_waiting();

// ------------------------------------------------------------------------- the save survives

$GLOBALS['callbacks'] = array();
ADVCM_Auto::register();
rules( array( 'a1' => $analysis ) );
$GLOBALS['has_term_throws'] = true;

$threw = false;

try {
	foreach ( $GLOBALS['callbacks']['wp_after_insert_post'] as $cb ) {
		call_user_func( $cb, 9, post( 9, 'publish', 'post', array( 7 ) ), false, null );
	}
} catch ( Throwable $e ) {
	$threw = true;
}

$GLOBALS['has_term_throws'] = false;

check( 'a fault inside the hook never reaches the save that fired it', ! $threw && ! empty( $GLOBALS['callbacks']['wp_after_insert_post'] ) );
check( 'and it is recorded for the screen', is_array( get_option( ADVCM_Safe::LAST_ERROR ) ) && false !== strpos( get_option( ADVCM_Safe::LAST_ERROR )['what'], 'has_term broke' ) );
check( 'the clear has a callback on every request, so its event is never orphaned', ! empty( $GLOBALS['callbacks'][ ADVCM_Auto::FLUSH_HOOK ] ) );

// ------------------------------------------------------------------------------- the clear

rules(
	array(
		'a1' => $analysis,
		'b2' => array_merge( $analysis, array( 'term' => 8, 'urls' => array( 'https://example.test/news/', 'https://example.test/analysis/' ) ) ),
		'c3' => array_merge( $analysis, array( 'term' => 0, 'taxonomy' => '', 'urls' => array( 'https://example.test/not-triggered/' ) ) ),
	)
);
reset_waiting();
add_option( ADVCM_Auto::MARK . 'a1', time() );
add_option( ADVCM_Auto::MARK . 'b2', time() );

$job = ADVCM_Auto::flush();

check( 'the clear is one per-URL job over every waiting rule', is_array( $job ) && 'urls' === $job['scope'] );
check( 'naming each URL once, and only the waiting rules\' URLs', is_array( $job ) && array( 'https://example.test/analysis/', 'https://example.test/news/' ) === $job['urls'], is_array( $job ) ? implode( ', ', $job['urls'] ) : '' );
check( 'recorded as an auto-clear, by nobody', 'auto' === $job['source'] && 0 === $job['by'] );
check( 'run in the cron request itself, which is already the background', 'fast' === $job['mode'] && 'done' === $job['state'], $job['state'] );
check( 'with NitroPack invalidated, not purged', 'invalidate' === $layer->asked[0][2]['nitropack_mode'] );
check( 'and the marks are taken off', array() === marks() );
check( 'and nothing is left to clear the next time', null === ADVCM_Auto::flush() );

add_option( ADVCM_Auto::MARK . 'a1', time() );
rules( array( 'a1' => array_merge( $analysis, array( 'enabled' => false ) ) ) );

check( 'a rule switched off while it waited clears nothing', null === ADVCM_Auto::flush() && array() === marks() );

add_option( ADVCM_Auto::MARK . 'a1', time() );
rules( array( 'a1' => array_merge( $analysis, array( 'nitropack' => 'purge' ) ) ) );
$layer->asked = array();
ADVCM_Auto::flush();

check( 'a rule may ask for a purge', 'purge' === $layer->asked[0][2]['nitropack_mode'] );

add_option( ADVCM_Auto::MARK . 'a1', time() );
rules( array( 'a1' => array_merge( $analysis, array( 'urls' => array( 'https://attacker.test/' ) ) ) ) );
$layer->asked = array();

check( 'a URL no longer on this site is never cleared, whatever the stored rule says', null === ADVCM_Auto::flush() && array() === $layer->asked );

// ------------------------------------------------------------------------ writing a rule

$r = ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'taxonomy' => 'category', 'term' => 'analysis', 'urls' => "/analysis/\n/analysis/" ) );

check( 'a rule names a type, a term by slug, and this site\'s URLs once each', is_array( $r ) && 7 === $r['term'] && array( 'https://example.test/analysis/' ) === $r['urls'] );
check( 'and is added switched off unless asked', is_array( $r ) && false === $r['enabled'] );
check( 'and invalidates NitroPack unless it asks to purge', is_array( $r ) && 'invalidate' === $r['nitropack'] );

$r = ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'taxonomy' => 'category', 'term' => 'Analysis', 'urls' => '/a/', 'nitropack' => array( 'purge' ) ) );
check( 'a term by name too; and an answer that is not "purge" is invalidate', is_array( $r ) && 7 === $r['term'] && 'invalidate' === $r['nitropack'] );

check( 'a URL off this site is refused, not dropped', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'urls' => "/a/\nhttps://attacker.test/" ) ) ) );
check( 'a rule with no URL is refused', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'urls' => '' ) ) ) );
check( 'so is a post type the site does not have', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'nope', 'urls' => '/a/' ) ) ) );
check( 'and a term it does not have', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'taxonomy' => 'category', 'term' => 'nope', 'urls' => '/a/' ) ) ) );
check( 'and a term in a taxonomy it does not have', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'taxonomy' => 'nope', 'term' => 'analysis', 'urls' => '/a/' ) ) ) );

$many = '';
for ( $i = 0; $i <= ADVCM_Auto::MAX_URLS; $i++ ) {
	$many .= '/p' . $i . "/\n";
}

check( 'a rule may name at most ' . ADVCM_Auto::MAX_URLS . ' URLs', is_string( ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'urls' => $many ) ) ) );
check( 'and one that clears a whole site is not a rule: there is no such field', ! array_key_exists( 'scope', (array) ADVCM_Auto::rule_from( array( 'post_type' => 'post', 'urls' => '/a/', 'scope' => 'all' ) ) ) );

// --------------------------------------------------------------------- changing the rules

rules( array() );
reset_waiting();

check( 'adding a rule stores it', '' === ADVCM_Auto::change( array( 'op' => 'add', 'post_type' => 'post', 'urls' => '/analysis/', 'enabled' => '1' ) ) && 1 === count( ADVCM_Auto::rules() ) );

$id = array_keys( ADVCM_Auto::rules() )[0];

check( 'switching it off', '' === ADVCM_Auto::change( array( 'op' => 'disable', 'rule' => $id ) ) && false === ADVCM_Auto::rules()[ $id ]['enabled'] );
check( 'and on', '' === ADVCM_Auto::change( array( 'op' => 'enable', 'rule' => $id ) ) && true === ADVCM_Auto::rules()[ $id ]['enabled'] );

add_option( ADVCM_Auto::MARK . $id, time() );

check( 'deleting it takes its waiting mark with it', '' === ADVCM_Auto::change( array( 'op' => 'delete', 'rule' => $id ) ) && array() === ADVCM_Auto::rules() && array() === marks() );
check( 'a change to a rule that does not exist is refused', is_string( ADVCM_Auto::change( array( 'op' => 'enable', 'rule' => 'gone' ) ) ) && '' !== ADVCM_Auto::change( array( 'op' => 'whatever' ) ) );

for ( $i = 0; $i < ADVCM_Auto::MAX_RULES; $i++ ) {
	ADVCM_Auto::change( array( 'op' => 'add', 'post_type' => 'post', 'urls' => '/p' . $i . '/' ) );
}

check( 'a site may have at most ' . ADVCM_Auto::MAX_RULES . ' rules', ADVCM_Auto::MAX_RULES === count( ADVCM_Auto::rules() ) && '' !== ADVCM_Auto::change( array( 'op' => 'add', 'post_type' => 'post', 'urls' => '/more/' ) ) );

update_option( ADVCM_Auto::RULES, array( 'x' => 'junk', 'y' => array( 'post_type' => 'post' ), 'z' => array( 'post_type' => 'post', 'urls' => 'not a list' ) ) );
check( 'a malformed row in the option is dropped on the read, not trusted', array() === ADVCM_Auto::rules() );

$code = file_get_contents( ADVCM_DIR . 'includes/class-auto.php' );
check( 'only the stronger capability may write rules', 1 === preg_match( '~function save\(\)\s*\{\s*if \( ! current_user_can\( ADVCM_Capabilities::HARD \) \)~', $code ) && false !== strpos( $code, 'check_admin_referer( self::SAVE )' ) );
check( 'and the hook is on wp_after_insert_post, after terms are saved, never transition_post_status', false !== strpos( $code, "ADVCM_Safe::action( 'wp_after_insert_post'" ) && false === strpos( $code, "action( 'transition_post_status'" ) );

// ---------------------------------------------------------------- the history keeps room

update_option( ADVCM_Jobs::OPTION, array() );
$GLOBALS['options'][ ADVCM_Jobs::OPTION ] = array();

ADVCM_Jobs::save( array( 'id' => 'manual', 'source' => 'wp-admin', 'state' => 'done', 'finished' => 1 ) );

for ( $i = 0; $i < 6; $i++ ) {
	ADVCM_Jobs::save( array( 'id' => 'auto' . $i, 'source' => 'auto', 'state' => 'done', 'finished' => 1 ) );
}

$kept = array_keys( ADVCM_Jobs::all() );
check( 'the history keeps the last ' . ADVCM_Jobs::AUTO_KEEP . ' auto-clears, so a busy day of publishing cannot push out a clear somebody pressed', array( 'auto5', 'auto4', 'auto3', 'manual' ) === $kept, implode( ',', $kept ) );

ADVCM_Jobs::save( array( 'id' => 'auto-running', 'source' => 'auto', 'state' => 'running' ) );
ADVCM_Jobs::save( array( 'id' => 'auto9', 'source' => 'auto', 'state' => 'done', 'finished' => 1 ) );
ADVCM_Jobs::save( array( 'id' => 'auto10', 'source' => 'auto', 'state' => 'done', 'finished' => 1 ) );
ADVCM_Jobs::save( array( 'id' => 'auto11', 'source' => 'auto', 'state' => 'done', 'finished' => 1 ) );

check( 'and never drops a running one', null !== ADVCM_Jobs::get( 'auto-running' ) && null !== ADVCM_Jobs::get( 'manual' ) );

// -------------------------------------------------------------------- NitroPack per URL

require __DIR__ . '/fixtures/vendors.php';
load_class( 'adapter-nitropack' );

$nitro = new ADVCM_Adapter_Nitropack();

$GLOBALS['vendor_calls'] = array();
$nitro->clear( 'urls', array( 'https://example.test/analysis/' ), array( 'nitropack_mode' => 'invalidate' ) );
check( 'NitroPack: an auto-clear invalidates exactly its URL', array( array( 'nitropack_sdk_invalidate', array( 'https://example.test/analysis/' ) ) ) === $GLOBALS['vendor_calls'] );

$GLOBALS['vendor_calls'] = array();
$nitro->clear( 'urls', array( 'https://example.test/analysis/' ), array() );
check( 'and a page somebody clears is still purged', array( array( 'nitropack_sdk_purge', array( 'https://example.test/analysis/' ) ) ) === $GLOBALS['vendor_calls'] );

// --------------------------------------------------------------- WP-CLI keeps the name

define( 'WP_CLI', true );

$j = ADVCM_Runner::start( array( 'scope' => 'urls', 'urls' => array( 'https://example.test/a/' ), 'source' => 'auto' ) );
check( 'an auto-clear run by a cron that WP-CLI drives is still an auto-clear', 'auto' === $j['source'] );

$j = ADVCM_Runner::start( array( 'scope' => 'urls', 'urls' => array( 'https://example.test/a/' ), 'source' => 'wp-admin', 'by' => 5 ) );
check( 'while anything else from WP-CLI is WP-CLI', 'wp-cli' === $j['source'] && 0 === $j['by'] );

finish();
