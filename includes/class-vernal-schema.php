<?php
/**
 * Schema and Table of Contents Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Schema {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Insert visual TOC in front-end content
        add_filter('the_content', array($this, 'add_table_of_contents'), 8);
        
        // Add Schema JSON-LD to head
        add_action('wp_head', array($this, 'add_schema_jsonld'), 10);
        
        // Add BreadcrumbList schema
        add_action('wp_head', array($this, 'add_breadcrumb_schema'), 50);
    }
    
    /**
     * Add table of contents to content
     */
    public function add_table_of_contents($content) {
        if (!is_singular() || is_admin()) {
            return $content;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        if (empty($options['show_toc'])) {
            return $content;
        }
        
        $headings = $this->get_headings($content);
        if (count($headings) < 2) {
            return $content;
        }
        
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
        
        $content = $this->add_ids_to_headings($content, $headings);
        
        return $toc . $content;
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
     * Add Schema JSON-LD to head
     */
    public function add_schema_jsonld() {
        if (!is_singular() || is_admin()) {
            return;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        if (empty($options['show_schema'])) {
            return;
        }
        
        global $post;
        $headings = $this->get_headings($post->post_content);
        
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
        
        // Logo logic
        if (!empty($options['use_site_logo'])) {
            $custom_logo_id = get_theme_mod('custom_logo');
            $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
        } else {
            $logo_url = !empty($options['logo_url']) ? $options['logo_url'] : '';
        }
        
        if (!$logo_url) {
            $logo_url = get_site_url() . '/wp-content/uploads/logo.png'; // Fallback
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
        
        $summary_abstract = wp_trim_words(strip_tags($post->post_content), 40, "...");
        
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
            "wordCount" => str_word_count(strip_tags($post->post_content)),
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
     * Add BreadcrumbList schema
     */
    public function add_breadcrumb_schema() {
        if (!is_singular() || is_admin()) {
            return;
        }
        
        $options = get_option('vernal_contentum_settings', array());
        if (empty($options['show_schema'])) {
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

