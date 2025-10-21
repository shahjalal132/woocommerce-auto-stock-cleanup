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

        $limit   = intval($request->get_param('limit')) ?: 200; // Increased default limit
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

        // Build lookup tables for fast reference checking (BULK LOAD)
        $attachment_ids = array_column($attachments, 'ID');
        $reference_cache = $this->build_reference_cache($attachment_ids);

        $scanned            = 0;
        $unreferencedFound  = 0;
        $deletedIds         = [];
        $skippedIds         = [];

        foreach ($attachments as $attachment) {
            $scanned++;
            $last_id = $attachment->ID;

            // Check if image has any references using cached data
            $has_reference = $this->has_any_reference_fast($attachment->ID, $attachment->post_parent, $reference_cache);

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
     * Build reference cache for batch of attachments (FAST BULK LOADING)
     * 
     * @param array $attachment_ids Array of attachment IDs to check
     * @return array Cache of all references
     */
    private function build_reference_cache($attachment_ids) {
        global $wpdb;
        
        $cache = [
            'valid_parents' => [],
            'thumbnails' => [],
            'galleries' => [],
            'content_refs' => [],
            'meta_refs' => [],
        ];

        if (empty($attachment_ids)) {
            return $cache;
        }

        $ids_placeholder = implode(',', array_fill(0, count($attachment_ids), '%d'));

        // 1. Get all valid parent posts in one query
        $parent_ids = array_unique(array_filter(array_column($wpdb->get_results($wpdb->prepare("
            SELECT DISTINCT post_parent 
            FROM {$wpdb->posts}
            WHERE ID IN ($ids_placeholder)
            AND post_parent > 0
        ", ...$attachment_ids)), 'post_parent')));

        if (!empty($parent_ids)) {
            $parent_placeholder = implode(',', array_fill(0, count($parent_ids), '%d'));
            $valid_parents = $wpdb->get_col($wpdb->prepare("
                SELECT ID FROM {$wpdb->posts}
                WHERE ID IN ($parent_placeholder)
                AND post_status != 'trash'
            ", ...$parent_ids));
            $cache['valid_parents'] = array_flip($valid_parents);
        }

        // 2. Get all thumbnail references in one query
        $thumbnails = $wpdb->get_results($wpdb->prepare("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_thumbnail_id'
            AND meta_value IN ($ids_placeholder)
        ", ...$attachment_ids));
        
        foreach ($thumbnails as $thumb) {
            $cache['thumbnails'][$thumb->meta_value] = true;
        }

        // 3. Get all product galleries in one query
        $galleries = $wpdb->get_results("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_product_image_gallery'
            AND meta_value != ''
        ", ARRAY_A);

        foreach ($galleries as $gallery) {
            $gallery_ids = array_filter(array_map('intval', explode(',', $gallery['meta_value'])));
            foreach ($gallery_ids as $gid) {
                $cache['galleries'][$gid] = true;
            }
        }

        // 4. Get attachment URLs for content checking (batch)
        $attachment_data = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, pm.meta_value as file_path
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
            WHERE p.ID IN ($ids_placeholder)
        ", ...$attachment_ids), OBJECT_K);

        $filenames = [];
        foreach ($attachment_data as $id => $data) {
            if ($data->file_path) {
                $filename = basename($data->file_path);
                $filenames[$id] = $filename;
            }
        }

        // Check content references for all filenames at once
        if (!empty($filenames)) {
            $filename_conditions = [];
            $filename_params = [];
            foreach ($filenames as $id => $filename) {
                $filename_conditions[] = "post_content LIKE %s";
                $filename_params[] = '%' . $wpdb->esc_like($filename) . '%';
            }
            
            $content_query = "
                SELECT DISTINCT ID FROM {$wpdb->posts}
                WHERE (" . implode(' OR ', $filename_conditions) . ")
                AND post_status != 'trash'
                LIMIT 1000
            ";
            
            $has_content = $wpdb->get_var($wpdb->prepare($content_query, ...$filename_params));
            
            if ($has_content) {
                // If any content reference found, need to check individually later
                $cache['content_refs'] = $filenames;
            }
        }

        // 5. Get all meta references in one query
        $meta_keys = [
            '_product_image',
            'background_image',
            'header_image',
            'banner_image',
            'logo_image',
            'featured_image',
        ];

        $meta_conditions = [];
        $meta_params = [];
        foreach ($meta_keys as $key) {
            $meta_conditions[] = "meta_key = %s";
            $meta_params[] = $key;
        }

        $meta_refs = $wpdb->get_results($wpdb->prepare("
            SELECT meta_value FROM {$wpdb->postmeta}
            WHERE (" . implode(' OR ', $meta_conditions) . ")
            AND meta_value IN ($ids_placeholder)
        ", array_merge($meta_params, $attachment_ids)));

        foreach ($meta_refs as $ref) {
            $cache['meta_refs'][$ref->meta_value] = true;
        }

        return $cache;
    }

    /**
     * Fast reference check using pre-built cache
     * 
     * @param int $attachment_id The attachment ID to check
     * @param int $post_parent The post parent ID from the attachment
     * @param array $cache Pre-built reference cache
     * @return bool True if the image has references, false otherwise
     */
    private function has_any_reference_fast($attachment_id, $post_parent, $cache) {
        // 1. Check valid parent
        if ($post_parent > 0 && isset($cache['valid_parents'][$post_parent])) {
            return true;
        }

        // 2. Check thumbnails
        if (isset($cache['thumbnails'][$attachment_id])) {
            return true;
        }

        // 3. Check galleries
        if (isset($cache['galleries'][$attachment_id])) {
            return true;
        }

        // 4. Check content references
        if (isset($cache['content_refs'][$attachment_id])) {
            global $wpdb;
            $filename = $cache['content_refs'][$attachment_id];
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

        // 5. Check meta references
        if (isset($cache['meta_refs'][$attachment_id])) {
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
