<?php
/**
 * Full KSeF XAdES auth test — standalone, no Laravel.
 * Tests: challenge → XML → XAdES sign → submit → poll → redeem
 */

$baseUrl = 'https://api-test.ksef.mf.gov.pl/v2';
$nip = '6842680955';
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$keyPassword = 'Kobospzoo_202603!';

echo "=== STEP 1: Get Challenge ===" . PHP_EOL;
$challengeResp = httpPost("$baseUrl/auth/challenge", json_encode([
    // No body needed — challenge is generated server-side
]), 'application/json');
echo "HTTP: {$challengeResp['http_code']}" . PHP_EOL;
echo "Body: {$challengeResp['body']}" . PHP_EOL;

if ($challengeResp['http_code'] !== 200) {
    die("Challenge failed!" . PHP_EOL);
}

$challengeData = json_decode($challengeResp['body'], true);
$challenge = $challengeData['challenge'];
echo "Challenge: $challenge" . PHP_EOL . PHP_EOL;

echo "=== STEP 2: Build AuthTokenRequest XML ===" . PHP_EOL;
$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
    . '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">' . "\n"
    . '  <Challenge>' . htmlspecialchars($challenge, ENT_XML1, 'UTF-8') . '</Challenge>' . "\n"
    . '  <ContextIdentifier>' . "\n"
    . '    <Nip>' . htmlspecialchars($nip, ENT_XML1, 'UTF-8') . '</Nip>' . "\n"
    . '  </ContextIdentifier>' . "\n"
    . '  <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>' . "\n"
    . '</AuthTokenRequest>';
echo "XML built OK" . PHP_EOL . PHP_EOL;

echo "=== STEP 3: Sign XML with XAdES ===" . PHP_EOL;
$signedXml = signXmlWithXades($xml, $certPath, $keyPath, $keyPassword);
echo "Signed XML length: " . strlen($signedXml) . " bytes" . PHP_EOL;
// Save for debugging
file_put_contents(__DIR__ . '/storage/app/ksef/debug_signed.xml', $signedXml);
echo "Saved to storage/app/ksef/debug_signed.xml" . PHP_EOL . PHP_EOL;

echo "=== STEP 4: Submit XAdES signature ===" . PHP_EOL;
$authResp = httpPost("$baseUrl/auth/xades-signature?verifyCertificateChain=false", $signedXml, 'application/xml');
echo "HTTP: {$authResp['http_code']}" . PHP_EOL;
echo "Body: {$authResp['body']}" . PHP_EOL;

if ($authResp['http_code'] !== 202) {
    echo "XAdES auth failed!" . PHP_EOL;
    die();
}

$authData = json_decode($authResp['body'], true);
$referenceNumber = $authData['referenceNumber'];
$authToken = $authData['authenticationToken']['token'];
echo "Reference: $referenceNumber" . PHP_EOL;
echo "Auth Token: " . substr($authToken, 0, 50) . "..." . PHP_EOL . PHP_EOL;

echo "=== STEP 5: Poll auth status ===" . PHP_EOL;
$maxAttempts = 15;
$ready = false;
for ($i = 0; $i < $maxAttempts; $i++) {
    sleep(2);
    $statusResp = httpGet("$baseUrl/auth/$referenceNumber", $authToken);
    echo "Poll $i - HTTP: {$statusResp['http_code']}" . PHP_EOL;
    
    $statusData = json_decode($statusResp['body'], true);
    $code = $statusData['status']['code'] ?? 0;
    $desc = $statusData['status']['description'] ?? '';
    $details = $statusData['status']['details'] ?? [];
    
    echo "  Status code: $code, description: $desc" . PHP_EOL;
    if (!empty($details)) {
        echo "  Details: " . implode('; ', $details) . PHP_EOL;
    }
    if (isset($statusData['authenticationMethod'])) {
        echo "  Auth method: {$statusData['authenticationMethod']}" . PHP_EOL;
    }
    
    if ($code === 200) {
        $ready = true;
        echo "  AUTH READY!" . PHP_EOL;
        break;
    }
    
    if ($code >= 400) {
        echo "  AUTH FAILED (code $code)!" . PHP_EOL;
        die();
    }
}

