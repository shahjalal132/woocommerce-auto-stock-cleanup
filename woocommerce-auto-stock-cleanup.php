<?php
/**
 * Plugin Name: WooCommerce Auto Stock Cleanup
 * Plugin URI:  https://github.com/shahjalal132/woocommerce-auto-stock-cleanup
 * Author:      Shah Jalal
 * Author URI:  https://github.com/shahjalal132
 * Description: Automatically cleanup WooCommerce products with low/no stock and their images via REST API endpoints with comprehensive statistics tracking and manual deletion tools.
 * Version:     2.2.0
 * Text Domain: wc-auto-stock-cleanup
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 * Requires Plugins: woocommerce
 * Network: false
 * License: GPL v2 or later
 */

if (!defined('ABSPATH')) exit;

// Check if WooCommerce is active (supports multisite)
function wc_auto_stock_cleanup_is_woocommerce_active() {
    $active_plugins = (array) get_option('active_plugins', array());
    if (is_multisite()) {
        $active_plugins = array_merge($active_plugins, get_site_option('active_sitewide_plugins', array()));
    }
    return in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
}

if (!wc_auto_stock_cleanup_is_woocommerce_active()) {
    add_action('admin_notices', function() {
        echo '<div class="notice notice-error"><p><strong>WooCommerce Auto Stock Cleanup</strong> requires WooCommerce to be installed and active.</p></div>';
    });
    return;
}

class WooCommerce_Auto_Stock_Cleanup {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_delete_images_by_ids', [$this, 'ajax_delete_images']);
        
        // REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Manual cleanup trigger from admin
        add_action('admin_post_run_cleanup_now', [$this, 'manual_cleanup']);
        
