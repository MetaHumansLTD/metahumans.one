<?php
require_once __DIR__ . '/_context.php';

$ctx = mhw_require_context();

function mhw_safe_id_local(string $s): string {
    $s = trim((string)$s);
    $s = strtolower((string)preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim($s, '._-');
    return $s;
}

function mhw_persona_settings(string $tenantId, string $personaId): array {
    $tenantSafe = mhw_safe_id_local($tenantId);
    $personaSafe = mhw_safe_id_local($personaId);
    if ($tenantSafe === '' || $personaSafe === '') {
        return [];
    }
    $specPath = '/data/tenants/' . $tenantSafe . '/personas/' . $personaSafe . '/assets/persona-spec.json';
    if (!is_file($specPath)) {
        return [];
    }
    $raw = @file_get_contents($specPath);
    $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($j)) {
        return [];
    }
    $speechEngine = isset($j['speech']['engine']) && is_string($j['speech']['engine']) ? strtolower(trim((string)$j['speech']['engine'])) : 'classic';
    if (!in_array($speechEngine, ['classic', 'personaplex'], true)) {
        $speechEngine = 'classic';
    }
    $ppVoice = isset($j['speech']['personaplex_voice']) && is_string($j['speech']['personaplex_voice']) ? strtoupper(trim((string)$j['speech']['personaplex_voice'])) : '';
    if ($ppVoice === '') {
        $ppVoice = 'NATF2';
    }
    $voiceType = isset($j['voice']['type']) && is_string($j['voice']['type']) ? strtolower(trim((string)$j['voice']['type'])) : 'auto';
    if (!in_array($voiceType, ['male', 'female', 'animal', 'auto'], true)) {
        $voiceType = 'auto';
    }
    $language = isset($j['language']) && is_string($j['language']) ? trim((string)$j['language']) : 'en-US';
    if ($language === '') {
        $language = 'en-US';
    }
    $personaPrompt = isset($j['persona_description']) && is_string($j['persona_description']) ? trim((string)$j['persona_description']) : '';
    $instruction = isset($j['backends']['instruction']) && is_string($j['backends']['instruction']) ? trim((string)$j['backends']['instruction']) : 'hermes';
    return [
        'speech_engine' => $speechEngine,
        'personaplex_voice' => $ppVoice,
        'voice_type' => $voiceType,
        'language' => $language,
        'persona_prompt' => $personaPrompt,
        'translation_enabled' => !empty($j['translation_enabled']),
        'vision_enabled' => !empty($j['vision_enabled']),
        'hearing_enabled' => !empty($j['hearing_enabled']),
        'instruction_backend' => $instruction,
    ];
}

function mhw_personas_from_fs(string $tenantId, string $selectedPersona): array {
    $tenantSafe = mhw_safe_id_local($tenantId);
    if ($tenantSafe === '') {
        return [];
    }
    $root = '/data/tenants/' . $tenantSafe . '/personas';
    if (!is_dir($root)) {
        return [];
    }

    $selectedSafe = mhw_safe_id_local($selectedPersona);
    $entries = scandir($root);
    if (!is_array($entries)) {
        return [];
    }

    $items = [];
    foreach ($entries as $e) {
        if (!is_string($e) || $e === '.' || $e === '..') {
            continue;
        }
        $dir = $root . '/' . $e;
        if (!is_dir($dir)) {
            continue;
        }
        $personaId = mhw_safe_id_local($e);
        if ($personaId === '') {
            continue;
        }
        $label = $e;
        $manifest = $dir . '/assets/manifest.json';
        if (is_file($manifest)) {
            $raw = @file_get_contents($manifest);
            $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
            if (is_array($j)) {
                $pn = isset($j['persona_name']) && is_string($j['persona_name']) ? trim((string)$j['persona_name']) : '';
                if ($pn !== '') {
                    $label = $pn;
                }
            }
        }
        $items[] = [
            'persona_id' => $personaId,
            'label' => $label,
            'selected' => ($personaId === $selectedSafe) || ($label === $selectedPersona) || ($e === $selectedPersona),
        ];
    }

    usort($items, function ($a, $b) {
        $al = isset($a['label']) ? (string)$a['label'] : '';
        $bl = isset($b['label']) ? (string)$b['label'] : '';
        return strcasecmp($al, $bl);
    });

    return $items;
}

function mhw_personas_ensure_table(PDO $pdo): void {
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
}

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
if (!is_array($input)) $input = [];

$action = isset($input['action']) ? (string)$input['action'] : '';
if ($action === 'select') {
    $personaId = isset($input['persona_id']) ? trim((string)$input['persona_id']) : '';
    if ($personaId === '') {
        mhw_json(['success' => false, 'error' => 'missing_persona_id'], 400);
        exit;
    }
    $_SESSION['mh_selected_persona'] = $personaId;
    mhw_json(['success' => true, 'selected' => $personaId]);
    exit;
}

$username = (string)$ctx['username'];
$selected = mhw_get_persona_id();

$items = [];
try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (function_exists('database_getConnectionById') || function_exists('database_getContextAwareConnection')) {
        $pdo = function_exists('database_getConnectionById') ? database_getConnectionById('persona') : database_getContextAwareConnection();
        if ($pdo instanceof PDO) {
            mhw_personas_ensure_table($pdo);
            $stmt = $pdo->prepare("SELECT persona_id, persona_name FROM mh_personas WHERE owner_username = ? ORDER BY persona_name");
            if ($stmt instanceof PDOStatement) {
                $stmt->execute([$username]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($rows as $row) {
                    $p = trim((string)($row['persona_name'] ?? ''));
                    if ($p === '') continue;
                    $pid = trim((string)($row['persona_id'] ?? ''));
                    if ($pid === '') $pid = mhw_safe_id_local($p);
                    $items[] = [
                        'persona_id' => $pid,
                        'label' => $p,
                        'selected' => ($pid === $selected) || ($p === $selected),
                    ];
                }
            }
        }
    }
} catch (Throwable $e) {
}

if (!$items) {
    $items = mhw_personas_from_fs((string)($ctx['tenant_id'] ?? ''), (string)$selected);
}

if (!$items) {
    $items[] = [
        'persona_id' => $selected,
        'label' => $selected,
        'selected' => true,
    ];
}

mhw_json([
    'success' => true,
    'personas' => $items,
    'selected_settings' => mhw_persona_settings((string)($ctx['tenant_id'] ?? ''), (string)$selected),
]);
