<?php
/**
 * Deletion Helper
 * 
 * Handles product and image deletion operations
 */

if ( !defined( 'ABSPATH' ) ) exit;

class WC_Deletion_Helper {

    /**
     * Delete products fast using direct SQL
     */
    public static function delete_products_fast( $products ) {
        global $wpdb;

        $table_name         = $wpdb->prefix . 'out_of_stock_products_data';
        $product_ids        = [];
        $stored_count       = 0;
        $products_deleted   = 0;
        $variations_deleted = 0;

        foreach ( $products as $product ) {
            $product_id    = $product['product_id'];
            $product_ids[] = $product_id;

            // Store to tracking table
            $wpdb->insert(
                $table_name,
                [
                    'product_id'     => $product_id,
                    'product_title'  => $product['product_name'],
                    'category_slug'  => $product['category_slug'],
                    'attachment_ids' => $product['attachment_ids'],
                    'deleted_at'     => current_time( 'mysql' ),
                    'images_deleted' => 0,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d' ]
            );

            if ( $wpdb->insert_id ) {
                $stored_count++;
            }
        }

        if ( !empty( $product_ids ) ) {
            // Get variation IDs
            $variation_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}posts 
                WHERE post_parent IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")
                AND post_type = 'product_variation'",
                ...$product_ids
            ) );

            $variations_deleted = count( $variation_ids );

            // Delete variation meta
            if ( !empty( $variation_ids ) ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}postmeta 
                    WHERE post_id IN (" . implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) ) . ")",
                    ...$variation_ids
                ) );
            }

            // Delete variations
            if ( !empty( $variation_ids ) ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}posts 
                    WHERE ID IN (" . implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) ) . ")",
                    ...$variation_ids
                ) );
            }

            // Delete product meta
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}postmeta 
                WHERE post_id IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            // Delete product terms
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}term_relationships 
                WHERE object_id IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            // Delete products
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}posts 
                WHERE ID IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            $products_deleted = $result;
        }

        return [
            'products_deleted'   => $products_deleted,
            'variations_deleted' => $variations_deleted,
            'stored_count'       => $stored_count,
        ];
    }

    /**
     * Delete attachment fast (file + DB)
     */
    public static function delete_attachment_fast( $attachment_id ) {
        // Validate attachment ID
        if ( !$attachment_id || !is_numeric( $attachment_id ) ) {
            error_log( "WC_Deletion_Helper: Invalid attachment ID: " . var_export( $attachment_id, true ) );
            return false;
        }

        $attachment_id = (int) $attachment_id;
        
        // Check if post exists first
        $post_type = get_post_type( $attachment_id );
        
        if ( !$post_type ) {
            error_log( "WC_Deletion_Helper: Attachment $attachment_id does not exist in database" );
            return false;
        }
        
        if ( $post_type !== 'attachment' ) {
            error_log( "WC_Deletion_Helper: Post $attachment_id is not an attachment (type: $post_type)" );
            return false;
        }

        global $wpdb;

        // Get file path before deletion
        $file = get_attached_file( $attachment_id );
        $meta = wp_get_attachment_metadata( $attachment_id );
        
        error_log( "WC_Deletion_Helper: Attempting to delete attachment $attachment_id, file: " . ( $file ?: 'no file' ) );

        // Delete physical files
        $files_deleted = 0;
        if ( $file && file_exists( $file ) ) {
            if ( @unlink( $file ) ) {
                $files_deleted++;
                error_log( "WC_Deletion_Helper: Deleted main file: $file" );
            } else {
                error_log( "WC_Deletion_Helper: Failed to delete main file: $file" );
            }

            // Delete thumbnails
            if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
                $base_dir = dirname( $file );

                foreach ( $meta['sizes'] as $size_name => $size ) {
                    if ( isset( $size['file'] ) ) {
                        $thumb_file = $base_dir . '/' . $size['file'];
                        if ( file_exists( $thumb_file ) ) {
                            if ( @unlink( $thumb_file ) ) {
                                $files_deleted++;
                            }
                        }
                    }
                }
            }
        } else {
            error_log( "WC_Deletion_Helper: File not found for attachment $attachment_id" );
        }

        // Delete from database
        $meta_deleted = $wpdb->delete( $wpdb->prefix . 'postmeta', [ 'post_id' => $attachment_id ], [ '%d' ] );
        $post_deleted = $wpdb->delete( $wpdb->prefix . 'posts', [ 'ID' => $attachment_id ], [ '%d' ] );
        
        error_log( "WC_Deletion_Helper: Deleted attachment $attachment_id from DB - meta rows: $meta_deleted, post rows: $post_deleted, files: $files_deleted" );

        return ( $post_deleted > 0 );
    }

    /**
     * Delete products and their attachments (slower but comprehensive)
     */
    public static function delete_products_and_attachments( $products ) {
        global $wpdb;

        $stats = [
            'products_deleted'   => 0,
            'images_deleted'     => 0,
            'variations_deleted' => 0,
        ];

        foreach ( $products as $product ) {
            $product_id     = $product['product_id'];
            $attachment_ids = $product['attachment_ids'];

            // Count variations before deleting
            $variation_count = $wpdb->get_var( $wpdb->prepare( "
                SELECT COUNT(*)
                FROM {$wpdb->prefix}posts
                WHERE post_parent = %d
                AND post_type = 'product_variation'
            ", $product_id ) );

            if ( $variation_count ) {
                $stats['variations_deleted'] += (int) $variation_count;
            }

            // Parse and delete attachment IDs
            if ( !empty( $attachment_ids ) ) {
                $ids = array_filter( array_map( 'intval', explode( ',', $attachment_ids ) ) );

                // Delete each attachment
                foreach ( $ids as $attachment_id ) {
                    if ( $attachment_id && get_post_type( $attachment_id ) === 'attachment' ) {
                        $deleted = wp_delete_attachment( $attachment_id, true );
                        if ( $deleted ) {
                            $stats['images_deleted']++;
                        }
                    }
                }
            }

            // Delete the product (this will also delete variations)
            $deleted = wp_delete_post( $product_id, true );
            if ( $deleted ) {
                $stats['products_deleted']++;
            }
        }

        return $stats;
    }
}

