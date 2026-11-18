<?php
/**
 * Wishes Embedded Checkout contract tests. No WooCommerce runtime and no live charges.
 * Run: php tests/test_wishes_checkout_contract.php
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__);
}

require_once dirname(__DIR__) . '/includes/class-vernal-wishes-contract.php';

function assert_true($cond, $msg) {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "OK: $msg\n";
}

$payment = '550e8400-e29b-41d4-a716-446655440000';
$other = '550e8400-e29b-41d4-a716-446655440001';
$ref = Vernal_Wishes_Contract::order_reference(42, 'wc_order_abc123');

assert_true(Vernal_Wishes_Contract::amount_to_cents('45.00') === 4500, 'USD dollars to cents');
assert_true(Vernal_Wishes_Contract::amount_to_cents('10.1') === 1010, 'single decimal pads');
assert_true(Vernal_Wishes_Contract::amount_to_cents('10.005') === 1001, 'half-up from the third decimal');
assert_true(Vernal_Wishes_Contract::amount_to_cents('10.004') === 1000, 'below half stays');
assert_true(Vernal_Wishes_Contract::amount_to_cents('19.99') === 1999, 'two-decimal price');
assert_true(Vernal_Wishes_Contract::amount_to_cents('-1.00') === null, 'negative amounts are rejected');
assert_true(Vernal_Wishes_Contract::amount_to_cents('12.00 USD') === null, 'junk amounts are rejected');
assert_true(Vernal_Wishes_Contract::amount_to_cents('') === null, 'empty amount is rejected');

assert_true($ref === 'wc-order:42:wc_order_abc123', 'order reference shape');
assert_true(Vernal_Wishes_Contract::order_reference(0, 'wc_order_x') === null, 'missing order id is rejected');
assert_true(Vernal_Wishes_Contract::order_reference(5, 'bad key') === null, 'order key cannot contain spaces');

$base = array(
    'amount_cents' => 4500,
    'order_ref' => $ref,
    'payment_id' => $payment,
    'stored_payment_id' => '',
);
$approved = array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4500,
    'currency' => 'USD',
    'paid_with' => 'card',
);

$ok = Vernal_Wishes_Contract::assess_confirmation($base, $approved);
assert_true($ok['code'] === 'approved', 'exact confirmation matches');

$mismatch = Vernal_Wishes_Contract::assess_confirmation($base, array_merge($approved, array('amount_cents' => 4501)));
assert_true($mismatch['code'] === 'amount_mismatch', 'amount mismatch is rejected');

$currency = Vernal_Wishes_Contract::assess_confirmation($base, array_merge($approved, array('currency' => 'EUR')));
assert_true($currency['code'] === 'currency_mismatch', 'non-USD confirmation is rejected');

$order = Vernal_Wishes_Contract::assess_confirmation($base, array_merge($approved, array('order_id' => 'wc-order:99:other')));
assert_true($order['code'] === 'order_mismatch', 'order reference mismatch is rejected');

$declined = Vernal_Wishes_Contract::assess_confirmation($base, array_merge($approved, array('approved' => false)));
assert_true($declined['code'] === 'not_approved', 'unapproved payment is not marked paid');

$again = Vernal_Wishes_Contract::assess_confirmation(array_merge($base, array('stored_payment_id' => $payment)), array());
assert_true($again['code'] === 'already_paid', 'repeat confirmation of the same payment is idempotent');

$conflict = Vernal_Wishes_Contract::assess_confirmation(array_merge($base, array(
    'stored_payment_id' => $payment,
    'payment_id' => $other,
)), $approved);
assert_true($conflict['code'] === 'conflict_paid', 'a second payment id cannot complete an already paid order');

$stars = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4999,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => 499,
));
assert_true($stars['code'] === 'stars_surcharge' && $stars['stars_surcharge_cents'] === 499, 'Stars surcharge under $5 is accepted');

$one = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4501,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => 1,
));
assert_true($one['code'] === 'stars_surcharge', 'one-cent Stars adjustment is the lower boundary');

$five = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 5000,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => 500,
));
assert_true($five['code'] === 'invalid_stars', '$5 Stars adjustment is rejected');

$negative = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4400,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => -100,
));
assert_true($negative['code'] === 'invalid_stars', 'negative Stars adjustment is rejected');

$unexplained = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4600,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => 50,
));
assert_true($unexplained['code'] === 'invalid_stars', 'Stars surcharge that does not explain the amount is rejected');

$fake_stars = Vernal_Wishes_Contract::assess_confirmation($base, array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4600,
    'currency' => 'USD',
    'paid_with' => 'card',
    'stars_surcharge_cents' => 100,
));
assert_true($fake_stars['code'] === 'invalid_stars', 'surcharge without Stars is rejected');

$after_fee = Vernal_Wishes_Contract::assess_confirmation(array_merge($base, array('amount_cents' => 4999)), array(
    'approved' => true,
    'order_id' => $ref,
    'amount_cents' => 4999,
    'currency' => 'USD',
    'paid_with' => 'stars',
    'stars_surcharge_cents' => 499,
));
assert_true($after_fee['code'] === 'approved', 'after the fee is on the order the same response matches exactly');

$full = Vernal_Wishes_Contract::assess_refund(array(
    'payment_id' => $payment,
    'order_total_cents' => 4500,
    'confirmed_refunded_cents' => 0,
    'requested_cents' => 4500,
    'lock_held' => false,
));
assert_true($full['ok'] && $full['code'] === 'full' && $full['amount_cents'] === 4500, 'full refund is allowed');

$partial = Vernal_Wishes_Contract::assess_refund(array(
    'payment_id' => $payment,
    'order_total_cents' => 4500,
    'confirmed_refunded_cents' => 1000,
    'requested_cents' => 500,
    'lock_held' => false,
));
assert_true($partial['ok'] && $partial['code'] === 'partial', 'partial refund is allowed by the verified client');

$over = Vernal_Wishes_Contract::assess_refund(array(
    'payment_id' => $payment,
    'order_total_cents' => 4500,
    'confirmed_refunded_cents' => 4000,
    'requested_cents' => 600,
    'lock_held' => false,
));
assert_true(!$over['ok'] && $over['code'] === 'over_refund', 'over-refund is rejected');

$dup = Vernal_Wishes_Contract::assess_refund(array(
    'payment_id' => $payment,
    'order_total_cents' => 4500,
    'confirmed_refunded_cents' => 0,
    'requested_cents' => 100,
    'lock_held' => true,
));
assert_true(!$dup['ok'] && $dup['code'] === 'in_progress', 'duplicate in-flight refund is rejected');

$missing = Vernal_Wishes_Contract::assess_refund(array(
    'payment_id' => '',
    'order_total_cents' => 4500,
    'confirmed_refunded_cents' => 0,
    'requested_cents' => 100,
    'lock_held' => false,
));
assert_true(!$missing['ok'] && $missing['code'] === 'missing_payment', 'refund without a payment id is rejected');

assert_true(Vernal_Wishes_Contract::iframe_message_allowed('https://wishes.inc', true) === true, 'iframe message from the checkout frame is a hint we can read');
assert_true(Vernal_Wishes_Contract::iframe_message_allowed('https://wishes.inc.evil.com', true) === false, 'lookalike origin is rejected');
assert_true(Vernal_Wishes_Contract::iframe_message_allowed('https://www.wishes.inc', true) === false, 'www origin is rejected');
assert_true(Vernal_Wishes_Contract::iframe_message_allowed('http://wishes.inc', true) === false, 'insecure origin is rejected');
assert_true(Vernal_Wishes_Contract::iframe_message_allowed('https://wishes.inc', false) === false, 'message whose source is not the frame is rejected');

$session = Vernal_Wishes_Contract::reuse_session(array(
    'session_id' => 'sess_abc12345',
    'amount_cents' => 4500,
    'order_ref' => $ref,
), 4500, $ref, false);
assert_true($session === 'sess_abc12345', 'checkout refresh reuses the locked session');
assert_true(Vernal_Wishes_Contract::reuse_session(array(
    'session_id' => 'sess_abc12345',
    'amount_cents' => 4500,
    'order_ref' => $ref,
), 4600, $ref, false) === null, 'a changed total starts a new session');
assert_true(Vernal_Wishes_Contract::reuse_session(array(
    'session_id' => 'sess_abc12345',
    'amount_cents' => 4500,
    'order_ref' => $ref,
), 4500, $ref, true) === null, 'a paid order does not open another session');

$kept = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved', 'publishable_key' => 'pk_old', 'extra' => 'keep'),
    array('enabled' => '1', 'title' => 'Card', 'description' => 'Stay here', 'publishable_key' => 'pk_new', 'secret_key' => '')
);
assert_true($kept['settings']['secret_key'] === 'sk_saved', 'blank secret field keeps the stored secret');
assert_true($kept['settings']['extra'] === 'keep', 'unrelated settings are not wiped');
assert_true($kept['settings']['publishable_key'] === 'pk_new', 'publishable key can be replaced in the open field');
assert_true(empty($kept['errors']), 'keeping a secret is not an error');

$blocked = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved'),
    array('secret_key' => 'sk_new', 'publishable_key' => 'pk_new', 'title' => '', 'description' => '')
);
assert_true($blocked['settings']['secret_key'] === 'sk_saved', 'a new secret is ignored unless replace is explicit');
assert_true(!empty($blocked['errors']), 'ignored secret replacement is reported');

$replaced = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved'),
    array('secret_key' => 'sk_new', 'replace_secret' => '1', 'publishable_key' => '', 'title' => 'T', 'description' => '')
);
assert_true($replaced['settings']['secret_key'] === 'sk_new', 'replace secret stores the new key');
assert_true(!isset($replaced['settings']['replace_secret']), 'replace flag is not stored');

$cleared = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved'),
    array('clear_secret' => '1', 'secret_key' => '', 'publishable_key' => '', 'title' => 'T', 'description' => '')
);
assert_true($cleared['settings']['secret_key'] === '', 'clear secret removes it only when asked');

$both = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved'),
    array('clear_secret' => '1', 'replace_secret' => '1', 'secret_key' => 'sk_new', 'publishable_key' => '', 'title' => 'T', 'description' => '')
);
assert_true($both['settings']['secret_key'] === 'sk_saved', 'replace and clear together leave the secret unchanged');

$first = Vernal_Wishes_Contract::merge_settings(
    array(),
    array('secret_key' => 'sk_first', 'publishable_key' => 'pk_first', 'title' => '', 'description' => '', 'enabled' => '1')
);
assert_true($first['settings']['secret_key'] === 'sk_first' && $first['settings']['enabled'] === 'yes', 'the first secret does not need the replace box');

$bad = Vernal_Wishes_Contract::merge_settings(
    array('secret_key' => 'sk_saved', 'publishable_key' => 'pk_old'),
    array('publishable_key' => 'not-a-key', 'secret_key' => 'nope', 'replace_secret' => '1', 'title' => 'T', 'description' => '')
);
assert_true($bad['settings']['publishable_key'] === 'pk_old' && $bad['settings']['secret_key'] === 'sk_saved', 'keys with the wrong prefix are not saved');

$absent = Vernal_Wishes_Contract::readiness(array(
    'wc_active' => false,
    'currency' => 'USD',
    'publishable_key' => 'pk_x',
    'has_secret' => true,
    'standalone_active' => false,
));
assert_true($absent['woocommerce'] === false && $absent['ready'] === false, 'WooCommerce absent is not ready');

$non_usd = Vernal_Wishes_Contract::readiness(array(
    'wc_active' => true,
    'currency' => 'EUR',
    'publishable_key' => 'pk_x',
    'has_secret' => true,
    'standalone_active' => false,
));
assert_true($non_usd['usd'] === false && $non_usd['ready'] === false, 'non-USD store is not ready');

$no_keys = Vernal_Wishes_Contract::readiness(array(
    'wc_active' => true,
    'currency' => 'USD',
    'publishable_key' => '',
    'has_secret' => false,
    'standalone_active' => false,
));
assert_true($no_keys['publishable_key'] === false && $no_keys['secret_key'] === false && $no_keys['ready'] === false, 'missing keys are not ready');

$standalone = Vernal_Wishes_Contract::readiness(array(
    'wc_active' => true,
    'currency' => 'USD',
    'publishable_key' => 'pk_x',
    'has_secret' => true,
    'standalone_active' => true,
));
assert_true($standalone['standalone_conflict'] === true && $standalone['ready'] === false, 'standalone plugin blocks a second gateway');

$ready = Vernal_Wishes_Contract::readiness(array(
    'wc_active' => true,
    'currency' => 'usd',
    'publishable_key' => 'pk_x',
    'has_secret' => true,
    'standalone_active' => false,
));
assert_true($ready['ready'] === true, 'USD store with both keys and no standalone plugin is ready');

$root = dirname(__DIR__);
$gateway = file_get_contents($root . '/includes/class-vernal-wishes-gateway.php');
$inline = file_get_contents($root . '/assets/wishes-checkout-inline.js');
$blocks = file_get_contents($root . '/assets/wishes-checkout-blocks.js');
$ecommerce = file_get_contents($root . '/includes/class-vernal-ecommerce.php');
$expected_anon = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InFwZ3dmYnlrdGZmbm5xaXpxenNrIiwicm9sZSI6ImFub24iLCJpYXQiOjE3NzUwMDkwODgsImV4cCI6MjA5MDU4NTA4OH0.Z0IXf3iI3BbUIHYmcubv-JolyVUB5JT2QfB5RFiaymA';

assert_true(strpos($gateway, $expected_anon) !== false, 'anon key matches wishes-checkout 1.4.0');
$payload = json_decode(base64_decode(strtr(explode('.', $expected_anon)[1], '-_', '+/')), true);
assert_true(is_array($payload) && $payload['role'] === 'anon' && $payload['ref'] === 'qpgwfbyktffnnqizqzsk', 'anon JWT is the public Supabase role, not a merchant secret');
assert_true(strpos($gateway, "'Authorization' => 'Bearer ' . self::ANON_KEY") !== false, 'Authorization bearer is the anon key');
assert_true(strpos($gateway, "array(\n            'action' => 'confirm',\n            'payment_id' => \$payment_id,\n        ), false)") !== false, 'confirm does not send the merchant secret');
assert_true(strpos($gateway, "'x-api-key'") !== false, 'merchant secret is sent as x-api-key');
assert_true(stripos($gateway, 'ccnumber') === false, 'gateway does not collect raw card numbers');
assert_true(strpos($gateway, 'update_post_meta') === false, 'order metadata uses WooCommerce CRUD, not post meta writes');
assert_true(strpos($gateway, "update_meta_data('_wishes_payment_id'") !== false, 'payment id is stored on the order');
assert_true(strpos($gateway, "id = 'wishes'") !== false, 'gateway id stays wishes');
assert_true(strpos($gateway, 'wishes-inline-mount') !== false, 'classic checkout mounts the frame');
assert_true(strpos($gateway, "get_woocommerce_currency() === 'USD'") !== false, 'classic availability requires USD');
assert_true(strpos($blocks, "name: 'wishes'") !== false, 'blocks checkout registers the wishes method');
assert_true(strpos($blocks, 'wishes-inline-mount') !== false, 'blocks checkout uses the same mount');
assert_true(strpos($inline, "var EXPECTED_ORIGIN = 'https://wishes.inc';") !== false, 'iframe origin is fixed to wishes.inc');
assert_true(strpos($inline, "var EMBED_PATH = '/checkout/embed';") !== false, 'embed path is the hosted checkout');
assert_true(strpos($inline, 'inline: \'1\'') !== false, 'embed asks for the inline frame');
assert_true(strpos($inline, 'function vernalWishesMessageAllowed') !== false, 'iframe policy is explicit');
assert_true(strpos($ecommerce, 'deactivate_plugins') === false, 'Vernal does not deactivate the standalone plugin');
assert_true(strpos($ecommerce, 'type="password"') !== false && strpos($ecommerce, 'name="<?php echo esc_attr(Vernal_Wishes_Contract::OPTION_KEY); ?>[secret_key]" value=""') !== false, 'secret field is empty and is not printed back');

echo "All Wishes checkout contract checks passed.\n";
exit(0);
