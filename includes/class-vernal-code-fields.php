<?php
/**
 * Programmatic ACF field group for Machine QR + AR code fields.
 * Stable field keys — do not change between plugin releases.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Code_Fields {

    const GROUP_KEY = 'group_vernal_machine_codes';
    const GROUP_TITLE = 'Machine QR & AR Codes';

    /** Machine-owned fields that WP clients must not overwrite via unauthorized edit. */
    public static $machine_owned_keys = array(
        'machine_qr_public_id',
        'machine_qr_analytics_id',
        'machine_qr_redirect_url',
        'machine_ar_public_id',
        'machine_ar_analytics_id',
        'machine_ar_launch_url',
    );

    public static function init() {
        add_action('acf/init', array(__CLASS__, 'register_field_group'));
        // Also try on init if ACF already loaded
        add_action('init', array(__CLASS__, 'maybe_register'), 20);
        // Elementor Posts widget: set Query ID = machine_show_blog_articles
        add_action('elementor/query/machine_show_blog_articles', array(__CLASS__, 'elementor_query_show_blog_articles'));
    }

    /**
     * Filter Elementor Posts query to this show's Blog category leaf
     * (machine_blog_category_id on the current singular post).
     */
    public static function elementor_query_show_blog_articles($query) {
        $post_id = get_the_ID();
        if (!$post_id && !empty($GLOBALS['post']->ID)) {
            $post_id = (int) $GLOBALS['post']->ID;
        }
        if (!$post_id) {
            return;
        }
        $cat_id = 0;
        if (function_exists('get_field')) {
            $cat_id = intval(get_field('machine_blog_category_id', $post_id));
        }
        if ($cat_id <= 0) {
            $cat_id = intval(get_post_meta($post_id, 'machine_blog_category_id', true));
        }
        if ($cat_id > 0) {
            $query->set('cat', $cat_id);
            $query->set('category__in', array($cat_id));
        }
    }

    public static function maybe_register() {
        if (function_exists('acf_add_local_field_group') && !did_action('acf/init')) {
            // acf/init will fire; if ACF free without acf/init timing, register now
        }
        if (function_exists('acf_add_local_field_group')) {
            self::register_field_group();
        }
    }

    public static function is_acf_active() {
        return function_exists('acf_add_local_field_group') || class_exists('ACF');
    }

    public static function is_group_registered() {
        if (!function_exists('acf_get_local_field_groups')) {
            return self::is_acf_active();
        }
        $groups = acf_get_local_field_groups();
        if (!is_array($groups)) {
            return false;
        }
        foreach ($groups as $g) {
            if (!empty($g['key']) && $g['key'] === self::GROUP_KEY) {
                return true;
            }
        }
        return false;
    }

    private static function field($key, $name, $label, $type, $args = array()) {
        $base = array(
            'key' => $key,
            'label' => $label,
            'name' => $name,
            'type' => $type,
            'instructions' => isset($args['instructions']) ? $args['instructions'] : '',
            'required' => 0,
            'conditional_logic' => 0,
            'wrapper' => array('width' => '', 'class' => '', 'id' => ''),
        );
        if (!empty($args['readonly'])) {
            $base['readonly'] = 1;
            $base['disabled'] = 1;
        }
        if (isset($args['default_value'])) {
            $base['default_value'] = $args['default_value'];
        }
        if ($type === 'true_false') {
            $base['ui'] = 1;
            $base['ui_on_text'] = 'Yes';
            $base['ui_off_text'] = 'No';
        }
        if ($type === 'select' && !empty($args['choices'])) {
            $base['choices'] = $args['choices'];
            $base['allow_null'] = 0;
            $base['multiple'] = 0;
            $base['ui'] = 0;
            $base['return_format'] = 'value';
        }
        if ($type === 'url' || $type === 'text' || $type === 'number') {
            $base['placeholder'] = '';
        }
        return $base;
    }

    public static function register_field_group() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        // Avoid duplicate registration in same request
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $fields = array(
            self::field('field_machine_qr_enabled', 'machine_qr_enabled', 'QR Enabled', 'true_false', array(
                'default_value' => 1,
                'instructions' => 'Include QR artwork in new shirt builds. Owned by Machine.',
            )),
            self::field('field_machine_qr_public_id', 'machine_qr_public_id', 'QR Public ID', 'text', array(
                'readonly' => true,
                'instructions' => 'Permanent QR routing identifier (Machine-owned).',
            )),
            self::field('field_machine_qr_analytics_id', 'machine_qr_analytics_id', 'QR Analytics ID', 'text', array(
                'readonly' => true,
                'instructions' => 'Immutable analytics rollup ID (Machine-owned).',
            )),
            self::field('field_machine_qr_redirect_url', 'machine_qr_redirect_url', 'QR Redirect URL', 'url', array(
                'readonly' => true,
            )),
            self::field('field_machine_qr_destination_url', 'machine_qr_destination_url', 'QR Destination URL', 'url', array()),
            self::field('field_machine_qr_asset_url', 'machine_qr_asset_url', 'QR Asset URL', 'url', array('readonly' => true)),
            self::field('field_machine_qr_design_revision', 'machine_qr_design_revision', 'QR Design Revision', 'text', array('readonly' => true)),
            self::field('field_machine_qr_status', 'machine_qr_status', 'QR Status', 'select', array(
                'choices' => array(
                    'pending' => 'pending',
                    'ready' => 'ready',
                    'invalid' => 'invalid',
                    'disabled' => 'disabled',
                    'error' => 'error',
                ),
                'default_value' => 'pending',
            )),
            self::field('field_machine_ar_enabled', 'machine_ar_enabled', 'AR Enabled', 'true_false', array(
                'default_value' => 0,
            )),
            self::field('field_machine_ar_public_id', 'machine_ar_public_id', 'AR Public ID', 'text', array('readonly' => true)),
            self::field('field_machine_ar_analytics_id', 'machine_ar_analytics_id', 'AR Analytics ID', 'text', array('readonly' => true)),
            self::field('field_machine_ar_launch_url', 'machine_ar_launch_url', 'AR Launch URL', 'url', array('readonly' => true)),
            self::field('field_machine_ar_fallback_url', 'machine_ar_fallback_url', 'AR Fallback URL', 'url', array()),
            self::field('field_machine_ar_media_id', 'machine_ar_media_id', 'AR Media ID', 'number', array()),
            self::field('field_machine_ar_media_url', 'machine_ar_media_url', 'AR Media URL', 'url', array()),
            self::field('field_machine_ar_media_type', 'machine_ar_media_type', 'AR Media Type', 'text', array()),
            self::field('field_machine_ar_anchor_asset_url', 'machine_ar_anchor_asset_url', 'AR Anchor Asset URL', 'url', array('readonly' => true)),
            self::field('field_machine_ar_design_revision', 'machine_ar_design_revision', 'AR Design Revision', 'text', array('readonly' => true)),
            self::field('field_machine_ar_status', 'machine_ar_status', 'AR Status', 'select', array(
                'choices' => array(
                    'reserved' => 'reserved',
                    'media_unassigned' => 'media_unassigned',
                    'media_ready' => 'media_ready',
                    'anchor_ready' => 'anchor_ready',
                    'ready' => 'ready',
                    'active' => 'active',
                    'disabled' => 'disabled',
                    'error' => 'error',
                ),
                'default_value' => 'reserved',
            )),
            self::field('field_machine_ar_experience_version', 'machine_ar_experience_version', 'AR Experience Version', 'text', array()),
            self::field('field_machine_ar_anchor_state', 'machine_ar_anchor_state', 'AR Anchor State', 'text', array(
                'readonly' => true,
                'instructions' => 'Phase 1: heuristic_pass/fail only — not runtime validated.',
            )),
            // Per-show Blog category (ongoing articles). Landing post stays on Default Show Category.
            self::field('field_machine_blog_category_id', 'machine_blog_category_id', 'Show Blog Category ID', 'number', array(
                'readonly' => true,
                'instructions' => 'WP category id for this show\'s ongoing articles (e.g. 149). Use Elementor Query ID: machine_show_blog_articles.',
            )),
            self::field('field_machine_blog_category_slug', 'machine_blog_category_slug', 'Show Blog Category Slug', 'text', array(
                'readonly' => true,
            )),
            self::field('field_machine_blog_category_name', 'machine_blog_category_name', 'Show Blog Category Name', 'text', array(
                'readonly' => true,
            )),
        );

        acf_add_local_field_group(array(
            'key' => self::GROUP_KEY,
            'title' => self::GROUP_TITLE,
            'fields' => $fields,
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'post',
                    ),
                ),
            ),
            'menu_order' => 20,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 0,
        ));
    }

    /**
     * Read code fields from post meta / ACF.
     */
    public static function get_code_fields($post_id) {
        $names = array(
            'machine_qr_enabled', 'machine_qr_public_id', 'machine_qr_analytics_id',
            'machine_qr_redirect_url', 'machine_qr_destination_url', 'machine_qr_asset_url',
            'machine_qr_design_revision', 'machine_qr_status',
            'machine_ar_enabled', 'machine_ar_public_id', 'machine_ar_analytics_id',
            'machine_ar_launch_url', 'machine_ar_fallback_url', 'machine_ar_media_id',
            'machine_ar_media_url', 'machine_ar_media_type', 'machine_ar_anchor_asset_url',
            'machine_ar_design_revision', 'machine_ar_status', 'machine_ar_experience_version',
            'machine_ar_anchor_state',
            'machine_blog_category_id', 'machine_blog_category_slug', 'machine_blog_category_name',
        );
        $out = array();
        foreach ($names as $name) {
            $out[$name] = self::get_meta($post_id, $name);
        }
        // Normalize booleans
        $out['machine_qr_enabled'] = self::to_bool($out['machine_qr_enabled'], true);
        $out['machine_ar_enabled'] = self::to_bool($out['machine_ar_enabled'], false);
        if ($out['machine_ar_media_id'] !== '' && $out['machine_ar_media_id'] !== null) {
            $out['machine_ar_media_id'] = intval($out['machine_ar_media_id']);
        } else {
            $out['machine_ar_media_id'] = null;
        }
        return $out;
    }

    public static function set_code_fields($post_id, $data, $allow_machine_owned = true) {
        if (!is_array($data)) {
            return new WP_Error('invalid', 'Expected object of code fields');
        }
        $allowed = array_keys(self::get_code_fields($post_id));
        foreach ($data as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (!$allow_machine_owned && in_array($key, self::$machine_owned_keys, true)) {
                // Skip unauthorized replacement of Machine-owned IDs
                continue;
            }
            // When allow_machine_owned: Machine sync may set them.
            // Protect against clearing public IDs once set unless explicitly same.
            if (in_array($key, self::$machine_owned_keys, true) && !$allow_machine_owned) {
                continue;
            }
            self::set_meta($post_id, $key, $value);
        }
        return self::get_code_fields($post_id);
    }

    /**
     * Machine sync path — may set owned IDs. Rejects empty overwrite of existing public IDs.
     */
    public static function set_code_fields_from_machine($post_id, $data) {
        if (!is_array($data)) {
            return new WP_Error('invalid', 'Expected object');
        }
        $current = self::get_code_fields($post_id);
        foreach (self::$machine_owned_keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $incoming = $data[$key];
            $existing = isset($current[$key]) ? $current[$key] : '';
            if ($existing && $incoming && (string) $existing !== (string) $incoming) {
                // Do not replace an already-issued public/analytics ID with a different value
                unset($data[$key]);
            }
        }
        return self::set_code_fields($post_id, $data, true);
    }

    private static function get_meta($post_id, $key) {
        if (function_exists('get_field')) {
            $v = get_field($key, $post_id);
            if ($v !== null && $v !== false) {
                return $v;
            }
        }
        return get_post_meta($post_id, $key, true);
    }

    private static function set_meta($post_id, $key, $value) {
        if (function_exists('update_field')) {
            update_field($key, $value, $post_id);
        }
        update_post_meta($post_id, $key, $value);
    }

    private static function to_bool($v, $default = false) {
        if ($v === '' || $v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), array('1', 'true', 'yes', 'on'), true);
    }
}

Vernal_Code_Fields::init();
