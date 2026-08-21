<?php
/**
 * Plugin Name: Vernal Contentum Bridge
 * Plugin URI: https://vernalcontentum.com
 * Description: Bridge between WordPress and Vernal Contentum web app for content creation and management
 * Version: 1.2.19
 * Author: Vernal Contentum
 * License: GPL v2 or later
 * Text Domain: vernal-contentum
 *
 * Last updated: Format partner gallery IDs into image arrays for Elementor.
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('VERNAL_CONTENTUM_VERSION', '1.2.19');
define('VERNAL_CONTENTUM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VERNAL_CONTENTUM_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VERNAL_CONTENTUM_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Initialize plugin update checker
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/studio-matt/vernal-wordpress-plugin',
    __FILE__,
    'vernal-contentum',
    1 // Check GitHub Releases about every hour (default is 12)
);

// Enable GitHub Releases support (ZIP asset required on the release)
$updateChecker->getVcsApi()->enableReleaseAssets();

// Private-repo support: define VERNAL_GITHUB_TOKEN in wp-config.php
// (fine-grained PAT with Contents: Read on this repository only).
// Public repos do not need a token.
if (defined('VERNAL_GITHUB_TOKEN') && VERNAL_GITHUB_TOKEN) {
    $updateChecker->setAuthentication(VERNAL_GITHUB_TOKEN);
}

// When an admin opens the Plugins screen, refresh update metadata at most hourly
// so new GitHub releases surface without waiting for the longer default cron gap.
add_action('load-plugins.php', function () use ($updateChecker) {
    $key = 'vernal_puc_plugins_refresh_' . md5(home_url());
    if (get_transient($key)) {
        return;
    }
    try {
        $updateChecker->checkForUpdates();
    } catch (Exception $e) {
        // Ignore checker errors; WP will retry on the normal schedule.
    }
    set_transient($key, 1, HOUR_IN_SECONDS);
});
// Include required files
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-settings.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-code-fields.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-partner-fields.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-semantic-content.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-seo-adapter.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-api.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-backend-api.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-sitemap.php';
require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-schema.php';

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

        // Semantic content + SEO adapter (before schema so authority checks work)
        Vernal_Semantic_Content::get_instance();
        Vernal_SEO_Adapter::get_instance();
        
        // Initialize schema/TOC handler
        Vernal_Schema::get_instance();
        
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

