<?php
/**
 * Category Endpoints Documentation Partial
 * 
 * @var string $site_url
 */

if ( !defined( 'ABSPATH' ) ) exit;
?>

<div style="margin-bottom: 20px;">
    <h3>1. Get Products by Category (GET)</h3>
    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cat-products
    </code>
    <p><strong>Method:</strong> GET</p>
    <p><strong>Authentication:</strong> None</p>
    <p><strong>Parameters:</strong></p>
    <ul>
        <li><code>cat</code> (optional) - Category slug (e.g., 'biustonosze', 'brazyliany'). If not provided, fetches products from ALL categories</li>
        <li><code>limit</code> (optional, default 100, max 1000) - Number of products to fetch</li>
    </ul>
    <p><strong>Examples:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;"># Get 50 products from biustonosze category
curl "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cat-products?cat=biustonosze&limit=50"

# Get 100 products from ALL categories
curl "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/cat-products?limit=100"</pre>
</div>

<div style="margin-bottom: 20px;">
    <h3>2. Delete Products by Category (POST)</h3>
    <code style="display: block; background: #ffe6e6; padding: 10px; border-radius: 4px; border-left: 4px solid #d63638;">
        <?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-cat-products
    </code>
    <p><strong>Method:</strong> POST</p>
    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
    <p><strong>Parameters:</strong></p>
    <ul>
        <li><code>cat</code> (optional) - Category slug to delete products from. If not provided, deletes products from ALL categories</li>
        <li><code>limit</code> (optional, default 100, max 1000) - Number of products to delete</li>
    </ul>
    <p><strong>⚠️ Warning:</strong> This permanently deletes products. Images are queued for deletion via <code>/delete-associate-images</code></p>
    <p><strong>Examples:</strong></p>
    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;"># Delete 50 products from biustonosze category
curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-cat-products?cat=biustonosze&limit=50" \
     -H "X-API-Key: YOUR_API_KEY"

# Delete 100 products from ALL categories
curl -X POST "<?php echo esc_html( $site_url ); ?>/wp-json/delete-images/v1/delete-cat-products?limit=100" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
</div>

