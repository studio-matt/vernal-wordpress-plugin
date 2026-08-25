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
        add_filter('acf/format_value/name=ih_guest_links_json', array(__CLASS__, 'format_guest_links_json_html'), 20, 3);
        add_filter('acf/format_value/name=ih_guest_links_html', array(__CLASS__, 'format_guest_links_html_passthrough'), 20, 3);
        add_filter('acf/load_value/name=ih_guest_link_name', array(__CLASS__, 'load_current_row_name'), 20, 3);
        add_filter('acf/load_value/name=ih_guest_link_description', array(__CLASS__, 'load_current_row_description'), 20, 3);
        add_filter('acf/load_value/name=ih_guest_link_url', array(__CLASS__, 'load_current_row_url'), 20, 3);
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
        if ($type === 'wysiwyg') {
            $base['tabs'] = 'visual';
            $base['media_upload'] = 0;
            $base['delay'] = 0;
        }
        if ($type === 'true_false') {
            $base['ui'] = 1;
            $base['ui_on_text'] = 'Yes';
            $base['ui_off_text'] = 'No';
            $base['default_value'] = 0;
        }
        return $base;
    }

    /**
     * Elementor Text Editor bound to Guest Links JSON should render a list, not raw JSON.
     *
     * @param mixed $value
     * @param int   $post_id
     * @param array $field
     * @return mixed
     */
    public static function format_guest_links_json_html($value, $post_id, $field) {
        if (is_admin() && empty($_GET['elementor-preview'])) {
            return $value;
        }
        $html = self::guest_links_to_html($value);
        return $html !== '' ? $html : $value;
    }

    public static function load_current_row_name($value, $post_id, $field) {
        if (class_exists('Vernal_Guest_Link_Tags')) {
            $row = Vernal_Guest_Link_Tags::current_row();
            if (!empty($row['name'])) {
                return $row['name'];
            }
        }
        return $value;
    }

    public static function load_current_row_description($value, $post_id, $field) {
        if (class_exists('Vernal_Guest_Link_Tags')) {
            $row = Vernal_Guest_Link_Tags::current_row();
            if (!empty($row['description'])) {
                return $row['description'];
            }
        }
        return $value;
    }

    public static function load_current_row_url($value, $post_id, $field) {
        if (class_exists('Vernal_Guest_Link_Tags')) {
            $row = Vernal_Guest_Link_Tags::current_row();
            if (!empty($row['url'])) {
                return $row['url'];
            }
        }
        return $value;
    }

    public static function format_guest_links_html_passthrough($value, $post_id, $field) {
        $json = function_exists('get_field') ? get_field('ih_guest_links_json', $post_id, false) : '';
        $html = self::guest_links_to_html($json);
        if ($html !== '') {
            return $html;
        }
        return self::guest_links_to_html($value);
    }

    public static function guest_links_to_html($value) {
        $rows = self::normalize_guest_link_rows($value);
        if (empty($rows)) {
            return '';
        }
        $out = '<div class="ih-guest-links">';
        foreach ($rows as $row) {
            $name = isset($row['name']) ? $row['name'] : '';
            $url = isset($row['url']) ? $row['url'] : '';
            $desc = isset($row['description']) ? $row['description'] : '';
            if ($url === '') {
                continue;
            }
            if ($name === '') {
                $name = $url;
            }
            $out .= '<article class="ih-guest-link" style="margin:0 0 1.5em;">';
            $out .= '<h2 class="ih-guest-link__name"><a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($name) . '</a></h2>';
            if ($desc !== '') {
                $out .= '<p class="ih-guest-link__description">' . esc_html($desc) . '</p>';
            }
            $out .= '</article>';
        }
        $out .= '</div>';
        return $out;
    }

    public static function rows_from_value($value) {
        return self::normalize_guest_link_rows($value);
    }

    public static function resolve_show_post_id() {
        $candidates = array();
        $qid = (int) get_queried_object_id();
        if ($qid) {
            $candidates[] = $qid;
        }
        $tid = (int) get_the_ID();
        if ($tid) {
            $candidates[] = $tid;
        }
        if (class_exists('\Elementor\Plugin')) {
            $elementor = \Elementor\Plugin::$instance;
            if (!empty($elementor->documents)) {
                $document = $elementor->documents->get_current();
                if ($document && method_exists($document, 'get_settings')) {
                    $preview = $document->get_settings('preview_id');
                    if ($preview) {
                        $candidates[] = (int) $preview;
                    }
                }
            }
        }
        if (!empty($_GET['preview_id'])) {
            $candidates[] = (int) $_GET['preview_id'];
        }
        foreach ($candidates as $pid) {
            if ($pid <= 0) {
                continue;
            }
            $type = get_post_type($pid);
            if ($type && $type !== 'elementor_library' && $type !== 'elementor_font' && $type !== 'elementor_snippet') {
                return $pid;
            }
        }
        return $tid;
    }

    public static function load_rows_for_post($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return array();
        }

        $tries = array();
        if (function_exists('have_rows') && have_rows('ih_guest_links', $post_id)) {
            $from_rows = array();
            while (have_rows('ih_guest_links', $post_id)) {
                the_row();
                $from_rows[] = array(
                    'name' => (string) (get_sub_field('link_name') ?: get_sub_field('name') ?: ''),
                    'description' => (string) (get_sub_field('link_description') ?: get_sub_field('description') ?: ''),
                    'url' => (string) (get_sub_field('link_url') ?: get_sub_field('url') ?: ''),
                );
            }
            $tries[] = $from_rows;
        }
        if (function_exists('get_field')) {
            $tries[] = get_field('ih_guest_links', $post_id);
            $tries[] = get_field('ih_guest_links', $post_id, false);
            $tries[] = get_field('ih_guest_links_json', $post_id, false);
        }
        $tries[] = get_post_meta($post_id, 'ih_guest_links_json', true);
        $tries[] = get_post_meta($post_id, 'ih_guest_links', true);

        foreach ($tries as $raw) {
            $rows = self::normalize_guest_link_rows($raw);
            if (!empty($rows)) {
                return $rows;
            }
        }
        return array();
    }

    public static function row_from_item($item) {
        $rows = self::normalize_guest_link_rows(array($item));
        return !empty($rows) ? $rows[0] : array();
    }

    private static function normalize_guest_link_rows($value) {
        if (is_string($value)) {
            $trim = trim($value);
            if ($trim === '') {
                return array();
            }
            if ($trim[0] === '<') {
                return array();
            }
            $decoded = json_decode($trim, true);
            $value = is_array($decoded) ? $decoded : array();
        }
        if (!is_array($value)) {
            return array();
        }
        $rows = array();
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = '';
            $name = '';
            $description = '';
            foreach ($item as $k => $v) {
                if (!is_string($v) && !is_numeric($v)) {
                    continue;
                }
                $v = trim((string) $v);
                if ($v === '') {
                    continue;
                }
                $lk = strtolower((string) $k);
                if ($url === '' && (strpos($lk, 'url') !== false || strpos($lk, 'link') !== false) && preg_match('#^https?://#i', $v)) {
                    $url = esc_url_raw($v);
                } elseif ($url === '' && preg_match('#^https?://#i', $v) && strpos($lk, 'desc') === false) {
                    $url = esc_url_raw($v);
                } elseif ($name === '' && (strpos($lk, 'name') !== false || $lk === 'title' || substr($lk, -5) === 'title')) {
                    $name = sanitize_text_field($v);
                } elseif ($description === '' && (strpos($lk, 'desc') !== false || strpos($lk, 'snippet') !== false)) {
                    $description = sanitize_textarea_field($v);
                }
            }
            if ($url === '') {
                continue;
            }
            if ($description === '' && !empty($item['description'])) {
                $description = sanitize_textarea_field((string) $item['description']);
            }
            $rows[] = array(
                'name' => $name,
                'description' => $description,
                'url' => $url,
            );
        }
        return $rows;
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
                    'instructions' => 'For Elementor Text Editor: outputs an HTML list (name, description, new-window URL). Prefer the Guest Links repeater for Loop widgets.',
                    'rows' => 3,
                )
            ),
            self::field(
                'field_ih_guest_links_html',
                'ih_guest_links_html',
                'Guest Links HTML',
                'wysiwyg',
                array(
                    'instructions' => 'Ready-to-render Guest Links list. Bind a Text Editor or HTML widget to this if you are not using a Repeater.',
                )
            ),
            self::field(
                'field_ih_has_guest_links',
                'ih_has_guest_links',
                'Has Guest Links',
                'true_false',
                array(
                    'instructions' => 'Use this in Elementor Display Conditions (Is not empty / is True). The Guest Links repeater will not appear in that picker.',
                )
            ),
            self::field(
                'field_ih_guest_link_name',
                'ih_guest_link_name',
                'Guest Link Name (this row)',
                'text',
                array(
                    'instructions' => 'Loop item only. Bind this in a Loop whose source is Guest Links (ih_guest_links).',
                )
            ),
            self::field(
                'field_ih_guest_link_description',
                'ih_guest_link_description',
                'Guest Link Description (this row)',
                'textarea',
                array(
                    'instructions' => 'Loop item only. Description/snippet for the current Guest Links row.',
                    'rows' => 2,
                )
            ),
            self::field(
                'field_ih_guest_link_url',
                'ih_guest_link_url',
                'Guest Link URL (this row)',
                'url',
                array(
                    'instructions' => 'Loop item only. Set the widget link to this field and Open in new window.',
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
