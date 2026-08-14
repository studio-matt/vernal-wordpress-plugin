<?php
/**
 * Vernal SEO adapter — applies SeoManifest v1 to active SEO plugins or native WP.
 *
 * All AIOSEO / future Yoast / RankMath specifics stay here.
 * Machine never sends vendor field names.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_SEO_Adapter {

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * True when a supported SEO plugin should own Article JSON-LD / meta.
     */
    public function has_supported_seo_plugin() {
        return $this->is_aioseo_active();
    }

    public function is_aioseo_active() {
        if (function_exists('aioseo')) {
            return true;
        }
        if (defined('AIOSEO_VERSION')) {
            return true;
        }
        if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            return true;
        }
        return false;
    }

    /**
     * Apply native WP fields + vendor adapter from create_post params.
     *
     * @param int   $post_id
     * @param array $params Full create_post JSON body
     */
    public function apply_from_request($post_id, $params) {
        $post_id = (int) $post_id;
        if ($post_id <= 0 || !is_array($params)) {
            return;
        }

        // Slug
        if (!empty($params['slug'])) {
            $slug = sanitize_title($params['slug']);
            if ($slug) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_name' => $slug,
                ));
            }
        }

        // Excerpt — prefer explicit excerpt, else seo.description
        $excerpt = '';
        if (!empty($params['excerpt'])) {
            $excerpt = sanitize_textarea_field($params['excerpt']);
        } elseif (!empty($params['seo']['description'])) {
            $excerpt = sanitize_textarea_field($params['seo']['description']);
        }
        if ($excerpt !== '') {
            wp_update_post(array(
                'ID' => $post_id,
                'post_excerpt' => $excerpt,
            ));
        }

        // Tags (caller may also set; safe to re-apply)
        if (!empty($params['tags']) && is_array($params['tags'])) {
            $tags = array_map('sanitize_text_field', $params['tags']);
            $tags = array_filter($tags);
            if ($tags) {
                wp_set_post_tags($post_id, $tags);
            }
        }

        $seo = isset($params['seo']) && is_array($params['seo']) ? $params['seo'] : null;
        if (!$seo) {
            return;
        }

        if ($this->is_aioseo_active()) {
            $this->apply_aioseo($post_id, $seo, $excerpt);
        }
        // Future: elseif ($this->is_yoast_active()) { ... }
    }

    /**
     * Map Vernal manifest → AIOSEO storage (implementation detail).
     */
    private function apply_aioseo($post_id, array $seo, $fallback_excerpt = '') {
        $title = isset($seo['title']) ? wp_strip_all_tags((string) $seo['title']) : '';
        $description = isset($seo['description'])
            ? wp_strip_all_tags((string) $seo['description'])
            : $fallback_excerpt;
        $primary = isset($seo['primary_keyphrase'])
            ? wp_strip_all_tags((string) $seo['primary_keyphrase'])
            : '';
        $secondaries = array();
        if (!empty($seo['secondary_keyphrases']) && is_array($seo['secondary_keyphrases'])) {
            foreach ($seo['secondary_keyphrases'] as $s) {
                $s = wp_strip_all_tags((string) $s);
                if ($s !== '') {
                    $secondaries[] = $s;
                }
            }
        }
        $social = isset($seo['social']) && is_array($seo['social']) ? $seo['social'] : array();
        $og_title = !empty($social['title']) ? wp_strip_all_tags((string) $social['title']) : $title;
        $og_desc = !empty($social['description'])
            ? wp_strip_all_tags((string) $social['description'])
            : $description;
        $canonical = !empty($seo['canonical_url']) ? esc_url_raw((string) $seo['canonical_url']) : '';

        // Prefer AIOSEO model API when available
        if (class_exists('\AIOSEO\Plugin\Common\Models\Post')) {
            try {
                $aioseo_post = \AIOSEO\Plugin\Common\Models\Post::getPost($post_id);
                if ($aioseo_post) {
                    if ($title !== '') {
                        $aioseo_post->title = $title;
                    }
                    if ($description !== '') {
                        $aioseo_post->description = $description;
                    }
                    if ($og_title !== '') {
                        $aioseo_post->og_title = $og_title;
                    }
                    if ($og_desc !== '') {
                        $aioseo_post->og_description = $og_desc;
                    }
                    if ($canonical !== '') {
                        $aioseo_post->canonical_url = $canonical;
                    }
                    if ($primary !== '') {
                        $keyphrases = array(
                            'focus' => array(
                                'keyphrase' => $primary,
                                'score' => 0,
                                'analysis' => new \stdClass(),
                            ),
                            'additional' => array(),
                        );
                        foreach ($secondaries as $i => $sec) {
                            $keyphrases['additional'][] = array(
                                'keyphrase' => $sec,
                                'score' => 0,
                                'analysis' => new \stdClass(),
                            );
                        }
                        $aioseo_post->keyphrases = wp_json_encode($keyphrases);
                    }
                    $aioseo_post->save();
                    return;
                }
            } catch (Exception $e) {
                // Fall through to meta / table helpers
            } catch (Throwable $e) {
                // Fall through
            }
        }

        // Compatibility duplicates (not canonical for AIOSEO, but harmless)
        if ($title !== '') {
            update_post_meta($post_id, '_aioseo_title', $title);
        }
        if ($description !== '') {
            update_post_meta($post_id, '_aioseo_description', $description);
        }
        if ($primary !== '') {
            update_post_meta($post_id, '_aioseo_keywords', $primary);
        }

        // Direct table write as last resort when Models API unavailable
        global $wpdb;
        $table = $wpdb->prefix . 'aioseo_posts';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $wpdb->esc_like($table)
        ));
        if ($exists !== $table) {
            return;
        }

        $keyphrases_json = null;
        if ($primary !== '') {
            $kp = array(
                'focus' => array(
                    'keyphrase' => $primary,
                    'score' => 0,
                    'analysis' => new \stdClass(),
                ),
                'additional' => array(),
            );
            foreach ($secondaries as $sec) {
                $kp['additional'][] = array(
                    'keyphrase' => $sec,
                    'score' => 0,
                    'analysis' => new \stdClass(),
                );
            }
            $keyphrases_json = wp_json_encode($kp);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $row_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE post_id = %d",
            $post_id
        ));

        $now = current_time('mysql');
        $data = array(
            'post_id' => $post_id,
            'updated' => $now,
        );
        if ($title !== '') {
            $data['title'] = $title;
        }
        if ($description !== '') {
            $data['description'] = $description;
        }
        if ($og_title !== '') {
            $data['og_title'] = $og_title;
        }
        if ($og_desc !== '') {
            $data['og_description'] = $og_desc;
        }
        if ($canonical !== '') {
            $data['canonical_url'] = $canonical;
        }
        if ($keyphrases_json !== null) {
            $data['keyphrases'] = $keyphrases_json;
        }

        if ($row_id) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->update($table, $data, array('id' => (int) $row_id));
        } else {
            $data['created'] = $now;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery
            $wpdb->insert($table, $data);
        }
    }
}
