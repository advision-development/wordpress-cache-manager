<?php
/**
 * Settings: what the Settings tab stores, what it refuses, and that every change is recorded.
 *
 * @package ADVCM
 */

// Post types by name: whether a visitor can view one, and whether it is registered public.
$GLOBALS['types'] = array(
	'post'              => array( true, true ),
	'page'              => array( true, true ),
	'review_profile'    => array( true, true ),
	'attachment'        => array( true, true ),
	'elementor_library' => array( true, true ),
	'acf-field-group'   => array( true, true ),
	'wpforms_log'       => array( true, true ),
	'revision'          => array( true, true ),
	'hidden'            => array( false, false ),
);

function get_post_types( $args = array(), $output = 'names' ) {
	$out = array();

	foreach ( $GLOBALS['types'] as $name => $type ) {
		if ( empty( $args['public'] ) || $type[1] ) {
			$out[ $name ] = $name;
		}
	}

	return $out;
}

function is_post_type_viewable( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) && $GLOBALS['types'][ $type ][0];
}

function get_post_type_object( $type ) {
	return isset( $GLOBALS['types'][ $type ] ) ? (object) array( 'name' => $type, 'public' => $GLOBALS['types'][ $type ][1] ) : null;
}

function sanitize_key( $key ) {
	return preg_replace( '~[^a-z0-9_\-]~', '', strtolower( (string) $key ) );
}

function wp_get_current_user() {
	return (object) array( 'ID' => 7, 'user_login' => 'ed' );
}

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

foreach ( array( 'safe', 'stages', 'modes', 'adapter', 'urls', 'jobs', 'runner', 'capabilities', 'log', 'auto', 'settings' ) as $class ) {
	load_class( $class );
}

/**
 * The settings-section records, newest first, as "action:before>after".
 *
 * @return string[]
 */
function settings_log() {
	return array_map(
		function ( $r ) {
			return $r['action'] . ':' . $r['before'] . '>' . $r['after'];
		},
		ADVCM_Log::recent( 'settings', 20 )
	);
}

// ------------------------------------------------------------------------- defaults

$s = ADVCM_Settings::get();

check( 'own-page ignores saves that change nothing, by default', true === $s['own_skip_unchanged'] );
check( 'no excluded list is stored until somebody saves one: the shipped list applies', null === $s['excluded_types'] );
check( 'new rules purge NitroPack by default', 'purge' === $s['nitropack_default'] );
check( 'no version recorded yet', '' === $s['version_seen'] );

// ---------------------------------------------------------------- the post types

$offered = ADVCM_Settings::viewable_types();

check( 'the types offered are the public, viewable ones', in_array( 'review_profile', $offered, true ) && in_array( 'post', $offered, true ) && ! in_array( 'hidden', $offered, true ) );
check( 'never the three that are always left out', ! in_array( 'revision', $offered, true ) );
check( 'nor those a shipped prefix covers, which stay left out without a box to untick', ! in_array( 'acf-field-group', $offered, true ) && ! in_array( 'wpforms_log', $offered, true ) );

// Saving the form as it was shown, with the shipped boxes ticked, must not freeze the shipped list:
// types a later release adds to it would otherwise never apply on this site, and nothing would say.
$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();
ADVCM_Settings::save_form( array( 'op' => 'save', 'excluded_present' => '1', 'excluded' => array_values( array_intersect( ADVCM_Auto::EXCLUDED, $offered ) ) ) );

check( 'saving the shipped list unchanged keeps following the shipped list', null === ADVCM_Settings::get()['excluded_types'] && array() === settings_log(), implode( ' | ', settings_log() ) );

$refusal = ADVCM_Settings::save_form( array( 'op' => 'save', 'excluded_present' => '1', 'excluded' => array( 'review_profile', 'made_up' ) ) );

check( 'a post type the site does not have is refused, by name', is_string( $refusal ) && false !== strpos( $refusal, 'made_up' ) && null === ADVCM_Settings::get()['excluded_types'] );

