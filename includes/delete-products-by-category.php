<?php
/**
 * Delete Products by Category
 * 
 * Provides REST API endpoints to get and delete products by category
 */

if ( !defined( 'ABSPATH' ) )
    exit;

class WC_Delete_Products_By_Category {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // GET: Get products by category
        register_rest_route( 'delete-images/v1', '/cat-products', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'get_products_by_category' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'cat'   => [
                    'required'          => true,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param ) && !empty( $param );
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'required'          => false,
                    'default'           => 100,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && $param > 0 && $param <= 1000;
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
        ] );

        // POST: Delete products by category
        register_rest_route( 'delete-images/v1', '/delete-cat-products', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'delete_products_by_category' ],
            'permission_callback' => [ $this, 'rest_permission_check' ],
            'args'                => [
                'cat'   => [
                    'required'          => true,
                    'validate_callback' => function ( $param ) {
                        return is_string( $param ) && !empty( $param );
                    },
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'limit' => [
                    'required'          => false,
                    'default'           => 100,
                    'validate_callback' => function ( $param ) {
                        return is_numeric( $param ) && $param > 0 && $param <= 1000;
                    },
                    'sanitize_callback' => 'absint',
                ],
            ],
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
     * GET endpoint: Get products by category
     */
    public function get_products_by_category( $request ) {
        $cat   = $request->get_param( 'cat' );
        $limit = $request->get_param( 'limit' ) ?: 100;

        $products = $this->fetch_products_by_category( $cat, $limit );

        return new WP_REST_Response( [
            'success'  => true,
            'count'    => count( $products ),
            'category' => $cat,
            'limit'    => $limit,
            'products' => $products,
        ], 200 );
    }

    /**
     * POST endpoint: Delete products by category
     */
    public function delete_products_by_category( $request ) {
        $start_time = microtime( true );
        $cat        = $request->get_param( 'cat' );
        $limit      = $request->get_param( 'limit' ) ?: 100;

        // Get products to delete
        $products = $this->fetch_products_by_category( $cat, $limit );

        if ( empty( $products ) ) {
            return new WP_REST_Response( [
                'success'       => true,
                'message'       => 'No products found in category: ' . $cat,
                'deleted_count' => 0,
                'category'      => $cat,
            ], 200 );
        }

        // Delete products and store to table
        $result = $this->delete_products_fast( $products );

        $execution_time = round( microtime( true ) - $start_time, 2 );

        return new WP_REST_Response( [
            'success'                  => true,
            'category'                 => $cat,
            'products_deleted'         => $result['products_deleted'],
            'variations_deleted'       => $result['variations_deleted'],
            'stored_for_image_cleanup' => $result['stored_count'],
            'execution_time'           => $execution_time . ' seconds'
        ], 200 );
    }

    /**
     * Fetch products by category with all information
     */
    private function fetch_products_by_category( $category, $limit = 100 ) {
        global $wpdb;

        $query = $wpdb->prepare( "
            SELECT 
                p.ID AS product_id,
                p.post_title AS product_name,
                p.post_status AS product_status,
                t.slug AS category_slug,
                t.name AS category_name,
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
                ), ',,', ',')) AS attachment_ids,
                (
                    SELECT COUNT(DISTINCT v.ID)
                    FROM {$wpdb->prefix}posts AS v
                    WHERE v.post_parent = p.ID AND v.post_type = 'product_variation'
                ) AS variation_count,
                (
                    SELECT pm.meta_value
                    FROM {$wpdb->prefix}postmeta AS pm
                    WHERE pm.post_id = p.ID AND pm.meta_key = '_stock_status'
                    LIMIT 1
                ) AS stock_status,
                (
                    SELECT pm.meta_value
                    FROM {$wpdb->prefix}postmeta AS pm
                    WHERE pm.post_id = p.ID AND pm.meta_key = '_stock'
                    LIMIT 1
                ) AS stock_quantity
            FROM 
                {$wpdb->prefix}posts AS p
                INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                AND t.slug = %s
            GROUP BY p.ID
            ORDER BY p.ID DESC
            LIMIT %d
        ", $category, $limit );

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Delete products fast using direct SQL and store to tracking table
     */
    private function delete_products_fast( $products ) {
        global $wpdb;

        $table_name         = $wpdb->prefix . 'out_of_stock_products_data';
        $product_ids        = [];
        $stored_count       = 0;
        $products_deleted   = 0;
        $variations_deleted = 0;

        foreach ( $products as $product ) {
            $product_id    = $product['product_id'];
            $product_ids[] = $product_id;

            // Store to tracking table for later image deletion
            $wpdb->insert(
                $table_name,
                [
                    'product_id'     => $product_id,
                    'product_title'  => $product['product_name'],
                    'category_slug'  => $product['category_slug'],
                    'attachment_ids' => $product['attachment_ids'],
                    'deleted_at'     => current_time( 'mysql' ),
                    'images_deleted' => 0,
                ],
                [ '%d', '%s', '%s', '%s', '%s', '%d' ]
            );

            if ( $wpdb->insert_id ) {
                $stored_count++;
            }
        }

        if ( !empty( $product_ids ) ) {
            // Get variation IDs
            $variation_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->prefix}posts 
                WHERE post_parent IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")
                AND post_type = 'product_variation'",
                ...$product_ids
            ) );

            $variations_deleted = count( $variation_ids );

            // Delete variation meta
            if ( !empty( $variation_ids ) ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}postmeta 
                    WHERE post_id IN (" . implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) ) . ")",
                    ...$variation_ids
                ) );
            }

            // Delete variations
            if ( !empty( $variation_ids ) ) {
                $wpdb->query( $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}posts 
                    WHERE ID IN (" . implode( ',', array_fill( 0, count( $variation_ids ), '%d' ) ) . ")",
                    ...$variation_ids
                ) );
            }

            // Delete product meta
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}postmeta 
                WHERE post_id IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            // Delete product terms
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}term_relationships 
                WHERE object_id IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            // Delete products
            $result = $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->prefix}posts 
                WHERE ID IN (" . implode( ',', array_fill( 0, count( $product_ids ), '%d' ) ) . ")",
                ...$product_ids
            ) );

            $products_deleted = $result;
        }

        return [
            'products_deleted'   => $products_deleted,
            'variations_deleted' => $variations_deleted,
            'stored_count'       => $stored_count,
        ];
    }
}

// Initialize the class
new WC_Delete_Products_By_Category();

