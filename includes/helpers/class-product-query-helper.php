<?php
/**
 * Product Query Helper
 * 
 * Handles all product-related database queries
 */

if ( !defined( 'ABSPATH' ) ) exit;

class WC_Product_Query_Helper {

    /**
     * Get single quantity products OR category-filtered products
     */
    public static function get_single_quantity_products( $category = null, $limit = 100 ) {
        global $wpdb;

        if ( $category ) {
            // Category filter mode
            $query = $wpdb->prepare( "
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
                    ), ',,', ',')) AS attachment_ids,
                    (
                        SELECT COUNT(DISTINCT v.ID)
                        FROM {$wpdb->prefix}posts AS v
                        WHERE v.post_parent = p.ID AND v.post_type = 'product_variation'
                    ) AS variation_count
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
                    AND p.ID IN (
                        SELECT parent.ID
                        FROM {$wpdb->prefix}posts AS parent
                        JOIN {$wpdb->prefix}posts AS v 
                            ON v.post_parent = parent.ID 
                            AND v.post_type = 'product_variation'
                        WHERE parent.post_type = 'product'
                        GROUP BY parent.ID
                        HAVING COUNT(DISTINCT v.ID) < 5
                    )
                GROUP BY p.ID
                LIMIT %d
            ", $category, $limit );
        } else {
            // Default mode (single-quantity products)
            $query = $wpdb->prepare( "
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
            ", $limit );
        }

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Get all out-of-stock products
     */
    public static function get_all_out_of_stock_products( $limit = 100 ) {
        global $wpdb;

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
            FROM {$wpdb->prefix}posts AS p
            INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
            INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                AND (
                    EXISTS (
                        SELECT 1 FROM {$wpdb->prefix}postmeta AS pm
                        WHERE pm.post_id = p.ID
                        AND pm.meta_key = '_stock_status'
                        AND pm.meta_value = 'outofstock'
                    )
                    OR
                    p.ID IN (
                        SELECT parent.ID
                        FROM {$wpdb->prefix}posts AS parent
                        JOIN {$wpdb->prefix}posts AS v 
                            ON v.post_parent = parent.ID AND v.post_type = 'product_variation'
                        LEFT JOIN {$wpdb->prefix}postmeta AS stock_status 
                            ON stock_status.post_id = v.ID AND stock_status.meta_key = '_stock_status'
                        WHERE parent.post_type = 'product'
                        GROUP BY parent.ID
                        HAVING SUM(CASE WHEN stock_status.meta_value = 'instock' THEN 1 ELSE 0 END) = 0
                    )
                )
            GROUP BY p.ID
            LIMIT %d
        ";

        return $wpdb->get_results( $wpdb->prepare( $query, $limit ), ARRAY_A );
    }

    /**
     * Get non-brazyliany products with single quantity
     */
    public static function get_non_brazyliany_products( $limit = 0, $offset = 0 ) {
        global $wpdb;

        $limit_clause = '';
        if ( $limit > 0 ) {
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

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Get brazyliany (biustonosze) products with fewer than 5 variations
     */
    public static function get_brazyliany_products( $limit = 0, $offset = 0 ) {
        global $wpdb;

        $limit_clause = '';
        if ( $limit > 0 ) {
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
                ), ',,', ',')) AS attachment_ids,
                (
                    SELECT COUNT(DISTINCT v.ID)
                    FROM {$wpdb->prefix}posts AS v
                    WHERE v.post_parent = p.ID AND v.post_type = 'product_variation'
                ) AS variation_count
            FROM 
                {$wpdb->prefix}posts AS p
                INNER JOIN {$wpdb->prefix}term_relationships AS tr ON p.ID = tr.object_id
                INNER JOIN {$wpdb->prefix}term_taxonomy AS tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->prefix}terms AS t ON tt.term_id = t.term_id
            WHERE 
                p.post_type = 'product'
                AND p.post_status = 'publish'
                AND tt.taxonomy = 'product_cat'
                AND t.slug = 'biustonosze'
                AND p.ID IN (
                    SELECT parent.ID
                    FROM {$wpdb->prefix}posts AS parent
                    JOIN {$wpdb->prefix}posts AS v 
                        ON v.post_parent = parent.ID 
                        AND v.post_type = 'product_variation'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING COUNT(DISTINCT v.ID) < 5
                )
            GROUP BY p.ID
            $limit_clause
        ";

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Count non-brazyliany products
     */
    public static function count_non_brazyliany_products() {
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

        return (int) $wpdb->get_var( $query );
    }

    /**
     * Count brazyliany (biustonosze) products
     */
    public static function count_brazyliany_products() {
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
                AND t.slug = 'biustonosze'
                AND p.ID IN (
                    SELECT parent.ID
                    FROM {$wpdb->prefix}posts AS parent
                    JOIN {$wpdb->prefix}posts AS v 
                        ON v.post_parent = parent.ID 
                        AND v.post_type = 'product_variation'
                    WHERE parent.post_type = 'product'
                    GROUP BY parent.ID
                    HAVING COUNT(DISTINCT v.ID) < 5
                )
        ";

        return (int) $wpdb->get_var( $query );
    }
}

