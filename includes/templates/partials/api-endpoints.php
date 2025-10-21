<?php
/**
 * API Endpoints Documentation Partial
 * 
 * @var string $site_url
 */

if ( !defined( 'ABSPATH' ) ) exit;
?>

<div style="margin-bottom: 20px;">
    <h3>NEW 1. Get Out of Stock Products (GET)</h3>
    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/get-out-of-stock-products
    </code>
    <p><strong>Method:</strong> GET</p>
    <p><strong>Authentication:</strong> None</p>
    <p><strong>Parameters:</strong></p>
    <ul>
        <li><code>cat</code> (optional) - Category slug filter (e.g., 'biustonosze')</li>
        <li><code>filter</code> (optional) - Use <code>filter=out-of-stock</code> to get all out-of-stock products from ALL categories</li>
        <li><code>limit</code> (optional, default 100, max 1000) - Number of products</li>
    </ul>
    <p><strong>Examples:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;"># Get 10 products with fewer than 5 variations from biustonosze category
curl "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/get-out-of-stock-products?limit=10"

# Get all out-of-stock products from ALL categories
curl "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/get-out-of-stock-products?filter=out-of-stock&limit=50"</pre>
</div>

<div style="margin-bottom: 20px;">
    <h3>NEW 2. Delete Products Fast (POST)</h3>
    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-products
    </code>
    <p><strong>Method:</strong> POST</p>
    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
    <p><strong>Parameters:</strong></p>
    <ul>
        <li><code>cat</code> (optional) - Category slug filter (e.g., 'biustonosze')</li>
        <li><code>filter</code> (optional) - Use <code>filter=out-of-stock</code> to delete all out-of-stock products from ALL categories</li>
        <li><code>limit</code> (optional, default 100, max 1000) - Products to delete</li>
    </ul>
    <p><strong>Performance:</strong> ~100-200 products/second ⚡</p>
    <p><strong>Examples:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;"># Delete products with fewer than 5 variations from biustonosze category
curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-products?limit=500" \
     -H "X-API-Key: YOUR_API_KEY"

# Delete all out-of-stock products from ALL categories
curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-products?filter=out-of-stock&limit=500" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
</div>

<div style="margin-bottom: 20px;">
    <h3>NEW 3. Delete Associated Images (POST)</h3>
    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-associate-images
    </code>
    <p><strong>Method:</strong> POST</p>
    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
    <p><strong>Parameters:</strong></p>
    <ul>
        <li><code>batch_size</code> (optional, default 50, max 500) - Images per batch</li>
    </ul>
    <p><strong>Description:</strong> Deletes images from previously deleted products</p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-associate-images" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
</div>

<div style="margin-bottom: 20px;">
    <h3>LEGACY 4. Old Cleanup Endpoint (POST)</h3>
    <code style="display: block; background: #f0f0f1; padding: 10px; border-radius: 4px;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cleanup
    </code>
    <p><strong>Method:</strong> POST</p>
    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
    <p><strong>Example cURL:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY"</pre>

    <p><strong>Example crontab entry (runs daily at 2 AM):</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">0 2 * * * curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1</pre>
</div>

<div>
    <h3>5. Stats Endpoint (GET)</h3>
    <code style="display: block; background: #f0f0f1; padding: 10px; border-radius: 4px;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/stats
    </code>
    <p><strong>Method:</strong> GET</p>
    <p><strong>Authentication:</strong> None required (public endpoint)</p>
    <p><strong>Example cURL:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/stats"</pre>
</div>

