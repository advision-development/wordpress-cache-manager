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

function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
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

check( 'Settings is the sixth tab', array( 'status', 'clear', 'check', 'history', 'auto', 'settings' ) === ADVCM_Screen::TABS );
check( 'and is shown as one', false !== strpos( screen_part( 'render_tabs', array( 'status' ) ), '>Settings</a>' ) );

$source = file_get_contents( ADVCM_DIR . 'includes/class-screen.php' );

check( 'every tab gets its change-log box, on its own guard', false !== strpos( $source, "'screen:log'" ) && false !== strpos( $source, 'self::render_log( $tab );' ) );

// -------------------------------------------------------------------- Settings

$out = screen_part( 'render_settings', array( true ) );

check( 'the Settings form posts to its own action', false !== strpos( $out, 'name="action" value="' . ADVCM_Settings::ACTION . '"' ) );
check( 'with the markers that tell an unticked box from a missing one', false !== strpos( $out, 'name="excluded_present"' ) && false !== strpos( $out, 'name="own_present"' ) );
check( 'own-page ignoring unchanged saves is shown ticked by default', 1 === preg_match( '~name="own_skip_unchanged"[^>]*checked~', $out ) );
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

$out = screen_part( 'render_jobs', array( '' ) );

check( 'History says how long a clear took', false !== strpos( $out, 'took 90 s' ) );
check( 'and how long a clear somebody asked for waited before it ran, when WP-Cron was late', 1 === preg_match( '~Balanced — done — waited 8 h 30 min — took~', $out ) );
check( 'a Fast clear, which runs in the request, shows no wait', 1 === preg_match( '~Fast — done — took 3 s~', $out ) && 0 === preg_match( '~whole site — Fast — done — waited~', $out ) );
check( 'and how long an automatic clear waited after the first save that set it off', false !== strpos( $out, 'waited 8 h 30 min' ) );
check( 'a clear still running has no time yet', 1 === preg_match( '~balanced — running(?! — took)~i', $out ) );

// ------------------------------------------------------------------- the log box

$out = screen_part( 'render_log', array( 'auto' ) );

check( 'an empty log box says so', false !== strpos( $out, 'No changes yet.' ) );

ADVCM_Log::add( 'auto', 'rule_off', 'a picks is published → /analysis/', 'on', 'off' );
$out = screen_part( 'render_log', array( 'auto' ) );

check( 'a record shows who, what, and before → after', false !== strpos( $out, 'ed' ) && false !== strpos( $out, '/analysis/' ) && false !== strpos( $out, 'on → off' ) );

// --------------------------------------------------------------- nothing left behind

$source = file_get_contents( ADVCM_DIR . 'includes/class-screen.php' ) . file_get_contents( ADVCM_DIR . 'includes/class-auto.php' );

check( 'no screen text still says administrators only', false === stripos( $source, 'administrators only' ) && false === strpos( $source, 'Only administrators can' ) );

finish();
