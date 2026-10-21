<?php
/**
 * The screen since 0.4.0: a Settings tab holding every setting, the Auto-clear tab holding only
 * the rules, History in two lists, and a change-log box on every tab.
 *
 * Parts of the screen are rendered as the screen renders them, through reflection, with the
 * WordPress functions they print with stubbed.
 *
 * @package ADVCM
 */

$GLOBALS['types'] = array(
	'post'              => array( true, true ),
	'page'              => array( true, true ),
	'review_profile'    => array( true, true ),
	'elementor_library' => array( true, true ),
	'revision'          => array( true, true ),
);

function get_post_types( $args = array(), $output = 'names' ) {
	$out = array();

	foreach ( $GLOBALS['types'] as $name => $type ) {
		if ( empty( $args['public'] ) || $type[1] ) {
			$out[ $name ] = 'objects' === $output ? (object) array( 'name' => $name, 'labels' => (object) array( 'singular_name' => ucfirst( $name ) ) ) : $name;
		}
	}

	return $out;
}

function get_taxonomies( $args = array(), $output = 'names' ) {
	return array( 'category' => (object) array( 'name' => 'category', 'labels' => (object) array( 'singular_name' => 'Category' ) ) );
}

function is_post_type_viewable( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) && $GLOBALS['types'][ $type ][0];
}

function get_post_type_object( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) ? (object) array( 'name' => $type, 'public' => true, 'labels' => (object) array( 'singular_name' => ucfirst( $type ) ) ) : null;
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_url( $url ) {
	return (string) $url;
}

function esc_html__( $text, $domain = '' ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr__( $text, $domain = '' ) {
	return esc_attr( $text );
}

function wp_nonce_field( $action = -1, $name = '_wpnonce', $referer = true, $echo = true ) {
	echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce-' . esc_attr( $action ) . '" />';
}

function submit_button( $text = '', $type = '', $name = 'submit', $wrap = true ) {
	echo '<button type="submit">' . esc_attr( $text ) . '</button>';
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function add_query_arg( $args, $url, $third = null ) {
	// Both shapes WordPress takes: ( array, url ) and ( key, value, url ).
	if ( ! is_array( $args ) ) {
		return $third . ( false === strpos( (string) $third, '?' ) ? '?' : '&' ) . rawurlencode( $args ) . '=' . rawurlencode( $url );
	}

	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args );
}

function current_user_can( $cap ) {
	return true;
}

function wp_get_current_user() {
	return (object) array( 'ID' => 7, 'user_login' => 'ed' );
}

function sanitize_key( $key ) {
	return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $key ) );
}

function is_admin() {
	return false;
}

function wp_nonce_url( $url, $action = -1 ) {
	return $url . '&_wpnonce=nonce-' . $action;
}

function wp_verify_nonce( $nonce, $action = -1 ) {
	return 'nonce-' . $action === $nonce ? 1 : false;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $text ) {
	return trim( (string) $text );
}

function wp_create_nonce( $action = -1 ) {
	return 'nonce-' . $action;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'log', 'auto', 'settings', 'controller', 'probe', 'screen' ) as $class ) {
	load_class( $class );
}

/**
 * A private method of the screen, called as the screen calls it, its output returned.
 *
 * @param string $method Method.
 * @param array  $args   Arguments.
 * @return string
 */
function screen_part( $method, array $args = array() ) {
	$m = new ReflectionMethod( 'ADVCM_Screen', $method );
	$m->setAccessible( true );
	ob_start();
	$m->invokeArgs( null, $args );

	return ob_get_clean();
}

// ---------------------------------------------------------------------- the tabs

check( 'Freshness comes after History, Settings stays last', array( 'status', 'clear', 'check', 'history', 'freshness', 'auto', 'settings' ) === ADVCM_Screen::TABS );
check( 'and Freshness is shown as a tab', false !== strpos( screen_part( 'render_tabs', array( 'status' ) ), '>Freshness</a>' ) );
check( 'and is shown as one', false !== strpos( screen_part( 'render_tabs', array( 'status' ) ), '>Settings</a>' ) );

$source = file_get_contents( ADVCM_DIR . 'includes/class-screen.php' );

check( 'every tab gets its change-log box, on its own guard', false !== strpos( $source, "'screen:log'" ) && false !== strpos( $source, 'self::render_log( $tab );' ) );

// -------------------------------------------------------------------- Settings

$out = screen_part( 'render_settings', array( true ) );

