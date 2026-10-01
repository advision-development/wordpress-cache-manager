<?php
/**
 * The order.
 *
 * Clear from the source outward. Every layer rebuilds from the one beneath it, so clearing an
 * outer one first lets it re-cache stale content straight away.
 *
 * Where each layer sits was read from the code on a WP Engine site running NitroPack, not
 * assumed, and the first version had one of them wrong. A request travels
 *
 *     CDN → host page cache (WP Engine's Varnish) → PHP: page cache plugin or NitroPack's
 *     advanced-cache.php drop-in → WordPress (object cache, builder CSS)
 *
 * so NitroPack is **inside** the host cache, not outside it: its drop-in answers from PHP, behind
 * Varnish. Clearing WP Engine before NitroPack let Varnish store NitroPack's old copy in between.
 * NitroPack's own WP Engine integration purges Varnish after every NitroPack purge and again when
 * a rebuilt page is ready (its `cache_ready` webhook), which is the order its authors designed
 * for and the order here.
 *
 * The object cache comes first, ahead of builder CSS, because the two are independent (Elementor
 * deletes its own CSS meta through the metadata API, which invalidates the object cache entries
 * it touches) and because a pause after it is the useful one: memcached refills from real
 * traffic while the page caches are still serving, so when those go cold the database is not
 * taking every render at once. From builder CSS to the host cache nothing may pause — see
 * ADVCM_Modes.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stage numbers.
 */
final class ADVCM_Stages {

	/** The persistent object cache and transients. */
	const OBJECT = 1;

	/** Generated CSS of page builders. */
	const BUILDER = 2;

	/** Minified and combined asset files, which are built from the builder's CSS. */
	const ASSETS = 3;

	/** A page cache plugin answering from PHP (WP Rocket). */
	const PAGE = 4;

	/** NitroPack: an optimizer whose drop-in answers from PHP, inside the host cache. */
	const OPTIMIZER = 5;

	/** The host's own page cache, in front of PHP. */
	const HOST = 6;

	/** Requesting the cleared pages once, so the next visitor is not the one who waits. */
	const WARM = 7;

	/** A CDN in front of the site. */
	const CDN = 8;

	/**
	 * Stages held when builder CSS failed or only partly cleared: everything after it. Each of
	 * them would otherwise store, minify, optimize or request pages rendered against CSS that is
	 * half rebuilt — the failure this plugin exists to prevent.
	 *
	 * @return int[]
	 */
	public static function held_by_builder() {
		return array( self::ASSETS, self::PAGE, self::OPTIMIZER, self::HOST, self::WARM, self::CDN );
	}

	/**
	 * The stages before which no pause is allowed: from builder CSS through the host cache. The
	 * moment builder CSS is deleted, a page still held by a page cache points at files that are
	 * gone, so everything that stores pages follows at once.
	 *
	 * @return int[]
	 */
	public static function no_pause_before() {
		return array( self::ASSETS, self::PAGE, self::OPTIMIZER, self::HOST );
	}

	/**
	 * What a person reads for a stage.
	 *
	 * @param int $stage Stage number.
	 * @return string
	 */
	public static function label( $stage ) {
		$labels = array(
			self::OBJECT    => __( 'Object cache', 'advcm' ),
			self::BUILDER   => __( 'Builder CSS', 'advcm' ),
			self::ASSETS    => __( 'Asset files', 'advcm' ),
			self::PAGE      => __( 'Page cache', 'advcm' ),
			self::OPTIMIZER => __( 'Optimizer', 'advcm' ),
			self::HOST      => __( 'Host cache', 'advcm' ),
			self::WARM      => __( 'Warm-up', 'advcm' ),
			self::CDN       => __( 'CDN', 'advcm' ),
		);

		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : (string) $stage;
	}
}
