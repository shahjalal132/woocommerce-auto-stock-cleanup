<?php
/**
 * Admin Page Template
 * 
 * @var array $stats
 * @var array $last_cleanup
 * @var string $api_key
 * @var string $site_url
 */

if ( !defined( 'ABSPATH' ) ) exit;
?>

<div class="wrap">
    <h1>WooCommerce Auto Stock Cleanup</h1>

    <!-- WooCommerce Compatibility Status -->
    <div style="background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb; border-radius: 4px; margin: 15px 0;">
        <h4 style="margin: 0 0 10px 0; color: #0c5460;">✅ WooCommerce Compatibility Status</h4>
        <p style="margin: 0; color: #055160;">
            <strong>WooCommerce Version:</strong> <?php echo defined( 'WC_VERSION' ) ? WC_VERSION : 'Not detected'; ?> |
            <strong>HPOS Compatible:</strong> Yes |
            <strong>Blocks Compatible:</strong> Yes |
            <strong>Plugin Version:</strong> 2.2.0 (Fast Deletion)
        </p>
    </div>

    <!-- Manual Image Deletion Section -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin-bottom: 30px;">
        <h2>Manual Image Deletion</h2>
        <p>Enter comma-separated attachment IDs (e.g. <code>123,456,789</code>)</p>
        <textarea id="image-ids" rows="4" style="width: 100%;"></textarea>
        <br><br>
        <button id="delete-images-btn" class="button button-primary">Delete Images</button>

        <div id="progress-wrapper" style="display:none; margin-top:20px;">
            <div id="progress-bar"></div>
            <p id="progress-text">0%</p>
        </div>

        <div id="result" style="margin-top:20px;"></div>
    </div>

    <!-- REST API Endpoints Section -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin-bottom: 30px;">
        <h2>REST API Endpoints</h2>

        <div style="margin-bottom: 20px;">
            <h3>API Key Management</h3>
            <?php if ( empty( $api_key ) ) : ?>
                <p style="color: #d63638;">⚠️ No API key generated yet. Generate one to use the endpoints.</p>
            <?php else : ?>
                <p style="color: #00a32a;">✓ API Key is configured</p>
                <div style="background: #f0f0f1; padding: 10px; border-radius: 4px; font-family: monospace; word-break: break-all;">
                    <?php echo esc_html( $api_key ); ?>
                </div>
            <?php endif; ?>

            <form method="post" style="margin-top: 10px;">
                <?php wp_nonce_field( 'delete_images_api_key' ); ?>
                <button type="submit" name="generate_api_key" class="button button-secondary"
                    onclick="return confirm('This will generate a new API key. Update your cron job if you have one running.');">
                    <?php echo empty( $api_key ) ? 'Generate API Key' : 'Regenerate API Key'; ?>
                </button>
            </form>
        </div>

        <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px 0; color: #856404;">⚡ NEW: Fast Deletion API (v2.2.0)</h4>
            <p style="margin: 0; color: #856404;">
                <strong>20-40x faster!</strong> New endpoints separate product deletion (instant SQL) from image cleanup
                (batch processing).
                Use <code>/delete-products</code> for instant removal, then <code>/delete-associate-images</code> for
                image cleanup.
            </p>
        </div>

        <?php include __DIR__ . '/partials/api-endpoints.php'; ?>
    </div>

    <!-- Category-Based Deletion Section -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin-bottom: 30px;">
        <h2>Category-Based Product Deletion</h2>
        
        <div style="background: #d1f2eb; padding: 15px; border: 1px solid #1abc9c; border-radius: 4px; margin-bottom: 20px;">
            <h4 style="margin: 0 0 10px 0; color: #0e6655;">🎯 Delete Products by Category</h4>
            <p style="margin: 0; color: #0e6655;">
                <strong>Target specific categories</strong> for bulk deletion. Get product lists first, then delete them with image tracking.
            </p>
        </div>

        <?php include __DIR__ . '/partials/category-endpoints.php'; ?>
    </div>

    <!-- Cleanup Stats Section -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin-bottom: 30px;">
        <h2>Cleanup Statistics</h2>

        <div style="background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; border-radius: 4px; margin: 15px 0;">
            <h4 style="margin: 0 0 10px 0; color: #0073aa;">🚀 Performance Optimized Batch Processing</h4>
            <p style="margin: 0; color: #555;">
                The system now processes products in batches of <strong>50</strong> to handle large numbers efficiently.
                Maximum processing time is <strong>5 minutes</strong> per run with automatic timeout protection.
                For 2000+ products, multiple cron runs may be needed to complete deletion.
            </p>
        </div>

        <?php include __DIR__ . '/partials/stats-table.php'; ?>

        <div style="margin-top: 20px;">
            <button id="run-cleanup-now" class="button button-primary"
                onclick="if(confirm('Are you sure you want to run the cleanup now? This will delete products with no stock and their images.')) { window.location.href='<?php echo esc_url( admin_url( 'admin-post.php?action=run_cleanup_now' ) ); ?>'; }">
                Run Cleanup Now
            </button>
        </div>
    </div>

</div>

