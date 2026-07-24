<?php
// auth_classes.php
// Core authentication classes for Meta Humans

require_once __DIR__ . '/../.cue/cue.php';

// Helper to get vault path
function getVaultPath() {
    return '/home/onemeta/.vault'; // Adjust as needed
}

if (!class_exists('MetaPinBackup')) {
    class MetaPinBackup {
        private ?PDO $pdo = null;

    public function __construct() {
        $this->connectDatabase();
    }

    private function connectDatabase(): void {
        if (function_exists('cue_autoload')) {
            cue_autoload('database');
        }
        
        $bioConfig = null;
        if (function_exists('database_getConfiguration')) {
            $bioConfig = database_getConfiguration('biometrics');
        }
        
        if ($bioConfig) {
            $conn = database_getConnectionById('biometrics');
            $pdo = null;
            if ($conn instanceof PDO) {
                $pdo = $conn;
            } elseif (is_array($conn)) {
                $pdo = ($conn['pdo'] ?? $conn['connection'] ?? $conn['dbh'] ?? null);
            } elseif (is_object($conn)) {
                $pdo = ($conn->pdo ?? $conn->connection ?? $conn->dbh ?? null);
            }
            if (!$pdo instanceof PDO) {
                throw new Exception("Biometrics database connection failed.");
            }
            $this->pdo = $pdo;
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Auto-check schema on connection
            try {
                // Check for pin_hash
                $stmt = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'pin_hash'");
                if ($stmt->rowCount() === 0) {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN pin_hash VARCHAR(255) DEFAULT NULL");
                }
                
                // Check for pin_attempts
                $stmt = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'pin_attempts'");
                if ($stmt->rowCount() === 0) {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN pin_attempts INT DEFAULT 0");
                }
                
                // Check for pin_locked_until
                $stmt = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'pin_locked_until'");
                if ($stmt->rowCount() === 0) {
                    $this->pdo->exec("ALTER TABLE users ADD COLUMN pin_locked_until INT DEFAULT NULL");
                }
            } catch (Exception $e) {
                error_log("MetaPinBackup Schema Update Error: " . $e->getMessage());
            }
        } else {
            throw new Exception("Biometrics database configuration not found.");
        }
    }


    public function verifyPin(string $userId, string $pin): bool {
        if (!$this->pdo) throw new Exception("Database connection failed");

        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('User not found');
        }

        if (empty($user['pin_hash'])) {
            throw new Exception('No PIN set for user');
        }

        // Check lock
        if (!empty($user['pin_locked_until']) && $user['pin_locked_until'] > time()) {
            $remaining = $user['pin_locked_until'] - time();
            throw new Exception("Account locked. Try again in {$remaining} seconds");
        }

        $stored = is_string($user['pin_hash']) ? (string)$user['pin_hash'] : '';
        $stored = trim($stored);
        if ($stored !== '' && $stored[0] !== '$') {
            try {
                if (function_exists('cue_autoload')) {
                    cue_autoload('paths');
                    cue_autoload('security');
                }
                $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
                $security = function_exists('cue_autoload') ? cue_autoload('security') : null;
                $keyPath = ($paths && method_exists($paths, 'getEncryptionKeyPath')) ? (string)$paths->getEncryptionKeyPath() : '';
                $key = ($keyPath !== '' && is_file($keyPath)) ? trim((string)file_get_contents($keyPath)) : '';
                if ($security && $key !== '' && method_exists($security, 'decryptValue')) {
                    $plain = (string)$security->decryptValue($stored, $key);
                    if ($plain !== '' && hash_equals($plain, (string)$pin)) {
                        $hash = password_hash((string)$pin, PASSWORD_ARGON2ID);
                        $upd = $this->pdo->prepare("UPDATE users SET pin_hash = ?, pin_attempts = 0, pin_locked_until = NULL WHERE username = ?");
                        $upd->execute([$hash, $userId]);
                        try {
                            $stmt2 = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'pin'");
                            if ($stmt2 && $stmt2->rowCount() > 0) {
                                $this->pdo->prepare("UPDATE users SET pin = NULL WHERE username = ?")->execute([$userId]);
                            }
                        } catch (Throwable) {
                        }
                        return true;
                    }
                }
            } catch (Throwable) {
            }
        }

        // Verify hash using Argon2
        if (!password_verify($pin, $user['pin_hash'])) {
            // Increment attempts
            $attempts = ($user['pin_attempts'] ?? 0) + 1;
            $lockedUntil = null;
            
            if ($attempts >= 5) {
                $lockedUntil = time() + 300; // 5 minutes lockout
            }
            
            $upd = $this->pdo->prepare("UPDATE users SET pin_attempts = ?, pin_locked_until = ? WHERE username = ?");
            $upd->execute([$attempts, $lockedUntil, $userId]);
            
            throw new Exception('Invalid PIN');
        }

        // Reset attempts on success
        $upd = $this->pdo->prepare("UPDATE users SET pin_attempts = 0, pin_locked_until = NULL WHERE username = ?");
        $upd->execute([$userId]);
        
        return true;
    }
    
    public function setPinForUser(string $userId, string $pin): void {
        if (!$this->pdo) throw new Exception("Database connection failed");

        $hash = password_hash($pin, PASSWORD_ARGON2ID);
        
        // Ensure user exists (update if exists)
        // We assume user is created by register.php before this is called, 
        // but if setPinForUser is called separately, we check existence.
        
        $stmt = $this->pdo->prepare("UPDATE users SET pin_hash = ?, pin_attempts = 0, pin_locked_until = NULL WHERE username = ?");
        $stmt->execute([$hash, $userId]);
        
        if ($stmt->rowCount() === 0) {
            // Check if user exists but update didn't change anything (unlikely for new pin) or user doesn't exist
            $chk = $this->pdo->prepare("SELECT id FROM users WHERE username = ?");
            $chk->execute([$userId]);
            if (!$chk->fetch()) {
                 throw new Exception("User $userId does not exist in biometrics database.");
            }
        }
    }
    }
}

