<?php
/**
 * Runs a purge: plan it, then clear each layer in stage order, one guarded step at a time.
 *
 * The rules this class exists to hold, each asked for or measured:
 *
 * - **Order is the stage number.** Adapters are sorted by stage, whatever order they were
 *   registered in and whatever order a request listed them in.
 * - **Plan first, then check again.** `plan()` asks every layer whether it is here so the screen
 *   can show what will happen before anybody presses anything. `run()` asks again right before
 *   each step, because a plugin can be removed between the two.
 * - **Nothing another plugin does can stop the pipeline.** Every call into an adapter — detect
 *   and clear alike — is inside `catch ( Throwable )`. `Throwable`, not `Exception`: calling a
 *   function of a plugin that has been deleted is an `Error`, and `catch ( Exception )` would let
 *   it take the whole run down.
 * - **A fatal costs one step.** What `catch` cannot see — memory exhausted, time limit — ends
 *   the request. Each step is written as `running` before it starts, and a shutdown handler finds
 *   the one left running, records it as `failed: did not finish`, and schedules the rest to
 *   continue in a fresh request.
 * - **One exception to carrying on.** If builder CSS failed or only partly cleared, the stages
 *   that store pages are held: clearing them now would have them store pages rendered against
 *   half-rebuilt CSS, which is the failure this plugin exists to prevent. A builder that is
 *   simply not installed holds nothing. Holding can be overridden, by the stronger capability.
 * - **Every step says what happened.** `ok`, `skipped`, `partial`, `failed`, `held`, with a
 *   message and milliseconds. The job is `done`, `done with skips` or `done with failures`.
 * - **Background modes run on WP-Cron, which is WordPress core.** Each run does what it can,
 *   then schedules the next one: after a mode's pause, or straight away when a step (the
 *   warm-up) hands back a cursor. A lock per job keeps two cron requests from running the same
 *   job at once.
 *
 * @package ADVCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The pipeline.
 */
final class ADVCM_Runner {

	/** The event a job continues on: after a pause, after a step's cursor, after a fatal. */
	const CONTINUE_HOOK = 'advcm_continue';

	/** How long a job's lock is honoured, in seconds. Past it the run that held it is presumed dead. */
	const LOCK_TTL = 600;

	/** How long a request running a job inline keeps calling a step that hands back a cursor. */
	const INLINE_BUDGET = 45;

	/** How late a background job may be before the screen calls it stuck. */
	const STUCK_AFTER = 600;

	/**
	 * The job the current request is running, for the shutdown handler.
	 *
	 * @var string|null
	 */
	private static $current = null;

	/**
	 * Whether the shutdown handler is registered in this request.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * The adapters to use. Injected by the tests; the registry otherwise.
	 *
	 * @var ADVCM_Adapter[]|null
	 */
	private static $adapters = null;

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public static function register() {
		// Always registered, on every request: a scheduled event whose hook nothing answers is
		// what a malware scanner reports as an orphaned cron event.
		ADVCM_Safe::action( self::CONTINUE_HOOK, array( __CLASS__, 'run' ) );
	}

	/**
	 * Use these adapters instead of the registry's. For the test harness.
	 *
	 * @param ADVCM_Adapter[]|null $adapters Adapters, or null to go back to the registry.
	 * @return void
	 */
	public static function use_adapters( $adapters ) {
		self::$adapters = $adapters;
	}

	/**
	 * Every adapter, in stage order.
	 *
	 * Sorted by stage, then by id so two layers in one stage always run in the same order.
	 *
	 * @return ADVCM_Adapter[]
	 */
	public static function adapters() {
		$adapters = null !== self::$adapters ? self::$adapters : ADVCM_Registry::adapters();

		usort(
			$adapters,
			function ( $a, $b ) {
				if ( $a->stage() !== $b->stage() ) {
					return $a->stage() < $b->stage() ? -1 : 1;
				}

				return strcmp( $a->id(), $b->id() );
			}
		);

		return $adapters;
	}

	/**
	 * What a request would do, without doing any of it.
	 *
	 * @param array $request scope, urls, layers, options.
	 * @return array Steps, in the order they would run.
	 */
	public static function plan( array $request ) {
		$request = self::normalise( $request );
		$steps   = array();

		foreach ( self::adapters() as $adapter ) {
			$id = self::guard(
				function () use ( $adapter ) {
					return (string) $adapter->id();
				},
				$error
			);

			if ( null === $id ) {
				// An adapter that cannot even name itself is not one the pipeline can run.
				continue;
			}

			if ( ! empty( $request['layers'] ) && ! in_array( $id, $request['layers'], true ) ) {
				continue;
			}

			$steps[] = self::planned( $adapter, $id, $request );
		}

		return $steps;
	}

