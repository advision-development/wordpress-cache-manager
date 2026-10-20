<?php
/**
 * What the change log records when a person acts, through the handlers admin-post runs — and
 * that refusals, WP-Cron and the screen's tick record nothing.
 *
 * @package ADVCM
 */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

$GLOBALS['can'] = array();

function current_user_can( $cap ) {
	return ! empty( $GLOBALS['can'][ $cap ] );
}

function wp_get_current_user() {
	return (object) array( 'ID' => 1, 'user_login' => 'ed' );
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
	return 1;
}

function wp_unslash( $value ) {
	return $value;
}

function sanitize_text_field( $value ) {
	return trim( (string) $value );
}

function sanitize_key( $key ) {
	return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $key ) );
}

function delete_site_transient( $key ) {
	unset( $GLOBALS['site_transients'][ $key ] );

	return true;
}

function wp_remote_get( $url, $args = array() ) {
	return new WP_Error( 'offline', 'no network in the test' );
}

class WP_Error {
	private $message;

	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_transient( $key ) {
	return isset( $GLOBALS['transients'][ $key ] ) ? $GLOBALS['transients'][ $key ] : false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['transients'][ $key ] = $value;

	return true;
}

function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}

function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}

function url_to_postid( $url ) {
	return 0;
}

function clean_post_cache( $id ) {
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'log', 'auto', 'settings', 'controller', 'updater', 'screen' ) as $class ) {
	load_class( $class );
}

/**
 * A layer that clears and says so.
 */
class Quiet_Adapter extends ADVCM_Adapter {
	public function id() {
		return 'host';
	}

	public function label() {
		return 'Host';
	}

	public function stage() {
		return ADVCM_Stages::HOST;
	}

	public function detect() {
		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		return $this->ok( '' );
	}
}

ADVCM_Runner::use_adapters( array( new Quiet_Adapter() ) );

/**
 * Run a handler as admin-post does: it ends in a redirect, or in wp_die.
 *
 * @param callable $handler Handler.
 * @return string redirected, died
 */
function press( $handler ) {
	try {
		call_user_func( $handler );
	} catch ( Redirected $e ) {
		return 'redirected';
	} catch ( Died $e ) {
		return 'died';
	}

	return 'returned';
}

/**
 * A section's records as "action: summary".
 *
 * @param string $section Section.
 * @return string[]
 */
function records( $section ) {
	return array_map(
		function ( $r ) {
			return $r['action'] . ': ' . $r['summary'];
		},
		ADVCM_Log::recent( $section, 20 )
	);
}

$GLOBALS['can'] = array( ADVCM_Capabilities::PURGE => true, ADVCM_Capabilities::HARD => true, 'update_plugins' => true );

// ------------------------------------------------------------------------- a clear

$_REQUEST = array( 'action' => 'advcm_purge', 'scope' => 'urls', 'urls' => "/a/\n/b/", 'mode' => 'fast', '_wpnonce' => 'n' );

press( array( 'ADVCM_Controller', 'handle' ) );

$clear = records( 'clear' );

check( 'a clear somebody started is recorded', 1 === count( $clear ) && 0 === strpos( $clear[0], 'started: ' ), implode( ' | ', $clear ) );
check( 'saying what it was: the pages, how many, and the mode', false !== strpos( $clear[0], '2 page' ) && false !== stripos( $clear[0], 'fast' ), $clear[0] );

$GLOBALS['transients'][ 'advcm_rate_' . get_current_user_id() ] = ADVCM_Controller::URL_JOBS_PER_MINUTE;

press( array( 'ADVCM_Controller', 'handle' ) );

check( 'a clear refused by the rate limit is not', 1 === count( records( 'clear' ) ) );

unset( $GLOBALS['transients'][ 'advcm_rate_' . get_current_user_id() ] );

// ------------------------------------------------------------------------- the rules

$_POST = array( 'op' => 'add', 'post_type' => 'post', 'urls' => '/analysis/', 'enabled' => '1', 'nitropack' => 'purge', '_wpnonce' => 'n' );
press( array( 'ADVCM_Auto', 'save' ) );

$id = '';
foreach ( ADVCM_Auto::rules() as $rule_id => $rule ) {
	$id = $rule_id;
}

foreach ( array( 'disable', 'enable', 'delete' ) as $op ) {
	$_POST = array( 'op' => $op, 'rule' => $id, '_wpnonce' => 'n' );
	press( array( 'ADVCM_Auto', 'save' ) );
}