if (!$ready) {
    die("Timed out waiting for auth" . PHP_EOL);
}

echo PHP_EOL . "=== STEP 6: Redeem tokens ===" . PHP_EOL;
$redeemResp = httpPost("$baseUrl/auth/token/redeem", '', 'application/json', $authToken);
echo "HTTP: {$redeemResp['http_code']}" . PHP_EOL;
echo "Body: " . substr($redeemResp['body'], 0, 500) . PHP_EOL;

if ($redeemResp['http_code'] === 200) {
    $tokens = json_decode($redeemResp['body'], true);
    echo PHP_EOL . "=== SUCCESS ===" . PHP_EOL;
    echo "Access Token: " . substr($tokens['accessToken']['token'] ?? '', 0, 50) . "..." . PHP_EOL;
    echo "Valid Until: " . ($tokens['accessToken']['validUntil'] ?? 'N/A') . PHP_EOL;
    echo "Refresh Token: " . substr($tokens['refreshToken']['token'] ?? '', 0, 50) . "..." . PHP_EOL;
} else {
    echo "Redeem FAILED!" . PHP_EOL;
}

// =============================================
// FUNCTIONS
// =============================================

function httpPost(string $url, string $body, string $contentType, string $bearerToken = ''): array
{
    $ch = curl_init($url);
    $headers = ["Content-Type: $contentType", "Accept: application/json"];
    if ($bearerToken) {
        $headers[] = "Authorization: Bearer $bearerToken";
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $response = 'CURL ERROR: ' . curl_error($ch);
    }
    return ['http_code' => $httpCode, 'body' => $response];
}

function httpGet(string $url, string $bearerToken = ''): array
{
    $ch = curl_init($url);
    $headers = ["Accept: application/json"];
    if ($bearerToken) {
        $headers[] = "Authorization: Bearer $bearerToken";
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        $response = 'CURL ERROR: ' . curl_error($ch);
    }
    return ['http_code' => $httpCode, 'body' => $response];
}

