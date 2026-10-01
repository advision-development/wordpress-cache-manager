<?php
/**
 * Nothing this plugin hooks can take a page down.
 *
 * The guards are tested by calling the wrapped callbacks the way WordPress would, and the source
 * is read to show that every hook in the plugin goes through them — the second half is what
 * keeps the next hook somebody adds from being the one that is not guarded.
 *
 * @package ADVCM
 */

// error_log() is PHP's own and cannot be replaced, so the log is pointed at a file the suite reads.
$GLOBALS['log_file'] = tempnam( sys_get_temp_dir(), 'advcm-log' );
ini_set( 'error_log', $GLOBALS['log_file'] );

/**
 * The lines written to the error log so far.
 *
 * @return string[]
 */
function logged() {
	return array_values( array_filter( array_map( 'trim', file( $GLOBALS['log_file'] ) ) ) );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'safe' );

/**
 * The last callback registered on a hook.
 *
 * @param string $hook Hook.
 * @return callable
 */
function registered( $hook ) {
	return end( $GLOBALS['callbacks'][ $hook ] );
}

// --------------------------------------------------------------------------- filters

ADVCM_Safe::filter(
	'user_has_cap',
	function ( $caps ) {
		throw new RuntimeException( 'broken' );
	}
);

$given  = array( 'edit_posts' => true );
$answer = call_user_func( registered( 'user_has_cap' ), $given, array(), array() );

check( 'a filter that throws hands back exactly what it was given', $given === $answer );

ADVCM_Safe::filter(
	'pre_set_site_transient_update_plugins',
	function ( $transient ) {
		return advcm_no_such_function();
	}
);

$transient = (object) array( 'response' => array() );

check( 'and so does one that hits a PHP Error, not only an exception', $transient === call_user_func( registered( 'pre_set_site_transient_update_plugins' ), $transient ) );

ADVCM_Safe::filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		return $action . ':' . $args;
	},
	10,
	3
);

check( 'a filter that works passes every argument through', 'plugin_information:x' === call_user_func( registered( 'plugins_api' ), false, 'plugin_information', 'x' ) );

// --------------------------------------------------------------------------- actions

ADVCM_Safe::action(
	'admin_bar_menu',
	function () {
		throw new TypeError( 'bad bar' );
	}
);

$survived = true;

try {
	call_user_func( registered( 'admin_bar_menu' ), new stdClass() );
} catch ( Throwable $e ) {
	$survived = false;
}

check( 'an action that throws stops there and the request goes on', $survived );

// --------------------------------------------------------------------------- reporting

check( 'every fault is written to the PHP error log, with where it happened', 3 === count( logged() ) && false !== strpos( logged()[0], '[advcm] user_has_cap: RuntimeException: broken' ), implode( ' | ', logged() ) );

call_user_func( registered( 'user_has_cap' ), $given );
call_user_func( registered( 'user_has_cap' ), $given );

check( 'but once per request per fault: a capability filter runs on every check, and one fault must not become a slow site', 3 === count( logged() ) );

$last = get_option( ADVCM_Safe::LAST_ERROR );

check( 'and the last one is kept for the screen', is_array( $last ) && 'admin_bar_menu' === $last['where'] && false !== strpos( $last['what'], 'bad bar' ) );

check( 'run() answers the fallback when what it runs throws', 'fallback' === ADVCM_Safe::run( 'x', function () { throw new Exception( 'x' ); }, 'fallback' ) );

// ----------------------------------------------------------------- every hook is guarded

$sources = glob( ADVCM_DIR . 'includes/*.php' );
$raw     = array();

foreach ( $sources as $file ) {
	if ( 'class-safe.php' === basename( $file ) ) {
		continue;
	}

	foreach ( file( $file ) as $n => $line ) {
		// The one deliberate exception: WP Engine's paths filter is added and removed by the same
		// callable inside a single call that the runner already guards.
		if ( preg_match( '~\badd_(action|filter)\s*\(~', $line ) && false === strpos( $line, "'wpe_purge_varnish_cache_paths'" ) ) {
			$raw[] = basename( $file ) . ':' . ( $n + 1 );
		}
	}
}

check( 'no hook in the plugin is added without ADVCM_Safe', array() === $raw, implode( ', ', $raw ) );

$main = file_get_contents( ADVCM_DIR . 'wordpress-cache-manager.php' );

check( 'and the plugin registers itself inside a try, so a broken file stops the plugin and not the site', 1 === preg_match( '~try\s*\{[^}]*ADVCM_Capabilities::register\(\);.*ADVCM_Updater::register\(\);.*\}\s*catch\s*\(\s*Throwable~s', $main ) );
check( 'and refuses to load on a PHP below its floor', false !== strpos( $main, "version_compare( PHP_VERSION, '7.4', '<' )" ) );

foreach ( array( 'wordpress-cache-manager.php', 'uninstall.php' ) as $file ) {
	check( $file . ' catches Throwable, not only Exception', false !== strpos( file_get_contents( ADVCM_DIR . $file ), 'catch ( Throwable' ) );
}

$catches = 0;

foreach ( $sources as $file ) {
	// Code only: the runner's docblock explains why catch ( Exception ) is wrong, in those words.
	$code = '';

	foreach ( token_get_all( file_get_contents( $file ) ) as $token ) {
		if ( is_array( $token ) && in_array( $token[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
			continue;
		}

		$code .= is_array( $token ) ? $token[1] : $token;
	}

	if ( preg_match( '~catch\s*\(\s*\\\\?Exception\b~', $code ) ) {
		$catches++;
	}
}

check( 'no catch in the plugin is narrower than Throwable', 0 === $catches );

finish();
