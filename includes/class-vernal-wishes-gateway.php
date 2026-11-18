<?php
/**
 * WooCommerce payment gateway for Wishes Embedded Checkout.
 *
 * Loaded only when WooCommerce is present and the standalone wishes-checkout
 * plugin is not already providing gateway id "wishes".
 *
 * Public anon key: copied from wishes-checkout 1.4.0 wishes-checkout.php
 * (WISHES_CHECKOUT_ANON). Payload role is "anon" for project
 * qpgwfbyktffnnqizqzsk. It is not a merchant secret.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Wishes_Gateway extends WC_Payment_Gateway {

    /**
     * Public Supabase anon JWT from wishes-checkout 1.4.0. Not the merchant sk_.
     */
    const ANON_KEY = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InFwZ3dmYnlrdGZmbm5xaXpxenNrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzUwMDkwODgsImV4cCI6MjA5MDU4NTA4OH0.Z0IXf3iI3BbUIHYmcubv-JolyVUB5JT2QfB5RFiaymA';

    public function __construct() {
        $this->id = 'wishes';
        $this->method_title = __('Wishes Checkout', 'vernal-contentum');
        $this->method_description = __('Shoppers pay in a Wishes frame on your checkout page. Orders are marked paid only after Wishes confirms the payment and the amount.', 'vernal-contentum');
        $this->has_fields = true;
        $this->supports = array('products', 'refunds');
        $this->init_form_fields();
        $this->init_settings();
        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        add_action('woocommerce_api_wishes_return', array($this, 'handle_return'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_inline'));
    }

    public function init_form_fields() {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable', 'vernal-contentum'),
                'type' => 'checkbox',
                'default' => 'no',
            ),
            'title' => array(
                'title' => __('Title', 'vernal-contentum'),
                'type' => 'text',
                'default' => 'Pay with card, Apple Pay or Google Pay',
            ),
            'description' => array(
                'title' => __('Description', 'vernal-contentum'),
                'type' => 'textarea',
                'default' => 'Enter your card securely after you place your order. You will stay on this page.',
            ),
            'publishable_key' => array(
                'title' => __('Publishable key', 'vernal-contentum'),
                'type' => 'text',
                'default' => '',
            ),
            'secret_key' => array(
                'title' => __('Secret key', 'vernal-contentum'),
                'type' => 'password',
                'default' => '',
            ),
        );
    }

    /**
     * WooCommerce's Manage link lands here. Configuration stays on Vernal → Ecommerce.
     */
    public function admin_options() {
        wp_safe_redirect(admin_url('admin.php?page=' . Vernal_Ecommerce::PAGE));
        exit;
    }

    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }
        $pk = (string) $this->get_option('publishable_key');
        $sk = (string) $this->get_option('secret_key');
        if (strpos($pk, 'pk_') !== 0 || strpos($sk, 'sk_') !== 0) {
            return false;
        }
        return function_exists('get_woocommerce_currency') && get_woocommerce_currency() === 'USD';
    }

    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wp_kses_post($this->description));
        }
        echo '<div id="wishes-inline-mount"></div>';
    }

    public function enqueue_inline() {
        if (is_admin()) {
            return;
        }
        $on_checkout = function_exists('is_checkout') && is_checkout() && (!function_exists('is_order_received_page') || !is_order_received_page());
        $on_pay = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay');
        if (!$on_checkout && !$on_pay) {
            return;
        }
        wp_enqueue_script(
            'vernal-wishes-inline',
            VERNAL_CONTENTUM_PLUGIN_URL . 'assets/wishes-checkout-inline.js',
            array(),
            VERNAL_CONTENTUM_VERSION,
            true
        );
        wp_localize_script('vernal-wishes-inline', 'VernalWishesInline', array(
            'pk' => (string) $this->get_option('publishable_key'),
        ));
    }

    public function process_payment($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Order not found.', 'vernal-contentum'), 'error');
            return array('result' => 'failure');
        }
        if (get_woocommerce_currency() !== 'USD') {
            wc_add_notice(__('Wishes Checkout is available for USD orders only.', 'vernal-contentum'), 'error');
            return array('result' => 'failure');
        }
        if ($order->is_paid()) {
            return array('result' => 'success', 'redirect' => $this->get_return_url($order));
        }
        $cents = Vernal_Wishes_Contract::amount_to_cents($order->get_total());
        $ref = Vernal_Wishes_Contract::order_reference($order->get_id(), $order->get_order_key());
        if ($cents === null || $cents <= 0 || $ref === null) {
            wc_add_notice(__('This order cannot be sent to Wishes Checkout.', 'vernal-contentum'), 'error');
            return array('result' => 'failure');
        }
        $secret = (string) $this->get_option('secret_key');
        if (strpos($secret, 'sk_') !== 0) {
            wc_add_notice(__('Checkout is not fully set up. Please contact the store.', 'vernal-contentum'), 'error');
            return array('result' => 'failure');
        }

        $session = Vernal_Wishes_Contract::reuse_session(
            array(
                'session_id' => (string) $order->get_meta('_wishes_session_id'),
                'amount_cents' => (int) $order->get_meta('_wishes_session_amount_cents'),
                'order_ref' => (string) $order->get_meta('_wishes_session_order_ref'),
            ),
            $cents,
            $ref,
            false
        );

        if ($session === null) {
            $response = $this->api(array(
                'action' => 'create_session',
                'amount_cents' => $cents,
                'order_id' => $ref,
                'description' => sprintf('Order #%s', $order->get_order_number()),
                'prefill' => $this->prefill_from_order($order),
            ), true);
            $session = is_array($response) ? (string) (isset($response['session_id']) ? $response['session_id'] : '') : '';
            if (!preg_match('/^[A-Za-z0-9_\-]{8,}$/', $session)) {
                $message = is_array($response) && !empty($response['error']) ? (string) $response['error'] : __('please try again', 'vernal-contentum');
                wc_add_notice(
                    sprintf(
                        /* translators: %s is a gateway error that does not include card data */
                        __('Could not start checkout: %s', 'vernal-contentum'),
                        wp_strip_all_tags($message)
                    ),
                    'error'
                );
                return array('result' => 'failure');
            }
            $order->update_meta_data('_wishes_session_id', $session);
            $order->update_meta_data('_wishes_session_amount_cents', $cents);
            $order->update_meta_data('_wishes_session_order_ref', $ref);
            $order->save();
        }

        if (!$order->has_status('pending')) {
            $order->update_status('pending', __('Awaiting Wishes Checkout payment.', 'vernal-contentum'));
        }

        $return = add_query_arg('order_id', $order->get_id(), WC()->api_request_url('wishes_return'));
        $hash = http_build_query(array(
            'wishes-pay' => $session,
            'ret' => $return,
        ));
        return array(
            'result' => 'success',
            'redirect' => wc_get_checkout_url() . '#' . $hash,
        );
    }

    /**
     * Shopper return is not proof. Confirm with Wishes, then mark the order paid once.
     */
    public function handle_return() {
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $payment_id = isset($_GET['wishes_payment']) ? sanitize_text_field(wp_unslash($_GET['wishes_payment'])) : '';
        $order = $order_id ? wc_get_order($order_id) : false;
        if (!$order || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payment_id)) {
            wp_safe_redirect(function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/'));
            exit;
        }

        $stored_payment = (string) $order->get_meta('_wishes_payment_id');
        if ($order->is_paid() && ($stored_payment === '' || hash_equals($stored_payment, $payment_id))) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }
        if ($order->is_paid()) {
            $order->add_order_note(__('Ignored a different Wishes payment on an order that is already paid.', 'vernal-contentum'));
            $order->save();
            wc_add_notice(__('This order is already paid.', 'vernal-contentum'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $ref = Vernal_Wishes_Contract::order_reference($order->get_id(), $order->get_order_key());
        $session = (string) $order->get_meta('_wishes_session_id');
        $stored_ref = (string) $order->get_meta('_wishes_session_order_ref');
        if ($session === '' || $ref === null || $stored_ref !== $ref) {
            $order->add_order_note(__('Wishes return rejected: this order has no matching checkout session.', 'vernal-contentum'));
            $order->save();
            wc_add_notice(__('We could not confirm your payment. Please try again.', 'vernal-contentum'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $response = $this->api(array(
            'action' => 'confirm',
            'payment_id' => $payment_id,
        ), false);
        if (!is_array($response)) {
            $response = array();
        }

        $expected = Vernal_Wishes_Contract::amount_to_cents($order->get_total());
        $assessment = Vernal_Wishes_Contract::assess_confirmation(array(
            'amount_cents' => $expected,
            'order_ref' => $ref,
            'payment_id' => $payment_id,
            'stored_payment_id' => $stored_payment,
        ), $response);

        if ($assessment['code'] === 'stars_surcharge') {
            $original = (int) $expected;
            $surcharge = (int) $assessment['stars_surcharge_cents'];
            $this->apply_stars_rounding($order, $surcharge, $original);
            $expected = Vernal_Wishes_Contract::amount_to_cents($order->get_total());
            if ($expected !== $original + $surcharge) {
                $order->add_order_note(__('Wishes Stars rounding could not be applied to the order total.', 'vernal-contentum'));
                $order->save();
                wc_add_notice(__('We could not confirm your payment. Please try again.', 'vernal-contentum'), 'error');
                wp_safe_redirect(wc_get_checkout_url());
                exit;
            }
            $assessment = Vernal_Wishes_Contract::assess_confirmation(array(
                'amount_cents' => $expected,
                'order_ref' => $ref,
                'payment_id' => $payment_id,
                'stored_payment_id' => (string) $order->get_meta('_wishes_payment_id'),
            ), $response);
        }

        if ($assessment['code'] !== 'approved' && $assessment['code'] !== 'already_paid') {
            $order->add_order_note(sprintf(
                /* translators: %s is an internal confirmation code, not card data */
                __('Wishes payment could not be verified (%s).', 'vernal-contentum'),
                $assessment['code']
            ));
            $order->save();
            wc_add_notice(__('We could not confirm your payment. Please try again.', 'vernal-contentum'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order->get_id());
        $recorded = (string) $order->get_meta('_wishes_payment_id');
        if ($order->is_paid() && ($recorded === '' || hash_equals($recorded, $payment_id))) {
            wp_safe_redirect($this->get_return_url($order));
            exit;
        }
        if ($order->is_paid()) {
            wc_add_notice(__('This order is already paid.', 'vernal-contentum'), 'error');
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order->update_meta_data('_wishes_payment_id', $payment_id);
        if (!empty($response['test_mode'])) {
            $order->add_order_note(__('Wishes TEST payment — do not ship.', 'vernal-contentum'));
        }
        $order->payment_complete($payment_id);
        $order->add_order_note(sprintf(
            /* translators: %s is the Wishes payment id */
            __('Paid with Wishes Checkout. Payment %s', 'vernal-contentum'),
            $payment_id
        ));
        $order->save();
        if (function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }
        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);
        if (!$order) {
            return new WP_Error('wishes', __('Order not found.', 'vernal-contentum'));
        }
        $payment_id = (string) $order->get_meta('_wishes_payment_id');
        $secret = (string) $this->get_option('secret_key');
        if (strpos($secret, 'sk_') !== 0) {
            return new WP_Error('wishes', __('Add the Wishes secret key before refunding.', 'vernal-contentum'));
        }
        $lock = (int) $order->get_meta('_wishes_refund_lock');
        $ledger = $order->get_meta('_wishes_refund_ids');
        if (!is_array($ledger)) {
            $ledger = array();
        }
        $confirmed = 0;
        foreach ($ledger as $row) {
            if (is_array($row) && isset($row['amount_cents'])) {
                $confirmed += (int) $row['amount_cents'];
            }
        }
        $decision = Vernal_Wishes_Contract::assess_refund(array(
            'payment_id' => $payment_id,
            'order_total_cents' => Vernal_Wishes_Contract::amount_to_cents($order->get_total()),
            'confirmed_refunded_cents' => $confirmed,
            'requested_cents' => $amount === null ? null : Vernal_Wishes_Contract::amount_to_cents($amount),
            'lock_held' => $lock > 0 && (time() - $lock) < 60,
        ));
        if (!$decision['ok']) {
            return new WP_Error('wishes', $this->refund_error_message($decision['code']));
        }

        $order->update_meta_data('_wishes_refund_lock', time());
        $order->save();
        $response = $this->api(array(
            'action' => 'refund',
            'payment_id' => $payment_id,
            'amount_cents' => (int) $decision['amount_cents'],
        ), true);
        $order->delete_meta_data('_wishes_refund_lock');

        if (!is_array($response) || !empty($response['error'])) {
            $order->save();
            $message = is_array($response) && !empty($response['error']) ? (string) $response['error'] : __('Wishes did not confirm the refund.', 'vernal-contentum');
            return new WP_Error('wishes', $message);
        }

        $refund_id = isset($response['refund_id']) ? (string) $response['refund_id'] : '';
        $ledger[] = array(
            'id' => $refund_id,
            'amount_cents' => (int) $decision['amount_cents'],
            'reason' => (string) $reason,
        );
        $order->update_meta_data('_wishes_refund_ids', $ledger);
        $order->add_order_note(sprintf(
            /* translators: 1: amount, 2: provider refund id */
            __('Refunded %1$s via Wishes (%2$s). %3$s', 'vernal-contentum'),
            wp_strip_all_tags(wc_price(((int) $decision['amount_cents']) / 100)),
            $refund_id !== '' ? $refund_id : __('no refund id returned', 'vernal-contentum'),
            (string) $reason
        ));
        $order->save();
        return true;
    }

    private function refund_error_message($code) {
        switch ($code) {
            case 'missing_payment':
                return __('No Wishes payment is stored on this order.', 'vernal-contentum');
            case 'in_progress':
                return __('A Wishes refund is already in progress for this order.', 'vernal-contentum');
            case 'over_refund':
                return __('That refund is larger than the amount still available at Wishes.', 'vernal-contentum');
            case 'nothing_left':
                return __('This Wishes payment has already been fully refunded.', 'vernal-contentum');
            default:
                return __('This refund cannot be sent to Wishes.', 'vernal-contentum');
        }
    }

    private function apply_stars_rounding($order, $surcharge_cents, $original_cents) {
        if ((string) $order->get_meta('_wishes_stars_surcharge_cents') !== '') {
            return;
        }
        $dollars = number_format($surcharge_cents / 100, 2, '.', '');
        $fee = new WC_Order_Item_Fee();
        $fee->set_name(__('Stars rounding', 'vernal-contentum'));
        $fee->set_amount($dollars);
        $fee->set_total($dollars);
        $fee->set_tax_status('none');
        $order->add_item($fee);
        $order->calculate_totals(false);
        $order->update_meta_data('_wishes_stars_surcharge_cents', (string) $surcharge_cents);
        $order->update_meta_data('_wishes_original_amount_cents', (string) $original_cents);
        $order->save();
    }

    private function prefill_from_order($order) {
        $first = $order->get_shipping_first_name() !== '' ? $order->get_shipping_first_name() : $order->get_billing_first_name();
        $last = $order->get_shipping_last_name() !== '' ? $order->get_shipping_last_name() : $order->get_billing_last_name();
        return array(
            'email' => (string) $order->get_billing_email(),
            'name' => trim($first . ' ' . $last),
            'line1' => $order->get_shipping_address_1() !== '' ? $order->get_shipping_address_1() : $order->get_billing_address_1(),
            'line2' => $order->get_shipping_address_2() !== '' ? $order->get_shipping_address_2() : $order->get_billing_address_2(),
            'city' => $order->get_shipping_city() !== '' ? $order->get_shipping_city() : $order->get_billing_city(),
            'state' => $order->get_shipping_state() !== '' ? $order->get_shipping_state() : $order->get_billing_state(),
            'zip' => $order->get_shipping_postcode() !== '' ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
            'country' => $order->get_shipping_country() !== '' ? $order->get_shipping_country() : $order->get_billing_country(),
        );
    }

    /**
     * @param array $body
     * @param bool  $with_secret Merchant sk_ as x-api-key. Confirm does not use it.
     * @return array
     */
    private function api(array $body, $with_secret) {
        $headers = array(
            'Content-Type' => 'application/json',
            'apikey' => self::ANON_KEY,
            'Authorization' => 'Bearer ' . self::ANON_KEY,
        );
        if ($with_secret) {
            $secret = (string) $this->get_option('secret_key');
            if ($secret !== '') {
                $headers['x-api-key'] = $secret;
            }
        }
        $res = wp_remote_post(Vernal_Wishes_Contract::API_URL, array(
            'timeout' => 30,
            'headers' => $headers,
            'body' => wp_json_encode($body),
        ));
        if (is_wp_error($res)) {
            return array('error' => $res->get_error_message());
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return is_array($data) ? $data : array('error' => 'Bad response from Wishes');
    }
}

/**
 * Called from woocommerce_blocks_payment_method_type_registration.
 *
 * @param object $registry
 */
function vernal_wishes_register_block_method($registry) {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }
    if (!class_exists('Vernal_Wishes_Blocks')) {
        class Vernal_Wishes_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
            protected $name = 'wishes';

            public function initialize() {
                $this->settings = get_option(Vernal_Wishes_Contract::OPTION_KEY, array());
                if (!is_array($this->settings)) {
                    $this->settings = array();
                }
            }

            public function is_active() {
                $gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
                $gateway = isset($gateways['wishes']) ? $gateways['wishes'] : null;
                return $gateway ? $gateway->is_available() : false;
            }

            public function get_payment_method_script_handles() {
                if (!wp_script_is('vernal-wishes-inline', 'registered')) {
                    wp_register_script(
                        'vernal-wishes-inline',
                        VERNAL_CONTENTUM_PLUGIN_URL . 'assets/wishes-checkout-inline.js',
                        array(),
                        VERNAL_CONTENTUM_VERSION,
                        true
                    );
                }
                wp_localize_script('vernal-wishes-inline', 'VernalWishesInline', array(
                    'pk' => isset($this->settings['publishable_key']) ? (string) $this->settings['publishable_key'] : '',
                ));
                wp_register_script(
                    'vernal-wishes-blocks',
                    VERNAL_CONTENTUM_PLUGIN_URL . 'assets/wishes-checkout-blocks.js',
                    array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'vernal-wishes-inline'),
                    VERNAL_CONTENTUM_VERSION,
                    true
                );
                return array('vernal-wishes-blocks');
            }

            public function get_payment_method_data() {
                return array(
                    'title' => isset($this->settings['title']) ? $this->settings['title'] : 'Pay with card, Apple Pay or Google Pay',
                    'description' => isset($this->settings['description']) ? $this->settings['description'] : '',
                    'supports' => array('products', 'refunds'),
                );
            }
        }
    }
    $registry->register(new Vernal_Wishes_Blocks());
}
