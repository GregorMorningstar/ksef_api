<?php

// Full XAdES authentication test against KSeF TE
$baseUrl = 'https://api-test.ksef.mf.gov.pl/v2';
$nip = '6842465760';
$certPath = __DIR__ . '/storage/app/ksef/OnLine_KB.crt';
$keyPath = __DIR__ . '/storage/app/ksef/OnLine_KB.key';
$keyPassword = 'Kobospzoo_202603!';

echo "=== Step 1: Get Challenge ===\n";
$ch = curl_init("$baseUrl/auth/challenge");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP: $code\n";
echo "Response: $resp\n";
$challengeData = json_decode($resp, true);
if (!$challengeData) {
    die("Failed to get challenge\n");
}
$challenge = $challengeData['challenge'];
echo "Challenge: $challenge\n\n";

echo "=== Step 2: Build AuthTokenRequest XML ===\n";
$xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
$xml .= '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">' . "\n";
$xml .= '  <Challenge>' . htmlspecialchars($challenge, ENT_XML1, 'UTF-8') . '</Challenge>' . "\n";
$xml .= '  <ContextIdentifier>' . "\n";
$xml .= '    <Nip>' . htmlspecialchars($nip, ENT_XML1, 'UTF-8') . '</Nip>' . "\n";
$xml .= '  </ContextIdentifier>' . "\n";
$xml .= '  <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>' . "\n";
$xml .= '</AuthTokenRequest>';
echo "XML:\n$xml\n\n";

echo "=== Step 3: Sign with XAdES ===\n";

// Load cert & key
$certPem = file_get_contents($certPath);
$keyPem = file_get_contents($keyPath);

$privateKey = openssl_pkey_get_private($keyPem, $keyPassword);
if ($privateKey === false) {
    die("Failed to load private key: " . openssl_error_string() . "\n");
}
$keyDetails = openssl_pkey_get_details($privateKey);
$isEc = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);
echo "Key type: " . ($isEc ? 'ECDSA' : 'RSA') . "\n";

// Parse cert info
$certDer = pemToDer($certPem);
$certDigest = base64_encode(hash('sha256', $certDer, true));
$certInfo = openssl_x509_parse($certPem);
$issuerDn = buildIssuerDnString($certInfo['issuer'] ?? []);
$serialNumber = $certInfo['serialNumber'] ?? '';
$certBase64 = base64_encode($certDer);
$signingTime = gmdate('Y-m-d\TH:i:s\Z');

$sigId = 'Signature-' . bin2hex(random_bytes(4));
$signedPropsId = 'SignedProperties-' . bin2hex(random_bytes(4));
$keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(4));
$sigValueId = 'SignatureValue-' . bin2hex(random_bytes(4));
$refId = 'Reference-' . bin2hex(random_bytes(4));

$dsNs = 'http://www.w3.org/2000/09/xmldsig#';
$xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';

$signatureMethodUri = $isEc
    ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
    : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

// Body digest (C14N of document without Signature - use enveloped-signature transform)
// We compute this AFTER building full doc, from a clone with Signature removed

// Step A: Build the FULL document with Signature (placeholder digests)
// We need to compute SignedProperties digest in context of full document
$placeholderDigest = base64_encode(str_repeat("\x00", 32)); // 44 chars base64

$fullXml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
    . '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">' . "\n"
    . '  <Challenge>' . htmlspecialchars($challenge, ENT_XML1, 'UTF-8') . '</Challenge>' . "\n"
    . '  <ContextIdentifier>' . "\n"
    . '    <Nip>' . htmlspecialchars($nip, ENT_XML1, 'UTF-8') . '</Nip>' . "\n"
    . '  </ContextIdentifier>' . "\n"
    . '  <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>' . "\n"
    . '  <ds:Signature xmlns:ds="' . $dsNs . '" Id="' . $sigId . '">'
    . '<ds:SignedInfo>'
    . '<ds:CanonicalizationMethod Algorithm="http://www.w3.org/TR/2001/REC-xml-c14n-20010315"/>'
    . '<ds:SignatureMethod Algorithm="' . $signatureMethodUri . '"/>'
    . '<ds:Reference Id="' . $refId . '" URI="">'
    . '<ds:Transforms>'
    . '<ds:Transform Algorithm="http://www.w3.org/2000/09/xmldsig#enveloped-signature"/>'
    . '</ds:Transforms>'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $placeholderDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '<ds:Reference Type="http://uri.etsi.org/01903#SignedProperties" URI="#' . $signedPropsId . '">'
    . '<ds:DigestMethod Algorithm="http://www.w3.org/2001/04/xmlenc#sha256"/>'
    . '<ds:DigestValue>' . $placeholderDigest . '</ds:DigestValue>'
    . '</ds:Reference>'
    . '</ds:SignedInfo>'
    . '<ds:SignatureValue Id="' . $sigValueId . '">' . $placeholderDigest . '</ds:SignatureValue>'
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
    . '</ds:Signature>' . "\n"
    . '</AuthTokenRequest>';

// Step B: Parse full document and extract SignedProperties for C14N digest
$fullDoc = new DOMDocument('1.0', 'utf-8');
$fullDoc->loadXML($fullXml);

$xpath = new DOMXPath($fullDoc);
$xpath->registerNamespace('xades', $xadesNs);
$signedPropsNode = $xpath->query("//xades:SignedProperties[@Id='$signedPropsId']")->item(0);
if (!$signedPropsNode) {
    die("SignedProperties node not found!\n");
}
$spC14n = $signedPropsNode->C14N(false, false);
echo "SignedProperties C14N (first 200 chars):\n" . substr($spC14n, 0, 200) . "\n";
$spDigest = base64_encode(hash('sha256', $spC14n, true));
echo "SignedProperties digest: $spDigest\n";

