<?php
declare(strict_types=1);

/**
 * Plugin Name: Migration Data Puller
 * Description: Fetch files from URLs using wget and store them in the backups directory
 * Version: 1.0.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.4
 * Author: Hasan Zaheer
 * Author URI: https://technotch.dev
 * Text Domain: migration-data-puller
 * License: GPL v2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

namespace MigrationDataPuller;

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

// Define plugin constants
define('MDP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDP_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MDP_BACKUP_DIR', WP_CONTENT_DIR . '/ai1wm-backups');

/**
 * Add admin menu
 */
add_action('admin_menu', function(): void {
    add_menu_page(
        __('Migration Data Puller', 'migration-data-puller'),
        __('Migration Data Puller', 'migration-data-puller'),
        'manage_options',
        'migration-data-puller',
        __NAMESPACE__ . '\render_admin_page',
        'dashicons-download',
        30
    );
});

/**
 * Register admin scripts and styles
 */
add_action('admin_enqueue_scripts', function(string $hook): void {
    if ('toplevel_page_migration-data-puller' !== $hook) {
        return;
    }

    wp_enqueue_style(
        'migration-data-puller-css',
        MDP_PLUGIN_URL . 'css/migration-data-puller.css',
        array(),
        '1.0.0'
    );

    wp_enqueue_script(
        'migration-data-puller-js',
        MDP_PLUGIN_URL . 'js/migration-data-puller.js',
        array('jquery'),
        '1.0.0',
        true
    );

    wp_localize_script('migration-data-puller-js', 'mdpAjax', array(
        'nonce' => wp_create_nonce('migration_data_puller_nonce'),
        'ajaxurl' => admin_url('admin-ajax.php'),
        'progressText' => __('Downloading...', 'migration-data-puller'),
        'successText' => __('Download completed successfully!', 'migration-data-puller'),
        'errorText' => __('An error occurred during download.', 'migration-data-puller')
    ));
});

/**
 * Handle AJAX request for file download
 */
add_action('wp_ajax_mdp_download_file', __NAMESPACE__ . '\download_file');

/**
 * Download file from URL using WordPress HTTP API
 */
function download_file(): void {
    check_ajax_referer('migration_data_puller_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error(__('Insufficient permissions', 'migration-data-puller'));
    }

    $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
    $directory = isset($_POST['directory']) ? sanitize_text_field($_POST['directory']) : MDP_BACKUP_DIR;
    
    if (empty($url)) {
        wp_send_json_error(__('Please provide a valid URL', 'migration-data-puller'));
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        wp_send_json_error(__('Invalid URL format', 'migration-data-puller'));
    }

    // Validate and create directory
    $directory = trailingslashit($directory);
    if (!file_exists($directory)) {
        if (!wp_mkdir_p($directory)) {
            wp_send_json_error(sprintf(
                __('Failed to create directory: %s', 'migration-data-puller'),
                $directory
            ));
        }
    }

    if (!is_writable($directory)) {
        wp_send_json_error(sprintf(
            __('Directory is not writable: %s', 'migration-data-puller'),
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
            'Accept' => 'application/octet-stream'
        )
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
                __('Download failed: %s', 'migration-data-puller'),
                $response->get_error_message()
            ),
            'debug' => $debug_info
        ));
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        wp_send_json_error(array(
            'message' => sprintf(
                __('Download failed with status code: %d', 'migration-data-puller'),
                $response_code
            ),
            'debug' => $debug_info
        ));
    }

    // Verify file exists and is readable
    if (!file_exists($filepath) || !is_readable($filepath)) {
        wp_send_json_error(array(
            'message' => __('Downloaded file is not accessible', 'migration-data-puller'),
            'debug' => $debug_info
        ));
    }

    wp_send_json_success(array(
        'message' => __('File downloaded successfully', 'migration-data-puller'),
        'filename' => $filename,
        'filepath' => $filepath,
        'debug' => $debug_info
    ));
}

/**
 * Render admin page
 */
function render_admin_page(): void {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Get default directories
    $default_dirs = array(
        MDP_BACKUP_DIR,
        WP_CONTENT_DIR . '/uploads',
        WP_CONTENT_DIR . '/backups',
        ABSPATH . 'wp-content/backups'
    );

    // Get existing directories
    $existing_dirs = array();
    foreach ($default_dirs as $dir) {
        if (file_exists($dir)) {
            $existing_dirs[] = $dir;
        }
    }
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
            <h2><?php esc_html_e('Download File from URL', 'migration-data-puller'); ?></h2>
            <p><?php esc_html_e('Enter the URL of the file you want to download:', 'migration-data-puller'); ?></p>
            
            <form id="mdp-download-form">
                <div class="mdp-form-group">
                    <label for="mdp-file-url"><?php esc_html_e('File URL:', 'migration-data-puller'); ?></label>
                    <input type="url" 
                           id="mdp-file-url" 
                           name="file_url" 
                           class="regular-text" 
                           placeholder="<?php esc_attr_e('https://example.com/file.zip', 'migration-data-puller'); ?>" 
                           required />
                </div>

                <div class="mdp-form-group">
                    <label for="mdp-directory"><?php esc_html_e('Storage Directory:', 'migration-data-puller'); ?></label>
                    <select id="mdp-directory" name="directory" class="regular-text">
                        <?php foreach ($existing_dirs as $dir): ?>
                            <option value="<?php echo esc_attr($dir); ?>"><?php echo esc_html($dir); ?></option>
                        <?php endforeach; ?>
                        <option value="custom"><?php esc_html_e('Custom Directory...', 'migration-data-puller'); ?></option>
                    </select>
                    <input type="text" 
                           id="mdp-custom-directory" 
                           name="custom_directory" 
                           class="regular-text" 
                           style="display: none; margin-top: 10px;"
                           placeholder="<?php esc_attr_e('Enter custom directory path', 'migration-data-puller'); ?>" />
                </div>
                
                <div class="mdp-form-group">
                    <button type="submit" 
                            id="mdp-download-button" 
                            class="button button-primary button-large">
                        <?php esc_html_e('Download File', 'migration-data-puller'); ?>
                    </button>
                </div>
            </form>

            <div id="mdp-progress" class="mdp-progress" style="display: none;">
                <div class="mdp-progress-bar">
                    <div class="mdp-progress-bar-fill"></div>
                </div>
                <div class="mdp-progress-text"></div>
            </div>

            <div id="mdp-result" class="mdp-result" style="display: none;"></div>
            <div id="mdp-debug" class="mdp-debug" style="display: none;">
                <h3><?php esc_html_e('Debug Information', 'migration-data-puller'); ?></h3>
                <pre class="mdp-debug-output"></pre>
            </div>
        </div>
    </div>
    <?php
} 