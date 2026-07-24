<?php
declare(strict_types=1);

require_once __DIR__ . '/../../auth/tenant_provisioning.php';

final class MhGridPasskeyWebAuthn
{
    private string $tenantId;
    private string $challengeStore;

    public function __construct(string $tenantId)
    {
        $this->tenantId = trim($tenantId);
        if ($this->tenantId === '') {
            throw new RuntimeException('missing_tenant_id');
        }

        $tenantSafe = function_exists('mh_tenant_safe') ? mh_tenant_safe($this->tenantId) : '';
        if (!is_string($tenantSafe) || trim($tenantSafe) === '') {
            $tenantSafe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $this->tenantId) ?: 'tenant';
        }

        $this->challengeStore = '/data/tenants/' . $tenantSafe . '/settlement/passkeys/challenges/';
        if (!is_dir($this->challengeStore) && !@mkdir($this->challengeStore, 0700, true) && !is_dir($this->challengeStore)) {
            throw new RuntimeException('challenge_store_unavailable');
        }
    }

    public function startRegistration(string $userId, string $userName, string $userDisplayName): array
    {
        $challenge = random_bytes(32);
        $challengeId = bin2hex(random_bytes(16));
        $rpId = $this->getExpectedRpId();

        $options = [
            'rp' => [
                'name' => 'Meta Humans Grid',
                'id' => $rpId,
            ],
            'user' => [
                'id' => $this->base64urlEncode($userId),
                'name' => $userName,
                'displayName' => $userDisplayName,
            ],
            'challenge' => $this->base64urlEncode($challenge),
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => -7],
                ['type' => 'public-key', 'alg' => -257],
            ],
            'timeout' => 60000,
            'authenticatorSelection' => [
                'authenticatorAttachment' => 'platform',
                'userVerification' => 'required',
                'residentKey' => 'required',
                'requireResidentKey' => true,
            ],
            'attestation' => 'none',
        ];

        $this->storeChallenge($challengeId, $challenge, $userId);

        return [
            'challengeId' => $challengeId,
            'options' => $options,
        ];
    }

    public function verifyRegistration(string $challengeId, array $credential): array
    {
        $storedChallenge = $this->getStoredChallenge($challengeId);
        if ($storedChallenge === null) {
            throw new RuntimeException('invalid_or_expired_challenge');
        }

        $clientDataJson = $credential['response']['clientDataJSON'] ?? '';
        $attestationObject = $credential['response']['attestationObject'] ?? '';
        $credentialId = $credential['id'] ?? '';

        if (!is_string($clientDataJson) || trim($clientDataJson) === '') {
            throw new RuntimeException('missing_client_data_json');
        }
        if (!is_string($attestationObject) || trim($attestationObject) === '') {
            throw new RuntimeException('missing_attestation_object');
        }
        if (!is_string($credentialId) || trim($credentialId) === '') {
            throw new RuntimeException('missing_credential_id');
        }

        $clientDataRaw = $this->decodeWebauthnBinary($clientDataJson);
        $clientData = json_decode($clientDataRaw, true);
        if (!is_array($clientData)) {
            throw new RuntimeException('invalid_client_data_json');
        }

        $this->validateClientData($clientData, $clientDataRaw, $storedChallenge, 'webauthn.create');
        $parsed = $this->parseAttestationObject($attestationObject);
        $this->validateAuthenticatorData($parsed['rpIdHash'], $parsed['flags']);

        $this->clearChallenge($challengeId);

        $transports = [];
        $rawTransports = $credential['transports'] ?? [];
        if (is_array($rawTransports)) {
            foreach ($rawTransports as $transport) {
                if (!is_string($transport)) {
                    continue;
                }
                $transport = trim($transport);
                if ($transport !== '') {
                    $transports[] = $transport;
                }
            }
        }

        return [
            'challenge' => (string)($storedChallenge['challenge_b64url'] ?? ''),
            'credentialId' => $credentialId,
            'clientDataJson' => $clientDataJson,
            'attestationObject' => $attestationObject,
            'transports' => array_values(array_unique($transports)),
        ];
    }

    private function storeChallenge(string $challengeId, string $challenge, string $userId): void
    {
        $payload = [
            'challenge' => base64_encode($challenge),
            'challenge_b64url' => $this->base64urlEncode($challenge),
            'userId' => $userId,
            'expires' => time() + 300,
        ];
        file_put_contents($this->challengeStore . $challengeId . '.json', json_encode($payload, JSON_UNESCAPED_SLASHES));
    }

    private function getStoredChallenge(string $challengeId): ?array
    {
        $file = $this->challengeStore . $challengeId . '.json';
        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($file), true);
        if (!is_array($decoded)) {
            return null;
        }
        if ((int)($decoded['expires'] ?? 0) < time()) {
            @unlink($file);
            return null;
        }

        return $decoded;
    }

    private function clearChallenge(string $challengeId): void
    {
        $file = $this->challengeStore . $challengeId . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $remainder = strlen($data) % 4;
        if ($remainder !== 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($data, true);
        if (!is_string($decoded)) {
            throw new RuntimeException('invalid_base64url');
        }
        return $decoded;
    }

    private function decodeWebauthnBinary(string $value): string
    {
        $value = trim($value);
        $decoded = base64_decode($value, true);
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
        return $this->base64urlDecode($value);
    }

    private function validateClientData(array $clientData, string $clientDataRaw, array $storedChallenge, string $expectedType): void
    {
        if ($clientDataRaw === '') {
            throw new RuntimeException('invalid_client_data');
        }

        $type = $clientData['type'] ?? '';
        if (!is_string($type) || $type !== $expectedType) {
            throw new RuntimeException('invalid_client_data_type');
        }

        $challenge = $clientData['challenge'] ?? '';
        if (!is_string($challenge) || $challenge === '') {
            throw new RuntimeException('missing_challenge');
        }

        $clientChallenge = $this->base64urlDecode($challenge);
        $stored = base64_decode((string)($storedChallenge['challenge'] ?? ''), true);
        if (!is_string($stored) || $stored === '' || !hash_equals($stored, $clientChallenge)) {
            throw new RuntimeException('challenge_mismatch');
        }

        $origin = $clientData['origin'] ?? '';
        if (!is_string($origin) || $origin === '') {
            throw new RuntimeException('missing_origin');
        }

        $originHost = parse_url($origin, PHP_URL_HOST);
        $originScheme = parse_url($origin, PHP_URL_SCHEME);
        if (!is_string($originHost) || $originHost === '' || $originScheme !== 'https') {
            throw new RuntimeException('invalid_origin');
        }

        $rpId = $this->getExpectedRpId();
        if ($originHost !== $rpId && !$this->endsWith($originHost, '.' . $rpId)) {
            throw new RuntimeException('origin_mismatch');
        }
    }

    private function validateAuthenticatorData(string $rpIdHash, int $flags): void
    {
        $expected = hash('sha256', $this->getExpectedRpId(), true);
        if (!hash_equals($expected, $rpIdHash)) {
            throw new RuntimeException('rp_id_hash_mismatch');
        }
        if (($flags & 0x01) !== 0x01) {
            throw new RuntimeException('user_not_present');
        }
        if (($flags & 0x04) !== 0x04) {
            throw new RuntimeException('user_not_verified');
        }
    }

    private function getExpectedRpId(): string
    {
        $host = '';
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (is_string($origin) && trim($origin) !== '') {
            $parsed = parse_url($origin);
            if (is_array($parsed) && isset($parsed['host']) && is_string($parsed['host']) && trim($parsed['host']) !== '') {
                $host = trim((string)$parsed['host']);
            }
        }

        $forwardedHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
        if ($host === '' && is_string($forwardedHost) && trim($forwardedHost) !== '') {
            $parts = explode(',', $forwardedHost);
            $host = trim((string)($parts[0] ?? ''));
        }

        if ($host === '') {
            $host = (string)($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'metahumans.one'));
        }

        $host = strtolower(trim($host));
        $host = preg_replace('/:\d+$/', '', $host) ?: '';
        $host = rtrim($host, '.');
        if ($host === '' || $host === 'localhost' || preg_match('/^\d{1,3}(?:\.\d{1,3}){3}$/', $host)) {
            $host = 'metahumans.one';
        }
        if ($host === 'www.metahumans.one' || $this->endsWith($host, '.metahumans.one')) {
            return 'metahumans.one';
        }
        return $host;
    }

    private function parseAttestationObject(string $attestationObject): array
    {
        $attestationRaw = $this->decodeWebauthnBinary($attestationObject);
        $offset = 0;
        $decoded = $this->cborDecode($attestationRaw, $offset);
        if (!is_array($decoded) || !isset($decoded['authData']) || !is_string($decoded['authData'])) {
            throw new RuntimeException('invalid_attestation_object');
        }
        return $this->parseAuthenticatorData($decoded['authData'], true);
    }

    private function parseAuthenticatorData(string $authData, bool $expectAttested): array
    {
        if (strlen($authData) < 37) {
            throw new RuntimeException('invalid_authenticator_data');
        }

        $result = [
            'rpIdHash' => substr($authData, 0, 32),
            'flags' => ord($authData[32]),
            'signCount' => unpack('N', substr($authData, 33, 4))[1],
        ];

        if ($expectAttested) {
            if (($result['flags'] & 0x40) !== 0x40) {
                throw new RuntimeException('missing_attested_credential_data');
            }

            $offset = 37 + 16;
            if (strlen($authData) < $offset + 2) {
                throw new RuntimeException('invalid_attested_credential_data');
            }

            $credentialIdLength = unpack('n', substr($authData, $offset, 2))[1];
            $offset += 2;
            if (strlen($authData) < $offset + $credentialIdLength) {
                throw new RuntimeException('invalid_credential_id');
            }

            $offset += $credentialIdLength;
            $coseOffset = $offset;
            $decodedOffset = $offset;
            $this->cborDecode($authData, $decodedOffset);
            if ($decodedOffset <= $coseOffset) {
                throw new RuntimeException('invalid_credential_public_key');
            }
        }

        return $result;
    }

    private function cborDecode(string $data, int &$offset)
    {
        if ($offset >= strlen($data)) {
            throw new RuntimeException('cbor_decode_overflow');
        }

        $initial = ord($data[$offset++]);
        $major = $initial >> 5;
        $additionalInfo = $initial & 0x1f;
        $length = $this->cborReadLength($data, $offset, $additionalInfo);

        if ($major === 0) {
            return $length;
        }
        if ($major === 1) {
            return -1 - $length;
        }
        if ($major === 2 || $major === 3) {
            $value = substr($data, $offset, $length);
            $offset += $length;
            return $value;
        }
        if ($major === 4) {
            $values = [];
            for ($i = 0; $i < $length; $i++) {
                $values[] = $this->cborDecode($data, $offset);
            }
            return $values;
        }
        if ($major === 5) {
            $map = [];
            for ($i = 0; $i < $length; $i++) {
                $key = $this->cborDecode($data, $offset);
                $value = $this->cborDecode($data, $offset);
                if (is_int($key) || is_string($key)) {
                    $map[$key] = $value;
                }
            }
            return $map;
        }
        if ($major === 7) {
            if ($additionalInfo === 20) {
                return false;
            }
            if ($additionalInfo === 21) {
                return true;
            }
            if ($additionalInfo === 22) {
                return null;
            }
        }

        throw new RuntimeException('unsupported_cbor_type');
    }

    private function cborReadLength(string $data, int &$offset, int $additionalInfo): int
    {
        if ($additionalInfo < 24) {
            return $additionalInfo;
        }
        if ($additionalInfo === 24) {
            return ord($data[$offset++]);
        }
        if ($additionalInfo === 25) {
            $value = unpack('n', substr($data, $offset, 2))[1];
            $offset += 2;
            return $value;
        }
        if ($additionalInfo === 26) {
            $value = unpack('N', substr($data, $offset, 4))[1];
            $offset += 4;
            return $value;
        }

        throw new RuntimeException('unsupported_cbor_length');
    }

    private function endsWith(string $haystack, string $needle): bool
    {
        if ($needle === '') {
            return true;
        }
        $length = strlen($needle);
        if ($length > strlen($haystack)) {
            return false;
        }
        return substr($haystack, -$length) === $needle;
    }
}
