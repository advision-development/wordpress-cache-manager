<?php
/**
 * NitroPack.
 *
 * Read from NitroPack 1.19.4. Two families of function exist and only one is used:
 *
 * - `nitropack_purge()` / `nitropack_invalidate()` **also invalidate the home page and every
 *   archive on each call** (functions.php:1560, 1607), whatever URL they were given. They are
 *   what NitroPack's own save hook uses.
 * - `nitropack_sdk_purge()` / `nitropack_sdk_invalidate()` act on exactly what they are given.
 *
 * So this adapter calls the SDK functions, and a per-URL request touches those URLs only.
 *
 * **Invalidate versus purge.** Invalidating keeps serving the old optimized copy while NitroPack
 * rebuilds it; purging stops serving it at once and the page goes out un-optimized until the
 * rebuild lands. For a URL somebody wants to see changed, purge is right, and it is one page.
 * For the whole site the default is invalidate: a full purge on a large production site was
 * measured re-queuing tens of thousands of pages and serving them un-optimized for hours. A full purge is still possible,
 * behind `nitropack_mode = purge`, which only the stronger capability can ask for.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NitroPack.
 */
class ADVCM_Adapter_Nitropack extends ADVCM_Adapter {

	public function id() {
		return 'nitropack';
	}

	public function label() {
		return 'NitroPack';
	}

	public function stage() {
		return ADVCM_Stages::OPTIMIZER;
	}

	public function detect() {
		if ( ! function_exists( 'nitropack_sdk_invalidate' ) || ! function_exists( 'nitropack_sdk_purge' ) ) {
			return $this->absent( 'not installed' );
		}

		// NULL when the site is not connected to a NitroPack account, and then there is no remote
		// cache to act on.
		if ( ! function_exists( 'get_nitropack_sdk' ) || null === get_nitropack_sdk() ) {
			return $this->absent( 'not connected' );
		}

		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		$reason = 'CacheManager';

		if ( 'all' === $scope ) {
			if ( isset( $options['nitropack_mode'] ) && 'purge' === $options['nitropack_mode'] ) {
				$this->expect( nitropack_sdk_purge( null, null, $reason ), 'full purge' );

				return $this->ok( 'full purge: every page is served un-optimized until NitroPack rebuilds it' );
			}

			$this->expect( nitropack_sdk_invalidate( null, null, $reason ), 'invalidate' );

			return $this->ok( 'invalidated: the old optimized copies keep serving while NitroPack rebuilds them' );
		}

		$done   = 0;
		$failed = array();

		foreach ( $urls as $url ) {
			if ( false === nitropack_sdk_purge( $url, null, $reason ) ) {
				$failed[] = $url;
				continue;
			}

			$done++;
		}

		if ( $done === 0 && ! empty( $urls ) ) {
			throw new RuntimeException( 'NitroPack refused every URL' );
		}

		if ( ! empty( $failed ) ) {
			return $this->partial( $done, count( $urls ), 'NitroPack refused ' . implode( ', ', $failed ) );
		}

		return $this->ok( sprintf( 'purged %d URL(s)', $done ) );
	}

	public function info() {
		return array(
			'version'    => defined( 'NITROPACK_VERSION' ) ? NITROPACK_VERSION : '',
			'auto purge' => get_option( 'nitropack-autoCachePurge', 1 ) ? 'on' : 'off',
		);
	}

	/**
	 * The SDK answers false when its request failed. That is a failure, not an `ok` with a caveat.
	 *
	 * @param mixed  $status What the SDK returned.
	 * @param string $what   Which call.
	 * @return void
	 */
	private function expect( $status, $what ) {
		if ( false === $status ) {
			throw new RuntimeException( 'NitroPack ' . $what . ' returned false' );
		}
	}
}
