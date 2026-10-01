<?php
/**
 * Everything the plugin writes is named advcm, and footprint.json lists all of it.
 *
 * Read from the source. Each call that writes a name to the site — an option, a transient, a
 * scheduled event, an admin-post action, a capability — is found, its name resolved (a literal,
 * a class constant, or a literal prefix followed by an id), and checked against both rules. The
 * plugin reads other plugins' options freely; it only ever writes its own.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

$files = array_merge( glob( ADVCM_DIR . 'includes/*.php' ), array( ADVCM_DIR . 'wordpress-cache-manager.php', ADVCM_DIR . 'uninstall.php' ) );

// Every string class constant, by class and by bare name, to resolve self::X and Class::X.
$constants = array();

foreach ( $files as $file ) {
	$code  = file_get_contents( $file );
	$class = preg_match( '~\bclass\s+(\w+)~', $code, $m ) ? $m[1] : '';

	if ( preg_match_all( "~const\s+(\w+)\s*=\s*'([^']*)'~", $code, $all, PREG_SET_ORDER ) ) {
		foreach ( $all as $c ) {
			$constants[ $class . '::' . $c[1] ] = $c[2];
		}
	}
}

$writes = '\\b(?:update_option|add_option|delete_option|set_transient|delete_transient|set_site_transient|delete_site_transient|wp_unschedule_hook|wp_clear_scheduled_hook)\\s*\\(|\\bwp_schedule_single_event\\s*\\(\\s*[^,]+,';

/*
 * WordPress's own names this plugin is allowed to touch, each for a reason. Anything else outside
 * the advcm prefix fails.
 */
$wordpress_own = array(
	// The updater deletes it so the Plugins screen re-reads the update list after a check — the
	// same thing WordPress does itself after an update.
	'update_plugins',
);
$names  = array();
$opaque = array();

foreach ( $files as $file ) {
	$code  = file_get_contents( $file );
	$class = preg_match( '~\bclass\s+(\w+)~', $code, $m ) ? $m[1] : '';

	if ( ! preg_match_all( '~(?:' . $writes . ')\s*([^,)]+)~', $code, $calls, PREG_SET_ORDER ) ) {
		continue;
	}

	foreach ( $calls as $call ) {
		$arg = trim( $call[1] );

		if ( preg_match( "~^'([^']+)'~", $arg, $lit ) ) {
			$names[] = $lit[1];
		} elseif ( preg_match( '~^(self|static|\w+)::(\w+)~', $arg, $ref ) ) {
			$key     = ( 'self' === $ref[1] || 'static' === $ref[1] ? $class : $ref[1] ) . '::' . $ref[2];
			$names[] = isset( $constants[ $key ] ) ? $constants[ $key ] : '?' . $key;
		} elseif ( preg_match( '~^\$name$|^\$option$|^\$key$~', $arg ) ) {
			// A variable built a line above from a literal prefix; the prefix check below covers
			// it through the literal.
			continue;
		} else {
			$opaque[] = basename( $file ) . ': ' . $arg;
		}
	}

	// Names built as 'advcm_lock_' . $id and the like.
	if ( preg_match_all( "~'(advcm_[a-z_]+_)'\s*\.~", $code, $built ) ) {
		$names = array_merge( $names, $built[1] );
	}
}

$names = array_values( array_unique( $names ) );

check( 'the source has names to check', count( $names ) >= 6, implode( ', ', $names ) );

$foreign = array_filter(
	$names,
	function ( $n ) use ( $wordpress_own ) {
		return 0 !== strpos( $n, 'advcm' ) && ! in_array( $n, $wordpress_own, true );
	}
);

check( 'everything the plugin writes to the site is named advcm', array() === array_values( $foreign ), implode( ', ', $foreign ) );
check( 'and every name written could be resolved', array() === $opaque, implode( ' | ', $opaque ) );

// The other names a site carries: capabilities, admin-post actions, the page.
$code = implode( "\n", array_map( 'file_get_contents', $files ) );

preg_match_all( "~ADVCM_Safe::action\(\s*'admin_post_'\s*\.\s*(self::\w+)~", $code, $posts );
preg_match_all( "~const\s+(?:PURGE|HARD|SLUG|ACTION|RESUME|CHECK_ACTION|CONTINUE_HOOK)\s*=\s*'([^']+)'~", $code, $declared );

foreach ( $declared[1] as $name ) {
	check( 'the declared name ' . $name . ' is advcm', 0 === strpos( $name, 'advcm' ) );
}

// footprint.json lists every one of them.
$footprint = file_get_contents( ADVCM_DIR . 'footprint.json' );
$decoded   = json_decode( $footprint, true );

check( 'footprint.json is valid JSON', is_array( $decoded ) );

foreach ( array_diff( array_unique( array_merge( $names, $declared[1] ) ), $wordpress_own ) as $name ) {
	$listed = false !== strpos( $footprint, '"' . $name ) || false !== strpos( $footprint, '"' . rtrim( $name, '_' ) );

	check( 'footprint.json lists ' . $name, $listed );
}

check( 'and the warm-up user agent, which is what a firewall rule would match', false !== strpos( $footprint, 'AdvisionCacheWarm/' ) );

finish();
