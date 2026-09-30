<?php
/**
 * Bricks' generated CSS — reported, never regenerated.
 *
 * Read from Bricks 2.2: its regenerate walks every CSS file it owns and calls
 * `Assets_Files::regenerate_css_file()` on each, and **at index 0 that call deletes every file in
 * the CSS directory** before rebuilding them one at a time. The front end enqueues a post's file
 * only if it exists, so a missing file is not an error — it is a page with no styles. Bricks'
 * own button survives this because the browser drives the loop one request per file. Run as one
 * PHP request over a large site it dies part-way and leaves every page not yet reached unstyled,
 * and every page cache in front stores that.
 *
 * It is also rarely needed: with `cssLoading = file` Bricks rewrites a post's file on every save
 * and schedules a full regeneration itself after a theme update. So this adapter reports the
 * state and does nothing, and a request that includes it says so. If a real case ever needs it,
 * the constraints are in the design spec: only on `file`, never through index 0, batched, page
 * caches after the last batch.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bricks CSS, report-only.
 */
class ADVCM_Adapter_Bricks extends ADVCM_Adapter {

	public function id() {
		return 'bricks';
	}

	public function label() {
		return 'Bricks CSS';
	}

	public function stage() {
		return ADVCM_Stages::BUILDER;
	}

	public function detect() {
		if ( ! defined( 'BRICKS_VERSION' ) ) {
			return $this->absent( 'not installed' );
		}

		return $this->present();
	}

	public function report_only() {
		return true;
	}

	// Still answers, in case something calls it: the runner plans a report-only layer as a
	// skip and never gets here.
	public function clear( $scope, array $urls, array $options ) {
		return $this->not_applicable( 'Bricks CSS is reported, not regenerated — Bricks rewrites a post\'s file when it is saved' );
	}

	public function info() {
		$settings = get_option( 'bricks_global_settings', array() );
		$loading  = is_array( $settings ) && isset( $settings['cssLoading'] ) ? (string) $settings['cssLoading'] : 'inline';
		$last     = (int) get_option( 'bricks_css_files_last_generated_timestamp', 0 );

		return array(
			'version'             => BRICKS_VERSION,
			'CSS loading'         => $loading,
			'last full regenerate' => $last > 0 ? gmdate( 'Y-m-d H:i', $last ) . ' UTC' : 'never recorded',
		);
	}
}
