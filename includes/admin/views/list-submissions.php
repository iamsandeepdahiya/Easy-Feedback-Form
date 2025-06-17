<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Check admin capabilities
if (!current_user_can('manage_options')) {
    wp_die(__('You do not have sufficient permissions to access this page.'));
}

global $wpdb;
$table_name = $wpdb->prefix . 'feedback_submissions';

// Add pagination for 15 per page
$per_page = 15;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($current_page - 1) * $per_page;

// Get total count for pagination
$total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
$total_pages = ceil($total_items / $per_page);

// Get submissions with pagination and proper escaping
$submissions = $wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table_name ORDER BY created_at DESC LIMIT %d OFFSET %d",
    $per_page,
    $offset
));

// Show delete confirmation message with proper escaping
if (isset($_GET['deleted']) && $_GET['deleted'] == 1) {
    $message = __('Feedback entry deleted successfully.');
    echo wp_kses_post(sprintf(
        '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
        esc_html($message)
    ));
}

// Show error message if present
if (isset($_GET['error']) && $_GET['error'] == 1) {
    $message = __('An error occurred while processing your request.');
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
            <p><?php _e('No feedback submissions found.'); ?></p>
        </div>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-id"><?php _e('ID'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php _e('Status'); ?></th>
                    <th scope="col" class="manage-column column-name"><?php _e('Name'); ?></th>
                    <th scope="col" class="manage-column column-email"><?php _e('Email'); ?></th>
                    <th scope="col" class="manage-column column-message"><?php _e('Message'); ?></th>
                    <th scope="col" class="manage-column column-date"><?php _e('Date'); ?></th>
                    <th scope="col" class="manage-column column-actions"><?php _e('Actions'); ?></th>
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
                            <span class="feedback-status unread"><?php _e('New'); ?></span>
                        <?php else: ?>
                            <span class="feedback-status read"><?php _e('Read'); ?></span>
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
                                    'page' => 'feedback-submissions',
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
                                    'page' => 'feedback-submissions',
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
                            <?php _e('View'); ?>
                        </a>
                        <a href="<?php echo esc_url($delete_url); ?>" 
                           class="button button-small button-link-delete"
                           onclick="return confirm('<?php echo esc_js(__('Are you sure you want to delete this feedback entry?')); ?>');">
                            <?php _e('Delete'); ?>
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
        echo paginate_links(array(
            'base' => add_query_arg('paged', '%#%'),
            'format' => '',
            'prev_text' => __('&laquo;'),
            'next_text' => __('&raquo;'),
            'total' => $total_pages,
            'current' => $current_page
        ));
        echo '</div>';
        echo '</div>';
        ?>
    <?php endif; ?>
</div> 