<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check admin capabilities
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have permission to view feedback entries', 'easy-feedback-form'),
        esc_html__('Permission Denied', 'easy-feedback-form'),
        array('response' => 403)
    );
}

// Initialize feedback_id with a safe default
$feedback_id = 0;

// Sanitize and unslash feedback_id from GET before nonce verification
if (isset($_GET['feedback_id'], $_GET['_wpnonce'])) {
    // Sanitize and unslash the nonce immediately.
    $nonce = sanitize_text_field(wp_unslash($_GET['_wpnonce']));
    // Retrieve feedback_id as a raw string for nonce action construction first.
    // We'll intval it *after* nonce verification.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized --The raw feedback id is sanitized after nonce verification below.
    $raw_feedback_id = wp_unslash($_GET['feedback_id']); 

    if (!wp_verify_nonce($nonce, 'view_feedback_' . $raw_feedback_id)) {
        wp_die(
            esc_html__('Security check failed', 'easy-feedback-form'),
            esc_html__('Security Error', 'easy-feedback-form'),
            array('response' => 403)
        );
    }

    // Now that the nonce is verified, it's safe to fully sanitize the feedback_id.
    $feedback_id = intval($raw_feedback_id);
}


global $wpdb;
$table_name = $wpdb->prefix . 'feedback_submissions';

// Feedback ID is already sanitized and unslashed from the nonce check above.
if (!$feedback_id) {
    wp_die(
        esc_html__('Invalid feedback ID', 'easy-feedback-form'),
        esc_html__('Invalid Request', 'easy-feedback-form'),
        array('response' => 400) 
    );
}

// Define a unique cache key for this specific submission
$cache_key = 'eeform_submission_' . $feedback_id;
$cache_group = 'feedback_submissions';

// Try to get submission from cache first
$submission = wp_cache_get($cache_key, $cache_group);

// If not found in cache, fetch from the database
if (false === $submission) {
    // Get submission with proper escaping
    // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This is a necessary direct query to a custom table, and caching is implemented.
    $submission = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM %i WHERE id = %d", // Using %i for table name
        $table_name, // Pass table name as a parameter
        $feedback_id
    ));

    // Store the result in cache, for 1 hour (3600 seconds)
    // You can adjust the expiration time as needed
    if (!empty($submission)) {
        wp_cache_set($cache_key, $submission, $cache_group, 3600);
    }
}

if (!$submission) {
    wp_die(
        esc_html__('Feedback submission not found', 'easy-feedback-form'),
        esc_html__('Not Found', 'easy-feedback-form'),
        array('response' => 404)
    );
}

// Mark this submission as read with proper escaping
if (property_exists($submission, 'read_status') && $submission->read_status == 0) {
    // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Direct update to a custom table is necessary, and its cache is invalidated immediately after.
    $wpdb->update(
        $table_name,
        array('read_status' => 1),
        array('id' => $feedback_id),
        array('%d'), // Format for the updated column (read_status is integer)
        array('%d')  // Format for the WHERE clause column (id is integer)
    );
    // Update the object to reflect the change
    $submission->read_status = 1;

    // Invalidate the cache for this specific submission after update
    wp_cache_delete($cache_key, $cache_group);
    wp_cache_flush_group('feedback_submissions_list'); 
}

// Get the referring page URL
$list_url = wp_get_referer();
if (!$list_url || strpos($list_url, 'page=easy-feedback-form') === false) {
    $list_url = admin_url('admin.php?page=easy-feedback-form');
}
?>
<div class="wrap">
    <h1>
        <?php esc_html_e('View Feedback', 'easy-feedback-form'); ?>
        <a href="<?php echo esc_url($list_url); ?>" class="page-title-action"><?php esc_html_e('Back to List', 'easy-feedback-form'); ?></a>
    </h1>

    <div class="feedback-detail-container">
        <div class="feedback-detail-card">
            <div class="feedback-detail-header">
                <div class="feedback-meta">
                    <span class="feedback-date">
                        <?php 
                        printf(
                            // Translators: %s is the submission date.
                            esc_html__( 'Submitted on: %s', 'easy-feedback-form' ),
                            esc_html( date_i18n(
                            get_option( 'date_format' ) . ' \a\t ' . get_option( 'time_format' ),
                            strtotime( $submission->created_at )
                            ) )
                        );
                        ?>
                    </span>
                    <?php 
                    $read_status = property_exists($submission, 'read_status') ? $submission->read_status : 1;
                    if ($read_status == 0): 
                    ?>
                        <span class="feedback-status unread"><?php esc_html_e('New', 'easy-feedback-form'); ?></span>
                    <?php else: ?>
                        <span class="feedback-status read"><?php esc_html_e('Read', 'easy-feedback-form'); ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="feedback-detail-content">
                <div class="feedback-field">
                    <label><?php esc_html_e('Name:', 'easy-feedback-form'); ?></label>
                    <div class="field-value"><?php echo esc_html($submission->name); ?></div>
                </div>

                <div class="feedback-field">
                    <label><?php esc_html_e('Email:', 'easy-feedback-form'); ?></label>
                    <div class="field-value">
                        <a href="<?php echo esc_url('mailto:' . antispambot($submission->email)); ?>">
                            <?php echo esc_html(antispambot($submission->email)); ?>
                        </a>
                    </div>
                </div>

                <div class="feedback-field">
                    <label><?php esc_html_e('Message:', 'easy-feedback-form'); ?></label>
                    <div class="field-value message">
                        <?php echo wp_kses_post(nl2br(esc_html($submission->message))); ?>
                    </div>
                </div>
            </div>

            <div class="feedback-detail-footer">
                <?php
                $delete_url = wp_nonce_url(
                    add_query_arg(
                        array(
                            'page' => 'easy-feedback-form',
                            'action' => 'delete_feedback',
                            'feedback_id' => $submission->id
                        ),
                        admin_url('admin.php')
                    ),
                    'delete_feedback_' . $submission->id
                );
                ?>
                <a href="<?php echo esc_url($delete_url); ?>" 
                   class="button button-link-delete"
                   onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this feedback entry?', 'easy-feedback-form')); ?>');">
                    <?php esc_html_e('Delete Submission', 'easy-feedback-form'); ?>
                </a>
            </div>
        </div>
    </div>
</div>