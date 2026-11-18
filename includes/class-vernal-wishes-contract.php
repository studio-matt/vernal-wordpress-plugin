<?php
/**
 * Decision logic for Wishes Embedded Checkout.
 *
 * Verified against wishes-checkout 1.4.0 (https://wishes.inc/downloads/wishes-checkout.zip)
 * and the Embedded Checkout section of https://wishes.inc/api-docs.
 *
 * Contract notes (do not "fix" these by invention):
 * - create_session and refund send the merchant secret as x-api-key.
 * - confirm, in the 1.4.0 client, does not send the merchant secret.
 *   Payment proof is the confirm response plus order, amount, and USD checks.
 * - The Supabase anon JWT is the public client credential (role "anon"),
 *   sent as apikey and Authorization. It is not the merchant secret.
 * - Stars: paid_with=stars and 0 < stars_surcharge_cents < 500, with
 *   amount_cents equal to the order plus that surcharge.
 * - Partial refunds: the 1.4.0 client sends amount_cents when an amount is given.
 * - Iframe proof is not accepted. Messages are hints from https://wishes.inc only.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vernal_Wishes_Contract {

    const EMBED_ORIGIN = 'https://wishes.inc';
    const API_URL = 'https://qpgwfbyktffnnqizqzsk.supabase.co/functions/v1/embedded-checkout';
    const OPTION_KEY = 'woocommerce_wishes_settings';
    const STARS_SURCHARGE_MAX_CENTS = 499;

    /**
     * Decimal-safe dollar string to integer cents. Rejects junk and negatives.
     *
     * @param mixed $amount
     * @return int|null
     */
    public static function amount_to_cents($amount) {
        if (is_bool($amount) || is_array($amount) || is_object($amount) || $amount === null) {
            return null;
        }
        $raw = trim((string) $amount);
        if ($raw === '' || !preg_match('/^\d+(\.\d+)?$/', $raw)) {
            return null;
        }
        $parts = explode('.', $raw, 2);
        $whole = $parts[0];
        if (strlen($whole) > 9) {
            return null;
        }
        $frac = isset($parts[1]) ? $parts[1] : '';
        $frac = substr(str_pad($frac, 3, '0'), 0, 3);
        $cents = ((int) $whole) * 100 + (int) substr($frac, 0, 2);
        if ((int) $frac[2] >= 5) {
            $cents++;
        }
        return $cents;
    }

    /**
     * @param int    $order_id
     * @param string $order_key
     * @return string|null
     */
    public static function order_reference($order_id, $order_key) {
        $order_id = (int) $order_id;
        $order_key = (string) $order_key;
        if ($order_id < 1 || !preg_match('/^[A-Za-z0-9_\-]+$/', $order_key)) {
            return null;
        }
        return 'wc-order:' . $order_id . ':' . $order_key;
    }

    /**
     * @param array $stored session_id, amount_cents, order_ref
     * @param int   $amount_cents
     * @param string $order_ref
     * @param bool  $paid
     * @return string|null Session id to reuse, or null to create one.
     */
    public static function reuse_session(array $stored, $amount_cents, $order_ref, $paid) {
        if ($paid) {
            return null;
        }
        $session = isset($stored['session_id']) ? (string) $stored['session_id'] : '';
        if (!preg_match('/^[A-Za-z0-9_\-]{8,}$/', $session)) {
            return null;
        }
        if ((int) (isset($stored['amount_cents']) ? $stored['amount_cents'] : -1) !== (int) $amount_cents) {
            return null;
        }
        if ((string) (isset($stored['order_ref']) ? $stored['order_ref'] : '') !== (string) $order_ref) {
            return null;
        }
        return $session;
    }

    /**
     * @param array $expected amount_cents, order_ref, payment_id, stored_payment_id
     * @param array $response Confirm payload from Wishes.
     * @return array{code:string,stars_surcharge_cents:int}
     */
    public static function assess_confirmation(array $expected, array $response) {
        $incoming = isset($expected['payment_id']) ? (string) $expected['payment_id'] : '';
        $stored = isset($expected['stored_payment_id']) ? (string) $expected['stored_payment_id'] : '';
        if ($stored !== '') {
            if ($incoming !== '' && hash_equals($stored, $incoming)) {
                return array('code' => 'already_paid', 'stars_surcharge_cents' => 0);
            }
            return array('code' => 'conflict_paid', 'stars_surcharge_cents' => 0);
        }
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $incoming)) {
            return array('code' => 'invalid_payment', 'stars_surcharge_cents' => 0);
        }
        if (empty($response['approved'])) {
            return array('code' => 'not_approved', 'stars_surcharge_cents' => 0);
        }
        $order_ref = isset($expected['order_ref']) ? (string) $expected['order_ref'] : '';
        if ($order_ref === '' || (string) (isset($response['order_id']) ? $response['order_id'] : '') !== $order_ref) {
            return array('code' => 'order_mismatch', 'stars_surcharge_cents' => 0);
        }
        if (strtoupper((string) (isset($response['currency']) ? $response['currency'] : '')) !== 'USD') {
            return array('code' => 'currency_mismatch', 'stars_surcharge_cents' => 0);
        }
        if (!isset($response['amount_cents']) || !is_numeric($response['amount_cents'])) {
            return array('code' => 'amount_mismatch', 'stars_surcharge_cents' => 0);
        }
        $amount = (int) $response['amount_cents'];
        $expect = (int) (isset($expected['amount_cents']) ? $expected['amount_cents'] : -1);
        $surcharge = isset($response['stars_surcharge_cents']) ? (int) $response['stars_surcharge_cents'] : 0;
        $stars = (isset($response['paid_with']) ? (string) $response['paid_with'] : '') === 'stars';

        if ($surcharge !== 0 && !$stars) {
            return array('code' => 'invalid_stars', 'stars_surcharge_cents' => 0);
        }
        if ($stars && $surcharge !== 0) {
            if ($surcharge < 0 || $surcharge > self::STARS_SURCHARGE_MAX_CENTS) {
                return array('code' => 'invalid_stars', 'stars_surcharge_cents' => 0);
            }
            if ($amount === $expect + $surcharge) {
                return array('code' => 'stars_surcharge', 'stars_surcharge_cents' => $surcharge);
            }
            if ($amount !== $expect) {
                return array('code' => 'invalid_stars', 'stars_surcharge_cents' => 0);
            }
        }
        if ($amount !== $expect) {
            return array('code' => 'amount_mismatch', 'stars_surcharge_cents' => 0);
        }
        return array('code' => 'approved', 'stars_surcharge_cents' => 0);
    }

    /**
     * Partial refunds are supported: wishes-checkout 1.4.0 sends amount_cents.
     *
     * @param array $ctx payment_id, order_total_cents, confirmed_refunded_cents, requested_cents|null, lock_held
     * @return array{ok:bool,code:string,amount_cents:int}
     */
    public static function assess_refund(array $ctx) {
        $payment_id = isset($ctx['payment_id']) ? (string) $ctx['payment_id'] : '';
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $payment_id)) {
            return array('ok' => false, 'code' => 'missing_payment', 'amount_cents' => 0);
        }
        if (!empty($ctx['lock_held'])) {
            return array('ok' => false, 'code' => 'in_progress', 'amount_cents' => 0);
        }
        $total = (int) (isset($ctx['order_total_cents']) ? $ctx['order_total_cents'] : 0);
        $already = (int) (isset($ctx['confirmed_refunded_cents']) ? $ctx['confirmed_refunded_cents'] : 0);
        if ($already < 0) {
            $already = 0;
        }
        $remaining = $total - $already;
        if ($total <= 0 || $remaining <= 0) {
            return array('ok' => false, 'code' => 'nothing_left', 'amount_cents' => 0);
        }
        $requested = array_key_exists('requested_cents', $ctx) ? $ctx['requested_cents'] : null;
        if ($requested === null) {
            $requested = $remaining;
        } else {
            $requested = (int) $requested;
        }
        if ($requested <= 0) {
            return array('ok' => false, 'code' => 'invalid_amount', 'amount_cents' => 0);
        }
        if ($requested > $remaining) {
            return array('ok' => false, 'code' => 'over_refund', 'amount_cents' => 0);
        }
        return array(
            'ok' => true,
            'code' => ($requested === $remaining) ? 'full' : 'partial',
            'amount_cents' => $requested,
        );
    }

    /**
     * Browser postMessage is a hint. Accept only the checkout frame and the exact origin.
     *
     * @param string $origin
     * @param bool   $source_is_frame
     * @param string $expected_origin
     * @return bool
     */
    public static function iframe_message_allowed($origin, $source_is_frame, $expected_origin = self::EMBED_ORIGIN) {
        return $source_is_frame === true && is_string($origin) && $origin === $expected_origin;
    }

    /**
     * @param array $existing
     * @param array $input
     * @return array{settings:array,errors:array}
     */
    public static function merge_settings(array $existing, array $input) {
        $out = $existing;
        $errors = array();
        $out['enabled'] = !empty($input['enabled']) ? 'yes' : 'no';

        $title = trim((string) (isset($input['title']) ? $input['title'] : ''));
        $out['title'] = $title !== '' ? $title : 'Pay with card, Apple Pay or Google Pay';
        $out['description'] = trim((string) (isset($input['description']) ? $input['description'] : ''));

        $pk = trim((string) (isset($input['publishable_key']) ? $input['publishable_key'] : ''));
        if ($pk !== '' && strpos($pk, 'pk_') !== 0) {
            $errors[] = 'Publishable key must start with pk_. The previous publishable key was left unchanged.';
        } else {
            $out['publishable_key'] = $pk;
        }

        $replace = !empty($input['replace_secret']);
        $clear = !empty($input['clear_secret']);
        $posted = trim((string) (isset($input['secret_key']) ? $input['secret_key'] : ''));
        $stored = isset($existing['secret_key']) ? (string) $existing['secret_key'] : '';

        if ($replace && $clear) {
            $errors[] = 'Choose either replace or clear for the secret key. The stored secret was left unchanged.';
            $out['secret_key'] = $stored;
        } elseif ($clear) {
            $out['secret_key'] = '';
        } elseif ($posted === '') {
            $out['secret_key'] = $stored;
        } elseif (strpos($posted, 'sk_') !== 0) {
            $errors[] = 'Secret key must start with sk_. The stored secret was left unchanged.';
            $out['secret_key'] = $stored;
        } elseif ($stored !== '' && !$replace) {
            $errors[] = 'The stored secret was left unchanged. Tick Replace secret key to use the new value.';
            $out['secret_key'] = $stored;
        } else {
            $out['secret_key'] = $posted;
        }

        unset($out['replace_secret'], $out['clear_secret']);
        return array('settings' => $out, 'errors' => $errors);
    }

    /**
     * @param array $ctx wc_active, currency, publishable_key, has_secret, standalone_active
     * @return array{woocommerce:bool,usd:bool,publishable_key:bool,secret_key:bool,standalone_conflict:bool,ready:bool}
     */
    public static function readiness(array $ctx) {
        $wc = !empty($ctx['wc_active']);
        $usd = strtoupper((string) (isset($ctx['currency']) ? $ctx['currency'] : '')) === 'USD';
        $pk = strpos((string) (isset($ctx['publishable_key']) ? $ctx['publishable_key'] : ''), 'pk_') === 0;
        $secret = !empty($ctx['has_secret']);
        $standalone = !empty($ctx['standalone_active']);
        return array(
            'woocommerce' => $wc,
            'usd' => $usd,
            'publishable_key' => $pk,
            'secret_key' => $secret,
            'standalone_conflict' => $standalone,
            'ready' => $wc && $usd && $pk && $secret && !$standalone,
        );
    }
}
