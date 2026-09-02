<?php
/**
 * Registers the Elementor Guest Links widget after Elementor is loaded.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Guest_Links_Widget_Loader {

    public static function init() {
        add_action('elementor/elements/categories_registered', array(__CLASS__, 'register_category'));
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widget'));
    }

    public static function register_category($elements_manager) {
        if (!is_object($elements_manager) || !method_exists($elements_manager, 'add_category')) {
            return;
        }
        $elements_manager->add_category('vernal', array(
            'title' => 'Vernal',
            'icon' => 'eicon-plug',
        ));
    }

    public static function register_widget($widgets_manager) {
        if (!class_exists('\Elementor\Widget_Base') || !is_object($widgets_manager)) {
            return;
        }
        require_once __DIR__ . '/widget-vernal-guest-links.php';
        require_once __DIR__ . '/widget-vernal-guest-card.php';
        require_once __DIR__ . '/widget-vernal-show-gallery.php';
        foreach (array('Vernal_Guest_Links_Widget', 'Vernal_Guest_Card_Widget', 'Vernal_Show_Gallery_Widget') as $class) {
            if (class_exists($class)) {
                $widgets_manager->register(new $class());
            }
        }
    }
}

Vernal_Guest_Links_Widget_Loader::init();
