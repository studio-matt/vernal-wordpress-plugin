<?php
/**
 * Stamp a starter Elementor page with Vernal module widgets.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Template_Stamp {

    const META_FLAG = '_vernal_module_template';
    const PAGE_TITLE = 'Vernal Show Modules';

    public static function ensure($request) {
        if (!class_exists('\Elementor\Plugin')) {
            return new WP_Error(
                'elementor_required',
                __('Elementor is not active on this site.', 'vernal-contentum'),
                array('status' => 400)
            );
        }

        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        $allowed = array();
        if (class_exists('Vernal_Modules')) {
            foreach (Vernal_Modules::catalog() as $mod) {
                $allowed[$mod['widget']] = true;
            }
        }
        $wanted = array();
        $raw_widgets = isset($params['widgets']) && is_array($params['widgets']) ? $params['widgets'] : array_keys($allowed);
        foreach ($raw_widgets as $name) {
            $name = is_string($name) ? sanitize_key($name) : '';
            if ($name && isset($allowed[$name])) {
                $wanted[] = $name;
            }
        }
        if (empty($wanted)) {
            $wanted = array_keys($allowed);
        }

        $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
        if ($post_id <= 0) {
            $post_id = self::find_or_create_page();
            if (is_wp_error($post_id)) {
                return $post_id;
            }
        } else {
            $post = get_post($post_id);
            if (!$post) {
                return new WP_Error('not_found', __('Post not found', 'vernal-contentum'), array('status' => 404));
            }
        }

        $raw = get_post_meta($post_id, '_elementor_data', true);
        $elements = array();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $elements = $decoded;
            }
        } elseif (is_array($raw)) {
            $elements = $raw;
        }

        $present = array();
        self::collect_widget_types($elements, $present);
        $missing = array();
        foreach ($wanted as $widget) {
            if (empty($present[$widget])) {
                $missing[] = $widget;
            }
        }

        if (!empty($missing)) {
            $elements[] = self::section_for_widgets($missing);
            update_post_meta($post_id, '_elementor_data', wp_slash(wp_json_encode($elements)));
            update_post_meta($post_id, '_elementor_edit_mode', 'builder');
            update_post_meta($post_id, '_elementor_template_type', 'wp-page');
            if (defined('ELEMENTOR_VERSION')) {
                update_post_meta($post_id, '_elementor_version', ELEMENTOR_VERSION);
            }
            update_post_meta($post_id, self::META_FLAG, '1');
        }

        return rest_ensure_response(array(
            'success' => true,
            'data' => array(
                'post_id' => $post_id,
                'title' => get_the_title($post_id),
                'edit_url' => admin_url('post.php?post=' . $post_id . '&action=elementor'),
                'added' => $missing,
                'present' => array_keys($present),
                'widgets' => $wanted,
            ),
        ));
    }

    private static function find_or_create_page() {
        $found = get_posts(array(
            'post_type' => 'page',
            'post_status' => array('draft', 'publish', 'private'),
            'meta_key' => self::META_FLAG,
            'meta_value' => '1',
            'posts_per_page' => 1,
            'fields' => 'ids',
        ));
        if (!empty($found)) {
            return (int) $found[0];
        }
        $post_id = wp_insert_post(array(
            'post_title' => self::PAGE_TITLE,
            'post_status' => 'draft',
            'post_type' => 'page',
            'post_content' => '',
        ), true);
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        update_post_meta($post_id, self::META_FLAG, '1');
        return (int) $post_id;
    }

    private static function collect_widget_types($elements, array &$present) {
        if (!is_array($elements)) {
            return;
        }
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            if (!empty($el['widgetType'])) {
                $present[(string) $el['widgetType']] = true;
            }
            if (!empty($el['elements']) && is_array($el['elements'])) {
                self::collect_widget_types($el['elements'], $present);
            }
        }
    }

    private static function section_for_widgets(array $widgets) {
        $inner = array();
        foreach ($widgets as $type) {
            $inner[] = array(
                'id' => self::eid(),
                'elType' => 'widget',
                'widgetType' => $type,
                'isInner' => false,
                'settings' => array(),
                'elements' => array(),
            );
        }
        return array(
            'id' => self::eid(),
            'elType' => 'section',
            'isInner' => false,
            'settings' => array('structure' => '10'),
            'elements' => array(
                array(
                    'id' => self::eid(),
                    'elType' => 'column',
                    'isInner' => false,
                    'settings' => array('_column_size' => 100),
                    'elements' => $inner,
                ),
            ),
        );
    }

    private static function eid() {
        return substr(bin2hex(random_bytes(4)), 0, 7);
    }
}
