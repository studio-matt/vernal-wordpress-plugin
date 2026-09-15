#!/usr/bin/env php
<?php
/**
 * Focused harness for Vernal_Internal_Link_Inserter (no full WP bootstrap).
 *
 * Run: php tests/test_internal_link_inserter.php
 */

define('ABSPATH', __DIR__ . '/');

// Minimal WP stubs
function esc_attr($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_html($t) { return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8'); }
function esc_url($t) { return (string) $t; }
function home_url() { return 'https://example.com'; }
function wp_parse_url($url, $component = -1) {
    $p = parse_url($url);
    if ($component === -1) return $p;
    $map = array(
        PHP_URL_SCHEME => 'scheme',
        PHP_URL_HOST => 'host',
        PHP_URL_PATH => 'path',
        PHP_URL_QUERY => 'query',
    );
    $k = $map[$component] ?? null;
    return $k && isset($p[$k]) ? $p[$k] : null;
}
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function has_blocks($c) { return strpos((string) $c, '<!-- wp:') !== false; }
function parse_blocks($content) {
    // Minimal: treat whole content as freeform if no real parser
    return array(
        array(
            'blockName' => 'core/paragraph',
            'attrs' => array(),
            'innerBlocks' => array(),
            'innerHTML' => $content,
            'innerContent' => array($content),
        ),
    );
}
function serialize_blocks($blocks) {
    $out = '';
    foreach ($blocks as $b) {
        $out .= isset($b['innerHTML']) ? $b['innerHTML'] : '';
    }
    return $out;
}

require_once dirname(__DIR__) . '/includes/class-vernal-internal-link-inserter.php';

$failed = 0;
function assert_true($cond, $msg) {
    global $failed;
    if (!$cond) {
        echo "FAIL: $msg\n";
        $failed++;
    } else {
        echo "OK: $msg\n";
    }
}

// Headings untouched / phrase in heading+paragraph chooses paragraph
$html = '<h2>podcast microphone tips</h2><p>Before recording, choosing the right podcast microphone can help.</p>';
$anchor = '<a class="vernal-il" data-vernal-il="1" data-vernal-il-id="vil_test1" data-vernal-il-target="123" href="https://example.com/mic/">choosing the right podcast microphone</a>';
$r = Vernal_Internal_Link_Inserter::insert_into_html_fragment($html, 'choosing the right podcast microphone', $anchor);
assert_true($r['inserted'], 'inserts in paragraph');
assert_true(strpos($r['html'], '<h2>podcast microphone tips</h2>') !== false, 'heading unchanged');
assert_true(strpos($r['html'], 'data-vernal-il-id="vil_test1"') !== false, 'mutation id present');

// Existing <a> with phrase is masked / rejected
$html2 = '<p>See <a href="/x/">choosing the right podcast microphone</a> today.</p><p>Also choosing the right podcast microphone later.</p>';
$r2 = Vernal_Internal_Link_Inserter::insert_into_html_fragment($html2, 'choosing the right podcast microphone', $anchor);
assert_true($r2['inserted'], 'skips linked occurrence, uses second');
assert_true(substr_count($r2['html'], 'data-vernal-il-id="vil_test1"') === 1, 'only one vernal link');

// code/pre untouched
$html3 = '<pre>choosing the right podcast microphone</pre><p>choosing the right podcast microphone in prose</p>';
$r3 = Vernal_Internal_Link_Inserter::insert_into_html_fragment($html3, 'choosing the right podcast microphone', $anchor);
assert_true($r3['inserted'], 'inserts outside pre');
assert_true(preg_match('/<pre>choosing the right podcast microphone<\/pre>/', $r3['html']), 'pre unchanged');

// Undo by mutation id
$content = $r['html'];
$u = Vernal_Internal_Link_Inserter::unwrap_by_mutation_id($content, 'vil_test1');
assert_true($u['unwrapped'], 'undo finds mutation');
assert_true(strpos($u['content'], 'data-vernal-il') === false, 'anchor removed');
assert_true(strpos($u['content'], 'choosing the right podcast microphone') !== false, 'text preserved');

// Edited anchor text still unwraps
$edited = str_replace('>choosing the right podcast microphone</a>', '>edited anchor text</a>', $content);
$u2 = Vernal_Internal_Link_Inserter::unwrap_by_mutation_id($edited, 'vil_test1');
assert_true($u2['unwrapped'], 'undo with edited text');
assert_true(strpos($u2['content'], 'edited anchor text') !== false, 'edited text kept');

// Duplicate target detection
$with = '<p>Hello <a class="vernal-il" data-vernal-il="1" data-vernal-il-id="x" data-vernal-il-target="99" href="https://example.com/a/">x</a></p>';
$ins = Vernal_Internal_Link_Inserter::insert_link($with, array(
    'phrase' => 'Hello',
    'target_wp_post_id' => 99,
    'permalink' => 'https://example.com/b/',
    'mutation_id' => 'vil_dup',
));
assert_true(!$ins['inserted'], 'blocks duplicate vernal target');

// Density analysis counts manual internal
$man = '<p><a href="https://example.com/foo/">manual</a> and more</p>';
$a = Vernal_Internal_Link_Inserter::analyze_internal_links($man, 'example.com');
assert_true($a['manual'] === 1 && $a['vernal'] === 0, 'counts manual internal');

// Fingerprint stable when only vernal anchors differ
$fp1 = Vernal_Internal_Link_Inserter::content_fingerprint('<p>Same body text here.</p>');
$fp2 = Vernal_Internal_Link_Inserter::content_fingerprint(
    '<p>Same <a class="vernal-il" data-vernal-il="1" data-vernal-il-id="z" data-vernal-il-target="1" href="/x/">body</a> text here.</p>'
);
assert_true($fp1 === $fp2, 'fingerprint ignores vernal anchors');

// First eligible occurrence deterministic
$html4 = '<p>alpha beta gamma</p><p>alpha beta gamma again</p>';
$anchor4 = '<a class="vernal-il" data-vernal-il="1" data-vernal-il-id="vil_first" data-vernal-il-target="5" href="https://example.com/t/">alpha beta</a>';
$r4 = Vernal_Internal_Link_Inserter::insert_into_html_fragment($html4, 'alpha beta', $anchor4);
assert_true($r4['inserted'], 'first occurrence insert');
$pos_link = strpos($r4['html'], 'vil_first');
$pos_again = strpos($r4['html'], 'again');
assert_true($pos_link !== false && $pos_again !== false && $pos_link < $pos_again, 'links first paragraph');

// Banned click here
$r5 = Vernal_Internal_Link_Inserter::insert_link('<p>please click here now</p>', array(
    'phrase' => 'click here',
    'target_wp_post_id' => 1,
    'permalink' => 'https://example.com/t/',
    'mutation_id' => 'vil_bad',
));
assert_true(!$r5['inserted'], 'rejects click here');

// insertable_plain_text excludes headings / anchors (aligns with Machine grounding)
$plain = Vernal_Internal_Link_Inserter::insertable_plain_text(
    '<h2>Peptide Therapy Guide</h2><p>Patients ask about peptide therapy often.</p><p>See <a href="/x/">other</a>.</p>'
);
assert_true(stripos($plain, 'Peptide Therapy Guide') === false, 'plain excludes heading');
assert_true(stripos($plain, 'peptide therapy often') !== false, 'plain keeps paragraph');
assert_true(stripos($plain, 'other') === false, 'plain excludes existing anchor text');

// HTML entity variant of phrase
$html_ent = '<p>Health &amp; Wellness basics matter.</p>';
$anchor_ent = '<a class="vernal-il" data-vernal-il="1" data-vernal-il-id="vil_ent" data-vernal-il-target="7" href="https://example.com/hw/">Health & Wellness</a>';
$r_ent = Vernal_Internal_Link_Inserter::insert_into_html_fragment($html_ent, 'Health & Wellness', $anchor_ent);
assert_true($r_ent['inserted'], 'inserts entity-encoded phrase');

// Classic fallback when Gutenberg has no safe block match
$blocks = "<!-- wp:heading --><h2>only in heading peptide therapy</h2><!-- /wp:heading -->\n"
    . "<!-- wp:paragraph --><p>Body has peptide therapy for readers.</p><!-- /wp:paragraph -->";
$r_blocks = Vernal_Internal_Link_Inserter::insert_link($blocks, array(
    'phrase' => 'peptide therapy',
    'target_wp_post_id' => 9,
    'permalink' => 'https://example.com/p/',
    'mutation_id' => 'vil_blk',
));
assert_true($r_blocks['inserted'], 'block walk or classic fallback inserts');

if ($failed > 0) {
    echo "\n$failed failure(s)\n";
    exit(1);
}
echo "\nAll inserter harness checks passed.\n";
exit(0);
