<?php
/**
 * Auto-clear: which saves mark a rule, that a burst is one clear, what the clear asks for, who may
 * write a rule and what a rule may name. Since 0.3.1 also what a clear says triggered it, when each
 * rule last fired, "any content", subcategories and the pause.
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

function is_taxonomy_hierarchical( $taxonomy ) {
	return 'category' === $taxonomy;
}

// Children by taxonomy and parent id. post_tag has some too, though WordPress would not: it shows
// a rule on a flat taxonomy is never widened, whatever get_term_children() would answer.
$GLOBALS['term_children'] = array(
	'category' => array( 7 => array( 70, 71 ) ),
	'post_tag' => array( 30 => array( 31 ) ),
);

function get_term_children( $term, $taxonomy ) {
	return isset( $GLOBALS['term_children'][ $taxonomy ][ $term ] ) ? $GLOBALS['term_children'][ $taxonomy ][ $term ] : array();
}

// Post types by name: whether a visitor can view one, and whether it is registered public.
$GLOBALS['types'] = array(
	'post'              => array( true, true ),
	'page'              => array( true, true ),
	'review'            => array( true, true ),
	'attachment'        => array( true, true ),
	'elementor_library' => array( true, true ),
	'acf-field-group'   => array( true, true ),
	'wpforms_log'       => array( true, true ),
	'wpcode'            => array( true, true ),
	'bricks_template'   => array( true, true ),
	// Viewable and public here, as no real site has it, to show "any content" never covers it.
	'revision'          => array( true, true ),
	'queryable_only'    => array( true, false ),
	'public_unviewable' => array( false, true ),
	'hidden'            => array( false, false ),
);
$GLOBALS['stub_post_types'] = array_keys( $GLOBALS['types'] );

function is_post_type_viewable( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) && $GLOBALS['types'][ $type ][0];
}

// The type each post has now, for History's links; a post not listed is a post.
$GLOBALS['live_types'] = array();

function get_post_type( $id ) {
	return array_key_exists( $id, $GLOBALS['live_types'] ) ? $GLOBALS['live_types'][ $id ] : 'post';
}

function get_post_type_object( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) ? (object) array( 'name' => $type, 'public' => $GLOBALS['types'][ $type ][1] ) : null;
}

// Every option read, by name, so the cost of the save hook is an assertion and not a comment.
$GLOBALS['options']   = array();
$GLOBALS['reads']     = array();
$GLOBALS['additions'] = array();

function get_option( $name, $default = false ) {
	$GLOBALS['reads'][] = $name;

	return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	$GLOBALS['additions'][] = $name;

	if ( array_key_exists( $name, $GLOBALS['options'] ) ) {
		return false;
	}

	$GLOBALS['options'][ $name ] = $value;

	return true;
}

// Who is asking, for the rules form and History's links.
$GLOBALS['can']       = array();
$GLOBALS['can_edit']  = array();
$GLOBALS['referer']   = array();

function current_user_can( $cap, $id = 0 ) {
	if ( 'edit_post' === $cap ) {
		return in_array( (int) $id, $GLOBALS['can_edit'], true );
	}

	return ! empty( $GLOBALS['can'][ $cap ] );
}

class Died extends Exception {}
class Redirected extends Exception {}

function wp_die( $message = '' ) {
	throw new Died( (string) $message );
}

function wp_safe_redirect( $url ) {
	throw new Redirected( (string) $url );
}

function check_admin_referer( $action ) {
	$GLOBALS['referer'][] = $action;

	return 1;
}

function wp_unslash( $value ) {
	return $value;
}

function set_transient( $name, $value, $ttl = 0 ) {
	return true;
}

function add_query_arg( $args, $url = '' ) {
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_html__( $text, $domain = '' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
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

	// One id or a list, as WordPress takes either: true when the post has any of them.
	$has = isset( $GLOBALS['post_terms'][ $post->ID ] ) ? $GLOBALS['post_terms'][ $post->ID ] : array();

	return array() !== array_intersect( (array) $term, $has );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'auto', 'screen' ) as $class ) {
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
function post( $id, $status = 'publish', $type = 'post', array $terms = array(), $title = '' ) {
	$GLOBALS['post_terms'][ $id ] = $terms;

	return (object) array( 'ID' => $id, 'post_status' => $status, 'post_type' => $type, 'post_title' => '' !== $title ? $title : 'Post ' . $id );
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

// A string, as the form posts it: an id of eight digits is an int as an array key, and passing
// that on made this section fail about one run in forty.
$id = (string) array_keys( ADVCM_Auto::rules() )[0];

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


// ------------------------------------------------------------- what triggered it (0.3.1)

/**
 * A waiting rule's mark, as the clear will read it.
 *
 * @param string $id Rule id.
 * @return array
 */
