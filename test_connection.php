<?php
require __DIR__ . '/vendor/autoload.php';

$client = new GuzzleHttp\Client([
    'base_uri' => 'https://api-test.ksef.mf.gov.pl/v2/',
    'verify' => false,
    'timeout' => 15,
]);

echo "=== Step 1: Challenge ===" . PHP_EOL;
try {
    $resp = $client->post('auth/challenge');
    $body = $resp->getBody()->getContents();
    echo "Status: " . $resp->getStatusCode() . PHP_EOL;
    $challenge = json_decode($body, true);
    echo "Challenge: " . $challenge['challenge'] . PHP_EOL;
    echo "TimestampMs: " . $challenge['timestampMs'] . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
    if (method_exists($e, 'getResponse') && $e->getResponse()) {
        echo "Response: " . $e->getResponse()->getBody()->getContents() . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "=== Step 2: Load certificate ===" . PHP_EOL;
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$keyPassword = 'Kobospzoo_202603!';

if (!file_exists($certPath)) { echo "CERT NOT FOUND: $certPath" . PHP_EOL; exit(1); }
if (!file_exists($keyPath)) { echo "KEY NOT FOUND: $keyPath" . PHP_EOL; exit(1); }

$certPem = file_get_contents($certPath);
$keyPem = file_get_contents($keyPath);

$privateKey = openssl_pkey_get_private($keyPem, $keyPassword);
if ($privateKey === false) {
    echo "FAILED TO LOAD KEY: " . openssl_error_string() . PHP_EOL;
    exit(1);
}

$keyDetails = openssl_pkey_get_details($privateKey);
echo "Key type: " . ($keyDetails['type'] === OPENSSL_KEYTYPE_EC ? 'EC' : 'RSA') . PHP_EOL;
echo "Key bits: " . $keyDetails['bits'] . PHP_EOL;

$certInfo = openssl_x509_parse($certPem);
echo "Cert subject: " . ($certInfo['subject']['CN'] ?? 'N/A') . PHP_EOL;
echo "Cert serial: " . $certInfo['serialNumber'] . PHP_EOL;
echo "Cert valid until: " . date('Y-m-d H:i:s', $certInfo['validTo_time_t']) . PHP_EOL;

echo PHP_EOL . "=== Step 3: Build & Sign XAdES ===" . PHP_EOL;
$nip = '6842465760';
$challengeValue = $challenge['challenge'];

// Build AuthTokenRequest XML
$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
    . '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">' . "\n"
    . '  <Challenge>' . htmlspecialchars($challengeValue, ENT_XML1, 'UTF-8') . '</Challenge>' . "\n"
    . '  <ContextIdentifier>' . "\n"
    . '    <Nip>' . htmlspecialchars($nip, ENT_XML1, 'UTF-8') . '</Nip>' . "\n"
    . '  </ContextIdentifier>' . "\n"
    . '  <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>' . "\n"
    . '</AuthTokenRequest>';

$isEc = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);
$dsNs = 'http://www.w3.org/2000/09/xmldsig#';
$xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';

$sigId = 'Signature-' . bin2hex(random_bytes(4));
$signedPropsId = 'SignedProperties-' . bin2hex(random_bytes(4));
$keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(4));
$sigValueId = 'SignatureValue-' . bin2hex(random_bytes(4));
$refId = 'Reference-' . bin2hex(random_bytes(4));

// Cert info
$certPemClean = str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n", ' '], '', $certPem);
$certDer = base64_decode($certPemClean);
$certDigest = base64_encode(hash('sha256', $certDer, true));
$certBase64 = base64_encode($certDer);

// Issuer DN
$issuerParts = [];
foreach ($certInfo['issuer'] as $key => $value) {
    if (is_array($value)) {
        foreach ($value as $v) $issuerParts[] = "$key=$v";
    } else {
        $issuerParts[] = "$key=$value";
    }
}
$issuerDn = implode(', ', $issuerParts);
$serialNumber = $certInfo['serialNumber'];
$signingTime = gmdate('Y-m-d\TH:i:s\Z');

