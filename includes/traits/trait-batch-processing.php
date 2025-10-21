<?php
/**
 * Batch Processing Trait
 * 
 * Handles batch processing for cleanup operations
 */

if ( !defined( 'ABSPATH' ) ) exit;

trait WC_Batch_Processing_Trait {

    /**
     * Run daily cleanup - main cron job function with batch processing
     */
    public function run_daily_cleanup() {
        $start_time         = microtime( true );
        $batch_size         = 50; // Process 50 products at a time
        $max_execution_time = 300; // 5 minutes max

        // Set time limit and increase memory if possible
        @set_time_limit( $max_execution_time );
        @ini_set( 'memory_limit', '512M' );

        // Initialize stats
        $stats = [
            'total_scanned'        => 0,
            'non_brazyliany_found' => 0,
            'brazyliany_found'     => 0,
            'products_deleted'     => 0,
            'images_deleted'       => 0,
            'variations_deleted'   => 0,
            'execution_time'       => '',
            'timestamp'            => current_time( 'mysql' ),
            'batches_processed'    => 0,
            'status'               => 'completed',
        ];

        // Get total products count for scanning
        global $wpdb;
        $total_products         = $wpdb->get_var( "
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->prefix}posts AS p
            INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'product_cat'
        " );
        $stats['total_scanned'] = (int) $total_products;

        // Count products that match deletion criteria
        $stats['non_brazyliany_found'] = WC_Product_Query_Helper::count_non_brazyliany_products();
        $stats['brazyliany_found']     = WC_Product_Query_Helper::count_brazyliany_products();

        $total_to_delete = $stats['non_brazyliany_found'] + $stats['brazyliany_found'];

        // If too many products to delete in one go, process in batches
        if ( $total_to_delete > 0 ) {
            WC_Utility_Helper::log_message( "Starting batch deletion of $total_to_delete products..." );

            // Process non-brazyliany products in batches
            $this->process_non_brazyliany_batches( $batch_size, $max_execution_time, $start_time, $stats );

            // Check if we still have time for brazyliany products
            if ( ( microtime( true ) - $start_time ) < ( $max_execution_time - 30 ) ) {
                $this->process_brazyliany_batches( $batch_size, $max_execution_time, $start_time, $stats );
            } else {
                $stats['status'] = 'partial_timeout';
                WC_Utility_Helper::log_message( "Timeout reached, brazyliany products will be processed in next run" );
            }

            // Log the cleanup
            WC_Utility_Helper::log_cleanup( $stats, [] );
        } else {
            // Still save stats even if nothing was deleted
            update_option( 'delete_images_cleanup_stats', $stats );
        }

        // Calculate execution time
        $end_time                = microtime( true );
        $execution_time          = round( $end_time - $start_time, 2 );
        $stats['execution_time'] = $execution_time . ' seconds';

        // Update stats with execution time
        update_option( 'delete_images_cleanup_stats', $stats );

        WC_Utility_Helper::log_message( "Cleanup completed. Total time: {$stats['execution_time']}" );

        return $stats;
    }

    /**
     * Process non-brazyliany products in batches
     */
    private function process_non_brazyliany_batches( $batch_size, $max_execution_time, $start_time, &$stats ) {
        $offset      = 0;
        $batch_count = 0;

        while ( ( microtime( true ) - $start_time ) < ( $max_execution_time - 60 ) ) { // Leave 60s buffer
            $products = WC_Product_Query_Helper::get_non_brazyliany_products( $batch_size, $offset );

            if ( empty( $products ) ) {
                break; // No more products to process
            }

            $batch_count++;
            WC_Utility_Helper::log_message( "Processing non-brazyliany batch $batch_count (" . count( $products ) . " products)" );

            $deletion_stats              = WC_Deletion_Helper::delete_products_and_attachments( $products );
            $stats['products_deleted'] += $deletion_stats['products_deleted'];
            $stats['images_deleted'] += $deletion_stats['images_deleted'];
            $stats['variations_deleted'] += $deletion_stats['variations_deleted'];
            $stats['batches_processed']++;

            $offset += $batch_size;

            // Memory cleanup
            unset( $products );
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }

            // Small delay to prevent overwhelming the server
            usleep( 100000 ); // 0.1 second
        }

        WC_Utility_Helper::log_message( "Processed $batch_count non-brazyliany batches" );
    }

    /**
     * Process brazyliany products in batches
     */
    private function process_brazyliany_batches( $batch_size, $max_execution_time, $start_time, &$stats ) {
        $offset      = 0;
        $batch_count = 0;

        while ( ( microtime( true ) - $start_time ) < ( $max_execution_time - 30 ) ) { // Leave 30s buffer
            $products = WC_Product_Query_Helper::get_brazyliany_products( $batch_size, $offset );

            if ( empty( $products ) ) {
                break; // No more products to process
            }

            $batch_count++;
            WC_Utility_Helper::log_message( "Processing brazyliany batch $batch_count (" . count( $products ) . " products)" );

            $deletion_stats              = WC_Deletion_Helper::delete_products_and_attachments( $products );
            $stats['products_deleted'] += $deletion_stats['products_deleted'];
            $stats['images_deleted'] += $deletion_stats['images_deleted'];
            $stats['variations_deleted'] += $deletion_stats['variations_deleted'];
            $stats['batches_processed']++;

            $offset += $batch_size;

            // Memory cleanup
            unset( $products );
            if ( function_exists( 'gc_collect_cycles' ) ) {
                gc_collect_cycles();
            }

            // Small delay to prevent overwhelming the server
            usleep( 100000 ); // 0.1 second
        }

        WC_Utility_Helper::log_message( "Processed $batch_count brazyliany batches" );
    }
}

