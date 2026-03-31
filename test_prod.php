<?php
// Test production KSeF API
$url = 'https://api.ksef.mf.gov.pl/v2';
$nip = '6842465760';

echo "=== KSeF Production API Test ===\n";
echo "URL: $url\n";
echo "NIP: $nip\n\n";

// 1. Challenge
echo "1. Challenge...\n";
$caBundle = __DIR__ . '/storage/app/ksef/cacert.pem';
$ch = curl_init("$url/auth/challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['nip' => $nip]));
curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "   HTTP: $httpCode\n";
if ($error) echo "   cURL error: $error\n";
echo "   Response: $response\n\n";

if ($httpCode !== 200) {
    echo "Challenge failed. Exiting.\n";
    exit(1);
}

$challengeData = json_decode($response, true);
$challenge = $challengeData['challenge'] ?? null;
$timestamp = $challengeData['timestamp'] ?? null;

echo "   Challenge: $challenge\n";
echo "   Timestamp: $timestamp\n\n";

// 2. Load certificate and sign
echo "2. Loading certificate...\n";
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$keyPass = 'Kobospzoo_202603!';

if (!file_exists($certPath)) {
    echo "   ERROR: Certificate not found at $certPath\n";
    exit(1);
}
if (!file_exists($keyPath)) {
    echo "   ERROR: Key not found at $keyPath\n";
    exit(1);
}

$certPem = file_get_contents($certPath);
$keyPem = file_get_contents($keyPath);

$cert = openssl_x509_read($certPem);
if (!$cert) {
    echo "   ERROR: Cannot read certificate\n";
    exit(1);
}

$certInfo = openssl_x509_parse($cert);
echo "   Subject: " . $certInfo['subject']['CN'] . "\n";
echo "   Issuer: " . $certInfo['issuer']['CN'] . "\n";
echo "   Valid to: " . date('Y-m-d', $certInfo['validTo_time_t']) . "\n";

$pkey = openssl_pkey_get_private($keyPem, $keyPass);
if (!$pkey) {
    echo "   ERROR: Cannot load private key (wrong password?)\n";
    echo "   OpenSSL: " . openssl_error_string() . "\n";
    exit(1);
}

$keyDetails = openssl_pkey_get_details($pkey);
echo "   Key type: " . ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'ECDSA' : 'RSA') . "\n\n";

// 3. Build and sign XAdES XML
echo "3. Building XAdES signed XML...\n";

$certDer = '';
openssl_x509_export($cert, $certPemExport);
$certDer = base64_decode(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $certPemExport));
$certDigest = base64_encode(hash('sha256', $certDer, true));
$certB64 = base64_encode($certDer);

$issuerName = '';
foreach (['CN', 'O', 'C'] as $field) {
    if (isset($certInfo['issuer'][$field])) {
        $issuerName .= ($issuerName ? ',' : '') . "$field=" . $certInfo['issuer'][$field];
    }
}
$serialNumber = $certInfo['serialNumber'];
$signingTime = gmdate('Y-m-d\TH:i:s\Z');

// Body XML (what we sign)
$bodyXml = '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">'
    . '<Challenge>' . htmlspecialchars($challenge) . '</Challenge>'
    . '<ContextIdentifier><Type>Nip</Type><Value>' . htmlspecialchars($nip) . '</Value></ContextIdentifier>'
    . '</AuthTokenRequest>';

// Canonicalized SignedProperties
$signedPropsContent = '<xades:SignedProperties xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Id="SignedProperties">'
    . '<xades:SignedSignatureProperties>'
    . '<xades:SigningTime>' . $signingTime . '</xades:SigningTime>'
    . '<xades:SigningCertificate>'
    . '<xades:Cert>'
    . '<xades:CertDigest>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $certDigest . '</ds:DigestValue>'
    . '</xades:CertDigest>'
    . '<xades:IssuerSerial>'
    . '<ds:X509IssuerName>' . htmlspecialchars($issuerName) . '</ds:X509IssuerName>'
    . '<ds:X509SerialNumber>' . $serialNumber . '</ds:X509SerialNumber>'
    . '</xades:IssuerSerial>'
    . '</xades:Cert>'
    . '</xades:SigningCertificate>'
    . '</xades:SignedSignatureProperties>'
    . '</xades:SignedProperties>';

