<?php
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$pass1 = 'Kobospzoo_202603!';
$pass2 = 'AsoFoodspzoo_202603!';
$keyData = file_get_contents($keyPath);

$k1 = openssl_pkey_get_private($keyData, $pass1);
$k2 = openssl_pkey_get_private($keyData, $pass2);
echo "Password 'Kobospzoo_202603!': " . ($k1 ? 'OK' : 'FAIL') . PHP_EOL;
echo "Password 'AsoFoodspzoo_202603!': " . ($k2 ? 'OK' : 'FAIL') . PHP_EOL;

$certPem = file_get_contents(__DIR__ . '/storage/app/ksef/OnLine_KB.crt');
$ci = openssl_x509_parse($certPem);
echo "Cert CN: " . $ci['subject']['CN'] . PHP_EOL;
echo "Key file modified: " . date('Y-m-d H:i:s', filemtime($keyPath)) . PHP_EOL;
echo "Cert file modified: " . date('Y-m-d H:i:s', filemtime(__DIR__ . '/storage/app/ksef/OnLine_KB.crt')) . PHP_EOL;

// Check OffLine too
$offKey = file_get_contents(__DIR__ . '/storage/app/ksef/OffLine_KB.key');
$ok1 = openssl_pkey_get_private($offKey, $pass1);
$ok2 = openssl_pkey_get_private($offKey, $pass2);
echo PHP_EOL . "=== OffLine_KB.key ===" . PHP_EOL;
echo "Password 'Kobospzoo_202603!': " . ($ok1 ? 'OK' : 'FAIL') . PHP_EOL;
echo "Password 'AsoFoodspzoo_202603!': " . ($ok2 ? 'OK' : 'FAIL') . PHP_EOL;
