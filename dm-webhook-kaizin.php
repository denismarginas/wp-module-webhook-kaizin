<?php

/**
 * Plugin Name: Webhooks Kaizin
 * Description: Sends WooCommerce user lifecycle events to Kaizin webhook endpoints.
 * Version: 1.0.0
 * Author:  Mentor Marketing Experts 
 * Requires PHP: 8.0
 */

if (! defined('ABSPATH')) {
    exit;
}

define('DM_KAIZIN_PLUGIN_FILE', __FILE__);
define('DM_KAIZIN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DM_KAIZIN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DM_KAIZIN_PLUGIN_VERSION', '1.0.0');

require_once DM_KAIZIN_PLUGIN_DIR . 'includes/helpers.php';
require_once DM_KAIZIN_PLUGIN_DIR . 'includes/logging.php';
require_once DM_KAIZIN_PLUGIN_DIR . 'includes/webhooks.php';
require_once DM_KAIZIN_PLUGIN_DIR . 'includes/settings.php';
require_once DM_KAIZIN_PLUGIN_DIR . 'includes/hooks.php';

register_activation_hook(__FILE__, 'dm_kaizin_activate_plugin');
register_deactivation_hook(__FILE__, 'dm_kaizin_deactivate_plugin');

function dm_kaizin_activate_plugin()
{
    $upload_dir = wp_upload_dir();
    $log_file = trailingslashit($upload_dir['basedir']) . 'kaizin-webhooks.log';
    $debug_log_file = trailingslashit($upload_dir['basedir']) . 'kaizin-debug.log';
    if (! file_exists($log_file)) {
        file_put_contents($log_file, '');
    }
    if (! file_exists($debug_log_file)) {
        file_put_contents($debug_log_file, '');
    }
}

function dm_kaizin_deactivate_plugin()
{
    // Nothing to do for now.
}
