<?php
/**
 * Safe in-body internal link insertion / undo for Gutenberg + classic content.
 *
 * @package VernalContentum
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Internal_Link_Inserter {

    const ANCHOR_CLASS = 'vernal-il';

    /** @var array<string,bool> */
    private static $safe_blocks = array(
        'core/paragraph' => true,
        'core/list'      => true,
        'core/list-item' => true,
        'core/freeform'  => true,
        'core/html'      => true,
    );

    /**
     * Count existing internal links (manual + Vernal) in HTML/content.
     *
     * @param string $content
     * @param string $home_host Host of home_url() without www.
     * @return array{total:int,vernal:int,manual:int,target_ids:int[]}
     */
    public static function analyze_internal_links($content, $home_host = '') {
        $total = 0;
        $vernal = 0;
        $manual = 0;
        $target_ids = array();
        if (!is_string($content) || $content === '') {
            return compact('total', 'vernal', 'manual', 'target_ids');
        }
        if (preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $attrs = $m[1];
                $is_vernal = (strpos($attrs, 'data-vernal-il') !== false || strpos($attrs, 'vernal-il') !== false);
                $href = '';
                if (preg_match('/href=["\']([^"\']+)["\']/', $attrs, $hm)) {
                    $href = $hm[1];
                }
                $internal = self::href_is_internal($href, $home_host);
                if (!$internal && !$is_vernal) {
                    continue;
                }
                $total++;
                if ($is_vernal) {
                    $vernal++;
                    if (preg_match('/data-vernal-il-target=["\'](\d+)["\']/', $attrs, $tm)) {
                        $target_ids[] = (int) $tm[1];
                    }
                } else {
                    $manual++;
                }
            }
        }
        return array(
            'total'      => $total,
            'vernal'     => $vernal,
            'manual'     => $manual,
            'target_ids' => array_values(array_unique($target_ids)),
        );
    }

    /**
     * @param string $href
     * @param string $home_host
     */
    public static function href_is_internal($href, $home_host = '') {
        $href = trim((string) $href);
        if ($href === '' || $href === '#' || strpos($href, 'mailto:') === 0 || strpos($href, 'tel:') === 0) {
            return false;
        }
        if (strpos($href, '/') === 0 && strpos($href, '//') !== 0) {
            return true;
        }
        $host = wp_parse_url($href, PHP_URL_HOST);
        if (!$host) {
            return false;
        }
        $home_host = $home_host ? $home_host : (string) wp_parse_url(home_url(), PHP_URL_HOST);
        $norm = function ($h) {
            $h = strtolower((string) $h);
            return preg_replace('/^www\./', '', $h);
        };
        return $norm($host) === $norm($home_host);
    }

    /**
     * Fingerprint content with Vernal anchors unwrapped (editorial change detection).
     *
     * @param string $content
     * @return string
     */
    public static function content_fingerprint($content) {
        $plain = self::unwrap_all_vernal_anchors((string) $content);
        $plain = wp_strip_all_tags($plain);
        $plain = preg_replace('/\s+/', ' ', $plain);
        $plain = trim((string) $plain);
        return hash('sha256', $plain);
    }

    /**
     * Unwrap all Vernal IL anchors to plain text.
     *
     * @param string $content
     * @return string
     */
    public static function unwrap_all_vernal_anchors($content) {
        return preg_replace_callback(
            '/<a\b([^>]*\bdata-vernal-il\b[^>]*)>(.*?)<\/a>/is',
            function ($m) {
                return $m[2];
            },
            (string) $content
        );
    }

    /**
     * Undo a single mutation by stable id.
     *
     * @param string $content
     * @param string $mutation_id
     * @return array{content:string,unwrapped:bool}
     */
    public static function unwrap_by_mutation_id($content, $mutation_id) {
        $mutation_id = preg_quote((string) $mutation_id, '/');
        $unwrapped = false;
        $new = preg_replace_callback(
            '/<a\b([^>]*\bdata-vernal-il-id=["\']' . $mutation_id . '["\'][^>]*)>(.*?)<\/a>/is',
            function ($m) use (&$unwrapped) {
                $unwrapped = true;
                return $m[2];
            },
            (string) $content,
            1
        );
        return array(
            'content'   => is_string($new) ? $new : $content,
            'unwrapped' => $unwrapped,
        );
    }

    /**
     * Insert one link for a grounded phrase into safe content surfaces.
     *
     * @param string $content
     * @param array  $args {
     *   @type string $phrase
     *   @type int    $target_wp_post_id
     *   @type string $permalink
     *   @type string $mutation_id
     * }
     * @return array{content:string,inserted:bool,reason:string}
     */
    public static function insert_link($content, $args) {
        $phrase = isset($args['phrase']) ? (string) $args['phrase'] : '';
        $target_id = isset($args['target_wp_post_id']) ? (int) $args['target_wp_post_id'] : 0;
        $permalink = isset($args['permalink']) ? (string) $args['permalink'] : '';
        $mutation_id = isset($args['mutation_id']) ? (string) $args['mutation_id'] : '';

        if ($phrase === '' || $target_id < 1 || $permalink === '' || $mutation_id === '') {
            return array('content' => $content, 'inserted' => false, 'reason' => 'missing_args');
        }
        if (preg_match('/^(click here|read more|learn more|here)$/i', trim($phrase))) {
            return array('content' => $content, 'inserted' => false, 'reason' => 'banned_anchor');
        }

        $analysis = self::analyze_internal_links($content);
        if (in_array($target_id, $analysis['target_ids'], true)) {
            return array('content' => $content, 'inserted' => false, 'reason' => 'already_vernal_target');
        }
        // Also block if any existing <a> already points at this permalink
        if (self::content_has_href($content, $permalink)) {
            return array('content' => $content, 'inserted' => false, 'reason' => 'already_href');
        }

        $anchor_html = sprintf(
            '<a class="%s" data-vernal-il="1" data-vernal-il-id="%s" data-vernal-il-target="%d" href="%s">%s</a>',
            esc_attr(self::ANCHOR_CLASS),
            esc_attr($mutation_id),
            $target_id,
            esc_url($permalink),
            esc_html($phrase)
        );

        // Prefer block-aware mutation when blocks exist.
        if (function_exists('parse_blocks') && function_exists('serialize_blocks') && has_blocks($content)) {
            $blocks = parse_blocks($content);
            $inserted = false;
            $blocks = self::walk_blocks_insert($blocks, $phrase, $anchor_html, $inserted);
            if ($inserted) {
                return array(
                    'content'  => serialize_blocks($blocks),
                    'inserted' => true,
                    'reason'   => 'ok_blocks',
                );
            }
            return array('content' => $content, 'inserted' => false, 'reason' => 'phrase_not_in_safe_blocks');
        }

        // Classic / freeform
        $result = self::insert_into_html_fragment($content, $phrase, $anchor_html);
        if ($result['inserted']) {
            return array('content' => $result['html'], 'inserted' => true, 'reason' => 'ok_classic');
        }
        return array('content' => $content, 'inserted' => false, 'reason' => $result['reason']);
    }

    /**
     * @param string $content
     * @param string $permalink
     */
    public static function content_has_href($content, $permalink) {
        $permalink = untrailingslashit($permalink);
        if ($permalink === '') {
            return false;
        }
        return (bool) preg_match(
            '/<a\b[^>]*href=["\']' . preg_quote($permalink, '/') . '\/?["\']/i',
            $content
        );
    }

    /**
     * @param array  $blocks
     * @param string $phrase
     * @param string $anchor_html
     * @param bool   $inserted
     * @return array
     */
    private static function walk_blocks_insert($blocks, $phrase, $anchor_html, &$inserted) {
        foreach ($blocks as $i => $block) {
            if ($inserted) {
                break;
            }
            $name = isset($block['blockName']) ? $block['blockName'] : null;
            if (!empty($block['innerBlocks'])) {
                $block['innerBlocks'] = self::walk_blocks_insert($block['innerBlocks'], $phrase, $anchor_html, $inserted);
                $blocks[$i] = $block;
                if ($inserted) {
                    break;
                }
            }
            if ($inserted) {
                break;
            }
            if ($name && empty(self::$safe_blocks[$name])) {
                continue;
            }
            // Skip headings even if misclassified
            if ($name && strpos($name, 'heading') !== false) {
                continue;
            }
            $html = isset($block['innerHTML']) ? $block['innerHTML'] : '';
            if ($html === '' && !empty($block['innerContent'])) {
                // Rebuild from innerContent strings
                $html = '';
                foreach ($block['innerContent'] as $piece) {
                    if (is_string($piece)) {
                        $html .= $piece;
                    }
                }
            }
            if ($html === '') {
                continue;
            }
            $frag = self::insert_into_html_fragment($html, $phrase, $anchor_html);
            if (!$frag['inserted']) {
                continue;
            }
            $block['innerHTML'] = $frag['html'];
            if (!empty($block['innerContent']) && is_array($block['innerContent'])) {
                // Replace first string segment that contains the phrase (naive but preserves structure for paragraphs)
                foreach ($block['innerContent'] as $j => $piece) {
                    if (!is_string($piece)) {
                        continue;
                    }
                    $sub = self::insert_into_html_fragment($piece, $phrase, $anchor_html);
                    if ($sub['inserted']) {
                        $block['innerContent'][$j] = $sub['html'];
                        break;
                    }
                }
            }
            $blocks[$i] = $block;
            $inserted = true;
            break;
        }
        return $blocks;
    }

    /**
     * Insert phrase link into an HTML fragment, skipping excluded elements.
     *
     * @param string $html
     * @param string $phrase
     * @param string $anchor_html
     * @return array{html:string,inserted:bool,reason:string}
     */
    public static function insert_into_html_fragment($html, $phrase, $anchor_html) {
        if ($html === '' || $phrase === '') {
            return array('html' => $html, 'inserted' => false, 'reason' => 'empty');
        }

        // Mask excluded regions so we do not match inside them.
        $masks = array();
        $masked = preg_replace_callback(
            '/<(h[1-6]|a|script|style|code|pre)\b[^>]*>.*?<\/\1>/is',
            function ($m) use (&$masks) {
                $key = '___VERNAL_MASK_' . count($masks) . '___';
                $masks[$key] = $m[0];
                return $key;
            },
            $html
        );
        if (!is_string($masked)) {
            return array('html' => $html, 'inserted' => false, 'reason' => 'mask_failed');
        }

        // Also mask shortcodes
        $masked = preg_replace_callback(
            '/\[[^\]]+\]/',
            function ($m) use (&$masks) {
                $key = '___VERNAL_MASK_' . count($masks) . '___';
                $masks[$key] = $m[0];
                return $key;
            },
            $masked
        );

        $pos = self::find_phrase_offset($masked, $phrase);
        if ($pos === null) {
            return array('html' => $html, 'inserted' => false, 'reason' => 'phrase_not_found_eligible');
        }

        $len = strlen($phrase);
        // Use actual casing from content
        $found = substr($masked, $pos, $len);
        // Rebuild anchor with found casing if phrase matched case-insensitively
        if (strcasecmp($found, $phrase) === 0 && $found !== $phrase) {
            $anchor_html = preg_replace(
                '/>' . preg_quote(esc_html($phrase), '/') . '</',
                '>' . esc_html($found) . '<',
                $anchor_html,
                1
            );
        }

        $new_masked = substr($masked, 0, $pos) . $anchor_html . substr($masked, $pos + $len);
        $restored = strtr($new_masked, $masks);
        return array('html' => $restored, 'inserted' => true, 'reason' => 'ok');
    }

    /**
     * First eligible case-insensitive offset of phrase in text that is not mid-tag.
     *
     * @param string $haystack
     * @param string $phrase
     * @return int|null
     */
    public static function find_phrase_offset($haystack, $phrase) {
        $haystack = (string) $haystack;
        $phrase = (string) $phrase;
        if ($phrase === '' || $haystack === '') {
            return null;
        }
        $offset = 0;
        $lower_h = strtolower($haystack);
        $lower_p = strtolower($phrase);
        $plen = strlen($phrase);
        while (($pos = strpos($lower_h, $lower_p, $offset)) !== false) {
            // Reject if inside an HTML tag
            $before = substr($haystack, 0, $pos);
            $last_lt = strrpos($before, '<');
            $last_gt = strrpos($before, '>');
            if ($last_lt !== false && ($last_gt === false || $last_lt > $last_gt)) {
                $offset = $pos + 1;
                continue;
            }
            // Reject if inside a mask token
            if (preg_match('/___VERNAL_MASK_\d+___/', substr($haystack, max(0, $pos - 20), $plen + 40))) {
                // Check specifically
                if (preg_match('/___VERNAL_MASK_\d+___/', substr($haystack, $pos, $plen))) {
                    $offset = $pos + 1;
                    continue;
                }
            }
            return $pos;
        }
        return null;
    }

    /**
     * Generate a stable unique mutation id.
     *
     * @return string
     */
    public static function new_mutation_id() {
        try {
            return 'vil_' . bin2hex(random_bytes(8));
        } catch (Exception $e) {
            return 'vil_' . uniqid('', true);
        }
    }
}