function mark_of( $id ) {
	return ADVCM_Auto::mark_from( get_option( ADVCM_Auto::MARK . $id, false ) );
}

/**
 * The posts on a mark, as id => change.
 *
 * @param string $id Rule id.
 * @return array
 */
function changes_of( $id ) {
	$out = array();

	foreach ( mark_of( $id )['posts'] as $p ) {
		$out[ $p['id'] ] = $p['change'];
	}

	return $out;
}

rules( array( 'a1' => $analysis ) );
reset_waiting();
$GLOBALS['additions'] = array();

ADVCM_Auto::saved( 11, post( 11, 'publish', 'post', array( 7 ), 'A new pick' ), false, null );

check( 'the first trigger is still an add_option, which inserts only when the row is absent', array( ADVCM_Auto::MARK . 'a1' ) === $GLOBALS['additions'], implode( ',', $GLOBALS['additions'] ) );

ADVCM_Auto::saved( 12, post( 12, 'publish', 'post', array( 7 ) ), true, post( 12, 'publish', 'post', array( 7 ) ) );
ADVCM_Auto::saved( 13, post( 13, 'trash', 'post', array( 7 ) ), true, post( 13, 'publish', 'post', array( 7 ) ) );
ADVCM_Auto::saved( 14, post( 14, 'draft', 'post', array( 7 ) ), true, post( 14, 'publish', 'post', array( 7 ) ) );

check( 'a waiting rule names each post that triggered it and what happened to it', array( 11 => 'published', 12 => 'updated', 13 => 'unpublished', 14 => 'unpublished' ) === changes_of( 'a1' ), wp_json_encode( changes_of( 'a1' ) ) );
check( 'with its title and type', 'A new pick' === mark_of( 'a1' )['posts'][0]['title'] && 'post' === mark_of( 'a1' )['posts'][0]['type'] );
check( 'and still one mark and one clear for all of them', array( 'a1' ) === marks() && 1 === count( $GLOBALS['scheduled'] ) );

ADVCM_Auto::saved( 11, post( 11, 'publish', 'post', array( 7 ), 'A new pick, retitled' ), true, post( 11, 'publish', 'post', array( 7 ) ) );
ADVCM_Auto::saved( 12, post( 12, 'draft', 'post', array( 7 ) ), true, post( 12, 'publish', 'post', array( 7 ) ) );

check( 'a post saved again is counted once', 4 === mark_of( 'a1' )['total'] && 4 === count( mark_of( 'a1' )['posts'] ) );
check( 'published then updated still says published, with the newer title', 'published' === changes_of( 'a1' )[11] && 'A new pick, retitled' === mark_of( 'a1' )['posts'][0]['title'] );
check( 'updated then taken down says unpublished', 'unpublished' === changes_of( 'a1' )[12] );

for ( $i = 20; $i < 31; $i++ ) {
	ADVCM_Auto::saved( $i, post( $i, 'publish', 'post', array( 7 ) ), false, null );
}

// The stored row, not the read: mark_from() caps on the read too, and only the row's size is a
// bound on what a burst writes.
check( 'a rule names at most ' . ADVCM_Auto::MAX_POSTS . ' posts', ADVCM_Auto::MAX_POSTS === count( $GLOBALS['options'][ ADVCM_Auto::MARK . 'a1' ]['posts'] ), (string) count( $GLOBALS['options'][ ADVCM_Auto::MARK . 'a1' ]['posts'] ) );
check( 'and counts the rest', 15 === mark_of( 'a1' )['total'], (string) mark_of( 'a1' )['total'] );

ADVCM_Auto::saved( 30, post( 30, 'publish', 'post', array( 7 ) ), true, post( 30, 'publish', 'post', array( 7 ) ) );
check( 'a post past the ten saved twice is still counted once', 15 === mark_of( 'a1' )['total'], (string) mark_of( 'a1' )['total'] );

