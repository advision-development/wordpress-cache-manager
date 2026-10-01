<?php
/**
 * Modes, pauses, cursors, the job lock, and what the screen calls stuck.
 *
 * WP-Cron is played by `fire()` in store-stubs.php: an event is taken off the schedule and its
 * job run, exactly what WordPress does when one comes due. Time is not mocked; a pause is
 * observed by what was scheduled and for when, not by waiting for it.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'jobs', 'runner' ) as $class ) {
	load_class( $class );
}

/**
 * A layer that records its calls and can hand back a cursor a set number of times.
 */
class Step_Adapter extends ADVCM_Adapter {

	public $calls   = 0;
	public $carries = array();

	private $id;
	private $stage;
	private $present;
	private $continues;

	public function __construct( $id, $stage, $present = true, $continues = 0 ) {
		$this->id        = $id;
		$this->stage     = $stage;
		$this->present   = $present;
		$this->continues = $continues;
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return $this->id;
	}

	public function stage() {
		return $this->stage;
	}

	public function detect() {
		return $this->present ? $this->present() : $this->absent( 'not installed' );
	}

	public function clear( $scope, array $urls, array $options ) {
		$this->calls++;
		$this->carries[] = isset( $options['carry'] ) ? $options['carry'] : null;

		if ( $this->continues > 0 ) {
			$this->continues--;

			return array( 'status' => 'continue', 'message' => 'more', 'carry' => array( 'cursor' => $this->calls ) );
		}

		return $this->ok( 'done' );
	}
}

/**
 * Statuses by id.
 *
 * @param string $id Job id.
 * @return array
 */
function statuses_of( $id ) {
	$out = array();

	foreach ( ADVCM_Jobs::get( $id )['steps'] as $step ) {
		$out[ $step['id'] ] = $step['status'];
	}

	return $out;
}

/**
 * The full set of layers, one per stage.
 *
 * @return Step_Adapter[]
 */
function layers() {
	return array(
		'object' => new Step_Adapter( 'object', ADVCM_Stages::OBJECT ),
		'css'    => new Step_Adapter( 'css', ADVCM_Stages::BUILDER ),
		'nitro'  => new Step_Adapter( 'nitro', ADVCM_Stages::OPTIMIZER ),
		'host'   => new Step_Adapter( 'host', ADVCM_Stages::HOST ),
		'cdn'    => new Step_Adapter( 'cdn', ADVCM_Stages::CDN ),
	);
}

// ----------------------------------------------------------------------------- the presets

$fast     = ADVCM_Modes::get( ADVCM_Modes::FAST );
$balanced = ADVCM_Modes::get( ADVCM_Modes::BALANCED );
$careful  = ADVCM_Modes::get( ADVCM_Modes::CAREFUL );

check( 'Fast runs in the request', true === $fast['inline'] && array() === $fast['pause_before'] );
check( 'Balanced pauses 2 minutes after the object cache and 1 before the CDN', array( ADVCM_Stages::BUILDER => 120, ADVCM_Stages::CDN => 60 ) === $balanced['pause_before'] );
check( 'Careful pauses 5 and 3', array( ADVCM_Stages::BUILDER => 300, ADVCM_Stages::CDN => 180 ) === $careful['pause_before'] );
check( 'and warms 20 and 100 pages', 20 === $balanced['warm_site'] && 100 === $careful['warm_site'] );
check( 'no preset pauses anywhere from builder CSS through the host cache', array() === array_intersect( array_keys( $balanced['pause_before'] + $careful['pause_before'] ), ADVCM_Stages::no_pause_before() ) );
check( 'pages are cleared Fast by default', ADVCM_Modes::FAST === ADVCM_Modes::recommended( 'urls' ) );
check( 'and the whole site Balanced', ADVCM_Modes::BALANCED === ADVCM_Modes::recommended( 'all' ) );
check( 'an unknown mode is Fast, never something that waits for an event', ADVCM_Modes::FAST === ADVCM_Modes::get( 'whatever' )['id'] );

