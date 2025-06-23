<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class EEFORM_Database {
    /**
     * Get database version
     */
    public static function get_db_version() {
        return get_option('eeform_db_version', '1.0.0');
    }

    /**
     * Create or upgrade database tables
     */
    public static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            message text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            read_status tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Set initial version
        if (get_option('eeform_db_version') === false) {
        add_option('eeform_db_version', '1.0.0');
        }
    }

    /**
     * Upgrade database if needed
     */
    public static function maybe_upgrade() {
        $installed_version = self::get_db_version();
        
        // If version is less than 1.1, add read_status column
        if (version_compare($installed_version, '1.0.0', '<')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'feedback_submissions';
            
            // Check if column exists
            $row = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'read_status'");
            if (empty($row)) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN read_status tinyint(1) DEFAULT 0");
            }
            update_option('eeform_db_version', '1.0.0');
        }
    }

    /**
     * Insert a new feedback submission
     */
    public static function insert_feedback($name, $email, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';
        
        return $wpdb->insert(
            $table_name,
            array(
                'name' => $name,
                'email' => $email,
                'message' => $message,
                'read_status' => 0
            ),
            array('%s', '%s', '%s', '%d')
        );
    }

    /**
     * Delete feedback and reindex IDs
     */
    public static function delete_feedback($feedback_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';

        // Start transaction
        $wpdb->query('START TRANSACTION');

        try {
            // Delete the feedback
            $wpdb->delete(
                $table_name,
                array('id' => $feedback_id),
                array('%d')
            );

            // Get all remaining records ordered by creation date
            $results = $wpdb->get_results("SELECT id FROM {$table_name} ORDER BY created_at ASC");
            
            // Create temporary table
            $wpdb->query("CREATE TEMPORARY TABLE temp_ids (
                old_id mediumint(9),
                new_id mediumint(9) AUTO_INCREMENT,
                PRIMARY KEY (new_id)
            )");

            // Map old IDs to new sequential IDs
            foreach ($results as $row) {
                $wpdb->insert(
                    'temp_ids',
                    array('old_id' => $row->id),
                    array('%d')
                );
            }

            // Update original table with new IDs
            $wpdb->query("UPDATE {$table_name} 
                         JOIN temp_ids ON {$table_name}.id = temp_ids.old_id 
                         SET {$table_name}.id = temp_ids.new_id");

            // Drop temporary table
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS temp_ids");

            // Reset auto increment
            $next_id = count($results) + 1;
            $wpdb->query("ALTER TABLE {$table_name} AUTO_INCREMENT = {$next_id}");

            // Commit transaction
            $wpdb->query('COMMIT');
            return true;
        } catch (Exception $e) {
            // Rollback on error
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
} 