check( 'the Settings form posts to its own action', false !== strpos( $out, 'name="action" value="' . ADVCM_Settings::ACTION . '"' ) );
check( 'with the markers that tell an unticked box from a missing one', false !== strpos( $out, 'name="excluded_present"' ) && false !== strpos( $out, 'name="own_present"' ) );
check( 'own-page ignoring unchanged saves is shown ticked by default', 1 === preg_match( '~name="own_skip_unchanged"[^>]*checked~', $out ) );
check( 'the cache target is offered, 3 hours selected', 1 === preg_match( '~<option value="3" selected>3 hours</option>~', $out ) );
check( 'measuring before a clear is shown ticked by default, with its marker', 1 === preg_match( '~name="measure_before_clear"[^>]*checked~', $out ) && false !== strpos( $out, 'name="measure_present"' ) );
check( 'the post types to leave out are boxes, the shipped ones ticked', 1 === preg_match( '~value="elementor_library"[^>]*checked~', $out ) && 1 === preg_match( '~value="review_profile"(?![^>]*checked)~', $out ) );
check( 'never a box for the always-excluded three', false === strpos( $out, 'value="revision"' ) );
check( 'NitroPack for new rules: purge selected', 1 === preg_match( '~name="nitropack_default" value="purge"[^>]*checked~', $out ) );
check( 'the pause is here', false !== strpos( $out, 'name="op" value="pause"' ) );
check( 'and own-page is switched here', false !== strpos( $out, 'name="op" value="own_on"' ) );

$GLOBALS['options'][ ADVCM_Settings::OPTION ] = array( 'nitropack_default' => 'invalidate', 'excluded_types' => array( 'review_profile' ) );
$out = screen_part( 'render_settings', array( true ) );

check( 'stored values are what is selected', 1 === preg_match( '~name="nitropack_default" value="invalidate"[^>]*checked~', $out ) && 1 === preg_match( '~value="review_profile"[^>]*checked~', $out ) && 1 === preg_match( '~value="elementor_library"(?![^>]*checked)~', $out ) );

// -------------------------------------------------------------------- Auto-clear

$out = screen_part( 'render_auto', array( true ) );

check( 'the Auto-clear tab no longer switches own-page or the pause', false === strpos( $out, 'value="own_on"' ) && false === strpos( $out, 'value="pause"' ) );
check( 'a new rule starts with the NitroPack default from Settings', 1 === preg_match( '~name="nitropack" value="invalidate"[^>]*checked~', $out ) );

update_option( ADVCM_Auto::PAUSED, time() );
$out = screen_part( 'render_auto', array( true ) );

check( 'while paused the tab still says so first, and links to Settings', false !== strpos( $out, 'Auto-clear is paused' ) && false !== strpos( $out, 'tab=settings' ) );

delete_option( ADVCM_Auto::PAUSED );

// ---------------------------------------------------------------------- History

ADVCM_Jobs::save( array( 'id' => 'm1', 'created' => time(), 'finished' => time(), 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'fast', 'state' => 'done', 'steps' => array() ) );
ADVCM_Jobs::save( array( 'id' => 'a1', 'created' => time(), 'finished' => time(), 'by' => 0, 'source' => 'auto', 'scope' => 'urls', 'urls' => array( 'https://example.test/analysis/' ), 'mode' => 'fast', 'state' => 'done', 'steps' => array() ) );

$out = screen_part( 'render_jobs', array( '' ) );

check( 'History shows the clears people started, then the automatic ones, each its own list', false !== strpos( $out, '>Clears<' ) && false !== strpos( $out, '>Automatic clears<' ) && strpos( $out, '>Clears<' ) < strpos( $out, '>Automatic clears<' ) );

// ------------------------------------------------------------ how long it took

$GLOBALS['options'][ ADVCM_Jobs::OPTION ]      = array();
$GLOBALS['options'][ ADVCM_Jobs::OPTION_AUTO ] = array();
$t = time();

ADVCM_Jobs::save( array( 'id' => 'took', 'created' => $t - 90, 'finished' => $t, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'fast', 'state' => 'done', 'steps' => array() ) );
ADVCM_Jobs::save( array( 'id' => 'waited', 'created' => $t - 10, 'finished' => $t, 'by' => 0, 'source' => 'auto', 'scope' => 'urls', 'urls' => array( 'https://example.test/analysis/' ), 'mode' => 'fast', 'state' => 'done', 'steps' => array(), 'options' => array( 'triggers' => array( array( 'rule' => 'a1', 'line' => 'a post is published', 'posts' => array(), 'total' => 1, 'at' => $t - 10 - 30600 ) ) ) ) );
ADVCM_Jobs::save( array( 'id' => 'still', 'created' => $t - 5, 'finished' => 0, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'balanced', 'state' => 'running', 'steps' => array() ) );

