<?php
/**
 * Sample test case for Ijabat Image Offloader.
 */

class SampleTest extends WP_UnitTestCase {
    public function test_plugin_loaded() {
        // Verify the plugin’s main function exists
        $this->assertTrue( function_exists( 'ijabat_image_offloader_init' ) );
    }
}
