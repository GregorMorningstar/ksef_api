<?php
// Test production KSeF API - with proper XAdES signing
$url = 'https://api.ksef.mf.gov.pl/v2';
$nip = '6842465760';
$caBundle = __DIR__ . '/storage/app/ksef/cacert.pem';

echo "=== KSeF Production API Test ===\n";
echo "URL: $url\n";
echo "NIP: $nip\n\n";

// 1. Challenge
echo "1. Challenge...\n";
$ch = curl_init("$url/auth/challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['nip' => $nip]));
curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

echo "   HTTP: $httpCode\n";
if ($error) { echo "   cURL error: $error\n"; exit(1); }

$challengeData = json_decode($response, true);
$challenge = $challengeData['challenge'];
$timestamp = $challengeData['timestamp'];
echo "   Challenge: $challenge\n";
echo "   Timestamp: $timestamp\n\n";

// 2. Load certificate
echo "2. Loading certificate...\n";
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$keyPass = 'Kobospzoo_202603!';

$certPem = file_get_contents($certPath);
$keyPem = file_get_contents($keyPath);

$cert = openssl_x509_read($certPem);
$certInfo = openssl_x509_parse($cert);
echo "   Subject: " . $certInfo['subject']['CN'] . "\n";

$pkey = openssl_pkey_get_private($keyPem, $keyPass);
if (!$pkey) { echo "   ERROR: Cannot load key\n"; exit(1); }
echo "   Key loaded OK\n\n";

// Get cert data
openssl_x509_export($cert, $certPemExport);
$certDer = base64_decode(str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\n", "\r"], '', $certPemExport));
$certDigest = base64_encode(hash('sha256', $certDer, true));
$certB64 = base64_encode($certDer);

$issuerParts = [];
foreach (['CN', 'O', 'C'] as $f) {
    if (isset($certInfo['issuer'][$f])) $issuerParts[] = "$f=" . $certInfo['issuer'][$f];
}
$issuerName = implode(',', $issuerParts);
$serialNumber = $certInfo['serialNumber'];
$signingTime = gmdate('Y-m-d\TH:i:s\Z');

// 3. Build full XML with PLACEHOLDER digests, then fix via DOM canonicalization
echo "3. Building XAdES signed XML with proper C14N...\n";

$fullXml = '<?xml version="1.0" encoding="UTF-8"?>'
    . '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<Challenge>' . htmlspecialchars($challenge) . '</Challenge>'
    . '<ContextIdentifier><Nip>' . htmlspecialchars($nip) . '</Nip></ContextIdentifier>'
    . '<SubjectIdentifierType>certificateSubject</SubjectIdentifierType>'
    . '<ds:Signature xmlns:ds="http://www.w3.org/2000/09/xmldsig#" Id="Signature">'
    . '<ds:SignedInfo>'
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
    . '<ds:SignatureMethod Algorithm="http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256"/>'
    . '<ds:Reference URI="">'
    . '<ds:Transforms><ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/></ds:Transforms>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>PLACEHOLDER_BODY</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference URI="#SignedProperties" Type="http://uri.etsi.org/01903#SignedProperties">'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>PLACEHOLDER_PROPS</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>'
    . '<ds:SignatureValue>PLACEHOLDER_SIG</ds:SignatureValue>'
    . '<ds:KeyInfo>'
    . '<ds:X509Data><ds:X509Certificate>' . $certB64 . '</ds:X509Certificate></ds:X509Data>'
    . '</ds:KeyInfo>'
    . '<ds:Object>'
    . '<xades:QualifyingProperties xmlns:xades="http://uri.etsi.org/01903/v1.3.2#" Target="#Signature">'
    . '<xades:SignedProperties Id="SignedProperties">'
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
    . '</xades:SignedProperties>'
    . '</xades:QualifyingProperties>'
    . '</ds:Object>'
    . '</ds:Signature>'
    . '</AuthTokenRequest>';

// Load into DOM for proper C14N
$doc = new DOMDocument();
$doc->loadXML($fullXml);

$xpath = new DOMXPath($doc);
$xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
$xpath->registerNamespace('xades', 'http://uri.etsi.org/01903/v1.3.2#');

// A) Compute body digest: root element WITHOUT ds:Signature (enveloped-signature transform)
// Build clean body without Signature to avoid inherited namespace pollution
$bodyDoc = new DOMDocument();
$bodyXml = '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
    . '<Challenge>' . htmlspecialchars($challenge) . '</Challenge>'
    . '<ContextIdentifier><Nip>' . htmlspecialchars($nip) . '</Nip></ContextIdentifier>'
    . '<SubjectIdentifierType>certificateSubject</SubjectIdentifierType>'
    . '</AuthTokenRequest>';
$bodyDoc->loadXML($bodyXml);
$bodyC14N = $bodyDoc->documentElement->C14N(false, false);
$bodyDigest = base64_encode(hash('sha256', $bodyC14N, true));
echo "   Body C14N (" . strlen($bodyC14N) . " bytes): " . substr($bodyC14N, 0, 120) . "...\n";
echo "   Body digest: $bodyDigest\n";

// B) Compute SignedProperties digest (with inherited namespaces via C14N)
$spNodes = $xpath->query('//xades:SignedProperties');
$spNode = $spNodes->item(0);
$spC14N = $spNode->C14N(false, false); // inclusive C14N, no comments
$propsDigest = base64_encode(hash('sha256', $spC14N, true));
echo "   SignedProps C14N (" . strlen($spC14N) . " bytes): " . substr($spC14N, 0, 120) . "...\n";
echo "   Props digest: $propsDigest\n";

// C) Update digest values in the document
$digestNodes = $xpath->query('//ds:SignedInfo/ds:Reference[@URI=""]/ds:DigestValue');
$digestNodes->item(0)->textContent = $bodyDigest;

$digestNodes2 = $xpath->query('//ds:SignedInfo/ds:Reference[@URI="#SignedProperties"]/ds:DigestValue');
$digestNodes2->item(0)->textContent = $propsDigest;

// D) Canonicalize SignedInfo and sign it
$siNodes = $xpath->query('//ds:SignedInfo');
$siNode = $siNodes->item(0);
$signedInfoC14N = $siNode->C14N(false, false);
echo "   SignedInfo C14N (" . strlen($signedInfoC14N) . " bytes)\n";

// Sign with ECDSA-SHA256
openssl_sign($signedInfoC14N, $derSignature, $pkey, OPENSSL_ALGO_SHA256);

// Convert DER signature to R||S (IEEE P1363) for XML-DSig
$offset = 2;
$totalLen = ord($derSignature[1]);
if ($totalLen > 127) {
    $offset = 3; // long form
}
// Parse R
$offset++; // skip 0x02 tag for R
$rLen = ord($derSignature[$offset]);
$offset++;
$r = substr($derSignature, $offset, $rLen);
$offset += $rLen;
// Parse S
$offset++; // skip 0x02 tag for S
$sLen = ord($derSignature[$offset]);
$offset++;
$s = substr($derSignature, $offset, $sLen);

// Normalize: strip leading zero, pad to 32 bytes (P-256)
$r = ltrim($r, "\x00");
$s = ltrim($s, "\x00");
$r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
$s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
$signatureB64 = base64_encode($r . $s);

echo "   Signature: " . substr($signatureB64, 0, 40) . "...\n";

// E) Update SignatureValue
$sigValNodes = $xpath->query('//ds:SignatureValue');
$sigValNodes->item(0)->textContent = $signatureB64;

// Final XML
$finalXml = $doc->saveXML();
echo "   Final XML: " . strlen($finalXml) . " bytes\n\n";

// Debug: save XML for inspection
file_put_contents(__DIR__ . '/storage/app/ksef/debug_signed.xml', $finalXml);
echo "   Saved to storage/app/ksef/debug_signed.xml\n\n";

// 4. Send to auth endpoint
echo "4. Sending to /auth/xades-signature...\n";
$ch = curl_init("$url/auth/xades-signature");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/xml', 'Accept: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, $finalXml);
curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

echo "   HTTP: $httpCode\n";
if ($error) echo "   cURL error: $error\n";
echo "   Response: $response\n\n";

if ($httpCode === 202) {
    $authData = json_decode($response, true);
    $ref = $authData['referenceNumber'] ?? 'unknown';
    echo "   Reference: $ref\n\n";
    
    // 5. Poll for status
    echo "5. Polling auth status...\n";
    for ($i = 0; $i < 15; $i++) {
        sleep(2);
        $ch = curl_init("$url/auth/status/$ref");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        $statusResponse = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        echo "   Attempt " . ($i + 1) . ": HTTP $statusCode\n";
        $statusData = json_decode($statusResponse, true);
        
        if ($statusCode === 200) {
            $pc = $statusData['processingCode'] ?? 0;
            echo "   ProcessingCode: $pc\n";
            
            if ($pc === 200) {
                echo "\n   === AUTH SUCCESS ===\n";
                echo "   Session token: " . substr($statusData['sessionToken']['token'] ?? '?', 0, 50) . "...\n";
                
                // Redeem token
                $redeemUrl = "$url/auth/token/redeem";
                $ch2 = curl_init($redeemUrl);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Authorization: Bearer ' . $statusData['sessionToken']['token']
                ]);
                curl_setopt($ch2, CURLOPT_CAINFO, $caBundle);
                $redeemResponse = curl_exec($ch2);
                echo "   Redeem: $redeemResponse\n";
                break;
            } elseif ($pc >= 400) {
                echo "\n   === AUTH FAILED ===\n";
                echo "   Description: " . ($statusData['processingDescription'] ?? json_encode($statusData)) . "\n";
                break;
            }
        } else {
            echo "   Body: $statusResponse\n";
        }
    }
} else {
    echo "   Auth request failed!\n";
}

echo "\nDone.\n";
