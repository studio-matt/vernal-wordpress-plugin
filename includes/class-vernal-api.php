<?php
/**
 * REST API Endpoints
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_API {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        $namespace = 'vernal-contentum/v1';
        
        // Sitemap endpoint
        register_rest_route($namespace, '/sitemap', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_sitemap'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        // Categories endpoint
        register_rest_route($namespace, '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        // Authors endpoint
        register_rest_route($namespace, '/authors', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_authors'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        // Create post endpoint
        register_rest_route($namespace, '/posts', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_post'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
        
        // Configure backend endpoint (for automatic setup)
        register_rest_route($namespace, '/configure-backend', array(
            'methods' => 'POST',
            'callback' => array($this, 'configure_backend'),
            'permission_callback' => array($this, 'check_api_key'),
        ));
    }
    
    /**
     * Verify API key from request header
     */
    public function check_api_key($request) {
        $api_key = get_option('vernal_contentum_api_key', '');
        
        if (empty($api_key)) {
            return new WP_Error(
                'no_api_key',
                __('API key not configured', 'vernal-contentum'),
                array('status' => 500)
            );
        }
        
        // Check for API key in header
        $request_api_key = $request->get_header('X-API-Key');
        
        if (empty($request_api_key)) {
            // Also check query parameter for convenience
            $request_api_key = $request->get_param('api_key');
        }
        
        if ($request_api_key !== $api_key) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid API key', 'vernal-contentum'),
                array('status' => 401)
            );
        }
        
        return true;
    }
    
    /**
     * Get sitemap data
     */
    public function get_sitemap($request) {
        $sitemap_handler = Vernal_Sitemap::get_instance();
        $sitemap_data = $sitemap_handler->get_sitemap_data();
        
        return rest_ensure_response($sitemap_data);
    }
    
    /**
     * Get all categories
     */
    public function get_categories($request) {
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        $formatted = array();
        foreach ($categories as $category) {
            $formatted[] = array(
                'id' => $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
                'description' => $category->description,
                'count' => $category->count,
                'parent' => $category->parent,
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $formatted,
            'count' => count($formatted)
        ));
    }
    
    /**
     * Get all authors
     */
    public function get_authors($request) {
        $authors = get_users(array(
            'who' => 'authors',
            'has_published_posts' => true,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));
        
        $formatted = array();
        foreach ($authors as $author) {
            $formatted[] = array(
                'id' => $author->ID,
                'username' => $author->user_login,
                'display_name' => $author->display_name,
                'email' => $author->user_email,
                'first_name' => get_user_meta($author->ID, 'first_name', true),
                'last_name' => get_user_meta($author->ID, 'last_name', true),
            );
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => $formatted,
            'count' => count($formatted)
        ));
    }
    
    /**
     * Create a new post
     */
    public function create_post($request) {
        $params = $request->get_json_params();
        
        // Validate required fields
        if (empty($params['title'])) {
            return new WP_Error(
                'missing_title',
                __('Post title is required', 'vernal-contentum'),
                array('status' => 400)
            );
        }
        
        if (empty($params['content'])) {
            return new WP_Error(
                'missing_content',
                __('Post content is required', 'vernal-contentum'),
                array('status' => 400)
            );
        }
        
        // Prepare post data
        $post_data = array(
            'post_title' => sanitize_text_field($params['title']),
            'post_content' => wp_kses_post($params['content']),
            'post_status' => isset($params['status']) ? sanitize_text_field($params['status']) : 'draft',
            'post_type' => isset($params['post_type']) ? sanitize_text_field($params['post_type']) : 'post',
        );
        
        // Set author if provided
        if (!empty($params['author_id'])) {
            $author_id = intval($params['author_id']);
            if (get_user_by('ID', $author_id)) {
                $post_data['post_author'] = $author_id;
            }
        }
        
        // Set post date if provided
        if (!empty($params['post_date'])) {
            $post_data['post_date'] = sanitize_text_field($params['post_date']);
        }
        
        // Create the post
        $post_id = wp_insert_post($post_data, true);
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Set categories if provided
        if (!empty($params['category_ids']) && is_array($params['category_ids'])) {
            $category_ids = array_map('intval', $params['category_ids']);
            wp_set_post_categories($post_id, $category_ids);
        } elseif (!empty($params['category_id'])) {
            wp_set_post_categories($post_id, array(intval($params['category_id'])));
        }
        
        // Set tags if provided
        if (!empty($params['tags']) && is_array($params['tags'])) {
            wp_set_post_tags($post_id, $params['tags']);
        }
        
        // Set featured image if provided
        if (!empty($params['featured_image_url'])) {
            $this->set_featured_image($post_id, $params['featured_image_url']);
        }
        
        // Set excerpt if provided
        if (!empty($params['excerpt'])) {
            update_post_meta($post_id, '_wp_old_slug', ''); // Clear old slug
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => sanitize_textarea_field($params['excerpt'])
            ));
        }
        
        // Set custom meta if provided
        if (!empty($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                update_post_meta($post_id, sanitize_key($key), sanitize_text_field($value));
            }
        }
        
        // Get the created post
        $post = get_post($post_id);
        
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => $post_id,
                'title' => $post->post_title,
                'status' => $post->post_status,
                'url' => get_permalink($post_id),
                'edit_url' => admin_url('post.php?action=edit&post=' . $post_id),
            )
        ));
    }
    
    /**
     * Set featured image from URL
     */
    private function set_featured_image($post_id, $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        
        $tmp = download_url($image_url);
        
        if (is_wp_error($tmp)) {
            return false;
        }
        
        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp
        );
        
        $id = media_handle_sideload($file_array, $post_id);
        
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }
        
        set_post_thumbnail($post_id, $id);
        return true;
    }
}

