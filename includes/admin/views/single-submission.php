<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check admin capabilities
if (!current_user_can('manage_options')) {
    wp_die(esc_html__('You do not have permission to view feedback entries', 'easy-feedback-form'));
}

// Verify nonce for viewing feedback
if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'view_feedback_' . $_GET['feedback_id'])) {
    wp_die(esc_html__('Security check failed', 'easy-feedback-form'));
}

global $wpdb;
$table_name = $wpdb->prefix . 'feedback_submissions';
$feedback_id = isset($_GET['feedback_id']) ? intval($_GET['feedback_id']) : 0;

if (!$feedback_id) {
    wp_die(esc_html__('Invalid feedback ID', 'easy-feedback-form'));
}

// Get submission with proper escaping
$submission = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM $table_name WHERE id = %d",
    $feedback_id
));

if (!$submission) {
    wp_die(esc_html__('Feedback submission not found', 'easy-feedback-form'));
}

// Mark this submission as read with proper escaping
if (property_exists($submission, 'read_status') && $submission->read_status == 0) {
    $wpdb->update(
        $table_name,
        array('read_status' => 1),
        array('id' => $feedback_id),
        array('%d'),
        array('%d')
    );
    // Update the object to reflect the change
    $submission->read_status = 1;
}

// Get the referring page URL
$list_url = wp_get_referer();
if (!$list_url || strpos($list_url, 'page=feedback-submissions') === false) {
    $list_url = admin_url('admin.php?page=feedback-submissions');
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
                            'page' => 'feedback-submissions',
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