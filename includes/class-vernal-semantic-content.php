<?php
/**
 * Semantic content resolver — single source for TOC / headings / SEO validation input.
 *
 * normal article  → post_content
 * show landing    → ih_show_summary (ACF), detected via vernal_episode_id meta
 * future kinds    → register via Vernal_Semantic_Content::register_resolver()
 *
 * Do NOT copy ACF semantic fields into post_content to chase TruSEO scores.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Semantic_Content {

    private static $instance = null;

    /** @var array<string, callable> kind => resolver(WP_Post): string */
    private $resolvers = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_resolver('article', array($this, 'resolve_article'));
        $this->register_resolver('show_landing', array($this, 'resolve_show_landing'));
    }

    /**
     * Register or override a semantic content resolver for a kind.
     *
     * @param string   $kind
     * @param callable $callback function( WP_Post $post ): string
     */
    public function register_resolver($kind, $callback) {
        if (is_callable($callback)) {
            $this->resolvers[$kind] = $callback;
        }
    }

    /**
     * Durable show-landing signal: episode meta set by Machine publish.
     */
    public function detect_kind($post) {
        if (!$post || empty($post->ID)) {
            return 'article';
        }
        $episode_id = get_post_meta($post->ID, 'vernal_episode_id', true);
        if (!empty($episode_id)) {
            return 'show_landing';
        }
        // Secondary: ACF show summary present with non-trivial HTML
        $summary = $this->get_acf_field($post->ID, 'ih_show_summary');
        if (is_string($summary) && strlen(trim(wp_strip_all_tags($summary))) > 40) {
            // Only treat as show if body is empty/placeholder
            $body = trim(wp_strip_all_tags($post->post_content));
            if ($body === '' || $body === '&nbsp;' || $body === "\xC2\xA0") {
                return 'show_landing';
            }
        }
        return 'article';
    }

    /**
     * @param WP_Post|int|null $post
     * @return string HTML
     */
    public function get_wp_semantic_content($post = null) {
        $post = $this->normalize_post($post);
        if (!$post) {
            return '';
        }
        $kind = $this->detect_kind($post);
        if (isset($this->resolvers[$kind]) && is_callable($this->resolvers[$kind])) {
            $html = call_user_func($this->resolvers[$kind], $post);
            return is_string($html) ? $html : '';
        }
        return is_string($post->post_content) ? $post->post_content : '';
    }

    public function resolve_article($post) {
        return is_string($post->post_content) ? $post->post_content : '';
    }

    public function resolve_show_landing($post) {
        $summary = $this->get_acf_field($post->ID, 'ih_show_summary');
        if (is_string($summary) && trim($summary) !== '') {
            return $summary;
        }
        // Fallback: do not invent content; empty semantic content is honest
        return '';
    }

    private function get_acf_field($post_id, $field_name) {
        if (function_exists('get_field')) {
            $val = get_field($field_name, $post_id);
            if (is_string($val)) {
                return $val;
            }
        }
        $raw = get_post_meta($post_id, $field_name, true);
        return is_string($raw) ? $raw : '';
    }

    private function normalize_post($post) {
        if ($post instanceof WP_Post) {
            return $post;
        }
        if (is_numeric($post)) {
            return get_post((int) $post);
        }
        if ($post === null) {
            global $post;
            return ($post instanceof WP_Post) ? $post : null;
        }
        return null;
    }
}
