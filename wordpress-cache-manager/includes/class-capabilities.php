<?php
/**
 * Who may purge.
 *
 * Two capabilities, granted from capabilities people already hold rather than written into
 * roles. Writing them into roles would change the site's role table on activation and leave it
 * changed after uninstall; mapping them means deactivating the plugin takes them away with it.
 *
 * - `advcm_purge` — purge a URL, purge the site, see the status screen. Editors and up
 *   (`edit_others_posts`), because the people asking for a purge in the tickets are the people
 *   publishing, and a button they cannot press is a ticket for a developer.
 * - `advcm_purge_hard` — the two things with a cost somebody should own: a full NitroPack purge
 *   (every page un-optimized until rebuilt) and overriding the hold after builder CSS failed.
 *   Administrators (`manage_options`).
 *
 * Both are filterable, and the menu and the page ask for the same one — another settings page
 * shipped with the menu on `read` and the page on `manage_options`, so everybody saw an item
 * almost nobody could open.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Capability mapping.
 */
final class ADVCM_Capabilities {

	/** Purge URLs and the site. */
	const PURGE = 'advcm_purge';

	/** Full NitroPack purge and overriding a hold. */
	const HARD = 'advcm_purge_hard';

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		// Guarded above all: this runs on every capability check of every request.
		ADVCM_Safe::filter( 'user_has_cap', array( __CLASS__, 'grant' ), 10, 1 );
	}

	/**
	 * The existing capability each one is granted from.
	 *
	 * @return array ours => theirs
	 */
	public static function bases() {
		return array(
			self::PURGE => (string) apply_filters( 'advcm_purge_base_capability', 'edit_others_posts' ),
			self::HARD  => (string) apply_filters( 'advcm_purge_hard_base_capability', 'manage_options' ),
		);
	}

	/**
	 * Add ours to a user's capabilities where the base one is held.
	 *
	 * @param array $allcaps What the user has.
	 * @return array
	 */
	public static function grant( $allcaps ) {
		if ( ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		foreach ( self::bases() as $ours => $theirs ) {
			if ( ! empty( $allcaps[ $theirs ] ) ) {
				$allcaps[ $ours ] = true;
			}
		}

		return $allcaps;
	}
}
