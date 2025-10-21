<?php
/**
 * Stats Table Partial
 * 
 * @var array $stats
 * @var array $last_cleanup
 */

if ( !defined( 'ABSPATH' ) ) exit;
?>

<?php if ( !empty( $stats ) ) : ?>
    <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
        <thead>
            <tr>
                <th style="width: 40%;">Metric</th>
                <th style="width: 60%;">Value</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>Total Products Scanned</strong></td>
                <td><?php echo number_format( $stats['total_scanned'] ?? 0 ); ?></td>
            </tr>
            <tr>
                <td><strong>Non-Biustonosze Products Found</strong></td>
                <td><?php echo number_format( $stats['non_brazyliany_found'] ?? 0 ); ?> (stock = 1)</td>
            </tr>
            <tr>
                <td><strong>Biustonosze Products Found</strong></td>
                <td><?php echo number_format( $stats['brazyliany_found'] ?? 0 ); ?> (variations &lt; 5)</td>
            </tr>
            <tr>
                <td><strong>Total Products Deleted</strong></td>
                <td style="color: #d63638; font-weight: bold;">
                    <?php echo number_format( $stats['products_deleted'] ?? 0 ); ?>
                </td>
            </tr>
            <tr>
                <td><strong>Total Images Deleted</strong></td>
                <td style="color: #d63638; font-weight: bold;">
                    <?php echo number_format( $stats['images_deleted'] ?? 0 ); ?>
                </td>
            </tr>
            <tr>
                <td><strong>Total Variations Deleted</strong></td>
                <td><?php echo number_format( $stats['variations_deleted'] ?? 0 ); ?></td>
            </tr>
            <tr>
                <td><strong>Execution Time</strong></td>
                <td><?php echo esc_html( $stats['execution_time'] ?? 'N/A' ); ?></td>
            </tr>
            <tr>
                <td><strong>Batches Processed</strong></td>
                <td><?php echo number_format( $stats['batches_processed'] ?? 0 ); ?></td>
            </tr>
            <tr>
                <td><strong>Processing Status</strong></td>
                <td>
                    <?php
                    $status = $stats['status'] ?? 'completed';
                    $color  = $status === 'completed' ? '#00a32a' : '#d63638';
                    echo '<span style="color: ' . $color . '; font-weight: bold;">' . ucfirst( str_replace( '_', ' ', esc_html( $status ) ) ) . '</span>';
                    ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php if ( !empty( $last_cleanup['date'] ) ) : ?>
        <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin-top: 20px;">
            <strong>Last Cleanup Run:</strong> <?php echo esc_html( $last_cleanup['date'] ); ?>
        </div>
    <?php endif; ?>
<?php else : ?>
    <p style="color: #666; font-style: italic;">No cleanup has been run yet. Click "Run Cleanup Now" to start.</p>
<?php endif; ?>