$signatureMethodUri = $isEc
    ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
    : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

$placeholder = base64_encode(str_repeat("\x00", 32));

// Build full doc with signature (placeholder digests)
$doc = new DOMDocument('1.0', 'utf-8');
$doc->loadXML($xml);

$sigXml = '<ds:Signature xmlns:ds="' . $dsNs . '" Id="' . $sigId . '">'
    . '<ds:SignedInfo>'
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
    . '<ds:SignatureMethod Algorithm="' . $signatureMethodUri . '"/>'
    . '<ds:Reference Id="' . $refId . '" URI="">'
    . '<ds:Transforms>'
    . '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
    . '</ds:Transforms>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $placeholder . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference Type="http://uri.etsi.org/01903#SignedProperties" URI="#' . $signedPropsId . '">'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $placeholder . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>'
    . '<ds:SignatureValue Id="' . $sigValueId . '">' . $placeholder . '</ds:SignatureValue>'
    . '<ds:KeyInfo Id="' . $keyInfoId . '">'
    . '<ds:X509Data>'
    . '<ds:X509Certificate>' . $certBase64 . '</ds:X509Certificate>'
    . '</ds:X509Data>'
    . '</ds:KeyInfo>'
    . '<ds:Object>'
    . '<xades:QualifyingProperties xmlns:xades="' . $xadesNs . '" Target="#' . $sigId . '">'
    . '<xades:SignedProperties Id="' . $signedPropsId . '">'
    . '<xades:SignedSignatureProperties>'
    . '<xades:SigningTime>' . $signingTime . '</xades:SigningTime>'
    . '<xades:SigningCertificate>'
    . '<xades:Cert>'
    . '<xades:CertDigest>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $certDigest . '</ds:DigestValue>'
    . '</xades:CertDigest>'
    . '<xades:IssuerSerial>'
    . '<ds:X509IssuerName>' . htmlspecialchars($issuerDn, ENT_XML1) . '</ds:X509IssuerName>'
    . '<ds:X509SerialNumber>' . $serialNumber . '</ds:X509SerialNumber>'
    . '</xades:IssuerSerial>'
    . '</xades:Cert>'
    . '</xades:SigningCertificate>'
    . '</xades:SignedSignatureProperties>'
    . '</xades:SignedProperties>'
    . '</xades:QualifyingProperties>'
    . '</ds:Object>'
    . '</ds:Signature>';

$sigFrag = new DOMDocument();
$sigFrag->loadXML($sigXml);
$importedSig = $doc->importNode($sigFrag->documentElement, true);
$doc->documentElement->appendChild($importedSig);

$xpath = new DOMXPath($doc);
$xpath->registerNamespace('ds', $dsNs);
$xpath->registerNamespace('xades', $xadesNs);

// Compute SignedProperties digest
$spNode = $xpath->query("//xades:SignedProperties[@Id='$signedPropsId']")->item(0);
$spC14n = $spNode->C14N(false, false);
$spDigest = base64_encode(hash('sha256', $spC14n, true));

// Compute body digest (doc without Signature)
$cloneDoc = $doc->cloneNode(true);
$cloneXpath = new DOMXPath($cloneDoc);
$cloneXpath->registerNamespace('ds', $dsNs);
$sigNode = $cloneXpath->query("//ds:Signature")->item(0);
$sigNode->parentNode->removeChild($sigNode);
$bodyC14n = $cloneDoc->C14N(false, false);
$bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));

// Update digests
$xpath->query("//ds:Reference[@URI='']/ds:DigestValue")->item(0)->nodeValue = $bodyDigest;
$xpath->query("//ds:Reference[@URI='#$signedPropsId']/ds:DigestValue")->item(0)->nodeValue = $spDigest;

// Sign
$siNode = $xpath->query("//ds:SignedInfo")->item(0);
$siC14n = $siNode->C14N(false, false);

$signatureRaw = '';
if (!openssl_sign($siC14n, $signatureRaw, $privateKey, OPENSSL_ALGO_SHA256)) {
    echo "openssl_sign FAILED: " . openssl_error_string() . PHP_EOL;
    exit(1);
}

