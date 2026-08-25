<?php
/**
 * Loaded only from elementor/widgets/register.
 */

if (!defined('ABSPATH') || !class_exists('\Elementor\Widget_Base')) {
    return;
}

class Vernal_Guest_Links_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'vernal_guest_links';
    }

    public function get_title() {
        return 'Guest Links';
    }

    public function get_icon() {
        return 'eicon-editor-list-ul';
    }

    public function get_categories() {
        return array('vernal', 'general');
    }

    public function get_keywords() {
        return array('guest', 'links', 'acf', 'show', 'vernal');
    }

    protected function register_controls() {
        $this->start_controls_section('content_section', array(
            'label' => 'Content',
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ));
        $this->add_control('empty_notice', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => 'Pulls approved Guest Links from this show. Style the title (H2 link) and body copy in the Style tab.',
        ));
        $this->add_control('title_tag', array(
            'label' => 'Title HTML tag',
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'h2',
            'options' => array(
                'h2' => 'H2',
                'h3' => 'H3',
                'h4' => 'H4',
                'p' => 'Paragraph',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_list', array(
            'label' => 'List',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_responsive_control('item_gap', array(
            'label' => 'Space between entries',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em', 'rem'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 80),
                'em' => array('min' => 0, 'max' => 6),
            ),
            'default' => array('size' => 1.5, 'unit' => 'em'),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-links' => 'display:flex;flex-direction:column;gap:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_title', array(
            'label' => 'Title (link)',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'title_typography',
                'selector' => '{{WRAPPER}} .ih-guest-link__name, {{WRAPPER}} .ih-guest-link__name a',
            )
        );
        $this->add_control('title_color', array(
            'label' => 'Color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-link__name a' => 'color: {{VALUE}};',
            ),
        ));
        $this->add_control('title_hover_color', array(
            'label' => 'Hover color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-link__name a:hover' => 'color: {{VALUE}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_desc', array(
            'label' => 'Description (body)',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'desc_typography',
                'selector' => '{{WRAPPER}} .ih-guest-link__description',
            )
        );
        $this->add_control('desc_color', array(
            'label' => 'Color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-link__description' => 'color: {{VALUE}};',
            ),
        ));
        $this->add_responsive_control('desc_spacing', array(
            'label' => 'Space under title',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 40),
            ),
            'default' => array('size' => 0.35, 'unit' => 'em'),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-link__description' => 'margin: {{SIZE}}{{UNIT}} 0 0;',
            ),
        ));
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $tag = isset($settings['title_tag']) ? $settings['title_tag'] : 'h2';
        $allowed = array('h2' => 1, 'h3' => 1, 'h4' => 1, 'p' => 1);
        if (!isset($allowed[$tag])) {
            $tag = 'h2';
        }

        $rows = array();
        if (function_exists('get_field')) {
            $raw = get_field('ih_guest_links');
            if (empty($raw)) {
                $raw = get_field('ih_guest_links_json', false, false);
            }
            if (class_exists('Vernal_Show_Notes_Fields')) {
                $rows = Vernal_Show_Notes_Fields::rows_from_value($raw);
            }
        }
        if (empty($rows)) {
            return;
        }

        echo '<div class="ih-guest-links">';
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
            echo '<article class="ih-guest-link">';
            echo '<' . $tag . ' class="ih-guest-link__name">';
            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($name) . '</a>';
            echo '</' . $tag . '>';
            if ($desc !== '') {
                echo '<p class="ih-guest-link__description">' . esc_html($desc) . '</p>';
            }
            echo '</article>';
        }
        echo '</div>';
    }
}
