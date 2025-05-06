<?php
/**
 * Plugin Name:        Ijabat Image Offloader
 * Description:        Offloads WordPress media uploads and thumbnails to AWS S3.
 * Version:            1.0.0
 * Author:             Ijabat Tech Solutions, LLC
 * License:            GPL2+
 * License URI:        https://www.gnu.org/licenses/gpl-2.0.html
 * PHP version         8.2.4
 *
 * @category WordPress_Plugin
 * @package  IjabatImageOffloader
 * @author   Ijabat Tech Solutions, LLC <info@ijabat.org>
 * @license  https://www.gnu.org/licenses/gpl-2.0.html GPL2+
 * @link     https://ijabat.org
 * @since    1.0.0
 */

// Prevent direct access.
defined('ABSPATH') || exit;

// config
define('IJABAT_PLUGIN_FILE', __FILE__);

// Composer autoload.
require plugin_dir_path(IJABAT_PLUGIN_FILE) . 'vendor/autoload.php';

// On Activation, generate and store encryption keys
// under secure-data
register_activation_hook(
    __FILE__,
    [\IjabatImageOffloader\CryptoHelper::class, 'generateAndStoreKeys']
);

// initialize plugin
add_action(
    'plugins_loaded',
    function () {
        new \IjabatImageOffloader\Plugin();
    }
);
