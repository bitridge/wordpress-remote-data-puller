<?php
/**
 * Plugin Name: WordPress Remote Data Puller
 * Description: Securely fetch and store files from remote URLs using WordPress HTTP API
 * Version: 1.0.0
 * Requires at least: 6.7
 * Requires PHP: 7.4
 * Tested up to: 6.7
 * Author: Hasan Zaheer
 * Author URI: https://technotch.dev
 * Text Domain: wordpress-remote-data-puller
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace WordPressRemoteDataPuller;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('WRDP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WRDP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WRDP_BACKUP_DIR', WP_CONTENT_DIR . '/ai1wm-backups');

/**
 * Add admin menu
 */
add_action('admin_menu', function() {
    add_menu_page(
        __('Remote Data Puller', 'wordpress-remote-data-puller'),
        __('Remote Data Puller', 'wordpress-remote-data-puller'),
        'manage_options',
        'wordpress-remote-data-puller',
        __NAMESPACE__ . '\render_admin_page',
        'dashicons-download',
        30
    );
});

/**
 * Register admin scripts and styles
 */
add_action('admin_enqueue_scripts', function($hook) {
    if ('toplevel_page_wordpress-remote-data-puller' !== $hook) {
        return;
    }

    wp_enqueue_style(
        'wordpress-remote-data-puller-css',
        WRDP_PLUGIN_URL . 'css/wordpress-remote-data-puller.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'wordpress-remote-data-puller-js',
        WRDP_PLUGIN_URL . 'js/wordpress-remote-data-puller.js',
        array('jquery'),
        '1.0.0',
        true
    );

    wp_localize_script('wordpress-remote-data-puller-js', 'wrdpAjax', array(
        'nonce' => wp_create_nonce('wordpress_remote_data_puller_nonce'),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'progressText' => __('Downloading...', 'wordpress-remote-data-puller'),
        'successText' => __('Download completed successfully!', 'wordpress-remote-data-puller'),
        'errorText' => __('An error occurred during download.', 'wordpress-remote-data-puller')
    ));
});

/**
 * Handle AJAX request for file download
 */
add_action('wp_ajax_wrdp_download_file', __NAMESPACE__ . '\download_file');

/**
 * Download file from URL using WordPress HTTP API
 */
