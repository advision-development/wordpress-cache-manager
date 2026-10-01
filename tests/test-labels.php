<?php
/**
 * Every label says whose it is.
 *
 * Read from the source, because the screen needs WordPress to render. A menu reading only
 * "Cache" sits beside three other cache menus on a site with WP Rocket, NitroPack and a host
 * plugin, and nobody can tell which one clears everything in order.
 *
 * @package ADVCM
 */

require __DIR__ . '/store-stubs.php';
require __DIR__ . '/bootstrap.php';

$source = file_get_contents( ADVCM_DIR . 'includes/class-screen.php' );

check( 'no menu or title says only "Cache"', false === strpos( $source, "__( 'Cache', 'advcm' )" ) );
check( 'the admin bar and the Tools entry say Adv Cache', 2 === substr_count( $source, "__( 'Adv Cache', 'advcm' )" ), (string) substr_count( $source, "__( 'Adv Cache', 'advcm' )" ) );
check( 'and the screen and its page title name the plugin', 2 === substr_count( $source, "'Advision Cache Management', 'advcm' )" ) );

finish();
