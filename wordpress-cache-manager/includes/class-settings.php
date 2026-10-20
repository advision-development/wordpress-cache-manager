<?php
/**
 * The Settings tab: every setting a person may change, in one place, and the record of who did.
 *
 * Until 0.4.0 the pause and own-page sat on the Auto-clear tab next to the rules, and the post
 * types "any content" leaves out existed only as a code filter. They live here now:
 *
 * - pause auto-clear — still its own row, `advcm_rules_paused` (ADVCM_Auto)
 * - each post's own page — still the reserved rule `own-page` in `advcm_rules` (ADVCM_Auto), so
 *   a save on a site using none of this still reads one option
 * - own-page ignores saves that change nothing a visitor sees
 * - the post types auto-clear leaves out
 * - the NitroPack choice a new rule starts with
 * - whether a clear of some pages first measures how old their cached copies were (0.5.0)
 *
 * The last three are this row, `advcm_settings`, not autoloaded. The save hook reads it only
 * after a rule is known to be on, the save is visible and the pause is off.
 *
 * Not here, on purpose: the plugin's auto-update, the mode timings, who may use the plugin, rate
 * limits and storage caps. Those are filters and constants: a checkbox for them is a way to break
 * a site or the release path, and nobody asked for one.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings.
 */
final class ADVCM_Settings {

	/** The row. */
	const OPTION = 'advcm_settings';

	/** The admin-post action and nonce. */
	const ACTION = 'advcm_settings';

