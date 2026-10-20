<?php
/**
 * Where jobs are kept, and how little.
 *
 * WordPress is a short buffer, not the history. Three options, none autoloaded:
 *
 * - `advcm_jobs` — the last KEEP clears people started (and WP-CLI), each written after every
 *   step, so a request that dies part-way leaves a record of how far it got (the shutdown handler
 *   in the runner reads it).
 * - `advcm_jobs_auto` — since 0.4.0, the last AUTO_KEEP automatic clears, in a row of their own.
 *   With "each post's own page" on, BMR filled the three automatic places 0.3.2 kept within hours,
 *   and the clears before were gone before anybody looked. Kept apart, an automatic clear never
 *   pushes out a clear somebody pressed, and each of its steps rewrites only its own row.
 * - `advcm_layers` — per layer, the last time it was cleared, by whom and how it went. A few
 *   hundred bytes that answer "when was NitroPack last cleared" after that job has left the
 *   buffer.
 *
 * The full history will be Hawkeye's, once sites report to it, and each job carries `sent` for
 * that. Until 0.4.0 an unsent job stayed past KEEP, up to 30; nothing marks a job sent yet, so
 * that only made the row heavier. The cap is the cap until Hawkeye exists.
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

	/** The row of clears people started. */
	const OPTION = 'advcm_jobs';

	/** The row of automatic clears. */
	const OPTION_AUTO = 'advcm_jobs_auto';

	/** The per-layer summary option. */
	const LAYERS = 'advcm_layers';

	/** How many clears people started are kept. A job is about 5 KB on BMR. */
	const KEEP = 20;

	/** How many automatic clears are kept. */
	const AUTO_KEEP = 20;

	/**
	 * Every stored job, both rows, newest first.
	 *
	 * @return array id => job
	 */
	public static function all() {
		$jobs  = self::row( self::OPTION ) + self::row( self::OPTION_AUTO );
		$order = array();
		$i     = 0;

		// Newest first by when they started; equal times keep each row's own order, so PHP 7.4's
		// unstable sort cannot shuffle them.
		foreach ( $jobs as $id => $job ) {
			$order[ $id ] = array( isset( $job['created'] ) ? (int) $job['created'] : 0, $i++ );
		}

		uksort(
			$jobs,
			function ( $a, $b ) use ( $order ) {
				if ( $order[ $a ][0] !== $order[ $b ][0] ) {
					return $order[ $b ][0] - $order[ $a ][0];
				}

				return $order[ $a ][1] - $order[ $b ][1];
			}
		);

		return $jobs;
	}

	/**
	 * One job, from either row.
	 *
	 * @param string $id Job id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( array( self::OPTION, self::OPTION_AUTO ) as $option ) {
			$row = self::row( $option );

			if ( isset( $row[ $id ] ) ) {
				return $row[ $id ];
			}
		}

		return null;
	}

	/**
	 * Store a job in the row of its source, then trim that row.
	 *
	 * @param array $job Job.
	 * @return void
	 */
	public static function save( array $job ) {
		$option = self::is_auto( $job ) ? self::OPTION_AUTO : self::OPTION;

		self::migrate();

		$jobs = self::row( $option );

		if ( ! isset( $job['sent'] ) ) {
			$job['sent'] = isset( $jobs[ $job['id'] ]['sent'] ) ? (bool) $jobs[ $job['id'] ]['sent'] : false;
		}

		unset( $jobs[ $job['id'] ] );
		$jobs = array( $job['id'] => $job ) + $jobs;

		update_option( $option, self::trim( $jobs, self::OPTION_AUTO === $option ? self::AUTO_KEEP : self::KEEP ), false );
	}

	/**
	 * Move automatic clears 0.3.2 kept in the main row to their own. Each once: it leaves the
	 * main row as it lands in the other. A running one moves too, and is never evicted there.
	 *
	 * @return void
	 */
	private static function migrate() {
		$main  = self::row( self::OPTION );
		$moved = array();

		foreach ( $main as $id => $job ) {
			if ( self::is_auto( $job ) ) {
				$moved[ $id ] = $job;
				unset( $main[ $id ] );
			}
		}

		if ( empty( $moved ) ) {
			return;
		}

		$auto = self::row( self::OPTION_AUTO );

		update_option( self::OPTION_AUTO, self::trim( $auto + $moved, self::AUTO_KEEP ), false );
		update_option( self::OPTION, $main, false );
	}

	/**
	 * Record that Hawkeye has a job.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function mark_sent( $id ) {
		$job = self::get( $id );

		if ( ! is_array( $job ) ) {
			return;
		}

		$job['sent'] = true;
		self::save( $job );
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
	 * The newest $keep jobs of a row. A running job is never evicted: its next run would find
	 * nothing and stop silently half-way, which a burst of small clears could otherwise cause on
	 * purpose.
	 *
	 * @param array $jobs Jobs, newest first.
	 * @param int   $keep How many.
	 * @return array
	 */
	public static function trim( array $jobs, $keep = self::KEEP ) {
		$kept  = array();
		$count = 0;

		foreach ( $jobs as $id => $job ) {
			$running = isset( $job['state'] ) && 'running' === $job['state'];

			if ( ++$count <= $keep || $running ) {
				$kept[ $id ] = $job;
			}
		}

		return $kept;
	}

	/**
	 * Whether a job is an automatic clear.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	private static function is_auto( array $job ) {
		return isset( $job['source'] ) && 'auto' === $job['source'];
	}

	/**
	 * A row, as a list of jobs by id.
	 *
	 * @param string $option Option name.
	 * @return array
	 */
	private static function row( $option ) {
		$jobs = get_option( $option, array() );

		return is_array( $jobs ) ? $jobs : array();
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
