<?php
/**
 * Simple script to view table data
 * Access via: http://localhost/wholesaler/wp-content/plugins/delete-images-by-ids/view-table-data.php
 */

// Load WordPress
require_once( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) . '/wp-load.php' );

// Check if user is admin
if ( !current_user_can( 'manage_options' ) ) {
    wp_die( 'Unauthorized' );
}

global $wpdb;
$table_name = $wpdb->prefix . 'out_of_stock_products_data';

// Check if table exists
$table_exists = $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" );

?>
<!DOCTYPE html>
<html>
<head>
    <title>View Tracking Table Data</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        th {
            background: #0073aa;
            color: white;
        }
        tr:hover {
            background: #f9f9f9;
        }
        .empty {
            color: #999;
            font-style: italic;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        .badge-yes {
            background: #d4edda;
            color: #155724;
        }
        .badge-no {
            background: #f8d7da;
            color: #721c24;
        }
        .info {
            background: #e7f3ff;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #0073aa;
        }
    </style>
</head>
<body>
    <h1>📊 Tracking Table Data</h1>
    
    <?php if ( !$table_exists ) : ?>
        <div class="info" style="background: #ffebee; border-left-color: #d32f2f;">
            ❌ Table <code><?php echo esc_html( $table_name ); ?></code> does not exist!
        </div>
    <?php else : ?>
        <?php
        // Get all records
        $records = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY id DESC LIMIT 20", ARRAY_A );
        $total = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name" );
        $pending = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE images_deleted = 0" );
        $processed = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE images_deleted = 1" );
        ?>
        
        <div class="info">
            <strong>Total Records:</strong> <?php echo $total; ?> |
            <strong>Pending:</strong> <?php echo $pending; ?> |
            <strong>Processed:</strong> <?php echo $processed; ?>
        </div>
        
        <?php if ( empty( $records ) ) : ?>
            <div class="info">
                ℹ️ No records found in the table.
            </div>
        <?php else : ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Product ID</th>
                        <th>Product Title</th>
                        <th>Category</th>
                        <th>Attachment IDs</th>
                        <th>Deleted At</th>
                        <th>Images Deleted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $records as $record ) : ?>
                        <tr>
                            <td><?php echo $record['id']; ?></td>
                            <td><?php echo $record['product_id']; ?></td>
                            <td><?php echo esc_html( $record['product_title'] ); ?></td>
                            <td><?php echo esc_html( $record['category_slug'] ); ?></td>
                            <td>
                                <?php if ( !empty( $record['attachment_ids'] ) ) : ?>
                                    <?php
                                    $att_ids = explode( ',', $record['attachment_ids'] );
                                    echo '<strong>' . count( $att_ids ) . ' IDs:</strong> ';
                                    echo esc_html( $record['attachment_ids'] );
                                    
                                    // Check if they exist
                                    echo '<br><small>';
                                    foreach ( array_slice( $att_ids, 0, 3 ) as $att_id ) {
                                        $att_id = (int) $att_id;
                                        $exists = get_post_type( $att_id );
                                        if ( $exists ) {
                                            echo "✓ $att_id ($exists) ";
                                        } else {
                                            echo "✗ $att_id (not found) ";
                                        }
                                    }
                                    if ( count( $att_ids ) > 3 ) {
                                        echo '...';
                                    }
                                    echo '</small>';
                                    ?>
                                <?php else : ?>
                                    <span class="empty">No attachment IDs</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $record['deleted_at'] ); ?></td>
                            <td>
                                <?php if ( $record['images_deleted'] ) : ?>
                                    <span class="badge badge-yes">YES</span>
                                <?php else : ?>
                                    <span class="badge badge-no">NO</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    <?php endif; ?>
    
    <p><a href="<?php echo admin_url( 'tools.php?page=woocommerce-auto-stock-cleanup' ); ?>">← Back to Plugin Page</a></p>
</body>
</html>