	/**
	 * Plan and run a request, then return the job.
	 *
	 * @param array $request scope, urls, refused, layers, options, source, by.
	 * @return array The job as it ended.
	 */
	public static function start( array $request ) {
		$request = self::normalise( $request );

		$job = array(
			'id'       => self::new_id(),
			'created'  => time(),
			'finished' => 0,
			'by'       => $request['by'],
			'source'   => $request['source'],
			'scope'    => $request['scope'],
			'urls'     => $request['urls'],
			'refused'  => $request['refused'],
			'layers'   => $request['layers'],
			'options'  => $request['options'],
			'mode'     => $request['mode'],
			'next_at'  => 0,
			'paused'   => array(),
			'state'    => 'running',
			'steps'    => self::plan( $request ),
		);

		$mode = ADVCM_Modes::get( $job['mode'] );

		// The mode's warm-up settings, over anything the request carried: the presets are the
		// only place those numbers come from.
		foreach ( array( 'warm_site', 'warm_batch', 'warm_delay' ) as $key ) {
			$job['options'][ $key ] = $mode[ $key ];
		}

		ADVCM_Jobs::save( $job );

		if ( $mode['inline'] ) {
			self::run( $job['id'] );
		} else {
			self::schedule( $job['id'], time() );
		}

		$ended = ADVCM_Jobs::get( $job['id'] );

		return is_array( $ended ) ? $ended : $job;
	}

	/**
	 * Run what is left of a job now, in this request, without its remaining pauses.
	 *
	 * The screen's answer to a background job that WP-Cron has not picked up — a site with no
	 * traffic, or a host that blocks the request WordPress makes to wake its own cron. The order
	 * is kept; only the waiting goes.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function resume_now( $id ) {
		$job = ADVCM_Jobs::get( (string) $id );

		if ( ! is_array( $job ) || 'running' !== $job['state'] ) {
			return;
		}

		// Run the rest as Fast does — in this request, no pauses — while the job keeps the mode it
		// was pressed with, so its history still says what somebody chose.
		$job['resumed'] = true;
		ADVCM_Jobs::save( $job );

		wp_clear_scheduled_hook( self::CONTINUE_HOOK, array( $job['id'] ) );

		self::run( $job['id'] );
	}

	/**
	 * Whether a background job has stopped moving.
	 *
	 * @param array $job Job.
	 * @return bool
	 */
	public static function stuck( array $job ) {
		if ( 'running' !== $job['state'] || self::locked( $job['id'] ) ) {
			return false;
		}

		$due = ! empty( $job['next_at'] ) ? (int) $job['next_at'] : (int) $job['created'];

		return time() > $due + self::STUCK_AFTER;
	}

