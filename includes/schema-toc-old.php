<?php
/*
 * Schema TOC Helper
 * - Adds Table of Contents functionality and connects with FAQ/TLDR metadata.
 */
function cmschema_generate_toc($content) {
    preg_match_all('/<h[2-3][^>]*>(.*?)<\/h[2-3]>/', $content, $matches);
    if (!empty($matches[1])) {
        $toc = '<nav class="cmschema-toc"><ul>';
        foreach ($matches[1] as $heading) {
            $anchor = sanitize_title($heading);
            $toc .= '<li><a href="#' . $anchor . '">' . esc_html($heading) . '</a></li>';
        }
        $toc .= '</ul></nav>';
        $content = preg_replace_callback(
            '/<h([2-3])([^>]*)>(.*?)<\/h\1>/',
            function($m) {
                $anchor = sanitize_title($m[3]);
                return '<h' . $m[1] . $m[2] . ' id="' . $anchor . '">' . $m[3] . '</h' . $m[1] . '>';
            },
            $content
        );
        return $toc . $content;
    }
    return $content;
}
add_filter('the_content', 'cmschema_generate_toc', 5);