if (!class_exists('MockAuthenticationAdapter')) {
    class MockAuthenticationAdapter {
        public function getHeaders(): array {
            return [
                'HTTP_AUTH_USER' => 'dev_admin',
                'HTTP_MAIL' => 'dev_admin@metahumans.one',
                'HTTP_AUTH_GROUPS' => 'KripzMasters;Developers'
            ];
        }
    }
}

if (!class_exists('PasskeyAuth')) {
    class PasskeyAuth {
        private string $credentialStore;
        private string $challengeStore;
        private string $keyFile;
        private ?PDO $bioPdo = null;

        public function __construct() {
            $dataPath = '/home/onemeta/lldap/deployment/data';
            $vaultPath = '/home/onemeta/lldap/deployment/.kripz';
            $this->credentialStore = $dataPath . '/webauthn-keys.json';
            $this->challengeStore = $dataPath . '/challenges/';
            $this->keyFile = $vaultPath . '/encryption.key';
            $this->ensureStorageExists($dataPath, $vaultPath);
            $this->bioPdo = null;
        }

        private function getBiometricsPdo(): ?PDO {
            if ($this->bioPdo instanceof PDO) {
                return $this->bioPdo;
            }
            try {
                if (function_exists('cue_autoload')) {
                    cue_autoload('database');
                }
            } catch (Throwable) {
            }
            if (!function_exists('database_getConnectionById')) {
                return null;
            }
            try {
                $pdo = database_getConnectionById('biometrics');
            } catch (Throwable) {
                return null;
            }
            if ($pdo instanceof PDO) {
                $this->bioPdo = $pdo;
                return $this->bioPdo;
            }
            if (is_object($pdo) && property_exists($pdo, 'pdo') && $pdo->pdo instanceof PDO) {
                $this->bioPdo = $pdo->pdo;
                return $this->bioPdo;
            }
            return null;
        }

        private function ensureWebauthnSchema(PDO $pdo): void {
            $pdo->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
                credential_id_hex VARCHAR(512) NOT NULL PRIMARY KEY,
                user_id VARCHAR(255) NOT NULL,
                public_key_b64 MEDIUMTEXT NOT NULL,
                sign_count INT NOT NULL DEFAULT 0,
                device_info VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_used_at TIMESTAMP NULL DEFAULT NULL
            )");

            $expectedColumns = [
                'credential_id_hex' => "ALTER TABLE webauthn_credentials ADD COLUMN credential_id_hex VARCHAR(512) NOT NULL",
                'user_id' => "ALTER TABLE webauthn_credentials ADD COLUMN user_id VARCHAR(255) NOT NULL",
                'public_key_b64' => "ALTER TABLE webauthn_credentials ADD COLUMN public_key_b64 MEDIUMTEXT NOT NULL",
                'sign_count' => "ALTER TABLE webauthn_credentials ADD COLUMN sign_count INT NOT NULL DEFAULT 0",
                'device_info' => "ALTER TABLE webauthn_credentials ADD COLUMN device_info VARCHAR(255) DEFAULT NULL",
                'created_at' => "ALTER TABLE webauthn_credentials ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
                'last_used_at' => "ALTER TABLE webauthn_credentials ADD COLUMN last_used_at TIMESTAMP NULL DEFAULT NULL",
            ];

            foreach ($expectedColumns as $col => $ddl) {
                $stmt = $pdo->query("SHOW COLUMNS FROM webauthn_credentials LIKE " . $pdo->quote($col));
                if ($stmt && $stmt->rowCount() === 0) {
                    try {
                        $pdo->exec($ddl);
                    } catch (Throwable) {
                    }
                }
            }

            try {
                $idx = $pdo->query("SHOW INDEX FROM webauthn_credentials WHERE Key_name = 'idx_user_id'");
                if ($idx && $idx->rowCount() === 0) {
                    $pdo->exec("CREATE INDEX idx_user_id ON webauthn_credentials(user_id)");
                }
            } catch (Throwable) {
            }

            try {
                $stmt = $pdo->query("SHOW KEYS FROM webauthn_credentials WHERE Key_name = 'PRIMARY'");
                $hasPk = $stmt && $stmt->rowCount() > 0;
                if (!$hasPk) {
                    $pdo->exec("ALTER TABLE webauthn_credentials ADD PRIMARY KEY (credential_id_hex)");
                }
            } catch (Throwable) {
            }
        }

        public function generateRegistrationChallenge(string $userId, string $userName, string $userDisplayName): array {
            $challenge = random_bytes(32);
            $challengeId = bin2hex(random_bytes(16));

            $rpId = $this->getExpectedRpId();
            $publicKeyCredentialCreationOptions = [
                'rp' => [
                    'name' => 'Meta Humans',
                    'id' => $rpId
                ],
                'user' => [
                    'id' => $this->base64url_encode((string)$userId),
                    'name' => $userName,
                    'displayName' => $userDisplayName
                ],
                'challenge' => $this->base64url_encode($challenge),
                'pubKeyCredParams' => [
                    ['type' => 'public-key', 'alg' => -7],
                    ['type' => 'public-key', 'alg' => -257]
                ],
                'timeout' => 60000,
                'authenticatorSelection' => [
                    'authenticatorAttachment' => 'platform',
                    'userVerification' => 'required',
                    'residentKey' => 'preferred',
                    'requireResidentKey' => false
                ],
                'attestation' => 'none'
            ];

            $this->storeChallenge($challengeId, $challenge, (string)$userId);

            return [
                'challengeId' => $challengeId,
                'options' => $publicKeyCredentialCreationOptions
            ];
        }

        public function verifyRegistration(string $challengeId, array $credential): bool {
            $storedChallenge = $this->getStoredChallenge($challengeId);
            if (!$storedChallenge) {
                throw new Exception('Invalid or expired challenge');
            }

            $clientDataJSON = $credential['response']['clientDataJSON'] ?? null;
            if (!is_string($clientDataJSON) || $clientDataJSON === '') {
                throw new Exception('Missing clientDataJSON');
            }
            $clientDataRaw = $this->decodeWebAuthnBinary($clientDataJSON);
            $clientData = json_decode($clientDataRaw, true);
            if (!is_array($clientData)) {
                throw new Exception('Invalid clientDataJSON');
            }
            $this->validateClientData($clientData, $clientDataRaw, $storedChallenge, 'webauthn.create');

            $attestationObject = $credential['response']['attestationObject'] ?? null;
            if (!is_string($attestationObject) || $attestationObject === '') {
                throw new Exception('Missing attestationObject');
            }
            $parsed = $this->parseAttestationObject($attestationObject);
            $this->validateAuthenticatorData($parsed['rpIdHash'], $parsed['flags']);

            $credentialId = $credential['id'] ?? null;
            if (!is_string($credentialId) || $credentialId === '') {
                throw new Exception('Missing credential id');
            }

            $this->storeCredential(
                (string)$storedChallenge['userId'],
                $credentialId,
                $parsed['credentialPublicKeyCose'],
                (int)$parsed['signCount']
            );
            $this->clearChallenge($challengeId);

            return true;
        }

        public function generateAuthenticationChallenge(?string $userId = null): array {
            $challenge = random_bytes(32);
            $challengeId = bin2hex(random_bytes(16));

            $allowCredentials = [];
            if (is_string($userId) && $userId !== '') {
                $userCredentials = $this->getUserCredentials($userId);
                foreach ($userCredentials as $cred) {
                    $rawId = hex2bin($cred['id']);
                    if ($rawId === false) {
                        continue;
                    }
                    $allowCredentials[] = [
                        'type' => 'public-key',
                        'id' => $this->base64url_encode($rawId)
                    ];
                }
            }

            $publicKeyCredentialRequestOptions = [
                'challenge' => $this->base64url_encode($challenge),
                'timeout' => 60000,
                'userVerification' => 'required',
                'rpId' => $this->getExpectedRpId(),
            ];
            if (!empty($allowCredentials)) {
                $publicKeyCredentialRequestOptions['allowCredentials'] = $allowCredentials;
            }

            $this->storeChallenge($challengeId, $challenge, $userId ? (string)$userId : null);

            return [
                'challengeId' => $challengeId,
                'options' => $publicKeyCredentialRequestOptions
            ];
        }

        public function verifyAuthentication(string $challengeId, array $assertion): string {
            $storedChallenge = $this->getStoredChallenge($challengeId);
            if (!$storedChallenge) {
                throw new Exception('Invalid or expired challenge');
            }

            $clientDataJSON = $assertion['response']['clientDataJSON'] ?? null;
            if (!is_string($clientDataJSON) || $clientDataJSON === '') {
                throw new Exception('Missing clientDataJSON');
            }
            $clientDataRaw = $this->decodeWebAuthnBinary($clientDataJSON);
            $clientData = json_decode($clientDataRaw, true);
            if (!is_array($clientData)) {
                throw new Exception('Invalid clientDataJSON');
            }
            $this->validateClientData($clientData, $clientDataRaw, $storedChallenge, 'webauthn.get');

            $credentialId = $assertion['id'] ?? null;
            if (!is_string($credentialId) || $credentialId === '') {
                throw new Exception('Missing credential id');
            }
            $credential = $this->getCredentialById($credentialId);
            if (!$credential) {
                throw new Exception('Unknown credential');
            }

            $authenticatorDataB64 = $assertion['response']['authenticatorData'] ?? null;
            $signatureB64 = $assertion['response']['signature'] ?? null;
            if (!is_string($authenticatorDataB64) || $authenticatorDataB64 === '' || !is_string($signatureB64) || $signatureB64 === '') {
                throw new Exception('Invalid assertion response');
            }

            $authDataRaw = $this->decodeWebAuthnBinary($authenticatorDataB64);
            $parsedAuth = $this->parseAuthenticatorData($authDataRaw, false);
            $this->validateAuthenticatorData($parsedAuth['rpIdHash'], $parsedAuth['flags']);

            $publicKeyCoseRaw = $this->decodeStoredPublicKey($credential['publicKey'] ?? '');
            $pem = $this->coseToPem($publicKeyCoseRaw);

            $clientDataHash = hash('sha256', $clientDataRaw, true);
            $signedData = $authDataRaw . $clientDataHash;
            $sigRaw = $this->decodeWebAuthnBinary($signatureB64);

            $ok = openssl_verify($signedData, $sigRaw, $pem, OPENSSL_ALGO_SHA256);
            if ($ok !== 1) {
                throw new Exception('Invalid signature');
            }

            $storedCounter = (int)($credential['signCount'] ?? 0);
            if ((int)$parsedAuth['signCount'] > 0 && $storedCounter > 0 && (int)$parsedAuth['signCount'] <= $storedCounter) {
                throw new Exception('Invalid signature counter');
            }

            $this->updateCredentialUsage($credentialId, (int)$parsedAuth['signCount']);
            $this->clearChallenge($challengeId);

            return $credential['userId'];
        }

        public function hasUserPasskeys(string $userId): bool {
            $credentials = $this->getUserCredentials($userId);
            return !empty($credentials);
        }

        public function migrateUserCredentials(string $oldUserId, string $newUserId): void {
            if ($oldUserId === '' || $newUserId === '' || $oldUserId === $newUserId) {
                return;
            }

            $pdo = $this->getBiometricsPdo();
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    try {
                        $cols = $pdo->query("SHOW COLUMNS FROM webauthn_credentials LIKE 'user_id'");
                        if ($cols && $cols->rowCount() > 0) {
                            $pdo->prepare("UPDATE webauthn_credentials SET user_id = ? WHERE user_id = ?")->execute([$newUserId, $oldUserId]);
                        }
                    } catch (Throwable) {}
                } catch (Throwable) {}
            }

            try {
                $credentials = $this->loadCredentials();
                $changed = false;
                foreach ($credentials as &$cred) {
                    if (isset($cred['userId']) && (string)$cred['userId'] === $oldUserId) {
                        $cred['userId'] = $newUserId;
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->saveCredentials($credentials);
                }
            } catch (Throwable) {}
        }

        private function parseAttestationObject(string $attestationObjectB64): array {
            $attestationRaw = $this->decodeWebAuthnBinary($attestationObjectB64);
            $offset = 0;
            $decoded = $this->cborDecode($attestationRaw, $offset);
            if (!is_array($decoded) || !isset($decoded['authData']) || !is_string($decoded['authData'])) {
                throw new Exception('Invalid attestationObject');
            }
            $authData = $decoded['authData'];
            return $this->parseAuthenticatorData($authData, true);
        }

        private function storeCredential(string $userId, string $credentialIdB64u, string $publicKeyCoseRaw, int $signCount = 0): void {
            $hexId = bin2hex($this->base64url_decode($credentialIdB64u));
            $pdo = $this->getBiometricsPdo();
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    $pkB64 = base64_encode($publicKeyCoseRaw);
                    $deviceInfo = $this->getDeviceFingerprint();
                    $upd = $pdo->prepare("UPDATE webauthn_credentials
                        SET user_id = ?, public_key_b64 = ?, sign_count = ?, device_info = ?, last_used_at = NOW()
                        WHERE credential_id_hex = ?
                        LIMIT 1");
                    $upd->execute([(string)$userId, $pkB64, (int)$signCount, $deviceInfo, $hexId]);
                    if ((int)$upd->rowCount() === 0) {
                        $ins = $pdo->prepare("INSERT INTO webauthn_credentials
                            (credential_id_hex, user_id, public_key_b64, sign_count, device_info, created_at, last_used_at)
                            VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
                        $ins->execute([$hexId, (string)$userId, $pkB64, (int)$signCount, $deviceInfo]);
                    }
                } catch (Throwable) {
                }
            }

            $credentials = $this->loadCredentials();

            $credentials = array_values(array_filter($credentials, function ($cred) use ($hexId) {
                return !isset($cred['id']) || $cred['id'] !== $hexId;
            }));

            $credentials[] = [
                'id' => $hexId,
                'userId' => $userId,
                'publicKey' => base64_encode($publicKeyCoseRaw),
                'signCount' => (int)$signCount,
                'created' => time(),
                'lastUsed' => time(),
                'deviceInfo' => $this->getDeviceFingerprint()
            ];

            $this->saveCredentials($credentials);
        }

        private function updateCredentialUsage(string $credentialIdB64u, int $signCount): void {
            $hexId = bin2hex($this->base64url_decode($credentialIdB64u));
            $pdo = $this->getBiometricsPdo();
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    $stmt = $pdo->prepare("UPDATE webauthn_credentials
                        SET last_used_at = NOW(), sign_count = CASE WHEN ? > 0 THEN ? ELSE sign_count END
                        WHERE credential_id_hex = ?");
                    $stmt->execute([(int)$signCount, (int)$signCount, $hexId]);
                } catch (Throwable) {
                }
            }

            $credentials = $this->loadCredentials();
            $changed = false;
            foreach ($credentials as &$cred) {
                if (($cred['id'] ?? '') === $hexId) {
                    $cred['lastUsed'] = time();
                    if ((int)$signCount > 0) {
                        $cred['signCount'] = (int)$signCount;
                    }
                    $changed = true;
                    break;
                }
            }
            if ($changed) {
                $this->saveCredentials($credentials);
            }
        }

        private function getUserCredentials(string $userId): array {
            $pdo = $this->getBiometricsPdo();
            $dbCreds = [];
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    $stmt = $pdo->prepare("SELECT credential_id_hex, user_id, public_key_b64, sign_count, device_info, created_at, last_used_at
                        FROM webauthn_credentials WHERE user_id = ? ORDER BY last_used_at DESC, created_at DESC");
                    $stmt->execute([(string)$userId]);
                    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($rows as $row) {
                        $createdAt = isset($row['created_at']) ? strtotime((string)$row['created_at']) : false;
                        $lastUsedAt = isset($row['last_used_at']) ? strtotime((string)$row['last_used_at']) : false;
                        $dbCreds[] = [
                            'id' => (string)($row['credential_id_hex'] ?? ''),
                            'userId' => (string)($row['user_id'] ?? ''),
                            'publicKey' => (string)($row['public_key_b64'] ?? ''),
                            'signCount' => (int)($row['sign_count'] ?? 0),
                            'created' => $createdAt ? (int)$createdAt : time(),
                            'lastUsed' => $lastUsedAt ? (int)$lastUsedAt : time(),
                            'deviceInfo' => (string)($row['device_info'] ?? ''),
                        ];
                    }
                } catch (Throwable) {
                    $dbCreds = [];
                }
            }

            $credentials = $this->loadCredentials();
            $out = [];
            foreach ($credentials as $cred) {
                if (($cred['userId'] ?? null) === $userId) {
                    $out[] = $cred;
                }
            }

            if (!empty($dbCreds)) {
                $merged = [];
                foreach (array_merge($dbCreds, $out) as $cred) {
                    $id = (string)($cred['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    if (!isset($merged[$id])) {
                        $merged[$id] = $cred;
                    }
                }
                return array_values($merged);
            }

            if ($pdo instanceof PDO && !empty($out)) {
                foreach ($out as $cred) {
                    $id = (string)($cred['id'] ?? '');
                    if ($id === '') {
                        continue;
                    }
                    try {
                        $this->ensureWebauthnSchema($pdo);
                        $uid = (string)($cred['userId'] ?? $userId);
                        $pk = (string)($cred['publicKey'] ?? '');
                        $sc = (int)($cred['signCount'] ?? 0);
                        $di = (string)($cred['deviceInfo'] ?? '');
                        $created = (int)($cred['created'] ?? time());
                        $lastUsed = (int)($cred['lastUsed'] ?? time());
                        $upd = $pdo->prepare("UPDATE webauthn_credentials
                            SET user_id = ?, public_key_b64 = ?, sign_count = ?, device_info = ?, created_at = FROM_UNIXTIME(?), last_used_at = FROM_UNIXTIME(?)
                            WHERE credential_id_hex = ?
                            LIMIT 1");
                        $upd->execute([$uid, $pk, $sc, $di, $created, $lastUsed, $id]);
                        if ((int)$upd->rowCount() === 0) {
                            $ins = $pdo->prepare("INSERT INTO webauthn_credentials
                                (credential_id_hex, user_id, public_key_b64, sign_count, device_info, created_at, last_used_at)
                                VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))");
                            $ins->execute([$id, $uid, $pk, $sc, $di, $created, $lastUsed]);
                        }
                    } catch (Throwable) {
                    }
                }
            }

            return $out;
        }

        private function getCredentialById(string $credentialIdB64u): ?array {
            $hexId = bin2hex($this->base64url_decode($credentialIdB64u));
            $pdo = $this->getBiometricsPdo();
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    $stmt = $pdo->prepare("SELECT credential_id_hex, user_id, public_key_b64, sign_count, device_info, created_at, last_used_at
                        FROM webauthn_credentials WHERE credential_id_hex = ? LIMIT 1");
                    $stmt->execute([$hexId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row)) {
                        $createdAt = isset($row['created_at']) ? strtotime((string)$row['created_at']) : false;
                        $lastUsedAt = isset($row['last_used_at']) ? strtotime((string)$row['last_used_at']) : false;
                        return [
                            'id' => (string)($row['credential_id_hex'] ?? $hexId),
                            'userId' => (string)($row['user_id'] ?? ''),
                            'publicKey' => (string)($row['public_key_b64'] ?? ''),
                            'signCount' => (int)($row['sign_count'] ?? 0),
                            'created' => $createdAt ? (int)$createdAt : time(),
                            'lastUsed' => $lastUsedAt ? (int)$lastUsedAt : time(),
                            'deviceInfo' => (string)($row['device_info'] ?? ''),
                        ];
                    }
                } catch (Throwable) {
                }
            }

            $credentials = $this->loadCredentials();
            foreach ($credentials as $cred) {
                if (($cred['id'] ?? '') === $hexId) {
                    if ($pdo instanceof PDO) {
                        try {
                            $this->ensureWebauthnSchema($pdo);
                            $uid = (string)($cred['userId'] ?? '');
                            $pk = (string)($cred['publicKey'] ?? '');
                            $sc = (int)($cred['signCount'] ?? 0);
                            $di = (string)($cred['deviceInfo'] ?? '');
                            $created = (int)($cred['created'] ?? time());
                            $lastUsed = (int)($cred['lastUsed'] ?? time());
                            $upd = $pdo->prepare("UPDATE webauthn_credentials
                                SET user_id = ?, public_key_b64 = ?, sign_count = ?, device_info = ?, created_at = FROM_UNIXTIME(?), last_used_at = FROM_UNIXTIME(?)
                                WHERE credential_id_hex = ?
                                LIMIT 1");
                            $upd->execute([$uid, $pk, $sc, $di, $created, $lastUsed, $hexId]);
                            if ((int)$upd->rowCount() === 0) {
                                $ins = $pdo->prepare("INSERT INTO webauthn_credentials
                                    (credential_id_hex, user_id, public_key_b64, sign_count, device_info, created_at, last_used_at)
                                    VALUES (?, ?, ?, ?, ?, FROM_UNIXTIME(?), FROM_UNIXTIME(?))");
                                $ins->execute([$hexId, $uid, $pk, $sc, $di, $created, $lastUsed]);
                            }
                        } catch (Throwable) {
                        }
                    }
                    return $cred;
                }
            }
            return null;
        }

        private function loadCredentials() {
            if (!file_exists($this->credentialStore)) {
                return [];
            }
            $encrypted = file_get_contents($this->credentialStore);
            if (!is_string($encrypted) || $encrypted === '') {
                return [];
            }
            $decrypted = $this->decrypt($encrypted);
            $decoded = json_decode($decrypted, true);
            return is_array($decoded) ? $decoded : [];
        }

        public function listCredentialUserIds(): array {
            $creds = $this->loadCredentials();
            $out = [];
            foreach ($creds as $cred) {
                if (is_array($cred) && isset($cred['userId']) && is_string($cred['userId'])) {
                    $uid = trim((string)$cred['userId']);
                    if ($uid !== '') {
                        $out[$uid] = true;
                    }
                }
            }
            return array_keys($out);
        }

        public function deleteUserCredentials(string $userId): array {
            $userId = trim($userId);
            if ($userId === '') {
                return ['json_removed' => 0, 'db_removed' => 0];
            }

            $jsonRemoved = 0;
            $creds = $this->loadCredentials();
            $kept = [];
            foreach ($creds as $cred) {
                if (!is_array($cred)) {
                    continue;
                }
                $uid = isset($cred['userId']) && is_string($cred['userId']) ? trim((string)$cred['userId']) : '';
                if ($uid !== '' && $uid === $userId) {
                    $jsonRemoved++;
                    continue;
                }
                $kept[] = $cred;
            }
            if ($jsonRemoved > 0) {
                $this->saveCredentials($kept);
            }

            $dbRemoved = 0;
            $pdo = $this->getBiometricsPdo();
            if ($pdo instanceof PDO) {
                try {
                    $this->ensureWebauthnSchema($pdo);
                    $stmt = $pdo->prepare("DELETE FROM webauthn_credentials WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $dbRemoved = (int)$stmt->rowCount();
                } catch (Throwable) {
                }
            }

            return ['json_removed' => $jsonRemoved, 'db_removed' => $dbRemoved];
        }

        private function saveCredentials(array $credentials): void {
            $json = json_encode(array_values($credentials), JSON_PRETTY_PRINT);
            $encrypted = $this->encrypt($json);
            file_put_contents($this->credentialStore, $encrypted);
            @chmod($this->credentialStore, 0600);
        }

        private function storeChallenge(string $challengeId, string $challenge, ?string $userId): void {
            $challengeData = [
                'challenge' => base64_encode($challenge),
                'userId' => $userId,
                'expires' => time() + 300
            ];
            file_put_contents($this->challengeStore . $challengeId . '.json', json_encode($challengeData));
        }

        private function getStoredChallenge(string $challengeId): ?array {
            $file = $this->challengeStore . $challengeId . '.json';
            if (!file_exists($file)) {
                return null;
            }
            $data = json_decode(file_get_contents($file), true);
            if (!is_array($data)) {
                return null;
            }
            if (($data['expires'] ?? 0) < time()) {
                @unlink($file);
                return null;
            }
            return $data;
        }

        private function clearChallenge(string $challengeId): void {
            $file = $this->challengeStore . $challengeId . '.json';
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        private function getDeviceFingerprint() {
            return hash('sha256',
                ($_SERVER['HTTP_USER_AGENT'] ?? '') .
                ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') .
                ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')
            );
        }

        private function ensureStorageExists(string $dataPath, string $vaultPath): void {
            foreach ([$dataPath, $this->challengeStore, $vaultPath] as $dir) {
                if (!is_dir($dir)) {
                    @mkdir($dir, 0700, true);
                }
            }
        }

        private function encrypt(string $data) {
            $key = $this->getEncryptionKey();
            $iv = random_bytes(16);
            $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
            return base64_encode($iv . $encrypted);
        }

        private function decrypt(string $data): string {
            $key = $this->getEncryptionKey();
            $raw = base64_decode($data, true);
            if ($raw === false || strlen($raw) < 17) {
                return '';
            }
            $iv = substr($raw, 0, 16);
            $encrypted = substr($raw, 16);
            $out = openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
            return is_string($out) ? $out : '';
        }

        private function getEncryptionKey() {
            if (!file_exists($this->keyFile)) {
                $key = random_bytes(32);
                file_put_contents($this->keyFile, base64_encode($key));
                @chmod($this->keyFile, 0600);
            }
            return base64_decode((string)file_get_contents($this->keyFile));
        }

        private function base64url_encode(string $data): string {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        }

        private function base64url_decode(string $data) {
            $data = strtr($data, '-_', '+/');
            $remainder = strlen($data) % 4;
            if ($remainder) {
                $data .= str_repeat('=', 4 - $remainder);
            }
            return base64_decode($data);
        }

        private function decodeWebAuthnBinary(string $value) {
            $value = trim((string)$value);
            $decoded = base64_decode($value, true);
            if ($decoded !== false) {
                return $decoded;
            }
            return $this->base64url_decode($value);
        }

        private function validateClientData(array $clientData, string $clientDataRaw, array $storedChallenge, string $expectedType): void {
            if (!is_string($clientDataRaw) || $clientDataRaw === '') {
                throw new Exception('Invalid client data');
            }
            $type = $clientData['type'] ?? '';
            if (!is_string($type) || $type !== $expectedType) {
                throw new Exception('Invalid client data type');
            }

            $challengeB64u = $clientData['challenge'] ?? '';
            if (!is_string($challengeB64u) || $challengeB64u === '') {
                throw new Exception('Missing challenge');
            }

            $clientChallenge = $this->base64url_decode($challengeB64u);
            $stored = base64_decode($storedChallenge['challenge'] ?? '', true);
            if (!is_string($stored) || $stored === '' || !hash_equals($stored, $clientChallenge)) {
                throw new Exception('Challenge mismatch');
            }

            $origin = $clientData['origin'] ?? '';
            if (!is_string($origin) || $origin === '') {
                throw new Exception('Missing origin');
            }
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originScheme = parse_url($origin, PHP_URL_SCHEME);
            if (!is_string($originHost) || $originHost === '' || $originScheme !== 'https') {
                throw new Exception('Invalid origin');
            }

            $rpId = $this->getExpectedRpId();
            if ($originHost !== $rpId && !$this->endsWith($originHost, '.' . $rpId)) {
                throw new Exception('Origin mismatch');
            }
        }

        private function validateAuthenticatorData(string $rpIdHash, int $flags): void {
            $rpId = $this->getExpectedRpId();
            $expected = hash('sha256', $rpId, true);
            if (!hash_equals($expected, $rpIdHash)) {
                throw new Exception('RP ID hash mismatch');
            }
            if (($flags & 0x01) !== 0x01) {
                throw new Exception('User not present');
            }
            if (($flags & 0x04) !== 0x04) {
                throw new Exception('User not verified');
            }
        }

        private function getExpectedRpId(): string {
            $host = '';
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            if (is_string($origin) && trim($origin) !== '') {
                $u = parse_url($origin);
                if (is_array($u) && isset($u['host']) && is_string($u['host']) && trim($u['host']) !== '') {
                    $host = trim((string)$u['host']);
                }
            }
            $xfh = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '';
            if ($host === '' && is_string($xfh) && trim($xfh) !== '') {
                $parts = explode(',', $xfh);
                $host = trim((string)($parts[0] ?? ''));
            }
            if ($host === '') {
                $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'metahumans.one');
            }
            $host = strtolower(trim((string)$host));
            $host = preg_replace('/:\\d+$/', '', $host) ?: '';
            $host = rtrim($host, '.');
            if ($host === '' || $host === 'localhost' || preg_match('/^\\d{1,3}(?:\\.\\d{1,3}){3}$/', $host)) {
                $host = 'metahumans.one';
            }
            if ($host === 'www.metahumans.one') {
                return 'metahumans.one';
            }
            if ($this->endsWith($host, '.metahumans.one')) {
                return 'metahumans.one';
            }
            return $host;
        }

        private function parseAuthenticatorData(string $authData, bool $expectAttested): array {
            if (strlen($authData) < 37) {
                throw new Exception('Invalid authenticatorData');
            }
            $rpIdHash = substr($authData, 0, 32);
            $flags = ord($authData[32]);
            $signCount = unpack('N', substr($authData, 33, 4))[1];
            $offset = 37;

            $result = [
                'rpIdHash' => $rpIdHash,
                'flags' => $flags,
                'signCount' => $signCount
            ];

            if ($expectAttested) {
                if (($flags & 0x40) !== 0x40) {
                    throw new Exception('Missing attested credential data');
                }
                if (strlen($authData) < $offset + 18) {
                    throw new Exception('Invalid attested credential data');
                }
                $offset += 16;
                $credIdLen = unpack('n', substr($authData, $offset, 2))[1];
                $offset += 2;
                if (strlen($authData) < $offset + $credIdLen) {
                    throw new Exception('Invalid credential ID');
                }
                $credentialId = substr($authData, $offset, $credIdLen);
                $offset += $credIdLen;

                $coseStart = $offset;
                $decodedOffset = $offset;
                $this->cborDecode($authData, $decodedOffset);
                $coseEnd = $decodedOffset;
                if ($coseEnd <= $coseStart) {
                    throw new Exception('Invalid credential public key');
                }
                $credentialPublicKeyCose = substr($authData, $coseStart, $coseEnd - $coseStart);

                $result['credentialId'] = $credentialId;
                $result['credentialPublicKeyCose'] = $credentialPublicKeyCose;
            }

            return $result;
        }

        private function decodeStoredPublicKey(string $stored): string {
            $raw = base64_decode((string)$stored, true);
            if ($raw === false || $raw === '') {
                throw new Exception('Invalid stored public key');
            }
            return $raw;
        }

        private function coseToPem(string $coseKeyRaw): string {
            $offset = 0;
            $cose = $this->cborDecode($coseKeyRaw, $offset);
            if (!is_array($cose)) {
                throw new Exception('Invalid COSE key');
            }

            $kty = $cose[1] ?? null;
            if ($kty === 2) {
                $crv = $cose[-1] ?? null;
                $x = $cose[-2] ?? null;
                $y = $cose[-3] ?? null;
                if ($crv !== 1 || !is_string($x) || !is_string($y) || strlen($x) !== 32 || strlen($y) !== 32) {
                    throw new Exception('Invalid EC2 key');
                }
                $point = "\x04" . $x . $y;
                $algo = $this->asn1Sequence(
                    $this->asn1Oid('1.2.840.10045.2.1') . $this->asn1Oid('1.2.840.10045.3.1.7')
                );
                $spki = $this->asn1Sequence($algo . $this->asn1BitString($point));
                return $this->pemEncode('PUBLIC KEY', $spki);
            }

            if ($kty === 3) {
                $n = $cose[-1] ?? null;
                $e = $cose[-2] ?? null;
                if (!is_string($n) || !is_string($e) || $n === '' || $e === '') {
                    throw new Exception('Invalid RSA key');
                }
                $rsaPub = $this->asn1Sequence(
                    $this->asn1Integer($n) . $this->asn1Integer($e)
                );
                $algo = $this->asn1Sequence(
                    $this->asn1Oid('1.2.840.113549.1.1.1') . $this->asn1Null()
                );
                $spki = $this->asn1Sequence($algo . $this->asn1BitString($rsaPub));
                return $this->pemEncode('PUBLIC KEY', $spki);
            }

            throw new Exception('Unsupported key type');
        }

        private function cborDecode(string $data, int &$offset) {
            if ($offset >= strlen($data)) {
                throw new Exception('CBOR decode overflow');
            }
            $initial = ord($data[$offset++]);
            $major = $initial >> 5;
            $ai = $initial & 0x1f;
            $len = $this->cborReadLength($data, $offset, $ai);

            if ($major === 0) {
                return $len;
            }
            if ($major === 1) {
                return -1 - $len;
            }
            if ($major === 2) {
                $val = substr($data, $offset, $len);
                $offset += $len;
                return $val;
            }
            if ($major === 3) {
                $val = substr($data, $offset, $len);
                $offset += $len;
                return $val;
            }
            if ($major === 4) {
                $arr = [];
                for ($i = 0; $i < $len; $i++) {
                    $arr[] = $this->cborDecode($data, $offset);
                }
                return $arr;
            }
            if ($major === 5) {
                $map = [];
                for ($i = 0; $i < $len; $i++) {
                    $k = $this->cborDecode($data, $offset);
                    $v = $this->cborDecode($data, $offset);
                    if (is_int($k) || is_string($k)) {
                        $map[$k] = $v;
                    }
                }
                return $map;
            }
            if ($major === 7) {
                if ($ai === 20) return false;
                if ($ai === 21) return true;
                if ($ai === 22) return null;
            }
            throw new Exception('Unsupported CBOR type');
        }

        private function cborReadLength(string $data, int &$offset, int $ai): int {
            if ($ai < 24) {
                return $ai;
            }
            if ($ai === 24) {
                return ord($data[$offset++]);
            }
            if ($ai === 25) {
                $v = unpack('n', substr($data, $offset, 2))[1];
                $offset += 2;
                return $v;
            }
            if ($ai === 26) {
                $v = unpack('N', substr($data, $offset, 4))[1];
                $offset += 4;
                return $v;
            }
            throw new Exception('Unsupported CBOR length');
        }

        private function asn1Len(int $len): string {
            if ($len < 128) {
                return chr($len);
            }
            $out = '';
            while ($len > 0) {
                $out = chr($len & 0xff) . $out;
                $len >>= 8;
            }
            return chr(0x80 | strlen($out)) . $out;
        }

        private function asn1Tag(int $tag, string $value): string {
            return chr($tag) . $this->asn1Len(strlen($value)) . $value;
        }

        private function asn1Sequence(string $value): string {
            return $this->asn1Tag(0x30, $value);
        }

        private function asn1Integer(string $bytes): string {
            $bytes = ltrim($bytes, "\x00");
            if ($bytes === '' || (ord($bytes[0]) & 0x80)) {
                $bytes = "\x00" . $bytes;
            }
            return $this->asn1Tag(0x02, $bytes);
        }

        private function asn1Null() {
            return "\x05\x00";
        }

        private function asn1BitString(string $bytes): string {
            return $this->asn1Tag(0x03, "\x00" . $bytes);
        }

        private function asn1Oid(string $oid): string {
            $parts = array_map('intval', explode('.', $oid));
            if (count($parts) < 2) {
                throw new Exception('Invalid OID');
            }
            $first = (40 * $parts[0]) + $parts[1];
            $out = chr($first);
            for ($i = 2; $i < count($parts); $i++) {
                $n = $parts[$i];
                $stack = [];
                do {
                    $stack[] = $n & 0x7f;
                    $n >>= 7;
                } while ($n > 0);
                for ($j = count($stack) - 1; $j >= 0; $j--) {
                    $byte = $stack[$j];
                    if ($j !== 0) {
                        $byte |= 0x80;
                    }
                    $out .= chr($byte);
                }
            }
            return $this->asn1Tag(0x06, $out);
        }

        private function pemEncode(string $label, string $der): string {
            $b64 = chunk_split(base64_encode($der), 64, "\n");
            return "-----BEGIN {$label}-----\n" . $b64 . "-----END {$label}-----\n";
        }

        private function endsWith(string $haystack, string $needle): bool {
            $needle = (string)$needle;
            if ($needle === '') return true;
            $len = strlen($needle);
            if ($len > strlen($haystack)) return false;
            return substr($haystack, -$len) === $needle;
        }
    }
}

if (!class_exists('MetaPasskeyAuth')) {
    class MetaPasskeyAuth extends PasskeyAuth {}
}
