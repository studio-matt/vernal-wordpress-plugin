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
        add_action('wp_ajax_vernal_test_backend_connection', array($this, 'ajax_test_backend_connection'));
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
        
        // Submenu: Scheduled Content
        add_submenu_page(
            'vernal-contentum',
            __('Scheduled Content', 'vernal-contentum'),
            __('Scheduled Content', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum-scheduled',
            array($this, 'render_scheduled_content_page')
        );

        // Submenu: Show Retrofit (temporary ops UI for legacy landings)
        add_submenu_page(
            'vernal-contentum',
            __('Show Retrofit', 'vernal-contentum'),
            __('Show Retrofit', 'vernal-contentum'),
            'manage_options',
            'vernal-contentum-show-retrofit',
            array($this, 'render_show_retrofit_page')
        );
    }
    
    public function register_settings() {
        register_setting('vernal_contentum_settings', 'vernal_contentum_settings', array($this, 'sanitize_settings'));
        register_setting('vernal_contentum_integration', 'vernal_contentum_integration', array($this, 'sanitize_integration_settings'));
        
        add_settings_section(
            'vernal_connection_section',
            '',
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
        
        // Connection credentials are managed by Vernal automatically — no admin form fields.
        
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
        // Merge with existing so blank password/key fields do not wipe stored values.
        $existing = get_option('vernal_contentum_settings', array());
        if (!is_array($existing)) {
            $existing = array();
        }
        $sanitized = $existing;
        if (!is_array($input)) {
            return $sanitized;
        }
        
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
        if (!is_array($settings)) {
            $settings = array();
        }
        $api_key = get_option('vernal_contentum_api_key', '');
        $site_url = get_site_url();
        $backend_configured = class_exists('Vernal_Backend_API') && Vernal_Backend_API::is_configured();
        $outbound_status = isset($settings['outbound_status']) ? $settings['outbound_status'] : ($backend_configured ? 'unknown' : 'not_configured');
        $linked = ($outbound_status === 'connected') || ($backend_configured && in_array($outbound_status, array('configured', 'unknown'), true));
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="vernal-connection-box" style="background: #fff; border: 1px solid #ccd0d4; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <h2><?php _e('Connect this site to Vernal', 'vernal-contentum'); ?></h2>
                <p><?php _e('In Vernal → Account Settings → WordPress → Add site, paste the Site URL and API Key below. Vernal finishes the rest automatically.', 'vernal-contentum'); ?></p>
                
                <div style="margin: 15px 0;">
                    <label for="vernal-site-url" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        <?php _e('Site URL', 'vernal-contentum'); ?>
                    </label>
                    <input
                        type="text"
                        id="vernal-site-url"
                        class="regular-text"
                        readonly
                        value="<?php echo esc_attr($site_url); ?>"
                        onclick="this.select();"
                        style="max-width: 480px;"
                    />
                    <button type="button" class="button vernal-copy-field" data-target="vernal-site-url">
                        <?php _e('Copy', 'vernal-contentum'); ?>
                    </button>
                </div>
                
                <div style="margin: 15px 0;">
                    <label for="vernal-inbound-api-key" style="display: block; margin-bottom: 5px; font-weight: 600;">
                        <?php _e('API Key', 'vernal-contentum'); ?>
                    </label>
                    <input
                        type="text"
                        id="vernal-inbound-api-key"
                        class="regular-text"
                        readonly
                        value="<?php echo esc_attr($api_key); ?>"
                        onclick="this.select();"
                        style="max-width: 480px; font-family: monospace;"
                    />
                    <button type="button" class="button vernal-copy-field" data-target="vernal-inbound-api-key">
                        <?php _e('Copy', 'vernal-contentum'); ?>
                    </button>
                    <p class="description">
                        <?php _e('Treat this like a password. Anyone with it can publish to this site through Vernal.', 'vernal-contentum'); ?>
                    </p>
                </div>
                
                <div id="vernal-connection-status-panel" style="margin-top: 20px; padding: 12px 14px; background: #f6f7f7; border-left: 4px solid <?php echo ($outbound_status === 'connected') ? '#46b450' : '#2271b1'; ?>;">
                    <strong><?php _e('Status', 'vernal-contentum'); ?></strong>
                    <p style="margin: 8px 0 0;">
                        <?php if (!empty($api_key) && $outbound_status === 'connected'): ?>
                            <span style="color: #46b450; font-weight: 600;" id="vernal-outbound-status-label"><?php _e('✓ Connected to Vernal', 'vernal-contentum'); ?></span>
                        <?php elseif (!empty($api_key) && $linked): ?>
                            <span style="color: #46b450;" id="vernal-outbound-status-label"><?php _e('Connected to Vernal', 'vernal-contentum'); ?></span>
                        <?php elseif (!empty($api_key)): ?>
                            <span style="color: #646970;" id="vernal-outbound-status-label"><?php _e('Waiting for connection from Vernal', 'vernal-contentum'); ?></span>
                        <?php else: ?>
                            <span style="color: #dc3232;" id="vernal-outbound-status-label"><?php _e('Not ready — reinstall the plugin', 'vernal-contentum'); ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($backend_configured): ?>
                        <p style="margin: 10px 0 0;">
                            <button type="button" class="button" id="test-backend-connection">
                                <?php _e('Check connection', 'vernal-contentum'); ?>
                            </button>
                            <span id="backend-connection-status" style="margin-left: 10px;"></span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
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
     * Render Scheduled Content page
     */
    public function render_scheduled_content_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option('vernal_contentum_settings', array());
        $backend_url = defined('VERNAL_BACKEND_URL') ? VERNAL_BACKEND_URL : (isset($settings['backend_url']) ? $settings['backend_url'] : '');
        $backend_api_key = defined('VERNAL_BACKEND_API_KEY') ? VERNAL_BACKEND_API_KEY : (isset($settings['backend_api_key']) ? $settings['backend_api_key'] : '');
        
        // Fetch scheduled posts from backend
        $scheduled_posts = array();
        $error_message = '';
        
        if (!empty($backend_url) && !empty($backend_api_key)) {
            $api_url = trailingslashit($backend_url) . 'scheduled-posts';
            $response = wp_remote_get($api_url, array(
                'headers' => array(
                    'X-API-Key' => $backend_api_key,
                    'Content-Type' => 'application/json',
                ),
                'timeout' => 30,
            ));
            
            if (!is_wp_error($response)) {
                $body = wp_remote_retrieve_body($response);
                $data = json_decode($body, true);
                
                if (isset($data['status']) && $data['status'] === 'success' && isset($data['message']['posts'])) {
                    $scheduled_posts = $data['message']['posts'];
                } else {
                    $error_message = __('Failed to fetch scheduled posts. Please check your backend connection settings.', 'vernal-contentum');
                }
            } else {
                $error_message = __('Error connecting to backend: ', 'vernal-contentum') . $response->get_error_message();
            }
        } else {
            $error_message = __('Backend API URL and API Key must be configured to view scheduled content.', 'vernal-contentum');
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (!empty($error_message)): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($error_message); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (empty($backend_url) || empty($backend_api_key)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('Please configure your Backend API URL and API Key in Connection Settings to view scheduled content.', 'vernal-contentum'); ?></p>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($scheduled_posts)): ?>
                <div class="vernal-info-box" style="background: #fff; border-left: 4px solid #2271b1; padding: 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                    <h2><?php _e('Scheduled Content from Vernal', 'vernal-contentum'); ?></h2>
                    <p><?php _e('Content scheduled in your Vernal dashboard that will be posted to WordPress:', 'vernal-contentum'); ?></p>
                    
                    <table class="widefat" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th style="width: 200px;"><?php _e('Title', 'vernal-contentum'); ?></th>
                                <th style="width: 100px;"><?php _e('Platform', 'vernal-contentum'); ?></th>
                                <th style="width: 150px;"><?php _e('Schedule Time', 'vernal-contentum'); ?></th>
                                <th style="width: 100px;"><?php _e('Status', 'vernal-contentum'); ?></th>
                                <th><?php _e('Preview', 'vernal-contentum'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scheduled_posts as $post): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($post['title'] ?: __('Untitled', 'vernal-contentum')); ?></strong></td>
                                    <td><?php echo esc_html(ucfirst($post['platform'] ?: 'N/A')); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($post['schedule_time'])) {
                                            $schedule_date = new DateTime($post['schedule_time']);
                                            echo esc_html($schedule_date->format('Y-m-d H:i'));
                                        } else {
                                            echo __('Not scheduled', 'vernal-contentum');
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <span style="padding: 3px 8px; border-radius: 3px; background: <?php echo $post['status'] === 'scheduled' ? '#46b450' : ($post['status'] === 'published' ? '#2271b1' : '#f0f0f1'); ?>; color: <?php echo $post['status'] === 'scheduled' ? '#fff' : '#000'; ?>;">
                                            <?php echo esc_html(ucfirst($post['status'] ?: 'draft')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($post['content'])): ?>
                                            <details>
                                                <summary style="cursor: pointer; color: #2271b1;"><?php _e('View Content', 'vernal-contentum'); ?></summary>
                                                <div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px; max-height: 200px; overflow-y: auto;">
                                                    <?php echo wp_kses_post(wpautop(substr($post['content'], 0, 500) . (strlen($post['content']) > 500 ? '...' : ''))); ?>
                                                </div>
                                            </details>
                                        <?php else: ?>
                                            <span style="color: #999;"><?php _e('No content', 'vernal-contentum'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif (empty($error_message) && !empty($backend_url) && !empty($backend_api_key)): ?>
                <div class="notice notice-info">
                    <p><?php _e('No scheduled content found. Schedule content in your Vernal dashboard to see it here.', 'vernal-contentum'); ?></p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Temporary Show Retrofit ops UI — lists local show landings and drives Machine reconcile.
     * Intelligence lives on Machine; this page only renders diagnostics and posts actions.
     */
    public function render_show_retrofit_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = get_option('vernal_contentum_settings', array());
        $backend_url = defined('VERNAL_BACKEND_URL') ? VERNAL_BACKEND_URL : (isset($settings['backend_url']) ? $settings['backend_url'] : '');
        $backend_api_key = defined('VERNAL_BACKEND_API_KEY') ? VERNAL_BACKEND_API_KEY : (isset($settings['backend_api_key']) ? $settings['backend_api_key'] : '');
        $destination_id = isset($_GET['destination_id']) ? intval($_GET['destination_id']) : intval(get_option('vernal_contentum_retrofit_destination_id', 0));
        $category_slug = isset($_GET['category']) ? sanitize_title($_GET['category']) : 'shows';
        $notice = '';
        $notice_type = 'info';

        // Handle actions from this page (POST).
        if (!empty($_POST['vernal_retrofit_action']) && check_admin_referer('vernal_show_retrofit')) {
            $action = sanitize_text_field($_POST['vernal_retrofit_action']);
            $wp_post_id = isset($_POST['wp_post_id']) ? intval($_POST['wp_post_id']) : 0;
            $destination_id = isset($_POST['destination_id']) ? intval($_POST['destination_id']) : $destination_id;
            if ($destination_id > 0) {
                update_option('vernal_contentum_retrofit_destination_id', $destination_id, false);
            }
            $payload = array(
                'destination_id' => $destination_id,
                'wp_post_id' => $wp_post_id,
            );
            $path = '';
            if ($action === 'discover' && $wp_post_id && $destination_id) {
                $path = 'podcasts/retrofit/discover';
            } elseif ($action === 'ocr' && $wp_post_id && $destination_id) {
                $path = 'podcasts/retrofit/ocr';
            } elseif ($action === 'confirm' && $wp_post_id && $destination_id) {
                $path = 'podcasts/retrofit/confirm';
                $payload['show_number'] = intval($_POST['show_number'] ?? 0);
                $payload['force'] = !empty($_POST['force']);
            } elseif ($action === 'dry_run' && $wp_post_id && $destination_id) {
                $path = 'podcasts/retrofit/reconcile';
                $payload['dry_run'] = true;
            } elseif ($action === 'reformat' && $wp_post_id && $destination_id) {
                $path = 'podcasts/retrofit/reconcile';
                $payload['dry_run'] = false;
            } elseif ($action === 'queue' && !empty($_POST['retrofit_id'])) {
                $path = 'podcasts/retrofit/queue';
                $payload = array('retrofit_id' => intval($_POST['retrofit_id']));
            } elseif ($action === 'save_destination') {
                $notice = __('Destination ID saved.', 'vernal-contentum');
            }

            if ($path && $backend_url && $backend_api_key) {
                $api_url = trailingslashit($backend_url) . ltrim($path, '/');
                $response = wp_remote_post($api_url, array(
                    'headers' => array(
                        'X-API-Key' => $backend_api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'timeout' => 180,
                    'body' => wp_json_encode($payload),
                ));
                if (is_wp_error($response)) {
                    $notice = $response->get_error_message();
                    $notice_type = 'error';
                } else {
                    $code = wp_remote_retrieve_response_code($response);
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if ($code >= 200 && $code < 300) {
                        $notice = sprintf(
                            /* translators: 1: action name 2: status */
                            __('Action %1$s completed (%2$s).', 'vernal-contentum'),
                            esc_html($action),
                            esc_html(isset($body['status']) ? $body['status'] : 'ok')
                        );
                        $notice_type = 'success';
                        if (!empty($body['error'])) {
                            $notice .= ' ' . esc_html(is_string($body['error']) ? $body['error'] : wp_json_encode($body['error']));
                            $notice_type = 'warning';
                        }
                    } else {
                        $notice = sprintf(__('Backend returned HTTP %d', 'vernal-contentum'), $code);
                        if (is_array($body)) {
                            $notice .= ': ' . esc_html(wp_json_encode($body));
                        }
                        $notice_type = 'error';
                    }
                }
            } elseif ($path) {
                $notice = __('Backend URL and API key required.', 'vernal-contentum');
                $notice_type = 'error';
            }
        }

        // Local list for UX (Machine still re-fetches via GET /shows/{id} on reconcile).
        $cat = get_category_by_slug($category_slug);
        $args = array(
            'post_type' => 'post',
            'post_status' => array('publish', 'draft', 'private', 'pending'),
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        if ($cat && !is_wp_error($cat)) {
            $args['cat'] = intval($cat->term_id);
        }
        $posts = get_posts($args);

        // Machine rows for status overlay
        $machine_rows = array();
        if ($backend_url && $backend_api_key) {
            $rows_url = trailingslashit($backend_url) . 'podcasts/retrofit/rows?limit=200';
            if ($destination_id > 0) {
                $rows_url .= '&destination_id=' . intval($destination_id);
            }
            $resp = wp_remote_get($rows_url, array(
                'headers' => array('X-API-Key' => $backend_api_key),
                'timeout' => 30,
            ));
            if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
                $data = json_decode(wp_remote_retrieve_body($resp), true);
                if (!empty($data['rows']) && is_array($data['rows'])) {
                    foreach ($data['rows'] as $row) {
                        if (!empty($row['wp_post_id'])) {
                            $machine_rows[intval($row['wp_post_id'])] = $row;
                        }
                    }
                }
            }
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php _e('Temporary ops UI for aligning legacy show landings. Machine owns diagnose/reconcile; this page renders facets and actions.', 'vernal-contentum'); ?></p>

            <?php if ($notice): ?>
                <div class="notice notice-<?php echo esc_attr($notice_type); ?>"><p><?php echo esc_html($notice); ?></p></div>
            <?php endif; ?>

            <?php if (empty($backend_url) || empty($backend_api_key)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('Configure Backend API URL and API Key under Connection to enable Machine actions.', 'vernal-contentum'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" style="margin: 12px 0;">
                <input type="hidden" name="page" value="vernal-contentum-show-retrofit" />
                <label>
                    <?php _e('Machine destination_id', 'vernal-contentum'); ?>
                    <input type="number" name="destination_id" value="<?php echo esc_attr($destination_id); ?>" min="1" style="width:100px;" />
                </label>
                <label style="margin-left:12px;">
                    <?php _e('Category slug', 'vernal-contentum'); ?>
                    <input type="text" name="category" value="<?php echo esc_attr($category_slug); ?>" style="width:120px;" />
                </label>
                <?php submit_button(__('Refresh', 'vernal-contentum'), 'secondary', '', false); ?>
            </form>

            <form method="post" style="display:inline;">
                <?php wp_nonce_field('vernal_show_retrofit'); ?>
                <input type="hidden" name="vernal_retrofit_action" value="save_destination" />
                <input type="hidden" name="destination_id" value="<?php echo esc_attr($destination_id); ?>" />
            </form>

            <table class="widefat striped" style="margin-top:16px;">
                <thead>
                    <tr>
                        <th><?php _e('Thumb', 'vernal-contentum'); ?></th>
                        <th><?php _e('Title', 'vernal-contentum'); ?></th>
                        <th><?php _e('Show #', 'vernal-contentum'); ?></th>
                        <th><?php _e('Machine status', 'vernal-contentum'); ?></th>
                        <th><?php _e('Facets', 'vernal-contentum'); ?></th>
                        <th><?php _e('Actions', 'vernal-contentum'); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($posts)): ?>
                    <tr><td colspan="6"><?php _e('No posts found in this category.', 'vernal-contentum'); ?></td></tr>
                <?php else: ?>
                    <?php foreach ($posts as $post):
                        $pid = intval($post->ID);
                        $thumb = get_the_post_thumbnail_url($pid, 'thumbnail');
                        $show_number = get_post_meta($pid, 'show_number', true);
                        $ocr = get_post_meta($pid, '_vernal_ocr_show_number', true);
                        $row = isset($machine_rows[$pid]) ? $machine_rows[$pid] : null;
                        $diag = $row && !empty($row['diagnostic']) ? $row['diagnostic'] : null;
                        ?>
                        <tr>
                            <td><?php if ($thumb): ?><img src="<?php echo esc_url($thumb); ?>" width="48" height="48" alt="" /><?php endif; ?></td>
                            <td>
                                <strong><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>"><?php echo esc_html(get_the_title($pid)); ?></a></strong><br />
                                <code>#<?php echo esc_html((string) $pid); ?></code>
                                <?php if (!empty($post->post_date)): ?>
                                    <span style="color:#666;"> · <?php echo esc_html($post->post_date); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div>meta: <strong><?php echo $show_number !== '' && $show_number !== false ? esc_html((string) $show_number) : '—'; ?></strong></div>
                                <div>ocr: <?php echo $ocr !== '' && $ocr !== false ? esc_html((string) $ocr) : '—'; ?></div>
                            </td>
                            <td>
                                <?php if ($row): ?>
                                    <code><?php echo esc_html($row['status']); ?></code>
                                    <?php if (!empty($row['show_number'])): ?>
                                        <div>#<?php echo esc_html((string) $row['show_number']); ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color:#888;"><?php _e('not discovered', 'vernal-contentum'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; line-height:1.4;">
                                <?php if ($diag): ?>
                                    <?php
                                    $facets = array(
                                        'show_number' => $diag['show_number']['status'] ?? '?',
                                        'identity' => $diag['episode_identity']['status'] ?? '?',
                                        'acf' => $diag['landing_acf']['status'] ?? '?',
                                        'enclosure' => $diag['enclosure']['status'] ?? '?',
                                        'category' => $diag['show_category']['status'] ?? '?',
                                        'campaign' => $diag['campaign']['status'] ?? '?',
                                        'articles' => isset($diag['articles'])
                                            ? (($diag['articles']['actual'] ?? 0) . '/' . ($diag['articles']['expected'] ?? 3))
                                            : '?',
                                    );
                                    foreach ($facets as $label => $val) {
                                        echo esc_html($label) . ': <strong>' . esc_html((string) $val) . '</strong><br />';
                                    }
                                    if (!empty($diag['collisions'])) {
                                        echo '<span style="color:#b32d2e;">' . esc_html__('collisions!', 'vernal-contentum') . '</span>';
                                    }
                                    ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($destination_id > 0): ?>
                                <form method="post" style="margin-bottom:4px;">
                                    <?php wp_nonce_field('vernal_show_retrofit'); ?>
                                    <input type="hidden" name="destination_id" value="<?php echo esc_attr($destination_id); ?>" />
                                    <input type="hidden" name="wp_post_id" value="<?php echo esc_attr($pid); ?>" />
                                    <button class="button button-small" name="vernal_retrofit_action" value="discover"><?php _e('Diagnose', 'vernal-contentum'); ?></button>
                                    <button class="button button-small" name="vernal_retrofit_action" value="ocr"><?php _e('OCR', 'vernal-contentum'); ?></button>
                                    <button class="button button-small" name="vernal_retrofit_action" value="dry_run"><?php _e('Dry run', 'vernal-contentum'); ?></button>
                                    <button class="button button-small" name="vernal_retrofit_action" value="reformat"><?php _e('Reformat now', 'vernal-contentum'); ?></button>
                                </form>
                                <form method="post" style="margin-bottom:4px;">
                                    <?php wp_nonce_field('vernal_show_retrofit'); ?>
                                    <input type="hidden" name="destination_id" value="<?php echo esc_attr($destination_id); ?>" />
                                    <input type="hidden" name="wp_post_id" value="<?php echo esc_attr($pid); ?>" />
                                    <input type="number" name="show_number" placeholder="#" value="<?php echo esc_attr($ocr ?: $show_number); ?>" style="width:70px;" />
                                    <button class="button button-small button-primary" name="vernal_retrofit_action" value="confirm"><?php _e('Confirm #', 'vernal-contentum'); ?></button>
                                </form>
                                <?php if ($row && !empty($row['id']) && in_array($row['status'], array('ready', 'failed', 'needs_review'), true)): ?>
                                <form method="post">
                                    <?php wp_nonce_field('vernal_show_retrofit'); ?>
                                    <input type="hidden" name="retrofit_id" value="<?php echo esc_attr($row['id']); ?>" />
                                    <button class="button button-small" name="vernal_retrofit_action" value="queue"><?php _e('Queue', 'vernal-contentum'); ?></button>
                                </form>
                                <?php endif; ?>
                                <?php else: ?>
                                    <em><?php _e('Set destination_id first', 'vernal-contentum'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
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
        // Default to enabled (1) if not set
        $value = isset($integration['enable_sitemap']) ? $integration['enable_sitemap'] : 1;
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
        // Default to enabled (1) if not set
        $value = isset($integration['enable_categories']) ? $integration['enable_categories'] : 1;
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
        // Intentionally empty — connection UI is rendered in render_settings_page().
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
        $settings = get_option('vernal_contentum_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $outbound_status = isset($settings['outbound_status']) ? $settings['outbound_status'] : '';
        $backend_configured = class_exists('Vernal_Backend_API') && Vernal_Backend_API::is_configured();
        wp_localize_script('vernal-contentum-admin', 'vernalContentum', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vernal_contentum_nonce'),
            // Auto-run the same verify as "Check connection" when Vernal has configured
            // but status is not yet the verified "connected" state.
            'auto_verify' => ($backend_configured && $outbound_status !== 'connected'),
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
        
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Unauthorized', 'vernal-contentum')));
        }
        
        // Test the connection
        $result = Vernal_Backend_API::test_connection();
        $settings = get_option('vernal_contentum_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $settings['outbound_last_tested_at'] = gmdate('c');
        
        if (is_wp_error($result)) {
            $settings['outbound_status'] = 'error';
            update_option('vernal_contentum_settings', $settings);
            wp_send_json_error(array(
                'message' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ));
        }
        
        $settings['outbound_status'] = 'connected';
        update_option('vernal_contentum_settings', $settings);
        
        wp_send_json_success(array(
            'message' => __('Connected', 'vernal-contentum'),
            'last_tested_at' => $settings['outbound_last_tested_at'],
        ));
    }
}

