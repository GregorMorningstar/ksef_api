<?php
// Test OnLine certificate with password
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$passwords = [
    'Kobospzoo_202603!',
    'AsoFoodspzoo_202603!',
];

echo "=== OnLine_KB.crt ===" . PHP_EOL;
$cert = openssl_x509_read(file_get_contents($certPath));
if ($cert) {
    $info = openssl_x509_parse($cert);
    echo 'Subject CN: ' . ($info['subject']['CN'] ?? 'N/A') . PHP_EOL;
    echo 'Issuer CN: ' . ($info['issuer']['CN'] ?? 'N/A') . PHP_EOL;
    echo 'Serial: ' . $info['serialNumber'] . PHP_EOL;
    echo 'Valid: ' . date('Y-m-d', $info['validFrom_time_t']) . ' to ' . date('Y-m-d', $info['validTo_time_t']) . PHP_EOL;
    if (isset($info['subject']['serialNumber'])) echo 'Subject ID: ' . $info['subject']['serialNumber'] . PHP_EOL;
} else {
    echo 'CERT LOAD ERROR' . PHP_EOL;
}

echo PHP_EOL . "=== Testing passwords ===" . PHP_EOL;
$keyData = file_get_contents($keyPath);
foreach ($passwords as $pass) {
    $key = openssl_pkey_get_private($keyData, $pass);
    echo "Password '$pass': " . ($key ? 'OK' : 'FAIL') . PHP_EOL;
    if ($key) {
        $details = openssl_pkey_get_details($key);
        echo '  Key type: ' . ($details['type'] == OPENSSL_KEYTYPE_EC ? 'ECDSA' : 'RSA') . PHP_EOL;
        echo '  Key bits: ' . $details['bits'] . PHP_EOL;
    }
}

// Now test full auth flow
echo PHP_EOL . "=== Testing KSeF connection ===" . PHP_EOL;
$baseUrl = 'https://ksef-test.mf.gov.pl/api';

// 1. Challenge
$ch = curl_init("$baseUrl/online/Session/AuthorisationChallenge");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode([
        'contextIdentifier' => [
            'type' => 'onip',
            'identifier' => '6842465760'
        ]
    ]),
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "Challenge HTTP: $httpCode" . PHP_EOL;
echo "Response: $response" . PHP_EOL;