// A filtered preset that pauses between builder CSS and the page caches is refused.
$GLOBALS['filter_values']['advcm_modes'] = array(
	ADVCM_Modes::BALANCED => array( 'pause_before' => array( ADVCM_Stages::HOST => 600 ) ),
	ADVCM_Modes::CAREFUL  => array( 'warm_batch' => 50 ),
);

check( 'a filter cannot put a pause before the host cache', array( ADVCM_Stages::BUILDER => 120, ADVCM_Stages::CDN => 60 ) === ADVCM_Modes::get( ADVCM_Modes::BALANCED )['pause_before'] );
check( 'or a warm-up of fifty at once', 5 === ADVCM_Modes::get( ADVCM_Modes::CAREFUL )['warm_batch'] );

$GLOBALS['filter_values']['advcm_modes'] = array( ADVCM_Modes::BALANCED => array( 'pause_before' => array( ADVCM_Stages::BUILDER => 30, ADVCM_Stages::CDN => 10 ) ) );

check( 'but can change the pauses where pauses are allowed', array( ADVCM_Stages::BUILDER => 30, ADVCM_Stages::CDN => 10 ) === ADVCM_Modes::get( ADVCM_Modes::BALANCED )['pause_before'] );

unset( $GLOBALS['filter_values']['advcm_modes'] );

// ------------------------------------------------------------------------- Balanced, end to end

$l = layers();
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::BALANCED ) );
$id  = $job['id'];

check( 'a background press returns at once, with nothing cleared yet', 'running' === $job['state'] && 0 === $l['object']->calls );
check( 'and the first run scheduled for now', false !== scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) && scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) <= time() );
check( 'and cron woken so a quiet site does not wait for a visitor', $GLOBALS['spawned'] > 0 );

fire( ADVCM_Runner::CONTINUE_HOOK, $id );

check( 'the first run clears the object cache', 1 === $l['object']->calls );
check( 'and stops before builder CSS', 0 === $l['css']->calls );
check( 'with the next run two minutes out', abs( scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) - ( time() + 120 ) ) <= 2, (string) ( scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) - time() ) );
check( 'and the job saying when', abs( ADVCM_Jobs::get( $id )['next_at'] - ( time() + 120 ) ) <= 2 );
check( 'and the lock released, so the next run can take it', ! ADVCM_Runner::locked( $id ) );

fire( ADVCM_Runner::CONTINUE_HOOK, $id );

check( 'the second run clears builder CSS, NitroPack and the host cache together', 1 === $l['css']->calls && 1 === $l['nitro']->calls && 1 === $l['host']->calls );
check( 'and stops before the CDN, one minute out', 0 === $l['cdn']->calls && abs( scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) - ( time() + 60 ) ) <= 2 );

fire( ADVCM_Runner::CONTINUE_HOOK, $id );

check( 'the third run clears the CDN', 1 === $l['cdn']->calls );
check( 'and the job is done', 'done' === ADVCM_Jobs::get( $id )['state'], ADVCM_Jobs::get( $id )['state'] );
check( 'with nothing left on the schedule', false === scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $id ) );
check( 'and no layer cleared twice', 1 === $l['object']->calls && 1 === $l['css']->calls && 1 === $l['cdn']->calls );

// ------------------------------------------------------------------- a pause after nothing

$l = layers();
$l['object'] = new Step_Adapter( 'object', ADVCM_Stages::OBJECT, false );
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::BALANCED ) );

fire( ADVCM_Runner::CONTINUE_HOOK, $job['id'] );

check( 'with no object cache there is nothing to wait for, so builder CSS follows at once', 1 === $l['css']->calls && 1 === $l['host']->calls );

// ------------------------------------------------------------------------------ Fast

$l = layers();
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::FAST ) );

check( 'Fast clears everything in the request', 'done' === $job['state'] && 1 === $l['cdn']->calls );
check( 'and schedules nothing', false === scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $job['id'] ) );

// ------------------------------------------------------------------------------ cursors

$warm = new Step_Adapter( 'warm', ADVCM_Stages::WARM, true, 2 );
ADVCM_Runner::use_adapters( array( $warm ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::FAST ) );