$bodyDigest = base64_encode(hash('sha256', $bodyXml, true));
$propsDigest = base64_encode(hash('sha256', $signedPropsContent, true));

// SignedInfo (what gets actually signed)
$signedInfo = '<ds:SignedInfo xmlns:ds="http://www.w3.org/2000/09/xmldsig#" xmlns:xades="http://uri.etsi.org/01903/v1.3.2#">'
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
    . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
    . '<ds:Reference URI="">'
    . '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></ds:Transforms>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $bodyDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference URI="#SignedProperties" Type="http://uri.etsi.org/01903#SignedProperties">'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $propsDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>';

// Sign
$signedInfoHash = hash('sha256', $signedInfo, true);
$derSignature = '';
openssl_sign($signedInfo, $derSignature, $pkey, OPENSSL_ALGO_SHA256);

// Convert DER to R||S for XML-DSig ECDSA
$offset = 3;
$rLen = ord($derSignature[$offset]);
$offset++;
$r = substr($derSignature, $offset, $rLen);
$offset += $rLen + 1;
$sLen = ord($derSignature[$offset]);
$offset++;
$s = substr($derSignature, $offset, $sLen);

// Normalize to 32 bytes each
$r = ltrim($r, "\x00");
$s = ltrim($s, "\x00");
$r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
$s = str_pad($s, 32, "\x00", STR_PAD_LEFT);

$signatureB64 = base64_encode($r . $s);

// Full signed XML
$fullXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">'
    . '<Challenge>' . htmlspecialchars($challenge) . '</Challenge>'
    . '<ContextIdentifier><Type>Nip</Type><Value>' . htmlspecialchars($nip) . '</Value></ContextIdentifier>'
    . '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="Signature">'
    . $signedInfo
    . '<ds:SignatureValue>' . $signatureB64 . '</ds:SignatureValue>'
    . '<ds:KeyInfo>'
    . '<ds:X509Data><ds:X509Certificate>' . $certB64 . '</ds:X509Certificate></ds:X509Data>'
    . '</ds:KeyInfo>'
    . '<ds:Object>'
    . '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#Signature">'
    . $signedPropsContent
    . '</xades:QualifyingProperties>'
    . '</ds:Object>'
    . '</ds:Signature>'
    . '</AuthTokenRequest>';

echo "   XML built (" . strlen($fullXml) . " bytes)\n\n";

// 4. Send to auth endpoint
echo "4. Sending to /auth/xades-signature...\n";
$ch = curl_init("$url/auth/xades-signature");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $fullXml);
curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

echo "   HTTP: $httpCode\n";
if ($error) echo "   cURL error: $error\n";
echo "   Response: $response\n\n";

if ($httpCode === 202) {
    $authData = json_decode($response, true);
    $ref = $authData['referenceNumber'] ?? 'unknown';
    echo "   Reference: $ref\n\n";
    
    // 5. Poll for status
    echo "5. Polling auth status...\n";
    for ($i = 0; $i < 10; $i++) {
        sleep(2);
        $ch = curl_init("$url/auth/status/$ref");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        $statusResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "   Attempt " . ($i + 1) . ": HTTP $statusCode\n";
        $statusData = json_decode($statusResponse, true);
        
        if ($statusCode === 200) {
            echo "   Status: " . json_encode($statusData, JSON_PRETTY_PRINT) . "\n";
            
            if (isset($statusData['processingCode']) && $statusData['processingCode'] === 200) {
                echo "\n   === AUTH SUCCESS ===\n";
                echo "   Session Token available!\n";
                break;
            } elseif (isset($statusData['processingCode']) && $statusData['processingCode'] >= 400) {
                echo "\n   === AUTH FAILED ===\n";
                echo "   Code: " . $statusData['processingCode'] . "\n";
                echo "   Description: " . ($statusData['processingDescription'] ?? 'unknown') . "\n";
                break;
            }
        } else {
            echo "   Response: $statusResponse\n";
        }
    }
} else {
    echo "   Auth request failed!\n";
}

echo "\nDone.\n";
