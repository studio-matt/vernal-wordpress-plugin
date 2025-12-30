<?php
/**
 * Plugin Name: Vernal Contentum Bridge
 * Plugin URI: https://vernalcontentum.com
 * Description: Bridge between WordPress and Vernal Contentum web app for content creation and management
 * Version: 1.0.0
 * Author: Vernal Contentum
 * License: GPL v2 or later
 * Text Domain: vernal-contentum
 * 
 * Last updated: Testing deployment with IP address (50.6.198.220)
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VERNAL_CONTENTUM_VERSION', '1.0.0');
define('VERNAL_CONTENTUM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VERNAL_CONTENTUM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VERNAL_CONTENTUM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Initialize plugin update checker
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/studio-matt/vernal-wordpress-plugin',
    __FILE__,
    'vernal-contentum'
);

// Enable GitHub Releases support
$updateChecker->getVcsApi()->enableReleaseAssets();

// Include required files
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-settings.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-api.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-sitemap.php';

/**
 * Main plugin class
 */
class Vernal_Contentum {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->init();
    }
    
    private function init() {
        // Initialize settings
        Vernal_Settings::get_instance();
        
        // Initialize REST API
        Vernal_API::get_instance();
        
        // Initialize sitemap handler
        Vernal_Sitemap::get_instance();
        
        // Activation/Deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function activate() {
        // Generate API key on activation
        if (!get_option('vernal_contentum_api_key')) {
            update_option('vernal_contentum_api_key', $this->generate_api_key());
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function generate_api_key() {
        return 'vc_' . bin2hex(random_bytes(32));
    }
}

// Initialize the plugin
Vernal_Contentum::get_instance();

