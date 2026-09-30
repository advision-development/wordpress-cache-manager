<?php
/**
 * Who may purge: mapped from capabilities people already hold, never written into roles.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

load_class( 'capabilities' );

$editor = ADVCM_Capabilities::grant( array( 'edit_posts' => true, 'edit_others_posts' => true ) );
$author = ADVCM_Capabilities::grant( array( 'edit_posts' => true ) );
$admin  = ADVCM_Capabilities::grant( array( 'edit_others_posts' => true, 'manage_options' => true ) );

check( 'an editor can purge', ! empty( $editor[ ADVCM_Capabilities::PURGE ] ) );
check( 'but not the costly parts', empty( $editor[ ADVCM_Capabilities::HARD ] ) );
check( 'an author cannot purge', empty( $author[ ADVCM_Capabilities::PURGE ] ) );
check( 'an administrator can do both', ! empty( $admin[ ADVCM_Capabilities::PURGE ] ) && ! empty( $admin[ ADVCM_Capabilities::HARD ] ) );
check( 'a capability that is present but false grants nothing', empty( ADVCM_Capabilities::grant( array( 'edit_others_posts' => false ) )[ ADVCM_Capabilities::PURGE ] ) );
check( 'and a malformed answer is handed back untouched', 'x' === ADVCM_Capabilities::grant( 'x' ) );

$GLOBALS['filter_values']['advcm_purge_base_capability'] = 'edit_posts';

$author = ADVCM_Capabilities::grant( array( 'edit_posts' => true ) );

check( 'the base capability is filterable, so a site can hand it to authors', ! empty( $author[ ADVCM_Capabilities::PURGE ] ) );

finish();
