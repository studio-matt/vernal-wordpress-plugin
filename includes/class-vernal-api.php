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
        
        // Categories endpoint (list)
        register_rest_route($namespace, '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_categories'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // Categories endpoint (create)
        register_rest_route($namespace, '/categories', array(
            'methods' => 'POST',
            'callback' => array($this, 'create_category'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // Categories endpoint (update parent / name)
        register_rest_route($namespace, '/categories/(?P<id>\d+)', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_category'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // Categories endpoint (delete)
        register_rest_route($namespace, '/categories/(?P<id>\d+)', array(
            'methods' => 'DELETE',
            'callback' => array($this, 'delete_category'),
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

        register_rest_route($namespace, '/health', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_health'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route($namespace, '/media', array(
            'methods' => 'GET',
            'callback' => array($this, 'search_media'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route($namespace, '/media/(?P<id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_media'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route($namespace, '/media', array(
            'methods' => 'POST',
            'callback' => array($this, 'upload_media'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route($namespace, '/posts/(?P<id>\d+)/code-fields', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_code_fields'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        register_rest_route($namespace, '/posts/(?P<id>\d+)/code-fields', array(
            'methods' => 'PUT',
            'callback' => array($this, 'put_code_fields'),
            'permission_callback' => array($this, 'check_api_key'),
        ));

        // Sync approved shirt print PNGs → media library + ACF galleries (front + back placeholder)
        register_rest_route($namespace, '/posts/(?P<id>\d+)/shirt-prints', array(
            'methods' => 'POST',
            'callback' => array($this, 'sync_shirt_prints'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));

        // Update Show Notes ACF on an existing landing post (API key; bypasses wp/v2 edit caps)
        register_rest_route($namespace, '/posts/(?P<id>\d+)/show-notes', array(
            'methods' => 'POST',
            'callback' => array($this, 'update_show_notes'),
            'permission_callback' => array($this, 'check_api_key'),
            'args' => array(
                'id' => array(
                    'required' => true,
                    'validate_callback' => function ($param) {
                        return is_numeric($param);
                    },
                ),
            ),
        ));
    }
    
    /**
     * Verify inbound API key (vc_…) from request header.
     * Outbound vcb_ keys must never authorize plugin REST routes.
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
        
        $request_api_key = $request->get_header('X-API-Key');
        
        if (empty($request_api_key)) {
            $request_api_key = $request->get_param('api_key');
        }
        
        if (empty($request_api_key)) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid API key', 'vernal-contentum'),
                array('status' => 401)
            );
        }

        // Reject outbound backend keys if presented as inbound auth.
        if (is_string($request_api_key) && strpos($request_api_key, 'vcb_') === 0) {
            return new WP_Error(
                'invalid_api_key',
                __('Invalid API key', 'vernal-contentum'),
                array('status' => 401)
            );
        }
        
        if (!hash_equals((string) $api_key, (string) $request_api_key)) {
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
     * Create a WordPress category (used by podcast topic → category flow)
     */
    public function create_category($request) {
        $params = $request->get_json_params();
        $name = isset($params['name']) ? sanitize_text_field($params['name']) : '';
        if (empty($name)) {
            return new WP_Error(
                'missing_name',
                __('Category name is required', 'vernal-contentum'),
                array('status' => 400)
            );
        }

        $parent = !empty($params['parent']) ? intval($params['parent']) : 0;
        $slug = !empty($params['slug']) ? sanitize_title($params['slug']) : '';

        // Prefer durable identity: slug + parent (supports same name under different parents).
        if ($slug !== '') {
            $by_slug = get_terms(array(
                'taxonomy' => 'category',
                'hide_empty' => false,
                'slug' => $slug,
                'parent' => $parent,
                'number' => 1,
            ));
            if (!is_wp_error($by_slug) && !empty($by_slug)) {
                $term = $by_slug[0];
                return rest_ensure_response(array(
                    'success' => true,
                    'data' => array(
                        'id' => (int) $term->term_id,
                        'name' => $term->name,
                        'slug' => $term->slug,
                        'parent' => (int) $term->parent,
                        'existing' => true,
                    ),
                ));
            }
        }

        // Name match within the requested parent scope first.
        $by_name = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'name' => $name,
            'parent' => $parent,
            'number' => 1,
        ));
        if (!is_wp_error($by_name) && !empty($by_name)) {
            $term = $by_name[0];
            return rest_ensure_response(array(
                'success' => true,
                'data' => array(
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent' => (int) $term->parent,
                    'existing' => true,
                ),
            ));
        }

        // Reuse an existing same-name category under any parent (avoid bill-quateman-2 dupes).
        $by_name_any = get_terms(array(
            'taxonomy' => 'category',
            'hide_empty' => false,
            'name' => $name,
            'number' => 1,
        ));
        if (!is_wp_error($by_name_any) && !empty($by_name_any)) {
            $term = $by_name_any[0];
            return rest_ensure_response(array(
                'success' => true,
                'data' => array(
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent' => (int) $term->parent,
                    'existing' => true,
                ),
            ));
        }

        $args = array();
        if (!empty($params['description'])) {
            $args['description'] = sanitize_textarea_field($params['description']);
        }
        if ($parent > 0) {
            $args['parent'] = $parent;
        }
        if ($slug !== '') {
            $args['slug'] = $slug;
        }

        $result = wp_insert_term($name, 'category', $args);
        if (is_wp_error($result)) {
            // Race: another request created the same slug/parent — re-fetch.
            if ($slug !== '') {
                $retry = get_terms(array(
                    'taxonomy' => 'category',
                    'hide_empty' => false,
                    'slug' => $slug,
                    'parent' => $parent,
                    'number' => 1,
                ));
                if (!is_wp_error($retry) && !empty($retry)) {
                    $term = $retry[0];
                    return rest_ensure_response(array(
                        'success' => true,
                        'data' => array(
                            'id' => (int) $term->term_id,
                            'name' => $term->name,
                            'slug' => $term->slug,
                            'parent' => (int) $term->parent,
                            'existing' => true,
                        ),
                    ));
                }
            }
            return $result;
        }

        $term = get_term((int) $result['term_id'], 'category');
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => (int) $term->parent,
                'existing' => false,
            ),
        ));
    }

    /**
     * Update a WordPress category (parent / name / slug).
     */
    public function update_category($request) {
        $term_id = intval($request['id']);
        $term = get_term($term_id, 'category');
        if (!$term || is_wp_error($term)) {
            return new WP_Error(
                'not_found',
                __('Category not found', 'vernal-contentum'),
                array('status' => 404)
            );
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }
        $args = array();
        if (isset($params['name']) && is_string($params['name']) && $params['name'] !== '') {
            $args['name'] = sanitize_text_field($params['name']);
        }
        if (isset($params['slug']) && is_string($params['slug']) && $params['slug'] !== '') {
            $args['slug'] = sanitize_title($params['slug']);
        }
        if (array_key_exists('parent', $params)) {
            $args['parent'] = max(0, intval($params['parent']));
        }
        if (isset($params['description'])) {
            $args['description'] = sanitize_textarea_field($params['description']);
        }
        if (empty($args)) {
            return rest_ensure_response(array(
                'success' => true,
                'data' => array(
                    'id' => (int) $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'parent' => (int) $term->parent,
                    'updated' => false,
                ),
            ));
        }
        $result = wp_update_term($term_id, 'category', $args);
        if (is_wp_error($result)) {
            return $result;
        }
        $term = get_term($term_id, 'category');
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'parent' => (int) $term->parent,
                'updated' => true,
            ),
        ));
    }

    /**
     * Delete a WordPress category.
     */
    public function delete_category($request) {
        $term_id = intval($request['id']);
        $term = get_term($term_id, 'category');
        if (!$term || is_wp_error($term)) {
            return new WP_Error(
                'not_found',
                __('Category not found', 'vernal-contentum'),
                array('status' => 404)
            );
        }
        $deleted = wp_delete_term($term_id, 'category');
        if (is_wp_error($deleted)) {
            return $deleted;
        }
        if (!$deleted) {
            return new WP_Error(
                'delete_failed',
                __('Failed to delete category', 'vernal-contentum'),
                array('status' => 500)
            );
        }
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => $term_id,
                'deleted' => true,
            ),
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
        
        // Set featured image if provided (also used as ACF thumbnail attachment)
        $thumbnail_attachment_id = 0;
        $image_url = '';
        if (!empty($params['thumbnail_url'])) {
            $image_url = esc_url_raw($params['thumbnail_url']);
        } elseif (!empty($params['featured_image_url'])) {
            $image_url = esc_url_raw($params['featured_image_url']);
        }
        if (!empty($image_url)) {
            $thumbnail_attachment_id = $this->sideload_image_attachment($post_id, $image_url);
            if ($thumbnail_attachment_id) {
                set_post_thumbnail($post_id, $thumbnail_attachment_id);
            }
        }
        
        // Set excerpt if provided
        if (!empty($params['excerpt'])) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => sanitize_textarea_field($params['excerpt'])
            ));
        }

        // Set custom meta if provided (supports arrays/objects as JSON)
        if (!empty($params['meta']) && is_array($params['meta'])) {
            foreach ($params['meta'] as $key => $value) {
                $this->set_post_meta_value($post_id, $key, $value);
            }
        }

        // ACF fields (preferred when Advanced Custom Fields is active)
        if (!empty($params['acf']) && is_array($params['acf'])) {
            $this->apply_acf_fields($post_id, $params['acf'], $thumbnail_attachment_id);
        } elseif ($thumbnail_attachment_id) {
            $this->set_acf_or_meta($post_id, 'thumbnail', $thumbnail_attachment_id);
        }

        // Vernal SEO Manifest → adapter (slug / excerpt sync / AIOSEO or native)
        // Meta (e.g. vernal_episode_id) must be set first so semantic kind detection works later.
        if (class_exists('Vernal_SEO_Adapter')) {
            Vernal_SEO_Adapter::get_instance()->apply_from_request($post_id, $params);
        } elseif (!empty($params['slug'])) {
            $slug = sanitize_title($params['slug']);
            if ($slug) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_name' => $slug,
                ));
            }
        }

        // PowerPress enclosure — Media URL must point at Blubrry CDN (or other public host).
        // Do NOT run through sanitize_text_field (strips newlines / corrupts multiline enclosure).
        if (!empty($params['powerpress']) && is_array($params['powerpress'])) {
            $this->set_powerpress_enclosure($post_id, $params['powerpress']);
        }

        // Approved shirt print assets → Media Library + ACF galleries
        $shirt_sync = array('front_ids' => array(), 'back_ids' => array());
        if (isset($params['shirt_print_assets']) && is_array($params['shirt_print_assets'])) {
            $shirt_sync = $this->apply_shirt_print_assets($post_id, $params['shirt_print_assets']);
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
                'thumbnail_id' => $thumbnail_attachment_id ? $thumbnail_attachment_id : null,
                'shirt_print_attachment_ids' => $shirt_sync['front_ids'],
                'shirt_print_back_attachment_ids' => $shirt_sync['back_ids'],
            )
        ));
    }

    /**
     * Re-sync approved shirt prints onto an existing episode post (approve/unapprove from Machine).
     */
    public function sync_shirt_prints($request) {
        $post_id = intval($request['id']);
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Post not found', 'vernal-contentum'),
                array('status' => 404)
            );
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }
        $assets = isset($params['shirt_print_assets']) && is_array($params['shirt_print_assets'])
            ? $params['shirt_print_assets']
            : array();
        $result = $this->apply_shirt_print_assets($post_id, $assets);
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => $post_id,
                'shirt_print_attachment_ids' => $result['front_ids'],
                'shirt_print_back_attachment_ids' => $result['back_ids'],
                'count_front' => count($result['front_ids']),
                'count_back' => count($result['back_ids']),
            ),
        ));
    }

    /**
     * Update Show Notes ACF fields on an existing episode landing post.
     *
     * Accepts the same `acf` map as create_post. Image fields that receive a URL
     * (e.g. ih_guest_headshot) are sideloaded into the Media Library first.
     */
    public function update_show_notes($request) {
        $post_id = intval($request['id']);
        $post = get_post($post_id);
        if (!$post) {
            return new WP_Error(
                'not_found',
                __('Post not found', 'vernal-contentum'),
                array('status' => 404)
            );
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        $updated_keys = array();
        if (!empty($params['title'])) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => sanitize_text_field($params['title']),
            ));
            $updated_keys[] = 'title';
        }
        if (isset($params['excerpt'])) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => sanitize_textarea_field($params['excerpt']),
            ));
            $updated_keys[] = 'excerpt';
        }
        if (!empty($params['status']) && in_array($params['status'], array('draft', 'publish', 'private', 'pending'), true)) {
            wp_update_post(array(
                'ID' => $post_id,
                'post_status' => $params['status'],
            ));
            $updated_keys[] = 'status';
        }
        if (!empty($params['powerpress']) && is_array($params['powerpress'])) {
            $this->set_powerpress_enclosure($post_id, $params['powerpress']);
            $updated_keys[] = 'powerpress';
        }
        if (!empty($params['category_ids']) && is_array($params['category_ids'])) {
            $category_ids = array_values(array_filter(array_map('intval', $params['category_ids'])));
            if (!empty($category_ids)) {
                wp_set_post_categories($post_id, $category_ids);
                $updated_keys[] = 'category_ids';
            }
        } elseif (isset($params['category_id']) && $params['category_id'] !== '' && $params['category_id'] !== null) {
            $cid = intval($params['category_id']);
            if ($cid > 0) {
                wp_set_post_categories($post_id, array($cid));
                $updated_keys[] = 'category_id';
            }
        }
        $verified = array();
        if (!empty($params['acf']) && is_array($params['acf'])) {
            $this->apply_acf_fields($post_id, $params['acf'], 0);
            $updated_keys = array_merge($updated_keys, array_keys($params['acf']));
            foreach (array_keys($params['acf']) as $acf_key) {
                if (!is_string($acf_key) || $acf_key === '') {
                    continue;
                }
                $verified[$acf_key] = $this->read_acf_or_meta($post_id, $acf_key);
            }
            // Always surface guest name + shirt galleries for ops debugging
            foreach (array('ih_guests_name', 'ih_guest_name', 'shirt_prints', 'shirt_prints_back', 'shirt_front', 'shirt_back') as $probe) {
                if (!array_key_exists($probe, $verified)) {
                    $verified[$probe] = $this->read_acf_or_meta($post_id, $probe);
                }
            }
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'id' => $post_id,
                'url' => get_permalink($post_id),
                'updated_keys' => array_values(array_unique($updated_keys)),
                'verified_acf' => $verified,
            ),
        ));
    }

    /**
     * Sideload shirt print PNGs into the Media Library and write ACF galleries + JSON.
     *
     * ACF fields (create on the episode post group):
     * - shirt_prints (Gallery) — front print attachment IDs
     * - shirt_prints_back (Gallery) — back print attachment IDs (placeholder until back builds ship)
     * - shirt_prints_json (Textarea) — metadata for Printful / theme
     * - shirt_prints_back_json (Textarea) — back metadata placeholder
     *
     * @param int   $post_id
     * @param array $assets  list of { url, side, design_id, build_id, quote, speaker_name, build_version }
     * @return array{front_ids:int[],back_ids:int[]}
     */
    private function apply_shirt_print_assets($post_id, $assets) {
        $front_ids = array();
        $back_ids = array();
        $front_meta = array();
        $back_meta = array();
        $seen_keys = array();

        if (!is_array($assets)) {
            $assets = array();
        }

        foreach ($assets as $asset) {
            if (!is_array($asset)) {
                continue;
            }
            $url = isset($asset['url']) ? trim((string) $asset['url']) : '';
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $side = isset($asset['side']) ? strtolower(trim((string) $asset['side'])) : 'front';
            if ($side !== 'back') {
                $side = 'front';
            }
            $design_id = isset($asset['design_id']) ? sanitize_text_field((string) $asset['design_id']) : '';
            $build_id = isset($asset['build_id']) ? sanitize_text_field((string) $asset['build_id']) : '';
            $key = $side . ':' . $design_id . ':' . $build_id;
            if ($key === 'front::' || $key === 'back::') {
                $key = $side . ':' . md5($url);
            }
            if (isset($seen_keys[$key])) {
                continue;
            }
            $seen_keys[$key] = true;

            $attachment_id = $this->find_shirt_attachment_by_key($post_id, $key);
            if (!$attachment_id) {
                $attachment_id = $this->sideload_image_attachment($post_id, $url);
                if ($attachment_id) {
                    update_post_meta($attachment_id, '_vernal_shirt_key', $key);
                    update_post_meta($attachment_id, '_vernal_shirt_side', $side);
                    if ($design_id !== '') {
                        update_post_meta($attachment_id, '_vernal_shirt_design_id', $design_id);
                    }
                    if ($build_id !== '') {
                        update_post_meta($attachment_id, '_vernal_shirt_build_id', $build_id);
                    }
                }
            }
            if (!$attachment_id) {
                continue;
            }

            $entry = array(
                'attachment_id' => intval($attachment_id),
                'url' => wp_get_attachment_url($attachment_id) ?: $url,
                'source_url' => $url,
                'side' => $side,
                'design_id' => $design_id,
                'build_id' => $build_id,
                'build_version' => isset($asset['build_version']) ? intval($asset['build_version']) : 0,
                'quote' => isset($asset['quote']) ? sanitize_text_field((string) $asset['quote']) : '',
                'speaker_name' => isset($asset['speaker_name']) ? sanitize_text_field((string) $asset['speaker_name']) : '',
            );

            if ($side === 'back') {
                $back_ids[] = intval($attachment_id);
                $back_meta[] = $entry;
            } else {
                $front_ids[] = intval($attachment_id);
                $front_meta[] = $entry;
            }
        }

        // Gallery fields: replace wholesale so unapprove removes from ACF (media files kept).
        $this->set_acf_or_meta($post_id, 'shirt_prints', $front_ids);
        $this->set_acf_or_meta($post_id, 'shirt_prints_back', $back_ids);
        $this->set_acf_or_meta($post_id, 'shirt_prints_json', wp_json_encode($front_meta));
        $this->set_acf_or_meta($post_id, 'shirt_prints_back_json', wp_json_encode($back_meta));
        // Aliases if the site created Gallery fields as shirt_front / shirt_back
        $this->set_acf_or_meta($post_id, 'shirt_front', $front_ids);
        $this->set_acf_or_meta($post_id, 'shirt_back', $back_ids);

        return array(
            'front_ids' => $front_ids,
            'back_ids' => $back_ids,
        );
    }

    /**
     * Find an existing sideloaded shirt attachment for this post by stable key.
     */
    private function find_shirt_attachment_by_key($post_id, $key) {
        $q = new WP_Query(array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'post_parent' => intval($post_id),
            'fields' => 'ids',
            'meta_query' => array(
                array(
                    'key' => '_vernal_shirt_key',
                    'value' => $key,
                    'compare' => '=',
                ),
            ),
        ));
        if (!empty($q->posts)) {
            return intval($q->posts[0]);
        }
        return 0;
    }
    
    /**
     * Persist post meta; arrays/objects stored as JSON strings.
     */
    private function set_post_meta_value($post_id, $key, $value) {
        $meta_key = sanitize_key($key);
        if (is_array($value) || is_object($value)) {
            update_post_meta($post_id, $meta_key, wp_json_encode($value));
            return;
        }
        if (is_bool($value)) {
            update_post_meta($post_id, $meta_key, $value ? '1' : '0');
            return;
        }
        update_post_meta($post_id, $meta_key, is_string($value) ? sanitize_text_field($value) : $value);
    }

    /**
     * Write ACF map onto a post. Image-like keys with http(s) values are sideloaded.
     *
     * @param int   $post_id
     * @param array $acf
     * @param int   $thumbnail_attachment_id Optional featured image already sideloaded for `thumbnail`.
     */
    private function apply_acf_fields($post_id, $acf, $thumbnail_attachment_id = 0) {
        if (!is_array($acf)) {
            return;
        }
        $image_keys = array(
            'thumbnail',
            'ih_guest_headshot',
            'ih_guest_headshot_url',
        );
        foreach ($acf as $key => $value) {
            $key = is_string($key) ? $key : '';
            if ($key === '') {
                continue;
            }
            // Alias: Machine may send URL under *_url; write to the real image field.
            $target_key = ($key === 'ih_guest_headshot_url') ? 'ih_guest_headshot' : $key;

            if ($target_key === 'thumbnail' && $thumbnail_attachment_id) {
                $this->set_acf_or_meta($post_id, 'thumbnail', $thumbnail_attachment_id);
                continue;
            }

            if (in_array($key, $image_keys, true) || in_array($target_key, $image_keys, true)) {
                if (is_numeric($value)) {
                    $this->set_acf_or_meta($post_id, $target_key, intval($value));
                    continue;
                }
                $url = is_string($value) ? esc_url_raw(trim($value)) : '';
                if ($url && preg_match('#^https?://#i', $url)) {
                    $att = $this->sideload_image_attachment($post_id, $url);
                    if ($att) {
                        $this->set_acf_or_meta($post_id, $target_key, $att);
                    }
                    continue;
                }
                // Empty string clears the image field
                if ($value === '' || $value === null) {
                    $this->set_acf_or_meta($post_id, $target_key, null);
                }
                continue;
            }

            $this->set_acf_or_meta($post_id, $target_key, $value);
        }
    }

    /**
     * Write ACF field when available; always mirror to post meta as a fallback
     * for Elementor custom-field tags and misconfigured field keys.
     */
    private function set_acf_or_meta($post_id, $key, $value) {
        if (function_exists('update_field')) {
            // Strict false = field missing / update failed. Empty string is a valid write.
            $result = update_field($key, $value, $post_id);
            if ($result === false) {
                $this->set_post_meta_value($post_id, $key, $value);
            }
            return;
        }
        $this->set_post_meta_value($post_id, $key, $value);
    }

    /**
     * Read an ACF field value, falling back to post meta.
     */
    private function read_acf_or_meta($post_id, $key) {
        if (function_exists('get_field')) {
            $val = get_field($key, $post_id);
            if ($val !== null && $val !== false) {
                return $val;
            }
        }
        $meta = get_post_meta($post_id, $key, true);
        return ($meta === '' || $meta === false) ? null : $meta;
    }

    /**
     * Sideload a remote image and return the attachment ID (0 on failure).
     */
    private function sideload_image_attachment($post_id, $image_url) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return 0;
        }

        $name = basename(parse_url($image_url, PHP_URL_PATH));
        if (empty($name) || strpos($name, '.') === false) {
            $name = 'featured-' . $post_id . '.jpg';
        }

        $file_array = array(
            'name' => $name,
            'tmp_name' => $tmp,
        );

        $id = media_handle_sideload($file_array, $post_id);
        if (is_wp_error($id)) {
            @unlink($file_array['tmp_name']);
            return 0;
        }
        return intval($id);
    }

    /**
     * Set featured image from URL (legacy helper).
     */
    private function set_featured_image($post_id, $image_url) {
        $id = $this->sideload_image_attachment($post_id, $image_url);
        if (!$id) {
            return false;
        }
        set_post_thumbnail($post_id, $id);
        return true;
    }

    /**
     * Write PowerPress enclosure meta from a public media URL (Blubrry CDN).
     *
     * PowerPress expects post meta `enclosure` as:
     *   url\nlength\ntype\n
     * Optionally also writes `_podcast:mediaurl` for newer PowerPress UIs.
     *
     * @param int   $post_id
     * @param array $powerpress  expects media_url; optional length, type
     */
    private function set_powerpress_enclosure($post_id, $powerpress) {
        $media_url = isset($powerpress['media_url']) ? trim((string) $powerpress['media_url']) : '';
        if ($media_url === '') {
            return;
        }
        // Only allow http(s) public URLs — never Machine-internal paths as enclosure.
        if (!preg_match('#^https?://#i', $media_url)) {
            return;
        }

        $length = isset($powerpress['length']) ? intval($powerpress['length']) : 0;
        $type = isset($powerpress['type']) ? trim((string) $powerpress['type']) : 'audio/mpeg';
        if ($type === '') {
            $type = 'audio/mpeg';
        }

        if ($length <= 0) {
            $length = $this->probe_remote_content_length($media_url);
        }

        // Multiline enclosure — do not sanitize_text_field (strips newlines).
        $enclosure = $media_url . "\n" . $length . "\n" . $type . "\n";
        delete_post_meta($post_id, 'enclosure');
        add_post_meta($post_id, 'enclosure', $enclosure, true);

        // PowerPress extras used by the episode editor UI
        update_post_meta($post_id, '_podcast:mediaurl', $media_url);
        if (function_exists('powerpress_add_episode')) {
            // Older PowerPress helper if present — ignore failures.
            try {
                powerpress_add_episode($post_id, array('url' => $media_url, 'size' => $length, 'type' => $type));
            } catch (Exception $e) {
                // no-op
            }
        }
    }

    /**
     * HEAD-request Content-Length for enclosure size (0 if unknown).
     */
    private function probe_remote_content_length($url) {
        $response = wp_remote_head($url, array(
            'timeout' => 15,
            'redirection' => 3,
        ));
        if (is_wp_error($response)) {
            return 0;
        }
        $headers = wp_remote_retrieve_headers($response);
        if (empty($headers['content-length'])) {
            return 0;
        }
        return intval($headers['content-length']);
    }
    
    /**
     * Allowed Vernal API hosts for configure-backend (HTTPS only).
     * Keeps compromised WP installs from being pointed at arbitrary servers.
     */
    private function allowed_backend_hosts() {
        $hosts = array(
            'themachine.vernalcontentum.com',
            'machine.vernalcontentum.com',
        );
        /**
         * Filter allowed Vernal backend hosts for configure-backend.
         *
         * @param array $hosts Lowercase hostnames only.
         */
        $hosts = apply_filters('vernal_contentum_allowed_backend_hosts', $hosts);
        return array_values(array_filter(array_map('strtolower', (array) $hosts)));
    }

    private function is_allowed_backend_url($url) {
        $parsed = wp_parse_url($url);
        if (empty($parsed['scheme']) || strtolower($parsed['scheme']) !== 'https') {
            return false;
        }
        if (empty($parsed['host'])) {
            return false;
        }
        // Reject userinfo, unexpected ports (allow default 443 only).
        if (!empty($parsed['user']) || !empty($parsed['pass'])) {
            return false;
        }
        if (!empty($parsed['port']) && intval($parsed['port']) !== 443) {
            return false;
        }
        $host = strtolower($parsed['host']);
        return in_array($host, $this->allowed_backend_hosts(), true);
    }

    /**
     * Configure backend settings (automatic setup from Vernal).
     * Auth boundary is inbound vc_ via check_api_key only (no WP logged-in user).
     */
    public function configure_backend($request) {
        $api_check = $this->check_api_key($request);
        if (is_wp_error($api_check)) {
            return $api_check;
        }

        // Rate limit: max 10 configure attempts per hour per site.
        $throttle_key = 'vernal_configure_backend_' . md5(home_url());
        $hits = (int) get_transient($throttle_key);
        if ($hits >= 10) {
            return new WP_Error(
                'rate_limited',
                __('Too many configuration attempts. Try again later.', 'vernal-contentum'),
                array('status' => 429)
            );
        }
        set_transient($throttle_key, $hits + 1, HOUR_IN_SECONDS);
        
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }
        
        if (empty($params['backend_url']) || empty($params['backend_api_key'])) {
            return new WP_Error(
                'missing_parameters',
                __('Configuration parameters are required', 'vernal-contentum'),
                array('status' => 400)
            );
        }

        $backend_url = esc_url_raw(trim((string) $params['backend_url']));
        $backend_api_key = sanitize_text_field((string) $params['backend_api_key']);

        if (empty($backend_url) || !$this->is_allowed_backend_url($backend_url)) {
            return new WP_Error(
                'invalid_backend_url',
                __('Backend URL is not allowed', 'vernal-contentum'),
                array('status' => 400)
            );
        }

        // Only accept outbound-style keys from Vernal.
        if (strpos($backend_api_key, 'vcb_') !== 0 || strlen($backend_api_key) < 20) {
            return new WP_Error(
                'invalid_backend_api_key',
                __('Backend API key format is invalid', 'vernal-contentum'),
                array('status' => 400)
            );
        }
        
        $settings = get_option('vernal_contentum_settings', array());
        if (!is_array($settings)) {
            $settings = array();
        }
        // Only approved fields — do not merge arbitrary payload keys.
        $settings['backend_url'] = untrailingslashit($backend_url);
        $settings['backend_api_key'] = $backend_api_key;
        $settings['outbound_status'] = 'configured';
        $settings['outbound_configured_at'] = gmdate('c');
        update_option('vernal_contentum_settings', $settings);

        // Immediately verify WP → Vernal (same check as the admin "Check connection" button)
        // so the Connection page shows Connected/verified without a manual click.
        $outbound_verified = false;
        if (class_exists('Vernal_Backend_API')) {
            $test = Vernal_Backend_API::test_connection();
            $settings = get_option('vernal_contentum_settings', array());
            if (!is_array($settings)) {
                $settings = array();
            }
            $settings['outbound_last_tested_at'] = gmdate('c');
            if (!is_wp_error($test)) {
                $settings['outbound_status'] = 'connected';
                $outbound_verified = true;
            } else {
                $settings['outbound_status'] = 'error';
            }
            update_option('vernal_contentum_settings', $settings);
        }
        
        // Do not echo URL or key material back to the client.
        return rest_ensure_response(array(
            'status' => 'success',
            'message' => $outbound_verified
                ? __('Connected and verified', 'vernal-contentum')
                : __('Connected successfully', 'vernal-contentum'),
            'outbound_configured' => true,
            'outbound_verified' => $outbound_verified,
        ));
    }

    public function get_health($request) {
        $max_upload = wp_max_upload_size();
        $acf_active = class_exists('Vernal_Code_Fields') ? Vernal_Code_Fields::is_acf_active() : false;
        $group_registered = class_exists('Vernal_Code_Fields') ? Vernal_Code_Fields::is_group_registered() : false;
        if ($acf_active && class_exists('Vernal_Code_Fields')) {
            Vernal_Code_Fields::register_field_group();
            $group_registered = Vernal_Code_Fields::is_group_registered() || $acf_active;
        }
        $settings = get_option('vernal_contentum_settings', array());
        $outbound_configured = false;
        if (is_array($settings) && !empty($settings['backend_url']) && !empty($settings['backend_api_key'])) {
            $outbound_configured = true;
        }
        if (defined('VERNAL_BACKEND_URL') && defined('VERNAL_BACKEND_API_KEY')) {
            $outbound_configured = true;
        }
        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'site_url' => site_url(),
                'home_url' => home_url(),
                'plugin_version' => defined('VERNAL_CONTENTUM_VERSION') ? VERNAL_CONTENTUM_VERSION : '',
                'acf_active' => $acf_active,
                'code_field_group_registered' => $group_registered,
                'post_type_supports_meta' => post_type_exists('post'),
                'outbound_configured' => $outbound_configured,
                'upload' => array(
                    'max_upload_bytes' => $max_upload,
                    'allowed_mime_types' => array_values(get_allowed_mime_types()),
                ),
                'capabilities' => array(
                    'media' => true,
                    'code_fields' => true,
                    'configure_backend' => true,
                ),
            ),
        ));
    }

    public function search_media($request) {
        $search = sanitize_text_field($request->get_param('search'));
        $page = max(1, intval($request->get_param('page') ?: 1));
        $per_page = min(50, max(1, intval($request->get_param('per_page') ?: 20)));
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
        );
        if ($search) {
            $args['s'] = $search;
        }
        $mime = $request->get_param('mime');
        if ($mime) {
            $args['post_mime_type'] = sanitize_text_field($mime);
        }
        $q = new WP_Query($args);
        $items = array();
        foreach ($q->posts as $post) {
            $items[] = $this->format_media_item($post);
        }
        return rest_ensure_response(array(
            'success' => true,
            'data' => $items,
            'page' => $page,
            'total' => intval($q->found_posts),
        ));
    }

    public function get_media($request) {
        $id = intval($request['id']);
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new WP_Error('not_found', __('Media not found', 'vernal-contentum'), array('status' => 404));
        }
        $item = $this->format_media_item($post);
        $item['accessible'] = (bool) wp_get_attachment_url($id);
        return rest_ensure_response(array('success' => true, 'data' => $item));
    }

    public function upload_media($request) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $files = $request->get_file_params();
        if (empty($files['file'])) {
            return new WP_Error('missing_file', __('File is required', 'vernal-contentum'), array('status' => 400));
        }
        $file = $files['file'];
        $overrides = array('test_form' => false);
        $move = wp_handle_upload($file, $overrides);
        if (isset($move['error'])) {
            return new WP_Error('upload_error', $move['error'], array('status' => 400));
        }
        $attachment = array(
            'post_mime_type' => $move['type'],
            'post_title' => sanitize_file_name(basename($move['file'])),
            'post_content' => '',
            'post_status' => 'inherit',
        );
        $attach_id = wp_insert_attachment($attachment, $move['file']);
        if (is_wp_error($attach_id)) {
            return $attach_id;
        }
        $meta = wp_generate_attachment_metadata($attach_id, $move['file']);
        wp_update_attachment_metadata($attach_id, $meta);
        $post = get_post($attach_id);
        return rest_ensure_response(array(
            'success' => true,
            'data' => $this->format_media_item($post),
        ));
    }

    private function format_media_item($post) {
        $id = (int) $post->ID;
        return array(
            'id' => $id,
            'media_id' => $id,
            'title' => get_the_title($id),
            'url' => wp_get_attachment_url($id),
            'source_url' => wp_get_attachment_url($id),
            'mime_type' => get_post_mime_type($id),
            'mime' => get_post_mime_type($id),
            'date' => $post->post_date,
        );
    }

    public function get_code_fields($request) {
        $id = intval($request['id']);
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('not_found', __('Post not found', 'vernal-contentum'), array('status' => 404));
        }
        $fields = Vernal_Code_Fields::get_code_fields($id);
        return rest_ensure_response(array('success' => true, 'data' => $fields));
    }

    public function put_code_fields($request) {
        $id = intval($request['id']);
        $post = get_post($id);
        if (!$post) {
            return new WP_Error('not_found', __('Post not found', 'vernal-contentum'), array('status' => 404));
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_Error('invalid', __('JSON body required', 'vernal-contentum'), array('status' => 400));
        }
        // Authenticated Machine API key path — use machine setter with ID protection
        $result = Vernal_Code_Fields::set_code_fields_from_machine($id, $params);
        if (is_wp_error($result)) {
            return $result;
        }
        return rest_ensure_response(array('success' => true, 'data' => $result));
    }
}

