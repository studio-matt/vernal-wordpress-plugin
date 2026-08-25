<?php
/**
 * Extra Show Notes ACF fields (Guest Links repeater, TikTok, Misc, social URLs).
 *
 * Attaches onto the live IH group titled “Show Notes & Other Fun Stuff” when
 * that group exists so Elementor lists them in the same picker. Falls back to
 * a local group with that title.
 *
 * Not readonly/disabled: Elementor omits disabled ACF fields from dynamic tags.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Show_Notes_Fields {

    const GROUP_KEY = 'group_vernal_show_notes_guest_links';
    const GROUP_TITLE = 'Show Notes & Other Fun Stuff';

    public static function init() {
        add_action('acf/init', array(__CLASS__, 'register_fields'));
        add_action('init', array(__CLASS__, 'maybe_register'), 20);
    }

    public static function maybe_register() {
        if (function_exists('acf_add_local_field') || function_exists('acf_add_local_field_group')) {
            self::register_fields();
        }
    }

    private static function find_show_notes_group_key() {
        if (!function_exists('acf_get_field_groups')) {
            return '';
        }
        $groups = acf_get_field_groups(array('post_type' => 'post'));
        if (!is_array($groups)) {
            return '';
        }
        foreach ($groups as $g) {
            $title = isset($g['title']) ? (string) $g['title'] : '';
            $key = isset($g['key']) ? (string) $g['key'] : '';
            if ($key === self::GROUP_KEY) {
                continue;
            }
            if (stripos($title, 'Show Notes') !== false && stripos($title, 'Fun') !== false) {
                return $key;
            }
        }
        return '';
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
        if ($type === 'url' || $type === 'text' || $type === 'textarea') {
            $base['placeholder'] = '';
        }
        if ($type === 'textarea') {
            $base['rows'] = isset($args['rows']) ? intval($args['rows']) : 3;
            $base['new_lines'] = 'br';
        }
        if ($type === 'repeater') {
            $base['layout'] = isset($args['layout']) ? $args['layout'] : 'row';
            $base['button_label'] = isset($args['button_label']) ? $args['button_label'] : 'Add link';
            $base['sub_fields'] = isset($args['sub_fields']) ? $args['sub_fields'] : array();
            $base['min'] = 0;
            $base['max'] = 0;
        }
        return $base;
    }

    private static function extra_fields() {
        $link_name = self::field(
            'field_ih_guest_link_name',
            'link_name',
            'Link name',
            'text',
            array('instructions' => 'Shown on the front as the link title.')
        );
        $link_description = self::field(
            'field_ih_guest_link_description',
            'link_description',
            'Link description',
            'textarea',
            array('instructions' => 'Short meta / snippet under the name.', 'rows' => 2)
        );
        $link_url = self::field(
            'field_ih_guest_link_url',
            'link_url',
            'Link URL',
            'url',
            array('instructions' => 'Opens in a new window. Bind and set Open in new window in Elementor.')
        );

        return array(
            self::field(
                'field_ih_guest_links',
                'ih_guest_links',
                'Guest Links',
                'repeater',
                array(
                    'instructions' => 'Approved Guest Links from Machine (name, description, URL). Bind this repeater in Elementor. Set the URL widget to open in a new window.',
                    'layout' => 'row',
                    'button_label' => 'Add guest link',
                    'sub_fields' => array($link_name, $link_description, $link_url),
                )
            ),
            self::field(
                'field_ih_guest_links_json',
                'ih_guest_links_json',
                'Guest Links JSON',
                'textarea',
                array(
                    'instructions' => 'Raw JSON backup of Guest Links. Prefer the Guest Links repeater for Elementor.',
                    'rows' => 3,
                )
            ),
            self::field('field_ih_personal_website', 'ih_personal_website', 'Personal Website', 'url', array(
                'instructions' => 'Guest website. Hide widget when empty.',
            )),
            self::field('field_ih_podcast', 'ih_podcast', 'Their Podcast', 'url'),
            self::field('field_ih_amazon', 'ih_amazon', 'Amazon Link', 'url'),
            self::field('field_ih_instagram', 'ih_instagram', 'Instagram', 'url'),
            self::field('field_ih_youtube', 'ih_youtube', 'YouTube (guest channel)', 'url', array(
                'instructions' => 'Guest channel/profile. Not Watch on YouTube (ih_youtube_link).',
            )),
            self::field('field_ih_facebook', 'ih_facebook', 'Facebook', 'url'),
            self::field('field_ih_linkedin', 'ih_linkedin', 'LinkedIn', 'url'),
            self::field('field_ih_twitter', 'ih_twitter', 'X / Twitter', 'url'),
            self::field('field_ih_tiktok', 'ih_tiktok', 'TikTok', 'url'),
            self::field('field_ih_misc_label', 'ih_misc_label', 'Misc Link Label', 'text', array(
                'instructions' => 'Front-of-site label for the misc link.',
            )),
            self::field('field_ih_misc_link', 'ih_misc_link', 'Misc Link', 'url', array(
                'instructions' => 'Misc URL. Pair with Misc Link Label.',
            )),
        );
    }

    private static function field_already_exists($name) {
        if (!function_exists('acf_get_field')) {
            return false;
        }
        $existing = acf_get_field($name);
        return is_array($existing) && !empty($existing['key']);
    }

    public static function register_fields() {
        if (!function_exists('acf_add_local_field') && !function_exists('acf_add_local_field_group')) {
            return;
        }
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $fields = array();
        foreach (self::extra_fields() as $field) {
            $name = isset($field['name']) ? $field['name'] : '';
            if ($name && self::field_already_exists($name)) {
                continue;
            }
            $fields[] = $field;
        }
        if (empty($fields)) {
            return;
        }

        $parent = self::find_show_notes_group_key();
        if ($parent && function_exists('acf_add_local_field')) {
            foreach ($fields as $field) {
                $field['parent'] = $parent;
                $subs = isset($field['sub_fields']) ? $field['sub_fields'] : array();
                acf_add_local_field($field);
                foreach ($subs as $sub) {
                    $sub['parent'] = $field['key'];
                    acf_add_local_field($sub);
                }
            }
            return;
        }

        if (!function_exists('acf_add_local_field_group')) {
            return;
        }
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
            'menu_order' => 5,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 1,
        ));
    }
}

Vernal_Show_Notes_Fields::init();
