<?php
/**
 * The pipeline: order, guards, holds, and what a fatal leaves behind.
 *
 * Every assertion here is one of the rules the runner's docblock names, and each one is written
 * so that deleting the code it names makes it fail: an adapter that throws must be *seen* to be
 * followed by one that runs, a hold must be *seen* to spare the object cache, and the order must
 * be checked against a registration order that is deliberately wrong.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'stages' );
load_class( 'adapter' );
load_class( 'jobs' );
load_class( 'runner' );

/**
 * A layer whose every answer the test chooses.
 */
class Fake_Adapter extends ADVCM_Adapter {

	public $calls = 0;

	private $id;
	private $stage;
	private $detects;
	private $clear;
	private $scopes;

	/**
	 * @param string          $id      Id.
	 * @param int             $stage   Stage.
	 * @param array|callable  $detects Detection answers in order, the last repeating; or a callable.
	 * @param callable|null   $clear   What clear() does; ok by default.
	 * @param array           $scopes  Scopes.
	 */
	public function __construct( $id, $stage, $detects = null, $clear = null, $scopes = array( 'all', 'urls' ) ) {
		$this->id      = $id;
		$this->stage   = $stage;
		$this->detects = null === $detects ? array( true ) : $detects;
		$this->clear   = $clear;
		$this->scopes  = $scopes;
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return 'Fake ' . $this->id;
	}

	public function stage() {
		return $this->stage;
	}

	public function scopes() {
		return $this->scopes;
	}

	public function detect() {
		if ( is_callable( $this->detects ) ) {
			return call_user_func( $this->detects );
		}

		$answer = count( $this->detects ) > 1 ? array_shift( $this->detects ) : $this->detects[0];

		return true === $answer ? $this->present() : $this->absent( (string) $answer );
	}

	public function clear( $scope, array $urls, array $options ) {
		$this->calls++;

		if ( null !== $this->clear ) {
			return call_user_func( $this->clear, $this );
		}

		return $this->ok( 'cleared ' . $scope );
	}

	public function give_partial() {
		return $this->partial( 1, 2, 'half' );
	}
}

/**
 * The statuses of a job's steps, by id.
 *
 * @param array $job Job.
 * @return array
 */
function statuses( array $job ) {
	$out = array();

	foreach ( $job['steps'] as $step ) {
		$out[ $step['id'] ] = $step['status'];
	}

	return $out;
}

/**
 * The ids of a job's steps, in order.
 *
 * @param array $steps Steps.
 * @return string
 */
function order_of( array $steps ) {
	return implode( ',', array_map( function ( $s ) { return $s['id']; }, $steps ) );
}

// ------------------------------------------------------------------------- the order

ADVCM_Runner::use_adapters(
	array(
		new Fake_Adapter( 'nitro', ADVCM_Stages::OPTIMIZER ),
		new Fake_Adapter( 'object', ADVCM_Stages::OBJECT ),
		new Fake_Adapter( 'host', ADVCM_Stages::HOST ),
		new Fake_Adapter( 'css', ADVCM_Stages::BUILDER ),
		new Fake_Adapter( 'cdn', ADVCM_Stages::CDN ),
	)
);

$plan = ADVCM_Runner::plan( array( 'scope' => 'all' ) );

check( 'the plan runs in stage order whatever order the layers were registered in', 'css,object,host,nitro,cdn' === order_of( $plan ), order_of( $plan ) );

$plan = ADVCM_Runner::plan( array( 'scope' => 'all', 'layers' => array( 'nitro', 'css' ) ) );

check( 'and a request naming layers gets them in stage order, not in its own', 'css,nitro' === order_of( $plan ), order_of( $plan ) );

// Two layers in one stage: the same order every time, by id.
ADVCM_Runner::use_adapters(
	array(
		new Fake_Adapter( 'zeta', ADVCM_Stages::PAGE ),
		new Fake_Adapter( 'alpha', ADVCM_Stages::PAGE ),
	)
);

check( 'two layers in one stage keep a fixed order', 'alpha,zeta' === order_of( ADVCM_Runner::plan( array() ) ) );

// ----------------------------------------------------------- a missing layer is skipped

$rocket = new Fake_Adapter( 'rocket', ADVCM_Stages::PAGE, array( 'not installed' ) );
$host   = new Fake_Adapter( 'host', ADVCM_Stages::HOST );