function download_file() {
    check_ajax_referer('wordpress_remote_data_puller_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'wordpress-remote-data-puller'));
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $directory = isset($_POST['directory']) ? sanitize_text_field($_POST['directory']) : WRDP_BACKUP_DIR;
    
    if (empty($url)) {
        wp_send_json_error(__('Please provide a valid URL', 'wordpress-remote-data-puller'));
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(__('Invalid URL format', 'wordpress-remote-data-puller'));
    }

    // Handle custom directory path
    if ($directory === 'custom') {
        $custom_path = isset($_POST['custom_directory']) ? sanitize_text_field($_POST['custom_directory']) : '';
        if (empty($custom_path)) {
            wp_send_json_error(__('Please enter a custom directory path', 'wordpress-remote-data-puller'));
        }
        
        // Remove leading/trailing slashes and normalize path
        $custom_path = trim($custom_path, '/\\');
        
        // Ensure path doesn't contain directory traversal
        if (strpos($custom_path, '..') !== false) {
            wp_send_json_error(__('Invalid directory path', 'wordpress-remote-data-puller'));
        }
        
        // Build absolute path from WordPress root
        $directory = ABSPATH . $custom_path;
    }

    // Validate and create directory
    $directory = trailingslashit($directory);
    if (!file_exists($directory)) {
        if (!wp_mkdir_p($directory)) {
            wp_send_json_error(sprintf(
                __('Failed to create directory: %s', 'wordpress-remote-data-puller'),
                $directory
            ));
        }
    }

    if (!is_writable($directory)) {
        wp_send_json_error(sprintf(
            __('Directory is not writable: %s', 'wordpress-remote-data-puller'),
            $directory
        ));
    }

    // Generate unique filename
    $filename = wp_unique_filename($directory, basename($url));
    $filepath = $directory . $filename;

    // Download file using WordPress HTTP API
    $response = wp_remote_get($url, array(
        'timeout' => 300, // 5 minutes timeout
        'stream' => true,
        'filename' => $filepath,
        'headers' => array(
            'Accept' => 'application/octet-stream',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url')
        ),
        'sslverify' => true,
        'redirection' => 5,
        'reject_unsafe_urls' => true
    ));

    $debug_info = array(
        'url' => $url,
        'filepath' => $filepath,
        'directory' => $directory,
        'response_code' => wp_remote_retrieve_response_code($response),
        'response_message' => wp_remote_retrieve_response_message($response),
        'headers' => wp_remote_retrieve_headers($response)
    );

    if (is_wp_error($response)) {
        wp_send_json_error(array(
            'message' => sprintf(
                __('Download failed: %s', 'wordpress-remote-data-puller'),
                $response->get_error_message()
            ),
            'debug' => $debug_info
        ));
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        wp_send_json_error(array(
            'message' => sprintf(
                __('Download failed with status code: %d', 'wordpress-remote-data-puller'),
                $response_code
            ),
            'debug' => $debug_info
        ));
    }

    // Verify file exists and is readable
    if (!file_exists($filepath) || !is_readable($filepath)) {
        wp_send_json_error(array(
            'message' => __('Downloaded file is not accessible', 'wordpress-remote-data-puller'),
            'debug' => $debug_info
        ));
    }

    // Verify file size matches Content-Length header
    $content_length = wp_remote_retrieve_header($response, 'content-length');
    if ($content_length && filesize($filepath) !== (int) $content_length) {
        wp_send_json_error(array(
            'message' => __('Downloaded file size does not match expected size', 'wordpress-remote-data-puller'),
            'debug' => $debug_info
        ));
    }

    wp_send_json_success(array(
        'message' => __('File downloaded successfully', 'wordpress-remote-data-puller'),
        'filename' => $filename,
        'filepath' => $filepath,
        'debug' => $debug_info
    ));
}

/**
 * Render admin page
 */
function render_admin_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get default directories
    $default_dirs = array(
        WRDP_BACKUP_DIR,
        WP_CONTENT_DIR . '/uploads',
        WP_CONTENT_DIR . '/backups',
        ABSPATH . 'wp-content/backups'
    );

    // Get existing directories
    $existing_dirs = array();
    foreach ($default_dirs as $dir) {
        if (file_exists($dir)) {
            // Convert absolute path to relative path for display
            $relative_path = str_replace(ABSPATH, '', $dir);
            $existing_dirs[$dir] = $relative_path;
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
            <h2><?php esc_html_e('Download File from URL', 'wordpress-remote-data-puller'); ?></h2>
            <p><?php esc_html_e('Enter the URL of the file you want to download:', 'wordpress-remote-data-puller'); ?></p>
            
            <form id="wrdp-download-form">
                <div class="wrdp-form-group">
                    <label for="wrdp-file-url"><?php esc_html_e('File URL:', 'wordpress-remote-data-puller'); ?></label>
                    <input type="url" 
                           id="wrdp-file-url" 
                           name="file_url" 
                           class="regular-text" 
                           placeholder="<?php esc_attr_e('https://example.com/file.zip', 'wordpress-remote-data-puller'); ?>" 
                           required />
                </div>

                <div class="wrdp-form-group">
                    <label for="wrdp-directory"><?php esc_html_e('Storage Directory:', 'wordpress-remote-data-puller'); ?></label>
                    <select id="wrdp-directory" name="directory" class="regular-text">
                        <?php foreach ($existing_dirs as $absolute_path => $relative_path): ?>
                            <option value="<?php echo esc_attr($absolute_path); ?>"><?php echo esc_html($relative_path); ?></option>
                        <?php endforeach; ?>
                        <option value="custom"><?php esc_html_e('Custom Directory...', 'wordpress-remote-data-puller'); ?></option>
                    </select>
                    <input type="text" 
                           id="wrdp-custom-directory" 
                           name="custom_directory" 
                           class="regular-text" 
                           style="display: none; margin-top: 10px;"
                           placeholder="<?php esc_attr_e('Enter path relative to WordPress root (e.g., wp-content/custom-folder)', 'wordpress-remote-data-puller'); ?>" />
                    <p class="description" style="display: none; margin-top: 5px;">
                        <?php esc_html_e('Enter a path relative to WordPress root directory. Example: wp-content/custom-folder', 'wordpress-remote-data-puller'); ?>
                    </p>
                </div>
                
                <div class="wrdp-form-group">
                    <button type="submit" 
                            id="wrdp-download-button" 
                            class="button button-primary button-large">
                        <?php esc_html_e('Download File', 'wordpress-remote-data-puller'); ?>
                    </button>
                </div>
            </form>

            <div id="wrdp-progress" class="wrdp-progress" style="display: none;">
                <div class="wrdp-progress-bar">
                    <div class="wrdp-progress-bar-fill"></div>
                </div>
                <div class="wrdp-progress-text"></div>
            </div>

            <div id="wrdp-result" class="wrdp-result" style="display: none;"></div>
            <div id="wrdp-debug" class="wrdp-debug" style="display: none;">
                <h3><?php esc_html_e('Debug Information', 'wordpress-remote-data-puller'); ?></h3>
                <pre class="wrdp-debug-output"></pre>
            </div>
        </div>
    </div>
    <?php
} 