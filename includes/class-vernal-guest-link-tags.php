<?php
/**
 * Elementor Dynamic Tags for one Guest Links repeater row.
 *
 * Bind these inside a Loop Item whose Loop query is ACF Repeater
 * `ih_guest_links`. They do not appear under Show Notes top-level fields.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Guest_Link_Tags {

    public static function init() {
        add_action('elementor/dynamic_tags/register', array(__CLASS__, 'register'));
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_css'));
        add_action('elementor/preview/enqueue_styles', array(__CLASS__, 'enqueue_css'));
    }

    public static function enqueue_css() {
        $css = '.ih-guest-links{display:flex;flex-direction:column;gap:1.25rem;}'
            . '.ih-guest-link{margin:0;}'
            . '.ih-guest-link__name{display:block;font-weight:600;text-decoration:underline;}'
            . '.ih-guest-link__description{margin:.35rem 0 0;opacity:.88;line-height:1.45;}';
        $ver = defined('VERNAL_CONTENTUM_VERSION') ? VERNAL_CONTENTUM_VERSION : '1.2.24';
        wp_register_style('vernal-guest-links', false, array(), $ver);
        wp_enqueue_style('vernal-guest-links');
        wp_add_inline_style('vernal-guest-links', $css);
    }

    public static function register($module) {
        if (!is_object($module) || !method_exists($module, 'register')) {
            return;
        }
        if (method_exists($module, 'register_group')) {
            $module->register_group('vernal_guest_link', array(
                'title' => 'Guest Link (this row)',
            ));
        }
        if (!class_exists('\Elementor\Core\DynamicTags\Tag')) {
            return;
        }
        $module->register(new Vernal_Guest_Link_Name_Tag());
        $module->register(new Vernal_Guest_Link_Description_Tag());
        if (class_exists('\Elementor\Core\DynamicTags\Data_Tag')) {
            $module->register(new Vernal_Guest_Link_Url_Tag());
        }
        $module->register(new Vernal_Guest_Link_Url_Text_Tag());
    }

    public static function current_row() {
        if (function_exists('get_sub_field')) {
            $url = get_sub_field('link_url');
            if (!$url) {
                $url = get_sub_field('url');
            }
            $name = get_sub_field('link_name');
            if (!$name) {
                $name = get_sub_field('name');
            }
            $description = get_sub_field('link_description');
            if (!$description) {
                $description = get_sub_field('description');
            }
            if ($url || $name) {
                return array(
                    'name' => is_string($name) ? $name : '',
                    'description' => is_string($description) ? $description : '',
                    'url' => is_string($url) ? $url : '',
                );
            }
        }
        if (function_exists('get_row')) {
            $row = get_row(true);
            if (is_array($row) && class_exists('Vernal_Show_Notes_Fields')) {
                $norm = Vernal_Show_Notes_Fields::row_from_item($row);
                if (!empty($norm['url']) || !empty($norm['name'])) {
                    return $norm;
                }
            }
        }
        return array();
    }
}

if (class_exists('\Elementor\Core\DynamicTags\Tag')) {

    abstract class Vernal_Guest_Link_Text_Tag extends \Elementor\Core\DynamicTags\Tag {
        public function get_group() {
            return 'vernal_guest_link';
        }

        public function get_categories() {
            return array(\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY);
        }
    }

    class Vernal_Guest_Link_Name_Tag extends Vernal_Guest_Link_Text_Tag {
        public function get_name() {
            return 'vernal-guest-link-name';
        }

        public function get_title() {
            return 'Guest Link Name';
        }

        public function render() {
            $row = Vernal_Guest_Link_Tags::current_row();
            echo esc_html(isset($row['name']) ? $row['name'] : '');
        }
    }

    class Vernal_Guest_Link_Description_Tag extends Vernal_Guest_Link_Text_Tag {
        public function get_name() {
            return 'vernal-guest-link-description';
        }

        public function get_title() {
            return 'Guest Link Description';
        }

        public function render() {
            $row = Vernal_Guest_Link_Tags::current_row();
            echo esc_html(isset($row['description']) ? $row['description'] : '');
        }
    }

    class Vernal_Guest_Link_Url_Text_Tag extends Vernal_Guest_Link_Text_Tag {
        public function get_name() {
            return 'vernal-guest-link-url-text';
        }

        public function get_title() {
            return 'Guest Link URL (text)';
        }

        public function render() {
            $row = Vernal_Guest_Link_Tags::current_row();
            echo esc_url(isset($row['url']) ? $row['url'] : '');
        }
    }
}

if (class_exists('\Elementor\Core\DynamicTags\Data_Tag') && class_exists('\Elementor\Modules\DynamicTags\Module')) {

    class Vernal_Guest_Link_Url_Tag extends \Elementor\Core\DynamicTags\Data_Tag {
        public function get_name() {
            return 'vernal-guest-link-url';
        }

        public function get_title() {
            return 'Guest Link URL';
        }

        public function get_group() {
            return 'vernal_guest_link';
        }

        public function get_categories() {
            return array(\Elementor\Modules\DynamicTags\Module::URL_CATEGORY);
        }

        public function get_value(array $options = array()) {
            $row = Vernal_Guest_Link_Tags::current_row();
            $url = isset($row['url']) ? $row['url'] : '';
            return array(
                'url' => $url,
                'is_external' => true,
                'nofollow' => false,
            );
        }
    }
}

Vernal_Guest_Link_Tags::init();