        // WooCommerce compatibility
        add_action('before_woocommerce_init', [$this, 'declare_compatibility']);
        add_action('init', [$this, 'init_plugin']);
    }
    
    /**
     * Declare WooCommerce feature compatibility
     */
    public function declare_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            // Declare HPOS compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            
            // Declare other WooCommerce features compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
            
            // Additional compatibility declarations
            if (method_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility')) {
                // Modern WooCommerce versions
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('product_block_editor', __FILE__, true);
            }
        }
    }
    
    /**
     * Initialize plugin after WordPress is fully loaded
     */
    public function init_plugin() {
        // Check WooCommerce version compatibility
        if (defined('WC_VERSION') && version_compare(WC_VERSION, '3.0', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>WooCommerce Auto Stock Cleanup</strong> requires WooCommerce 3.0 or higher. Please update WooCommerce.</p></div>';
            });
        }
    }
    
    /**
     * Plugin activation hook
     */
    public static function activate() {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die('WooCommerce Auto Stock Cleanup requires WooCommerce to be installed and active.');
        }
        
        // Create API key if not exists
        if (!get_option('delete_images_api_key')) {
            update_option('delete_images_api_key', wp_generate_password(32, false));
        }
        
        // Create custom table for tracking deleted products
        self::create_deleted_products_table();
    }
    
    /**
     * Create custom table for deleted products tracking
     */
    private static function create_deleted_products_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'out_of_stock_products_data';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            product_title varchar(255) NOT NULL,
            category_slug varchar(100) NOT NULL,
            attachment_ids text,
            deleted_at datetime NOT NULL,
            images_deleted tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY images_deleted (images_deleted),
            KEY deleted_at (deleted_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        // Clean up any scheduled events or temporary data if needed
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // OLD: Legacy cleanup endpoint (slower)
        register_rest_route('delete-images/v1', '/cleanup', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_run_cleanup'],
            'permission_callback' => [$this, 'rest_permission_check']
        ]);
        
        // NEW: Get out of stock products (fast query only)
        register_rest_route('delete-images/v1', '/get-out-of-stock-products', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_out_of_stock_products'],
            'permission_callback' => '__return_true',
            'args' => [
                'cat' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_string($param);
                    }
                ],
                'limit' => [
                    'required' => false,
                    'default' => 100,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 1000;
                    }
                ]
            ]
        ]);
        
        // NEW: Delete products instantly (SQL only, no images)
        register_rest_route('delete-images/v1', '/delete-products', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_delete_products_fast'],
            'permission_callback' => [$this, 'rest_permission_check'],
            'args' => [
                'cat' => [
                    'required' => false,
                    'validate_callback' => function($param) {
                        return is_string($param);
                    }
                ],
                'limit' => [
                    'required' => false,
                    'default' => 100,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 1000;
                    }
                ]
            ]
        ]);
        
        // NEW: Delete associated images from deleted products
        register_rest_route('delete-images/v1', '/delete-associate-images', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_delete_associate_images'],
            'permission_callback' => [$this, 'rest_permission_check'],
            'args' => [
                'batch_size' => [
                    'required' => false,
                    'default' => 50,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && $param > 0 && $param <= 500;
                    }
                ]
            ]
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
        // Get stored API key
        $api_key = get_option('delete_images_api_key', '');
        
        if (empty($api_key)) {
            return false;
        }
        
        // Try multiple methods to get the API key from request
        $provided_key = '';
        
        // Method 1: Check $_SERVER with different header formats
        if (isset($_SERVER['HTTP_X_API_KEY'])) {
            $provided_key = $_SERVER['HTTP_X_API_KEY'];
        }
        
        // Method 2: Use getallheaders() if available
        if (empty($provided_key) && function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['X-API-Key'])) {
                $provided_key = $headers['X-API-Key'];
            } elseif (isset($headers['X-Api-Key'])) {
                $provided_key = $headers['X-Api-Key'];
            } elseif (isset($headers['x-api-key'])) {
                $provided_key = $headers['x-api-key'];
            }
        }
        
        // Method 3: Check Apache request headers
        if (empty($provided_key) && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            if (isset($headers['X-API-Key'])) {
                $provided_key = $headers['X-API-Key'];
            } elseif (isset($headers['X-Api-Key'])) {
                $provided_key = $headers['X-Api-Key'];
            } elseif (isset($headers['x-api-key'])) {
                $provided_key = $headers['x-api-key'];
            }
        }
        
        // Trim any whitespace
        $provided_key = trim($provided_key);
        $api_key = trim($api_key);
        
        return !empty($provided_key) && $provided_key === $api_key;
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
     * NEW API: Get out of stock products (query only, no deletion)
     */
    public function rest_get_out_of_stock_products($request) {
        $cat = $request->get_param('cat');
        $limit = $request->get_param('limit') ?: 100;
        
        $products = $this->get_single_quantity_products($cat, $limit);
        
        return new WP_REST_Response([
            'success' => true,
            'count' => count($products),
            'products' => $products,
            'category' => $cat ?: 'all',
            'limit' => $limit
        ], 200);
    }
    
    /**
     * NEW API: Delete products fast (SQL only, store to table for image cleanup)
     */
    public function rest_delete_products_fast($request) {
        $start_time = microtime(true);
        $cat = $request->get_param('cat');
        $limit = $request->get_param('limit') ?: 100;
        
        // Get products to delete
        $products = $this->get_single_quantity_products($cat, $limit);
        
        if (empty($products)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'No products found to delete',
                'deleted_count' => 0
            ], 200);
        }
        
        // Store to custom table and delete products
        $result = $this->delete_products_fast($products);
        
        $execution_time = round(microtime(true) - $start_time, 2);
        
        return new WP_REST_Response([
            'success' => true,
            'products_deleted' => $result['products_deleted'],
            'variations_deleted' => $result['variations_deleted'],
            'stored_for_image_cleanup' => $result['stored_count'],
            'execution_time' => $execution_time . ' seconds',
            'category' => $cat ?: 'all'
        ], 200);
    }
    
    /**
     * NEW API: Delete associated images
     */
    public function rest_delete_associate_images($request) {
        $start_time = microtime(true);
        $batch_size = $request->get_param('batch_size') ?: 50;
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'out_of_stock_products_data';
        
        // Get products with pending image deletion
        $records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE images_deleted = 0 LIMIT %d",
            $batch_size
        ), ARRAY_A);
        
        if (empty($records)) {
            return new WP_REST_Response([
                'success' => true,
                'message' => 'No images pending deletion',
                'images_deleted' => 0
            ], 200);
        }
        
        $images_deleted = 0;
        $record_ids_processed = [];
        
        foreach ($records as $record) {
            if (!empty($record['attachment_ids'])) {
                $attachment_ids = array_filter(array_map('intval', explode(',', $record['attachment_ids'])));
                
                foreach ($attachment_ids as $attachment_id) {
                    if ($this->delete_attachment_fast($attachment_id)) {
                        $images_deleted++;
                    }
                }
            }
            
            $record_ids_processed[] = $record['id'];
        }
        
        // Mark records as processed
        if (!empty($record_ids_processed)) {
            $ids_placeholder = implode(',', array_fill(0, count($record_ids_processed), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE $table_name SET images_deleted = 1 WHERE id IN ($ids_placeholder)",
                ...$record_ids_processed
            ));
        }
        
        $execution_time = round(microtime(true) - $start_time, 2);
        
        // Check if more images pending
        $remaining = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE images_deleted = 0");
        
        return new WP_REST_Response([
            'success' => true,
            'images_deleted' => $images_deleted,
            'records_processed' => count($records),
            'remaining_records' => (int)$remaining,
            'execution_time' => $execution_time . ' seconds'
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
            <h1>WooCommerce Auto Stock Cleanup</h1>
            
            <!-- WooCommerce Compatibility Status -->
            <div style="background: #d1ecf1; padding: 15px; border: 1px solid #bee5eb; border-radius: 4px; margin: 15px 0;">
                <h4 style="margin: 0 0 10px 0; color: #0c5460;">✅ WooCommerce Compatibility Status</h4>
                <p style="margin: 0; color: #055160;">
                    <strong>WooCommerce Version:</strong> <?php echo defined('WC_VERSION') ? WC_VERSION : 'Not detected'; ?> |
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
                
                <div style="background: #fff3cd; padding: 15px; border: 1px solid #ffc107; border-radius: 4px; margin-bottom: 20px;">
                    <h4 style="margin: 0 0 10px 0; color: #856404;">⚡ NEW: Fast Deletion API (v2.2.0)</h4>
                    <p style="margin: 0; color: #856404;">
                        <strong>20-40x faster!</strong> New endpoints separate product deletion (instant SQL) from image cleanup (batch processing).
                        Use <code>/delete-products</code> for instant removal, then <code>/delete-associate-images</code> for image cleanup.
                    </p>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>NEW 1. Get Out of Stock Products (GET)</h3>
                    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
                        <?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/get-out-of-stock-products
                    </code>
                    <p><strong>Method:</strong> GET</p>
                    <p><strong>Authentication:</strong> None</p>
                    <p><strong>Parameters:</strong></p>
                    <ul>
                        <li><code>cat</code> (optional) - Category slug filter</li>
                        <li><code>limit</code> (optional, default 100, max 1000) - Number of products</li>
                    </ul>
                    <p><strong>Example:</strong></p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/get-out-of-stock-products?limit=10"</pre>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>NEW 2. Delete Products Fast (POST)</h3>
                    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
                        <?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/delete-products
                    </code>
                    <p><strong>Method:</strong> POST</p>
                    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
                    <p><strong>Parameters:</strong></p>
                    <ul>
                        <li><code>cat</code> (optional) - Category slug filter</li>
                        <li><code>limit</code> (optional, default 100, max 1000) - Products to delete</li>
                    </ul>
                    <p><strong>Performance:</strong> ~100-200 products/second ⚡</p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl -X POST "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/delete-products?limit=500" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>NEW 3. Delete Associated Images (POST)</h3>
                    <code style="display: block; background: #e7f3ff; padding: 10px; border-radius: 4px; border-left: 4px solid #0073aa;">
                        <?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/delete-associate-images
                    </code>
                    <p><strong>Method:</strong> POST</p>
                    <p><strong>Header:</strong> <code>X-API-Key: YOUR_API_KEY</code></p>
                    <p><strong>Parameters:</strong></p>
                    <ul>
                        <li><code>batch_size</code> (optional, default 50, max 500) - Images per batch</li>
                    </ul>
                    <p><strong>Description:</strong> Deletes images from previously deleted products</p>
                    <pre style="background: #282c34; color: #abb2bf; padding: 15px; border-radius: 4px; overflow-x: auto;">curl -X POST "<?php echo esc_html($site_url); ?>/wp-json/delete-images/v1/delete-associate-images" \
     -H "X-API-Key: YOUR_API_KEY"</pre>
                </div>
                
                <div style="margin-bottom: 20px;">
                    <h3>LEGACY 4. Old Cleanup Endpoint (POST)</h3>
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
                
                <div style="background: #e7f3ff; padding: 15px; border: 1px solid #b3d9ff; border-radius: 4px; margin: 15px 0;">
                    <h4 style="margin: 0 0 10px 0; color: #0073aa;">🚀 Performance Optimized Batch Processing</h4>
                    <p style="margin: 0; color: #555;">
                        The system now processes products in batches of <strong>50</strong> to handle large numbers efficiently. 
                        Maximum processing time is <strong>5 minutes</strong> per run with automatic timeout protection.
                        For 2000+ products, multiple cron runs may be needed to complete deletion.
                    </p>
                </div>
                
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
                            <tr>
                                <td><strong>Batches Processed</strong></td>
                                <td><?php echo number_format($stats['batches_processed'] ?? 0); ?></td>
                            </tr>
                            <tr>
                                <td><strong>Processing Status</strong></td>
                                <td>
                                    <?php 
                                    $status = $stats['status'] ?? 'completed';
                                    $color = $status === 'completed' ? '#00a32a' : '#d63638';
                                    echo '<span style="color: ' . $color . '; font-weight: bold;">' . ucfirst(str_replace('_', ' ', esc_html($status))) . '</span>';
                                    ?>
                                </td>
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
     * Run daily cleanup - main cron job function with batch processing
     */
    public function run_daily_cleanup() {
        $start_time = microtime(true);
        $batch_size = 50; // Process 50 products at a time
        $max_execution_time = 300; // 5 minutes max
        
        // Set time limit and increase memory if possible
        @set_time_limit($max_execution_time);
        @ini_set('memory_limit', '512M');
        
        // Initialize stats
        $stats = [
            'total_scanned' => 0,
            'non_brazyliany_found' => 0,
            'brazyliany_found' => 0,
            'products_deleted' => 0,
            'images_deleted' => 0,
            'variations_deleted' => 0,
            'execution_time' => '',
            'timestamp' => current_time('mysql'),
            'batches_processed' => 0,
            'status' => 'completed'
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
        
        // Count products that match deletion criteria
        $stats['non_brazyliany_found'] = $this->count_non_brazyliany_products();
        $stats['brazyliany_found'] = $this->count_brazyliany_products();
        
        $total_to_delete = $stats['non_brazyliany_found'] + $stats['brazyliany_found'];
        
        // If too many products to delete in one go, process in batches
        if ($total_to_delete > 0) {
            $this->log_message("Starting batch deletion of $total_to_delete products...");
            
            // Process non-brazyliany products in batches
            $this->process_non_brazyliany_batches($batch_size, $max_execution_time, $start_time, $stats);
            
            // Check if we still have time for brazyliany products
            if ((microtime(true) - $start_time) < ($max_execution_time - 30)) {
                $this->process_brazyliany_batches($batch_size, $max_execution_time, $start_time, $stats);
            } else {
                $stats['status'] = 'partial_timeout';
                $this->log_message("Timeout reached, brazyliany products will be processed in next run");
            }
            
            // Log the cleanup
            $this->log_cleanup($stats, []);
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
        
        $this->log_message("Cleanup completed. Total time: {$stats['execution_time']}");
        
        return $stats;
    }
    
    /**
     * Process non-brazyliany products in batches
     */
    private function process_non_brazyliany_batches($batch_size, $max_execution_time, $start_time, &$stats) {
        $offset = 0;
        $batch_count = 0;
        
        while ((microtime(true) - $start_time) < ($max_execution_time - 60)) { // Leave 60s buffer
            $products = $this->get_non_brazyliany_products($batch_size, $offset);
            
            if (empty($products)) {
                break; // No more products to process
            }
            
            $batch_count++;
            $this->log_message("Processing non-brazyliany batch $batch_count (" . count($products) . " products)");
            
            $deletion_stats = $this->delete_products_and_attachments($products);
            $stats['products_deleted'] += $deletion_stats['products_deleted'];
            $stats['images_deleted'] += $deletion_stats['images_deleted'];
            $stats['variations_deleted'] += $deletion_stats['variations_deleted'];
            $stats['batches_processed']++;
            
            $offset += $batch_size;
            
            // Memory cleanup
            unset($products);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Small delay to prevent overwhelming the server
            usleep(100000); // 0.1 second
        }
        
        $this->log_message("Processed $batch_count non-brazyliany batches");
    }
    
    /**
     * Process brazyliany products in batches
     */
    private function process_brazyliany_batches($batch_size, $max_execution_time, $start_time, &$stats) {
        $offset = 0;
        $batch_count = 0;
        
        while ((microtime(true) - $start_time) < ($max_execution_time - 30)) { // Leave 30s buffer
            $products = $this->get_brazyliany_products($batch_size, $offset);
            
            if (empty($products)) {
                break; // No more products to process
            }
            
            $batch_count++;
            $this->log_message("Processing brazyliany batch $batch_count (" . count($products) . " products)");
            
            $deletion_stats = $this->delete_products_and_attachments($products);
            $stats['products_deleted'] += $deletion_stats['products_deleted'];
            $stats['images_deleted'] += $deletion_stats['images_deleted'];
            $stats['variations_deleted'] += $deletion_stats['variations_deleted'];
            $stats['batches_processed']++;
            
            $offset += $batch_size;
            
            // Memory cleanup
            unset($products);
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
            
            // Small delay to prevent overwhelming the server
            usleep(100000); // 0.1 second
        }
        
        $this->log_message("Processed $batch_count brazyliany batches");
    }
    
    /**
     * Log messages to file and error log
     */
    private function log_message($message) {
        $log_entry = "[" . current_time('Y-m-d H:i:s') . "] $message\n";
        $log_file = WP_CONTENT_DIR . '/delete-images-cleanup.log';
        error_log($log_entry, 3, $log_file);
    }
    
    /**
     * =================
     * FAST DELETION METHODS
     * =================
     */
    
    /**
     * Get single quantity products (optimized query)
     */
    private function get_single_quantity_products($category = null, $limit = 100) {
        global $wpdb;
        
        $category_filter = '';
        if ($category) {
            $category_filter = $wpdb->prepare("AND t.slug = %s", $category);
        }
        
        $query = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                t.slug AS category_slug,
                TRIM(BOTH ',' FROM REPLACE(CONCAT_WS(',',
                    (SELECT GROUP_CONCAT(DISTINCT pm1.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm1 
                     WHERE pm1.post_id = p.ID AND pm1.meta_key = '_thumbnail_id'),
                    (SELECT GROUP_CONCAT(DISTINCT pm2.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm2 
                     WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery'),
                    (SELECT GROUP_CONCAT(DISTINCT a.ID) 
                     FROM {$wpdb->prefix}posts AS a 
                     WHERE a.post_parent = p.ID AND a.post_type = 'attachment')
                ), ',,', ',')) AS attachment_ids
            FROM 
                {$wpdb->prefix}posts AS p
                INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                $category_filter
                AND p.ID IN (
                    SELECT parent.ID
                    FROM {$wpdb->prefix}posts AS parent
                    JOIN {$wpdb->prefix}posts AS v 
                        ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                    LEFT JOIN {$wpdb->prefix}postmeta AS stock_qty 
                        ON stock_qty.post_id = v.ID AND stock_qty.meta_key = '_stock'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING SUM(
                        IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0) <> 1
                    ) = 0
                )
            GROUP BY p.ID
            LIMIT %d
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $limit), ARRAY_A);
    }
    
    /**
     * Delete products fast using direct SQL
     */
    private function delete_products_fast($products) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'out_of_stock_products_data';
        $product_ids = [];
        $stored_count = 0;
        $products_deleted = 0;
        $variations_deleted = 0;
        
        foreach ($products as $product) {
            $product_id = $product['product_id'];
            $product_ids[] = $product_id;
            
            // Store to tracking table
            $wpdb->insert(
                $table_name,
                [
                    'product_id' => $product_id,
                    'product_title' => $product['product_name'],
                    'category_slug' => $product['category_slug'],
                    'attachment_ids' => $product['attachment_ids'],
                    'deleted_at' => current_time('mysql'),
                    'images_deleted' => 0
                ],
                ['%d', '%s', '%s', '%s', '%s', '%d']
            );
            
            if ($wpdb->insert_id) {
                $stored_count++;
            }
        }
        
        if (!empty($product_ids)) {
            // Get variation IDs
            $variation_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}posts 
                WHERE post_parent IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")
                AND post_type = 'product_variation'",
                ...$product_ids
            ));
            
            $variations_deleted = count($variation_ids);
            
            // Delete variation meta
            if (!empty($variation_ids)) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}postmeta 
                    WHERE post_id IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")",
                    ...$variation_ids
                ));
            }
            
            // Delete variations
            if (!empty($variation_ids)) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}posts 
                    WHERE ID IN (" . implode(',', array_fill(0, count($variation_ids), '%d')) . ")",
                    ...$variation_ids
                ));
            }
            
            // Delete product meta
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}postmeta 
                WHERE post_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")",
                ...$product_ids
            ));
            
            // Delete product terms
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}term_relationships 
                WHERE object_id IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")",
                ...$product_ids
            ));
            
            // Delete products
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}posts 
                WHERE ID IN (" . implode(',', array_fill(0, count($product_ids), '%d')) . ")",
                ...$product_ids
            ));
            
            $products_deleted = $result;
        }
        
        return [
            'products_deleted' => $products_deleted,
            'variations_deleted' => $variations_deleted,
            'stored_count' => $stored_count
        ];
    }
    
    /**
     * Delete attachment fast (file + DB)
     */
    private function delete_attachment_fast($attachment_id) {
        if (!$attachment_id || get_post_type($attachment_id) !== 'attachment') {
            return false;
        }
        
        global $wpdb;
        
        // Get file path
        $file = get_attached_file($attachment_id);
        $meta = wp_get_attachment_metadata($attachment_id);
        
        // Delete physical files
        if ($file && file_exists($file)) {
            @unlink($file);
            
            // Delete thumbnails
            if (isset($meta['sizes']) && is_array($meta['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = dirname($file);
                
                foreach ($meta['sizes'] as $size) {
                    if (isset($size['file'])) {
                        $thumb_file = $base_dir . '/' . $size['file'];
                        if (file_exists($thumb_file)) {
                            @unlink($thumb_file);
                        }
                    }
                }
            }
        }
        
        // Delete from database
        $wpdb->delete($wpdb->prefix . 'postmeta', ['post_id' => $attachment_id]);
        $wpdb->delete($wpdb->prefix . 'posts', ['ID' => $attachment_id]);
        
        return true;
    }
    
    /**
     * Get non-brazyliany products with single quantity (stock = 1)
     */
    private function get_non_brazyliany_products($limit = 0, $offset = 0) {
        global $wpdb;
        
        $limit_clause = '';
        if ($limit > 0) {
            $limit_clause = "LIMIT $limit OFFSET $offset";
        }
        
        $query = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                t.slug AS category_slug,
                TRIM(BOTH ',' FROM REPLACE(CONCAT_WS(',',
                    (SELECT GROUP_CONCAT(DISTINCT pm1.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm1 
                     WHERE pm1.post_id = p.ID AND pm1.meta_key = '_thumbnail_id'),
                    (SELECT GROUP_CONCAT(DISTINCT pm2.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm2 
                     WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery'),
                    (SELECT GROUP_CONCAT(DISTINCT a.ID) 
                     FROM {$wpdb->prefix}posts AS a 
                     WHERE a.post_parent = p.ID AND a.post_type = 'attachment')
                ), ',,', ',')) AS attachment_ids
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
                    JOIN {$wpdb->prefix}posts AS v 
                        ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                    LEFT JOIN {$wpdb->prefix}postmeta AS stock_qty 
                        ON stock_qty.post_id = v.ID AND stock_qty.meta_key = '_stock'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING SUM(
                        IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0) <> 1
                    ) = 0
                )
            GROUP BY p.ID
            $limit_clause
        ";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Count non-brazyliany products with single quantity
     */
    private function count_non_brazyliany_products() {
        global $wpdb;
        
        $query = "
            SELECT COUNT(DISTINCT p.ID)
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
                    JOIN {$wpdb->prefix}posts AS v 
                        ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                    LEFT JOIN {$wpdb->prefix}postmeta AS stock_qty 
                        ON stock_qty.post_id = v.ID AND stock_qty.meta_key = '_stock'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING SUM(
                        IFNULL(CAST(stock_qty.meta_value AS UNSIGNED), 0) <> 1
                    ) = 0
                )
        ";
        
        return (int) $wpdb->get_var($query);
    }
    
    /**
     * Get brazyliany products where all variations have stock < 5
     */
    private function get_brazyliany_products($limit = 0, $offset = 0) {
        global $wpdb;
        
        $limit_clause = '';
        if ($limit > 0) {
            $limit_clause = "LIMIT $limit OFFSET $offset";
        }
        
        $query = "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                t.slug AS category_slug,
                TRIM(BOTH ',' FROM REPLACE(CONCAT_WS(',',
                    (SELECT GROUP_CONCAT(DISTINCT pm1.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm1 
                     WHERE pm1.post_id = p.ID AND pm1.meta_key = '_thumbnail_id'),
                    (SELECT GROUP_CONCAT(DISTINCT pm2.meta_value) 
                     FROM {$wpdb->prefix}postmeta AS pm2 
                     WHERE pm2.post_id = p.ID AND pm2.meta_key = '_product_image_gallery'),
                    (SELECT GROUP_CONCAT(DISTINCT a.ID) 
                     FROM {$wpdb->prefix}posts AS a 
                     WHERE a.post_parent = p.ID AND a.post_type = 'attachment')
                ), ',,', ',')) AS attachment_ids
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
            $limit_clause
        ";
        
        return $wpdb->get_results($query, ARRAY_A);
    }
    
    /**
     * Count brazyliany products where all variations have stock < 5
     */
    private function count_brazyliany_products() {
        global $wpdb;
        
        $query = "
            SELECT COUNT(DISTINCT p.ID)
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
        ";
        
        return (int) $wpdb->get_var($query);
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

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, ['WooCommerce_Auto_Stock_Cleanup', 'activate']);
register_deactivation_hook(__FILE__, ['WooCommerce_Auto_Stock_Cleanup', 'deactivate']);

// Initialize the plugin
new WooCommerce_Auto_Stock_Cleanup();
