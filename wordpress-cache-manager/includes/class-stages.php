<?php
/**
 * The order.
 *
 * Clear from the source outward. Every layer rebuilds from the one beneath it, so clearing an
 * outer one first lets it re-cache stale content straight away — NitroPack re-optimizing a page
 * it fetched through a host cache still holding the old copy, a page cache storing a render that
 * referenced CSS about to be regenerated. The numbers are the order, and nothing else is.
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

	/** Generated CSS of page builders. */
	const BUILDER = 1;

	/** Minified and combined asset files. */
	const ASSETS = 2;

	/** The persistent object cache and transients. */
	const OBJECT = 3;

	/** A page cache plugin on the origin. */
	const PAGE = 4;

	/** The host's own page cache. */
	const HOST = 5;

	/** A SaaS optimizer serving its own cached copy (NitroPack). */
	const OPTIMIZER = 6;

	/** A CDN in front of the site. */
	const CDN = 7;

	/**
	 * Stages that serve a stored page. Held when builder CSS failed part-way, because clearing
	 * them over half-rebuilt CSS makes them store the broken page.
	 *
	 * @return int[]
	 */
	public static function page_serving() {
		return array( self::PAGE, self::HOST, self::OPTIMIZER, self::CDN );
	}

	/**
	 * What a person reads for a stage.
	 *
	 * @param int $stage Stage number.
	 * @return string
	 */
	public static function label( $stage ) {
		$labels = array(
			self::BUILDER   => __( 'Builder CSS', 'advcm' ),
			self::ASSETS    => __( 'Asset files', 'advcm' ),
			self::OBJECT    => __( 'Object cache', 'advcm' ),
			self::PAGE      => __( 'Page cache', 'advcm' ),
			self::HOST      => __( 'Host cache', 'advcm' ),
			self::OPTIMIZER => __( 'Optimizer', 'advcm' ),
			self::CDN       => __( 'CDN', 'advcm' ),
		);

		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : (string) $stage;
	}
}
