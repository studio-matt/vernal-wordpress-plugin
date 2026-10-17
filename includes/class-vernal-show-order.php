<?php
/**
 * List the public `shows` category by show_number (newest first).
 * Does not change post_date. Posts without a number stay at the end.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Show_Order {
    const CATEGORY_SLUG = 'shows';

    public static function init() {
        add_action('pre_get_posts', array(__CLASS__, 'flag_shows_loop'), 20);
        add_filter('posts_clauses', array(__CLASS__, 'order_clauses'), 20, 2);
    }

    public static function flag_shows_loop($query) {
        if (!($query instanceof WP_Query)) {
            return;
        }
        if (is_admin()) {
            return;
        }
        if ($query->is_singular() || $query->get('p') || $query->get('name')) {
            return;
        }
        if (!self::query_is_only_shows_category($query)) {
            return;
        }
        $query->set('vernal_order_by_show_number', 1);
    }

    public static function order_clauses($clauses, $query) {
        if (!($query instanceof WP_Query) || !$query->get('vernal_order_by_show_number')) {
            return $clauses;
        }
        global $wpdb;
        if (strpos($clauses['join'], 'vernal_show_number') !== false) {
            return $clauses;
        }
        $clauses['join'] .= " LEFT JOIN {$wpdb->postmeta} AS vernal_show_number ON ({$wpdb->posts}.ID = vernal_show_number.post_id AND vernal_show_number.meta_key = 'show_number') ";
        $clauses['orderby'] = "CAST(vernal_show_number.meta_value AS UNSIGNED) DESC, {$wpdb->posts}.post_date DESC";
        $clauses['groupby'] = $clauses['groupby'] ? $clauses['groupby'] : "{$wpdb->posts}.ID";
        return $clauses;
    }

    /**
     * True when the query is limited to the shows category and no other category.
     * Covers category archives, category__in, and Elementor tax_query loops.
     */
    public static function query_is_only_shows_category($query) {
        $shows = get_category_by_slug(self::CATEGORY_SLUG);
        if (!$shows) {
            return false;
        }
        $shows_id = (int) $shows->term_id;
        $ids = array();

        $cat = $query->get('cat');
        if ($cat !== '' && $cat !== null && (int) $cat !== 0) {
            $ids[] = (int) $cat;
        }
        $name = $query->get('category_name');
        if (is_string($name) && $name !== '') {
            $ids[] = $name === self::CATEGORY_SLUG ? $shows_id : -1;
        }
        foreach (array('category__in', 'category__and') as $key) {
            $list = $query->get($key);
            if (!is_array($list)) {
                continue;
            }
            foreach ($list as $term_id) {
                $ids[] = (int) $term_id;
            }
        }
        $tax = $query->get('tax_query');
        if (is_array($tax)) {
            foreach ($tax as $clause) {
                if (!is_array($clause) || ($clause['taxonomy'] ?? '') !== 'category') {
                    continue;
                }
                $field = $clause['field'] ?? 'term_id';
                foreach ((array) ($clause['terms'] ?? array()) as $term) {
                    if ($field === 'slug' || $field === 'name') {
                        $ids[] = ((string) $term === self::CATEGORY_SLUG) ? $shows_id : -1;
                    } else {
                        $ids[] = (int) $term;
                    }
                }
            }
        }

        $ids = array_values(array_unique($ids));
        return count($ids) === 1 && (int) $ids[0] === $shows_id;
    }
}

Vernal_Show_Order::init();