reset_waiting();
add_option( ADVCM_Auto::MARK . 'a1', time() - 30 );
ADVCM_Auto::saved( 40, post( 40, 'publish', 'post', array( 7 ) ), false, null );
check( 'a mark left by 0.3.0, a bare time, takes posts from the next save', array( 40 => 'published' ) === changes_of( 'a1' ) && mark_of( 'a1' )['at'] > 0 );

// ------------------------------------------------------------- copied into the job

rules(
	array(
		'a1' => $analysis,
		'b2' => array_merge( $analysis, array( 'term' => 8, 'term_name' => 'News', 'urls' => array( 'https://example.test/news/' ) ) ),
	)
);
reset_waiting();
update_option( ADVCM_Auto::LAST, array( 'gone' => array( 'at' => 1, 'job' => 'x', 'state' => 'done', 'total' => 1 ) ) );

ADVCM_Auto::saved( 50, post( 50, 'publish', 'post', array( 7 ), 'Fifty' ), false, null );
ADVCM_Auto::saved( 51, post( 51, 'publish', 'post', array( 8 ), 'Fifty-one' ), false, null );

$rules_before = $GLOBALS['options'][ ADVCM_Auto::RULES ];
$job          = ADVCM_Auto::flush();
$triggers     = is_array( $job ) && isset( $job['options']['triggers'] ) ? $job['options']['triggers'] : array();
$by_rule      = array();

foreach ( $triggers as $t ) {
	$by_rule[ $t['rule'] ] = $t;
}

check( 'the job carries each rule that fired it', array( 'a1', 'b2' ) === array_keys( $by_rule ), implode( ',', array_keys( $by_rule ) ) );
check( 'in words', isset( $by_rule['b2'] ) && 'a post is published in category "News" or under it' === $by_rule['b2']['line'], isset( $by_rule['b2'] ) ? $by_rule['b2']['line'] : '' );
check( 'with its posts and its count', isset( $by_rule['a1'] ) && 1 === $by_rule['a1']['total'] && 50 === $by_rule['a1']['posts'][0]['id'] && 'Fifty' === $by_rule['a1']['posts'][0]['title'] && 'published' === $by_rule['a1']['posts'][0]['change'] );
check( 'and the job as stored says the same, for History', isset( ADVCM_Jobs::get( $job['id'] )['options']['triggers'][1]['posts'][0]['id'] ) && 51 === ADVCM_Jobs::get( $job['id'] )['options']['triggers'][1]['posts'][0]['id'] );

$last = ADVCM_Auto::last();

check( 'the clear records when each rule last fired, against its job', isset( $last['a1'], $last['b2'] ) && $job['id'] === $last['a1']['job'] && $last['a1']['at'] >= time() - 5 && 1 === $last['b2']['total'] && $job['state'] === $last['a1']['state'], wp_json_encode( $last ) );
check( 'and keeps that row to the rules that exist', ! isset( $last['gone'] ) );
check( 'in its own row: the clear never writes the rules, which only the admin form does', $rules_before === $GLOBALS['options'][ ADVCM_Auto::RULES ] && ! isset( $GLOBALS['options'][ ADVCM_Auto::RULES ]['a1']['last'] ) );

ADVCM_Auto::change( array( 'op' => 'delete', 'rule' => 'b2' ) );
check( 'deleting a rule deletes its last clear', ! isset( ADVCM_Auto::last()['b2'] ) && isset( ADVCM_Auto::last()['a1'] ) );

// ------------------------------------------------------------------------ any content

$any = array_merge( $analysis, array( 'post_type' => ADVCM_Auto::ANY, 'taxonomy' => '', 'term' => 0 ) );
rules( array( 'any' => $any ) );

$fires = function ( $type ) {
	return 1 === count( ADVCM_Auto::matching( ADVCM_Auto::rules(), post( 60, 'publish', $type ) ) );
};

