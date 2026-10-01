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
check( 'the admin bar, the Tools entry, the page title and the heading all say CacheManager', 4 === substr_count( $source, "'CacheManager', 'advcm' )" ), (string) substr_count( $source, "'CacheManager', 'advcm' )" ) );
check( 'and the old names are gone', false === strpos( $source, 'Adv Cache' ) && false === strpos( $source, 'Advision Cache Management' ) );

$main = file_get_contents( ADVCM_DIR . 'wordpress-cache-manager.php' );

check( 'the Plugins screen lists it as CacheManager', 1 === preg_match( '~^ \* Plugin Name:\s+CacheManager$~m', $main ) );
check( 'and nothing in the plugin still carries the old name', false === strpos( implode( '', array_map( 'file_get_contents', glob( ADVCM_DIR . 'includes/*.php' ) ) ) . $main, 'Advision Cache Management' ) );

finish();
