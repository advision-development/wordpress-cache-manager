<?php
/**
 * The layers this plugin knows.
 *
 * Adding a vendor is one adapter class and one line here. The list's order does not matter —
 * the runner sorts by stage — which is deliberate: an order that depended on where somebody
 * inserted a line would be the order nobody chose.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adapter registry.
 */
final class ADVCM_Registry {

	/**
	 * Every adapter, in no particular order.
	 *
	 * @return ADVCM_Adapter[]
	 */
	public static function adapters() {
		$adapters = array(
			new ADVCM_Adapter_Elementor(),
			new ADVCM_Adapter_Bricks(),
			new ADVCM_Adapter_Wp_Rocket_Assets(),
			new ADVCM_Adapter_Object_Cache(),
			new ADVCM_Adapter_Wp_Rocket(),
			new ADVCM_Adapter_Wp_Engine(),
			new ADVCM_Adapter_Nitropack(),
		);

		/**
		 * Filter the adapters.
		 *
		 * Anything returned that is not an ADVCM_Adapter is dropped, so a filter cannot hand the
		 * runner something it would call blind.
		 *
		 * @param ADVCM_Adapter[] $adapters Adapters.
		 */
		$filtered = apply_filters( 'advcm_adapters', $adapters );

		if ( ! is_array( $filtered ) ) {
			return $adapters;
		}

		return array_values(
			array_filter(
				$filtered,
				function ( $adapter ) {
					return $adapter instanceof ADVCM_Adapter;
				}
			)
		);
	}
}
