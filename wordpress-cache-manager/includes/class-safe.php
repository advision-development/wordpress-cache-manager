<?php
/**
 * Every hook this plugin adds goes through here, so nothing it does can take a page down.
 *
 * The plugin is not only the purge. Its capability filter runs on every permission check on
 * every request, its admin bar menu is built on every page a logged-in editor sees, and its
 * updater sits in WordPress's update transient. An exception escaping any of those is a white
 * screen for the whole site, for a cache button. So:
 *
 * - **A filter that throws returns what it was given.** WordPress carries on exactly as if this
 *   plugin were not installed: the capability check answers from the user's real capabilities,
 *   the update list is the one WordPress built.
 * - **An action that throws stops there.** The page goes on.
 * - **A screen that throws prints a notice**, not half a page and a 500.
 * - **Every catch is `Throwable`**, so a PHP `Error` — a missing class, a type error, a parse
 *   error in a file being autoloaded — is caught as surely as an exception.
 * - **Nothing is swallowed silently.** Each failure is written to the PHP error log with the
 *   hook it happened in, and kept as the last error for the status screen.
 *
 * The runner's own guards around other plugins' code are separate and stay: those decide what a
 * step reports. These decide that a fault of ours, anywhere, costs nothing but a log line.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guarded hooks.
 */
final class ADVCM_Safe {

	/** Where the last error is kept for the screen. */
	const LAST_ERROR = 'advcm_last_error';

	/**
	 * Faults already reported in this request.
	 *
	 * @var array
	 */
	private static $seen = array();

	/**
	 * Add a filter whose callback can never break the filtered value.
	 *
	 * @param string   $hook     Filter.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	public static function filter( $hook, $callback, $priority = 10, $args = 1 ) {
		add_filter(
			$hook,
			function ( ...$given ) use ( $hook, $callback ) {
				try {
					return call_user_func_array( $callback, $given );
				} catch ( Throwable $e ) {
					self::report( $hook, $e );

					return isset( $given[0] ) ? $given[0] : null;
				}
			},
			$priority,
			$args
		);
	}

	/**
	 * Add an action whose callback can never break the request.
	 *
	 * @param string   $hook     Action.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 * @return void
	 */
	public static function action( $hook, $callback, $priority = 10, $args = 1 ) {
		add_action(
			$hook,
			function ( ...$given ) use ( $hook, $callback ) {
				try {
					call_user_func_array( $callback, $given );
				} catch ( Throwable $e ) {
					self::report( $hook, $e );
				}
			},
			$priority,
			$args
		);
	}

	/**
	 * Run something and survive it.
	 *
	 * @param string   $where    Name for the log.
	 * @param callable $callback What to run.
	 * @param mixed    $fallback What to return when it throws.
	 * @return mixed
	 */
	public static function run( $where, $callback, $fallback = null ) {
		try {
			return call_user_func( $callback );
		} catch ( Throwable $e ) {
			self::report( $where, $e );

			return $fallback;
		}
	}

	/**
	 * A message with the installation's absolute paths taken out, for the screen. The full text
	 * still goes to the PHP error log, where whoever reads it can see the server.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function without_paths( $message ) {
		$message = (string) $message;

		foreach ( array( defined( 'ABSPATH' ) ? ABSPATH : '', defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/' : '' ) as $root ) {
			if ( '' !== $root ) {
				$message = str_replace( array( $root, rtrim( $root, '/' ) ), '', $message );
			}
		}

		return $message;
	}

	/**
	 * Write a failure down. Never throws itself: a logger that can fail is one more thing that can
	 * take a page down.
	 *
	 * @param string    $where Hook or place.
	 * @param Throwable $e     What went wrong.
	 * @return void
	 */
	public static function report( $where, $e ) {
		try {
			// The log gets the whole message; the screen, read by editors, gets it without paths.
			$raw  = get_class( $e ) . ': ' . $e->getMessage();
			$what = get_class( $e ) . ': ' . self::without_paths( $e->getMessage() );
			$key  = $where . '|' . $what;

			// Once per request per fault. A filter that fails runs on every capability check, and
			// writing the log and an option each time would turn one fault into a slow site.
			if ( isset( self::$seen[ $key ] ) ) {
				return;
			}

			self::$seen[ $key ] = true;

			error_log( sprintf( '[advcm] %s: %s in %s:%d', $where, $raw, basename( $e->getFile() ), $e->getLine() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- the only record of a fault this class kept from reaching the page.

			if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
				return;
			}

			// And across requests: the same fault already recorded in the last five minutes is
			// not written again.
			$last = get_option( self::LAST_ERROR, array() );

			if ( is_array( $last ) && isset( $last['where'], $last['what'], $last['at'] ) && $last['where'] === $where && $last['what'] === $what && time() - (int) $last['at'] < 300 ) {
				return;
			}

			update_option(
				self::LAST_ERROR,
				array(
					'at'    => time(),
					'where' => (string) $where,
					'what'  => $what,
				),
				false
			);
		} catch ( Throwable $ignored ) {
			unset( $ignored );
		}
	}
}
