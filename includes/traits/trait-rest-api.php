<?php
/**
 * REST API Trait
 * 
 * Handles REST API endpoint registration and responses
 */

if ( !defined( 'ABSPATH' ) ) exit;

trait WC_REST_API_Trait {

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // OLD: Legacy cleanup endpoint (slower)
        register_rest_route( 'delete-images/v1', '/cleanup', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_run_cleanup' ],
            'permission_callback' => [ $this, 'rest_permission_check' ],
        ] );

        // NEW: Get out of stock products (fast query only)
        register_rest_route( 'delete-images/v1', '/get-out-of-stock-products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_out_of_stock_products' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'cat'    => [
                    'required'          => false,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param );
                    }
                ],
                'filter' => [
                    'required'          => false,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param ) && $param === 'out-of-stock';
                    }
                ],
                'limit'  => [
                    'required'          => false,
                    'default'           => 100,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && $param > 0 && $param <= 1000;
                    }
                ],
            ],
        ] );

        // NEW: Delete products instantly (SQL only, no images)
        register_rest_route( 'delete-images/v1', '/delete-products', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_delete_products_fast' ],
            'permission_callback' => [ $this, 'rest_permission_check' ],
            'args'                => [
                'cat'    => [
                    'required'          => false,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param );
                    }
                ],
                'filter' => [
                    'required'          => false,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param ) && $param === 'out-of-stock';
                    }
                ],
                'limit'  => [
                    'required'          => false,
                    'default'           => 100,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && $param > 0 && $param <= 1000;
                    }
                ],
            ],
        ] );

        // NEW: Delete associated images from deleted products
        register_rest_route( 'delete-images/v1', '/delete-associate-images', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_delete_associate_images' ],
            'permission_callback' => [ $this, 'rest_permission_check' ],
            'args'                => [
                'batch_size' => [
                    'required'          => false,
                    'default'           => 50,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && $param > 0 && $param <= 500;
                    }
                ],
            ],
        ] );

        // Stats endpoint
        register_rest_route( 'delete-images/v1', '/stats', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_get_stats' ],
            'permission_callback' => '__return_true' // Public endpoint
        ] );
    }

    /**
     * Permission check for REST API
     */
    public function rest_permission_check() {
        // Get stored API key
        $api_key = get_option( 'delete_images_api_key', '' );

        if ( empty( $api_key ) ) {
            return false;
        }

        // Try multiple methods to get the API key from request
        $provided_key = '';

        // Method 1: Check $_SERVER with different header formats
        if ( isset( $_SERVER['HTTP_X_API_KEY'] ) ) {
            $provided_key = $_SERVER['HTTP_X_API_KEY'];
        }

        // Method 2: Use getallheaders() if available
        if ( empty( $provided_key ) && function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            if ( isset( $headers['X-API-Key'] ) ) {
                $provided_key = $headers['X-API-Key'];
            } elseif ( isset( $headers['X-Api-Key'] ) ) {
                $provided_key = $headers['X-Api-Key'];
            } elseif ( isset( $headers['x-api-key'] ) ) {
                $provided_key = $headers['x-api-key'];
            }
        }

        // Method 3: Check Apache request headers
        if ( empty( $provided_key ) && function_exists( 'apache_request_headers' ) ) {
            $headers = apache_request_headers();
            if ( isset( $headers['X-API-Key'] ) ) {
                $provided_key = $headers['X-API-Key'];
            } elseif ( isset( $headers['X-Api-Key'] ) ) {
                $provided_key = $headers['X-Api-Key'];
            } elseif ( isset( $headers['x-api-key'] ) ) {
                $provided_key = $headers['x-api-key'];
            }
        }

        // Trim any whitespace
        $provided_key = trim( $provided_key );
        $api_key      = trim( $api_key );

        return !empty( $provided_key ) && $provided_key === $api_key;
    }

    /**
     * REST API cleanup endpoint
     */
    public function rest_run_cleanup( $request ) {
        $result = $this->run_daily_cleanup();

        return new WP_REST_Response( [
            'success' => true,
            'data'    => $result,
        ], 200 );
    }

    /**
     * NEW API: Get out of stock products (query only, no deletion)
     */
    public function rest_get_out_of_stock_products( $request ) {
        $cat    = $request->get_param( 'cat' );
        $filter = $request->get_param( 'filter' );
        $limit  = $request->get_param( 'limit' ) ?: 100;

        // If filter=out-of-stock, get all out-of-stock products from all categories
        if ( $filter === 'out-of-stock' ) {
            $products = WC_Product_Query_Helper::get_all_out_of_stock_products( $limit );

            return new WP_REST_Response( [
                'success'  => true,
                'count'    => count( $products ),
                'products' => $products,
                'filter'   => 'out-of-stock',
                'category' => 'all',
                'limit'    => $limit,
            ], 200 );
        }

        // Otherwise use the existing logic
        $products = WC_Product_Query_Helper::get_single_quantity_products( $cat, $limit );

        return new WP_REST_Response( [
            'success'  => true,
            'count'    => count( $products ),
            'products' => $products,
            'category' => $cat ?: 'all',
            'limit'    => $limit,
        ], 200 );
    }

    /**
     * NEW API: Delete products fast (SQL only, store to table for image cleanup)
     */
    public function rest_delete_products_fast( $request ) {
        $start_time = microtime( true );
        $cat        = $request->get_param( 'cat' );
        $filter     = $request->get_param( 'filter' );
        $limit      = $request->get_param( 'limit' ) ?: 100;

        // Get products to delete
        if ( $filter === 'out-of-stock' ) {
            // Get all out-of-stock products from all categories
            $products = WC_Product_Query_Helper::get_all_out_of_stock_products( $limit );
        } else {
            // Use existing logic
            $products = WC_Product_Query_Helper::get_single_quantity_products( $cat, $limit );
        }

        if ( empty( $products ) ) {
            return new WP_REST_Response( [
                'success'       => true,
                'message'       => 'No products found to delete',
                'deleted_count' => 0,
            ], 200 );
        }

        // Store to custom table and delete products
        $result = WC_Deletion_Helper::delete_products_fast( $products );

        $execution_time = round( microtime( true ) - $start_time, 2 );

        $response_data = [
            'success'                  => true,
            'products_deleted'         => $result['products_deleted'],
            'variations_deleted'       => $result['variations_deleted'],
            'stored_for_image_cleanup' => $result['stored_count'],
            'execution_time'           => $execution_time . ' seconds'
        ];

        if ( $filter === 'out-of-stock' ) {
            $response_data['filter']   = 'out-of-stock';
            $response_data['category'] = 'all';
        } else {
            $response_data['category'] = $cat ?: 'all';
        }

        return new WP_REST_Response( $response_data, 200 );
    }

    /**
     * NEW API: Delete associated images
     */
    public function rest_delete_associate_images( $request ) {
        $start_time = microtime( true );
        $batch_size = $request->get_param( 'batch_size' ) ?: 50;

        global $wpdb;
        $table_name = $wpdb->prefix . 'out_of_stock_products_data';

        // Get products with pending image deletion
        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name WHERE images_deleted = 0 LIMIT %d",
            $batch_size
        ), ARRAY_A );

        if ( empty( $records ) ) {
            return new WP_REST_Response( [
                'success'        => true,
                'message'        => 'No images pending deletion',
                'images_deleted' => 0,
            ], 200 );
        }

        $images_deleted       = 0;
        $record_ids_processed = [];

        foreach ( $records as $record ) {
            if ( !empty( $record['attachment_ids'] ) ) {
                $attachment_ids = array_filter( array_map( 'intval', explode( ',', $record['attachment_ids'] ) ) );

                foreach ( $attachment_ids as $attachment_id ) {
                    if ( WC_Deletion_Helper::delete_attachment_fast( $attachment_id ) ) {
                        $images_deleted++;
                    }
                }
            }

            $record_ids_processed[] = $record['id'];
        }

        // Mark records as processed
        if ( !empty( $record_ids_processed ) ) {
            $ids_placeholder = implode( ',', array_fill( 0, count( $record_ids_processed ), '%d' ) );
            $wpdb->query( $wpdb->prepare(
                "UPDATE $table_name SET images_deleted = 1 WHERE id IN ($ids_placeholder)",
                ...$record_ids_processed
            ) );
        }

        $execution_time = round( microtime( true ) - $start_time, 2 );

        // Check if more images pending
        $remaining = $wpdb->get_var( "SELECT COUNT(*) FROM $table_name WHERE images_deleted = 0" );

        return new WP_REST_Response( [
            'success'           => true,
            'images_deleted'    => $images_deleted,
            'records_processed' => count( $records ),
            'remaining_records' => (int) $remaining,
            'execution_time'    => $execution_time . ' seconds'
        ], 200 );
    }

    /**
     * REST API stats endpoint
     */
    public function rest_get_stats( $request ) {
        $stats        = get_option( 'delete_images_cleanup_stats', [] );
        $last_cleanup = get_option( 'delete_images_last_cleanup', [] );

        return new WP_REST_Response( [
            'success'      => true,
            'stats'        => $stats,
            'last_cleanup' => $last_cleanup,
        ], 200 );
    }
}