// Step B2: Compute body digest - clone doc, remove Signature, C14N entire document
$cloneDoc = $fullDoc->cloneNode(true);
$cloneXpath = new DOMXPath($cloneDoc);
$cloneXpath->registerNamespace('ds', $dsNs);
$sigNode = $cloneXpath->query("//ds:Signature")->item(0);
if ($sigNode) {
    $sigNode->parentNode->removeChild($sigNode);
}
$bodyC14n = $cloneDoc->C14N(false, false);
$bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));
echo "Body C14N (first 300 chars):\n" . substr($bodyC14n, 0, 300) . "\n";
echo "Body digest: $bodyDigest\n";

// Step C: Update both placeholder digests with the real ones
$xpath->registerNamespace('ds', $dsNs);

// Update body digest (Reference URI="")
$bodyDigestNodes = $xpath->query("//ds:Reference[@URI='']/ds:DigestValue");
if ($bodyDigestNodes->length > 0) {
    $bodyDigestNodes->item(0)->nodeValue = $bodyDigest;
}

// Update SignedProperties digest
$spDigestNodes = $xpath->query("//ds:Reference[@URI='#$signedPropsId']/ds:DigestValue");
if ($spDigestNodes->length > 0) {
    $spDigestNodes->item(0)->nodeValue = $spDigest;
}

// Step D: Canonicalize SignedInfo and sign
$signedInfoNode = $xpath->query("//ds:SignedInfo")->item(0);
$siC14n = $signedInfoNode->C14N(false, false);
echo "SignedInfo C14N (first 300 chars):\n" . substr($siC14n, 0, 300) . "\n";

$signatureRaw = '';
if (!openssl_sign($siC14n, $signatureRaw, $privateKey, OPENSSL_ALGO_SHA256)) {
    die("openssl_sign failed: " . openssl_error_string() . "\n");
}

// For ECDSA: convert DER signature to raw R||S
if ($isEc) {
    $signatureRaw = ecDerToRawRS($signatureRaw, $keyDetails);
}

$signatureValue = base64_encode($signatureRaw);
echo "Signature generated OK (length: " . strlen($signatureRaw) . " bytes)\n";

// Step E: Update SignatureValue in document
$sigValueNodes = $xpath->query("//ds:SignatureValue[@Id='$sigValueId']");
if ($sigValueNodes->length > 0) {
    $sigValueNodes->item(0)->nodeValue = $signatureValue;
}

$signedXml = $fullDoc->saveXML();
echo "Signed XML length: " . strlen($signedXml) . " bytes\n\n";

echo "=== Step 4: Submit to auth/xades-signature ===\n";
$ch = curl_init("$baseUrl/auth/xades-signature?verifyCertificateChain=false");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/xml',
    'Accept: application/json',
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, $signedXml);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP: $code\n";
echo "Response: $resp\n\n";

$authData = json_decode($resp, true);
if ($code === 202 && $authData) {
    echo "SUCCESS! Auth initiated:\n";
    echo "Reference Number: " . $authData['referenceNumber'] . "\n";
    echo "Auth Token: " . $authData['authenticationToken']['token'] . "\n";

    echo "\n=== Step 5: Poll auth status ===\n";
    $refNum = $authData['referenceNumber'];
    $authToken = $authData['authenticationToken']['token'];

    for ($i = 0; $i < 10; $i++) {
        sleep(2);
        $ch = curl_init("$baseUrl/auth/$refNum");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            "Authorization: Bearer $authToken",
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $statusData = json_decode($resp, true);
        $statusCode = $statusData['status']['code'] ?? 'unknown';
        echo "Poll $i: HTTP $code, status code: $statusCode\n";

        if ($statusCode == 200) {
            echo "Auth ready!\n\n";

            echo "=== Step 6: Redeem tokens ===\n";
            $ch = curl_init("$baseUrl/auth/token/redeem");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
                "Authorization: Bearer $authToken",
            ]);
            curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            echo "HTTP: $code\n";
            echo "Response: $resp\n";
            break;
        }

        if ($statusCode >= 400) {
            echo "Auth FAILED: " . ($statusData['status']['description'] ?? 'unknown') . "\n";
            echo "Full response: $resp\n";
            break;
        }
    }
} else {
    echo "Auth initiation FAILED.\n";
    echo "Trying to parse error:\n";
    if ($authData && isset($authData['exception'])) {
        foreach ($authData['exception']['exceptionDetailList'] ?? [] as $detail) {
            echo "  Code: " . ($detail['exceptionCode'] ?? '?') . "\n";
            echo "  Desc: " . ($detail['exceptionDescription'] ?? '?') . "\n";
            echo "  Details: " . implode(', ', $detail['details'] ?? []) . "\n";
        }
    }
}

// Helper functions
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

function ecDerToRawRS(string $der, array $keyDetails): string
{
    $curveBits = $keyDetails['bits'] ?? 256;
    $fieldLen = (int) ceil($curveBits / 8);

    $offset = 0;
    if (ord($der[$offset]) !== 0x30) {
        throw new RuntimeException('Invalid ECDSA DER signature');
    }
    $offset++;
    $len = ord($der[$offset]);
    $offset++;
    if ($len & 0x80) {
        $offset += ($len & 0x7f);
    }

    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Expected INTEGER tag for R');
    }
    $offset++;
    $rLen = ord($der[$offset]);
    $offset++;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    if (ord($der[$offset]) !== 0x02) {
        throw new RuntimeException('Expected INTEGER tag for S');
    }
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
