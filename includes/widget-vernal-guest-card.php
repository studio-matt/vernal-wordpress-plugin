<?php
/**
 * Loaded only from elementor/widgets/register.
 */

if (!defined('ABSPATH') || !class_exists('\Elementor\Widget_Base')) {
    return;
}

class Vernal_Guest_Card_Widget extends \Elementor\Widget_Base {

    public function get_name() {
        return 'vernal_guest_card';
    }

    public function get_title() {
        return 'Guest Card';
    }

    public function get_icon() {
        return 'eicon-person';
    }

    public function get_categories() {
        return array('vernal', 'general');
    }

    public function get_keywords() {
        return array('guest', 'bio', 'headshot', 'acf', 'show', 'vernal');
    }

    protected function register_controls() {
        $this->start_controls_section('content_section', array(
            'label' => 'Content',
            'tab' => \Elementor\Controls_Manager::TAB_CONTENT,
        ));
        $this->add_control('empty_notice', array(
            'type' => \Elementor\Controls_Manager::RAW_HTML,
            'raw' => 'Pulls guest name, bio, headshot, and social URLs from this show. Style in the Style tab.',
        ));
        $this->add_control('show_socials', array(
            'label' => 'Show social links',
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'label_on' => 'Yes',
            'label_off' => 'No',
            'return_value' => 'yes',
            'default' => 'yes',
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_layout', array(
            'label' => 'Layout',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_responsive_control('card_gap', array(
            'label' => 'Gap',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 80),
            ),
            'default' => array('size' => 16, 'unit' => 'px'),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card' => 'display:flex;flex-direction:column;gap:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_photo', array(
            'label' => 'Headshot',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_responsive_control('photo_size', array(
            'label' => 'Size',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px'),
            'range' => array(
                'px' => array('min' => 48, 'max' => 400),
            ),
            'default' => array('size' => 160, 'unit' => 'px'),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__photo' => 'width:{{SIZE}}{{UNIT}};height:{{SIZE}}{{UNIT}};object-fit:cover;',
            ),
        ));
        $this->add_control('photo_radius', array(
            'label' => 'Radius',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', '%'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 200),
            ),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__photo' => 'border-radius:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_name', array(
            'label' => 'Name',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'name_typography',
                'selector' => '{{WRAPPER}} .ih-guest-card__name',
            )
        );
        $this->add_control('name_color', array(
            'label' => 'Color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__name' => 'color: {{VALUE}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_bio', array(
            'label' => 'Bio',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_group_control(
            \Elementor\Group_Control_Typography::get_type(),
            array(
                'name' => 'bio_typography',
                'selector' => '{{WRAPPER}} .ih-guest-card__bio',
            )
        );
        $this->add_control('bio_color', array(
            'label' => 'Color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__bio' => 'color: {{VALUE}};',
            ),
        ));
        $this->end_controls_section();

        $this->start_controls_section('style_socials', array(
            'label' => 'Socials',
            'tab' => \Elementor\Controls_Manager::TAB_STYLE,
        ));
        $this->add_control('social_color', array(
            'label' => 'Color',
            'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__socials a' => 'color: {{VALUE}};',
            ),
        ));
        $this->add_responsive_control('social_gap', array(
            'label' => 'Gap',
            'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => array('px', 'em'),
            'range' => array(
                'px' => array('min' => 0, 'max' => 40),
            ),
            'default' => array('size' => 12, 'unit' => 'px'),
            'selectors' => array(
                '{{WRAPPER}} .ih-guest-card__socials' => 'display:flex;flex-wrap:wrap;gap:{{SIZE}}{{UNIT}};',
            ),
        ));
        $this->end_controls_section();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $card = array('name' => '', 'bio' => '', 'headshot_url' => '', 'socials' => array());
        if (class_exists('Vernal_Show_Notes_Fields')) {
            $post_id = Vernal_Show_Notes_Fields::resolve_show_post_id();
            $card = Vernal_Show_Notes_Fields::load_guest_card_for_post($post_id);
        }
        $has = ($card['name'] !== '' && strcasecmp($card['name'], 'Guest') !== 0)
            || $card['bio'] !== ''
            || $card['headshot_url'] !== '';
        if (!$has) {
            if (class_exists('Vernal_Show_Notes_Fields') && Vernal_Show_Notes_Fields::is_elementor_edit_mode()) {
                Vernal_Show_Notes_Fields::render_empty_notice('guest card');
            }
            return;
        }

        echo '<aside class="ih-guest-card">';
        if ($card['headshot_url'] !== '') {
            echo '<img class="ih-guest-card__photo" src="' . esc_url($card['headshot_url']) . '" alt="' . esc_attr($card['name']) . '" />';
        }
        if ($card['name'] !== '') {
            echo '<h2 class="ih-guest-card__name">' . esc_html($card['name']) . '</h2>';
        }
        if ($card['bio'] !== '') {
            echo '<p class="ih-guest-card__bio">' . esc_html($card['bio']) . '</p>';
        }
        $show_socials = !empty($settings['show_socials']) && $settings['show_socials'] === 'yes';
        if ($show_socials && !empty($card['socials'])) {
            echo '<nav class="ih-guest-card__socials">';
            foreach ($card['socials'] as $row) {
                echo '<a href="' . esc_url($row['url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($row['label']) . '</a>';
            }
            echo '</nav>';
        }
        echo '</aside>';
    }
}