check( 'inline, a step that hands back a cursor is called again in the same request', 'done' === $job['state'] && 3 === $warm->calls );
check( 'with the cursor it handed back', array( 'cursor' => 2 ) === $warm->carries[2] );

$warm = new Step_Adapter( 'warm', ADVCM_Stages::WARM, true, 2 );
ADVCM_Runner::use_adapters( array( $warm ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::BALANCED ) );

fire( ADVCM_Runner::CONTINUE_HOOK, $job['id'] );

check( 'in the background, it continues in a fresh run instead', 1 === $warm->calls && 'pending' === statuses_of( $job['id'] )['warm'] );
check( 'scheduled straight away, not after a pause', scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $job['id'] ) <= time() );

fire( ADVCM_Runner::CONTINUE_HOOK, $job['id'] );
fire( ADVCM_Runner::CONTINUE_HOOK, $job['id'] );

check( 'until it finishes', 3 === $warm->calls && 'ok' === statuses_of( $job['id'] )['warm'] );
check( 'and its carry is not kept on the finished step', ! isset( ADVCM_Jobs::get( $job['id'] )['steps'][0]['carry'] ) );

// ------------------------------------------------------------------------------ the lock

$l = layers();
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::BALANCED ) );

add_option( 'advcm_lock_' . $job['id'], time() );
ADVCM_Runner::run( $job['id'] );

check( 'a second request cannot run a job another request holds', 0 === $l['object']->calls );

$GLOBALS['options'][ 'advcm_lock_' . $job['id'] ] = time() - ADVCM_Runner::LOCK_TTL - 1;
ADVCM_Runner::run( $job['id'] );

check( 'but a lock older than its time-to-live is a dead run, and is taken over', 1 === $l['object']->calls );

// ---------------------------------------------------------------------- stuck and resume

$l = layers();
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::CAREFUL ) );
fire( ADVCM_Runner::CONTINUE_HOOK, $job['id'] );

$stored = ADVCM_Jobs::get( $job['id'] );

check( 'a job waiting out its pause is not stuck', false === ADVCM_Runner::stuck( $stored ) );

$stored['next_at'] = time() - ADVCM_Runner::STUCK_AFTER - 5;
ADVCM_Jobs::save( $stored );

check( 'one whose next run is well overdue is', true === ADVCM_Runner::stuck( ADVCM_Jobs::get( $job['id'] ) ) );

ADVCM_Runner::resume_now( $job['id'] );

check( 'continuing it now runs the rest in this request, without the remaining pauses', 'done' === ADVCM_Jobs::get( $job['id'] )['state'] && 1 === $l['cdn']->calls );
check( 'in order', 1 === $l['css']->calls && 1 === $l['host']->calls );
check( 'and leaves nothing scheduled', false === scheduled_at( ADVCM_Runner::CONTINUE_HOOK, $job['id'] ) );
check( 'while the job still records the mode it was pressed with', ADVCM_Modes::CAREFUL === ADVCM_Jobs::get( $job['id'] )['mode'] && ! empty( ADVCM_Jobs::get( $job['id'] )['resumed'] ) );

// ------------------------------------------------------------------- no WP-Cron on the site

define( 'DISABLE_WP_CRON', true );

check( 'with WP-Cron off only Fast is offered', array( ADVCM_Modes::FAST ) === ADVCM_Modes::available() );
check( 'and recommended for the whole site too', ADVCM_Modes::FAST === ADVCM_Modes::recommended( 'all' ) );

$l = layers();
ADVCM_Runner::use_adapters( array_values( $l ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'mode' => ADVCM_Modes::BALANCED ) );

check( 'and a request for a background mode runs Fast rather than waiting for an event nothing fires', 'done' === $job['state'] && 1 === $l['cdn']->calls );

$GLOBALS['filter_values']['advcm_background_available'] = true;

check( 'unless the site says a server cron runs wp-cron.php', 3 === count( ADVCM_Modes::available() ) );

finish();
