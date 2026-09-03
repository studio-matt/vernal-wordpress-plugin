<?php
/**
 * RAG ingestion eligibility + category exclusions (WP is source of truth).
 *
 * @package VernalContentum
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Rag_Eligibility {

    const OPTION_KEY = 'vernal_contentum_rag';
    const LOCK_KEY   = 'vernal_rag_exclusions_lock';
    const LOCK_TTL   = 5;

    /**
     * @return int[]
     */
    public static function get_excluded_category_ids() {
        $raw = get_option(self::OPTION_KEY, array());
        if (!is_array($raw)) {
            $raw = array();
        }
        return self::normalize_id_list(isset($raw['excluded_category_ids']) ? $raw['excluded_category_ids'] : array());
    }

    /**
     * @param mixed $raw
     * @return int[]
     */
    public static function normalize_id_list($raw) {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw);
        }
        if (!is_array($raw)) {
            return array();
        }
        $out = array();
        foreach ($raw as $v) {
            $i = (int) $v;
            if ($i > 0 && !in_array($i, $out, true)) {
                $out[] = $i;
            }
        }
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /**
     * Persist exclusions under lock. Returns false if lock not acquired.
     *
     * @param int[] $ids
     * @return bool
     */
    public static function save_excluded_category_ids($ids) {
        $ids = self::normalize_id_list($ids);
        $payload = array('excluded_category_ids' => $ids);
        return update_option(self::OPTION_KEY, $payload, false);
    }

    /**
     * Acquire short exclusive lock for RMW. Returns true if held.
     */
    public static function acquire_lock($retries = 8, $sleep_us = 50000) {
        for ($i = 0; $i < $retries; $i++) {
            // add_option is atomic for first writer
            if (add_option(self::LOCK_KEY, (string) time(), '', 'no')) {
                return true;
            }
            $started = (int) get_option(self::LOCK_KEY, 0);
            if ($started > 0 && (time() - $started) > self::LOCK_TTL) {
                delete_option(self::LOCK_KEY);
                if (add_option(self::LOCK_KEY, (string) time(), '', 'no')) {
                    return true;
                }
            }
            usleep($sleep_us);
        }
        return false;
    }

    public static function release_lock() {
        delete_option(self::LOCK_KEY);
    }

    /**
     * Locked append. Returns array with excluded_category_ids, changed, affected_count.
     *
     * @param int $category_id
     * @return array|WP_Error
     */
    public static function add_excluded_category($category_id) {
        $category_id = (int) $category_id;
        if ($category_id < 1) {
            return new WP_Error('invalid_category', 'category_id required', array('status' => 400));
        }
        if (!self::acquire_lock()) {
            return new WP_Error('locked', 'Exclusions are busy; retry shortly.', array('status' => 503));
        }
        try {
            $ids = self::get_excluded_category_ids();
            $changed = !in_array($category_id, $ids, true);
            if ($changed) {
                $ids[] = $category_id;
                self::save_excluded_category_ids($ids);
                $ids = self::get_excluded_category_ids();
            }
            $affected = $changed ? self::count_posts_in_category($category_id) : 0;
            return array(
                'excluded_category_ids' => $ids,
                'affected_count'        => $affected,
                'changed'               => $changed,
            );
        } finally {
            self::release_lock();
        }
    }

    /**
     * Locked remove.
     *
     * @param int $category_id
     * @return array|WP_Error
     */
    public static function remove_excluded_category($category_id) {
        $category_id = (int) $category_id;
        if ($category_id < 1) {
            return new WP_Error('invalid_category', 'category_id required', array('status' => 400));
        }
        if (!self::acquire_lock()) {
            return new WP_Error('locked', 'Exclusions are busy; retry shortly.', array('status' => 503));
        }
        try {
            $ids = self::get_excluded_category_ids();
            $changed = in_array($category_id, $ids, true);
            if ($changed) {
                $ids = array_values(array_filter($ids, function ($i) use ($category_id) {
                    return (int) $i !== $category_id;
                }));
                self::save_excluded_category_ids($ids);
                $ids = self::get_excluded_category_ids();
            }
            $affected = $changed ? self::count_posts_in_category($category_id) : 0;
            return array(
                'excluded_category_ids' => $ids,
                'affected_count'        => $affected,
                'changed'               => $changed,
            );
        } finally {
            self::release_lock();
        }
    }

    /**
     * Full replace under lock (bulk/import).
     *
     * @param int[] $ids
     * @return array|WP_Error
     */
    public static function replace_excluded_categories($ids) {
        if (!self::acquire_lock()) {
            return new WP_Error('locked', 'Exclusions are busy; retry shortly.', array('status' => 503));
        }
        try {
            $before = self::get_excluded_category_ids();
            $next = self::normalize_id_list($ids);
            $changed = ($before !== $next);
            if ($changed) {
                self::save_excluded_category_ids($next);
                $next = self::get_excluded_category_ids();
            }
            return array(
                'excluded_category_ids' => $next,
                'affected_count'        => 0,
                'changed'               => $changed,
            );
        } finally {
            self::release_lock();
        }
    }

    /**
     * @param int $category_id
     * @return int
     */
    public static function count_posts_in_category($category_id) {
        $q = new WP_Query(array(
            'post_type'              => 'post',
            'post_status'            => 'publish',
            'posts_per_page'         => 1,
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'category__in'           => array((int) $category_id),
            'ignore_sticky_posts'    => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ));
        return (int) $q->found_posts;
    }

    /**
     * ANY-match: post has at least one excluded category.
     *
     * @param int|WP_Post $post
     * @return bool
     */
    public static function is_rag_category_excluded($post) {
        $post = get_post($post);
        if (!$post) {
            return true;
        }
        $excluded = self::get_excluded_category_ids();
        if (!$excluded) {
            return false;
        }
        $cats = wp_get_post_categories($post->ID);
        foreach ($cats as $c) {
            if (in_array((int) $c, $excluded, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Full RAG eligibility (base + optional category check).
     *
     * @param int|WP_Post $post
     * @param array       $opts bypass_rag_category_exclusion => bool
     * @return bool
     */
    public static function is_post_eligible($post, $opts = array()) {
        $post = get_post($post);
        if (!$post || $post->post_type !== 'post' || $post->post_status !== 'publish') {
            return false;
        }
        if (!empty($post->post_password)) {
            return false;
        }
        $permalink = get_permalink($post);
        if (!$permalink || is_wp_error($permalink)) {
            return false;
        }
        if (class_exists('Vernal_Semantic_Content')) {
            $kind = Vernal_Semantic_Content::get_instance()->detect_kind($post);
            if ($kind === 'show_landing') {
                return false;
            }
        } elseif (get_post_meta($post->ID, 'vernal_episode_id', true)) {
            return false;
        }
        $bypass = !empty($opts['bypass_rag_category_exclusion']);
        if (!$bypass && self::is_rag_category_excluded($post)) {
            return false;
        }
        return true;
    }

    /**
     * Resolve excluded ids to category rows for UI.
     *
     * @return array{excluded_category_ids:int[], categories:array}
     */
    public static function get_exclusions_payload() {
        $ids = self::get_excluded_category_ids();
        $categories = array();
        foreach ($ids as $id) {
            $term = get_category($id);
            if ($term && !is_wp_error($term)) {
                $categories[] = array(
                    'id'   => (int) $term->term_id,
                    'name' => (string) $term->name,
                    'slug' => (string) $term->slug,
                );
            } else {
                $categories[] = array(
                    'id'   => (int) $id,
                    'name' => sprintf('Category #%d (missing)', $id),
                    'slug' => '',
                );
            }
        }
        return array(
            'excluded_category_ids' => $ids,
            'categories'            => $categories,
        );
    }
}
