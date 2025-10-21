<?php
/**
 * Plugin Name: WooCommerce Auto Stock Cleanup
 * Plugin URI:  https://github.com/shahjalal132/woocommerce-auto-stock-cleanup
 * Author:      Shah Jalal
 * Author URI:  https://github.com/shahjalal132
 * Description: Automatically cleanup WooCommerce products with low/no stock and their images via REST API endpoints with comprehensive statistics tracking and manual deletion tools.
 * Version:     3.0.0
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

if ( !defined( 'ABSPATH' ) )
    exit;

// Check if WooCommerce is active (supports multisite)
function wc_auto_stock_cleanup_is_woocommerce_active() {
    $active_plugins = (array) get_option( 'active_plugins', array() );
    if ( is_multisite() ) {
        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
    }
    return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
}

if ( !wc_auto_stock_cleanup_is_woocommerce_active() ) {
    add_action( 'admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>WooCommerce Auto Stock Cleanup</strong> requires WooCommerce to be installed and active.</p></div>';
    } );
    return;
}

// Load dependencies
require_once __DIR__ . '/includes/helpers/class-product-query-helper.php';
require_once __DIR__ . '/includes/helpers/class-deletion-helper.php';
require_once __DIR__ . '/includes/helpers/class-utility-helper.php';
require_once __DIR__ . '/includes/traits/trait-rest-api.php';
require_once __DIR__ . '/includes/traits/trait-batch-processing.php';

// Include additional features
require_once __DIR__ . '/includes/scheduler.php';
require_once __DIR__ . '/includes/delete-duplicate-images.php';
require_once __DIR__ . '/includes/delete-products-by-category.php';

class WooCommerce_Auto_Stock_Cleanup {
    use WC_REST_API_Trait;
    use WC_Batch_Processing_Trait;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_delete_images_by_ids', [ $this, 'ajax_delete_images' ] );

        // REST API endpoints
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

        // Manual cleanup trigger from admin
        add_action( 'admin_post_run_cleanup_now', [ $this, 'manual_cleanup' ] );

        // WooCommerce compatibility
        add_action( 'before_woocommerce_init', [ $this, 'declare_compatibility' ] );
        add_action( 'init', [ $this, 'init_plugin' ] );
    }

    /**
     * Declare WooCommerce feature compatibility
     */
    public function declare_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            // Declare HPOS compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );

            // Declare other WooCommerce features compatibility
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'orders_cache', __FILE__, true );
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );

            // Additional compatibility declarations
            if ( method_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil', 'declare_compatibility' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
            }
        }
    }

    /**
     * Initialize plugin after WordPress is fully loaded
     */
    public function init_plugin() {
        // Check WooCommerce version compatibility
        if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '3.0', '<' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-warning"><p><strong>WooCommerce Auto Stock Cleanup</strong> requires WooCommerce 3.0 or higher. Please update WooCommerce.</p></div>';
            } );
        }
    }

    /**
     * Plugin activation hook
     */
    public static function activate() {
        // Check if WooCommerce is active
        if ( !class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'WooCommerce Auto Stock Cleanup requires WooCommerce to be installed and active.' );
        }

        // Create API key if not exists
        if ( !get_option( 'delete_images_api_key' ) ) {
            update_option( 'delete_images_api_key', wp_generate_password( 32, false ) );
        }

        // Create custom table for tracking deleted products
        WC_Utility_Helper::create_deleted_products_table();
    }

    /**
     * Plugin deactivation hook
     */
    public static function deactivate() {
        // Clean up any scheduled events or temporary data if needed
    }

    /**
     * Register admin menu
     */
    public function register_menu() {
        add_management_page(
            'WooCommerce Auto Stock Cleanup',
            'WooCommerce Auto Stock Cleanup',
            'manage_options',
            'woocommerce-auto-stock-cleanup',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Enqueue assets
     */
    public function enqueue_assets( $hook ) {
        if ( $hook !== 'tools_page_woocommerce-auto-stock-cleanup' ) {
            return;
        }

        wp_enqueue_script( 'jquery' );
        wp_enqueue_script(
            'delete-images-script',
            plugin_dir_url( __FILE__ ) . 'assets/admin/js/delete-images.js',
            [ 'jquery' ],
            false,
            true
        );
        wp_localize_script( 'delete-images-script', 'deleteImages', [
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'delete_images_nonce' ),
        ] );

        wp_enqueue_style( 'delete-images-style', plugin_dir_url( __FILE__ ) . 'assets/admin/css/delete-images.css' );
    }

    /**
     * Render admin page
     */
    public function render_page() {
        // Handle API key generation
        if ( isset( $_POST['generate_api_key'] ) && check_admin_referer( 'delete_images_api_key' ) ) {
            $api_key = wp_generate_password( 32, false );
            update_option( 'delete_images_api_key', $api_key );
            echo '<div class="notice notice-success is-dismissible"><p>API Key generated successfully!</p></div>';
        }

        // Check for cleanup success message
        if ( isset( $_GET['cleanup'] ) && $_GET['cleanup'] === 'success' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Cleanup completed successfully!</p></div>';
        }

        // Get stats and cleanup info
        $stats        = get_option( 'delete_images_cleanup_stats', [] );
        $last_cleanup = get_option( 'delete_images_last_cleanup', [] );
        $api_key      = get_option( 'delete_images_api_key', '' );
        $site_url     = get_site_url();

        // Include template
        include __DIR__ . '/includes/templates/admin-page.php';
    }

    /**
     * Manual cleanup trigger
     */
    public function manual_cleanup() {
        if ( !current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized' );
        }

        // Run the cleanup and get stats
        $stats = $this->run_daily_cleanup();

        // Redirect back with success message
        wp_redirect( add_query_arg( [
            'page'    => 'woocommerce-auto-stock-cleanup',
            'cleanup' => 'success',
        ], admin_url( 'tools.php' ) ) );
        exit;
    }

    /**
     * AJAX handler for manual image deletion
     */
    public function ajax_delete_images() {
        check_ajax_referer( 'delete_images_nonce', 'nonce' );

        $ids = isset( $_POST['ids'] ) ? array_map( 'intval', explode( ',', $_POST['ids'] ) ) : [];

        $deleted = [];
        $failed  = [];

        foreach ( $ids as $id ) {
            if ( get_post_type( $id ) === 'attachment' ) {
                $result = wp_delete_attachment( $id, true );
                if ( $result ) {
                    $deleted[] = $id;
                } else {
                    $failed[] = $id;
                }
            } else {
                $failed[] = $id;
            }
        }

        wp_send_json( [
            'success' => true,
            'deleted' => $deleted,
            'failed'  => $failed,
        ] );
    }
}

// Plugin activation/deactivation hooks
register_activation_hook( __FILE__, [ 'WooCommerce_Auto_Stock_Cleanup', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'WooCommerce_Auto_Stock_Cleanup', 'deactivate' ] );

// Initialize the plugin
new WooCommerce_Auto_Stock_Cleanup();

