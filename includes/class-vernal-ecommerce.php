<?php
/**
 * Vernal → Ecommerce. Wishes checkout settings live here, not on a second gateway screen.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Ecommerce {

    const PAGE = 'vernal-contentum-ecommerce';

    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'redirect_wc_settings'));
        add_action('before_woocommerce_init', array($this, 'declare_wc_compatibility'));
        add_action('plugins_loaded', array($this, 'load_gateway'), 20);
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_blocks'));
    }

    public function register_blocks($registry) {
        if (self::standalone_active() || !class_exists('WC_Payment_Gateway')) {
            return;
        }
        if (!class_exists('Vernal_Wishes_Gateway')) {
            require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-wishes-gateway.php';
        }
        if (function_exists('vernal_wishes_register_block_method')) {
            vernal_wishes_register_block_method($registry);
        }
    }

    public function declare_wc_compatibility() {
        if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            return;
        }
        $file = defined('VERNAL_CONTENTUM_PLUGIN_FILE')
            ? VERNAL_CONTENTUM_PLUGIN_FILE
            : dirname(__DIR__) . '/vernal-contentum.php';
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', $file, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', $file, true);
    }

    /**
     * Standalone wishes-checkout owns gateway id "wishes" while it is active.
     */
    public static function standalone_active() {
        if (defined('WISHES_CHECKOUT_HOST')) {
            return true;
        }
        if (!function_exists('is_plugin_active')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($plugin_file)) {
                require_once $plugin_file;
            }
        }
        if (function_exists('is_plugin_active') && is_plugin_active('wishes-checkout/wishes-checkout.php')) {
            return true;
        }
        return class_exists('WC_Gateway_Wishes', false);
    }

    public function load_gateway() {
        if (!class_exists('WC_Payment_Gateway')) {
            return;
        }
        if (self::standalone_active()) {
            return;
        }
        require_once VERNAL_CONTENTUM_PLUGIN_DIR . 'includes/class-vernal-wishes-gateway.php';
        add_filter('woocommerce_payment_gateways', array($this, 'register_gateway'));
    }

    public function register_gateway($gateways) {
        if (self::standalone_active()) {
            return $gateways;
        }
        if (!in_array('Vernal_Wishes_Gateway', $gateways, true)) {
            $gateways[] = 'Vernal_Wishes_Gateway';
        }
        return $gateways;
    }

    public function redirect_wc_settings() {
        if (self::standalone_active() || !is_admin() || !current_user_can('manage_options')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'checkout';
        $section = isset($_GET['section']) ? sanitize_key(wp_unslash($_GET['section'])) : '';
        if ($page === 'wc-settings' && $tab === 'checkout' && $section === 'wishes') {
            wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE));
            exit;
        }
    }

    public function register_settings() {
        register_setting(
            'vernal_wishes_checkout',
            Vernal_Wishes_Contract::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize'),
                'capability' => 'manage_options',
                'default' => array(),
            )
        );
    }

    public function sanitize($input) {
        $existing = get_option(Vernal_Wishes_Contract::OPTION_KEY, array());
        if (!is_array($existing)) {
            $existing = array();
        }
        if (!current_user_can('manage_options')) {
            return $existing;
        }
        if (!is_array($input)) {
            $input = array();
        }
        $input['title'] = sanitize_text_field(isset($input['title']) ? $input['title'] : '');
        $input['description'] = sanitize_textarea_field(isset($input['description']) ? $input['description'] : '');
        $input['publishable_key'] = sanitize_text_field(isset($input['publishable_key']) ? $input['publishable_key'] : '');
        $input['secret_key'] = sanitize_text_field(isset($input['secret_key']) ? $input['secret_key'] : '');
        $merged = Vernal_Wishes_Contract::merge_settings($existing, $input);
        foreach ($merged['errors'] as $error) {
            add_settings_error(Vernal_Wishes_Contract::OPTION_KEY, 'vernal_wishes', $error, 'error');
        }
        return $merged['settings'];
    }

    public static function current_settings() {
        $settings = get_option(Vernal_Wishes_Contract::OPTION_KEY, array());
        return is_array($settings) ? $settings : array();
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = self::current_settings();
        $pk = isset($settings['publishable_key']) ? (string) $settings['publishable_key'] : '';
        $has_secret = isset($settings['secret_key']) && $settings['secret_key'] !== '';
        $wc = class_exists('WooCommerce');
        $currency = ($wc && function_exists('get_woocommerce_currency')) ? get_woocommerce_currency() : '';
        $ready = Vernal_Wishes_Contract::readiness(array(
            'wc_active' => $wc,
            'currency' => $currency,
            'publishable_key' => $pk,
            'has_secret' => $has_secret,
            'standalone_active' => self::standalone_active(),
        ));
        $enabled = isset($settings['enabled']) && $settings['enabled'] === 'yes';
        $title = isset($settings['title']) ? (string) $settings['title'] : 'Pay with card, Apple Pay or Google Pay';
        $description = isset($settings['description']) ? (string) $settings['description'] : 'Enter your card securely after you place your order. You will stay on this page.';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <p><?php esc_html_e('WooCommerce remains the cart and checkout. This screen stores the Wishes keys and the label shoppers see. Card numbers are entered in the Wishes frame, not on this site.', 'vernal-contentum'); ?></p>

            <?php if ($ready['standalone_conflict']) : ?>
                <div class="notice notice-warning">
                    <p><?php esc_html_e('The standalone Wishes Checkout plugin is active, so Vernal is not registering a second payment method. Deactivate that plugin when you want Vernal to host checkout. Existing orders and the saved keys stay where they are. Vernal will not deactivate it for you.', 'vernal-contentum'); ?></p>
                </div>
            <?php endif; ?>

            <div class="card" style="max-width: 720px; padding: 12px 16px; margin: 16px 0;">
                <h2><?php esc_html_e('Readiness', 'vernal-contentum'); ?></h2>
                <ul>
                    <li><?php echo $ready['woocommerce'] ? '✓' : '•'; ?> <?php esc_html_e('WooCommerce is active', 'vernal-contentum'); ?></li>
                    <li><?php echo $ready['usd'] ? '✓' : '•'; ?> <?php echo esc_html(sprintf('Store currency is USD%s', $currency !== '' ? ' (now ' . $currency . ')' : '')); ?></li>
                    <li><?php echo $ready['publishable_key'] ? '✓' : '•'; ?> <?php esc_html_e('Publishable key is set', 'vernal-contentum'); ?></li>
                    <li><?php echo $ready['secret_key'] ? '✓' : '•'; ?> <?php esc_html_e('Secret key is stored on the server', 'vernal-contentum'); ?></li>
                    <li><?php echo $ready['standalone_conflict'] ? '•' : '✓'; ?> <?php esc_html_e('Standalone Wishes Checkout plugin is not also active', 'vernal-contentum'); ?></li>
                </ul>
                <p><?php esc_html_e('In the Wishes dashboard, turn on Embedded Checkout for this business. Sandbox or live follows the key pair you paste here. Test funds never become spendable balance.', 'vernal-contentum'); ?></p>
            </div>

            <?php settings_errors(Vernal_Wishes_Contract::OPTION_KEY); ?>

            <form action="options.php" method="post">
                <?php settings_fields('vernal_wishes_checkout'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable', 'vernal-contentum'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[enabled]" value="1" <?php checked($enabled); ?> />
                                <?php esc_html_e('Enable Wishes Checkout', 'vernal-contentum'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vernal-wishes-title"><?php esc_html_e('Checkout title', 'vernal-contentum'); ?></label></th>
                        <td><input id="vernal-wishes-title" class="regular-text" type="text" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[title]" value="<?php echo esc_attr($title); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vernal-wishes-description"><?php esc_html_e('Checkout description', 'vernal-contentum'); ?></label></th>
                        <td><textarea id="vernal-wishes-description" class="large-text" rows="3" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[description]"><?php echo esc_textarea($description); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vernal-wishes-pk"><?php esc_html_e('Publishable key', 'vernal-contentum'); ?></label></th>
                        <td>
                            <input id="vernal-wishes-pk" class="regular-text" type="text" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[publishable_key]" value="<?php echo esc_attr($pk); ?>" autocomplete="off" />
                            <p class="description"><?php esc_html_e('From Wishes → API Keys. Starts with pk_. This is the only key the browser receives.', 'vernal-contentum'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vernal-wishes-sk"><?php esc_html_e('Secret key', 'vernal-contentum'); ?></label></th>
                        <td>
                            <input id="vernal-wishes-sk" class="regular-text" type="password" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[secret_key]" value="" autocomplete="new-password" />
                            <p class="description">
                                <?php if ($has_secret) : ?>
                                    <?php esc_html_e('A secret key is saved on this server. Leave this blank to keep it. Tick Replace secret key and paste a new sk_ value to change it, or tick Clear stored secret to remove it.', 'vernal-contentum'); ?>
                                <?php else : ?>
                                    <?php esc_html_e('From Wishes → API Keys. Starts with sk_. Stored on the server and never sent to the browser.', 'vernal-contentum'); ?>
                                <?php endif; ?>
                            </p>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[replace_secret]" value="1" />
                                <?php esc_html_e('Replace secret key', 'vernal-contentum'); ?>
                            </label>
                            <br />
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[clear_secret]" value="1" />
                                <?php esc_html_e('Clear stored secret', 'vernal-contentum'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(__('Save Ecommerce settings', 'vernal-contentum')); ?>
            </form>

            <p class="description">
                <?php esc_html_e('Moving off the standalone Wishes Checkout plugin: deactivate it yourself after these keys are saved. Vernal uses the same WooCommerce setting record and the same order payment id, so existing paid orders are left in place.', 'vernal-contentum'); ?>
            </p>
        </div>
        <?php
    }
}
