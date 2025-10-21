<?php
/**
 * Utility Helper
 * 
 * Common utility functions
 */

if ( !defined( 'ABSPATH' ) ) exit;

class WC_Utility_Helper {

    /**
     * Log messages to file and error log
     */
    public static function log_message( $message ) {
        $log_entry = "[" . current_time( 'Y-m-d H:i:s' ) . "] $message\n";
        $log_file  = WP_CONTENT_DIR . '/delete-images-cleanup.log';
        error_log( $log_entry, 3, $log_file );
    }

    /**
     * Log cleanup activity
     */
    public static function log_cleanup( $stats, $products ) {
        $log_entry = sprintf(
            "[%s] Cleanup Stats:\n" .
            "  - Products Scanned: %d\n" .
            "  - Non-Brazyliany Found: %d\n" .
            "  - Brazyliany Found: %d\n" .
            "  - Products Deleted: %d\n" .
            "  - Images Deleted: %d\n" .
            "  - Variations Deleted: %d\n" .
            "  - Execution Time: %s\n\n",
            current_time( 'Y-m-d H:i:s' ),
            $stats['total_scanned'],
            $stats['non_brazyliany_found'],
            $stats['brazyliany_found'],
            $stats['products_deleted'],
            $stats['images_deleted'],
            $stats['variations_deleted'],
            $stats['execution_time']
        );

        $log_file = WP_CONTENT_DIR . '/delete-images-cleanup.log';
        error_log( $log_entry, 3, $log_file );

        // Store in database for admin viewing
        update_option( 'delete_images_last_cleanup', [
            'date'        => current_time( 'mysql' ),
            'count'       => $stats['products_deleted'],
            'product_ids' => array_column( $products, 'product_id' ),
            'stats'       => $stats,
        ] );
    }

    /**
     * Create custom table for deleted products tracking
     */
    public static function create_deleted_products_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'out_of_stock_products_data';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            product_title varchar(255) NOT NULL,
            category_slug varchar(100) NOT NULL,
            attachment_ids text,
            deleted_at datetime NOT NULL,
            images_deleted tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY images_deleted (images_deleted),
            KEY deleted_at (deleted_at)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}

