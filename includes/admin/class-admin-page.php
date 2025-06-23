<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class EEFORM_Admin_Page {
    /**
     * Initialize the admin page
     */
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_item'));
        add_action('admin_init', array(__CLASS__, 'handle_actions'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /**
     * Add menu item to WordPress admin
     */
    public static function add_menu_item() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';
        
        // Check if read_status column exists
        $row = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'read_status'");
        if (!empty($row)) {
            $unread_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE read_status = 0");
        } else {
            $unread_count = 0;
        }
        
        $menu_title = 'Easy Feedback';
        if ($unread_count > 0) {
            $menu_title .= " <span class='update-plugins count-{$unread_count}'><span class='feedback-count'>" . number_format_i18n($unread_count) . "</span></span>";
        }
        
        add_menu_page(
            'Feedback Received',
            $menu_title,
            'manage_options',
            'feedback-submissions',
            array(__CLASS__, 'render_page'),
            'dashicons-feedback',
            30
        );
    }

    /**
     * Handle admin page actions
     */
    public static function handle_actions() {
        if (isset($_GET['action']) && $_GET['action'] === 'delete_feedback' && isset($_GET['feedback_id']) && isset($_GET['_wpnonce'])) {
            if (!wp_verify_nonce($_GET['_wpnonce'], 'delete_feedback_' . $_GET['feedback_id'])) {
                wp_die('Security check failed');
            }

            if (!current_user_can('manage_options')) {
                wp_die('You do not have permission to delete feedback entries');
            }

            $feedback_id = intval($_GET['feedback_id']);
            $result = EEFORM_Database::delete_feedback($feedback_id);

            if ($result) {
                wp_redirect(admin_url('admin.php?page=feedback-submissions&deleted=1'));
            } else {
                wp_redirect(admin_url('admin.php?page=feedback-submissions&error=1'));
            }
            exit;
        }
    }

    /**
     * Enqueue admin styles
     */
    public static function enqueue_styles($hook) {
        if ('toplevel_page_feedback-submissions' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'eeform-admin-styles',
            EEFORM_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EEFORM_VERSION
        );
    }

    /**
     * Render the admin page
     */
    public static function render_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'view_feedback' && isset($_GET['feedback_id'])) {
            self::render_single_view();
        } else {
            self::render_list_view();
        }
    }

    /**
     * Render single submission view
     */
    private static function render_single_view() {
        require_once EEFORM_PLUGIN_DIR . 'includes/admin/views/single-submission.php';
    }

    /**
     * Render submissions list view
     */
    private static function render_list_view() {
        require_once EEFORM_PLUGIN_DIR . 'includes/admin/views/list-submissions.php';
    }
} 