// A background clear WP-Cron ran late: its first step started 8.5 hours after it was asked for.
ADVCM_Jobs::save( array( 'id' => 'late', 'created' => $t - 30700, 'finished' => $t - 100, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'balanced', 'state' => 'done', 'steps' => array(
	array( 'id' => 'object-cache', 'label' => 'Object cache', 'stage' => 1, 'action' => 'skip', 'status' => 'skipped', 'message' => '', 'ms' => 0, 'started' => 0 ),
	array( 'id' => 'wp-engine', 'label' => 'Host', 'stage' => 6, 'action' => 'run', 'status' => 'ok', 'message' => '', 'ms' => 12, 'started' => $t - 30700 + 30600 + 0.4 ),
) ) );
// A Fast clear runs in the request that asked for it.
ADVCM_Jobs::save( array( 'id' => 'quick', 'created' => $t - 20, 'finished' => $t - 17, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'fast', 'state' => 'done', 'steps' => array(
	array( 'id' => 'wp-engine', 'label' => 'Host', 'stage' => 6, 'action' => 'run', 'status' => 'ok', 'message' => '', 'ms' => 12, 'started' => $t - 20 + 0.3 ),
) ) );

// Three more: one that waited 15 minutes, one of five pages with measurements, one that failed.
ADVCM_Jobs::save( array( 'id' => 'amber', 'created' => $t - 1000, 'finished' => $t - 90, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'all', 'urls' => array(), 'mode' => 'balanced', 'state' => 'done with skips', 'steps' => array(
	array( 'id' => 'wp-engine', 'label' => 'Host', 'stage' => 6, 'action' => 'run', 'status' => 'ok', 'message' => '', 'ms' => 12, 'started' => $t - 1000 + 900 ),
) ) );
ADVCM_Jobs::save( array( 'id' => 'pages', 'created' => $t - 50, 'finished' => $t - 40, 'by' => 0, 'source' => 'wp-cli', 'scope' => 'urls', 'urls' => array( 'https://example.test/a/', 'https://example.test/b/?x=<script>', 'https://example.test/c/', 'https://example.test/d/', 'https://example.test/e/' ), 'mode' => 'fast', 'state' => 'done with failures', 'measured' => true, 'not_measured' => 0, 'steps' => array(
	array( 'id' => 'wp-engine', 'label' => 'Host', 'stage' => 6, 'action' => 'run', 'status' => 'failed', 'message' => 'Error: <b>gone</b>', 'ms' => 12, 'started' => $t - 50 ),
) ) );
$GLOBALS['options']['advcm_freshness'] = array(
	array( 'at' => $t - 50, 'url' => 'https://example.test/a/', 'job' => 'pages', 'source' => 'wp-cli', 'by' => 0, 'code' => 200, 'age' => 5000, 'how' => 'exact', 'ages' => array( 'host cache' => 5000 ), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => $t - 50, 'url' => 'https://example.test/c/', 'job' => 'pages', 'source' => 'wp-cli', 'by' => 0, 'code' => 200, 'age' => 30, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
	array( 'at' => $t - 20, 'url' => 'https://example.test/q/', 'job' => 'quick', 'source' => 'wp-cli', 'by' => 0, 'code' => 0, 'age' => null, 'how' => 'no answer', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
);

load_class( 'freshness' );

$out = screen_part( 'render_jobs', array( '' ) );

/**
 * One job's summary row, and the details row under it.
 */
function job_row( $out, $id ) {
	return preg_match( '~<tr data-job="' . preg_quote( $id, '~' ) . '">(.*?)</tr>~s', $out, $m ) ? $m[1] : '';
}

function job_cell( $out, $id, $column ) {
	return preg_match( '~<td data-col="' . $column . '"[^>]*>(.*?)</td>~s', job_row( $out, $id ), $m ) ? $m[1] : '';
}

function job_details( $out, $id ) {
	return preg_match( '~<tr data-details="' . preg_quote( $id, '~' ) . '">(.*?)</tr>\s*(?=<tr data-job|</tbody>)~s', $out, $m ) ? $m[1] : '';
}

check( 'each list is a table with the summary columns', 2 === substr_count( $out, '<table class="widefat striped advcm-history"' ) && 1 === preg_match( '~<th>When</th><th>Who</th><th>What</th><th>Mode</th><th>State</th><th>Waited</th><th>Took</th><th>Cache age</th>~', $out ) );
check( 'which scrolls sideways on a narrow screen rather than breaking the page', false !== strpos( $out, '<div style="overflow-x:auto">' ) );
check( 'When is the time in UTC and how long ago', 1 === preg_match( '~\d{4}-\d\d-\d\d \d\d:\d\d UTC.*90 s ago~s', job_cell( $out, 'took', 'when' ) ) );
check( 'History says how long a clear took', '90 s' === strip_tags( job_cell( $out, 'took', 'took' ) ) );
check( 'and how long a clear somebody asked for waited before it ran, when WP-Cron was late', '8 h 30 min' === strip_tags( job_cell( $out, 'late', 'waited' ) ), (string) job_cell( $out, 'late', 'waited' ) );
check( 'a wait over an hour is red, over ten minutes amber', false !== strpos( job_row( $out, 'late' ), 'advcm-wait-red' ) && false !== strpos( job_row( $out, 'amber' ), 'advcm-wait-amber' ) && false === strpos( job_row( $out, 'quick' ), 'advcm-wait' ) );
check( 'a Fast clear, which runs in the request, shows no wait', '' === trim( strip_tags( job_cell( $out, 'quick', 'waited' ) ) ) && '3 s' === strip_tags( job_cell( $out, 'quick', 'took' ) ) );
check( 'and how long an automatic clear waited after the first save that set it off', '8 h 30 min' === strip_tags( job_cell( $out, 'waited', 'waited' ) ) );
check( 'a clear still running has no time yet, and is open', '' === trim( strip_tags( job_cell( $out, 'still', 'took' ) ) ) && 1 === preg_match( '~<details open~', job_details( $out, 'still' ) ) );
check( 'Who is a badge for WP-CLI and Auto-clear', false !== strpos( job_cell( $out, 'took', 'who' ), 'WP-CLI</span>' ) && false !== strpos( job_cell( $out, 'waited', 'who' ), 'Auto-clear</span>' ) );
check( 'What is the whole site, or the first two pages and how many more', 'Whole site' === strip_tags( job_cell( $out, 'took', 'what' ) ) && 1 === preg_match( '~/a/, /b/\?x=&lt;script&gt; \+3 more~', job_cell( $out, 'pages', 'what' ) ), (string) job_cell( $out, 'pages', 'what' ) );
check( 'State is a badge in its colour', false !== strpos( job_cell( $out, 'took', 'state' ), '#008a20' ) && false !== strpos( job_cell( $out, 'pages', 'state' ), '#d63638' ) && false !== strpos( job_cell( $out, 'amber', 'state' ), '#646970' ) && false !== strpos( job_cell( $out, 'still', 'state' ), '#2271b1' ) );
check( 'Cache age is the oldest copy measured for that clear', '83 min 20 s' === strip_tags( job_cell( $out, 'pages', 'age' ) ) );
check( 'unknown when measured and no cache said, empty when not measured', 'unknown' === strip_tags( job_cell( $out, 'quick', 'age' ) ) && '' === strip_tags( job_cell( $out, 'took', 'age' ) ) );

$details = job_details( $out, 'pages' );

check( 'a row opens to its steps, every page and each page\'s cache age', false !== strpos( $details, 'Host' ) && false !== strpos( $details, 'https://example.test/e/' ) && 1 === preg_match( '~https://example.test/a/.{0,80}83 min 20 s~s', $details ) );
check( 'everything in it escaped', false === strpos( $out, '<script>' ) && false === strpos( $out, '<b>gone' ) );

// ------------------------------------------------------------------- the log box

$out = screen_part( 'render_log', array( 'auto' ) );

check( 'an empty log box says so', false !== strpos( $out, 'No changes yet.' ) );

ADVCM_Log::add( 'auto', 'rule_off', 'a picks is published → /analysis/', 'on', 'off' );
$out = screen_part( 'render_log', array( 'auto' ) );

check( 'a record shows who, what, and before → after', false !== strpos( $out, 'ed' ) && false !== strpos( $out, '/analysis/' ) && false !== strpos( $out, 'on → off' ) );

// --------------------------------------------------------------- nothing left behind

$source = file_get_contents( ADVCM_DIR . 'includes/class-screen.php' ) . file_get_contents( ADVCM_DIR . 'includes/class-auto.php' );

check( 'no screen text still says administrators only', false === stripos( $source, 'administrators only' ) && false === strpos( $source, 'Only administrators can' ) );

// ----------------------------------------------------------------- a lost clear

// The plugin update that took an auto-clear's event on BMR was followed, a minute later, by
// somebody opening this screen. Opening any tab now puts the clear back.
$GLOBALS['options']['advcm_rules'] = array( 'r9' => array( 'post_type' => 'post', 'enabled' => true, 'urls' => array( 'https://example.test/x/' ) ) );
add_option( ADVCM_Auto::MARK . 'r9', time() );
wp_clear_scheduled_hook( ADVCM_Auto::FLUSH_HOOK );
$_GET['tab'] = 'settings';
screen_part( 'render' );
unset( $_GET['tab'] );

check( 'opening any tab puts back an auto-clear whose event was lost', false !== wp_next_scheduled( ADVCM_Auto::FLUSH_HOOK ) );

delete_option( ADVCM_Auto::MARK . 'r9' );
wp_clear_scheduled_hook( ADVCM_Auto::FLUSH_HOOK );
unset( $GLOBALS['options']['advcm_rules'] );

// -------------------------------------------------------------------- Freshness

load_class( 'freshness' );
load_class( 'listings' );

$GLOBALS['options'] = array();

$out = screen_part( 'render_freshness', array( true ) );

check( 'with no scan yet the tab says so, and offers one', false !== strpos( $out, 'No scan yet' ) && false !== strpos( $out, 'name="action" value="' . ADVCM_Listings::ACTION . '"' ) && false !== strpos( $out, 'nonce-' . ADVCM_Listings::ACTION ) );
check( 'with no measurement yet, that too', false !== strpos( $out, 'No clear has been measured yet' ) );

$GLOBALS['options'][ ADVCM_Listings::OPTION ] = array(
	'started'  => time() - 600,
	'by'       => 7,
	'cursor'   => 400,
	'done'     => time() - 300,
	'scanned'  => 196,
	'total'    => 196,
	'listings' => 137,
	'green'    => 120,
	'findings' => array(
		array( 'verdict' => 'amber', 'days' => 9, 'doc' => 2, 'pos' => 0, 'title' => 'Texas', 'doc_type' => 'states', 'url' => 'https://example.test/usa/texas/', 'widget' => 'a1', 'type' => 'loop-grid', 'post_type' => 'post', 'per_page' => 6, 'filter' => 'tag texas', 'broken' => false, 'cannot_match' => false, 'custom' => false, 'newest' => array( 'id' => 5, 'title' => 'Texas news', 'date' => time() - 9 * 86400 ) ),
		array( 'verdict' => 'red', 'days' => 293, 'doc' => 1, 'pos' => 0, 'title' => 'Alabama <script>x</script>', 'doc_type' => 'states', 'url' => 'https://example.test/usa/alabama/', 'widget' => 'b2', 'type' => 'posts', 'post_type' => 'post', 'per_page' => 6, 'filter' => 'tag alabama', 'broken' => false, 'cannot_match' => false, 'custom' => false, 'newest' => array( 'id' => 6, 'title' => 'Alabama news', 'date' => time() - 293 * 86400 ) ),
		array( 'verdict' => 'red', 'days' => null, 'doc' => 3, 'pos' => 0, 'title' => 'Template', 'doc_type' => 'elementor_library', 'url' => '', 'widget' => 'c3', 'type' => 'posts', 'post_type' => 'post', 'per_page' => null, 'filter' => 'missing term #99', 'broken' => true, 'cannot_match' => false, 'custom' => false, 'newest' => null ),
	),
);

$out = screen_part( 'render_freshness', array( true ) );

check( 'a finished scan says when, by whom, and what it read', false !== strpos( $out, '196 documents' ) && false !== strpos( $out, '137 listings' ) && false !== strpos( $out, '120 up to date' ) );
check( 'findings are listed red first', strpos( $out, 'tag alabama' ) < strpos( $out, 'tag texas' ) && strpos( $out, 'missing term #99' ) < strpos( $out, 'tag texas' ) );
check( 'with the filter in words and the days since its newest post', false !== strpos( $out, '293' ) && false !== strpos( $out, 'Alabama news' ) );
check( 'a broken filter says so', false !== strpos( $out, 'broken filter' ) );
check( 'everything shown is escaped', false === strpos( $out, '<script>x' ) && false !== strpos( $out, '&lt;script&gt;' ) );

$GLOBALS['options'][ ADVCM_Listings::OPTION ]['done']     = 0;
$GLOBALS['options'][ ADVCM_Listings::OPTION ]['scanned']  = 40;
$GLOBALS['options'][ ADVCM_Listings::OPTION ]['next_at']  = time();

$out = screen_part( 'render_freshness', array( true ) );

check( 'a running scan shows its progress', false !== strpos( $out, '40 of 196' ) );

$tick = screen_part( 'render_tick', array( true, true ) );

check( 'and the tab moves it along, saying it is the Freshness tab', false !== strpos( $tick, 'd.append("listings","1")' ) );
$GLOBALS['options'][ ADVCM_Jobs::OPTION ] = array( 'jr' => array( 'id' => 'jr', 'state' => 'running', 'scope' => 'all', 'urls' => array(), 'created' => time(), 'finished' => 0, 'steps' => array(), 'source' => 'wp-admin', 'by' => 7 ) );
$other = screen_part( 'render_tick', array( false ) );

check( 'other tabs never ask for a listings batch, even while they tick', false !== strpos( $other, 'fetch(' ) && false === strpos( $other, 'listings' ) );

unset( $GLOBALS['options'][ ADVCM_Jobs::OPTION ] );

$GLOBALS['options'][ ADVCM_Freshness::OPTION ] = array(
	array( 'at' => time() - 60, 'url' => 'https://example.test/analysis/', 'job' => 'h2', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => 5000, 'how' => 'exact', 'ages' => array( 'host cache' => 5000 ), 'nitro_state' => 'STALE', 'nitro_copy' => 'stale', 'nitro_age' => 7200 ),
	array( 'at' => time() - 120, 'url' => 'https://example.test/analysis/', 'job' => 'h1', 'source' => 'wp-admin', 'by' => 7, 'code' => 200, 'age' => 30, 'how' => 'exact', 'ages' => array(), 'nitro_state' => null, 'nitro_copy' => null, 'nitro_age' => null ),
);

$GLOBALS['options'][ ADVCM_Freshness::STATS ] = array(
	'pages' => array( 'https://example.test/analysis/' => array( 'n' => 2, 'hand' => 2, 'auto' => 0, 'unk' => 0, 'young' => 1, 'over' => 0, 'max' => 5000, 'sum' => 5030, 'hsum' => 5030, 'hk' => 2, 'last' => time() - 60, 'rules' => array() ) ),
	'days'  => array( gmdate( 'Y-m-d' ) => array( 'n' => 2, 'unk' => 0, 'young' => 1, 'over' => 0, 'max' => 5000 ) ),
);

$out = screen_part( 'render_freshness', array( true ) );

check( 'the cache age at each clear is listed, layer by layer', false !== strpos( $out, 'host cache 83 min 20 s' ) && false !== strpos( $out, 'NitroPack: stale' ) );
check( 'pages cleared by hand often are listed, with no rule clearing them', false !== strpos( $out, 'no rule clears this page' ) && false !== strpos( $out, '2 times' ) );

// ------------------------------------------------- Freshness says what to do (0.5.1)

$GLOBALS['options']['advcm_rules'] = array( 'r2' => array( 'post_type' => 'post', 'enabled' => true, 'urls' => array( 'https://example.test/news/' ) ) );
$GLOBALS['options'][ ADVCM_Freshness::STATS ] = array(
	'pages' => array(
		'https://example.test/old/'  => array( 'n' => 3, 'hand' => 3, 'auto' => 0, 'unk' => 0, 'young' => 0, 'over' => 2, 'max' => 9 * 3600, 'sum' => 20 * 3600, 'hsum' => 20 * 3600, 'hk' => 3, 'last' => time() - 60, 'rules' => array() ),
		'https://example.test/news/' => array( 'n' => 8, 'hand' => 0, 'auto' => 8, 'unk' => 0, 'young' => 8, 'over' => 0, 'max' => 120, 'sum' => 400, 'hsum' => 0, 'hk' => 0, 'last' => time() - 30, 'rules' => array( 'r2' ) ),
	),
	'days'  => array( gmdate( 'Y-m-d' ) => array( 'n' => 11, 'unk' => 0, 'young' => 8, 'over' => 2, 'max' => 9 * 3600 ) ),
);
$GLOBALS['options'][ ADVCM_Listings::OPTION ]['done']     = time() - 60;
$GLOBALS['options'][ ADVCM_Listings::OPTION ]['findings'] = array();

for ( $k = 0; $k < 4; $k++ ) {
	$GLOBALS['options'][ ADVCM_Listings::OPTION ]['findings'][] = array( 'verdict' => 'amber', 'days' => 17, 'doc' => 7, 'pos' => $k, 'title' => 'Category articles', 'doc_type' => 'elementor_library', 'url' => '', 'widget' => 'w' . $k, 'type' => 'posts', 'post_type' => 'picks', 'per_page' => 3, 'filter' => 'picks_tag analysis', 'broken' => false, 'cannot_match' => false, 'custom' => false, 'newest' => array( 'id' => 9, 'title' => 'Patriots vs Bills', 'date' => time() - 17 * 86400 ) );
}

$GLOBALS['options'][ ADVCM_Listings::OPTION ]['findings'][] = array( 'verdict' => 'red', 'days' => 293, 'doc' => 1, 'pos' => 0, 'title' => 'Alabama', 'doc_type' => 'states', 'url' => 'https://example.test/usa/alabama/', 'widget' => 'b2', 'type' => 'posts', 'post_type' => 'post', 'per_page' => 6, 'filter' => 'tag alabama', 'broken' => false, 'cannot_match' => false, 'custom' => false, 'newest' => array( 'id' => 6, 'title' => 'Alabama news', 'date' => time() - 293 * 86400 ) );
$GLOBALS['options'][ ADVCM_Listings::OPTION ]['findings'][] = array( 'verdict' => 'red', 'days' => null, 'doc' => 3, 'pos' => 0, 'title' => 'Template', 'doc_type' => 'elementor_library', 'url' => '', 'widget' => 'c3', 'type' => 'posts', 'post_type' => 'post', 'per_page' => null, 'filter' => 'missing term #99', 'broken' => true, 'cannot_match' => false, 'custom' => false, 'newest' => null );

$out = screen_part( 'render_freshness', array( true ) );

$GLOBALS['options'][ ADVCM_Freshness::STATS ]['pages']['https://example.test/nfl/analysis/'] = array( 'n' => 1, 'hand' => 0, 'auto' => 1, 'unk' => 1, 'young' => 0, 'over' => 0, 'max' => 0, 'sum' => 0, 'hsum' => 0, 'hk' => 0, 'last' => time(), 'rules' => array( 'r2' ), 'moved' => '/analysis/' );
$GLOBALS['options']['advcm_rules']['r2']['urls'][] = 'https://example.test/nfl/analysis/';

$out = screen_part( 'render_freshness', array( true ) );

$order = array( 'Last 30 days', 'Past the target', 'Pages that redirect', 'Cleared by hand, often', 'Listings with nothing new', 'Last measurements' );
$at    = array_map( function ( $h ) use ( $out ) { return strpos( $out, '>' . $h . '</h2>' ); }, $order );

check( 'the tab leads with what to do: summary, past the target, redirects, by hand, listings, then the raw measurements', ! in_array( false, $at, true ) && $at === array_values( array_unique( $at ) ) && $at == ( function ( $a ) { sort( $a ); return $a; } )( $at ), json_encode( $at ) );
check( 'the summary counts the last 30 days against the 3-hour target', false !== strpos( $out, '11 pages measured' ) && false !== strpos( $out, '2 past the 3-hour target' ) && false !== strpos( $out, '8 found a copy under 10 minutes' ) );
check( 'a page past the target with no rule says so, and links to adding one', 1 === preg_match( '~/old/.*?no rule clears it.*?tab=auto~s', $out ) );
check( 'a rule\'s page that redirects says where to, and that the rule should name that instead', 1 === preg_match( '~/nfl/analysis/.*?/analysis/.*?When a post is published~s', $out ) );
check( 'a listing cleared while fresh is no longer called a problem', false === strpos( $out, 'Cleared while fresh' ) );
check( 'pages cleared by hand say how often the copy was fresh', false !== strpos( $out, 'Found a fresh copy' ) );
check( 'the raw measurements are folded away', 1 === preg_match( '~<details[^>]*><summary><h2[^>]*>Last measurements</h2>~', $out ) );
check( 'listing labels say how old: over 30 days, 7–30 days, broken filter', false !== strpos( $out, 'over 30 days</span>' ) && false !== strpos( $out, '7–30 days</span>' ) && false !== strpos( $out, 'broken filter</span>' ) && false === strpos( $out, 'slowing' ) );
check( 'the same listing four times in one template is one row, ×4', 1 === substr_count( $out, 'picks_tag analysis' ) && false !== strpos( $out, '×4' ) );

unset( $GLOBALS['options']['advcm_rules'] );

// ------------------------------------------------------- every tab explains itself

foreach ( ADVCM_Screen::TABS as $tab ) {
	$help = screen_part( 'render_help', array( $tab ) );

	check( 'the ' . $tab . ' tab says what it does, and how it works in detail', false !== strpos( $help, 'class="advcm-help"' ) && 1 === preg_match( '~<p><strong>[^<]{20,}~', $help ) && 1 === preg_match( '~<summary>How this tab works</summary>~', $help ) && substr_count( $help, '<h4' ) >= 3, $tab . ': ' . strlen( $help ) . ' bytes, ' . substr_count( $help, '<h4' ) . ' parts' );
}

$help = screen_part( 'render_help', array( 'history' ) );

check( 'History\'s legend shows the state badges as they look', false !== strpos( $help, '#008a20' ) && false !== strpos( $help, '#d63638' ) && false !== strpos( $help, '#2271b1' ) && false !== strpos( $help, 'advcm-wait-red' ) );

$help = screen_part( 'render_help', array( 'freshness' ) );

check( 'Freshness says it only shows, and never clears anything itself', false !== strpos( $help, 'never clears' ) );
check( 'Freshness explains each label and the target from Settings', false !== strpos( $help, 'over 30 days' ) && false !== strpos( $help, '7–30 days' ) && false !== strpos( $help, 'broken filter' ) && false !== strpos( $help, '×N' ) && false !== strpos( $help, '3 hours' ) );

$help = screen_part( 'render_help', array( 'check' ) );

check( 'a site without NitroPack is not told about NitroPack', false === strpos( $help, 'NitroPack' ) );

$help = screen_part( 'render_help', array( 'clear' ) );

check( 'Clear states every limit a person can meet', false !== strpos( $help, (string) ADVCM_Controller::SITE_WIDE_PER_HOUR . ' times an hour' ) && false !== strpos( $help, (string) ADVCM_Controller::URL_JOBS_PER_MINUTE_SITE . ' page clears a minute' ) );
check( 'Clear explains every mode this site offers, from the modes themselves', false !== strpos( $help, ADVCM_Modes::get( 'fast' )['label'] ) && false !== strpos( $help, ADVCM_Modes::get( 'fast' )['summary'] ) );
check( 'the help box is drawn on its own guard, so it never takes a tab with it', false !== strpos( file_get_contents( ADVCM_DIR . 'includes/class-screen.php' ), "'screen:help'" ) );
check( 'the change-log box says what it is', false !== strpos( screen_part( 'render_log', array( 'auto' ) ), 'Who changed what on this tab' ) );

// ------------------------------------------------ the admin bar posts its clears (security)

// From the security review of 2026-10-20: the links carried the nonce in the URL, into access
// logs and browser history. They now hold no nonce; a listener posts it.
$bar = new class() {
	public $nodes = array();

	public function add_node( $node ) {
		$this->nodes[ $node['id'] ] = $node;
	}
};

$_SERVER['REQUEST_URI'] = '/analysis/';
ADVCM_Screen::admin_bar( $bar );

check( 'the admin bar\'s clear links carry no nonce', isset( $bar->nodes['advcm-page'], $bar->nodes['advcm-site'] ) && false === strpos( $bar->nodes['advcm-page']['href'] . $bar->nodes['advcm-site']['href'], '_wpnonce' ) );

ob_start();
ADVCM_Screen::admin_bar_confirm();
$script = ob_get_clean();

check( 'a listener posts them, with the nonce in the form, the whole site after asking', false !== strpos( $script, '"post"' ) && false !== strpos( $script, 'nonce-' . ADVCM_Controller::ACTION ) && false !== strpos( $script, '#wp-admin-bar-advcm-page > a' ) && false !== strpos( $script, 'confirm(' ) );

// ------------------------------------------- before → after with one side empty (BMR)

$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();
ADVCM_Log::add( 'auto', 'rule_added', 'When a picks is published → /analysis/', '', 'on' );
ADVCM_Log::add( 'auto', 'rule_deleted', 'When a picks is published → 8 URLs', 'on', '' );
ADVCM_Log::add( 'auto', 'rule_off', 'When a post is published → /x/', 'on', 'off' );
$log = screen_part( 'render_log', array( 'auto' ) );

check( 'a change with nothing before reads as new, not "→ on"', false !== strpos( $log, '<td>new: on</td>' ) && false === strpos( $log, '<td> → on</td>' ) );
check( 'one with nothing after reads as removed, not "on →"', false !== strpos( $log, '<td>removed (was on)</td>' ) && false === strpos( $log, '<td>on → </td>' ) );
check( 'and a change of value still reads before → after', false !== strpos( $log, '<td>on → off</td>' ) );

// --------------------------------------------- a refused check is not recorded (2026-10-21)

// Seen in the manual's screenshots: a cache-age check of /wp-login.php, refused, still appeared
// under Last changes as a probe. The README says refusals are not recorded.
$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();
$_GET['advcm_probe']                      = '/wp-login.php';
$_GET['_wpnonce']                         = 'nonce-' . ADVCM_Screen::PROBE;
$probe                                    = screen_part( 'render_probe' );
unset( $_GET['advcm_probe'], $_GET['_wpnonce'] );

check( 'a refused cache-age check says why, and is not recorded as a probe', false !== strpos( $probe, 'That is not a page on this site.' ) && array() === ADVCM_Log::recent( 'check' ) );

finish();
