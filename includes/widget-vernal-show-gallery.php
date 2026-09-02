<?php
/**
 * Loaded only from elementor/widgets/register.
 */

if (!defined('ABSPATH') || !class_exists('\Elementor\Widget_Base')) {
    return;
}

class Vernal_Show_Gallery_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'vernal_show_gallery';
    }

    public function get_title() {
        return 'Show Gallery';
    }

    public function get_icon() {
        return 'eicon-gallery-grid';
    }

    public function get_categories() {
        return array('vernal', 'general');
    }

    public function get_keywords() {
        return array('gallery', 'images', 'acf', 'show', 'vernal');
    }

    protected function register_controls() {
        $this->start_controls_section('content_section', array(
            'label' => 'Content',
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ));
        $this->add_control('empty_notice', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => 'Pulls Machine images for this show (cover and extras). Style columns and gap in the Style tab.',
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_grid', array(
            'label' => 'Grid',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_responsive_control('columns', array(
            'label' => 'Columns',
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => '3',
            'options' => array(
                '1' => '1',
                '2' => '2',
                '3' => '3',
                '4' => '4',
            ),
            'selectors' => array(
                '{{WRAPPER}} .ih-show-gallery' => 'display:grid;grid-template-columns:repeat({{VALUE}}, minmax(0, 1fr));',
            ),
        ));
        $this->add_responsive_control('gap', array(
            'label' => 'Gap',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 80),
            ),
            'default' => array('size' => 12, 'unit' => 'px'),
            'selectors' => array(
                '{{WRAPPER}} .ih-show-gallery' => 'gap:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->add_control('radius', array(
            'label' => 'Image radius',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 40),
            ),
            'selectors' => array(
                '{{WRAPPER}} .ih-show-gallery img' => 'border-radius:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();
    }

    protected function render() {
        $urls = array();
        if (class_exists('Vernal_Show_Notes_Fields')) {
            $post_id = Vernal_Show_Notes_Fields::resolve_show_post_id();
            $urls = Vernal_Show_Notes_Fields::load_gallery_for_post($post_id);
        }
        if (empty($urls)) {
            if (class_exists('Vernal_Show_Notes_Fields') && Vernal_Show_Notes_Fields::is_elementor_edit_mode()) {
                Vernal_Show_Notes_Fields::render_empty_notice('show gallery');
            }
            return;
        }
        echo '<div class="ih-show-gallery">';
        foreach ($urls as $url) {
            echo '<figure class="ih-show-gallery__item">';
            echo '<img src="' . esc_url($url) . '" alt="" />';
            echo '</figure>';
        }
        echo '</div>';
    }
}
