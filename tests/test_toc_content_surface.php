<?php
/**
 * TOC jump links belong on singular posts, not the homepage or blog.
 * Run: php tests/test_toc_content_surface.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require_once dirname(__DIR__) . '/includes/class-vernal-schema.php';

function assert_true($cond, $msg) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

$article = array(
    'admin' => false,
    'singular' => true,
    'front_page' => false,
    'home' => false,
    'page_on_front' => 2092,
    'page_for_posts' => 40,
);

assert_true(
    Vernal_Schema::toc_allowed_for_view('post', 501, $article),
    'singular article post gets jump links'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('page', 2092, array_merge($article, array(
        'front_page' => true,
    ))),
    'homepage page does not get jump links'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('page', 88, $article),
    'ordinary Page does not get jump links'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('page', 40, array_merge($article, array(
        'singular' => false,
        'home' => true,
    ))),
    'blog index does not get jump links'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('post', 40, $article),
    'posts page id is excluded even if queried as a post'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('post', 2092, $article),
    'front page id is excluded even if it is a post'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('post', 501, array_merge($article, array(
        'singular' => false,
        'home' => true,
        'front_page' => true,
    ))),
    'latest-posts homepage does not get jump links'
);

assert_true(
    !Vernal_Schema::toc_allowed_for_view('post', 501, array_merge($article, array(
        'admin' => true,
    ))),
    'admin does not inject jump links'
);

echo "All TOC content-surface checks passed.\n";
exit(0);
