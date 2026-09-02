<?php
/**
 * Catalog of portable Vernal Elementor modules (ACF contract + widget names).
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Modules {

    public static function catalog() {
        return array(
            array(
                'widget' => 'vernal_guest_links',
                'label' => 'Guest Links',
                'acf' => array('ih_guest_links', 'ih_guest_links_json', 'ih_has_guest_links'),
                'hide_flag' => 'ih_has_guest_links',
            ),
            array(
                'widget' => 'vernal_guest_card',
                'label' => 'Guest Card',
                'acf' => array('ih_guests_name', 'ih_guest_bio', 'ih_guest_headshot', 'ih_has_guest_card'),
                'hide_flag' => 'ih_has_guest_card',
            ),
            array(
                'widget' => 'vernal_show_gallery',
                'label' => 'Show Gallery',
                'acf' => array('ih_show_gallery', 'ih_show_gallery_json', 'ih_has_show_gallery'),
                'hide_flag' => 'ih_has_show_gallery',
            ),
        );
    }
}
