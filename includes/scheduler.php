<?php

class WooCommerce_Auto_Stock_Cleanup_Scheduler {
    public function __construct() {
        // Schedule cron
        add_action('init', [ $this, 'schedule_daily_cleanup' ]);

        // Hook cleanup function
        add_action('woocommerce_auto_stock_cleanup_daily', [ $this, 'run_daily_cleanup' ]);
    }

    /**
     * Schedule the daily cleanup event if not already scheduled
     */
    public function schedule_daily_cleanup() {
        if ( ! wp_next_scheduled('woocommerce_auto_stock_cleanup_daily') ) {
            // Schedule once daily
            wp_schedule_event(time(), 'daily', 'woocommerce_auto_stock_cleanup_daily');
        }
    }

    /**
     * Run cleanup on both tables:
     * - out_of_stock_products_data (where images_deleted = 1)
     * - wholesaler_background_jobs (where status = 'completed')
     */
    public function run_daily_cleanup() {
        global $wpdb;

        $out_of_stock_table = $wpdb->prefix . 'out_of_stock_products_data';
        $jobs_table          = $wpdb->prefix . 'wholesaler_background_jobs';

        // 1️⃣ Delete from out_of_stock_products_data where images_deleted = 1
        $deleted_out_of_stock = $wpdb->query(
            "DELETE FROM {$out_of_stock_table} WHERE images_deleted = 1"
        );

        // 2️⃣ Delete from wholesaler_background_jobs where status = 'completed'
        $deleted_jobs = $wpdb->query(
            $wpdb->prepare("DELETE FROM {$jobs_table} WHERE status = %s", 'completed')
        );

        // 🪵 Optional logging
        if ( defined('WP_DEBUG') && WP_DEBUG ) {
            error_log("WooCommerce Auto Cleanup: Deleted {$deleted_out_of_stock} rows from {$out_of_stock_table} (images_deleted = 1)");
            error_log("WooCommerce Auto Cleanup: Deleted {$deleted_jobs} rows from {$jobs_table} (status = completed)");
        }
    }
}

new WooCommerce_Auto_Stock_Cleanup_Scheduler();
