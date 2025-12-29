<?php
/*
 * Content Machine Schema Maker
 * - FAQ (Q&A pairs) in JSON-LD and visual blocks
 * - TL;DR summary box
 * - Canonical hub linking
 * - Pull quote shortcode '[cmschema_quote]'
 * - Spicy internal links '[cmschema_link url="..." text="..."]'
 * - H2/H3 hierarchy, chunked paragraphs
 * - Crawl reminders for robots.txt, sitemap
 */

// Register settings and plugin admin menu
require_once plugin_dir_path(__FILE__) . 'settings.php';

// Enqueue styles for visual schema blocks
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('cmschema-style', plugin_dir_url(__FILE__) . 'css/cmschema.css');
});

// Render schema blocks above, mid-article, or below content
add_filter('the_content', function($content) {
    $post_id = get_the_ID();
    $options = get_post_meta($post_id, '_cmschema_options', true);
    $out = '';

    // TL;DR summary box at the top
    if (!empty($options['tldr'])) {
        $out .= '<div class="cmschema-tldr-box"><strong>TL;DR:</strong> ' . esc_html($options['tldr']) . '</div>';
    }

    // Visual FAQ blocks
    if (!empty($options['faq'])) {
        $faqs = explode("\n", $options['faq']);
        $out .= '<section class="cmschema-faq-blocks"><h2>Frequently Asked Questions</h2><ul>';
        foreach ($faqs as $line) {
            $parts = explode('|', $line, 2);
            if (count($parts) === 2) {
                $question = esc_html(trim($parts[0]));
                $answer = esc_html(trim($parts[1]));
                $out .= "<li><b>Q:</b> {$question}<br /><b>A:</b> {$answer}</li>";
            }
        }
        $out .= '</ul></section>';
    }

    // Canonical hub link
    if (!empty($options['canonical'])) {
        $out .= '<div class="cmschema-canonical-link">Canonical context: <a href="' . esc_url($options['canonical']) . '">' . esc_html($options['canonical']) . '</a></div>';
    }

    // Chunk paragraphs into skimmable blocks
    $chunks = preg_split('/\n|\r\n/', $content);
    $chunked_content = '';
    foreach ($chunks as $para) {
        if (trim($para) !== '') {
            $chunked_content .= '<p>' . esc_html($para) . '</p>';
        }
    }

    // Insert reminders for AI bot crawling compliance
    $out .= '<div class="cmschema-ai-reminder"><small>Reminder: Ensure robots.txt/sitemap allow major AI bots.</small></div>';

    return $out . $chunked_content;
});

// Add FAQPage schema in JSON-LD to <head>
add_action('wp_head', function() {
    if (is_single() || is_page()) {
        $post_id = get_the_ID();
        $options = get_post_meta($post_id, '_cmschema_options', true);
        if (!empty($options['faq'])) {
            $faqs = explode("\n", $options['faq']);
            $faq_arr = [];
            foreach ($faqs as $line) {
                $parts = explode('|', $line, 2);
                if (count($parts) === 2) {
                    $faq_arr[] = [
                        "@type" => "Question",
                        "name" => trim($parts[0]),
                        "acceptedAnswer" => [
                            "@type" => "Answer",
                            "text" => trim($parts[1])
                        ]
                    ];
                }
            }
            if (!empty($faq_arr)) {
                echo '<script type="application/ld+json">' . json_encode([
                    "@context" => "https://schema.org",
                    "@type" => "FAQPage",
                    "mainEntity" => $faq_arr
                ]) . '</script>';
            }
        }
    }
});

// Shortcode for spicy links
add_shortcode('cmschema_link', function($atts) {
    $url = isset($atts['url']) ? esc_url($atts['url']) : '#';
    $text = isset($atts['text']) ? esc_html($atts['text']) : 'Read more';
    return '<a href="' . $url . '" class="cmschema-spicy-link">' . $text . '</a>';
});

// Shortcode for pull quotes
add_shortcode('cmschema_quote', function($atts, $content = null) {
    return '<blockquote class="cmschema-quote">' . esc_html($content) . '</blockquote>';
});