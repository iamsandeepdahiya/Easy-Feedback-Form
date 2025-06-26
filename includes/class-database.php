<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class EEFORM_Database {
    /**
     * Get database version
     *
     * @return string Current database version.
     */
    public static function get_db_version() {
        return get_option('eeform_db_version', '1.0.0');
    }

    /**
     * Create or upgrade database tables
     *
     * This function uses dbDelta for table creation and updates.
     * Direct database calls are necessary for schema management.
     */
    public static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';
        
        $charset_collate = $wpdb->get_charset_collate();

        // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery,-- dbDelta is required for table creation/upgrades and cannot use caching.
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            name varchar(100) NOT NULL,
            email varchar(100) NOT NULL,
            message text NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            read_status tinyint(1) DEFAULT 0,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        // Set initial version
        if (get_option('eeform_db_version') === false) {
            add_option('eeform_db_version', '1.0.0');
        }
    }

    /**
     * Upgrade database if needed
     *
     * This handles adding the read_status column.
     */
    public static function maybe_upgrade() {
        $installed_version = self::get_db_version();
        
        // If version is less than 1.0.0 (initial install logic for column)
        if (version_compare($installed_version, '1.0.0', '<')) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'feedback_submissions';
            
            // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary direct query for schema inspection. Table name and LIKE pattern are sanitized via prepare. No caching applicable.
            $row = $wpdb->get_results($wpdb->prepare("SHOW COLUMNS FROM %i LIKE %s", $table_name, 'read_status'));

            if (empty($row)) {
                // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE is a direct schema modification. %i is used for table name sanitization. No caching applicable.
                $wpdb->query($wpdb->prepare("ALTER TABLE %i ADD COLUMN read_status tinyint(1) DEFAULT 0", $table_name));
            }
            update_option('eeform_db_version', '1.0.0');
        }
    }

    /**
     * Insert a new feedback submission
     *
     * @param string $name The name of the submitter.
     * @param string $email The email of the submitter.
     * @param string $message The feedback message.
     * @return int|false The ID of the inserted row on success, or false on failure.
     */
    public static function insert_feedback($name, $email, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';
        
        // Sanitize input data before insertion
        $sanitized_name = sanitize_text_field($name);
        $sanitized_email = sanitize_email($email);
        $sanitized_message = sanitize_textarea_field($message);

        // When inserting, we don't fetch/cache. We will need to invalidate related caches.
        // phpcs:ignore  WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary direct query for schema inspection. Table name and LIKE pattern are sanitized via prepare. No caching applicable.
        $result = $wpdb->insert(
            $table_name,
            array(
                'name' => $sanitized_name,
                'email' => $sanitized_email,
                'message' => $sanitized_message,
                'read_status' => 0
            ),
            array('%s', '%s', '%s', '%d')
        );

        // Invalidate relevant caches after a successful insert.
        if (false !== $result) {
            wp_cache_delete('eeform_total_submissions_count', 'feedback_submissions_list');
            wp_cache_flush_group('feedback_submissions_list');
        }

        return $result;
    }

    /**
     * Delete feedback and reindex IDs
     *
     * This complex operation reindexes IDs after deletion to maintain sequential IDs.
     * It involves multiple direct database queries within a transaction.
     *
     * @param int $feedback_id The ID of the feedback entry to delete.
     * @return bool True on success, false on failure.
     */
    public static function delete_feedback($feedback_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'feedback_submissions';

        // Start transaction
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactions are direct SQL; no higher-level API.
        $wpdb->query('START TRANSACTION');

        try {
            // Delete the feedback
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct delete to a custom table; no higher-level API.
            $wpdb->delete(
                $table_name,
                array('id' => $feedback_id),
                array('%d')
            );

            // Get all remaining records ordered by creation date
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Necessary query for reindexing; table name is sanitized via prepare. Caching not practical here due to immediate modification.
            $results = $wpdb->get_results($wpdb->prepare("SELECT id FROM %i ORDER BY created_at ASC", $table_name));
            
            // Create temporary table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- CREATE TEMPORARY TABLE is a direct schema modification; no higher-level API.
            $wpdb->query("CREATE TEMPORARY TABLE temp_ids (
                old_id mediumint(9),
                new_id mediumint(9) AUTO_INCREMENT,
                PRIMARY KEY (new_id)
            )");

            // Map old IDs to new sequential IDs
            foreach ($results as $row) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserting into a temporary table for reindexing; no higher-level API.
                $wpdb->insert(
                    'temp_ids',
                    array('old_id' => $row->id),
                    array('%d')
                );
            }

            // Update original table with new IDs
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct query for reindexing IDs; all table names are sanitized via prepare. No higher-level API.
            $wpdb->query($wpdb->prepare("UPDATE %i 
                                         JOIN temp_ids ON %i.id = temp_ids.old_id 
                                         SET %i.id = temp_ids.new_id",
                                         $table_name, 
                                         $table_name, 
                                         $table_name)); 
            
            // Drop temporary table
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- DROP TEMPORARY TABLE is a direct schema modification; no higher-level API.
            $wpdb->query("DROP TEMPORARY TABLE IF EXISTS temp_ids");

            // Reset auto increment
            $next_id = count($results) + 1;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange -- ALTER TABLE is a direct schema modification. Table name and auto-increment value are sanitized via prepare. No higher-level API.
            $wpdb->query($wpdb->prepare("ALTER TABLE %i AUTO_INCREMENT = %d", $table_name, $next_id));

            // Commit transaction
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactions are direct SQL; no higher-level API.
            $wpdb->query('COMMIT');

            // Invalidate the cache for the specific item being deleted.
            wp_cache_delete('eeform_submission_' . $feedback_id, 'feedback_submissions');
            // Flushes all entries in this group.
            wp_cache_flush_group('feedback_submissions_list'); 

            return true;
        } catch (Exception $e) {
            // Rollback on error
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transactions are direct SQL; no higher-level API.
            $wpdb->query('ROLLBACK');
            return false;
        }
    }
}