// Convert EC DER to R||S
if ($isEc) {
    $curveBits = $keyDetails['bits'] ?? 256;
    $fieldLen = (int) ceil($curveBits / 8);
    
    $offset = 0;
    $der = $signatureRaw;
    if (ord($der[$offset]) !== 0x30) { echo "Invalid DER" . PHP_EOL; exit(1); }
    $offset++;
    $len = ord($der[$offset]); $offset++;
    if ($len & 0x80) $offset += ($len & 0x7f);
    
    if (ord($der[$offset]) !== 0x02) { echo "Expected INTEGER for R" . PHP_EOL; exit(1); }
    $offset++; $rLen = ord($der[$offset]); $offset++;
    $r = substr($der, $offset, $rLen); $offset += $rLen;
    
    if (ord($der[$offset]) !== 0x02) { echo "Expected INTEGER for S" . PHP_EOL; exit(1); }
    $offset++; $sLen = ord($der[$offset]); $offset++;
    $s = substr($der, $offset, $sLen);
    
    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, $fieldLen, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $fieldLen, "\x00", STR_PAD_LEFT);
    
    $signatureRaw = $r . $s;
}

$xpath->query("//ds:SignatureValue[@Id='$sigValueId']")->item(0)->nodeValue = base64_encode($signatureRaw);

$signedXml = $doc->saveXML();
echo "Signed XML length: " . strlen($signedXml) . PHP_EOL;

echo PHP_EOL . "=== Step 4: Submit to /auth/xades-signature ===" . PHP_EOL;
try {
    $resp = $client->post('auth/xades-signature', [
        'headers' => ['Content-Type' => 'application/xml'],
        'query' => ['verifyCertificateChain' => 'false'],
        'body' => $signedXml,
    ]);
    $body = $resp->getBody()->getContents();
    echo "Status: " . $resp->getStatusCode() . PHP_EOL;
    echo "Body: " . $body . PHP_EOL;
    $authResult = json_decode($body, true);
    
    if (isset($authResult['referenceNumber'])) {
        echo PHP_EOL . "=== Step 5: Poll auth status ===" . PHP_EOL;
        $refNum = $authResult['referenceNumber'];
        $authToken = $authResult['authenticationToken']['token'];
        echo "Ref: $refNum" . PHP_EOL;
        echo "AuthToken: " . substr($authToken, 0, 20) . "..." . PHP_EOL;
        
        for ($i = 0; $i < 10; $i++) {
            sleep(2);
            $statusResp = $client->get("auth/$refNum", [
                'headers' => ['Authorization' => "Bearer $authToken"],
            ]);
            $statusBody = json_decode($statusResp->getBody()->getContents(), true);
            $code = $statusBody['status']['code'] ?? 0;
            echo "Poll $i: code=$code" . PHP_EOL;
            
            if ($code === 200) {
                echo PHP_EOL . "=== Step 6: Redeem tokens ===" . PHP_EOL;
                $redeemResp = $client->post('auth/token/redeem', [
                    'headers' => ['Authorization' => "Bearer $authToken"],
                ]);
                $tokens = json_decode($redeemResp->getBody()->getContents(), true);
                echo "ACCESS TOKEN: " . substr($tokens['accessToken']['token'] ?? 'N/A', 0, 30) . "..." . PHP_EOL;
                echo "REFRESH TOKEN: " . substr($tokens['refreshToken']['token'] ?? 'N/A', 0, 30) . "..." . PHP_EOL;
                echo PHP_EOL . "SUCCESS! KSeF connection established." . PHP_EOL;
                break;
            }
            
            if ($code >= 400) {
                echo "AUTH FAILED: " . json_encode($statusBody) . PHP_EOL;
                break;
            }
        }
    }
} catch (GuzzleHttp\Exception\ClientException $e) {
    echo "HTTP ERROR " . $e->getResponse()->getStatusCode() . PHP_EOL;
    echo "Response: " . $e->getResponse()->getBody()->getContents() . PHP_EOL;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . PHP_EOL;
}
