<?php
/**
 * Plugin Name: WooCommerce Auto Stock Cleanup
 * Plugin URI:  https://github.com/shahjalal132/woocommerce-auto-stock-cleanup
 * Author:      Shah Jalal
 * Author URI:  https://github.com/shahjalal132
 * Description: Automatically cleanup WooCommerce products with low/no stock and their images via REST API endpoints with comprehensive statistics tracking and manual deletion tools.
 * Version:     2.0.0
 * Text Domain: wc-auto-stock-cleanup
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) exit;

class Delete_Images_By_IDs {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_delete_images_by_ids', [$this, 'ajax_delete_images']);
        
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Manual cleanup trigger from admin
        add_action('admin_post_run_cleanup_now', [$this, 'manual_cleanup']);
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Cleanup endpoint
        register_rest_route('delete-images/v1', '/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_run_cleanup'],
            'permission_callback' => [$this, 'rest_permission_check']
        ]);
        
        // Stats endpoint
        register_rest_route('delete-images/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_stats'],
            'permission_callback' => '__return_true' // Public endpoint
        ]);
    }
    
    /**
     * Permission check for REST API
     */
    public function rest_permission_check() {
        // Check for API key in header
        $api_key = get_option('delete_images_api_key', '');
        $provided_key = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
        
        return !empty($api_key) && $provided_key === $api_key;
    }
    
    /**
     * REST API cleanup endpoint
     */
    public function rest_run_cleanup($request) {
        $result = $this->run_daily_cleanup();
        
        return new WP_REST_Response([
            'success' => true,
            'data' => $result
        ], 200);
    }
    
    /**
     * REST API stats endpoint
     */
    public function rest_get_stats($request) {
        $stats = get_option('delete_images_cleanup_stats', []);
        $last_cleanup = get_option('delete_images_last_cleanup', []);
        
        return new WP_REST_Response([
            'success' => true,
            'stats' => $stats,
            'last_cleanup' => $last_cleanup
        ], 200);
    }

    public function register_menu() {
        add_management_page(
            'Delete Images by IDs',
            'Delete Images by IDs',
            'manage_options',
            'delete-images-by-ids',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'tools_page_delete-images-by-ids') return;

        wp_enqueue_script('jquery');
        wp_enqueue_script(
            'delete-images-script',
            plugin_dir_url(__FILE__) . 'assets/admin/js/delete-images.js',
            ['jquery'],
            false,
            true
        );
        wp_localize_script('delete-images-script', 'deleteImages', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('delete_images_nonce')
        ]);

        wp_enqueue_style('delete-images-style', plugin_dir_url(__FILE__) . 'assets/admin/css/delete-images.css');
    }

    public function render_page() {
        // Handle API key generation
        if (isset($_POST['generate_api_key']) && check_admin_referer('delete_images_api_key')) {
            $api_key = wp_generate_password(32, false);
            update_option('delete_images_api_key', $api_key);
            echo '<div class="notice notice-success is-dismissible"><p>API Key generated successfully!</p></div>';
        }
        
        // Check for cleanup success message
        if (isset($_GET['cleanup']) && $_GET['cleanup'] === 'success') {
            echo '<div class="notice notice-success is-dismissible"><p>Cleanup completed successfully!</p></div>';
        }
        
        // Get stats and cleanup info
        $stats = get_option('delete_images_cleanup_stats', []);
        $last_cleanup = get_option('delete_images_last_cleanup', []);
        $api_key = get_option('delete_images_api_key', '');
        $site_url = get_site_url();
        ?>
        <div class="wrap">
            <h1>Delete Images by IDs</h1>
            
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
                    <?php if (empty($api_key)): ?>
                        <p style="color: #d63638;">⚠️ No API key generated yet. Generate one to use the endpoints.</p>
                    <?php else: ?>
                        <p style="color: #00a32a;">✓ API Key is configured</p>
                        <div style="background: #f0f0f1; padding: 10px; border-radius: 4px; font-family: monospace; word-break: break-all;">
                            <?php echo esc_html($api_key); ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="post" style="margin-top: 10px;">
                        <?php wp_nonce_field('delete_images_api_key'); ?>
                        <button type="submit" name="generate_api_key" class="button button-secondary" 
                                onclick="return confirm('This will generate a new API key. Update your cron job if you have one running.');">
                            <?php echo empty($api_key) ? 'Generate API Key' : 'Regenerate API Key'; ?>
                        </button>
                    </form>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>1. Cleanup Endpoint (POST)</h3>
                    <code style="display: block; background: #f0f0f1; padding: 10px; border-radius: 4px;">
                        <?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/cleanup
                    </code>
                    <p><strong>Method:</strong> POST</p>
                    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
                    <p><strong>Example cURL:</strong></p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl -X POST "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/cleanup" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
                    
                    <p><strong>Example crontab entry (runs daily at 2 AM):</strong></p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">0 2 * * * curl -X POST "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/cleanup" -H "X-API-Key: YOUR_API_KEY" >> /var/log/product-cleanup.log 2>&1</pre>
                </div>
                
                <div>
                    <h3>2. Stats Endpoint (GET)</h3>
                    <code style="display: block; background: #f0f0f1; padding: 10px; border-radius: 4px;">
                        <?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/stats
                    </code>
                    <p><strong>Method:</strong> GET</p>
                    <p><strong>Authentication:</strong> None required (public endpoint)</p>
                    <p><strong>Example cURL:</strong></p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/stats"</pre>
                </div>
            </div>
            
            <!-- Cleanup Stats Section -->
            <div style="background: #fff; padding: 20px; border: 1px solid #ccc; margin-bottom: 30px;">
                <h2>Cleanup Statistics</h2>
                
                <?php if (!empty($stats)): ?>
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
                                <td><?php echo number_format($stats['total_scanned'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Non-Brazyliany Products Found</strong></td>
                                <td><?php echo number_format($stats['non_brazyliany_found'] ?? 0); ?> (out of stock)</td>
                            </tr>
                            <tr>
                                <td><strong>Brazyliany Products Found</strong></td>
                                <td><?php echo number_format($stats['brazyliany_found'] ?? 0); ?> (stock &lt; 5)</td>
                            </tr>
                            <tr>
                                <td><strong>Total Products Deleted</strong></td>
                                <td style="color: #d63638; font-weight: bold;"><?php echo number_format($stats['products_deleted'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Images Deleted</strong></td>
                                <td style="color: #d63638; font-weight: bold;"><?php echo number_format($stats['images_deleted'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Total Variations Deleted</strong></td>
                                <td><?php echo number_format($stats['variations_deleted'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Execution Time</strong></td>
                                <td><?php echo esc_html($stats['execution_time'] ?? 'N/A'); ?></td>
                            </tr>
                        </tbody>
                    </table>
                    
                    <?php if (!empty($last_cleanup['date'])): ?>
                        <div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #2271b1; margin-top: 20px;">
                            <strong>Last Cleanup Run:</strong> <?php echo esc_html($last_cleanup['date']); ?>
                        </div>
                    <?php endif; ?>
                <?php else: ?>
                    <p style="color: #666; font-style: italic;">No cleanup has been run yet. Click "Run Cleanup Now" to start.</p>
                <?php endif; ?>
                
                <div style="margin-top: 20px;">
                    <button id="run-cleanup-now" class="button button-primary" 
                            onclick="if(confirm('Are you sure you want to run the cleanup now? This will delete products with no stock and their images.')) { window.location.href='<?php echo esc_url(admin_url('admin-post.php?action=run_cleanup_now')); ?>'; }">
                        Run Cleanup Now
                    </button>
                </div>
            </div>
            
        </div>
        <?php
    }
    
    /**
     * Run daily cleanup - main cron job function
     */
    public function run_daily_cleanup() {
        $start_time = microtime(true);
        
        // Initialize stats
        $stats = [
            'total_scanned' => 0,
            'non_brazyliany_found' => 0,
            'brazyliany_found' => 0,
            'products_deleted' => 0,
            'images_deleted' => 0,
            'variations_deleted' => 0,
            'execution_time' => '',
            'timestamp' => current_time('mysql')
        ];
        
        // Get total products count for scanning
        global $wpdb;
        $total_products = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->prefix}posts AS p
            INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND tt.taxonomy = 'product_cat'
        ");
        $stats['total_scanned'] = (int)$total_products;
        
        // Get products from both categories
        $non_brazyliany_products = $this->get_non_brazyliany_products();
        $brazyliany_products = $this->get_brazyliany_products();
        
        $stats['non_brazyliany_found'] = count($non_brazyliany_products);
        $stats['brazyliany_found'] = count($brazyliany_products);
        
        // Merge results
        $all_products = array_merge($non_brazyliany_products, $brazyliany_products);
        
        // Delete products and their attachments
        if (!empty($all_products)) {
            $deletion_stats = $this->delete_products_and_attachments($all_products);
            $stats['products_deleted'] = $deletion_stats['products_deleted'];
            $stats['images_deleted'] = $deletion_stats['images_deleted'];
            $stats['variations_deleted'] = $deletion_stats['variations_deleted'];
            
            // Log the cleanup
            $this->log_cleanup($stats, $all_products);
        } else {
            // Still save stats even if nothing was deleted
            update_option('delete_images_cleanup_stats', $stats);
        }
        
        // Calculate execution time
        $end_time = microtime(true);
        $execution_time = round($end_time - $start_time, 2);
        $stats['execution_time'] = $execution_time . ' seconds';
        
        // Update stats with execution time
        update_option('delete_images_cleanup_stats', $stats);
        
        return $stats;
    }
    
    /**
     * Get non-brazyliany products where all variations are out of stock
     */
    private function get_non_brazyliany_products() {
        global $wpdb;
        
        $query = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                t.slug AS category_slug,
                CONCAT_WS(
                    ',',
                    (SELECT pm1.meta_value 
                     FROM {$wpdb->prefix}postmeta AS pm1 
                     WHERE pm1.post_id = p.ID AND pm1.meta_key = '_thumbnail_id' LIMIT 1),
                    (SELECT pm2.meta_value 
                     FROM {$wpdb->prefix}postmeta AS pm2 
                     WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery' LIMIT 1)
                ) AS attachment_ids
            FROM 
                {$wpdb->prefix}posts AS p
                INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                AND t.slug != 'brazyliany'
                AND p.ID IN (
                    SELECT parent.ID
                    FROM {$wpdb->prefix}posts AS parent
                    JOIN {$wpdb->prefix}posts AS v ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                    LEFT JOIN {$wpdb->prefix}postmeta AS stock_status 
                        ON stock_status.post_id = v.ID AND stock_status.meta_key = '_stock_status'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING SUM(CASE WHEN stock_status.meta_value = 'instock' THEN 1 ELSE 0 END) = 0
                )
            GROUP BY p.ID
        ";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Get brazyliany products where all variations have stock < 5
     */
    private function get_brazyliany_products() {
        global $wpdb;
        
        $query = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                t.slug AS category_slug,
                CONCAT_WS(
                    ',',
                    (SELECT pm1.meta_value 
                     FROM {$wpdb->prefix}postmeta AS pm1 
                     WHERE pm1.post_id = p.ID AND pm1.meta_key = '_thumbnail_id' LIMIT 1),
                    (SELECT pm2.meta_value 
                     FROM {$wpdb->prefix}postmeta AS pm2 
                     WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery' LIMIT 1)
                ) AS attachment_ids
            FROM 
                {$wpdb->prefix}posts AS p
                INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                AND t.slug = 'brazyliany'
                AND p.ID IN (
                    SELECT parent.ID
                    FROM {$wpdb->prefix}posts AS parent
                    JOIN {$wpdb->prefix}posts AS v ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                    LEFT JOIN {$wpdb->prefix}postmeta AS stock_qty 
                        ON stock_qty.post_id = v.ID AND stock_qty.meta_key = '_stock'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING SUM(CASE WHEN CAST(stock_qty.meta_value AS UNSIGNED) >= 5 THEN 1 ELSE 0 END) = 0
                )
            GROUP BY p.ID
        ";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Delete products and their attachments
     */
    private function delete_products_and_attachments($products) {
        global $wpdb;
        
        $stats = [
            'products_deleted' => 0,
            'images_deleted' => 0,
            'variations_deleted' => 0
        ];
        
        foreach ($products as $product) {
            $product_id = $product['product_id'];
            $attachment_ids = $product['attachment_ids'];
            
            // Count variations before deleting
            $variation_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM {$wpdb->prefix}posts
                WHERE post_parent = %d
                AND post_type = 'product_variation'
            ", $product_id));
            
            if ($variation_count) {
                $stats['variations_deleted'] += (int)$variation_count;
            }
            
            // Parse and delete attachment IDs
            if (!empty($attachment_ids)) {
                $ids = array_filter(array_map('intval', explode(',', $attachment_ids)));
                
                // Delete each attachment
                foreach ($ids as $attachment_id) {
                    if ($attachment_id && get_post_type($attachment_id) === 'attachment') {
                        $deleted = wp_delete_attachment($attachment_id, true);
                        if ($deleted) {
                            $stats['images_deleted']++;
                        }
                    }
                }
            }
            
            // Delete the product (this will also delete variations)
            $deleted = wp_delete_post($product_id, true);
            if ($deleted) {
                $stats['products_deleted']++;
            }
        }
        
        return $stats;
    }
    
    /**
     * Log cleanup activity
     */
    private function log_cleanup($stats, $products) {
        $log_entry = sprintf(
            "[%s] Cleanup Stats:\n" .
            "  - Products Scanned: %d\n" .
            "  - Non-Brazyliany Found: %d\n" .
            "  - Brazyliany Found: %d\n" .
            "  - Products Deleted: %d\n" .
            "  - Images Deleted: %d\n" .
            "  - Variations Deleted: %d\n" .
            "  - Execution Time: %s\n\n",
            current_time('Y-m-d H:i:s'),
            $stats['total_scanned'],
            $stats['non_brazyliany_found'],
            $stats['brazyliany_found'],
            $stats['products_deleted'],
            $stats['images_deleted'],
            $stats['variations_deleted'],
            $stats['execution_time']
        );
        
        $log_file = WP_CONTENT_DIR . '/delete-images-cleanup.log';
        error_log($log_entry, 3, $log_file);
        
        // Store in database for admin viewing
        update_option('delete_images_last_cleanup', [
            'date' => current_time('mysql'),
            'count' => $stats['products_deleted'],
            'product_ids' => array_column($products, 'product_id'),
            'stats' => $stats
        ]);
    }
    
    /**
     * Manual cleanup trigger
     */
    public function manual_cleanup() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        // Run the cleanup and get stats
        $stats = $this->run_daily_cleanup();
        
        // Redirect back with success message
        wp_redirect(add_query_arg([
            'page' => 'delete-images-by-ids',
            'cleanup' => 'success'
        ], admin_url('tools.php')));
        exit;
    }

    public function ajax_delete_images() {
        check_ajax_referer('delete_images_nonce', 'nonce');

        $ids = isset($_POST['ids']) ? array_map('intval', explode(',', $_POST['ids'])) : [];

        $deleted = [];
        $failed = [];

        foreach ($ids as $id) {
            if (get_post_type($id) === 'attachment') {
                $result = wp_delete_attachment($id, true);
                if ($result) {
                    $deleted[] = $id;
                } else {
                    $failed[] = $id;
                }
            } else {
                $failed[] = $id;
            }
        }

        wp_send_json([
            'success' => true,
            'deleted' => $deleted,
            'failed'  => $failed
        ]);
    }
}

new Delete_Images_By_IDs();
