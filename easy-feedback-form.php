<?php
/*
Plugin Name: Easy Feedback Form – Simple, clean feedback & survey form
Plugin URI: https://github.com/iamsandeepdahiya/Easy-Feedback-Form
Description: A simple, clean feedback & survey form plugin for WordPress
Version: 1.0.0
Author: Sandeep Dahiya
Author URI: https://profiles.wordpress.org/sandeepdahiya/
License: GPL v2 or later
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('EEFORM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EEFORM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EEFORM_VERSION', '1.1');

// Autoload classes
spl_autoload_register(function ($class) {
    $prefix = 'EEFORM_';
    $base_dir = EEFORM_PLUGIN_DIR . 'includes/';

    // Check if the class uses this prefix
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    // Get the relative class name
    $relative_class = substr($class, $len);

    // Handle special cases for admin classes
    if (strpos($relative_class, 'Admin_') === 0) {
        $file = $base_dir . 'admin/class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    } else {
        // For other classes
        $file = $base_dir . 'class-' . str_replace('_', '-', strtolower($relative_class)) . '.php';
    }

    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});

// Initialize the plugin
class EEFORM_Feedback_Form {
    /**
     * Initialize the plugin
     */
    public static function init() {
        // Initialize database
        register_activation_hook(__FILE__, array('EEFORM_Database', 'create_tables'));
        add_action('plugins_loaded', array('EEFORM_Database', 'maybe_upgrade'));

        // Initialize admin
        if (is_admin()) {
            EEFORM_Admin_Page::init();
        }

        // Initialize form handler
        EEFORM_Form_Handler::init();

        // Register shortcode
        add_shortcode('easy_feedback_form', array('EEFORM_Form_Handler', 'render_form'));
    }
}

// Start the plugin
EEFORM_Feedback_Form::init(); 