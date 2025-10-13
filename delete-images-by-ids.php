<?php
/**
 * Plugin Name: Delete Images by IDs
 * Description: Delete WordPress attachments by their IDs with AJAX and a progress bar.
 * Version: 1.0
 * Author: Shah Jalal
 */

if (!defined('ABSPATH')) exit;

class Delete_Images_By_IDs {
    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_delete_images_by_ids', [$this, 'ajax_delete_images']);
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
        ?>
        <div class="wrap">
            <h1>Delete Images by IDs</h1>
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
        <?php
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
