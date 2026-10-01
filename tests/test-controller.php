<?php
/**
 * What a press may ask for, and who may ask for the costly parts.
 *
 * @package ADVCM
 */

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'controller' ) as $class ) {
	load_class( $class );
}

/**
 * An adapter with nothing but an id and a stage.
 */
class Named_Adapter extends ADVCM_Adapter {

	private $id;

	public function __construct( $id ) {
		$this->id = $id;
	}

	public function id() {
		return $this->id;
	}

	public function label() {
		return $this->id;
	}

	public function stage() {
		return ADVCM_Stages::PAGE;
	}

	public function detect() {
		return $this->present();
	}

	public function clear( $scope, array $urls, array $options ) {
		return $this->ok( '' );
	}
}

ADVCM_Runner::use_adapters( array( new Named_Adapter( 'nitropack' ), new Named_Adapter( 'wp-engine' ) ) );

// ---------------------------------------------------------------------------- scope

$r = ADVCM_Controller::request( array(), false );

check( 'no scope is the whole site', is_array( $r ) && 'all' === $r['scope'] );

$r = ADVCM_Controller::request( array( 'scope' => 'urls', 'urls' => "/a/\nhttps://attacker.test/" ), false );

check( 'a per-URL press keeps this site\'s URLs', is_array( $r ) && array( 'https://example.test/a/' ) === $r['urls'] );
check( 'and names the ones it refused', is_array( $r ) && array( 'https://attacker.test/' ) === $r['refused'] );

$r = ADVCM_Controller::request( array( 'scope' => 'urls', 'urls' => 'https://attacker.test/' ), false );

check( 'a per-URL press with nothing on this site is refused, not widened to the site', is_string( $r ), is_string( $r ) ? $r : 'returned a request' );

$r = ADVCM_Controller::request( array( 'scope' => 'urls', 'urls' => '' ), false );

check( 'and so is an empty one', is_string( $r ) );

// ---------------------------------------------------------------------------- layers

$r = ADVCM_Controller::request( array( 'layers' => array( 'wp-engine', 'nitropack' ) ), false );

check( 'an editor cannot choose layers: the screen never sends them, and builder CSS alone would leave pages unstyled', array() === $r['layers'], implode( ',', $r['layers'] ) );

$r = ADVCM_Controller::request( array( 'layers' => array( 'wp-engine', 'rm -rf', 'nitropack' ) ), true );

check( 'an administrator can, and only layers the registry knows are kept', array( 'wp-engine', 'nitropack' ) === $r['layers'], implode( ',', $r['layers'] ) );

// Valid, short lines — so it is the size that refuses it, not the parser.
$r = ADVCM_Controller::request( array( 'scope' => 'urls', 'urls' => str_repeat( "/a/\n", (int) ( ADVCM_Controller::MAX_INPUT / 4 ) + 1 ) ), false );

check( 'a URL list larger than the cap is refused before it is parsed or stored', is_string( $r ) );

// ------------------------------------------------------------------ the costly options

// ---------------------------------------------------------------- NitroPack, one choice

$r = ADVCM_Controller::request( array( 'scope' => 'all' ), false );

check( 'by default NitroPack is invalidated: nothing is left out and nothing purged', ! isset( $r['options']['except'] ) && ! isset( $r['options']['nitropack_mode'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'nitropack' => 'leave' ), false );

check( 'anyone who can clear may leave NitroPack out of a whole-site clear', array( 'nitropack' ) === $r['options']['except'] );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'nitropack' => 'purge' ), true );

check( 'an administrator may purge it', 'purge' === $r['options']['nitropack_mode'] && ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'nitropack' => 'purge' ), false );

check( 'anyone else asking to purge gets the default, not the purge', ! isset( $r['options']['nitropack_mode'] ) && ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'nitropack' => array( 'leave', 'purge' ) ), true );

check( 'two answers at once is not an answer: the default, never both', ! isset( $r['options']['nitropack_mode'] ) && ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'skip_nitropack' => '1', 'nitropack_mode' => 'purge' ), true );

check( 'and the two old checkboxes, sent together, do nothing contradictory: they are not read any more', ! isset( $r['options']['nitropack_mode'] ) && ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'urls', 'urls' => '/a/', 'nitropack' => 'leave' ), true );

check( 'a per-page clear has no NitroPack choice: NitroPack is the point of it', ! isset( $r['options']['except'] ) && ! isset( $r['options']['nitropack_mode'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'nitropack' => 'whatever' ), true );

check( 'an unknown choice is the default', ! isset( $r['options']['nitropack_mode'] ) && ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'scope' => 'all', 'except' => array( 'wp-engine' ) ), true );

check( 'and nothing else can be left out, by anyone', ! isset( $r['options']['except'] ) );

$r = ADVCM_Controller::request( array( 'override_hold' => '1' ), false );

check( 'overriding the hold is dropped for somebody without the stronger capability', ! isset( $r['options']['override_hold'] ) );

$r = ADVCM_Controller::request( array( 'override_hold' => '1' ), true );

check( 'and kept for somebody with it', true === $r['options']['override_hold'] );

finish();
