<?php
/**
 * Vernal Backend API Client
 * 
 * Handles WordPress → Backend authentication and API requests
 * 
 * @package VernalContentum
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Vernal_Backend_API {
    
    /**
     * Get backend URL from wp-config.php constant or wp_options
     * 
     * @return string Backend API URL
     */
    public static function get_backend_url() {
        // Check wp-config.php first (recommended)
        if (defined('VERNAL_BACKEND_URL')) {
            return trailingslashit(VERNAL_BACKEND_URL);
        }
        
        // Fallback to wp_options
        $settings = get_option('vernal_contentum_settings', array());
        $url = isset($settings['backend_url']) ? $settings['backend_url'] : '';
        
        return $url ? trailingslashit($url) : '';
    }
    
    /**
     * Get backend API key from wp-config.php constant or wp_options
     * 
     * @return string API key
     */
    public static function get_api_key() {
        // Check wp-config.php first (recommended)
        if (defined('VERNAL_BACKEND_API_KEY')) {
            return VERNAL_BACKEND_API_KEY;
        }
        
        // Fallback to wp_options
        $settings = get_option('vernal_contentum_settings', array());
        return isset($settings['backend_api_key']) ? $settings['backend_api_key'] : '';
    }
    
    /**
     * Check if backend connection is configured
     * 
     * @return bool True if configured
     */
    public static function is_configured() {
        return !empty(self::get_backend_url()) && !empty(self::get_api_key());
    }
    
    /**
     * Make authenticated request to backend API
     * 
     * @param string $endpoint API endpoint (e.g., '/plugin/test')
     * @param array $args Request arguments (method, body, etc.)
     * @return array|WP_Error Response data or error
     */
    public static function request($endpoint, $args = array()) {
        $backend_url = self::get_backend_url();
        $api_key = self::get_api_key();
        
        if (empty($backend_url) || empty($api_key)) {
            return new WP_Error(
                'not_configured',
                __('Backend connection is not configured. Please set backend URL and API key in settings.', 'vernal-contentum')
            );
        }
        
        // Remove leading slash from endpoint
        $endpoint = ltrim($endpoint, '/');
        $url = $backend_url . $endpoint;
        
        // Default request arguments
        $defaults = array(
            'method' => 'GET',
            'timeout' => 30,
            'headers' => array(
                'X-API-Key' => $api_key,
                'Content-Type' => 'application/json',
            ),
        );
        
        // Merge with provided arguments
        $args = wp_parse_args($args, $defaults);
        
        // Add API key to headers
        if (!isset($args['headers']['X-API-Key'])) {
            $args['headers']['X-API-Key'] = $api_key;
        }
        
        // If body is provided and is an array, encode it as JSON
        if (isset($args['body']) && is_array($args['body'])) {
            $args['body'] = json_encode($args['body']);
        }
        
        // Make the request
        $response = wp_remote_request($url, $args);
        
        // Handle errors
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Check for HTTP errors
        if ($status_code >= 400) {
            $error_message = isset($data['detail']) ? $data['detail'] : __('Backend API request failed', 'vernal-contentum');
            return new WP_Error(
                'api_error',
                $error_message,
                array(
                    'status_code' => $status_code,
                    'response' => $data
                )
            );
        }
        
        return $data;
    }
    
    /**
     * Test backend connection
     * 
     * @return array|WP_Error Test result
     */
    public static function test_connection() {
        return self::request('/plugin/test');
    }
    
    /**
     * Get authenticated user info
     * 
     * @return array|WP_Error User data or error
     */
    public static function get_user() {
        $response = self::request('/plugin/test');
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        return isset($response['user']) ? $response['user'] : $response;
    }
}


