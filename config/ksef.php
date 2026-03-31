<?php

return [
    'env' => env('KSEF_ENV', 'test'),

    'nip' => env('KSEF_NIP', ''),

    'token' => env('KSEF_TOKEN', ''),

    'api_url' => env('KSEF_API_URL', 'https://api-test.ksef.mf.gov.pl/v2/'),

    // Certificate-based auth (XAdES)
    'auth_method' => env('KSEF_AUTH_METHOD', 'token'), // 'token' or 'certificate'

    'cert_path' => env('KSEF_CERT_PATH', storage_path('app/ksef/OnLine_KB.crt')),
    'key_path' => env('KSEF_KEY_PATH', storage_path('app/ksef/OnLine_KB.key')),
    'key_password' => env('KSEF_KEY_PASSWORD', ''),
];