check( 'a valid list is stored', '' === ADVCM_Settings::save_form( array( 'op' => 'save', 'excluded_present' => '1', 'excluded' => array( 'review_profile', 'attachment' ) ) ) );
check( 'as it was ticked', array( 'attachment', 'review_profile' ) === ADVCM_Settings::get()['excluded_types'] );

$excluded = ADVCM_Auto::excluded_types();

check( 'auto-clear now leaves out what was ticked', in_array( 'review_profile', $excluded, true ) && ! in_array( 'elementor_library', $excluded, true ) );
check( 'and still the always-excluded three and the shipped prefixes', in_array( 'revision', $excluded, true ) && in_array( 'acf-*', $excluded, true ) );

ADVCM_Settings::save_form( array( 'op' => 'save', 'excluded_present' => '1' ) );

check( 'a form with every box unticked stores an empty list, not the shipped one', array() === ADVCM_Settings::get()['excluded_types'] );

ADVCM_Settings::save_form( array( 'op' => 'save' ) );

check( 'a form without the list does not touch it', array() === ADVCM_Settings::get()['excluded_types'] );

$GLOBALS['options'][ ADVCM_Settings::OPTION ]['excluded_types'] = array( 'review_profile', 'gone_type' );

check( 'a stored type the site no longer registers is ignored, not an error', in_array( 'review_profile', ADVCM_Auto::excluded_types(), true ) && ! in_array( 'gone_type', ADVCM_Settings::get()['excluded_types'], true ) );

// ---------------------------------------------------------------- the other two

ADVCM_Settings::save_form( array( 'op' => 'save', 'nitropack_default' => 'drop-everything' ) );

check( 'a NitroPack default other than purge or invalidate keeps the one before', 'purge' === ADVCM_Settings::get()['nitropack_default'] );

$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();

ADVCM_Settings::save_form( array( 'op' => 'save', 'nitropack_default' => 'invalidate', 'own_present' => '1' ) );

check( 'each setting that changed is one record, before and after', array( 'own_skip_unchanged:on>off', 'nitropack_default:purge>invalidate' ) === settings_log(), implode( ' | ', settings_log() ) );

ADVCM_Settings::save_form( array( 'op' => 'save', 'nitropack_default' => 'invalidate', 'own_present' => '1' ) );

check( 'saving without changing anything records nothing', 2 === count( settings_log() ) );

// ------------------------------------------------- pause and own-page, from here

$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();

ADVCM_Settings::save_form( array( 'op' => 'own_on' ) );
ADVCM_Settings::save_form( array( 'op' => 'pause' ) );

check( 'own-page is switched on from Settings', isset( ADVCM_Auto::rules()[ ADVCM_Auto::OWN ] ) );
check( 'and auto-clear paused', ADVCM_Auto::paused() );
check( 'both recorded', array( 'pause:off>on', 'own_page:off>on' ) === settings_log(), implode( ' | ', settings_log() ) );

ADVCM_Settings::save_form( array( 'op' => 'resume' ) );
ADVCM_Settings::save_form( array( 'op' => 'own_off' ) );

check( 'and back', ! ADVCM_Auto::paused() && ! isset( ADVCM_Auto::rules()[ ADVCM_Auto::OWN ] ) && 4 === count( settings_log() ) );

$GLOBALS['options'][ ADVCM_Log::OPTION ] = array();

ADVCM_Settings::save_form( array( 'op' => 'pause' ) );
ADVCM_Settings::save_form( array( 'op' => 'pause' ) );
ADVCM_Settings::save_form( array( 'op' => 'resume' ) );
ADVCM_Settings::save_form( array( 'op' => 'resume' ) );
ADVCM_Settings::save_form( array( 'op' => 'own_off' ) );

check( 'a switch already where it was asked to go records nothing: a stale tab or a double submit is not a change', array( 'pause:on>off', 'pause:off>on' ) === settings_log(), implode( ' | ', settings_log() ) );

check( 'an operation nobody offers is refused', is_string( ADVCM_Settings::save_form( array( 'op' => 'drop_tables' ) ) ) );

// ------------------------------------------------------------- the version seen

ADVCM_Settings::set_version_seen( '0.4.0' );

check( 'the version last seen is kept with the settings', '0.4.0' === ADVCM_Settings::get()['version_seen'] );

finish();
