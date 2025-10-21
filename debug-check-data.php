<?php
/**
 * Diagnostic script to check data in tracking table
 * 
 * Run via: wp eval-file debug-check-data.php --path=/srv/http/wholesaler
 */

global $wpdb;
$table_name = $wpdb->prefix . 'out_of_stock_products_data';

// Check if table exists
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );

if ( !$table_exists ) {
    echo "❌ Table $table_name does not exist!\n";
    exit;
}

echo "✅ Table $table_name exists\n\n";

// Get records with pending image deletion
$records = $wpdb->get_results( 
    "SELECT * FROM $table_name WHERE images_deleted = 0 LIMIT 5", 
    ARRAY_A 
);

if ( empty( $records ) ) {
    echo "ℹ️  No records with pending image deletion found.\n\n";
    
    // Check total records
    $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
    echo "Total records in table: $total\n";
    
    $processed = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE images_deleted = 1" );
    echo "Already processed: $processed\n";
    
    exit;
}

echo "📋 Found " . count( $records ) . " records with pending image deletion:\n\n";

foreach ( $records as $record ) {
    echo "=====================================\n";
    echo "Record ID: " . $record['id'] . "\n";
    echo "Product ID: " . $record['product_id'] . "\n";
    echo "Product Title: " . $record['product_title'] . "\n";
    echo "Category: " . $record['category_slug'] . "\n";
    echo "Attachment IDs: " . ( $record['attachment_ids'] ?: '(empty)' ) . "\n";
    echo "Deleted At: " . $record['deleted_at'] . "\n";
    echo "Images Deleted: " . ( $record['images_deleted'] ? 'Yes' : 'No' ) . "\n";
    
    // Check if attachment IDs are valid
    if ( !empty( $record['attachment_ids'] ) ) {
        $attachment_ids = array_filter( array_map( 'intval', explode( ',', $record['attachment_ids'] ) ) );
        echo "\nParsed Attachment IDs (" . count( $attachment_ids ) . "): " . implode( ', ', $attachment_ids ) . "\n";
        
        foreach ( $attachment_ids as $att_id ) {
            $post_type = get_post_type( $att_id );
            if ( $post_type ) {
                echo "  - ID $att_id: EXISTS (type: $post_type)\n";
                
                if ( $post_type === 'attachment' ) {
                    $file = get_attached_file( $att_id );
                    $exists = $file && file_exists( $file );
                    echo "    File: " . ( $file ?: 'no file' ) . ( $exists ? ' ✓ EXISTS' : ' ✗ MISSING' ) . "\n";
                }
            } else {
                echo "  - ID $att_id: ✗ POST NOT FOUND\n";
            }
        }
    } else {
        echo "\n⚠️  No attachment IDs stored for this record!\n";
    }
    
    echo "\n";
}

echo "=====================================\n";