	/**
	 * Run every step of a job that has not run yet.
	 *
	 * Also the continuation after a fatal: steps already finished are left as they are, and the
	 * one that died is already marked failed by the shutdown handler.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	public static function run( $id ) {
		$job = ADVCM_Jobs::get( (string) $id );

		if ( ! is_array( $job ) || empty( $job['steps'] ) || 'running' !== ( isset( $job['state'] ) ? $job['state'] : 'running' ) ) {
			return;
		}

		if ( ! self::lock( $job['id'] ) ) {
			// Another request is running this job right now. It schedules whatever comes next.
			return;
		}

		self::$current = $job['id'];
		$began         = microtime( true );
		$mode          = ADVCM_Modes::get( ! empty( $job['resumed'] ) || ! isset( $job['mode'] ) ? ADVCM_Modes::FAST : $job['mode'] );

		if ( ! self::$registered ) {
			register_shutdown_function( array( __CLASS__, 'shutdown' ) );
			self::$registered = true;
		}

		$adapters = array();

		foreach ( self::adapters() as $adapter ) {
			$adapter_id = self::guard(
				function () use ( $adapter ) {
					return (string) $adapter->id();
				},
				$error
			);

			if ( null !== $adapter_id ) {
				$adapters[ $adapter_id ] = $adapter;
			}
		}

		$index = 0;
		$count = count( $job['steps'] );

		while ( $index < $count ) {
			$step = $job['steps'][ $index ];

			if ( 'pending' !== $step['status'] ) {
				$index++;
				continue;
			}

			$pause = self::pause_for( $job, $step, $mode );

			if ( $pause > 0 ) {
				$job['paused'][ (string) $step['stage'] ] = true;
				$job['next_at']                           = time() + $pause;

				ADVCM_Jobs::save( $job );
				self::schedule( $job['id'], $job['next_at'] );
				self::release( $job['id'] );
				self::$current = null;

				return;
			}

			$job['steps'][ $index ] = self::step( $job, $step, isset( $adapters[ $step['id'] ] ) ? $adapters[ $step['id'] ] : null, $index );
			$job['next_at']         = 0;
			ADVCM_Jobs::save( $job );

			if ( 'pending' === $job['steps'][ $index ]['status'] ) {
				// The step handed back a cursor. Inline, keep at it while the request has time;
				// otherwise, or once it has not, continue in a fresh request.
				if ( $mode['inline'] && ( microtime( true ) - $began ) < self::INLINE_BUDGET ) {
					continue;
				}

				$job['next_at'] = time();
				ADVCM_Jobs::save( $job );
				self::schedule( $job['id'], $job['next_at'] );
				self::release( $job['id'] );
				self::$current = null;

				return;
			}

			ADVCM_Jobs::record_layer( $job, $job['steps'][ $index ] );
			$index++;
		}

		$job['state']    = self::state( $job['steps'] );
		$job['finished'] = time();

		ADVCM_Jobs::save( $job );
		self::release( $job['id'] );

		self::$current = null;
	}

	/**
	 * The seconds to wait before a step, or 0.
	 *
	 * A mode's pause applies once per stage, only when something earlier in the job actually
	 * cleared — a pause after a layer that was not installed waits for nothing — and only when
	 * this step will run.
	 *
	 * @param array $job  The job.
	 * @param array $step The step about to run.
	 * @param array $mode The job's mode.
	 * @return int
	 */
	private static function pause_for( array $job, array $step, array $mode ) {
		if ( 'run' !== $step['action'] ) {
			return 0;
		}

		$stage = (int) $step['stage'];

		if ( empty( $mode['pause_before'][ $stage ] ) || ! empty( $job['paused'][ (string) $stage ] ) ) {
			return 0;
		}

		foreach ( $job['steps'] as $earlier ) {
			if ( $earlier['stage'] < $stage && in_array( $earlier['status'], array( 'ok', 'partial', 'failed' ), true ) ) {
				return (int) $mode['pause_before'][ $stage ];
			}
		}

		return 0;
	}

