<?php
class Delete_Duplicate_Unattached_Images {

    private $namespace = 'media-cleaner/v1';
    private $route     = '/scan';

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API route
     */
    public function register_routes() {
        register_rest_route($this->namespace, $this->route, [
            'methods'  => 'POST',
            'callback' => [$this, 'scan_and_delete'],
            'permission_callback' => '__return_true', // Replace with API key check if needed
        ]);
    }

    /**
     * Scan images and delete those with no references
     */
    public function scan_and_delete(WP_REST_Request $request) {
        global $wpdb;

        $limit   = intval($request->get_param('limit')) ?: 50;
        $last_id = intval(get_option('media_cleaner_last_id', 0));

        // Fetch all images after last scanned ID
        $attachments = $wpdb->get_results($wpdb->prepare("
            SELECT ID, guid, post_parent
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_mime_type LIKE 'image%%'
            AND ID > %d
            ORDER BY ID ASC
            LIMIT %d
        ", $last_id, $limit));

        if (empty($attachments)) {
            update_option('media_cleaner_last_id', 0);

            return $this->response([
                'status'          => 'done',
                'message'         => 'All attachments scanned, resetting progress.',
                'scanned'         => 0,
                'unreferencedFound' => 0,
                'deletedIds'      => [],
                'skippedIds'      => [],
            ]);
        }

        $scanned            = 0;
        $unreferencedFound  = 0;
        $deletedIds         = [];
        $skippedIds         = [];

        foreach ($attachments as $attachment) {
            $scanned++;
            $last_id = $attachment->ID;

            // Check if image has any references
            $has_reference = $this->has_any_reference($attachment->ID, $attachment->post_parent);

            if (!$has_reference) {
                // No reference found, delete the image
                $unreferencedFound++;
                wp_delete_attachment($attachment->ID, true);
                $deletedIds[] = $attachment->ID;
            } else {
                // Image is referenced, skip it
                $skippedIds[] = $attachment->ID;
            }
        }

        update_option('media_cleaner_last_id', $last_id);

        return $this->response([
            'status'            => 'ok',
            'message'           => 'Scan completed successfully.',
            'scanned'           => $scanned,
            'unreferencedFound' => $unreferencedFound,
            'deletedIds'        => $deletedIds,
            'skippedIds'        => $skippedIds,
            'lastProcessedId'   => $last_id,
        ]);
    }

    /**
     * Check if an image has any references in posts, products, or designs
     * 
     * @param int $attachment_id The attachment ID to check
     * @param int $post_parent The post parent ID from the attachment
     * @return bool True if the image has references, false otherwise
     */
    private function has_any_reference($attachment_id, $post_parent) {
        global $wpdb;

        // 1. Check if it has a post parent (attached to a post/product)
        if ($post_parent > 0) {
            // Verify the parent post actually exists
            $parent_exists = $wpdb->get_var($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE ID = %d
                AND post_status != 'trash'
                LIMIT 1
            ", $post_parent));
            
            if ($parent_exists) {
                return true;
            }
        }

        // 2. Check if it's used as a featured image (thumbnail) for any post/product
        $used_as_thumbnail = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value = %d
            LIMIT 1
        ", $attachment_id));

        if ($used_as_thumbnail) {
            return true;
        }

        // 3. Check if it's in any WooCommerce product gallery
        $in_product_gallery = $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_image_gallery'
            AND meta_value LIKE %s
        ", '%' . $wpdb->esc_like($attachment_id) . '%'));

        foreach ($in_product_gallery as $gallery) {
            $gallery_ids = array_filter(array_map('intval', explode(',', $gallery->meta_value)));
            if (in_array($attachment_id, $gallery_ids)) {
                return true;
            }
        }

        // 4. Check if it's referenced in post content
        $attachment_url = wp_get_attachment_url($attachment_id);
        if ($attachment_url) {
            // Extract filename from URL for more flexible matching
            $filename = basename($attachment_url);
            
            $in_content = $wpdb->get_var($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE post_content LIKE %s
                AND post_status != 'trash'
                LIMIT 1
            ", '%' . $wpdb->esc_like($filename) . '%'));

            if ($in_content) {
                return true;
            }
        }

        // 5. Check if it's referenced in any other postmeta fields
        // Common meta keys that might store image IDs
        $common_meta_keys = [
            '_product_image',
            'background_image',
            'header_image',
            'banner_image',
            'logo_image',
            'featured_image',
        ];

        foreach ($common_meta_keys as $meta_key) {
            $in_meta = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                AND meta_value = %d
                LIMIT 1
            ", $meta_key, $attachment_id));

            if ($in_meta) {
                return true;
            }
        }

        // 6. Check ACF and other serialized meta fields
        $serialized_meta = $wpdb->get_results($wpdb->prepare("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_value LIKE %s
        ", '%' . $wpdb->esc_like('i:' . $attachment_id . ';') . '%'));

        if (!empty($serialized_meta)) {
            return true;
        }

        // No references found
        return false;
    }

    /**
     * Helper to return structured JSON response
     */
    private function response(array $data) {
        return new WP_REST_Response($data, 200);
    }
}

// Initialize the cleaner
new Delete_Duplicate_Unattached_Images();
