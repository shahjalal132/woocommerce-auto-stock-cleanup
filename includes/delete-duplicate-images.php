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
     * Scan unattached media and delete duplicates
     */
    public function scan_and_delete(WP_REST_Request $request) {
        global $wpdb;

        $limit   = intval($request->get_param('limit')) ?: 50;
        $last_id = intval(get_option('media_cleaner_last_id', 0));

        // Fetch unattached images after last scanned ID
        $attachments = $wpdb->get_results($wpdb->prepare("
            SELECT ID, guid
            FROM {$wpdb->posts}
            WHERE post_type = 'attachment'
            AND post_parent = 0
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
                'duplicateFound'  => 0,
                'deletedIds'      => [],
                'skippedIds'      => [],
            ]);
        }

        $scanned         = 0;
        $duplicateFound  = 0;
        $deletedIds      = [];
        $skippedIds      = [];

        foreach ($attachments as $attachment) {
            $scanned++;
            $last_id = $attachment->ID;

            $file_path = get_attached_file($attachment->ID);

            // Case 1: File missing
            if (!$file_path || !file_exists($file_path)) {
                wp_delete_attachment($attachment->ID, true);
                $deletedIds[] = $attachment->ID;
                continue;
            }

            // Compute file hash
            $hash = md5_file($file_path);

            // Check for duplicate by hash
            $existing = $wpdb->get_var($wpdb->prepare("
                SELECT post_id FROM {$wpdb->postmeta}
                WHERE meta_key = '_file_hash'
                AND meta_value = %s
                AND post_id != %d
                LIMIT 1
            ", $hash, $attachment->ID));

            if ($existing) {
                $duplicateFound++;
                wp_delete_attachment($attachment->ID, true);
                $deletedIds[] = $attachment->ID;
            } else {
                update_post_meta($attachment->ID, '_file_hash', $hash);
                $skippedIds[] = $attachment->ID;
            }
        }

        update_option('media_cleaner_last_id', $last_id);

        return $this->response([
            'status'          => 'ok',
            'message'         => 'Scan completed successfully.',
            'scanned'         => $scanned,
            'duplicateFound'  => $duplicateFound,
            'deletedIds'      => $deletedIds,
            'skippedIds'      => $skippedIds,
            'lastProcessedId' => $last_id,
        ]);
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
