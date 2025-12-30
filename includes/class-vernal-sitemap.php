<?php
/**
 * Sitemap Data Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Sitemap {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hook into WordPress sitemap if available (WP 5.5+)
        add_filter('wp_sitemaps_posts_query_args', array($this, 'filter_sitemap_posts'), 10, 2);
    }
    
    /**
     * Get comprehensive sitemap data for LLM
     */
    public function get_sitemap_data() {
        $data = array(
            'site_url' => get_site_url(),
            'home_url' => home_url(),
            'site_name' => get_bloginfo('name'),
            'last_updated' => current_time('mysql'),
            'posts' => $this->get_posts_data(),
            'pages' => $this->get_pages_data(),
            'categories' => $this->get_categories_data(),
            'tags' => $this->get_tags_data(),
            'authors' => $this->get_authors_data(),
            'post_types' => $this->get_post_types_data(),
        );
        
        return $data;
    }
    
    /**
     * Get all published posts with metadata
     */
    private function get_posts_data() {
        $posts = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ));
        
        $formatted = array();
        foreach ($posts as $post) {
            $categories = wp_get_post_categories($post->ID, array('fields' => 'names'));
            $tags = wp_get_post_tags($post->ID, array('fields' => 'names'));
            
            $formatted[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'url' => get_permalink($post->ID),
                'slug' => $post->post_name,
                'date' => $post->post_date,
                'modified' => $post->post_modified,
                'excerpt' => get_the_excerpt($post->ID),
                'author' => get_the_author_meta('display_name', $post->post_author),
                'author_id' => $post->post_author,
                'categories' => $categories,
                'tags' => $tags,
                'word_count' => str_word_count(strip_tags($post->post_content)),
                'featured_image' => get_the_post_thumbnail_url($post->ID, 'full'),
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get all published pages
     */
    private function get_pages_data() {
        $pages = get_pages(array(
            'post_status' => 'publish',
            'number' => -1,
            'sort_column' => 'post_date',
            'sort_order' => 'DESC'
        ));
        
        $formatted = array();
        foreach ($pages as $page) {
            $formatted[] = array(
                'id' => $page->ID,
                'title' => $page->post_title,
                'url' => get_permalink($page->ID),
                'slug' => $page->post_name,
                'date' => $page->post_date,
                'modified' => $page->post_modified,
                'parent' => $page->post_parent,
                'order' => $page->menu_order,
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get all categories with post counts
     */
    private function get_categories_data() {
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
                'url' => get_category_link($category->term_id),
                'description' => $category->description,
                'count' => $category->count,
                'parent' => $category->parent,
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get all tags with post counts
     */
    private function get_tags_data() {
        $tags = get_tags(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        $formatted = array();
        foreach ($tags as $tag) {
            $formatted[] = array(
                'id' => $tag->term_id,
                'name' => $tag->name,
                'slug' => $tag->slug,
                'url' => get_tag_link($tag->term_id),
                'description' => $tag->description,
                'count' => $tag->count,
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get all authors with post counts
     */
    private function get_authors_data() {
        $authors = get_users(array(
            'who' => 'authors',
            'has_published_posts' => true,
            'orderby' => 'display_name',
            'order' => 'ASC'
        ));
        
        $formatted = array();
        foreach ($authors as $author) {
            $post_count = count_user_posts($author->ID);
            
            $formatted[] = array(
                'id' => $author->ID,
                'username' => $author->user_login,
                'display_name' => $author->display_name,
                'email' => $author->user_email,
                'url' => get_author_posts_url($author->ID),
                'post_count' => $post_count,
            );
        }
        
        return $formatted;
    }
    
    /**
     * Get all public post types
     */
    private function get_post_types_data() {
        $post_types = get_post_types(array('public' => true), 'objects');
        
        $formatted = array();
        foreach ($post_types as $post_type) {
            $count = wp_count_posts($post_type->name);
            
            $formatted[] = array(
                'name' => $post_type->name,
                'label' => $post_type->label,
                'public' => $post_type->public,
                'has_archive' => $post_type->has_archive,
                'published_count' => $count->publish ?? 0,
            );
        }
        
        return $formatted;
    }
    
    /**
     * Filter sitemap posts query
     */
    public function filter_sitemap_posts($args, $post_type) {
        // Allow customization if needed
        return $args;
    }
}

