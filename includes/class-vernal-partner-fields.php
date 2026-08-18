<?php
/**
 * Programmatic ACF field group for Partner landings + related articles.
 * Stable field keys — do not change between plugin releases.
 *
 * Not readonly/disabled: Elementor omits disabled ACF fields from dynamic tags.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Partner_Fields {

    const GROUP_KEY = 'group_vernal_partner_landing';
    const GROUP_TITLE = 'Machine Partner';

    public static function init() {
        add_action('acf/init', array(__CLASS__, 'register_field_group'));
        add_action('init', array(__CLASS__, 'maybe_register'), 20);
    }

    public static function maybe_register() {
        if (function_exists('acf_add_local_field_group')) {
            self::register_field_group();
        }
    }

    public static function is_group_registered() {
        if (!function_exists('acf_get_local_field_groups')) {
            return function_exists('acf_add_local_field_group');
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
        if (isset($args['default_value'])) {
            $base['default_value'] = $args['default_value'];
        }
        if ($type === 'url' || $type === 'text' || $type === 'number' || $type === 'textarea') {
            $base['placeholder'] = '';
        }
        if ($type === 'textarea') {
            $base['rows'] = isset($args['rows']) ? intval($args['rows']) : 4;
            $base['new_lines'] = 'br';
        }
        if ($type === 'image') {
            $base['return_format'] = isset($args['return_format']) ? $args['return_format'] : 'array';
            $base['preview_size'] = 'medium';
            $base['library'] = 'all';
        }
        if ($type === 'gallery') {
            $base['return_format'] = isset($args['return_format']) ? $args['return_format'] : 'array';
            $base['preview_size'] = 'medium';
            $base['insert'] = 'append';
            $base['library'] = 'all';
            $base['min'] = 0;
            $base['max'] = 24;
        }
        return $base;
    }

    public static function register_field_group() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $fields = array(
            self::field('field_ih_partner_company_name', 'ih_partner_company_name', 'Partner company name', 'text', array(
                'instructions' => 'Machine Partner landing. Bind in Elementor as ACF ih_partner_company_name.',
            )),
            self::field('field_ih_partner_company_logo', 'ih_partner_company_logo', 'Partner logo', 'image', array(
                'instructions' => 'Company logo. Machine sideloads the URL into the Media Library.',
            )),
            self::field('field_ih_partner_excerpt', 'ih_partner_excerpt', 'Partner excerpt', 'textarea', array(
                'instructions' => 'Short blurb for the Partner landing.',
                'rows' => 4,
            )),
            self::field('field_ih_partner_gallery', 'ih_partner_gallery', 'Partner gallery', 'gallery', array(
                'instructions' => 'Must stay named ih_partner_gallery. Machine sideloads gallery URLs.',
            )),
            self::field('field_ih_partner_affiliate_url', 'ih_partner_affiliate_url', 'Affiliate destination URL', 'url', array(
                'instructions' => 'Raw affiliate destination (not the tracked CTA).',
            )),
            self::field('field_ih_partner_affiliate_cta_url', 'ih_partner_affiliate_cta_url', 'Affiliate CTA URL', 'url', array(
                'instructions' => 'Tracked Machine redirect with ?src=partner_page.',
            )),
            self::field('field_ih_partner_source_article_url', 'ih_partner_source_article_url', 'Lookalike source article URL', 'url', array(
                'instructions' => 'On related lookalike articles: the source URL used to write the piece.',
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
            'menu_order' => 21,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 1,
        ));
    }
}

Vernal_Partner_Fields::init();
