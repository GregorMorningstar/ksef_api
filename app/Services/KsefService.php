<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class KsefService
{
    private Client $client;
    private string $apiUrl;
    private string $nip;
    private string $ksefToken;
    private string $authMethod;

    public function __construct()
    {
        $this->apiUrl = config('ksef.api_url');
        $this->nip = config('ksef.nip');
        $this->ksefToken = config('ksef.token', '');
        $this->authMethod = config('ksef.auth_method', 'token');

        $this->client = new Client([
            'base_uri' => $this->apiUrl,
            'verify' => false,
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Authenticate using configured method (token or certificate).
     */
    public function authenticate(): array
    {
        if ($this->authMethod === 'certificate') {
            return $this->authenticateWithCertificate();
        }

        return $this->authenticateWithToken();
    }

    /**
     * Full authentication flow using KSeF token.
     */
    private function authenticateWithToken(): array
    {
        $challenge = $this->getChallenge();
        $publicKey = $this->getPublicKey();

        $tokenPayload = $this->ksefToken . '|' . $challenge['timestampMs'];
        $encryptedToken = $this->encryptWithPublicKey($tokenPayload, $publicKey);

        $authResponse = $this->startTokenAuth($challenge['challenge'], $encryptedToken);

        return $this->completeAuth($authResponse);
    }

    /**
     * Full authentication flow using XAdES certificate signature.
     * Flow: challenge → build XML → sign XAdES → POST /auth/xades-signature → poll → redeem
     */
    private function authenticateWithCertificate(): array
    {
        // 1. Get challenge
        $challenge = $this->getChallenge();

        // 2. Build AuthTokenRequest XML
        $xml = $this->buildAuthTokenRequestXml($challenge['challenge']);

        // 3. Sign XML with XAdES using certificate
        $signedXml = $this->signXmlWithXades($xml);

        Log::debug('KSeF XAdES signed XML', ['xml' => $signedXml]);

        // 4. Send signed XML to /auth/xades-signature
        $authResponse = $this->submitXadesAuth($signedXml);

        // 5. Poll + redeem
        return $this->completeAuth($authResponse);
    }

    /**
     * Complete authentication: poll status + redeem tokens.
     */
    private function completeAuth(array $authResponse): array
    {
        $referenceNumber = $authResponse['referenceNumber'];
        $authToken = $authResponse['authenticationToken']['token'];

        $this->waitForAuthReady($referenceNumber, $authToken);

        $tokens = $this->redeemTokens($authToken);

        $accessToken = $tokens['accessToken']['token'];
        $refreshToken = $tokens['refreshToken']['token'];

        Cache::put('ksef_access_token', $accessToken, now()->addMinutes(40));
        Cache::put('ksef_refresh_token', $refreshToken, now()->addHours(23));

        return $tokens;
    }

    /**
     * Get cached access token or re-authenticate.
     */
    public function getAccessToken(): string
    {
        $token = Cache::get('ksef_access_token');

        if ($token) {
            return $token;
        }

        // Try refresh
        $refreshToken = Cache::get('ksef_refresh_token');
        if ($refreshToken) {
            try {
                $result = $this->refreshAccessToken($refreshToken);
                $newToken = $result['accessToken']['token'];
                Cache::put('ksef_access_token', $newToken, now()->addMinutes(40));
                return $newToken;
            } catch (\Exception $e) {
                Log::warning('KSeF token refresh failed, re-authenticating', ['error' => $e->getMessage()]);
            }
        }

        // Full re-auth
        $tokens = $this->authenticate();
        return $tokens['accessToken']['token'];
    }

    // =============================================
    // CHALLENGE & PUBLIC KEY
    // =============================================

    public function getChallenge(): array
    {
        $response = $this->client->post('auth/challenge');
        return json_decode($response->getBody()->getContents(), true);
    }

    public function getPublicKey(): string
    {
        $response = $this->client->get('security/public-key-certificates');
        $certs = json_decode($response->getBody()->getContents(), true);

        foreach ($certs as $cert) {
            if (in_array('KsefTokenEncryption', $cert['usage'])) {
                return $cert['certificate'];
            }
        }

        throw new \RuntimeException('No KsefTokenEncryption public key found');
    }

    // =============================================
    // TOKEN AUTH
    // =============================================

    private function encryptWithPublicKey(string $data, string $publicKeyBase64): string
    {
        $derBytes = base64_decode($publicKeyBase64);

        // Try loading as DER-encoded RSA public key
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($derBytes), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            // Try as certificate
            $certPem = "-----BEGIN CERTIFICATE-----\n" . chunk_split(base64_encode($derBytes), 64, "\n") . "-----END CERTIFICATE-----\n";
            $key = openssl_pkey_get_public($certPem);
        }

        if ($key === false) {
            throw new \RuntimeException('Failed to load MF public key: ' . openssl_error_string());
        }

        openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        return base64_encode($encrypted);
    }

    private function startTokenAuth(string $challenge, string $encryptedToken): array
    {
        $response = $this->client->post('auth/ksef-token', [
            'json' => [
                'challenge' => $challenge,
                'contextIdentifier' => [
                    'type' => 'Nip',
                    'value' => $this->nip,
                ],
                'encryptedToken' => $encryptedToken,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    // =============================================
    // CERTIFICATE / XAdES AUTH
    // =============================================

    private function buildAuthTokenRequestXml(string $challenge): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $xml .= '<AuthTokenRequest xmlns="http://ksef.mf.gov.pl/auth/token/2.0">' . "\n";
        $xml .= '  <Challenge>' . htmlspecialchars($challenge, ENT_XML1, 'UTF-8') . '</Challenge>' . "\n";
        $xml .= '  <ContextIdentifier>' . "\n";
        $xml .= '    <Nip>' . htmlspecialchars($this->nip, ENT_XML1, 'UTF-8') . '</Nip>' . "\n";
        $xml .= '  </ContextIdentifier>' . "\n";
        $xml .= '  <SubjectIdentifierType>certificateSubject</SubjectIdentifierType>' . "\n";
        $xml .= '</AuthTokenRequest>';

        return $xml;
    }

    /**
     * Sign XML with XAdES-BES enveloped signature.
     * Computes digests in full document context for correct namespace inheritance.
     */
    private function signXmlWithXades(string $xml): string
    {
        $certPath = config('ksef.cert_path');
        $keyPath = config('ksef.key_path');
        $keyPassword = config('ksef.key_password', '');

        if (!file_exists($certPath) || !file_exists($keyPath)) {
            throw new \RuntimeException("Certificate files not found: {$certPath} or {$keyPath}");
        }

        $certPem = file_get_contents($certPath);
        $keyPem = file_get_contents($keyPath);

        $privateKey = openssl_pkey_get_private($keyPem, $keyPassword);
        if ($privateKey === false) {
            throw new \RuntimeException('Failed to load private key: ' . openssl_error_string());
        }

        $keyDetails = openssl_pkey_get_details($privateKey);
        $isEc = ($keyDetails['type'] === OPENSSL_KEYTYPE_EC);

        // IDs
        $sigId = 'Signature-' . bin2hex(random_bytes(4));
        $signedPropsId = 'SignedProperties-' . bin2hex(random_bytes(4));
        $keyInfoId = 'KeyInfo-' . bin2hex(random_bytes(4));
        $sigValueId = 'SignatureValue-' . bin2hex(random_bytes(4));
        $refId = 'Reference-' . bin2hex(random_bytes(4));

        $dsNs = 'http://www.w3.org/2000/09/xmldsig#';
        $xadesNs = 'http://uri.etsi.org/01903/v1.3.2#';

        // Certificate info
        $certDer = $this->pemToDer($certPem);
        $certDigest = base64_encode(hash('sha256', $certDer, true));
        $certInfo = openssl_x509_parse($certPem);
        $issuerDn = $this->buildIssuerDnString($certInfo['issuer'] ?? []);
        $serialNumber = $certInfo['serialNumber'] ?? '';
        $certBase64 = base64_encode($certDer);
        $signingTime = gmdate('Y-m-d\TH:i:s\Z');

        $signatureMethodUri = $isEc
            ? 'http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256'
            : 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256';

        $placeholder = base64_encode(str_repeat("\x00", 32));

        // Step 1: Build full document with Signature (placeholder digests)
        $doc = new \DOMDocument('1.0', 'utf-8');
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

        $sigFrag = new \DOMDocument();
        $sigFrag->loadXML($sigXml);
        $importedSig = $doc->importNode($sigFrag->documentElement, true);
        $doc->documentElement->appendChild($importedSig);

        $xpath = new \DOMXPath($doc);
        $xpath->registerNamespace('ds', $dsNs);
        $xpath->registerNamespace('xades', $xadesNs);

        // Step 2: Compute SignedProperties digest (in context with inherited namespaces)
        $spNode = $xpath->query("//xades:SignedProperties[@Id='$signedPropsId']")->item(0);
        $spC14n = $spNode->C14N(false, false);
        $spDigest = base64_encode(hash('sha256', $spC14n, true));

        // Step 3: Compute body digest (clone doc, remove Signature, C14N)
        $cloneDoc = $doc->cloneNode(true);
        $cloneXpath = new \DOMXPath($cloneDoc);
        $cloneXpath->registerNamespace('ds', $dsNs);
        $sigNode = $cloneXpath->query("//ds:Signature")->item(0);
        $sigNode->parentNode->removeChild($sigNode);
        $bodyC14n = $cloneDoc->C14N(false, false);
        $bodyDigest = base64_encode(hash('sha256', $bodyC14n, true));

        // Step 4: Update digests
        $xpath->query("//ds:Reference[@URI='']/ds:DigestValue")->item(0)->nodeValue = $bodyDigest;
        $xpath->query("//ds:Reference[@URI='#$signedPropsId']/ds:DigestValue")->item(0)->nodeValue = $spDigest;

        // Step 5: Canonicalize SignedInfo and sign
        $siNode = $xpath->query("//ds:SignedInfo")->item(0);
        $siC14n = $siNode->C14N(false, false);

        $signatureRaw = '';
        if (!openssl_sign($siC14n, $signatureRaw, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('openssl_sign failed: ' . openssl_error_string());
        }

        if ($isEc) {
            $signatureRaw = $this->ecDerToRawRS($signatureRaw, $keyDetails);
        }

        // Step 6: Update SignatureValue
        $xpath->query("//ds:SignatureValue[@Id='$sigValueId']")->item(0)->nodeValue = base64_encode($signatureRaw);

        return $doc->saveXML();
    }

    /**
     * Convert ECDSA DER-encoded signature to raw R||S format for XML-DSig.
     */
    private function ecDerToRawRS(string $der, array $keyDetails): string
    {
        // Determine the byte length of R and S from curve size
        $curveBits = $keyDetails['bits'] ?? 256;
        $fieldLen = (int) ceil($curveBits / 8);

        // Parse DER SEQUENCE { INTEGER r, INTEGER s }
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            throw new \RuntimeException('Invalid ECDSA DER signature');
        }
        $offset++; // skip SEQUENCE tag

        // Skip length byte(s)
        $len = ord($der[$offset]);
        $offset++;
        if ($len & 0x80) {
            $offset += ($len & 0x7f);
        }

        // Parse R
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Expected INTEGER tag for R');
        }
        $offset++;
        $rLen = ord($der[$offset]);
        $offset++;
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        // Parse S
        if (ord($der[$offset]) !== 0x02) {
            throw new \RuntimeException('Expected INTEGER tag for S');
        }
        $offset++;
        $sLen = ord($der[$offset]);
        $offset++;
        $s = substr($der, $offset, $sLen);

        // Remove leading zero padding (DER uses signed integers)
        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");

        // Pad to field length
        $r = str_pad($r, $fieldLen, "\x00", STR_PAD_LEFT);
        $s = str_pad($s, $fieldLen, "\x00", STR_PAD_LEFT);

        return $r . $s;
    }

    private function submitXadesAuth(string $signedXml): array
    {
        $response = $this->client->post('auth/xades-signature', [
            'headers' => [
                'Content-Type' => 'application/xml',
            ],
            'query' => [
                'verifyCertificateChain' => 'false',
            ],
            'body' => $signedXml,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function pemToDer(string $pem): string
    {
        $pem = str_replace(['-----BEGIN CERTIFICATE-----', '-----END CERTIFICATE-----', "\r", "\n", ' '], '', $pem);
        return base64_decode($pem);
    }

    private function buildIssuerDnString(array $parts): string
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

    // =============================================
    // AUTH FLOW COMMON
    // =============================================

    private function waitForAuthReady(string $referenceNumber, string $authToken): void
    {
        $maxAttempts = 20;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $response = $this->client->get("auth/{$referenceNumber}", [
                'headers' => ['Authorization' => "Bearer {$authToken}"],
            ]);

            $status = json_decode($response->getBody()->getContents(), true);
            $code = $status['status']['code'] ?? 0;

            Log::debug('KSeF auth poll', ['attempt' => $attempt, 'code' => $code, 'status' => $status]);

            if ($code === 200) {
                return;
            }

            if ($code >= 400) {
                $desc = $status['status']['description'] ?? 'Nieznany błąd';
                $details = $status['status']['details'] ?? [];
                $detailsStr = is_array($details) ? implode('; ', $details) : (string) $details;
                $authMethod = $status['authenticationMethod'] ?? '';
                $methodInfo = $status['authenticationMethodInfo']['displayName'] ?? '';

                $message = "KSeF (kod $code): $desc";
                if ($detailsStr) {
                    $message .= " — $detailsStr";
                }
                if ($authMethod) {
                    $message .= " [metoda: $authMethod / $methodInfo]";
                }

                throw new \RuntimeException($message);
            }

            $attempt++;
            sleep(2);
        }

        throw new \RuntimeException('KSeF authentication timed out after ' . $maxAttempts . ' attempts');
    }

    private function redeemTokens(string $authToken): array
    {
        $response = $this->client->post('auth/token/redeem', [
            'headers' => ['Authorization' => "Bearer {$authToken}"],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    private function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->client->post('auth/token/refresh', [
            'headers' => ['Authorization' => "Bearer {$refreshToken}"],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Logout - invalidate current session.
     */
    public function logout(): void
    {
        $token = Cache::get('ksef_access_token');
        if (!$token) {
            return;
        }

        try {
            $this->client->delete('auth/sessions/current', [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);
        } catch (\Exception $e) {
            Log::warning('KSeF logout failed', ['error' => $e->getMessage()]);
        }

        Cache::forget('ksef_access_token');
        Cache::forget('ksef_refresh_token');
    }

    // =============================================
    // INVOICE OPERATIONS
    // =============================================

    /**
     * Search invoice metadata with filters.
     */
    public function searchInvoices(array $filters, int $pageOffset = 0, int $pageSize = 50, string $sortOrder = 'Desc'): array
    {
        $token = $this->getAccessToken();

        $response = $this->client->post('invoices/query/metadata', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
            'query' => [
                'pageOffset' => $pageOffset,
                'pageSize' => $pageSize,
                'sortOrder' => $sortOrder,
            ],
            'json' => $filters,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get single invoice XML by KSeF number.
     */
    public function getInvoiceByKsefNumber(string $ksefNumber): string
    {
        $token = $this->getAccessToken();

        $response = $this->client->get("invoices/ksef/{$ksefNumber}", [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Accept' => 'application/xml',
            ],
        ]);

        return $response->getBody()->getContents();
    }

    /**
     * Start async export of invoices.
     */
    public function startInvoiceExport(array $filters, string $encryptedSymmetricKey, string $iv): array
    {
        $token = $this->getAccessToken();

        $response = $this->client->post('invoices/exports', [
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'encryption' => [
                    'encryptedSymmetricKey' => $encryptedSymmetricKey,
                    'initializationVector' => $iv,
                ],
                'onlyMetadata' => false,
                'filters' => $filters,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Check export status.
     */
    public function getExportStatus(string $referenceNumber): array
    {
        $token = $this->getAccessToken();

        $response = $this->client->get("invoices/exports/{$referenceNumber}", [
            'headers' => ['Authorization' => "Bearer {$token}"],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get session list.
     */
    public function getSessions(string $sessionType = 'Online', int $pageSize = 50): array
    {
        $token = $this->getAccessToken();

        $response = $this->client->get('sessions', [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query' => [
                'sessionType' => $sessionType,
                'pageSize' => $pageSize,
            ],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get session invoices.
     */
    public function getSessionInvoices(string $referenceNumber, int $pageSize = 100): array
    {
        $token = $this->getAccessToken();

        $response = $this->client->get("sessions/{$referenceNumber}/invoices", [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query' => ['pageSize' => $pageSize],
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Check if we have a valid session.
     */
    public function isAuthenticated(): bool
    {
        return Cache::has('ksef_access_token');
    }

    /**
     * Get current KSeF configuration status.
     */
    public function getStatus(): array
    {
        $certConfigured = $this->authMethod === 'certificate'
            && file_exists(config('ksef.cert_path', ''))
            && file_exists(config('ksef.key_path', ''));

        $tokenConfigured = $this->authMethod === 'token' && !empty($this->ksefToken);

        return [
            'configured' => !empty($this->nip) && ($certConfigured || $tokenConfigured),
            'authenticated' => $this->isAuthenticated(),
            'nip' => $this->nip ? substr($this->nip, 0, 3) . '****' . substr($this->nip, -3) : null,
            'environment' => config('ksef.env'),
            'api_url' => $this->apiUrl,
            'auth_method' => $this->authMethod,
        ];
    }
}
