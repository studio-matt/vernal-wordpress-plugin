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
        // Main menu page
        add_menu_page(
            __('Vernal Contentum', 'vernal-contentum'),
            __('Vernal Contentum', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum',
            array($this, 'render_settings_page'),
            'dashicons-admin-links',
            30
        );
        
        // Submenu: Connection (same as main page)
        add_submenu_page(
            'vernal-contentum',
            __('Connection', 'vernal-contentum'),
            __('Connection', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum',
            array($this, 'render_settings_page')
        );
        
        // Submenu: Content Integration (Sitemap & Categories) - Second position
        add_submenu_page(
            'vernal-contentum',
            __('Content Integration', 'vernal-contentum'),
            __('Content Integration', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum-integration',
            array($this, 'render_integration_page')
        );
        
        // Submenu: Sitemap & Schema Settings
        add_submenu_page(
            'vernal-contentum',
            __('Sitemap & Schema', 'vernal-contentum'),
            __('Sitemap & Schema', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum-schema',
            array($this, 'render_schema_settings_page')
        );
        
        // Submenu: API Endpoints - Last position
        add_submenu_page(
            'vernal-contentum',
            __('API Endpoints', 'vernal-contentum'),
            __('API Endpoints', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum-api',
            array($this, 'render_api_endpoints_page')
        );
    }
    
    public function register_settings() {
        register_setting('vernal_contentum_settings', 'vernal_contentum_settings', array($this, 'sanitize_settings'));
        register_setting('vernal_contentum_integration', 'vernal_contentum_integration', array($this, 'sanitize_integration_settings'));
        
        add_settings_section(
            'vernal_connection_section',
            __('Connection Settings', 'vernal-contentum'),
            array($this, 'render_connection_section'),
            'vernal-contentum'
        );
        
        // Integration settings section
        add_settings_section(
            'vernal_integration_section',
            __('Content Integration Settings', 'vernal-contentum'),
            array($this, 'render_integration_section'),
            'vernal-contentum-integration'
        );
        
        add_settings_field(
            'vernal_enable_sitemap',
            __('Enable Sitemap Exposure', 'vernal-contentum'),
            array($this, 'render_enable_sitemap_field'),
            'vernal-contentum-integration',
            'vernal_integration_section'
        );
        
        add_settings_field(
            'vernal_enable_categories',
            __('Enable Category Exposure', 'vernal-contentum'),
            array($this, 'render_enable_categories_field'),
            'vernal-contentum-integration',
            'vernal_integration_section'
        );
        
        add_settings_field(
            'vernal_api_key',
            __('API Key', 'vernal-contentum'),
            array($this, 'render_api_key_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        // Backend connection settings (for WordPress → Backend authentication)
        add_settings_field(
            'vernal_backend_url',
            __('Backend API URL', 'vernal-contentum'),
            array($this, 'render_backend_url_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        add_settings_field(
            'vernal_backend_api_key',
            __('Backend API Key', 'vernal-contentum'),
            array($this, 'render_backend_api_key_field'),
            'vernal-contentum',
            'vernal_connection_section'
        );
        
        // Sitemap/Schema Settings Section (for schema settings page)
        add_settings_section(
            'vernal_sitemap_section',
            __('Sitemap & Schema Settings', 'vernal-contentum'),
            array($this, 'render_sitemap_section'),
            'vernal-contentum-schema'
        );
        
        add_settings_field(
            'vernal_show_toc',
            __('Show Table of Contents', 'vernal-contentum'),
            array($this, 'render_show_toc_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
        );
        
        add_settings_field(
            'vernal_toc_label',
            __('TOC Label', 'vernal-contentum'),
            array($this, 'render_toc_label_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
        );
        
        add_settings_field(
            'vernal_toc_style',
            __('TOC Style', 'vernal-contentum'),
            array($this, 'render_toc_style_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
        );
        
        add_settings_field(
            'vernal_show_schema',
            __('Show Schema JSON-LD', 'vernal-contentum'),
            array($this, 'render_show_schema_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
        );
        
        add_settings_field(
            'vernal_use_site_logo',
            __('Use Site Logo', 'vernal-contentum'),
            array($this, 'render_use_site_logo_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
        );
        
        add_settings_field(
            'vernal_logo_url',
            __('Custom Logo URL', 'vernal-contentum'),
            array($this, 'render_logo_url_field'),
            'vernal-contentum-schema',
            'vernal_sitemap_section'
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
        
        if (isset($input['backend_url'])) {
            $sanitized['backend_url'] = esc_url_raw($input['backend_url']);
        }
        
        if (isset($input['backend_api_key'])) {
            // Only update if API key is not empty (to allow keeping existing key)
            if (!empty($input['backend_api_key'])) {
                $sanitized['backend_api_key'] = sanitize_text_field($input['backend_api_key']);
            }
        }
        
        // Sitemap/Schema settings
        if (isset($input['show_toc'])) {
            $sanitized['show_toc'] = !empty($input['show_toc']) ? 1 : 0;
        }
        
        if (isset($input['toc_label'])) {
            $sanitized['toc_label'] = sanitize_text_field($input['toc_label']);
        }
        
        if (isset($input['toc_style'])) {
            $sanitized['toc_style'] = in_array($input['toc_style'], array('bullets', 'numbers')) ? $input['toc_style'] : 'bullets';
        }
        
        if (isset($input['show_schema'])) {
            $sanitized['show_schema'] = !empty($input['show_schema']) ? 1 : 0;
        }
        
        if (isset($input['use_site_logo'])) {
            $sanitized['use_site_logo'] = !empty($input['use_site_logo']) ? 1 : 0;
        }
        
        if (isset($input['logo_url'])) {
            $sanitized['logo_url'] = esc_url_raw($input['logo_url']);
        }
        
        return $sanitized;
    }
    
    public function sanitize_integration_settings($input) {
        $sanitized = array();
        
        if (isset($input['enable_sitemap'])) {
            $sanitized['enable_sitemap'] = !empty($input['enable_sitemap']) ? 1 : 0;
        }
        
        if (isset($input['enable_categories'])) {
            $sanitized['enable_categories'] = !empty($input['enable_categories']) ? 1 : 0;
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
                <p><?php _e('Copy the connection data below and paste it into your Vernal dashboard to connect this WordPress site:', 'vernal-contentum'); ?></p>
                
                <div style="margin: 15px 0;">
                    <label for="vernal-connection-data" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        <?php _e('Connection Data:', 'vernal-contentum'); ?>
                    </label>
                    <textarea 
                        id="vernal-connection-data" 
                        readonly 
                        style="width: 100%; height: 120px; font-family: monospace; padding: 10px;"
                    ><?php 
                        // Format matching Infinite Web: 4 fields for easy paste
                        $wp_admin_url = trailingslashit($site_url) . 'wp-admin/';
                        echo esc_textarea(json_encode(array(
                            'wp_admin_url' => $wp_admin_url,
                            'website_url' => $site_url,
                            'admin_username' => $wp_username,
                            'activation_key' => $api_key,
                            // Legacy fields for backward compatibility
                            'site_url' => $site_url,
                            'username' => $wp_username,
                            'api_key' => $api_key,
                            'app_password' => $api_key,
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
        </div>
        <?php
    }
    
    /**
     * Render Schema Settings page
     */
    public function render_schema_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Auto-populate logo URL if not set and site logo exists
        $settings = get_option('vernal_contentum_settings', array());
        if (empty($settings['logo_url'])) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                if ($logo_url) {
                    $settings['logo_url'] = $logo_url;
                    update_option('vernal_contentum_settings', $settings);
                }
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('vernal_contentum_settings');
                do_settings_sections('vernal-contentum-schema');
                submit_button(__('Save Settings', 'vernal-contentum'));
                ?>
            </form>
        </div>
        <?php
    }
    
    /**
     * Render API Endpoints page
     */
    public function render_api_endpoints_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $api_key = get_option('vernal_contentum_api_key', '');
        $site_url = get_site_url();
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="vernal-info-box" style="background: #fff; border-left: 4px solid #2271b1; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php _e('Available API Endpoints', 'vernal-contentum'); ?></h2>
                <p><?php _e('Use these endpoints in Vernal to interact with WordPress:', 'vernal-contentum'); ?></p>
                
                <table class="widefat" style="margin-top: 15px;">
                    <thead>
                        <tr>
                            <th style="width: 150px;"><?php _e('Endpoint', 'vernal-contentum'); ?></th>
                            <th style="width: 80px;"><?php _e('Method', 'vernal-contentum'); ?></th>
                            <th><?php _e('URL', 'vernal-contentum'); ?></th>
                            <th><?php _e('Description', 'vernal-contentum'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><strong>Sitemap</strong></td>
                            <td>GET</td>
                            <td><code><?php echo esc_url(rest_url('vernal-contentum/v1/sitemap')); ?></code></td>
                            <td><?php _e('Get comprehensive sitemap data (posts, pages, categories, tags, authors)', 'vernal-contentum'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Categories</strong></td>
                            <td>GET</td>
                            <td><code><?php echo esc_url(rest_url('vernal-contentum/v1/categories')); ?></code></td>
                            <td><?php _e('Get all post categories for dropdown selection', 'vernal-contentum'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Authors</strong></td>
                            <td>GET</td>
                            <td><code><?php echo esc_url(rest_url('vernal-contentum/v1/authors')); ?></code></td>
                            <td><?php _e('Get all authors for dropdown selection', 'vernal-contentum'); ?></td>
                        </tr>
                        <tr>
                            <td><strong>Create Post</strong></td>
                            <td>POST</td>
                            <td><code><?php echo esc_url(rest_url('vernal-contentum/v1/posts')); ?></code></td>
                            <td><?php _e('Create a new WordPress post', 'vernal-contentum'); ?></td>
                        </tr>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                    <h3><?php _e('Authentication', 'vernal-contentum'); ?></h3>
                    <p><?php _e('All API requests require authentication using the API key in the request header:', 'vernal-contentum'); ?></p>
                    <code style="display: block; padding: 10px; background: #fff; margin-top: 10px;">
                        X-API-Key: <?php echo esc_html($api_key); ?>
                    </code>
                </div>
                
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #ffb900; border-radius: 4px;">
                    <h3><?php _e('Example Request', 'vernal-contentum'); ?></h3>
                    <pre style="background: #fff; padding: 15px; overflow-x: auto; margin-top: 10px;"><code>fetch('<?php echo esc_url(rest_url('vernal-contentum/v1/categories')); ?>', {
  headers: {
    'X-API-Key': '<?php echo esc_html($api_key); ?>'
  }
})
.then(response => response.json())
.then(data => console.log(data));</code></pre>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Content Integration page
     */
    public function render_integration_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="vernal-info-box" style="background: #fff; border-left: 4px solid #2271b1; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php _e('Content Integration with Vernal', 'vernal-contentum'); ?></h2>
                <p><?php _e('Enable these features to allow Vernal to access your WordPress content for better content planning and automated posting.', 'vernal-contentum'); ?></p>
            </div>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('vernal_contentum_integration');
                do_settings_sections('vernal-contentum-integration');
                submit_button(__('Save Settings', 'vernal-contentum'));
                ?>
            </form>
        </div>
        <?php
    }
    
    public function render_integration_section() {
        echo '<p>' . __('Configure which content data Vernal can access from your WordPress site.', 'vernal-contentum') . '</p>';
    }
    
    public function render_enable_sitemap_field() {
        $integration = get_option('vernal_contentum_integration', array());
        $value = isset($integration['enable_sitemap']) ? $integration['enable_sitemap'] : 0;
        $site_url = get_site_url();
        ?>
        <label>
        <input 
                type="checkbox" 
                name="vernal_contentum_integration[enable_sitemap]" 
                value="1" 
                <?php checked($value, 1); ?>
            />
            <?php _e('Enable sitemap exposure to Vernal', 'vernal-contentum'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, Vernal can access your sitemap data to analyze content coverage and identify gaps. This will also pre-populate the Site Base URL in Site Builder campaigns.', 'vernal-contentum'); ?>
            <?php if ($value): ?>
                <br><strong><?php _e('Sitemap URL:', 'vernal-contentum'); ?></strong> 
                <code><?php echo esc_url(rest_url('vernal-contentum/v1/sitemap')); ?></code>
            <?php endif; ?>
        </p>
        <?php
    }
    
    public function render_enable_categories_field() {
        $integration = get_option('vernal_contentum_integration', array());
        $value = isset($integration['enable_categories']) ? $integration['enable_categories'] : 0;
        ?>
        <label>
        <input 
                type="checkbox" 
                name="vernal_contentum_integration[enable_categories]" 
                value="1" 
                <?php checked($value, 1); ?>
            />
            <?php _e('Enable category exposure to Vernal', 'vernal-contentum'); ?>
        </label>
        <p class="description">
            <?php _e('When enabled, your WordPress categories will be available in Vernal for content planning. Content created in Vernal can be automatically posted to the selected category.', 'vernal-contentum'); ?>
            <?php if ($value): ?>
                <br><strong><?php _e('Categories API:', 'vernal-contentum'); ?></strong> 
                <code><?php echo esc_url(rest_url('vernal-contentum/v1/categories')); ?></code>
            <?php endif; ?>
        </p>
        <?php
    }
    
    public function render_connection_section() {
        echo '<p>' . __('Configure your connection to the Vernal backend API.', 'vernal-contentum') . '</p>';
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
        <p class="description"><?php _e('This API key is used to authenticate requests from Vernal to WordPress (inbound).', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_backend_url_field() {
        $settings = get_option('vernal_contentum_settings', array());
        // Check wp-config.php first (recommended), fallback to wp_options
        $value = defined('VERNAL_BACKEND_URL') 
            ? VERNAL_BACKEND_URL 
            : (isset($settings['backend_url']) ? $settings['backend_url'] : '');
        $is_from_config = defined('VERNAL_BACKEND_URL');
        ?>
        <input 
            type="url" 
            name="vernal_contentum_settings[backend_url]" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
            placeholder="https://themachine.vernalcontentum.com"
            <?php echo $is_from_config ? 'readonly' : ''; ?>
        />
        <?php if ($is_from_config): ?>
            <span class="description" style="color: #46b450;">
                <?php _e('✓ Set via wp-config.php constant', 'vernal-contentum'); ?>
            </span>
        <?php endif; ?>
        <p class="description">
            <?php _e('The backend API URL for WordPress → Backend authentication (outbound).', 'vernal-contentum'); ?>
            <?php if (!$is_from_config): ?>
                <br><strong><?php _e('Recommended:', 'vernal-contentum'); ?></strong> 
                <?php _e('Set VERNAL_BACKEND_URL constant in wp-config.php for better security.', 'vernal-contentum'); ?>
            <?php endif; ?>
        </p>
        <?php
    }
    
    public function render_backend_api_key_field() {
        $settings = get_option('vernal_contentum_settings', array());
        // Check wp-config.php first (recommended), fallback to wp_options
        $value = defined('VERNAL_BACKEND_API_KEY') 
            ? VERNAL_BACKEND_API_KEY 
            : (isset($settings['backend_api_key']) ? $settings['backend_api_key'] : '');
        $is_from_config = defined('VERNAL_BACKEND_API_KEY');
        $has_value = !empty($value);
        ?>
        <input 
            type="password" 
            name="vernal_contentum_settings[backend_api_key]" 
            value="" 
            class="regular-text"
            placeholder="<?php _e('Enter API key to update, or leave blank to keep current', 'vernal-contentum'); ?>"
            <?php echo $is_from_config ? 'readonly' : ''; ?>
        />
        <?php if ($is_from_config): ?>
            <span class="description" style="color: #46b450;">
                <?php _e('✓ Set via wp-config.php constant', 'vernal-contentum'); ?>
            </span>
        <?php elseif ($has_value): ?>
            <span class="description" style="color: #2271b1;">
                <?php _e('✓ API key is configured (hidden for security)', 'vernal-contentum'); ?>
            </span>
        <?php endif; ?>
        <button type="button" class="button" id="test-backend-connection" style="margin-left: 10px;">
            <?php _e('Test Connection', 'vernal-contentum'); ?>
        </button>
        <span id="backend-connection-status" style="margin-left: 10px;"></span>
        <p class="description">
            <?php _e('API key for WordPress → Backend authentication (outbound). Get this from Backend Admin → Plugins → API Keys.', 'vernal-contentum'); ?>
            <?php if (!$is_from_config): ?>
                <br><strong><?php _e('Recommended:', 'vernal-contentum'); ?></strong> 
                <?php _e('Set VERNAL_BACKEND_API_KEY constant in wp-config.php for better security.', 'vernal-contentum'); ?>
            <?php endif; ?>
        </p>
        <?php
    }
    
    public function render_sitemap_section() {
        echo '<p>' . __('Configure sitemap and schema JSON-LD output settings.', 'vernal-contentum') . '</p>';
    }
    
    public function render_show_toc_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['show_toc']) ? $settings['show_toc'] : 0;
        ?>
        <label>
            <input 
                type="checkbox" 
                name="vernal_contentum_settings[show_toc]" 
                value="1" 
                <?php checked($value, 1); ?>
            />
            <?php _e('Enable Table of Contents on posts and pages', 'vernal-contentum'); ?>
        </label>
        <p class="description"><?php _e('Automatically generates a table of contents from H2/H3 headings.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_toc_label_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['toc_label']) ? $settings['toc_label'] : 'In This Article...';
        ?>
        <input 
            type="text" 
            name="vernal_contentum_settings[toc_label]" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
        />
        <p class="description"><?php _e('Label text displayed above the table of contents.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_toc_style_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['toc_style']) ? $settings['toc_style'] : 'bullets';
        ?>
        <select name="vernal_contentum_settings[toc_style]">
            <option value="bullets" <?php selected($value, 'bullets'); ?>><?php _e('Bullets (Unordered List)', 'vernal-contentum'); ?></option>
            <option value="numbers" <?php selected($value, 'numbers'); ?>><?php _e('Numbers (Ordered List)', 'vernal-contentum'); ?></option>
        </select>
        <p class="description"><?php _e('Display style for the table of contents.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_show_schema_field() {
        $settings = get_option('vernal_contentum_settings', array());
        // Default to enabled (1) for better SEO
        $value = isset($settings['show_schema']) ? $settings['show_schema'] : 1;
        ?>
        <label>
            <input 
                type="checkbox" 
                name="vernal_contentum_settings[show_schema]" 
                value="1" 
                <?php checked($value, 1); ?>
            />
            <?php _e('Enable Schema.org JSON-LD markup', 'vernal-contentum'); ?>
        </label>
        <p class="description"><?php _e('Adds structured data (Article schema, BreadcrumbList) to posts and pages for better SEO. Enabled by default.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_use_site_logo_field() {
        $settings = get_option('vernal_contentum_settings', array());
        $value = isset($settings['use_site_logo']) ? $settings['use_site_logo'] : 1;
        ?>
        <label>
            <input 
                type="checkbox" 
                name="vernal_contentum_settings[use_site_logo]" 
                value="1" 
                <?php checked($value, 1); ?>
            />
            <?php _e('Use WordPress site logo in schema', 'vernal-contentum'); ?>
        </label>
        <p class="description"><?php _e('If unchecked, use the custom logo URL below.', 'vernal-contentum'); ?></p>
        <?php
    }
    
    public function render_logo_url_field() {
        $settings = get_option('vernal_contentum_settings', array());
        
        // Auto-populate with site logo if not set
        if (empty($settings['logo_url'])) {
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
                if ($logo_url) {
                    $settings['logo_url'] = $logo_url;
                    $value = $logo_url;
                } else {
                    $value = '';
                }
            } else {
                $value = '';
            }
        } else {
            $value = $settings['logo_url'];
        }
        
        ?>
        <input 
            type="url" 
            name="vernal_contentum_settings[logo_url]" 
            value="<?php echo esc_attr($value); ?>" 
            class="regular-text"
            placeholder="https://example.com/logo.png"
        />
        <button type="button" class="button" onclick="this.previousElementSibling.value='<?php echo esc_js($this->get_site_logo_url()); ?>';">
            <?php _e('Use Site Logo', 'vernal-contentum'); ?>
        </button>
        <p class="description">
            <?php _e('Logo URL for schema markup (used when "Use Site Logo" is unchecked).', 'vernal-contentum'); ?>
            <?php if ($this->get_site_logo_url()): ?>
                <br><strong><?php _e('Current site logo:', 'vernal-contentum'); ?></strong> 
                <code><?php echo esc_html($this->get_site_logo_url()); ?></code>
            <?php endif; ?>
        </p>
        <?php
    }
    
    /**
     * Get site logo URL
     */
    private function get_site_logo_url() {
        $custom_logo_id = get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');
            return $logo_url ? $logo_url : '';
        }
        return '';
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
        
        // Localize script for AJAX
        wp_localize_script('vernal-contentum-admin', 'vernalContentum', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vernal_contentum_nonce')
        ));
    }
    
    public function ajax_copy_connection_data() {
        check_ajax_referer('vernal_contentum_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'vernal-contentum')));
        }
        
        $site_url = get_site_url();
        $api_key = get_option('vernal_contentum_api_key', '');
        
        // Get current WordPress admin user (for app password generation)
        $current_user = wp_get_current_user();
        $username = $current_user->user_login;
        
        // Generate or get app password (application password for REST API)
        // WordPress application passwords are the recommended way for API auth
        // For now, we'll use the API key as the app password equivalent
        $app_password = $api_key; // This is what Vernal will use to authenticate to WP
        
        // Format as JSON for easy pasting
        $data = array(
            'site_url' => $site_url,
            'username' => $username,
            'api_key' => $api_key,
            'app_password' => $app_password,
            'api_endpoint' => rest_url('vernal-contentum/v1/')
        );
        
        // Return both JSON (for programmatic use) and formatted string (for manual copy)
        wp_send_json_success(array(
            'json' => $data,
            'formatted' => json_encode($data, JSON_PRETTY_PRINT),
            'compact' => json_encode($data) // Single line for pasting
        ));
    }
    
    public function ajax_test_backend_connection() {
        check_ajax_referer('vernal_contentum_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'vernal-contentum')));
        }
        
        // Test the connection
        $result = Vernal_Backend_API::test_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ));
        }
        
        wp_send_json_success(array(
            'message' => __('Connection successful!', 'vernal-contentum'),
            'data' => $result
        ));
    }
}

