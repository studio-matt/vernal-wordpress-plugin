<?php
/**
 * Settings and Connection Management
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Settings {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_vernal_copy_connection_data', array($this, 'ajax_copy_connection_data'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            __('Vernal Contentum', 'vernal-contentum'),
            __('Vernal Contentum', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum',
            array($this, 'render_settings_page'),
            'dashicons-admin-links',
            30
        );
    }
    
    public function register_settings() {
        register_setting('vernal_contentum_settings', 'vernal_contentum_settings', array($this, 'sanitize_settings'));
        
        add_settings_section(
            'vernal_connection_section',
            __('Connection Settings', 'vernal-contentum'),
            array($this, 'render_connection_section'),
            'vernal-contentum'
        );
        
        add_settings_field(
            'vernal_webapp_url',
            __('Vernal Contentum Web App URL', 'vernal-contentum'),
            array($this, 'render_webapp_url_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        add_settings_field(
            'vernal_username',
            __('Username', 'vernal-contentum'),
            array($this, 'render_username_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        add_settings_field(
            'vernal_password',
            __('Password', 'vernal-contentum'),
            array($this, 'render_password_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        add_settings_field(
            'vernal_api_key',
            __('API Key', 'vernal-contentum'),
            array($this, 'render_api_key_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
    }
    
    public function sanitize_settings($input) {
        $sanitized = array();
        
        if (isset($input['webapp_url'])) {
            $sanitized['webapp_url'] = esc_url_raw($input['webapp_url']);
        }
        
        if (isset($input['username'])) {
            $sanitized['username'] = sanitize_text_field($input['username']);
        }
        
        if (isset($input['password'])) {
            // Only update if password is not empty (to allow keeping existing password)
            if (!empty($input['password'])) {
                $sanitized['password'] = base64_encode($input['password']); // Basic encoding, consider encryption
            }
        }
        
        return $sanitized;
    }
    
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option('vernal_contentum_settings', array());
        $api_key = get_option('vernal_contentum_api_key', '');
        $site_url = get_site_url();
        $wp_username = wp_get_current_user()->user_login;
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="vernal-connection-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php _e('Quick Connection Setup', 'vernal-contentum'); ?></h2>
                <p><?php _e('Copy the connection data below and paste it into your Vernal Contentum web app dashboard:', 'vernal-contentum'); ?></p>
                
                <div style="margin: 15px 0;">
                    <label for="vernal-connection-data" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        <?php _e('Connection Data:', 'vernal-contentum'); ?>
                    </label>
                    <textarea 
                        id="vernal-connection-data" 
                        readonly 
                        style="width: 100%; height: 120px; font-family: monospace; padding: 10px;"
                    ><?php 
                        echo esc_textarea(json_encode(array(
                            'site_url' => $site_url,
                            'username' => $wp_username,
                            'api_key' => $api_key,
                            'app_password' => $api_key, // Alias for compatibility
                            'api_endpoint' => rest_url('vernal-contentum/v1/')
                        ), JSON_PRETTY_PRINT));
                    ?></textarea>
                </div>
                
                <button 
                    type="button" 
                    id="vernal-copy-btn" 
                    class="button button-primary"
                    style="margin-top: 10px;"
                >
                    <?php _e('Copy Connection Data', 'vernal-contentum'); ?>
                </button>
                <span id="vernal-copy-success" style="margin-left: 10px; color: #46b450; display: none;">
                    <?php _e('✓ Copied!', 'vernal-contentum'); ?>
                </span>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('vernal_contentum_settings');
                do_settings_sections('vernal-contentum');
                submit_button(__('Save Settings', 'vernal-contentum'));
                ?>
            </form>
            
            <div class="vernal-info-box" style="background: #fff; border-left: 4px solid #2271b1; padding: 15px; margin: 20px 0;">
                <h3><?php _e('API Endpoints', 'vernal-contentum'); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><strong>Sitemap:</strong> <code><?php echo esc_url(rest_url('vernal-contentum/v1/sitemap')); ?></code></li>
                    <li><strong>Categories:</strong> <code><?php echo esc_url(rest_url('vernal-contentum/v1/categories')); ?></code></li>
                    <li><strong>Authors:</strong> <code><?php echo esc_url(rest_url('vernal-contentum/v1/authors')); ?></code></li>
                    <li><strong>Create Post:</strong> <code><?php echo esc_url(rest_url('vernal-contentum/v1/posts')); ?></code> (POST)</li>
                </ul>
                <p style="margin-top: 10px;">
                    <strong><?php _e('Authentication:', 'vernal-contentum'); ?></strong> 
                    <?php _e('Include the API key in the request header:', 'vernal-contentum'); ?>
                    <code>X-API-Key: <?php echo esc_html($api_key); ?></code>
                </p>
            </div>
        </div>
        <?php
    }
    
    public function render_connection_section() {
        echo '<p>' . __('Configure your connection to the Vernal Contentum web app.', 'vernal-contentum') . '</p>';
    }
    
    public function render_webapp_url_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['webapp_url']) ? $settings['webapp_url'] : '';
        ?>
        <input 
            type="url" 
            name="vernal_contentum_settings[webapp_url]" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
            placeholder="https://app.vernalcontentum.com"
        />
        <p class="description"><?php _e('The URL of your Vernal Contentum web app.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_username_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['username']) ? $settings['username'] : '';
        ?>
        <input 
            type="text" 
            name="vernal_contentum_settings[username]" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
        />
        <p class="description"><?php _e('Your Vernal Contentum username.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_password_field() {
        ?>
        <input 
            type="password" 
            name="vernal_contentum_settings[password]" 
            value="" 
            class="regular-text"
            placeholder="<?php _e('Leave blank to keep current password', 'vernal-contentum'); ?>"
        />
        <p class="description"><?php _e('Your Vernal Contentum password. Leave blank to keep the current password.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_api_key_field() {
        $api_key = get_option('vernal_contentum_api_key', '');
        ?>
        <input 
            type="text" 
            value="<?php echo esc_attr($api_key); ?>" 
            class="regular-text" 
            readonly
            onclick="this.select();"
        />
        <button type="button" class="button" onclick="this.previousElementSibling.select(); document.execCommand('copy');">
            <?php _e('Copy', 'vernal-contentum'); ?>
        </button>
        <p class="description"><?php _e('This API key is used to authenticate requests from your Vernal Contentum web app.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_vernal-contentum' !== $hook) {
            return;
        }
        
        wp_enqueue_script(
            'vernal-contentum-admin',
            VERNAL_CONTENTUM_PLUGIN_URL . 'assets/admin.js',
            array('jquery'),
            VERNAL_CONTENTUM_VERSION,
            true
        );
    }
    
    public function ajax_copy_connection_data() {
        check_ajax_referer('vernal_contentum_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'vernal-contentum')));
        }
        
        $site_url = get_site_url();
        $api_key = get_option('vernal_contentum_api_key', '');
        
        $data = array(
            'site_url' => $site_url,
            'api_key' => $api_key,
            'api_endpoint' => rest_url('vernal-contentum/v1/')
        );
        
        wp_send_json_success($data);
    }
}

