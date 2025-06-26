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
     *
     * Uses WordPress Transients for rate limiting based on IP address.
     *
     * @return bool True if within limit, false if limit exceeded.
     */
    private static function check_rate_limit() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Input is sanitized and validated for the specific use case (rate limiting).
        $ip_address = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        
        // If no IP address is found (e.g., in a CLI environment), assume not rate limited.
        if (empty($ip_address)) {
            return true; 
        }

        $transient_key = 'eeform_feedback_limit_' . md5($ip_address);
        $submission_count = get_transient($transient_key);

        if ($submission_count === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return true;
        }

        if ($submission_count >= 25) { // Max 25 submissions per hour
            return false;
        }

        set_transient($transient_key, $submission_count + 1, HOUR_IN_SECONDS);
        return true;
    }

    /**
     * Validate form fields
     *
     * @param string $raw_name The submitter's raw name.
     * @param string $raw_email The submitter's raw email.
     * @param string $raw_message The feedback raw message.
     * @param string $sanitized_name The submitter's sanitized name.
     * @param string $sanitized_email The submitter's sanitized email.
     * @param string $sanitized_message The feedback sanitized message.
     * @return array An array of validation error messages.
     */
    private static function validate_fields($raw_name, $raw_email, $raw_message, $sanitized_name, $sanitized_email, $sanitized_message) {
        $errors = array();

        // Name validation: Check raw length, then if it became empty after sanitization
        if (strlen($raw_name) > 100) {
            $errors[] = esc_html__('Please provide a valid name (maximum 100 characters).', 'easy-feedback-form');
        } elseif (empty($sanitized_name)) { 
            $errors[] = esc_html__('Please provide a valid name.', 'easy-feedback-form');
        }

        // Email validation: Check raw length, then if it's a valid email after sanitization
        if (strlen($raw_email) > 100 || !is_email($sanitized_email)) {
            $errors[] = esc_html__('Please provide a valid email address (maximum 100 characters).', 'easy-feedback-form');
        }

        // Message validation: Check raw length, then if it became empty after sanitization
        if (strlen($raw_message) > 1000) {
            $errors[] = esc_html__('Please provide a message (maximum 1000 characters).', 'easy-feedback-form');
        } elseif (empty($sanitized_message)) { 
            $errors[] = esc_html__('Please provide a message.', 'easy-feedback-form');
        }

        return $errors;
    }

    /**
     * Handle form submission
     */
    public static function handle_submission() {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- $_SERVER['REQUEST_METHOD'] is checked for existence before use.
        if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['feedback_nonce'])) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce check is present.
        $nonce = sanitize_text_field(wp_unslash($_POST['feedback_nonce']));
        if (!wp_verify_nonce($nonce, 'submit_feedback')) {
            wp_die(esc_html__('Security check failed', 'easy-feedback-form'), esc_html__('Security Error', 'easy-feedback-form'), array('response' => 403));
        }

        // Check rate limit for submissions.
        if (!self::check_rate_limit()) {
            wp_die(esc_html__('Submission limit exceeded. Please try again later.', 'easy-feedback-form'), esc_html__('Rate Limit Error', 'easy-feedback-form'), array('response' => 429));
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input captured before sanitization, sanitized later.
        $raw_name = isset($_POST['name']) ? wp_unslash($_POST['name']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input captured before sanitization, sanitized later.
        $raw_email = isset($_POST['email']) ? wp_unslash($_POST['email']) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw input captured before sanitization, sanitized later.
        $raw_message = isset($_POST['message']) ? wp_unslash($_POST['message']) : '';

        // Sanitize the input for safe use/storage
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Input validated by validate_fields method after sanitization.
        $sanitized_name = sanitize_text_field($raw_name);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Input validated by validate_fields method after sanitization.
        $sanitized_email = sanitize_email($raw_email);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Input validated by validate_fields method after sanitization.
        $sanitized_message = sanitize_textarea_field($raw_message);
        
        // Validate fields, passing both raw and sanitized versions
        $errors = self::validate_fields(
            $raw_name, $raw_email, $raw_message,
            $sanitized_name, $sanitized_email, $sanitized_message
        );

        if (!empty($errors)) {
            // Store errors and raw input in a transient for display on redirect
            $error_data = array(
                'errors' => $errors,
                'old_input' => array(
                    'name' => $raw_name, 
                    'email' => $raw_email,
                    'message' => $raw_message,
                ),
            );
            $error_token = wp_hash(uniqid('eeform_error', true));
            set_transient('eeform_form_errors_' . $error_token, $error_data, MINUTE_IN_SECONDS); // Valid for 1 minute

            // Redirect back to the current page with the error token
            // phpcs:ignore  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER['REQUEST_URI'] is used to get the base path for redirect.
            $raw_request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
            $current_url_base = strtok(sanitize_url($raw_request_uri), '?');
            $redirect_url = add_query_arg('eeform_error_token', $error_token, $current_url_base);
            wp_safe_redirect($redirect_url);
            exit;
        }

        // Insert feedback (only if no validation errors) using the SANITIZED data
        $result = EEFORM_Database::insert_feedback($sanitized_name, $sanitized_email, $sanitized_message);

        if ($result) {
            // Use wp_hash to store success message securely in a transient
            $token = wp_hash(uniqid('eeform_feedback', true));
            set_transient('eeform_feedback_success_' . $token, true, 30); // Valid for 30 seconds

            // Prepare base URI: check existence, unslash, and sanitize immediately.
            // phpcs:ignore  WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_SERVER['REQUEST_URI'] is used to get the base path for redirect.
            $base_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_url(wp_unslash($_SERVER['REQUEST_URI'])) : '';
            
            $redirect_url = add_query_arg('feedback_token', $token, strtok($base_uri, '?'));
            wp_safe_redirect($redirect_url);
            exit;
        } else {
            wp_die(esc_html__('Failed to save feedback. Please try again.', 'easy-feedback-form'), esc_html__('Error', 'easy-feedback-form'), array('response' => 500));
        }
    }

    /**
     * Enqueue frontend styles for the feedback form.
     *
     * @hook wp_enqueue_scripts
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
     * Render the feedback form HTML.
     *
     * Handles displaying success messages via transients and includes CSRF protection.
     *
     * @return string The HTML output of the feedback form.
     */
    public static function render_form() {
        ob_start();
        
        $form_errors = array();
        $old_form_input = array(
            'name' => '',
            'email' => '',
            'message' => '',
        );

        // Check for success message using secure token
        // This is not form data processing but retrieving a server-generated token for a one-time success message display.
        // The actual form submission where this token originated was nonce-verified.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a server-generated token for a redirect, not user-submitted form data requiring a new nonce check.
        if (isset($_GET['feedback_token'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a server-generated token for a redirect, not user-submitted form data requiring a new nonce check.
            $token = sanitize_text_field(wp_unslash($_GET['feedback_token']));
            $success = get_transient('eeform_feedback_success_' . $token);
            if ($success) {
                delete_transient('eeform_feedback_success_' . $token);
                ?>
                <div class="feedback-success-message" id="feedback-success">
                    <span class="close-button" onclick="this.parentElement.style.display='none';">&times;</span>
                    <p><?php esc_html_e('Thank you for your feedback! We appreciate your time and will review your submission.', 'easy-feedback-form'); ?></p>
                </div>
                <?php
            }
        }

        // Check for validation errors from a previous submission via transient
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a server-generated token for a redirect, not user-submitted form data requiring a new nonce check.
        if (isset($_GET['eeform_error_token'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a server-generated token for a redirect, not user-submitted form data requiring a new nonce check.
            $error_token = sanitize_key(wp_unslash($_GET['eeform_error_token']));
            $error_data = get_transient('eeform_form_errors_' . $error_token);
            if ($error_data && is_array($error_data)) {
                $form_errors = isset($error_data['errors']) ? $error_data['errors'] : array();
                $old_form_input = isset($error_data['old_input']) ? $error_data['old_input'] : $old_form_input;
                delete_transient('eeform_form_errors_' . $error_token);
            }
        }
        
        // Display general errors if any
        if (!empty($form_errors)) {
            echo '<div class="feedback-error-message">';
            echo '<ul>';
            foreach ($form_errors as $error) {
                echo '<li>' . esc_html($error) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }

        ?>
        <div class="eeform-container">
            <form id="eeform" method="post" action="">
                <?php wp_nonce_field('submit_feedback', 'feedback_nonce'); ?>
                <div class="form-group">
                    <label for="name"><?php esc_html_e('Name:', 'easy-feedback-form'); ?></label>
                    <input type="text" name="name" id="name" maxlength="100" required 
                            value="<?php echo esc_attr($old_form_input['name']); ?>"
                            pattern=".{1,100}" title="<?php esc_attr_e('Name must be between 1 and 100 characters', 'easy-feedback-form'); ?>"
                            placeholder="<?php esc_attr_e('Your name', 'easy-feedback-form'); ?>">
                    <p class="eeform-hint"><?php esc_html_e('Maximum 100 characters.', 'easy-feedback-form'); ?></p>
                </div>
                
                <div class="form-group">
                    <label for="email"><?php esc_html_e('Email:', 'easy-feedback-form'); ?></label>
                    <input type="email" name="email" id="email" maxlength="100" required
                            value="<?php echo esc_attr($old_form_input['email']); ?>"
                            placeholder="<?php esc_attr_e('your.email@example.com', 'easy-feedback-form'); ?>">
                    <p class="eeform-hint"><?php esc_html_e('Maximum 100 characters.', 'easy-feedback-form'); ?></p>
                </div>
                
                <div class="form-group">
                    <label for="message"><?php esc_html_e('Message:', 'easy-feedback-form'); ?></label>
                    <textarea name="message" id="message" required maxlength="1000" 
                                title="<?php esc_attr_e('Message must not exceed 1000 characters', 'easy-feedback-form'); ?>"
                                placeholder="<?php esc_attr_e('Your feedback message', 'easy-feedback-form'); ?>"><?php echo esc_textarea($old_form_input['message']); ?></textarea>
                    <p class="eeform-hint"><?php esc_html_e('Maximum 1000 characters.', 'easy-feedback-form'); ?></p>
                </div>
                
                <input type="submit" value="<?php esc_attr_e('Submit', 'easy-feedback-form'); ?>" class="button button-primary">
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}