	/**
	 * Schedule a job's next run, once.
	 *
	 * @param string $id Job id.
	 * @param int    $at When.
	 * @return void
	 */
	private static function schedule( $id, $at ) {
		$args = array( (string) $id );

		if ( ! wp_next_scheduled( self::CONTINUE_HOOK, $args ) ) {
			wp_schedule_single_event( (int) $at, self::CONTINUE_HOOK, $args );
		}

		// WordPress wakes its cron on page loads. Asking now means a job pressed on a quiet site
		// does not wait for the next visitor; spawn_cron() returns without doing anything when
		// nothing is due yet.
		if ( $at <= time() && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/**
	 * Take a job's lock.
	 *
	 * `add_option()` inserts only when the row does not exist, so of two requests racing for the
	 * same job one gets it. A lock older than LOCK_TTL is a run that died without the shutdown
	 * handler reaching it, and is taken over.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	private static function lock( $id ) {
		$name = 'advcm_lock_' . $id;

		if ( add_option( $name, time(), '', false ) ) {
			return true;
		}

		if ( time() - (int) get_option( $name, 0 ) > self::LOCK_TTL ) {
			update_option( $name, time(), false );

			return true;
		}

		return false;
	}

	/**
	 * Release a job's lock.
	 *
	 * @param string $id Job id.
	 * @return void
	 */
	private static function release( $id ) {
		delete_option( 'advcm_lock_' . $id );
	}

	/**
	 * Whether a job's lock is held and fresh.
	 *
	 * @param string $id Job id.
	 * @return bool
	 */
	public static function locked( $id ) {
		$at = (int) get_option( 'advcm_lock_' . $id, 0 );

		return $at > 0 && time() - $at <= self::LOCK_TTL;
	}

	/**
	 * Find a step the request died in, record it, and continue the rest elsewhere.
	 *
	 * Registered with register_shutdown_function, which PHP runs after a fatal error too — that
	 * is the only reason this works for what `catch` cannot see.
	 *
	 * @return void
	 */
	public static function shutdown() {
		// The last code to run in the request, after a fatal too: anything it throws would be
		// printed on top of whatever the page already sent.
		try {
			self::finish_after_shutdown();
		} catch ( Throwable $e ) {
			ADVCM_Safe::report( 'shutdown', $e );
		}
	}

	/**
	 * The body of shutdown(), so the guard around it is the whole of shutdown().
	 *
	 * @return void
	 */
	private static function finish_after_shutdown() {
		if ( null === self::$current ) {
			return;
		}

		$job           = ADVCM_Jobs::get( self::$current );
		self::release( self::$current );
		self::$current = null;

		if ( ! is_array( $job ) ) {
			return;
		}

		$fatal = error_get_last();
		$why   = 'did not finish';

		if ( is_array( $fatal ) && in_array( $fatal['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			$why .= ': ' . $fatal['message'];
		}

		$pending = false;

		foreach ( $job['steps'] as $index => $step ) {
			if ( 'running' === $step['status'] ) {
				$job['steps'][ $index ]['status']  = 'failed';
				$job['steps'][ $index ]['message'] = $why;
				$job['steps'][ $index ]['ms']      = (int) round( ( microtime( true ) - $step['started'] ) * 1000 );

				ADVCM_Jobs::record_layer( $job, $job['steps'][ $index ] );
			}

			if ( 'pending' === $step['status'] ) {
				$pending = true;
			}
		}

		if ( ! $pending ) {
			$job['state']    = self::state( $job['steps'] );
			$job['finished'] = time();
		}

		ADVCM_Jobs::save( $job );

		if ( $pending ) {
			self::schedule( $job['id'], time() );
		}
	}

	/**
	 * The job's overall state, from its steps.
	 *
	 * @param array $steps Steps.
	 * @return string
	 */
	public static function state( array $steps ) {
		$state = 'done';

		foreach ( $steps as $step ) {
			if ( in_array( $step['status'], array( 'failed', 'held' ), true ) ) {
				return 'done with failures';
			}

			if ( in_array( $step['status'], array( 'pending', 'running' ), true ) ) {
				return 'running';
			}

			if ( in_array( $step['status'], array( 'skipped', 'partial' ), true ) ) {
				$state = 'done with skips';
			}
		}

		return $state;
	}

	/**
	 * One step, planned.
	 *
	 * @param ADVCM_Adapter $adapter Adapter.
	 * @param string        $id      Its id.
	 * @param array         $request Normalised request.
	 * @return array
	 */
	private static function planned( ADVCM_Adapter $adapter, $id, array $request ) {
		$label = self::guard( array( $adapter, 'label' ), $error );
		$stage = self::guard( array( $adapter, 'stage' ), $error );

		$step = array(
			'id'      => $id,
			'label'   => null === $label ? $id : (string) $label,
			'stage'   => null === $stage ? 0 : (int) $stage,
			'action'  => 'run',
			'status'  => 'pending',
			'message' => '',
			'ms'      => 0,
			'started' => 0,
		);

		$skip = self::why_not( $adapter, $request );

		if ( '' !== $skip ) {
			$step['action']  = 'skip';
			$step['message'] = $skip;
		}

		return $step;
	}

	/**
	 * Why a layer will not run for this request, or empty when it will.
	 *
	 * @param ADVCM_Adapter $adapter Adapter.
	 * @param array         $request Normalised request.
	 * @return string
	 */
	private static function why_not( ADVCM_Adapter $adapter, array $request ) {
		$detected = self::guard( array( $adapter, 'detect' ), $error );

		if ( null === $detected ) {
			return 'detection failed: ' . $error;
		}

		if ( ! is_array( $detected ) || empty( $detected['present'] ) ) {
			$reason = is_array( $detected ) && ! empty( $detected['reason'] ) ? $detected['reason'] : 'not installed';

			return (string) $reason;
		}

		if ( true === self::guard( array( $adapter, 'report_only' ), $error ) ) {
			return 'report only: this layer is shown, never cleared';
		}

		$scopes = self::guard( array( $adapter, 'scopes' ), $error );

		if ( is_array( $scopes ) && ! in_array( $request['scope'], $scopes, true ) ) {
			return 'not applicable: this layer is cleared site-wide only';
		}

		return '';
	}

	/**
	 * Run one step.
	 *
	 * @param array              $job     The job.
	 * @param array              $step    The step.
	 * @param ADVCM_Adapter|null $adapter Its adapter, if it still exists.
	 * @param int                $index   Where the step is in the job.
	 * @return array The step, finished.
	 */
	private static function step( array $job, array $step, $adapter, $index ) {
		if ( 'skip' === $step['action'] ) {
			$step['status'] = 'skipped';

			return $step;
		}

		if ( null === $adapter ) {
			$step['status']  = 'skipped';
			$step['message'] = 'not installed';

			return $step;
		}

		if ( self::held( $job, $step ) ) {
			$step['status']  = 'held';
			$step['message'] = 'builder CSS did not finish clearing, and clearing this layer now would store pages rendered against it';

			return $step;
		}

		// Asked again, because the site may have changed since the plan was made.
		$skip = self::why_not( $adapter, self::normalise( $job ) );

		if ( '' !== $skip ) {
			$step['status']  = 'skipped';
			$step['message'] = $skip;

			return $step;
		}

		$step['status']  = 'running';
		$step['started'] = microtime( true );

		$job['steps'][ $index ] = $step;
		ADVCM_Jobs::save( $job );

		$scope   = $job['scope'];
		$urls    = $job['urls'];
		$options = $job['options'];

		if ( isset( $step['carry'] ) ) {
			$options['carry'] = $step['carry'];
		}

		$result = self::guard(
			function () use ( $adapter, $scope, $urls, $options ) {
				return $adapter->clear( $scope, $urls, $options );
			},
			$error
		);

		$step['ms'] = ( isset( $step['ms_before'] ) ? (int) $step['ms_before'] : 0 ) + (int) round( ( microtime( true ) - $step['started'] ) * 1000 );

		if ( null === $result ) {
			$step['status']  = 'failed';
			$step['message'] = $error;

			return $step;
		}

		if ( ! is_array( $result ) || ! isset( $result['status'] ) || ! in_array( $result['status'], array( 'ok', 'partial', 'skipped', 'continue' ), true ) ) {
			unset( $step['carry'], $step['ms_before'] );
			$step['status']  = 'failed';
			$step['message'] = 'the layer answered without saying what it did';

			return $step;
		}

		$step['message'] = isset( $result['message'] ) ? (string) $result['message'] : '';

		if ( 'continue' === $result['status'] ) {
			// Not finished: back to pending, with where it got to.
			$step['status']    = 'pending';
			$step['carry']     = isset( $result['carry'] ) ? $result['carry'] : array();
			$step['ms_before'] = $step['ms'];

			return $step;
		}

		unset( $step['carry'], $step['ms_before'] );
		$step['status'] = $result['status'];

		return $step;
	}

	/**
	 * Whether a step waits because builder CSS failed or only partly cleared.
	 *
	 * @param array $job  The job so far.
	 * @param array $step The step about to run.
	 * @return bool
	 */
	private static function held( array $job, array $step ) {
		if ( ! in_array( $step['stage'], ADVCM_Stages::held_by_builder(), true ) ) {
			return false;
		}

		if ( ! empty( $job['options']['override_hold'] ) ) {
			return false;
		}

		foreach ( $job['steps'] as $earlier ) {
			if ( ADVCM_Stages::BUILDER === $earlier['stage'] && in_array( $earlier['status'], array( 'failed', 'partial' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Call something that belongs to another plugin, and survive it.
	 *
	 * @param callable $call  What to call.
	 * @param string   $error Set to what went wrong, when something did.
	 * @return mixed The call's answer, or null when it threw.
	 */
	private static function guard( $call, &$error ) {
		$error = '';

		try {
			return call_user_func( $call );
		} catch ( Throwable $e ) {
			$error = get_class( $e ) . ': ' . $e->getMessage();

			return null;
		}
	}

	/**
	 * A request with every key present and every value the right shape.
	 *
	 * @param array $request Request or job.
	 * @return array
	 */
	private static function normalise( array $request ) {
		$scope = isset( $request['scope'] ) && 'urls' === $request['scope'] ? 'urls' : 'all';

		return array(
			'scope'   => $scope,
			'urls'    => 'urls' === $scope && isset( $request['urls'] ) && is_array( $request['urls'] ) ? array_values( $request['urls'] ) : array(),
			'refused' => isset( $request['refused'] ) && is_array( $request['refused'] ) ? array_values( $request['refused'] ) : array(),
			'layers'  => isset( $request['layers'] ) && is_array( $request['layers'] ) ? array_values( array_map( 'strval', $request['layers'] ) ) : array(),
			'options' => isset( $request['options'] ) && is_array( $request['options'] ) ? $request['options'] : array(),
			'source'  => isset( $request['source'] ) ? (string) $request['source'] : 'wp-admin',
			// A mode this site cannot run (background with WP-Cron off) becomes Fast rather than
			// a job that waits for an event nothing will fire.
			'mode'    => isset( $request['mode'] ) && in_array( $request['mode'], ADVCM_Modes::available(), true ) ? $request['mode'] : ADVCM_Modes::FAST,
			'by'      => isset( $request['by'] ) ? (int) $request['by'] : 0,
		);
	}

	/**
	 * A job id.
	 *
	 * @return string
	 */
	private static function new_id() {
		return gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
	}
}
