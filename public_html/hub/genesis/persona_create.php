<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';

$ctx = mh_widget_require_auth();

$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string)$_SERVER['REQUEST_METHOD']) : 'GET';
if ($method !== 'POST') {
    header('Location: /hub/genesis/personas.php', true, 302);
    exit;
}

function mh_env_load_once(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $candidates = [];
    $envFile = getenv('MH_ENV_FILE');
    if (is_string($envFile) && trim($envFile) !== '') $candidates[] = trim((string)$envFile);
    $candidates[] = '/home/onemeta/.env';
    $candidates[] = '/home/onemeta/public_html/.env';
    foreach ($candidates as $path) {
        if (!is_string($path) || $path === '' || !is_file($path) || !is_readable($path)) continue;
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) continue;
        foreach ($lines as $line) {
            if (!is_string($line)) continue;
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $pos = strpos($line, '=');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k === '') continue;
            if (($v[0] ?? '') === '"' && str_ends_with($v, '"')) $v = substr($v, 1, -1);
            if (($v[0] ?? '') === "'" && str_ends_with($v, "'")) $v = substr($v, 1, -1);
            if (getenv($k) === false) {
                @putenv($k . '=' . $v);
                $_ENV[$k] = $v;
            }
        }
        break;
    }
}

function mh_env_get(string $key): string
{
    mh_env_load_once();
    $v = getenv($key);
    if (!is_string($v) || trim($v) === '') {
        $v = (string)($_ENV[$key] ?? ($_SERVER[$key] ?? ''));
    }
    return trim((string)$v);
}

function mh_sdxl_base_url(): string
{
    $u = mh_env_get('SDXL_TURBO_API_URL');
    if ($u === '') $u = mh_env_get('SDXL_API_URL');
    if ($u === '') $u = mh_superhumans_url('cortex-persona/sdxl-turbo');
    return rtrim($u, '/');
}

function mh_sdxl_token(): string
{
    $t = mh_env_get('SDXL_TURBO_API_TOKEN');
    if ($t !== '') return $t;
    return mh_env_get('SDXL_API_TOKEN');
}

function mh_cortex_persona_token(): string
{
    return mh_env_get('CORTEX_PERSONA_TOKEN');
}

function mh_sana_base_url(): string
{
    $u = mh_env_get('SANA_API_URL');
    return rtrim($u, '/');
}

function mh_sana_generate_b64(
    string $prompt,
    int $width = 1024,
    int $height = 1024,
    int $steps = 20,
    float $guidanceScale = 4.5,
    string $mode = 'avatar'
): array {
    $base = mh_sana_base_url();
    if ($base === '') throw new RuntimeException('sana_unconfigured');
    $token = mh_cortex_persona_token();
    $payload = [
        'prompt' => $prompt,
        'width' => $width,
        'height' => $height,
        'num_inference_steps' => $steps,
        'guidance_scale' => $guidanceScale,
        'negative_prompt' => '',
        'mode' => $mode,
    ];
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . '/v1/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 600);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('sana_failed:' . $httpCode . ':' . ($err !== '' ? $err : ''));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('sana_invalid_json');
    return $j;
}

function mh_sdxl_generate_b64(string $prompt, int $width = 512, int $height = 512, int $steps = 1): array
{
    $base = mh_sdxl_base_url();
    $token = mh_sdxl_token();
    $payload = [
        'prompt' => $prompt,
        'width' => $width,
        'height' => $height,
        'num_inference_steps' => $steps,
    ];
    $json = json_encode($payload);
    if (!is_string($json) || $json === '') throw new RuntimeException('json_encode_failed');
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $base . '/v1/generate');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    $headers = ['Content-Type: application/json'];
    if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('sdxl_failed:' . $httpCode . ':' . ($err !== '' ? $err : ''));
    }
    $j = json_decode($resp, true);
    if (!is_array($j)) throw new RuntimeException('sdxl_invalid_json');
    return $j;
}

