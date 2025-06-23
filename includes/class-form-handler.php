<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class EEFORM_Form_Handler {
    /**
     * Initialize the form handler
     */
    public static function init() {
        add_action('init', array(__CLASS__, 'handle_submission'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'));
    }

    /**
     * Check if user has exceeded submission limit
     */
    private static function check_rate_limit() {
        $ip_address = $_SERVER['REMOTE_ADDR'];
        $transient_key = 'eeform_feedback_limit_' . md5($ip_address);
        $submission_count = get_transient($transient_key);

        if ($submission_count === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return true;
        }

        if ($submission_count >= 5) { // Max 5 submissions per hour
            return false;
        }

        set_transient($transient_key, $submission_count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Validate form fields
     */
    private static function validate_fields($name, $email, $message) {
        $errors = array();

        // Name validation
        if (empty($name) || strlen($name) > 100) {
            $errors[] = 'Please provide a valid name (maximum 100 characters).';
        }

        // Email validation
        if (!is_email($email)) {
            $errors[] = 'Please provide a valid email address.';
        }

        // Message validation
        if (empty($message) || strlen($message) > 1000) {
            $errors[] = 'Please provide a message (maximum 1000 characters).';
        }

        return $errors;
    }

    /**
     * Handle form submission
     */
    public static function handle_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feedback_nonce'])) {
            return;
        }

        // Verify nonce
        if (!wp_verify_nonce($_POST['feedback_nonce'], 'submit_feedback')) {
            wp_die('Security check failed', 'Security Error', array('response' => 403));
        }

        // Check rate limit
        if (!self::check_rate_limit()) {
            wp_die('Submission limit exceeded. Please try again later.', 'Rate Limit Error', array('response' => 429));
        }

        // Get and sanitize input
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';

        // Validate fields
        $errors = self::validate_fields($name, $email, $message);
        if (!empty($errors)) {
            $escaped_errors = array_map('esc_html', $errors);
            wp_die(wp_kses_post(implode('<br>', $escaped_errors)), esc_html__('Validation Error', 'easy-feedback-form'), array('response' => 400));
        }

        // Insert feedback
        $result = EEFORM_Database::insert_feedback($name, $email, $message);

        if ($result) {
            // Use wp_hash to store success message
            $token = wp_hash(uniqid('eeform_feedback', true));
            set_transient('eeform_feedback_success_' . $token, true, 30);
            
            // Redirect with secure token
            $redirect_url = add_query_arg('feedback_token', $token, strtok($_SERVER["REQUEST_URI"], '?'));
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            wp_die('Failed to save feedback. Please try again.', 'Error', array('response' => 500));
        }
    }

    /**
     * Enqueue frontend styles
     */
    public static function enqueue_styles() {
        wp_enqueue_style(
            'eeform-frontend-styles',
            EEFORM_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            EEFORM_VERSION
        );
    }

    /**
     * Render the feedback form
     */
    public static function render_form() {
        ob_start();
        
        // Check for success message using secure token
        if (isset($_GET['feedback_token'])) {
            $token = sanitize_key($_GET['feedback_token']);
            $success = get_transient('eeform_feedback_success_' . $token);
            if ($success) {
                delete_transient('eeform_feedback_success_' . $token);
                ?>
                <div class="feedback-success-message" id="feedback-success">
                    <span class="close-button" onclick="this.parentElement.style.display='none';">&times;</span>
                    <p>Thank you for your feedback! We appreciate your time and will review your submission.</p>
                </div>
                <?php
            }
        }

        // Add CSRF protection
        wp_nonce_field('eeform_feedback_form', 'eeform_feedback_nonce');
        ?>
        <div class="feedback-form-container">
            <form id="feedback-form" method="post" action="">
                <div class="form-group">
                    <label for="name">Name:</label>
                    <input type="text" name="name" id="name" maxlength="100" required 
                           pattern=".{1,100}" title="Name must be between 1 and 100 characters">
                </div>
                
                <div class="form-group">
                    <label for="email">Email:</label>
                    <input type="email" name="email" id="email" maxlength="100" required>
                </div>
                
                <div class="form-group">
                    <label for="message">Message:</label>
                    <textarea name="message" id="message" required maxlength="1000" 
                              title="Message must not exceed 1000 characters"></textarea>
                </div>
                
                <input type="submit" value="Submit" class="button button-primary">
                <?php wp_nonce_field('submit_feedback', 'feedback_nonce'); ?>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
} 