check( '"any content" fires for a post', $fires( 'post' ) );
check( 'for a page', $fires( 'page' ) );
check( 'and for a custom public post type', $fires( 'review' ) );
check( 'not for a page-builder template, though it is public', ! $fires( 'elementor_library' ) && ! $fires( 'bricks_template' ) );
check( 'nor media', ! $fires( 'attachment' ) );
check( 'nor a type under an excluded prefix', ! $fires( 'acf-field-group' ) && ! $fires( 'wpforms_log' ) && ! $fires( 'wpcode' ) );
check( 'nor one a visitor cannot view, or that is not registered public', ! $fires( 'hidden' ) && ! $fires( 'queryable_only' ) && ! $fires( 'public_unviewable' ) );
check( 'nor a revision, even on a site that made one viewable', ! ADVCM_Auto::is_content_type( 'revision' ) );

$GLOBALS['filter_values'][ ADVCM_Auto::EXCLUDED_FILTER ] = array( 'review' );
check( 'the filter can leave a type out', ! $fires( 'review' ) && $fires( 'post' ) );
check( 'and let one back in', $fires( 'elementor_library' ) );
check( 'but never a revision, an auto-draft or a menu item', in_array( 'revision', ADVCM_Auto::excluded_types(), true ) && in_array( 'auto-draft', ADVCM_Auto::excluded_types(), true ) && ! ADVCM_Auto::is_content_type( 'revision' ) );

$GLOBALS['filter_values'][ ADVCM_Auto::EXCLUDED_FILTER ] = array( '*', '', 'not a type', array( 'x' ), 7 );
check( 'what a filter returns that is not a post type name is dropped: a lone * would switch every rule off', $fires( 'post' ) && $fires( 'review' ) );

$GLOBALS['filter_values'][ ADVCM_Auto::EXCLUDED_FILTER ] = 'elementor_library';
check( 'and an answer that is not a list is ignored', ! $fires( 'elementor_library' ) && $fires( 'post' ) );
unset( $GLOBALS['filter_values'][ ADVCM_Auto::EXCLUDED_FILTER ] );

$r = ADVCM_Auto::rule_from( array( 'post_type' => '*', 'urls' => '/latest/' ) );
check( 'an administrator can write one', is_array( $r ) && ADVCM_Auto::ANY === $r['post_type'] );
check( 'and it reads "any content is published"', 'any content is published' === ADVCM_Auto::describe( $any ) );
check( 'while a type name still has to exist', is_string( ADVCM_Auto::rule_from( array( 'post_type' => '**', 'urls' => '/latest/' ) ) ) );

reset_waiting();
ADVCM_Auto::saved( 61, post( 61, 'publish', 'page' ), false, null );
check( 'a page published marks an "any content" rule', array( 'any' ) === marks() );

// ------------------------------------------------------------------------ subcategories

rules( array( 'a1' => $analysis ) );
reset_waiting();

ADVCM_Auto::saved( 70, post( 70, 'publish', 'post', array( 71 ) ), false, null );
check( 'a post only in a child of the rule\'s category triggers it', array( 'a1' ) === marks() );

reset_waiting();
ADVCM_Auto::saved( 72, post( 72, 'publish', 'post', array( 8 ) ), false, null );
check( 'a post in another category still does not', array() === marks() );

rules( array( 't1' => array_merge( $analysis, array( 'taxonomy' => 'post_tag', 'term' => 30, 'term_name' => 'tagged' ) ) ) );
ADVCM_Auto::saved( 73, post( 73, 'publish', 'post', array( 31 ) ), false, null );
check( 'in a flat taxonomy only the term itself counts', array() === marks() );
ADVCM_Auto::saved( 74, post( 74, 'publish', 'post', array( 30 ) ), false, null );
check( 'which still does', array( 't1' ) === marks() );
check( 'and its words do not promise more', 'a post is published in post_tag "tagged"' === ADVCM_Auto::describe( ADVCM_Auto::rules()['t1'] ) );

// ---------------------------------------------------------------------------- the pause

rules( array( 'a1' => $analysis ) );
reset_waiting();
$rules_before = $GLOBALS['options'][ ADVCM_Auto::RULES ];

check( 'pausing is a change the form makes', '' === ADVCM_Auto::change( array( 'op' => 'pause' ) ) && ADVCM_Auto::paused() );
check( 'in its own row, leaving every rule as it was', $rules_before === $GLOBALS['options'][ ADVCM_Auto::RULES ] && is_int( get_option( ADVCM_Auto::PAUSED ) ) );

