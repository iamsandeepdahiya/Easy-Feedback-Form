<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check admin capabilities
if (!current_user_can('manage_options')) {
    wp_die(
        esc_html__('You do not have sufficient permissions to access this page.', 'easy-feedback-form'),
        esc_html__('Permission Denied', 'easy-feedback-form'),
        array('response' => 403)
    );
}

global $wpdb;
$table_name = $wpdb->prefix . 'feedback_submissions';
$cache_group = 'feedback_submissions_list';

// Add pagination for 15 per page
$per_page = 15;
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a read-only operation (pagination count/display) and does not require a nonce for security. Nonces are used for state-changing actions.
$current_page = isset($_GET['paged']) ? max(1, intval(wp_unslash($_GET['paged']))) : 1;
$offset = ($current_page - 1) * $per_page;

// --- Caching for Total Count ---
$total_items_cache_key = 'eeform_total_submissions_count';
$total_items = wp_cache_get($total_items_cache_key, $cache_group);

if (false === $total_items) {
    // Get total count for pagination
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching is performed above. Necessary direct query for custom table count, and the results are immediately cached for performance.
    $total_items = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM %i", $table_name));
    if (!empty($total_items)) {
        wp_cache_set($total_items_cache_key, $total_items, $cache_group, 3600); // Cache for 1 hour
    }
}
$total_pages = ceil($total_items / $per_page);


// --- Caching for Submissions List ---
$submissions_list_cache_key = 'eeform_submissions_page_' . $current_page . '_per_' . $per_page;
$submissions = wp_cache_get($submissions_list_cache_key, $cache_group);

if (false === $submissions) {
    // Get submissions with pagination and proper escaping
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Caching is performed above. Necessary direct query for custom table count, and the results are immediately cached for performance.
    $submissions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM %i ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $table_name,
        $per_page,
        $offset
    ));
    if (!empty($submissions)) {
        wp_cache_set($submissions_list_cache_key, $submissions, $cache_group, 3600); // Cache for 1 hour
    }
}

// Show delete confirmation message with proper escaping
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a post-redirect display of a status message and does not require nonce verification. The deletion action is handled and nonced elsewhere. Input is sanitized with intval(wp_unslash()).
$deleted_status = isset($_GET['deleted']) ? intval(wp_unslash($_GET['deleted'])) : 0;
if ($deleted_status === 1) {
    $message = __('Feedback entry deleted successfully.', 'easy-feedback-form');
    echo wp_kses_post(sprintf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html($message)
    ));
}

// Sanitize and unslash 'error' input
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a post-redirect display of a status message and does not require nonce verification. Input is sanitized with intval(wp_unslash()).
$error_status = isset($_GET['error']) ? intval(wp_unslash($_GET['error'])) : 0;
if ($error_status === 1) {
    $message = __('An error occurred while processing your request.', 'easy-feedback-form');
    echo wp_kses_post(sprintf(
        '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
        esc_html($message)
    ));
}
?>

<div class="wrap">
    <h1>Feedback Received</h1>

    <?php if (empty($submissions)): ?>
        <div class="notice notice-info">
            <p><?php esc_html_e('No feedback submissions found.', 'easy-feedback-form'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-id"><?php esc_html_e('ID', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php esc_html_e('Status', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-name"><?php esc_html_e('Name', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-email"><?php esc_html_e('Email', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-message"><?php esc_html_e('Message', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php esc_html_e('Date', 'easy-feedback-form'); ?></th>
                    <th scope="col" class="manage-column column-actions"><?php esc_html_e('Actions', 'easy-feedback-form'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $submission):
                    // Check if read_status property exists
                    $read_status = property_exists($submission, 'read_status') ? $submission->read_status : 1;
                ?>
                <tr>
                    <td><?php echo esc_html($submission->id); ?></td>
                    <td>
                        <?php if ($read_status == 0): ?>
                            <span class="feedback-status unread"><?php esc_html_e('New', 'easy-feedback-form'); ?></span>
                        <?php else: ?>
                            <span class="feedback-status read"><?php esc_html_e('Read', 'easy-feedback-form'); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($submission->name); ?></td>
                    <td><?php echo esc_html($submission->email); ?></td>
                    <td><?php echo esc_html(wp_trim_words($submission->message, 10, '...')); ?></td>
                    <td><?php echo esc_html(
                        date_i18n(
                            get_option('date_format') . ' ' . get_option('time_format'),
                            strtotime($submission->created_at)
                        )
                    ); ?></td>
                    <td>
                        <?php
                        $view_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'page' => 'easy-feedback-form',
                                    'action' => 'view_feedback',
                                    'feedback_id' => $submission->id
                                ),
                                admin_url('admin.php')
                            ),
                            'view_feedback_' . $submission->id
                        );

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
                        <a href="<?php echo esc_url($view_url); ?>"
                           class="button button-small">
                            <?php esc_html_e('View', 'easy-feedback-form'); ?>
                        </a>
                        <a href="<?php echo esc_url($delete_url); ?>"
                           class="button button-small button-link-delete"
                           onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this feedback entry?', 'easy-feedback-form')); ?>');">
                            <?php esc_html_e('Delete', 'easy-feedback-form'); ?>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        // Add pagination links
        echo '<div class="tablenav bottom">';
        echo '<div class="tablenav-pages">';
        echo wp_kses_post(paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;', 'easy-feedback-form'),
            'next_text' => __('&raquo;', 'easy-feedback-form'),
            'total' => $total_pages,
            'current' => $current_page
        )));
        echo '</div>';
        echo '</div>';
        ?>
    <?php endif; ?>
</div>