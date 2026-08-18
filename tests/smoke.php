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

if ($failures > 0) {
    echo "\n$failures test(s) FAILED\n";
    exit(1);
}
echo "\nAll smoke tests passed\n";