$auto = records( 'auto' );

check( 'each rule change is recorded, newest first', 4 === count( $auto ) && 0 === strpos( $auto[0], 'rule_deleted' ) && 0 === strpos( $auto[1], 'rule_on' ) && 0 === strpos( $auto[2], 'rule_off' ) && 0 === strpos( $auto[3], 'rule_added' ), implode( ' | ', $auto ) );
check( 'naming the rule in words and what it clears', false !== strpos( $auto[3], 'a post is published' ) && false !== strpos( $auto[3], '/analysis/' ), $auto[3] );

$_POST = array( 'op' => 'add', 'post_type' => 'no_such_type', 'urls' => '/x/', '_wpnonce' => 'n' );
press( array( 'ADVCM_Auto', 'save' ) );

check( 'a rule refused is not', 4 === count( records( 'auto' ) ) );

$_POST = array( 'op' => 'pause', '_wpnonce' => 'n' );
press( array( 'ADVCM_Auto', 'save' ) );

check( 'a pause sent to the rules form is recorded with the settings, where the switch now lives', ADVCM_Auto::paused() && 0 === strpos( records( 'settings' )[0], 'pause' ), implode( ' | ', records( 'settings' ) ) );

$_POST = array( 'op' => 'resume', '_wpnonce' => 'n' );
press( array( 'ADVCM_Auto', 'save' ) );

// ---------------------------------------------------------------- a stuck job, resumed

$stuck = array( 'id' => 'stuck-1', 'created' => time() - 3600, 'finished' => 0, 'state' => 'running', 'source' => 'wp-admin', 'by' => 1, 'scope' => 'all', 'urls' => array(), 'refused' => array(), 'layers' => array(), 'options' => array(), 'mode' => 'balanced', 'next_at' => time() - 3600, 'paused' => array(), 'steps' => ADVCM_Runner::plan( array( 'scope' => 'all' ) ) );
ADVCM_Jobs::save( $stuck );

$_POST = array( 'job' => 'stuck-1', '_wpnonce' => 'n' );
press( array( 'ADVCM_Controller', 'handle_resume' ) );

check( 'a stuck job somebody finished by hand is recorded in History\'s section', 1 === count( records( 'history' ) ) && false !== strpos( records( 'history' )[0], 'stuck-1' ), implode( ' | ', records( 'history' ) ) );

// ------------------------------------------------------------------- the release check

press( array( 'ADVCM_Updater', 'handle_check' ) );

check( 'a release check is recorded on Status', 1 === count( records( 'status' ) ) && 0 === strpos( records( 'status' )[0], 'release_check' ), implode( ' | ', records( 'status' ) ) );

// ------------------------------------------------------------------- an update

ADVCM_Settings::set_version_seen( '0.3.2' );
ADVCM_Screen::record_version();
ADVCM_Screen::record_version();

$status = ADVCM_Log::recent( 'status', 20 );

check( 'a new version is recorded once, from the one before', 2 === count( $status ) && 'updated' === $status[0]['action'] && '0.3.2' === $status[0]['before'] && ADVCM_VERSION === $status[0]['after'] );
check( 'as nobody: WordPress updates the plugin on its own', 0 === $status[0]['user'] && '' === $status[0]['login'] );
check( 'and the version is kept for next time', ADVCM_VERSION === ADVCM_Settings::get()['version_seen'] );

$GLOBALS['options'][ ADVCM_Settings::OPTION ]['version_seen'] = '';
ADVCM_Screen::record_version();

check( 'a site that never recorded a version records none, it only starts counting', 2 === count( ADVCM_Log::recent( 'status', 20 ) ) && ADVCM_VERSION === ADVCM_Settings::get()['version_seen'] );

// --------------------------------------------------------- what records nothing

$before = $GLOBALS['options'][ ADVCM_Log::OPTION ];

$_POST = array( 'op' => 'add', 'post_type' => 'post', 'urls' => '/analysis/', 'enabled' => '1', '_wpnonce' => 'n' );
press( array( 'ADVCM_Auto', 'save' ) );
$before = $GLOBALS['options'][ ADVCM_Log::OPTION ];

foreach ( ADVCM_Auto::rules() as $rule_id => $rule ) {
	add_option( ADVCM_Auto::MARK . $rule_id, time() );
}

ADVCM_Auto::flush();
ADVCM_Auto::tick();

check( 'an automatic clear, and the tick, record nothing: History has them', $before === $GLOBALS['options'][ ADVCM_Log::OPTION ] );

finish();
