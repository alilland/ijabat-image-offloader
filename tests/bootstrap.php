<?php
/**
 * PHPUnit bootstrap file for Ijabat Image Offloader plugin
 */

// Path to the WordPress tests library
if ( ! defined( 'WP_TESTS_DIR' ) ) {
    define( 'WP_TESTS_DIR', __DIR__ . '/wordpress-tests-lib' );
}

// Load the test suite functions
require_once WP_TESTS_DIR . '/includes/functions.php';

// Load our plugin
function _load_ijabat_image_offloader_plugin() {
    require __DIR__ . '/../ijabat-image-offloader.php';
}
tests_add_filter( 'muplugins_loaded', '_load_ijabat_image_offloader_plugin' );

// Bootstrap WordPress
require WP_TESTS_DIR . '/includes/bootstrap.php';
