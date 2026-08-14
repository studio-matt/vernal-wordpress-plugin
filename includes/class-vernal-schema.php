<?php
/**
 * Schema and Table of Contents Handler
 *
 * Vernal owns TOC + heading extraction from semantic content.
 * Article JSON-LD / BreadcrumbList emit only when no supported SEO plugin is active
 * (single schema authority).
 *
 * TOC injection rules:
 * - Articles: once via the_content, at the top of the body (guarded against double apply_filters).
 * - Show landings: once via ACF ih_show_summary only — never via the_content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Schema {
    
    private static $instance = null;

    /** @var bool Request-scoped: the_content TOC already injected */
    private $toc_injected_via_content = false;

    /** @var array<int,bool> Request-scoped: ACF TOC already injected per post */
    private $toc_injected_via_acf = array();
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Insert visual TOC in front-end content (articles using the_content)
        add_filter('the_content', array($this, 'add_table_of_contents'), 8);

        // Show landings: Elementor renders ACF ih_show_summary — prepend TOC there
        add_filter('acf/format_value/name=ih_show_summary', array($this, 'prepend_toc_to_acf_summary'), 20, 3);
        
        // Add Schema JSON-LD to head (only when Vernal is schema authority)
        add_action('wp_head', array($this, 'add_schema_jsonld'), 10);
        
        // Add BreadcrumbList schema (only when Vernal is schema authority)
        add_action('wp_head', array($this, 'add_breadcrumb_schema'), 50);
    }

    /**
     * Vernal emits Article/Breadcrumb JSON-LD only when no supported SEO plugin owns schema.
     */
    private function vernal_is_schema_authority() {
        if (class_exists('Vernal_SEO_Adapter')) {
            return !Vernal_SEO_Adapter::get_instance()->has_supported_seo_plugin();
        }
        // If adapter not loaded, keep legacy behavior (emit)
        return true;
    }

    private function semantic_html($post = null) {
        if (class_exists('Vernal_Semantic_Content')) {
            return Vernal_Semantic_Content::get_instance()->get_wp_semantic_content($post);
        }
        global $post;
        return ($post && isset($post->post_content)) ? $post->post_content : '';
    }

    private function content_has_vernal_toc($html) {
        if (!is_string($html) || $html === '') {
            return false;
        }
        return (strpos($html, 'vernal-toc') !== false);
    }

    private function build_toc_html($headings) {
        $options = get_option('vernal_contentum_settings', array());
        $label = esc_html($options['toc_label'] ?? 'In This Article...');
        $style = $options['toc_style'] ?? 'bullets';

        $toc = "<nav class='vernal-toc'><strong>{$label}</strong>";
        $toc .= $style === 'numbers' ? "<ol>" : "<ul>";

        foreach ($headings as $h) {
            $toc .= sprintf(
                '<li><a href="#%s">%s</a></li>',
                esc_attr($h['id']),
                esc_html($h['text'])
            );
        }

        $toc .= $style === 'numbers' ? "</ol>" : "</ul>";
        $toc .= "</nav>";
        return $toc;
    }
    
    /**
     * Add table of contents to post body (articles only).
     */
    public function add_table_of_contents($content) {
        if (!is_singular() || is_admin()) {
            return $content;
        }

        // Themes/Elementor often run the_content more than once.
        if ($this->toc_injected_via_content || $this->content_has_vernal_toc($content)) {
            return $content;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        if (empty($options['show_toc'])) {
            return $content;
        }

        // Show landings: TOC belongs on ACF summary only — never on placeholder body.
        if (class_exists('Vernal_Semantic_Content')) {
            $kind = Vernal_Semantic_Content::get_instance()->detect_kind(null);
            if ($kind === 'show_landing') {
                return $content;
            }
        }

        // Only inject when THIS content stream has the headings (do not borrow ACF).
        $headings = $this->get_headings($content);
        if (count($headings) < 2) {
            return $content;
        }
        
        $this->toc_injected_via_content = true;
        $toc = $this->build_toc_html($headings);
        $content = $this->add_ids_to_headings($content, $headings);
        
        return $toc . $content;
    }

    /**
     * Prepend TOC into ACF show summary for Elementor templates (once per post/request).
     */
    public function prepend_toc_to_acf_summary($value, $post_id, $field) {
        if (is_admin() || !is_singular()) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return $value;
        }
        $options = get_option('vernal_contentum_settings', array());
        if (empty($options['show_toc'])) {
            return $value;
        }
        if ($this->content_has_vernal_toc($value)) {
            return $value;
        }
        $post_id = (int) $post_id;
        if ($post_id > 0 && !empty($this->toc_injected_via_acf[$post_id])) {
            // Already injected for this post in this request; return value unchanged
            // so a second Elementor get_field does not prepend another TOC.
            return $value;
        }
        $headings = $this->get_headings($value);
        if (count($headings) < 2) {
            return $value;
        }
        if ($post_id > 0) {
            $this->toc_injected_via_acf[$post_id] = true;
        }
        $value = $this->add_ids_to_headings($value, $headings);
        return $this->build_toc_html($headings) . $value;
    }
    
    /**
     * Get headings from content
     */
    private function get_headings($content) {
        $headings = array();
        
        if (preg_match_all('/<h([2-6])([^>]*)>(.*?)<\/h\1>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $full) {
                $text = strip_tags($matches[3][$i][0]);
                
                if (preg_match('/id=["\']([^"\']+)["\']/', $matches[2][$i][0], $idmatch)) {
                    $slug = $idmatch[1];
                } else {
                    $text_clean = preg_replace('/[^\w\s-]/', '', strtolower($text));
                    $words = array_slice(explode(' ', $text_clean), 0, 7);
                    $short_slug = implode('-', $words);
                    $slug = 'vernal-' . $short_slug;
                }
                
                $headings[] = array(
                    'text' => $text,
                    'id' => $slug,
                    'tag' => $matches[1][$i][0],
                    'offset' => $matches[0][$i][1],
                    'full' => $matches[0][$i][0]
                );
            }
        }
        
        return $headings;
    }
    
    /**
     * Add IDs to headings
     */
    private function add_ids_to_headings($content, $headings) {
        foreach ($headings as $h) {
            if (!preg_match('/id=[\'"]'.preg_quote($h['id'], '/').'[\'"]/', $h['full'])) {
                $new = preg_replace(
                    '/<h([2-6])([^>]*)>/i',
                    '<h$1$2 id="'.$h['id'].'">',
                    $h['full'],
                    1
                );
                $content = str_replace($h['full'], $new, $content);
            }
        }
        
        return $content;
    }
    
    /**
     * Add Schema JSON-LD to head — only when Vernal is schema authority.
     */
    public function add_schema_jsonld() {
        if (!is_singular() || is_admin()) {
            return;
        }
        if (!$this->vernal_is_schema_authority()) {
            return;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        $show_schema = isset($options['show_schema']) ? $options['show_schema'] : 1;
        if (empty($show_schema)) {
            return;
        }
        
        global $post;
        $semantic = $this->semantic_html($post);
        $headings = $this->get_headings($semantic);
        
        if (count($headings) < 2) {
            return;
        }
        
        $itemList = array();
        foreach ($headings as $i => $h) {
            $itemList[] = array(
                "@type" => "ListItem",
                "position" => $i + 1,
                "name" => $h['text'],
                "url" => get_permalink() . "#" . $h['id']
            );
        }
        
        $articleSections = array_map(function($h) {
            return $h['text'];
        }, $headings);
        
        if (!empty($options['use_site_logo'])) {
            $custom_logo_id = get_theme_mod('custom_logo');
            $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
        } else {
            $logo_url = !empty($options['logo_url']) ? $options['logo_url'] : '';
        }
        
        if (!$logo_url) {
            $logo_url = get_site_url() . '/wp-content/uploads/logo.png';
        }
        
        $keywords = wp_get_post_tags($post->ID, array('fields' => 'names'));
        
        $hero_image_url = get_the_post_thumbnail_url($post, 'full');
        $hero_image_meta = array();
        
        if ($hero_image_url) {
            $hero_image_meta = array(
                "@type" => "ImageObject",
                "url" => $hero_image_url,
                "width" => 1200,
                "height" => 700,
            );
        }
        
        $summary_abstract = wp_trim_words(strip_tags($semantic), 40, "...");
        
        $jsonld = array(
            "@context" => "https://schema.org",
            "@type" => "Article",
            "@id" => get_permalink() . "#article",
            "headline" => get_the_title(),
            "url" => get_permalink(),
            "mainEntityOfPage" => get_permalink(),
            "isAccessibleForFree" => true,
            "datePublished" => get_the_date('c'),
            "dateModified" => get_the_modified_date('c'),
            "inLanguage" => "en",
            "image" => $hero_image_meta,
            "wordCount" => str_word_count(strip_tags($semantic)),
            "author" => array(
                "@type" => "Person",
                "name" => get_the_author_meta('display_name', $post->post_author)
            ),
            "publisher" => array(
                "@type" => "Organization",
                "name" => get_bloginfo('name'),
                "logo" => array(
                    "@type" => "ImageObject",
                    "url" => $logo_url
                )
            ),
            "description" => get_the_excerpt() ?: $summary_abstract,
            "articleSection" => $articleSections,
            "hasPart" => array(
                array(
                    "@type" => "ItemList",
                    "name" => "Table of Contents",
                    "itemListElement" => $itemList
                )
            ),
            "keywords" => $keywords
        );
        
        echo '<script type="application/ld+json">' . json_encode($jsonld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
    
    /**
     * BreadcrumbList — only when Vernal is schema authority.
     */
    public function add_breadcrumb_schema() {
        if (!is_singular() || is_admin()) {
            return;
        }
        if (!$this->vernal_is_schema_authority()) {
            return;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        $show_schema = isset($options['show_schema']) ? $options['show_schema'] : 1;
        if (empty($show_schema)) {
            return;
        }
        
        $breadcrumb = array(
            "@context" => "https://schema.org",
            "@type" => "BreadcrumbList",
            "itemListElement" => array(
                array(
                    "@type" => "ListItem",
                    "position" => 1,
                    "name" => get_bloginfo('name'),
                    "item" => home_url()
                ),
                array(
                    "@type" => "ListItem",
                    "position" => 2,
                    "name" => get_the_title(),
                    "item" => get_permalink()
                )
            )
        );
        
        echo '<script type="application/ld+json">' . json_encode($breadcrumb, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '</script>';
    }
}