function signXmlWithXades(string $xml, string $certPath, string $keyPath, string $keyPassword): string
{
    $certPem = file_get_contents($certPath);
    $keyPem = file_get_contents($keyPath);

    $privateKey = openssl_pkey_get_private($keyPem, $keyPassword);
    if ($privateKey === false) {
        throw new RuntimeException('Failed to load private key: ' . openssl_error_string());
    }

    $keyDetails = openssl_pkey_get_details($privateKey);
    $isEc = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);
    echo "Key type: " . ($isEc ? "ECDSA" : "RSA") . ", bits: {$keyDetails['bits']}" . PHP_EOL;

    $dsNs = 'http://www.w3.org/2000/09/xmldsig#';
    $xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';

    // IDs
    $sigId = 'Signature-' . bin2hex(random_bytes(4));
    $signedPropsId = 'SignedProperties-' . bin2hex(random_bytes(4));
    $keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(4));
    $sigValueId = 'SignatureValue-' . bin2hex(random_bytes(4));
    $refId = 'Reference-' . bin2hex(random_bytes(4));

    // Certificate info
    $certDer = pemToDer($certPem);
    $certDigest = base64_encode(hash('sha256', $certDer, true));
    $certInfo = openssl_x509_parse($certPem);
    $issuerDn = buildIssuerDnString($certInfo['issuer'] ?? []);
    $serialNumber = $certInfo['serialNumber'] ?? '';
    $certBase64 = base64_encode($certDer);
    $signingTime = gmdate('Y-m-d\TH:i:s\Z');

    $signatureMethodUri = $isEc
        ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
        : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

    $placeholder = base64_encode(str_repeat("\x00", 32));

    // Build the full document with Signature element (placeholder digests)
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

    // Compute SignedProperties digest (in-context C14N)
    $spNode = $xpath->query("//xades:SignedProperties[@Id='$signedPropsId']")->item(0);
    $spC14n = $spNode->C14N(false, false);
    $spDigest = base64_encode(hash('sha256', $spC14n, true));
    echo "SignedProperties C14N (" . strlen($spC14n) . " bytes): " . substr($spC14n, 0, 100) . "..." . PHP_EOL;
    echo "SignedProperties digest: $spDigest" . PHP_EOL;

    // Compute body digest (clone doc, remove Signature, C14N)
    $cloneDoc = $doc->cloneNode(true);
    $cloneXpath = new DOMXPath($cloneDoc);
    $cloneXpath->registerNamespace('ds', $dsNs);
    $sigNode = $cloneXpath->query("//ds:Signature")->item(0);
    $sigNode->parentNode->removeChild($sigNode);
    $bodyC14n = $cloneDoc->C14N(false, false);
    $bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));
    echo "Body C14N (" . strlen($bodyC14n) . " bytes): " . substr($bodyC14n, 0, 200) . PHP_EOL;
    echo "Body digest: $bodyDigest" . PHP_EOL;

    // Update digests
    $xpath->query("//ds:Reference[@URI='']/ds:DigestValue")->item(0)->nodeValue = $bodyDigest;
    $xpath->query("//ds:Reference[@URI='#$signedPropsId']/ds:DigestValue")->item(0)->nodeValue = $spDigest;

    // Canonicalize SignedInfo and sign
    $siNode = $xpath->query("//ds:SignedInfo")->item(0);
    $siC14n = $siNode->C14N(false, false);
    echo "SignedInfo C14N (" . strlen($siC14n) . " bytes)" . PHP_EOL;

    $signatureRaw = '';
    if (!openssl_sign($siC14n, $signatureRaw, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('openssl_sign failed: ' . openssl_error_string());
    }

    if ($isEc) {
        $signatureRaw = ecDerToRawRS($signatureRaw, $keyDetails);
        echo "ECDSA R||S signature: " . strlen($signatureRaw) . " bytes" . PHP_EOL;
    }

    // Update SignatureValue
    $xpath->query("//ds:SignatureValue[@Id='$sigValueId']")->item(0)->nodeValue = base64_encode($signatureRaw);

    return $doc->saveXML();
}

function ecDerToRawRS(string $der, array $keyDetails): string
{
    $curveBits = $keyDetails['bits'] ?? 256;
    $fieldLen = (int) ceil($curveBits / 8);

    $offset = 0;
    if (ord($der[$offset]) !== 0x30) throw new RuntimeException('Invalid ECDSA DER');
    $offset++;
    $len = ord($der[$offset]);
    $offset++;
    if ($len & 0x80) $offset += ($len & 0x7f);

    if (ord($der[$offset]) !== 0x02) throw new RuntimeException('Expected INTEGER for R');
    $offset++;
    $rLen = ord($der[$offset]);
    $offset++;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    if (ord($der[$offset]) !== 0x02) throw new RuntimeException('Expected INTEGER for S');
    $offset++;
    $sLen = ord($der[$offset]);
    $offset++;
    $s = substr($der, $offset, $sLen);

    $r = ltrim($r, "\x00");
    $s = ltrim($s, "\x00");
    $r = str_pad($r, $fieldLen, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $fieldLen, "\x00", STR_PAD_LEFT);

    return $r . $s;
}

function pemToDer(string $pem): string
{
    $pem = str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n", ' '], '', $pem);
    return base64_decode($pem);
}

function buildIssuerDnString(array $parts): string
{
    $dn = [];
    foreach ($parts as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $v) {
                $dn[] = $key . '=' . $v;
            }
        } else {
            $dn[] = $key . '=' . $value;
        }
    }
    return implode(', ', $dn);
}
