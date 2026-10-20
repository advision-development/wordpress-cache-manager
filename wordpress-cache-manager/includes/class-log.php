<?php
/**
 * Who changed what: a short record of every change a person makes through this plugin.
 *
 * Since 0.4.0 editors can do everything administrators can — change rules, pause auto-clear,
 * purge NitroPack in full — and the owner chose a record over a restriction: every such action
 * names who took it. Each tab shows the last few records of its own section.
 *
 * Bounded twice over: each section keeps its last PER_SECTION records, so a busy day of page
 * clears can never push the settings and rule changes out, and each value is cut to MAX_CHARS.
 * One row, not autoloaded, read only by this plugin's screen and the actions that write it.
 *
 * Written only by requests a person made. WP-Cron and the screen's tick never write here:
 * automatic clears are in History already.
 *
 * Read-modify-write without a lock: two people acting in the same instant can lose one record.
 * A lock would cost more than the record.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The change log.
 */
final class ADVCM_Log {

	/** The row. */
	const OPTION = 'advcm_log';

	/** Records kept per section. */
	const PER_SECTION = 20;

	/** Records a tab shows. */
	const SHOW = 10;

	/** Characters kept of a summary, a before or an after. */
	const MAX_CHARS = 200;

	/** One section per tab. */
	const SECTIONS = array( 'status', 'clear', 'check', 'history', 'freshness', 'auto', 'settings' );

	/**
	 * Record something a person did.
	 *
	 * @param string $section One of SECTIONS; anything else is not written.
	 * @param string $action  A short key: started, rule_added, changed, …
	 * @param string $summary One line a person reads.
	 * @param string $before  The value before, when this changed one.
	 * @param string $after   The value after.
	 * @param bool   $person  False for something nobody did, such as WordPress updating this
	 *                        plugin on its own: recorded with no user.
	 * @return void
	 */
	public static function add( $section, $action, $summary, $before = '', $after = '', $person = true ) {
		try {
			if ( ! in_array( $section, self::SECTIONS, true ) ) {
				return;
			}

			$user = $person && function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
			$log  = self::all();

			array_unshift(
				$log[ $section ],
				array(
					'at'      => time(),
					'user'    => $person ? (int) get_current_user_id() : 0,
					// The login as it is now, so a record still reads after the account is gone.
					'login'   => is_object( $user ) && isset( $user->user_login ) ? (string) $user->user_login : '',
					'section' => $section,
					'action'  => self::cut( $action ),
					'summary' => self::cut( $summary ),
					'before'  => self::cut( $before ),
					'after'   => self::cut( $after ),
				)
			);

			$log[ $section ] = array_slice( $log[ $section ], 0, self::PER_SECTION );

			update_option( self::OPTION, $log, false );
		} catch ( Throwable $e ) {
			// Recording a change must never stop the change it records.
			ADVCM_Safe::report( 'log', $e );
		}
	}

	/**
	 * Record something unless the same person recorded the same thing a moment ago: a probe is a
	 * reloadable link, and a reload is not a new action.
	 *
	 * @param string $section Section.
	 * @param string $action  Action key.
	 * @param string $summary Summary.
	 * @param int    $within  Seconds within which the same record is not repeated.
	 * @return void
	 */
	public static function add_once( $section, $action, $summary, $within ) {
		$user = (int) get_current_user_id();

		foreach ( self::recent( $section, self::PER_SECTION ) as $record ) {
			if ( $record['user'] === $user && $record['action'] === self::cut( $action ) && $record['summary'] === self::cut( $summary ) && time() - $record['at'] < $within ) {
				return;
			}
		}

		self::add( $section, $action, $summary );
	}

	/**
	 * A section's records, newest first.
	 *
	 * @param string $section Section.
	 * @param int    $limit   How many.
	 * @return array
	 */
	public static function recent( $section, $limit = self::SHOW ) {
		$log = self::all();

		return isset( $log[ $section ] ) ? array_slice( $log[ $section ], 0, max( 0, (int) $limit ) ) : array();
	}

	/**
	 * The row, every section present and a list. A row that is not what this class writes is
	 * started again rather than trusted.
	 *
	 * @return array
	 */
	private static function all() {
		$stored = get_option( self::OPTION, array() );
		$log    = array();

		foreach ( self::SECTIONS as $section ) {
			$records = is_array( $stored ) && isset( $stored[ $section ] ) && is_array( $stored[ $section ] ) ? $stored[ $section ] : array();

			$log[ $section ] = array_values(
				array_filter(
					$records,
					function ( $record ) {
						return is_array( $record ) && isset( $record['at'], $record['user'], $record['action'], $record['summary'] );
					}
				)
			);
		}

		return $log;
	}

	/**
	 * A value cut to MAX_CHARS characters, the last one an ellipsis when it was cut.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function cut( $value ) {
		$value = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );

		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value ) > self::MAX_CHARS ? mb_substr( $value, 0, self::MAX_CHARS - 1 ) . '…' : $value;
		}

		return strlen( $value ) > self::MAX_CHARS ? substr( $value, 0, self::MAX_CHARS - 3 ) . '...' : $value;
	}
}