function mh_write_placeholder_png(string $path): void
{
    $b64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGMQERH5DwABuAE8a9JwDAAAAABJRU5ErkJggg==';
    $bin = base64_decode($b64, true);
    if (!is_string($bin) || $bin === '') {
        throw new RuntimeException('placeholder_decode_failed');
    }
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('mkdir_failed');
        }
    }
    if (@file_put_contents($path, $bin) === false) {
        throw new RuntimeException('write_failed');
    }
}

$personaName = isset($_POST['persona_name']) ? trim((string)$_POST['persona_name']) : '';
$personaName = preg_replace('/\\s+/', ' ', $personaName);
if (!is_string($personaName) || $personaName === '') {
    mh_widget_json(['success' => false, 'error' => 'persona_name_required'], 400);
    exit;
}
if (strlen($personaName) > 64) $personaName = substr($personaName, 0, 64);

$tenantId = (string)($ctx['tenant_id'] ?? '');
$userId = (string)($ctx['username'] ?? '');
$tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));
if ($tenantSafe === '' || $tenantSafe === 'unknown') {
    mh_widget_json(['success' => false, 'error' => 'invalid_tenant_id'], 500);
    exit;
}

$username = (string)($ctx['username'] ?? '');
$personaRootBase = '/data/tenants/' . $tenantSafe . '/personas';
if (!is_dir($personaRootBase)) {
    if (!mkdir($personaRootBase, 0700, true) && !is_dir($personaRootBase)) {
        mh_widget_json(['success' => false, 'error' => 'mkdir_failed'], 500);
        exit;
    }
}

$existingPersonaId = '';
$existingPersonaDir = '';
try {
    $entries = scandir($personaRootBase);
    if (is_array($entries)) {
        foreach ($entries as $e) {
            if (!is_string($e) || $e === '.' || $e === '..') continue;
            $dir = $personaRootBase . '/' . $e;
            if (!is_dir($dir)) continue;
            $manifest = $dir . '/assets/manifest.json';
            if (!is_file($manifest)) continue;
            $raw = @file_get_contents($manifest);
            $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($j)) continue;
            $pname = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
            if ($pname === '') continue;
            if (strcasecmp($pname, $personaName) === 0) {
                $existingPersonaId = strtolower(mh_widget_sanitize_id($e));
                $existingPersonaDir = $dir;
                break;
            }
        }
    }
} catch (Throwable) {
    $existingPersonaId = '';
    $existingPersonaDir = '';
}

$requestedId = strtolower(mh_widget_sanitize_id($personaName));
if ($existingPersonaId === '' && $requestedId !== '' && $requestedId !== 'unknown') {
    $target = $personaRootBase . '/' . $requestedId;
    if (is_dir($target)) $existingPersonaId = $requestedId;
}

