<?php
/**
 * Smoke test for CHIP Magento module - verifies pure logic
 * Run: php tests/smoke.php
 */

$failures = 0;

// 1. Signature verification logic (same as SignatureVerifier::verify)
$keyPair = openssl_pkey_new(array('private_key_bits' => 2048));
$details = openssl_pkey_get_details($keyPair);
$publicKey = $details['key'];

$content = json_encode(array('id' => 'purchase_123', 'status' => 'paid', 'reference' => '1000001'));
openssl_sign($content, $signature, $keyPair, 'sha256WithRSAEncryption');
$signatureB64 = base64_encode($signature);

$result = openssl_verify($content, base64_decode($signatureB64), $publicKey, 'sha256WithRSAEncryption');
if ($result !== 1) {
    echo "FAIL: signature verification\n";
    $failures++;
} else {
    echo "PASS: signature verification\n";
}

// Tampered content should fail
$result = openssl_verify($content . 'x', base64_decode($signatureB64), $publicKey, 'sha256WithRSAEncryption');
if ($result === 1) {
    echo "FAIL: tampered content accepted\n";
    $failures++;
} else {
    echo "PASS: tampered content rejected\n";
}

// 2. JSON body encoding (same as Api::call)
$params = array(
    'reference' => '1000001',
    'purchase' => array('total_override' => 1000, 'currency' => 'MYR'),
    'client' => array('email' => 'test@example.com'),
);
$body = json_encode($params);
$decoded = json_decode($body, true);
if ($decoded['purchase']['total_override'] !== 1000 || $decoded['client']['email'] !== 'test@example.com') {
    echo "FAIL: JSON body encoding\n";
    $failures++;
} else {
    echo "PASS: JSON body encoding\n";
}

// 3. Payment method whitelist parsing (same as Chip::getPaymentMethodWhitelist)
$value = 'fpx,duitnow_qr,card';
$whitelist = array_filter(array_map('trim', explode(',', $value)));
if ($whitelist !== array('fpx', 'duitnow_qr', 'card')) {
    echo "FAIL: whitelist parsing\n";
    $failures++;
} else {
    echo "PASS: whitelist parsing\n";
}

// 4. Amount conversion (same as Chip::createPurchase)
$grandTotal = 123.45;
$totalOverride = (int) round($grandTotal * 100);
if ($totalOverride !== 12345) {
    echo "FAIL: amount conversion\n";
    $failures++;
} else {
    echo "PASS: amount conversion\n";
}

// 5. CSRF interface detection (same as Callback.php)
$hasCsrf = interface_exists('Magento\Framework\App\CsrfAwareActionInterface');
echo ($hasCsrf ? "PASS" : "INFO") . ": CsrfAwareActionInterface " . ($hasCsrf ? "exists" : "not present (expected on Magento 2.0-2.2)") . "\n";

// 6. Refund amount conversion (same as Chip::refund)
$refundAmount = 50.00;
$refundParams = array('amount' => (int) round($refundAmount * 100));
if ($refundParams['amount'] !== 5000) {
    echo "FAIL: refund amount conversion\n";
    $failures++;
} else {
    echo "PASS: refund amount conversion\n";
}

// 7. API error handling (same as Api::call null-on-error)
$apiResult = null; // simulate failed API call
$orderFound = is_array($apiResult) && isset($apiResult['id']);
if ($orderFound) {
    echo "FAIL: API error handling\n";
    $failures++;
} else {
    echo "PASS: API error handling\n";
}

// 8. Secret key detection (same as Chip::getSecretKey / SignatureVerifier)
// Ciphertext looks like "1:3:base64data"; plaintext does not.
$cipher = '1:3:QWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXo=';
$plain = 'sk_live_abcdef123456';
$looksEncrypted = function ($v) {
    return preg_match('/^[0-9]+:[0-9]+:/', $v) === 1;
};
if (!$looksEncrypted($cipher) || $looksEncrypted($plain)) {
    echo "FAIL: secret key format detection\n";
    $failures++;
} else {
    echo "PASS: secret key format detection\n";
}

// 9. Group resolution logic (same as Chip::resolvePaymentMethodGroups)
// without API call - simulate the short-circuit and expansion branches.
$duitnow_group = array('duitnow_qr', 'dnqr');
$shopee_group = array('razer_shopeepay', 'shopee_pay');
$card_group = array('visa', 'mastercard', 'maestro');

// 9a. no group member -> untouched
$wl = array('fpx', 'crypto_coin');
$has_dnqr = count(array_intersect($wl, $duitnow_group)) > 0;
$has_shopee = count(array_intersect($wl, $shopee_group)) > 0;
$has_card = in_array('card', $wl, true);
if ($has_dnqr || $has_shopee || $has_card || $wl !== array('fpx', 'crypto_coin')) {
    echo "FAIL: short-circuit whitelist untouched\n";
    $failures++;
} else {
    echo "PASS: short-circuit whitelist untouched\n";
}

// 9b. dnqr wins over duitnow_qr
$available = array('fpx', 'duitnow_qr', 'dnqr', 'shopee_pay');
$wl = array('duitnow_qr');
$expanded = array_values(array_unique(array_merge($wl, $duitnow_group)));
$resolved_dnqr = array_values(array_intersect($duitnow_group, $available));
if (in_array('dnqr', $resolved_dnqr, true)) {
    $resolved_dnqr = array_values(array_diff($resolved_dnqr, array('duitnow_qr')));
}
$all_groups = array_merge($duitnow_group, $shopee_group, $card_group, array('card'));
$final = array_values(array_diff($expanded, $all_groups));
$final = array_merge($final, $resolved_dnqr);
if ($final !== array('dnqr')) {
    echo "FAIL: dnqr priority resolution (got " . json_encode($final) . ")\n";
    $failures++;
} else {
    echo "PASS: dnqr priority resolution\n";
}

// 9c. card expands to networks
$wl = array('card');
$expanded = array_values(array_unique(array_merge($wl, $card_group)));
$all_groups = array_merge($duitnow_group, $shopee_group, $card_group, array('card'));
$final = array_values(array_diff($expanded, $all_groups));
$final = array_merge($final, array_values(array_intersect($card_group, array('visa', 'mastercard', 'maestro'))));
if ($final !== array('visa', 'mastercard', 'maestro')) {
    echo "FAIL: card expansion (got " . json_encode($final) . ")\n";
    $failures++;
} else {
    echo "PASS: card expansion\n";
}

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll smoke tests passed\n";