	/** The longest, in hours, a page should stay cached: what Freshness counts as past it. */
	const TARGETS = array( 1, 2, 3, 6, 12, 24 );

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		ADVCM_Safe::action( 'admin_post_' . self::ACTION, array( __CLASS__, 'guarded_handle' ) );
	}

	/**
	 * The settings, every key present and the right shape.
	 *
	 * @return array own_skip_unchanged, excluded_types (string[] or null for the shipped list),
	 *               nitropack_default, measure_before_clear, version_seen.
	 */
	public static function get() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		$excluded = null;

		if ( isset( $stored['excluded_types'] ) && is_array( $stored['excluded_types'] ) ) {
			// A type the site no longer registers is dropped on the read: nothing to leave out.
			$registered = function_exists( 'get_post_types' ) ? get_post_types( array(), 'names' ) : array();
			$excluded   = array_values( array_intersect( array_map( 'strval', $stored['excluded_types'] ), array_values( $registered ) ) );
		}

		return array(
			'own_skip_unchanged' => ! array_key_exists( 'own_skip_unchanged', $stored ) || ! empty( $stored['own_skip_unchanged'] ),
			'excluded_types'     => $excluded,
			'nitropack_default'  => isset( $stored['nitropack_default'] ) && 'invalidate' === $stored['nitropack_default'] ? 'invalidate' : 'purge',
			'measure_before_clear' => ! array_key_exists( 'measure_before_clear', $stored ) || ! empty( $stored['measure_before_clear'] ),
			'cache_target'         => isset( $stored['cache_target'] ) && in_array( (int) $stored['cache_target'], self::TARGETS, true ) ? (int) $stored['cache_target'] : 3,
			'version_seen'       => isset( $stored['version_seen'] ) ? (string) $stored['version_seen'] : '',
		);
	}

	/**
	 * The post types the Settings tab offers to leave out: public and viewable, less the three
	 * that are always left out and those a shipped prefix entry (`acf-*`, …) covers, which stay
	 * left out without a box anybody could untick.
	 *
	 * @return string[]
	 */
	public static function viewable_types() {
		$out = array();

		foreach ( function_exists( 'get_post_types' ) ? get_post_types( array( 'public' => true ), 'names' ) : array() as $type ) {
			$type = (string) $type;

			if ( ! is_post_type_viewable( $type ) || in_array( $type, ADVCM_Auto::ALWAYS_EXCLUDED, true ) || self::prefixed( $type ) ) {
				continue;
			}

			$out[] = $type;
		}

		sort( $out );

		return $out;
	}

	/**
	 * Whether a shipped prefix entry covers a type.
	 *
	 * @param string $type Post type.
	 * @return bool
	 */
	public static function prefixed( $type ) {
		foreach ( ADVCM_Auto::EXCLUDED as $entry ) {
			if ( '*' === substr( $entry, -1 ) && 0 === strpos( $type, substr( $entry, 0, -1 ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Keep the version this plugin last ran as, for the "updated from X to Y" record.
	 *
	 * @param string $version Version.
	 * @return void
	 */
	public static function set_version_seen( $version ) {
		self::write( array( 'version_seen' => (string) $version ) );
	}

	/**
	 * Apply the Settings form, or one of its buttons. Every change is recorded.
	 *
	 * @param array $input Unslashed input.
	 * @return string Empty on success, else the refusal.
	 */
	public static function save_form( array $input ) {
		$op = isset( $input['op'] ) && is_string( $input['op'] ) ? $input['op'] : '';

		$switches = array(
			'pause'   => array( 'pause', __( 'Auto-clear paused', 'advcm' ), 'off', 'on' ),
			'resume'  => array( 'pause', __( 'Auto-clear resumed', 'advcm' ), 'on', 'off' ),
			'own_on'  => array( 'own_page', __( 'Each post\'s own page switched on', 'advcm' ), 'off', 'on' ),
			'own_off' => array( 'own_page', __( 'Each post\'s own page switched off', 'advcm' ), 'on', 'off' ),
		);

		if ( isset( $switches[ $op ] ) ) {
			// Already where it was asked to go — a stale tab, a double submit: nothing changes, so
			// nothing is recorded, and a pause keeps the time it began.
			$now = 'pause' === $switches[ $op ][0] ? ADVCM_Auto::paused() : isset( ADVCM_Auto::rules()[ ADVCM_Auto::OWN ] );

			if ( $now === ( 'on' === $switches[ $op ][3] ) ) {
				return '';
			}

			$refusal = ADVCM_Auto::change( array( 'op' => $op ) );

			if ( '' === $refusal ) {
				ADVCM_Log::add( 'settings', $switches[ $op ][0], $switches[ $op ][1], $switches[ $op ][2], $switches[ $op ][3] );
			}

			return $refusal;
		}

		if ( 'save' !== $op ) {
			return __( 'That is not something the Settings tab does.', 'advcm' );
		}

		$now     = self::get();
		$changes = array();

		// The list arrives only with its marker: every box unticked is an empty list, which is
		// not the same as a form that did not carry the list at all.
		if ( ! empty( $input['excluded_present'] ) ) {
			$asked   = isset( $input['excluded'] ) && is_array( $input['excluded'] ) ? array_values( array_unique( array_map( 'strval', $input['excluded'] ) ) ) : array();
			$offered = self::viewable_types();
			$unknown = array_diff( $asked, $offered );

			if ( ! empty( $unknown ) ) {
				/* translators: %s: post type names. */
				return sprintf( __( 'Not a post type this site offers to leave out: %s', 'advcm' ), implode( ', ', array_map( 'sanitize_key', $unknown ) ) );
			}

			sort( $asked );

			$before = null === $now['excluded_types'] ? array_values( array_intersect( ADVCM_Auto::EXCLUDED, $offered ) ) : $now['excluded_types'];
			sort( $before );

			// Stored only when it differs from what applies now. Saving the shipped list unchanged
			// keeps following it, so a type a later release adds to it still applies here.
			if ( $before !== $asked ) {
				$changes['excluded_types'] = $asked;
				ADVCM_Log::add( 'settings', 'excluded_types', __( 'Post types auto-clear leaves out', 'advcm' ), implode( ', ', $before ), implode( ', ', $asked ) );
			}
		}

		if ( isset( $input['nitropack_default'] ) && in_array( $input['nitropack_default'], array( 'purge', 'invalidate' ), true ) && $input['nitropack_default'] !== $now['nitropack_default'] ) {
			$changes['nitropack_default'] = $input['nitropack_default'];
			ADVCM_Log::add( 'settings', 'nitropack_default', __( 'NitroPack for new rules', 'advcm' ), $now['nitropack_default'], $input['nitropack_default'] );
		}

		// A checkbox unticked is absent, so it too comes with a marker.
		if ( ! empty( $input['own_present'] ) ) {
			$skip = ! empty( $input['own_skip_unchanged'] );

			if ( $skip !== $now['own_skip_unchanged'] ) {
				$changes['own_skip_unchanged'] = $skip;
				ADVCM_Log::add( 'settings', 'own_skip_unchanged', __( 'Own page ignores saves that change nothing', 'advcm' ), $now['own_skip_unchanged'] ? 'on' : 'off', $skip ? 'on' : 'off' );
			}
		}

		if ( isset( $input['cache_target'] ) && in_array( (int) $input['cache_target'], self::TARGETS, true ) && (int) $input['cache_target'] !== $now['cache_target'] ) {
			$changes['cache_target'] = (int) $input['cache_target'];
			ADVCM_Log::add( 'settings', 'cache_target', __( 'Longest a page should stay cached', 'advcm' ), $now['cache_target'] . ' h', (int) $input['cache_target'] . ' h' );
		}

		if ( ! empty( $input['measure_present'] ) ) {
			$measure = ! empty( $input['measure_before_clear'] );

			if ( $measure !== $now['measure_before_clear'] ) {
				$changes['measure_before_clear'] = $measure;
				ADVCM_Log::add( 'settings', 'measure_before_clear', __( 'Measure cache age before a clear', 'advcm' ), $now['measure_before_clear'] ? 'on' : 'off', $measure ? 'on' : 'off' );
			}
		}

		if ( ! empty( $changes ) ) {
			self::write( $changes );
		}

		return '';
	}

	/**
	 * Merge values into the row.
	 *
	 * @param array $values Key => value.
	 * @return void
	 */
	private static function write( array $values ) {
		$stored = get_option( self::OPTION, array() );

		update_option( self::OPTION, array_merge( is_array( $stored ) ? $stored : array(), $values ), false );
	}

	/**
	 * The form's handler, guarded: anything that throws sends the person back to the tab with
	 * what went wrong.
	 *
	 * @return void
	 */
	public static function guarded_handle() {
		try {
			self::handle();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'admin_post_' . self::ACTION, $e );
			self::back( __( 'The settings could not be saved because of an error in this plugin. It has been written to the PHP error log.', 'advcm' ) );
		}
	}

	/**
	 * Handle the form. The same capability as the rules form, so a site that takes it back from
	 * editors takes back rules, pause, own-page and settings together.
	 *
	 * @return void
	 */
	public static function handle() {
		if ( ! current_user_can( ADVCM_Capabilities::HARD ) ) {
			wp_die( esc_html__( 'You are not allowed to change these settings.', 'advcm' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION );

		self::back( self::save_form( wp_unslash( $_POST ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	}

	/**
	 * Back to the Settings tab, with the refusal if there was one.
	 *
	 * @param string $notice Refusal.
	 * @return void
	 */
	private static function back( $notice ) {
		if ( '' !== $notice ) {
			set_transient( 'advcm_notice_' . get_current_user_id(), $notice, 60 );
		}

		wp_safe_redirect( ADVCM_Screen::tab_url( 'settings' ) );
		exit;
	}
}