$existingPersonaId = $existingPersonaId !== '' && $existingPersonaId !== 'unknown' ? $existingPersonaId : '';
if ($existingPersonaId !== '') {
    if ($existingPersonaDir !== '') {
        $targetDir = $personaRootBase . '/' . $existingPersonaId;
        if ($existingPersonaDir !== $targetDir && !is_dir($targetDir)) {
            @rename($existingPersonaDir, $targetDir);
        }
        $existingPersonaDir = is_dir($targetDir) ? $targetDir : $existingPersonaDir;
    }
    $personaRoot = $personaRootBase . '/' . $existingPersonaId;
    $metaHumanIdExisting = 'meta:' . strtolower(mh_widget_sanitize_id($existingPersonaId));
    $avatarDst = $personaRoot . '/assets/images/normalized/avatar.png';
    try {
        if (function_exists('cue_autoload')) cue_autoload('database');
        if (function_exists('database_getContextAwareConnection')) {
            $pdo = database_getContextAwareConnection();
            if ($pdo instanceof PDO) {
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $pdo->exec("CREATE TABLE IF NOT EXISTS mh_personas (
                    id BIGINT AUTO_INCREMENT PRIMARY KEY,
                    owner_username VARCHAR(255) NOT NULL,
                    user_id VARCHAR(255) NULL,
                    tenant_id VARCHAR(255) NULL,
                    persona_id VARCHAR(255) NULL,
                    meta_human_id VARCHAR(255) NULL,
                    persona_name VARCHAR(255) NOT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
                    UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id),
                    KEY idx_owner (owner_username)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
                $stmt = $pdo->prepare("INSERT INTO mh_personas (owner_username, user_id, tenant_id, persona_id, meta_human_id, persona_name)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        user_id = VALUES(user_id),
                        tenant_id = VALUES(tenant_id),
                        meta_human_id = VALUES(meta_human_id),
                        persona_name = VALUES(persona_name)");
                $stmt->execute([$username, $userId, $tenantId, $existingPersonaId, $metaHumanIdExisting, $personaName]);
            }
        }
    } catch (Throwable) {
    }
    $_SESSION['mh_selected_persona'] = $existingPersonaId;
    $_SESSION['mh_meta_human_id'] = $metaHumanIdExisting;
    mh_widget_json([
        'success' => true,
        'persona_id' => $existingPersonaId,
        'user_id' => $userId,
        'persona_name' => $personaName,
        'tenant_id' => $tenantId,
        'meta_human_id' => $metaHumanIdExisting,
        'db_ok' => false,
        'already_exists' => true,
        'avatar_generated' => false,
        'avatar_exists' => is_file($avatarDst) && filesize($avatarDst) > 0,
        'avatar_error' => null,
    ]);
    exit;
}

$baseId = strtolower(mh_widget_sanitize_id($personaName));
if ($baseId === '' || $baseId === 'unknown') $baseId = 'persona';
$personaId = $baseId;
for ($i = 0; $i < 40; $i++) {
    $tryId = $i === 0 ? $baseId : ($baseId . '_' . ($i + 1));
    $tryRoot = $personaRootBase . '/' . $tryId;
    if (!is_dir($tryRoot)) {
        $personaId = $tryId;
        break;
    }
}

$personaRoot = $personaRootBase . '/' . $personaId;
$metaHumanId = 'meta:' . strtolower(mh_widget_sanitize_id($personaId));
$imgDir = $personaRoot . '/assets/images/normalized';
$manifestDir = $personaRoot . '/assets';
if (!is_dir($imgDir)) {
    if (!mkdir($imgDir, 0700, true) && !is_dir($imgDir)) {
        mh_widget_json(['success' => false, 'error' => 'mkdir_failed'], 500);
        exit;
    }
}
if (!is_dir($manifestDir)) {
    if (!mkdir($manifestDir, 0700, true) && !is_dir($manifestDir)) {
        mh_widget_json(['success' => false, 'error' => 'mkdir_failed'], 500);
        exit;
    }
}

$avatarDst = $imgDir . '/avatar.png';
if (!is_file($avatarDst) || filesize($avatarDst) < 1) {
    $fallback = $personaRootBase . '/master/assets/images/normalized/avatar.png';
    if (is_file($fallback) && filesize($fallback) > 1) {
        @copy($fallback, $avatarDst);
    }
}

$personaDescription = isset($_POST['persona_description']) ? trim((string)$_POST['persona_description']) : '';
$personaDescription = preg_replace('/\\s+/', ' ', $personaDescription);
if (strlen($personaDescription) > 800) $personaDescription = substr($personaDescription, 0, 800);

$voiceType = isset($_POST['voice_type']) ? trim((string)$_POST['voice_type']) : '';
$voiceType = strtolower(mh_widget_sanitize_id($voiceType));
if (!in_array($voiceType, ['male', 'female', 'animal', 'auto'], true)) $voiceType = 'auto';

$voiceRefPath = '/data/tenants/' . $tenantSafe . '/voices/' . $personaId . '/reference.wav';
if ($voiceType !== 'auto' && (!is_file($voiceRefPath) || filesize($voiceRefPath) < 1024)) {
    $preset = '';
    if ($voiceType === 'female') $preset = '/home/onemeta/public_html/hub/genesis/voice-presets/pod_f_enhanced.wav';
    if ($voiceType === 'male') $preset = '/home/onemeta/public_html/hub/genesis/voice-presets/pod_m_enhanced.wav';
    if ($preset !== '' && is_file($preset) && filesize($preset) > 1024) {
        $dir = dirname($voiceRefPath);
        if (!is_dir($dir)) @mkdir($dir, 0700, true);
        @copy($preset, $voiceRefPath);
    }
}

$language = isset($_POST['language']) ? trim((string)$_POST['language']) : '';
$language = mh_widget_sanitize_id($language);
if ($language === '' || $language === 'unknown') $language = 'en-US';

$translationEnabled = isset($_POST['translation_enabled']) ? trim((string)$_POST['translation_enabled']) : '';
$translationEnabled = in_array(strtolower($translationEnabled), ['1', 'true', 'yes', 'on'], true);

$visionEnabled = isset($_POST['vision_enabled']) ? trim((string)$_POST['vision_enabled']) : '';
$visionEnabled = in_array(strtolower($visionEnabled), ['1', 'true', 'yes', 'on'], true);

$hearingEnabled = isset($_POST['hearing_enabled']) ? trim((string)$_POST['hearing_enabled']) : '';
$hearingEnabled = in_array(strtolower($hearingEnabled), ['1', 'true', 'yes', 'on'], true);

$instructionBackend = isset($_POST['instruction_backend']) ? trim((string)$_POST['instruction_backend']) : '';
$instructionBackend = strtolower(mh_widget_sanitize_id($instructionBackend));
if (!in_array($instructionBackend, ['hermes', 'tock', 'headless'], true)) $instructionBackend = 'hermes';

$memoryBackend = 'realtime';

$avatarGenerated = false;
$avatarError = '';
$avatarEngine = 'none';
$avatarEngineUrl = '';
try {
    $promptBase = 'portrait photo, studio lighting, ultra realistic, sharp focus';
    $subject = $personaName !== '' ? ('person named ' . $personaName) : 'person';
    $desc = $personaDescription !== '' ? (', ' . $personaDescription) : '';
    $prompt = $subject . $desc . ', ' . $promptBase;
    $j = null;
    $sanaUrl = mh_sana_base_url();
    if ($sanaUrl !== '') {
        try {
            $j = mh_sana_generate_b64($prompt, 512, 512, 20, 4.5, 'avatar');
            $avatarEngine = 'sana';
            $avatarEngineUrl = $sanaUrl;
        } catch (Throwable) {
            $j = null;
        }
    }
    if (!is_array($j)) {
        $j = mh_sdxl_generate_b64($prompt, 512, 512, 1);
        $avatarEngine = 'sdxl-turbo';
        $avatarEngineUrl = mh_sdxl_base_url();
    }
    $b64 = isset($j['image_png_base64']) && is_string($j['image_png_base64']) ? (string)$j['image_png_base64'] : '';
    $bin = $b64 !== '' ? base64_decode($b64, true) : false;
    if (!is_string($bin) || strlen($bin) < 128) {
        throw new RuntimeException('image_missing');
    }
    if (@file_put_contents($avatarDst, $bin) === false) {
        throw new RuntimeException('avatar_write_failed');
    }
    $avatarGenerated = true;
} catch (Throwable $e) {
    $avatarGenerated = false;
    $avatarError = $e->getMessage();
    if ((!is_file($avatarDst) || filesize($avatarDst) < 1)) {
        try {
            mh_write_placeholder_png($avatarDst);
        } catch (Throwable $e2) {
            if ($avatarError === '') $avatarError = $e2->getMessage();
        }
    }
}

$manifest = [
    'tenant_id' => $tenantId,
    'username' => $username,
    'persona_name' => $personaName,
    'persona_id' => $personaId,
    'created_at' => gmdate('c'),
    'avatar' => [
        'normalized_path' => $avatarDst,
        'exists' => is_file($avatarDst) && filesize($avatarDst) > 0,
        'generated' => $avatarGenerated,
        'engine' => $avatarGenerated ? $avatarEngine : 'none',
        'engine_url' => $avatarEngineUrl,
        'engine_error' => $avatarError !== '' ? $avatarError : null,
    ],
    'spec' => [
        'persona_description' => $personaDescription,
        'language' => $language,
        'translation_enabled' => $translationEnabled,
        'vision_enabled' => $visionEnabled,
        'hearing_enabled' => $hearingEnabled,
        'speech' => [
            'engine' => 'classic',
            'personaplex_voice' => $voiceType === 'female' ? 'NATF2' : ($voiceType === 'male' ? 'NATM1' : 'NATF2'),
        ],
        'voice' => [
            'type' => $voiceType,
        ],
        'backends' => [
            'instruction' => $instructionBackend,
            'memory' => $memoryBackend,
        ],
    ],
];
$manifestPath = $manifestDir . '/manifest.json';
@file_put_contents($manifestPath, json_encode($manifest, JSON_UNESCAPED_SLASHES));

$specPath = $manifestDir . '/persona-spec.json';
@file_put_contents($specPath, json_encode($manifest['spec'], JSON_UNESCAPED_SLASHES));

$dbOk = false;
try {
    if (function_exists('cue_autoload')) cue_autoload('database');
    if (function_exists('database_getContextAwareConnection')) {
        $pdo = database_getContextAwareConnection();
        if ($pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE IF NOT EXISTS mh_personas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                owner_username VARCHAR(255) NOT NULL,
                user_id VARCHAR(255) NULL,
                tenant_id VARCHAR(255) NULL,
                persona_id VARCHAR(255) NULL,
                meta_human_id VARCHAR(255) NULL,
                persona_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
                UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id),
                KEY idx_owner (owner_username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN user_id VARCHAR(255) NULL AFTER owner_username"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN tenant_id VARCHAR(255) NULL AFTER user_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN persona_id VARCHAR(255) NULL AFTER tenant_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD COLUMN meta_human_id VARCHAR(255) NULL AFTER persona_id"); } catch (Throwable) {}
            try { $pdo->exec("ALTER TABLE mh_personas ADD UNIQUE KEY uniq_owner_persona_id (owner_username, persona_id)"); } catch (Throwable) {}
            $stmt = $pdo->prepare("INSERT INTO mh_personas (owner_username, user_id, tenant_id, persona_id, meta_human_id, persona_name)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    tenant_id = VALUES(tenant_id),
                    meta_human_id = VALUES(meta_human_id),
                    persona_name = VALUES(persona_name)");
            $stmt->execute([$username, $userId, $tenantId, $personaId, $metaHumanId, $personaName]);
            $dbOk = true;
        }
    }
} catch (Throwable) {
}

try {
    $regPath = __DIR__ . '/../../auth/persona_registry.php';
    if (is_file($regPath)) {
        require_once $regPath;
        if (function_exists('mh_persona_registry_pdo') && function_exists('mh_persona_registry_claim')) {
            $pdoReg = mh_persona_registry_pdo();
            mh_persona_registry_claim($pdoReg, $username, $personaName);
        }
    }
} catch (Throwable) {
}

$_SESSION['mh_selected_persona'] = $personaId;
$_SESSION['mh_meta_human_id'] = $metaHumanId;

mh_widget_json([
    'success' => true,
    'persona_id' => $personaId,
    'user_id' => $userId,
    'persona_name' => $personaName,
    'tenant_id' => $tenantId,
    'meta_human_id' => $metaHumanId,
    'db_ok' => $dbOk,
    'avatar_generated' => $avatarGenerated,
    'avatar_exists' => is_file($avatarDst) && filesize($avatarDst) > 0,
    'avatar_error' => $avatarError !== '' ? $avatarError : null,
]);
