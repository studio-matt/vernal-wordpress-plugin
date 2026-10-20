<?php
/**
 * Lightweight contract checks for image SEO helpers (no WP bootstrap required for pure logic).
 * Run: php tests/test_image_seo_contract.php
 */

function assert_true($cond, $msg) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

// Mirror precedence rule in docs form for CI without loading WP.
function resolve_featured_image_params_sim($params) {
    $seo = array();
    $url = '';
    if (!empty($params['featured_image']) && is_array($params['featured_image'])) {
        $fi = $params['featured_image'];
        $fi_url = isset($fi['url']) ? (string) $fi['url'] : '';
        if ($fi_url !== '') {
            $url = $fi_url;
            foreach (array('title', 'alt', 'caption', 'description') as $k) {
                if (array_key_exists($k, $fi)) {
                    $seo[$k] = (string) $fi[$k];
                }
            }
        }
    }
    if ($url === '') {
        if (!empty($params['thumbnail_url'])) {
            $url = (string) $params['thumbnail_url'];
        } elseif (!empty($params['featured_image_url'])) {
            $url = (string) $params['featured_image_url'];
        }
        if ($url !== '' && !empty($params['featured_image']) && is_array($params['featured_image'])) {
            foreach (array('title', 'alt', 'caption', 'description') as $k) {
                if (array_key_exists($k, $params['featured_image'])) {
                    $seo[$k] = (string) $params['featured_image'][$k];
                }
            }
        }
    }
    return array('url' => $url, 'seo' => $seo);
}

$legacy = resolve_featured_image_params_sim(array(
    'featured_image_url' => 'https://cdn.example/old.png',
));
assert_true($legacy['url'] === 'https://cdn.example/old.png', 'legacy URL-only works');
assert_true(empty($legacy['seo']), 'legacy has no SEO');

$both = resolve_featured_image_params_sim(array(
    'featured_image_url' => 'https://cdn.example/legacy.png',
    'featured_image' => array(
        'url' => 'https://cdn.example/new.png',
        'title' => 'Guest — Show',
        'alt' => 'alt',
        'caption' => 'cap',
        'description' => 'desc',
    ),
));
assert_true($both['url'] === 'https://cdn.example/new.png', 'structured featured_image wins URL');
assert_true($both['seo']['title'] === 'Guest — Show', 'SEO title applied');

$dual_url_seo = resolve_featured_image_params_sim(array(
    'featured_image_url' => 'https://cdn.example/same.png',
    'featured_image' => array(
        'title' => 'T',
        'alt' => '',
        'caption' => 'C',
        'description' => 'D',
    ),
));
assert_true($dual_url_seo['url'] === 'https://cdn.example/same.png', 'URL from legacy when featured_image has no url');
assert_true($dual_url_seo['seo']['title'] === 'T', 'SEO still taken from featured_image object');

echo "All image SEO contract checks passed.\n";
exit(0);
