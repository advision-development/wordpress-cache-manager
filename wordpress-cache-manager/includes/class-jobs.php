<?php
/**
 * Where jobs are kept, and how little.
 *
 * WordPress is a short buffer, not the history. Two options, neither autoloaded:
 *
 * - `advcm_jobs` — the last KEEP jobs, each written after every step, so a request that dies
 *   part-way leaves a record of how far it got (the shutdown handler in the runner reads it).
 * - `advcm_layers` — per layer, the last time it was cleared, by whom and how it went. A few
 *   hundred bytes that answer "when was NitroPack last cleared" after that job has left the
 *   buffer.
 *
 * The full history is Hawkeye's, once sites report to it: every job goes there and expires on a
 * Firestore TTL. So each job carries `sent`, and a job Hawkeye has not confirmed stays in the
 * buffer past KEEP — up to UNSENT_CAP, so a Hawkeye that is down for a week cannot grow this row
 * without bound either. Until the reporting exists nothing marks a job sent, and the cap is what
 * holds the size.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Job storage.
 */
final class ADVCM_Jobs {

	/** The jobs option. */
	const OPTION = 'advcm_jobs';

	/** The per-layer summary option. */
	const LAYERS = 'advcm_layers';

	/** How many jobs are kept. */
	const KEEP = 10;

	/**
	 * How many jobs the buffer may hold while some are waiting to reach Hawkeye.
	 *
	 * A job is ~2–3 KB, so this bounds the row at well under 100 KB whatever Hawkeye is doing.
	 */
	const UNSENT_CAP = 30;

	/**
	 * Every stored job, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		$jobs = get_option( self::OPTION, array() );

		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * One job.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$jobs = self::all();

		return isset( $jobs[ $id ] ) ? $jobs[ $id ] : null;
	}

	/**
	 * Store a job, then trim the buffer.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function save( array $job ) {
		$jobs = self::all();

		if ( ! isset( $job['sent'] ) ) {
			$job['sent'] = isset( $jobs[ $job['id'] ]['sent'] ) ? (bool) $jobs[ $job['id'] ]['sent'] : false;
		}

		unset( $jobs[ $job['id'] ] );
		$jobs = array( $job['id'] => $job ) + $jobs;

		update_option( self::OPTION, self::trim( $jobs ), false );
	}

	/**
	 * Record that Hawkeye has a job, so the buffer may let it go.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function mark_sent( $id ) {
		$jobs = self::all();

		if ( ! isset( $jobs[ $id ] ) ) {
			return;
		}

		$jobs[ $id ]['sent'] = true;

		update_option( self::OPTION, self::trim( $jobs ), false );
	}

	/**
	 * Jobs Hawkeye does not have yet, oldest first — the order to send them in.
	 *
	 * Only finished ones: a running job is still being written.
	 *
	 * @return array
	 */
	public static function unsent() {
		$out = array();

		foreach ( array_reverse( self::all(), true ) as $id => $job ) {
			if ( empty( $job['sent'] ) && ! empty( $job['finished'] ) ) {
				$out[ $id ] = $job;
			}
		}

		return $out;
	}

	/**
	 * The newest KEEP jobs, plus unsent ones after them, up to UNSENT_CAP in all.
	 *
	 * @param array $jobs Jobs, newest first.
	 * @return array
	 */
	public static function trim( array $jobs ) {
		$kept  = array();
		$count = 0;

		foreach ( $jobs as $id => $job ) {
			$count++;

			// The second half is the cap. There was also a `break` at the cap after this, and
			// deleting it failed no assertion: KEEP is below UNSENT_CAP, so nothing past the cap
			// could be kept anyway. It is gone rather than left looking like a second guard.
			if ( $count <= self::KEEP || ( empty( $job['sent'] ) && count( $kept ) < self::UNSENT_CAP ) ) {
				$kept[ $id ] = $job;
			}
		}

		return $kept;
	}

	/**
	 * The per-layer summary.
	 *
	 * @return array layer id => array( at, by, status, message, scope, job, ok_at )
	 */
	public static function layers() {
		$layers = get_option( self::LAYERS, array() );

		return is_array( $layers ) ? $layers : array();
	}

	/**
	 * Record a finished step against its layer.
	 *
	 * Only steps that tried to clear something: `ok`, `partial`, `failed`. A skip or a hold
	 * cleared nothing, and recording it would overwrite the last time the layer really was
	 * cleared with a moment it was not. `ok_at` is kept apart from the last attempt, so a failure
	 * today does not hide that the layer was cleared yesterday.
	 *
	 * @param array $job  The job.
	 * @param array $step The finished step.
	 * @return void
	 */
	public static function record_layer( array $job, array $step ) {
		if ( ! in_array( $step['status'], array( 'ok', 'partial', 'failed' ), true ) ) {
			return;
		}

		$layers   = self::layers();
		$previous = isset( $layers[ $step['id'] ] ) ? $layers[ $step['id'] ] : array();

		$layers[ $step['id'] ] = array(
			'at'      => time(),
			'by'      => isset( $job['by'] ) ? (int) $job['by'] : 0,
			'source'  => isset( $job['source'] ) ? (string) $job['source'] : '',
			'status'  => $step['status'],
			'message' => (string) $step['message'],
			'scope'   => isset( $job['scope'] ) ? $job['scope'] : 'all',
			'job'     => $job['id'],
			'ok_at'   => 'ok' === $step['status'] ? time() : ( isset( $previous['ok_at'] ) ? (int) $previous['ok_at'] : 0 ),
		);

		update_option( self::LAYERS, $layers, false );
	}
}
