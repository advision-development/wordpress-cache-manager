<?php
/**
 * One cache layer, as the pipeline sees it.
 *
 * An adapter is the only place that knows a vendor. It says which stage it belongs to, whether
 * its layer is present on this site right now, and how to clear it. It never decides the
 * order — the stage number does — and it never catches its own failures: the runner wraps every
 * call, so an adapter that throws, or that calls a function its plugin no longer has, costs one
 * step and nothing else.
 *
 * `detect()` is asked at run time, not read from a stored inventory. A plugin removed since the
 * last time anybody looked is then `skipped: not installed`, which is the whole point.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Base class for a layer.
 */
abstract class ADVCM_Adapter {

	/**
	 * Stable id, used in requests, results and the console. Never renamed once shipped.
	 *
	 * @return string
	 */
	abstract public function id();

	/**
	 * What a person reads.
	 *
	 * @return string
	 */
	abstract public function label();

	/**
	 * Which stage this layer is cleared in. One of the ADVCM_Stages constants.
	 *
	 * @return int
	 */
	abstract public function stage();

	/**
	 * Whether the layer is here, right now.
	 *
	 * Answer with `present()` or `absent( $reason )`. Only cheap, side-effect-free checks: the
	 * preflight calls this for every layer on every page that shows the plan.
	 *
	 * @return array array( present => bool, reason => string )
	 */
	abstract public function detect();

	/**
	 * Clear the layer.
	 *
	 * Throwing is allowed and expected to be caught by the runner. Return `ok()`, `partial()`
	 * or `not_applicable()`; a boolean is not a result.
	 *
	 * @param string $scope   `all` or `urls`.
	 * @param array  $urls    Absolute, already validated URLs when the scope is `urls`.
	 * @param array  $options Request options (e.g. `nitropack_mode`).
	 * @return array
	 */
	abstract public function clear( $scope, array $urls, array $options );

	/**
	 * Which scopes the layer can honour. A layer that only clears everything says so, and a
	 * per-URL request skips it rather than silently widening to the whole site.
	 *
	 * @return array
	 */
	public function scopes() {
		return array( 'all', 'urls' );
	}

	/**
	 * Whether this layer is only reported, never cleared.
	 *
	 * The plan reads this so the screen does not promise a clear that will not happen: Bricks
	 * showed "will be cleared" on the first site it was installed on, and then every run
	 * skipped it.
	 *
	 * @return bool
	 */
	public function report_only() {
		return false;
	}

	/**
	 * Facts for the status screen: versions, settings that matter, timestamps.
	 *
	 * @return array label => value
	 */
	public function info() {
		return array();
	}

	/**
	 * A detection answer: present.
	 *
	 * @return array
	 */
	protected function present() {
		return array(
			'present' => true,
			'reason'  => '',
		);
	}

	/**
	 * A detection answer: absent, and why.
	 *
	 * @param string $reason `not installed`, `inactive`, `not connected`, …
	 * @return array
	 */
	protected function absent( $reason ) {
		return array(
			'present' => false,
			'reason'  => (string) $reason,
		);
	}

	/**
	 * A clear that did everything asked.
	 *
	 * @param string $message What was done, in words.
	 * @return array
	 */
	protected function ok( $message ) {
		return array(
			'status'  => 'ok',
			'message' => (string) $message,
		);
	}

	/**
	 * A clear that did some of it.
	 *
	 * @param int    $done    How many.
	 * @param int    $total   Out of.
	 * @param string $message Why not all.
	 * @return array
	 */
	protected function partial( $done, $total, $message ) {
		return array(
			'status'  => 'partial',
			'message' => sprintf( '%d of %d: %s', (int) $done, (int) $total, $message ),
		);
	}

	/**
	 * A layer that is present but has nothing to do for this request.
	 *
	 * @param string $message Why.
	 * @return array
	 */
	protected function not_applicable( $message ) {
		return array(
			'status'  => 'skipped',
			'message' => 'not applicable: ' . $message,
		);
	}
}
