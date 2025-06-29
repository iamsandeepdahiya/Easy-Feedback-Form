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
        
        $cache_key_unread_count = 'eeform_unread_submissions_count'; 
        $cache_key_total_count = 'eeform_total_submissions_count';  
        $cache_group_list = 'feedback_submissions_list'; 

        // Attempt to get unread count from cache first
        $unread_count = wp_cache_get($cache_key_unread_count, $cache_group_list);

        if (false === $unread_count) {
            // Check if read_status column exists
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Necessary direct query for schema inspection. Table name and LIKE pattern are sanitized via prepare. Not suitable for caching as it's a schema check.
            $row = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table_name, 'read_status'));
            if (!empty($row)) {
                // Get unread count from database
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query to get count. Result is explicitly cached below.
                $unread_count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i WHERE read_status = 0", $table_name));
            } else {
                $unread_count = 0;
            }
            
            // Set cache for unread count for 5 minutes (300 seconds)
            wp_cache_set($cache_key_unread_count, $unread_count, $cache_group_list, 300);
        }
        
        $menu_title = 'Easy Feedback';
        if ($unread_count > 0) {
            $menu_title .= " <span class='update-plugins count-{$unread_count}'><span class='feedback-count'>" . number_format_i18n($unread_count) . "</span></span>";
        }
        
        add_menu_page(
            esc_html__('Feedback Received', 'easy-feedback-form'),
            $menu_title,
            'manage_options',
            'easy-feedback-form',
            array(__CLASS__, 'render_page'),
            'dashicons-feedback',
            30
        );
    }

    /**
     * Handle admin page actions
     */
    public static function handle_actions() {
        // Check if the delete feedback action is requested
        if (isset($_GET['action']) && $_GET['action'] === 'delete_feedback') {

            // Sanitize and unslash nonce
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            // Retrieve feedback_id as a raw string for nonce action construction first.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --The raw feedback id is sanitized after nonce verification below.
            $raw_feedback_id = isset($_GET['feedback_id']) ? wp_unslash($_GET['feedback_id']) : '';

            // Verify nonce
            if (!wp_verify_nonce($nonce, 'delete_feedback_' . $raw_feedback_id)) {
                wp_die(
                    esc_html__('Security check failed', 'easy-feedback-form'),
                    esc_html__('Security Error', 'easy-feedback-form'),
                    array('response' => 403)
                );
            }

            // Now that the nonce is verified, it's safe to fully sanitize the feedback_id.
            $feedback_id = intval($raw_feedback_id);

            // Check user capabilities
            if (!current_user_can('manage_options')) {
                wp_die(
                    esc_html__('You do not have permission to delete feedback entries', 'easy-feedback-form'),
                    esc_html__('Permission Denied', 'easy-feedback-form'),
                    array('response' => 403)
                );
            }

            // Delete feedback from the database
            $result = EEFORM_Database::delete_feedback($feedback_id);

            // Redirect based on deletion result
            if ($result) {
                // Invalidate the unread count cache
                wp_cache_delete('eeform_unread_submissions_count', 'feedback_submissions_list');
                wp_cache_delete('eeform_total_submissions_count', 'feedback_submissions_list');
                wp_cache_delete('eeform_submission_' . $feedback_id, 'feedback_submissions_list');

                wp_redirect(admin_url('admin.php?page=easy-feedback-form&deleted=1'));
            } else {
                wp_redirect(admin_url('admin.php?page=easy-feedback-form&error=1'));
            }
            exit;
        }
    }

    /**
     * Enqueue admin styles
     *
     * @param string $hook The current admin page hook.
     */
    public static function enqueue_styles($hook) {
        if ('toplevel_page_easy-feedback-form' !== $hook) {
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
        // Check if the 'view_feedback' action is requested
        if (isset($_GET['action']) && $_GET['action'] === 'view_feedback' && isset($_GET['feedback_id'])) {
            // Sanitize and unslash nonce
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            // Retrieve feedback_id as a raw string for nonce action construction first.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --The raw feedback id is sanitized after nonce verification below.
            $raw_feedback_id = wp_unslash($_GET['feedback_id']); 

            // Verify nonce before rendering single view
            if (wp_verify_nonce($nonce, 'view_feedback_' . $raw_feedback_id)) {
                // Now that the nonce is verified, it's safe to fully sanitize the feedback_id.
                $feedback_id = intval($raw_feedback_id);
                self::render_single_view();
            } else {
                // Nonce verification failed, display an error or redirect
                wp_die(
                    esc_html__('Security check failed for viewing feedback.', 'easy-feedback-form'),
                    esc_html__('Security Error', 'easy-feedback-form'),
                    array('response' => 403)
                );
            }
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