ADVCM_Auto::saved( 80, post( 80, 'publish', 'post', array( 7 ) ), false, null );
check( 'while paused a publish marks nothing and schedules nothing', array() === marks() && false === flush_at() );

add_option( ADVCM_Auto::MARK . 'a1', time() );
$layer->asked = array();
$jobs_before  = count( ADVCM_Jobs::all() );

check( 'and a clear that fires clears nothing', null === ADVCM_Auto::flush() && array() === $layer->asked && $jobs_before === count( ADVCM_Jobs::all() ) );
check( 'and drops what was waiting, rather than clearing it on the first publish after the resume', array() === marks() );

check( 'resuming', '' === ADVCM_Auto::change( array( 'op' => 'resume' ) ) && ! ADVCM_Auto::paused() && $rules_before === $GLOBALS['options'][ ADVCM_Auto::RULES ] );
ADVCM_Auto::saved( 81, post( 81, 'publish', 'post', array( 7 ) ), false, null );
check( 'and publishing marks again', array( 'a1' ) === marks() && false !== flush_at() );

// Through the form's own handler, as admin-post runs it.
$_POST = array( 'op' => 'pause', '_wpnonce' => 'n' );

$GLOBALS['can']     = array( ADVCM_Capabilities::HARD => false );
$GLOBALS['referer'] = array();
$died               = false;

try {
	ADVCM_Auto::save();
} catch ( Died $e ) {
	$died = true;
} catch ( Redirected $e ) {
	$died = false;
}

check( 'somebody who is not an administrator cannot pause', $died && ! ADVCM_Auto::paused() );

$GLOBALS['can'] = array( ADVCM_Capabilities::HARD => true );

try {
	ADVCM_Auto::save();
} catch ( Redirected $e ) {
	$redirect = $e->getMessage();
}

check( 'an administrator can, through the form\'s nonce', ADVCM_Auto::paused() && array( ADVCM_Auto::SAVE ) === $GLOBALS['referer'] );

$_POST              = array( 'op' => 'resume', '_wpnonce' => 'n' );
$GLOBALS['can']     = array( ADVCM_Capabilities::HARD => false );
$died               = false;

try {
	ADVCM_Auto::save();
} catch ( Died $e ) {
	$died = true;
} catch ( Redirected $e ) {
	$died = false;
}

check( 'nor resume', $died && ADVCM_Auto::paused() );

$GLOBALS['can'] = array( ADVCM_Capabilities::HARD => true );

try {
	ADVCM_Auto::save();
} catch ( Redirected $e ) {
	$redirect = $e->getMessage();
}

check( 'which an administrator can', ! ADVCM_Auto::paused() );
$_POST          = array();
$GLOBALS['can'] = array();

// ------------------------------------------------------------------ what a save costs

reset_waiting();
rules( array() );
$GLOBALS['reads'] = array();
ADVCM_Auto::saved( 90, post( 90, 'publish', 'post', array( 7 ) ), false, null );
check( 'with no rule, a save still costs exactly one option read', array( ADVCM_Auto::RULES ) === $GLOBALS['reads'], implode( ',', $GLOBALS['reads'] ) );

rules( array( 'a1' => array_merge( $analysis, array( 'enabled' => false ) ) ) );
$GLOBALS['reads'] = array();
ADVCM_Auto::saved( 90, post( 90, 'publish', 'post', array( 7 ) ), false, null );
check( 'and with every rule switched off', array( ADVCM_Auto::RULES ) === $GLOBALS['reads'], implode( ',', $GLOBALS['reads'] ) );

rules( array( 'a1' => $analysis ) );
$GLOBALS['reads'] = array();
ADVCM_Auto::saved( 90, post( 90, 'draft', 'post', array( 7 ) ), false, null );
check( 'a draft saved with a rule on does not read the pause either', array( ADVCM_Auto::RULES ) === $GLOBALS['reads'], implode( ',', $GLOBALS['reads'] ) );
reset_waiting();

// --------------------------------------------------------------------------- History

/**
 * A private method of the screen, called as the screen calls it, its output returned.
 *
 * @param string $method Method.
 * @param array  $args   Arguments.
 * @return string
 */