ADVCM_Runner::use_adapters( array( $rocket, $host ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );
$st  = statuses( $job );

check( 'a layer that is not installed is skipped', 'skipped' === $st['rocket'] );
check( 'and never called', 0 === $rocket->calls );
check( 'and says why', 'not installed' === $job['steps'][0]['message'], $job['steps'][0]['message'] );
check( 'and the layers after it still run', 'ok' === $st['host'] && 1 === $host->calls );
check( 'and the job says it skipped something rather than calling it a success', 'done with skips' === $job['state'], $job['state'] );

// ------------------------------------------------------------- nothing can stop the pipeline

$gone = new Fake_Adapter(
	'gone',
	ADVCM_Stages::PAGE,
	null,
	function () {
		// What happens when a plugin's files were deleted and its function with them. An Error,
		// not an Exception — catch ( Exception ) would let this end the run.
		return advcm_this_function_does_not_exist();
	}
);
$after = new Fake_Adapter( 'after', ADVCM_Stages::HOST );

ADVCM_Runner::use_adapters( array( $gone, $after ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );
$st  = statuses( $job );

check( 'a layer that calls a function that no longer exists fails', 'failed' === $st['gone'] );
check( 'and the failure names the Error', false !== strpos( $job['steps'][0]['message'], 'Error' ), $job['steps'][0]['message'] );
check( 'and the next layer still runs', 'ok' === $st['after'] && 1 === $after->calls );
check( 'and the job says it failed', 'done with failures' === $job['state'] );

$thrower = new Fake_Adapter(
	'thrower',
	ADVCM_Stages::PAGE,
	function () {
		throw new RuntimeException( 'vendor exploded' );
	}
);
$after = new Fake_Adapter( 'after', ADVCM_Stages::HOST );

ADVCM_Runner::use_adapters( array( $thrower, $after ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );
$st  = statuses( $job );

check( 'a layer whose detection throws is skipped, not fatal', 'skipped' === $st['thrower'] );
check( 'with the reason', false !== strpos( $job['steps'][0]['message'], 'detection failed: RuntimeException: vendor exploded' ), $job['steps'][0]['message'] );
check( 'and the rest run', 'ok' === $st['after'] );

$liar = new Fake_Adapter(
	'liar',
	ADVCM_Stages::PAGE,
	null,
	function () {
		return true;
	}
);

ADVCM_Runner::use_adapters( array( $liar ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'a layer answering true instead of a result is a failure, not a success', 'failed' === $job['steps'][0]['status'], $job['steps'][0]['status'] );

// ----------------------------------------------------------- detection happens at run time

// Present when the plan was made, gone by the time its step runs.
$vanishing = new Fake_Adapter( 'vanishing', ADVCM_Stages::PAGE, array( true, 'removed since the plan' ) );

ADVCM_Runner::use_adapters( array( $vanishing ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'a layer removed between the plan and the run is skipped', 'skipped' === $job['steps'][0]['status'] );
check( 'and not called', 0 === $vanishing->calls );
check( 'with the reason detect gave at run time', 'removed since the plan' === $job['steps'][0]['message'], $job['steps'][0]['message'] );

// ------------------------------------------------------------------------------ scope

$global_only = new Fake_Adapter( 'global', ADVCM_Stages::BUILDER, null, null, array( 'all' ) );

ADVCM_Runner::use_adapters( array( $global_only ) );

$job = ADVCM_Runner::start( array( 'scope' => 'urls', 'urls' => array( 'https://example.test/a/' ) ) );

check( 'a site-wide-only layer is skipped by a per-URL request rather than widened', 'skipped' === $job['steps'][0]['status'] && 0 === $global_only->calls );
check( 'and says so', false !== strpos( $job['steps'][0]['message'], 'site-wide only' ), $job['steps'][0]['message'] );

// ------------------------------------------------------------------------ report only

class Report_Only_Adapter extends Fake_Adapter {

	public function report_only() {
		return true;
	}
}

$reported = new Report_Only_Adapter( 'reported', ADVCM_Stages::BUILDER );

ADVCM_Runner::use_adapters( array( $reported ) );

$plan = ADVCM_Runner::plan( array( 'scope' => 'all' ) );

check( 'a report-only layer is planned as a skip, so the screen does not promise a clear', 'skip' === $plan[0]['action'], $plan[0]['action'] );
check( 'and says why', 0 === strpos( $plan[0]['message'], 'report only' ), $plan[0]['message'] );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'and is never called', 0 === $reported->calls && 'skipped' === $job['steps'][0]['status'] );

$page = new Fake_Adapter( 'page', ADVCM_Stages::PAGE );

ADVCM_Runner::use_adapters( array( new Report_Only_Adapter( 'reported', ADVCM_Stages::BUILDER ), $page ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'and a report-only builder holds nothing, because it did not fail', 'ok' === statuses( $job )['page'] && 1 === $page->calls );

// ---------------------------------------------------------------------------- the hold

$broken_css = function () {
	return new Fake_Adapter(
		'css',
		ADVCM_Stages::BUILDER,
		null,
		function () {
			throw new RuntimeException( 'disk full' );
		}
	);
};

$object = new Fake_Adapter( 'object', ADVCM_Stages::OBJECT );
$page   = new Fake_Adapter( 'page', ADVCM_Stages::PAGE );
$host   = new Fake_Adapter( 'host', ADVCM_Stages::HOST );
$nitro  = new Fake_Adapter( 'nitro', ADVCM_Stages::OPTIMIZER );

ADVCM_Runner::use_adapters( array( $broken_css(), $object, $page, $host, $nitro ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );
$st  = statuses( $job );

check( 'when builder CSS fails, the page cache is held', 'held' === $st['page'] && 0 === $page->calls );
check( 'and so is the host cache', 'held' === $st['host'] && 0 === $host->calls );
check( 'and NitroPack', 'held' === $st['nitro'] && 0 === $nitro->calls );
check( 'but the object cache, which stores no pages, still clears', 'ok' === $st['object'] && 1 === $object->calls );
check( 'and a held job is not a success', 'done with failures' === $job['state'] );

$page = new Fake_Adapter( 'page', ADVCM_Stages::PAGE );

ADVCM_Runner::use_adapters( array( $broken_css(), $page ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all', 'options' => array( 'override_hold' => true ) ) );

check( 'overriding the hold clears them anyway', 'ok' === statuses( $job )['page'] && 1 === $page->calls );

$half_css = new Fake_Adapter(
	'css',
	ADVCM_Stages::BUILDER,
	null,
	function ( $self ) {
		return $self->give_partial();
	}
);
$page = new Fake_Adapter( 'page', ADVCM_Stages::PAGE );

ADVCM_Runner::use_adapters( array( $half_css, $page ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'a builder that only partly cleared holds them too', 'held' === statuses( $job )['page'] );

$no_builder = new Fake_Adapter( 'css', ADVCM_Stages::BUILDER, array( 'not installed' ) );
$page       = new Fake_Adapter( 'page', ADVCM_Stages::PAGE );

ADVCM_Runner::use_adapters( array( $no_builder, $page ) );

$job = ADVCM_Runner::start( array( 'scope' => 'all' ) );

check( 'a builder that is simply not installed holds nothing', 'ok' === statuses( $job )['page'] && 1 === $page->calls );

// ------------------------------------------------------------------------- the states

check( 'every step ok is done', 'done' === ADVCM_Runner::state( array( array( 'status' => 'ok' ), array( 'status' => 'ok' ) ) ) );
check( 'a partial is not a plain done', 'done with skips' === ADVCM_Runner::state( array( array( 'status' => 'ok' ), array( 'status' => 'partial' ) ) ) );
check( 'a failure outranks a skip', 'done with failures' === ADVCM_Runner::state( array( array( 'status' => 'skipped' ), array( 'status' => 'failed' ) ) ) );
check( 'a step still pending means it is running', 'running' === ADVCM_Runner::state( array( array( 'status' => 'ok' ), array( 'status' => 'pending' ) ) ) );

// ------------------------------------------------------------- what a fatal leaves behind

// A job as the request left it when PHP died inside the host-cache step: that step written as
// running, the NitroPack step not reached.
$died = array(
	'id'       => 'died-1',
	'created'  => time(),
	'finished' => 0,
	'by'       => 1,
	'source'   => 'wp-admin',
	'scope'    => 'all',
	'urls'     => array(),
	'refused'  => array(),
	'layers'   => array(),
	'options'  => array(),
	'state'    => 'running',
	'steps'    => array(
		array( 'id' => 'object', 'label' => 'o', 'stage' => 3, 'action' => 'run', 'status' => 'ok', 'message' => '', 'ms' => 3, 'started' => microtime( true ) ),
		array( 'id' => 'host', 'label' => 'h', 'stage' => 5, 'action' => 'run', 'status' => 'running', 'message' => '', 'ms' => 0, 'started' => microtime( true ) ),
		array( 'id' => 'nitro', 'label' => 'n', 'stage' => 6, 'action' => 'run', 'status' => 'pending', 'message' => '', 'ms' => 0, 'started' => 0 ),
	),
);

ADVCM_Jobs::save( $died );

$current = new ReflectionProperty( 'ADVCM_Runner', 'current' );
$current->setAccessible( true );
$current->setValue( null, 'died-1' );

ADVCM_Runner::shutdown();

$after_fatal = ADVCM_Jobs::get( 'died-1' );

check( 'the step the request died in is recorded as failed', 'failed' === $after_fatal['steps'][1]['status'], $after_fatal['steps'][1]['status'] );
check( 'as not having finished', 0 === strpos( $after_fatal['steps'][1]['message'], 'did not finish' ), $after_fatal['steps'][1]['message'] );
check( 'the step it never reached is still pending', 'pending' === $after_fatal['steps'][2]['status'] );
check( 'and the rest is scheduled to continue in a fresh request', false !== wp_next_scheduled( ADVCM_Runner::CONTINUE_HOOK, array( 'died-1' ) ) );
check( 'and the shutdown handler lets go of the job, so it cannot run twice', null === $current->getValue() );

// The continuation: WP-Cron fires the hook with the job id.
$nitro = new Fake_Adapter( 'nitro', ADVCM_Stages::OPTIMIZER );
$host  = new Fake_Adapter( 'host', ADVCM_Stages::HOST );

ADVCM_Runner::use_adapters( array( $host, $nitro ) );
ADVCM_Runner::run( 'died-1' );

$continued = ADVCM_Jobs::get( 'died-1' );

check( 'the continuation runs what was left', 'ok' === $continued['steps'][2]['status'] && 1 === $nitro->calls );
check( 'and does not retry the step that killed the request', 'failed' === $continued['steps'][1]['status'] && 0 === $host->calls );
check( 'and the job ends with its failure on record', 'done with failures' === $continued['state'], $continued['state'] );

ADVCM_Runner::shutdown();

check( 'a shutdown after a job finished cleanly changes nothing', 'done with failures' === ADVCM_Jobs::get( 'died-1' )['state'] );

// --------------------------------------------------------------------------- storage

ADVCM_Runner::use_adapters( array( new Fake_Adapter( 'x', ADVCM_Stages::PAGE ) ) );

for ( $i = 0; $i < ADVCM_Jobs::UNSENT_CAP + 5; $i++ ) {
	ADVCM_Runner::start( array( 'scope' => 'all' ) );
}

// Nothing marks a job sent until sites report to Hawkeye, so the buffer holds to its cap.
check( 'the buffer never grows past its cap', ADVCM_Jobs::UNSENT_CAP === count( ADVCM_Jobs::all() ), (string) count( ADVCM_Jobs::all() ) );

// ------------------------------------------------------------------- the layer summary

$GLOBALS['options'][ ADVCM_Jobs::LAYERS ] = array();

ADVCM_Runner::use_adapters(
	array(
		new Fake_Adapter( 'cleared', ADVCM_Stages::HOST ),
		new Fake_Adapter( 'absent', ADVCM_Stages::PAGE, array( 'not installed' ) ),
	)
);

$job    = ADVCM_Runner::start( array( 'scope' => 'all', 'by' => 7 ) );
$layers = ADVCM_Jobs::layers();

check( 'a layer that ran is in the summary', isset( $layers['cleared'] ) && 'ok' === $layers['cleared']['status'] );
check( 'with who ran it and from which job', 7 === $layers['cleared']['by'] && $job['id'] === $layers['cleared']['job'] );
check( 'a layer that was skipped is not, because nothing was cleared', ! isset( $layers['absent'] ) );

$GLOBALS['options'][ ADVCM_Jobs::LAYERS ]['host'] = array( 'at' => 1, 'by' => 1, 'status' => 'ok', 'message' => '', 'scope' => 'all', 'job' => 'x', 'ok_at' => 12345 );
$current->setValue( null, 'died-2' );

$died['id']                     = 'died-2';
$died['steps'][1]['status']     = 'running';
$died['steps'][2]['status']     = 'pending';
ADVCM_Jobs::save( $died );
ADVCM_Runner::shutdown();

$layers = ADVCM_Jobs::layers();

check( 'a step a fatal killed is in the summary as failed', 'failed' === $layers['host']['status'], $layers['host']['status'] );
check( 'and the last success before it is kept', 12345 === $layers['host']['ok_at'] );

finish();
