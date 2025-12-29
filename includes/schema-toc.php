<?php
// Insert visual TOC in front-end content
add_filter('the_content', function($content) {
    if (!is_singular() || is_admin()) return $content;
    $options = get_option('cmschema_options');
    if (empty($options['show_toc'])) return $content;
    $headings = cmschema_get_headings($content);
    if (count($headings) < 2) return $content;
    $label = esc_html($options['toc_label'] ?? 'In This Article...');
    $style = $options['toc_style'] ?? 'bullets';
    $toc = "<nav class='cmschema-toc'><strong>{$label}</strong>";
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
    $content = cmschema_add_ids_to_headings($content, $headings);
    return $toc . $content;
}, 8);

function cmschema_get_headings($content) {
    $headings = [];
    if (preg_match_all('/<h([2-6])([^>]*)>(.*?)<\/h\1>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[0] as $i => $full) {
            $text = strip_tags($matches[3][$i][0]);
            if (preg_match('/id=["\']([^"\']+)["\']/', $matches[2][$i][0], $idmatch)) {
                $slug = $idmatch[1];
            } else {
                $text_clean = preg_replace('/[^\w\s-]/', '', strtolower($text));
                $words = array_slice(explode(' ', $text_clean), 0, 7);
                $short_slug = implode('-', $words);
                $slug = 'cmschema-' . $short_slug;
            }
            $headings[] = [
                'text' => $text,
                'id' => $slug,
                'tag' => $matches[1][$i][0],
                'offset' => $matches[0][$i][1],
                'full' => $matches[0][$i][0]
            ];
        }
    }
    return $headings;
}

function cmschema_add_ids_to_headings($content, $headings) {
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

add_action('wp_head', function() {
    if (!is_singular() || is_admin()) return;
    $options = get_option('cmschema_options');
    if (empty($options['show_schema'])) return;

    global $post;
    $headings = cmschema_get_headings($post->post_content);
    if (count($headings) < 2) return;

    $itemList = [];
    foreach ($headings as $i => $h) {
        $itemList[] = [
            "@type" => "ListItem",
            "position" => $i + 1,
            "name" => $h['text'],
            "url" => get_permalink() . "#" . $h['id']
        ];
    }
    $articleSections = array_map(function($h){ return $h['text']; }, $headings);

    // Logo logic
    if (!empty($options['use_site_logo'])) {
        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = $custom_logo_id ? wp_get_attachment_image_url($custom_logo_id, 'full') : '';
    } else {
        $logo_url = !empty($options['logo_url']) ? $options['logo_url'] : '';
    }
    if (!$logo_url) $logo_url = 'https://irreverenthealth.com/path/to/logo.png';

    $keywords = wp_get_post_tags($post->ID, ['fields'=>'names']);
    $about = [
        [ "@type"=>"Drug", "name"=>"Ibogaine" ],
        [ "@type"=>"MedicalCondition", "name"=>"PTSD" ],
        [ "@type"=>"MedicalCondition", "name"=>"Depression" ],
        [ "@type"=>"MedicalCondition", "name"=>"Traumatic Brain Injury" ],
        [ "@type"=>"Place", "name"=>"Arizona" ]
    ];

    // Example mentions
    $mentions = [
        [
            "@type" => "Person",
            "name" => "Rick Perry",
            "sameAs" => "https://en.wikipedia.org/wiki/Rick_Perry"
        ],
        [
            "@type" => "Person",
            "name" => "Greg Abbott",
            "sameAs" => "https://en.wikipedia.org/wiki/Greg_Abbott"
        ]
    ];

    // Hero image metadata (sample, add your actual sizes!)
    $hero_image_url = get_the_post_thumbnail_url($post, 'full');
    $hero_image_meta = [];
    if ($hero_image_url) {
        $hero_image_meta = [
            "@type" => "ImageObject",
            "url" => $hero_image_url,
            "width" => 1200,     // Set your actual image width!
            "height" => 700,     // Set your actual image height!
            "caption" => "Ibogaine therapy hero image"
        ];
    }

    $summary_abstract = wp_trim_words(strip_tags($post->post_content), 40, "...");
    $citations = [
        "Arizona FY2026 budget references",
        "Psychedelic Science 2025 public remarks"
    ];

    $jsonld = [
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
        "author" => [ "@type"=>"Organization", "name"=>"Irreverent Health" ],
        "publisher" => [
            "@type"=>"Organization",
            "name"=>"Irreverent Health",
            "logo"=>[
                "@type"=>"ImageObject",
                "url"=>$logo_url
            ]
        ],
        "description" => get_post_meta($post->ID, '_aioseop_description', true) ?: wp_trim_words(strip_tags($post->post_content), 40, "..."),
        "articleSection" => $articleSections,
        "hasPart" => [
            [
                "@type" => "ItemList",
                "name" => "Table of Contents",
                "itemListElement" => $itemList
            ],
            [
                "@type" => "CreativeWork",
                "@id" => get_permalink() . "#facts",
                "name" => "Key Facts",
                "abstract" => $summary_abstract,
                "citation" => $citations
            ]
        ],
        "about" => $about,
        "keywords" => $keywords,
        "mentions" => $mentions
    ];
    echo '<script type="application/ld+json">' . json_encode($jsonld, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) . '</script>';
});

// BreadcrumbList schema
add_action('wp_head', function() {
    if (!is_singular() || is_admin()) return;
    $breadcrumb = [
        "@context"=>"https://schema.org",
        "@type"=>"BreadcrumbList",
        "itemListElement"=>[
            [
                "@type"=>"ListItem",
                "position"=>1,
                "name"=>"Irreverent Newsroom",
                "item"=>"https://irreverenthealth.com/newsroom/"
            ],
            [
                "@type"=>"ListItem",
                "position"=>2,
                "name"=>get_the_title(),
                "item"=>get_permalink()
            ]
        ]
    ];
    echo '<script type="application/ld+json">'.json_encode($breadcrumb, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).'</script>';
}, 50);

?>