function screen_part( $method, array $args ) {
	$m = new ReflectionMethod( 'ADVCM_Screen', $method );
	$m->setAccessible( true );
	ob_start();
	$returned = $m->invokeArgs( null, $args );

	return ob_get_clean() . ( is_string( $returned ) ? $returned : '' );
}

$posts = array(
	array( 'id' => 101, 'title' => '<script>alert(1)</script> & picks', 'type' => 'post', 'change' => 'published' ),
	array( 'id' => 102, 'title' => 'Not yours', 'type' => 'review', 'change' => 'unpublished' ),
	array( 'id' => 103, 'title' => '', 'type' => 'post', 'change' => 'nonsense' ),
	array( 'id' => 104, 'title' => 'Type since removed', 'type' => 'gone_type', 'change' => 'published' ),
	array( 'id' => 105, 'title' => 'Deleted since', 'type' => 'post', 'change' => 'published' ),
);
$GLOBALS['live_types'] = array( 104 => 'gone_type', 105 => false );
$auto_job = array(
	'source'  => 'auto',
	'options' => array(
		'triggers' => array(
			array( 'rule' => 'a1', 'line' => 'a post is published in category "<b>Analysis</b>"', 'posts' => $posts, 'total' => 8 ),
			array( 'rule' => 'b2', 'line' => 'any content is published', 'posts' => array(), 'total' => 0 ),
			'junk',
		),
	),
);

$GLOBALS['can_edit'] = array( 101, 104, 105 );
$html                = screen_part( 'render_triggers', array( $auto_job ) );

check( 'History names each rule in words', false !== strpos( $html, 'When a post is published in category &quot;&lt;b&gt;Analysis&lt;/b&gt;&quot;' ), $html );
check( 'and how many posts triggered it', false !== strpos( $html, 'triggered by 8 posts:' ) );
check( 'with a post\'s title escaped, never as markup', false === strpos( $html, '<script>' ) && false !== strpos( $html, '&lt;script&gt;alert(1)&lt;/script&gt; &amp; picks' ) );
check( 'linked to its edit screen for somebody who may edit it', false !== strpos( $html, '<a href="https://example.test/wp-admin/post.php?post=101&amp;action=edit">&lt;script&gt;' ) );
check( 'and plain text for somebody who may not', false !== strpos( $html, '<li>Not yours <span' ) && false === strpos( $html, 'post=102' ) );
check( 'with its id, type and what happened', false !== strpos( $html, '#102 · review · unpublished' ) );
check( 'a post with no title says so, and a change it does not know is not printed', false !== strpos( $html, '(no title)' ) && false === strpos( $html, 'nonsense' ) );
check( 'and the rest are a count', false !== strpos( $html, '<li>and 3 more</li>' ) );
check( 'a post whose type is no longer registered, or that is gone, is plain text: WordPress would print a notice asking about it', false !== strpos( $html, '<li>Type since removed <span' ) && false !== strpos( $html, '<li>Deleted since <span' ) );
check( 'a rule whose posts were not recorded says so', false !== strpos( $html, 'not recorded' ) );
check( 'and a stored trigger that is not one is not a line', 2 === substr_count( $html, '<strong>When ' ) && 1 === substr_count( $html, 'not recorded' ), (string) substr_count( $html, '<strong>When ' ) );

check( 'a job from before 0.3.1, with no triggers, shows nothing extra', '' === screen_part( 'render_triggers', array( array( 'source' => 'auto', 'options' => array( 'rules' => array( 'a1' ) ) ) ) ) && '' === screen_part( 'render_triggers', array( array( 'source' => 'auto' ) ) ) );
check( 'nor a clear somebody pressed', '' === screen_part( 'render_triggers', array( array( 'source' => 'wp-admin', 'options' => $auto_job['options'] ) ) ) );

check( 'a rule that never fired reads never', 'never' === screen_part( 'last_clear', array( null ) ) );
$cell = screen_part( 'last_clear', array( array( 'at' => 1759312800, 'job' => 'gone-job', 'state' => 'done<i>', 'total' => 3 ) ) );
check( 'one that did: when, how many posts, how it went, escaped', false !== strpos( $cell, '2025-10-01 10:00 UTC<br>3 posts — done&lt;i&gt;' ), $cell );
$GLOBALS['can_edit'] = array();

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
