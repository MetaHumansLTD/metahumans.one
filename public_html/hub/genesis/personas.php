<?php
require_once __DIR__ . '/../../.cue/cue.php';
require_once __DIR__ . '/../../auth/auth_functions.php';

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || trim((string)$_SESSION['mh_auth_user']) === '') {
    header('Location: /auth/login.php');
    exit;
}

function mh_genesis_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s;
}

function mh_genesis_tenant_id(string $username): string
{
    $t = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($t === '') {
        $t = 'user:' . $username;
    }
    return $t;
}

function mh_genesis_tenant_pdo(): ?PDO
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_getContextAwareConnection')) {
        return null;
    }
    try {
        $pdo = database_getContextAwareConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (Throwable $e) {
        return null;
    }
}

function mh_genesis_ensure_personas_table(PDO $pdo): void
{
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

$username = trim((string)$_SESSION['mh_auth_user']);
$tenantId = mh_genesis_tenant_id($username);
$tenantSafe = mh_genesis_safe_id($tenantId);
$defaultPersonaName = isset($_SESSION['mh_auth_persona']) && is_string($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
if ($defaultPersonaName === '') {
    $defaultPersonaName = 'MH-' . $username;
}

$tenantProvisioning = __DIR__ . '/../../auth/tenant_provisioning.php';
if ($tenantId !== '' && !function_exists('mh_apply_tenant_context') && is_file($tenantProvisioning)) {
    require_once $tenantProvisioning;
}
if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
    try { mh_apply_tenant_context($tenantId); } catch (Throwable $e) {}
}

$pdo = mh_genesis_tenant_pdo();
$personas = [];
$dbError = null;
if ($pdo instanceof PDO) {
    try {
        mh_genesis_ensure_personas_table($pdo);
        $stmt = $pdo->prepare("SELECT persona_id, persona_name, created_at FROM mh_personas WHERE owner_username = ? ORDER BY created_at DESC");
        $stmt->execute([$username]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows)) {
            $personas = $rows;
        }
    } catch (Throwable $e) {
        $dbError = $e->getMessage();
    }
} else {
    $dbError = 'tenant_db_unavailable';
}

$personaRecords = [];
if (is_array($personas)) {
    foreach ($personas as $p) {
        $personaName = is_string($p['persona_name'] ?? null) ? trim((string)$p['persona_name']) : '';
        $personaId = is_string($p['persona_id'] ?? null) ? trim((string)$p['persona_id']) : '';
        if ($personaId === '') $personaId = mh_genesis_safe_id($personaName);
        if ($personaId === '') $personaId = 'default';
        $createdAt = is_string($p['created_at'] ?? null) ? (string)$p['created_at'] : '';
        $personaRecords[$personaId] = [
            'persona_id' => $personaId,
            'persona_name' => $personaName !== '' ? $personaName : $personaId,
            'created_at' => $createdAt,
            'source' => 'db',
        ];
    }
}

$personaRootDir = '/data/tenants/' . $tenantSafe . '/personas';
$personaRootDirExists = ($tenantSafe !== '' && is_dir($personaRootDir));
$personaRootDirCount = 0;
if ($tenantSafe !== '' && is_dir($personaRootDir)) {
    $entries = scandir($personaRootDir);
    if (is_array($entries)) {
        $personaRootDirCount = count($entries);
        foreach ($entries as $e) {
            if (!is_string($e)) continue;
            if ($e === '.' || $e === '..') continue;
            $pid = mh_genesis_safe_id($e);
            if ($pid === '') continue;
            $dir = $personaRootDir . '/' . $e;
            if (!is_dir($dir)) continue;
            if (isset($personaRecords[$pid])) continue;
            $manifestPath = $dir . '/assets/manifest.json';
            $personaName = $pid;
            $createdAt = '';
            if (is_file($manifestPath)) {
                $raw = @file_get_contents($manifestPath);
                $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                if (is_array($j)) {
                    $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
                    if ($pn !== '') $personaName = $pn;
                    $ca = isset($j['created_at']) ? trim((string)$j['created_at']) : '';
                    if ($ca !== '') $createdAt = $ca;
                }
            }
            if ($createdAt === '' && is_dir($dir)) {
                $createdAt = gmdate('c', (int)@filemtime($dir));
            }
            $personaRecords[$pid] = [
                'persona_id' => $pid,
                'persona_name' => $personaName,
                'created_at' => $createdAt,
                'source' => 'fs',
            ];
        }
    }
}

$personaList = array_values($personaRecords);
usort($personaList, function (array $a, array $b): int {
    $at = isset($a['created_at']) ? (string)$a['created_at'] : '';
    $bt = isset($b['created_at']) ? (string)$b['created_at'] : '';
    return strcmp($bt, $at);
});

if (empty($personaList) && $personaRootDirExists && is_dir($personaRootDir . '/master')) {
    $manifestPath = $personaRootDir . '/master/assets/manifest.json';
    $personaName = 'master';
    $createdAt = gmdate('c', (int)@filemtime($personaRootDir . '/master'));
    if (is_file($manifestPath)) {
        $raw = @file_get_contents($manifestPath);
        $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($j)) {
            $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
            if ($pn !== '') $personaName = $pn;
            $ca = isset($j['created_at']) ? trim((string)$j['created_at']) : '';
            if ($ca !== '') $createdAt = $ca;
        }
    }
    $personaList[] = [
        'persona_id' => 'master',
        'persona_name' => $personaName,
        'created_at' => $createdAt,
        'source' => 'fs-fallback',
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genesis: Personas</title>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; ?>
    <link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">
    <style>
        body { background: #0a0a0a; color: #00d4ff; font-family: 'Rajdhani', sans-serif; margin: 0; }
        .genesis-page { display: flex; justify-content: center; padding: 15px; }
        .container { background: rgba(255,255,255,0.05); padding: 30px; border-radius: 12px; border: 1px solid #333; max-width: 980px; width: 100%; box-shadow: 0 0 20px rgba(0, 212, 255, 0.1); }
        h1 { margin: 0 0 10px; font-weight: 300; letter-spacing: 2px; text-align: center; }
        .sub { color: rgba(255,255,255,0.7); text-align: center; margin: 0 0 18px; }
        .meta { display: grid; grid-template-columns: 1fr; gap: 6px; background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 12px; margin-bottom: 18px; }
        .meta div { color: rgba(255,255,255,0.8); font-size: 14px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 14px; }
        .card { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; overflow: hidden; }
        .card .img { aspect-ratio: 1 / 1; background: #000; display:flex; align-items:center; justify-content:center; border-bottom: 1px solid rgba(255,255,255,0.12); }
        .card .img img { width: 100%; height: 100%; object-fit: contain; display: block; background: #000; }
        .card .img .placeholder { color: rgba(255,255,255,0.35); font-size: 13px; padding: 10px; text-align:center; }
        .card .body { padding: 14px; }
        .label { color: rgba(255,255,255,0.6); font-size: 13px; margin-bottom: 6px; }
        .value { color: rgba(255,255,255,0.9); font-size: 15px; margin-bottom: 10px; word-break: break-word; }
        .card-actions { display:flex; gap: 10px; justify-content:center; margin-top: 12px; flex-wrap: wrap; }
        .actions { display:flex; gap: 10px; justify-content:center; margin-top: 18px; flex-wrap: wrap; }
        .btn { background: linear-gradient(135deg, #00d4ff 0%, #7c3aed 100%); border: none; padding: 12px 18px; color: white; font-weight: bold; border-radius: 6px; cursor: pointer; font-size: 15px; transition: transform 0.2s; text-decoration: none; display: inline-block; }
        .btn:hover { transform: scale(1.03); }
        .btn.secondary { background: rgba(255,255,255,0.08); border: 1px solid rgba(255,255,255,0.12); }
        .btn.small { padding: 8px 12px; font-size: 13px; border-radius: 8px; }
        .btn.danger { background: rgba(255,90,122,0.12); border: 1px solid rgba(255,90,122,0.35); }
        .error { color: #ff5a7a; text-align:center; margin: 14px 0; }
        .persona-manager { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 14px; margin-bottom: 18px; }
        .persona-manager h2 { margin: 0 0 10px; font-weight: 300; letter-spacing: 1px; font-size: 16px; color: rgba(255,255,255,0.85); }
        .persona-create { display:flex; gap: 10px; flex-wrap: wrap; align-items:center; margin-bottom: 10px; }
        .persona-create input { flex: 1; min-width: 220px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px 12px; color: rgba(255,255,255,0.9); font-size: 14px; outline: none; box-sizing: border-box; }
        .persona-row { display:flex; align-items:center; justify-content:space-between; gap: 10px; padding: 8px 10px; border-radius: 10px; border: 1px solid rgba(255,255,255,0.08); background: rgba(0,0,0,0.25); margin-top: 8px; }
        .persona-row .name { color: rgba(255,255,255,0.9); font-size: 14px; word-break: break-word; }
        .persona-row .actions { display:flex; gap: 8px; margin: 0; }
        .ai-card { background: rgba(0,0,0,0.35); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 14px; margin-bottom: 18px; }
        .ai-card h2 { margin: 0 0 10px; font-weight: 300; letter-spacing: 1px; font-size: 16px; color: rgba(255,255,255,0.85); }
        .ai-grid { display:grid; grid-template-columns: 1fr; gap: 10px; }
        .ai-row { display:grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .ai-row select, .ai-grid textarea, .ai-grid input { width: 100%; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 10px 12px; color: rgba(255,255,255,0.9); font-size: 14px; outline: none; box-sizing: border-box; }
        .ai-grid textarea { min-height: 90px; resize: vertical; }
        .ai-toggles { display:flex; gap: 12px; flex-wrap: wrap; align-items:center; justify-content: flex-start; }
        .ai-toggles label { display:flex; gap: 8px; align-items:center; color: rgba(255,255,255,0.8); font-size: 13px; }
        .ai-actions { display:flex; gap: 10px; justify-content:flex-end; flex-wrap: wrap; }
        .inline-controls { display:flex; gap: 8px; flex-wrap: wrap; align-items:center; justify-content: space-between; margin-top: 10px; }
        .inline-controls select { background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.12); border-radius: 10px; padding: 8px 10px; color: rgba(255,255,255,0.9); font-size: 13px; outline: none; }
        .btn.mic { background: rgba(0,212,255,0.12); border: 1px solid rgba(0,212,255,0.35); }
    </style>
</head>
<body>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; ?>
    <main class="main-content">
        <div class="genesis-page">
            <div class="container">
                <h1>PERSONAS</h1>

                <?php if (is_string($dbError) && $dbError !== ''): ?>
                    <div class="error">Database error: <?php echo htmlspecialchars($dbError, ENT_QUOTES); ?></div>
                <?php endif; ?>

                <div id="ai-persona" class="ai-card">
                    <h2>Create AI Persona</h2>
                    <div class="ai-grid">
                        <input class="ai-persona-name" type="text" maxlength="64" placeholder="Persona name (e.g., Sarah, Coach Mike)" value="<?php echo htmlspecialchars($defaultPersonaName, ENT_QUOTES); ?>" data-default-name="<?php echo htmlspecialchars($defaultPersonaName, ENT_QUOTES); ?>" />
                        <textarea class="ai-persona-desc" maxlength="800" placeholder="Describe the persona: personality, role, tone, do/don’t rules, background…"></textarea>
                        <div class="ai-row">
                            <select class="ai-voice-type">
                                <option value="auto" selected>Voice: Auto</option>
                                <option value="female">Voice: Female</option>
                                <option value="male">Voice: Male</option>
                                <option value="animal">Voice: Animal</option>
                            </select>
                            <select class="ai-language"></select>
                        </div>
                        <div class="ai-toggles">
                            <label><input class="ai-translation" type="checkbox" /> Translation</label>
                            <label><input class="ai-vision" type="checkbox" /> Vision</label>
                            <label><input class="ai-hearing" type="checkbox" /> Hearing</label>
                        </div>
                        <div class="ai-row">
                            <select class="ai-instruction-backend">
                                <option value="hermes" selected>Instructions: Hermes</option>
                                <option value="tock">Instructions: Tock</option>
                                <option value="headless">Instructions: Headless IDE</option>
                            </select>
                            <select class="ai-memory-mode" disabled>
                                <option value="realtime" selected>Memory: Realtime (Always On)</option>
                            </select>
                        </div>
                        <div class="ai-actions">
                            <button class="btn secondary ai-create-btn" type="button">Create</button>
                            <a class="btn" href="/hub/genesis/persona-images.php">Persona Images</a>
                        </div>
                        <div class="preview-status ai-status"></div>
                    </div>
                </div>

                <div class="persona-manager">
                    <h2>Personas</h2>
                    <div class="persona-create">
                        <input class="persona-create-name" type="text" maxlength="64" placeholder="New persona name (e.g., Sarah, Coach Mike)" value="<?php echo htmlspecialchars($defaultPersonaName, ENT_QUOTES); ?>" data-default-name="<?php echo htmlspecialchars($defaultPersonaName, ENT_QUOTES); ?>" />
                        <button class="btn small persona-create-btn" type="button">Create</button>
                    </div>
                    <?php foreach ($personaList as $p): ?>
                        <?php
                            $personaName = is_string($p['persona_name'] ?? null) ? trim((string)$p['persona_name']) : '';
                            $personaId = is_string($p['persona_id'] ?? null) ? trim((string)$p['persona_id']) : '';
                            if ($personaId === '') $personaId = mh_genesis_safe_id($personaName);
                            if ($personaId === '') $personaId = 'default';
                        ?>
                        <div class="persona-row">
                            <div class="name"><?php echo htmlspecialchars($personaName !== '' ? $personaName : $personaId, ENT_QUOTES); ?></div>
                            <div class="actions">
                                <a class="btn secondary small" href="/hub/genesis/persona_edit.php?persona_id=<?php echo rawurlencode($personaId); ?>">Edit</a>
                                <button class="btn danger small persona-delete" type="button" data-persona-id="<?php echo htmlspecialchars($personaId, ENT_QUOTES); ?>" data-persona-name="<?php echo htmlspecialchars($personaName !== '' ? $personaName : $personaId, ENT_QUOTES); ?>">Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (empty($personaList)): ?>
                    <div class="error">No personas found yet.</div>
                    <div class="actions">
                        <a class="btn" href="/hub/genesis/persona-images.php">Persona Images</a>
                        <a class="btn secondary" href="#ai-persona">Create AI Persona</a>
                    </div>
                <?php else: ?>
                    <div class="grid">
                        <?php foreach ($personaList as $p): ?>
                            <?php
                                $personaName = is_string($p['persona_name'] ?? null) ? trim((string)$p['persona_name']) : '';
                                $personaId = is_string($p['persona_id'] ?? null) ? trim((string)$p['persona_id']) : '';
                                if ($personaId === '') {
                                    $personaId = mh_genesis_safe_id($personaName);
                                }
                                if ($personaId === '') $personaId = 'default';
                                $avatarPath = '/data/tenants/' . $tenantSafe . '/personas/' . $personaId . '/assets/images/normalized/avatar.png';
                                $specPath = '/data/tenants/' . $tenantSafe . '/personas/' . $personaId . '/assets/persona-spec.json';
                                $createdAt = is_string($p['created_at'] ?? null) ? (string)$p['created_at'] : '';
                                $avatarVersion = is_file($avatarPath) ? (int)@filemtime($avatarPath) : time();
                                $avatarUrl = '/hub/genesis/persona-images.php?persona=' . rawurlencode($personaId) . '&v=' . $avatarVersion;
                                $specVoiceType = 'auto';
                                $specLanguage = 'en-US';
                                $specSpeechEngine = 'classic';
                                $specPersonaplexVoice = 'NATF2';
                                try {
                                    if (is_file($specPath)) {
                                        $rawSpec = @file_get_contents($specPath);
                                        $jSpec = is_string($rawSpec) && $rawSpec !== '' ? json_decode($rawSpec, true) : null;
                                        if (is_array($jSpec)) {
                                            $vt = isset($jSpec['voice']['type']) ? strtolower(trim((string)$jSpec['voice']['type'])) : '';
                                            if (in_array($vt, ['female', 'male', 'animal', 'auto'], true)) $specVoiceType = $vt;
                                            $lg = isset($jSpec['language']) ? trim((string)$jSpec['language']) : '';
                                            if ($lg !== '' && $lg !== 'unknown') $specLanguage = $lg;
                                            $se = isset($jSpec['speech']['engine']) ? strtolower(trim((string)$jSpec['speech']['engine'])) : '';
                                            if (in_array($se, ['classic', 'personaplex'], true)) $specSpeechEngine = $se;
                                            $pv = isset($jSpec['speech']['personaplex_voice']) ? strtoupper(trim((string)$jSpec['speech']['personaplex_voice'])) : '';
                                            if ($pv !== '' && $pv !== 'UNKNOWN') $specPersonaplexVoice = $pv;
                                        }
                                    }
                                } catch (Throwable) {
                                }
                            ?>
                            <div class="card">
                                <div class="img">
                                    <img src="<?php echo htmlspecialchars($avatarUrl, ENT_QUOTES); ?>" alt="Persona">
                                </div>
                                <div class="body">
                                    <div class="label">persona_name</div>
                                    <div class="value"><?php echo htmlspecialchars($personaName, ENT_QUOTES); ?></div>
                                    <div class="label">persona_id</div>
                                    <div class="value"><?php echo htmlspecialchars($personaId, ENT_QUOTES); ?></div>
                                    <div class="label">created_at</div>
                                    <div class="value"><?php echo htmlspecialchars($createdAt, ENT_QUOTES); ?></div>
                                    <div class="card-actions">
                                        <span class="label">realtime</span>
                                    </div>
                                    <div class="card-actions">
                                        <button class="btn secondary small realtime-start" type="button" data-persona="<?php echo htmlspecialchars($personaId, ENT_QUOTES); ?>">Talk</button>
                                    </div>
                                    <iframe
                                      class="realtime-frame"
                                      allow="camera; microphone; autoplay; fullscreen"
                                      referrerpolicy="no-referrer"
                                      loading="lazy"
                                      src="about:blank"
                                      data-src="/hub/genesis/realtime.php?embed=1&view=chat&persona_id=<?php echo rawurlencode($personaId); ?>"
                                      style="display:none; border:0; width:100%; aspect-ratio: 1 / 1; background:#000; border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; margin-top: 10px;"
                                    ></iframe>
                                    <div class="inline-controls">
                                        <select class="card-voice">
                                            <option value="auto" <?php echo $specVoiceType === 'auto' ? 'selected' : ''; ?>>Auto Voice</option>
                                            <option value="female" <?php echo $specVoiceType === 'female' ? 'selected' : ''; ?>>Female</option>
                                            <option value="male" <?php echo $specVoiceType === 'male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="animal" <?php echo $specVoiceType === 'animal' ? 'selected' : ''; ?>>Animal</option>
                                        </select>
                                        <select class="card-language" data-language="<?php echo htmlspecialchars($specLanguage, ENT_QUOTES); ?>"></select>
                                        <select class="card-speech-engine" data-engine="<?php echo htmlspecialchars($specSpeechEngine, ENT_QUOTES); ?>">
                                            <option value="classic" <?php echo $specSpeechEngine === 'classic' ? 'selected' : ''; ?>>Classic</option>
                                            <option value="personaplex" <?php echo $specSpeechEngine === 'personaplex' ? 'selected' : ''; ?>>PersonaPlex</option>
                                        </select>
                                        <select class="card-pp-voice" data-ppvoice="<?php echo htmlspecialchars($specPersonaplexVoice, ENT_QUOTES); ?>">
                                            <?php
                                            $pp = ['NATF0','NATF1','NATF2','NATF3','NATM0','NATM1','NATM2','NATM3','VARF0','VARF1','VARF2','VARF3','VARF4','VARM0','VARM1','VARM2','VARM3','VARM4'];
                                            foreach ($pp as $v) {
                                                $sel = $specPersonaplexVoice === $v ? 'selected' : '';
                                                echo '<option value="' . htmlspecialchars($v, ENT_QUOTES) . '" ' . $sel . '>' . htmlspecialchars($v, ENT_QUOTES) . '</option>';
                                            }
                                            ?>
                                        </select>
                                        <button class="btn secondary small save-spec" type="button" data-persona="<?php echo htmlspecialchars($personaId, ENT_QUOTES); ?>">Save</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="actions">
                        <a class="btn secondary" href="#ai-persona">Create AI Persona</a>
                        <a class="btn" href="/hub/genesis/explanation.php">Continue</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; ?>
    <script>
      (function () {
        async function loadLanguages() {
          var sel = document.querySelector(".ai-language");
          if (!sel) return;
          sel.innerHTML = "";
          try {
            var res = await fetch("/hub/genesis/languages.php", { credentials: "include" });
            var j = await res.json();
            var codes = (j && j.success && j.spoken_langs) ? j.spoken_langs : null;
            var labels = (j && j.success && j.spoken_labels) ? j.spoken_labels : null;
            if (!codes || !Array.isArray(codes)) throw new Error("no_languages");
            var preferred = ["auto","en-US","en-GB","sw-KE","am-ET","ha-NG","yo-NG","ig-NG","zu-ZA","rw-RW","ti-ET","so-SO","wo-SN","fr-FR","ar-SA"];
            var set = {};
            function addOpt(k){
              if (!k || set[k]) return;
              var o = document.createElement("option");
              o.value = k;
              var label = (labels && labels[k]) ? labels[k] : k;
              o.textContent = label;
              sel.appendChild(o);
              set[k]=true;
            }
            preferred.forEach(addOpt);
            codes.forEach(addOpt);
            var defaultVal = set["en-US"] ? "en-US" : (set["auto"] ? "auto" : (codes[0] || "en-US"));
            sel.value = defaultVal;

            var cards = document.querySelectorAll(".card-language");
            cards.forEach(function (cs) {
              cs.innerHTML = "";
              var set2 = {};
              function addOpt2(k) {
                if (!k || set2[k]) return;
                var o2 = document.createElement("option");
                o2.value = k;
                var label2 = (labels && labels[k]) ? labels[k] : k;
                o2.textContent = label2;
                cs.appendChild(o2);
                set2[k] = true;
              }
              preferred.forEach(addOpt2);
              codes.forEach(addOpt2);
              var desired = cs.getAttribute("data-language") || "";
              if (desired && set2[desired]) {
                cs.value = desired;
              } else {
                cs.value = set2["en-US"] ? "en-US" : (set2["auto"] ? "auto" : (codes[0] || "en-US"));
              }
            });
          } catch (e) {
            var o = document.createElement("option");
            o.value = "en-US";
            o.textContent = "Language: English";
            sel.appendChild(o);
          }
        }
        function startRealtime(btn) {
          var card = btn.closest(".card");
          if (!card) return;
          var frame = card.querySelector(".realtime-frame");
          if (!frame) return;
          var src = frame.getAttribute("data-src") || "";
          if (!src) return;
          if (frame.getAttribute("src") !== src) frame.setAttribute("src", src);
          frame.style.display = "block";
          btn.disabled = true;
          btn.textContent = "Realtime Started";
        }

        document.querySelectorAll(".realtime-start").forEach(function (b) {
          b.addEventListener("click", function () { startRealtime(b); });
        });

        async function saveSpec(btn) {
          var card = btn.closest(".card");
          if (!card) return;
          var status = card.querySelector(".preview-status");
          var persona = btn.getAttribute("data-persona") || "";
          var voice = (card.querySelector(".card-voice") || {}).value || "auto";
          var lang = (card.querySelector(".card-language") || {}).value || "en-US";
          var engine = (card.querySelector(".card-speech-engine") || {}).value || "classic";
          var ppVoice = (card.querySelector(".card-pp-voice") || {}).value || "NATF2";
          btn.disabled = true;
          if (status) status.textContent = "Saving…";
          try {
            var body = "persona_id=" + encodeURIComponent(persona)
              + "&voice_type=" + encodeURIComponent(voice)
              + "&language=" + encodeURIComponent(lang)
              + "&speech_engine=" + encodeURIComponent(engine)
              + "&personaplex_voice=" + encodeURIComponent(ppVoice);
            var res = await fetch("/hub/genesis/persona_update.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded" },
              body: body,
              credentials: "include"
            });
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              if (status) status.textContent = (j && j.error) ? ("Save failed: " + j.error) : ("Save failed: " + (txt || ""));
              return;
            }
            if (status) status.textContent = "Saved.";
          } catch (e) {
            if (status) status.textContent = "Save error.";
          } finally {
            btn.disabled = false;
          }
        }

        var saves = document.querySelectorAll(".save-spec");
        saves.forEach(function (b) { b.addEventListener("click", function () { saveSpec(b); }); });

        function syncEngineUI(card) {
          if (!card) return;
          var engineEl = card.querySelector(".card-speech-engine");
          var ppEl = card.querySelector(".card-pp-voice");
          if (!engineEl) return;
          var engine = engineEl.value || "classic";
          if (ppEl) ppEl.style.display = engine === "personaplex" ? "inline-block" : "none";
        }

        document.querySelectorAll(".card").forEach(function (card) {
          var engineEl = card.querySelector(".card-speech-engine");
          if (engineEl) {
            engineEl.addEventListener("change", function () { syncEngineUI(card); });
          }
          syncEngineUI(card);
        });


        async function deletePersona(personaId, personaName) {
          if (!personaId) return;
          if (!confirm("Delete persona '" + personaId + "'? This removes its assets.")) return;
          try {
            var res = await fetch("/hub/genesis/persona_delete.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
              body: "persona_id=" + encodeURIComponent(personaId) + "&persona_name=" + encodeURIComponent(personaName || ""),
              credentials: "include"
            });
            if (res.status === 402) {
              var j402 = null;
              try { j402 = await res.json(); } catch (e) { j402 = null; }
              if (j402 && j402.redirect) {
                window.location.href = j402.redirect;
                return;
              }
            }
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              if (j && j.error) {
                if (j.persona_errors || j.voice_errors) {
                  alert(j.error + "\n" + JSON.stringify({ persona_errors: j.persona_errors || [], voice_errors: j.voice_errors || [] }, null, 2));
                } else {
                  alert(j.error);
                }
              } else {
                alert("Delete failed: " + (txt || ""));
              }
              return;
            }
            window.location.reload();
          } catch (e) {
            alert("Delete error.");
          }
        }

        var dels = document.querySelectorAll(".persona-delete");
        dels.forEach(function (b) {
          b.addEventListener("click", function () {
            deletePersona(b.getAttribute("data-persona-id") || "", b.getAttribute("data-persona-name") || "");
          });
        });

        async function createPersona() {
          var input = document.querySelector(".persona-create-name");
          var name = input ? (input.value || "").trim() : "";
          if (!name && input) {
            name = (input.getAttribute("data-default-name") || "").trim();
          }
          if (!name) { alert("Enter a persona name."); return; }
          try {
            var res = await fetch("/hub/genesis/persona_create.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
              body: "persona_name=" + encodeURIComponent(name),
              credentials: "include"
            });
            if (res.status === 402) {
              var j402 = null;
              try { j402 = await res.json(); } catch (e) { j402 = null; }
              if (j402 && j402.redirect) {
                window.location.href = j402.redirect;
                return;
              }
            }
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              alert((j && j.error) ? j.error : ("Create failed: " + (txt || "")));
              return;
            }
            window.location.reload();
          } catch (e) {
            alert("Create error.");
          }
        }

        var createBtn = document.querySelector(".persona-create-btn");
        if (createBtn) {
          createBtn.addEventListener("click", function () { createPersona(); });
        }

        async function createAiPersona() {
          var nameEl = document.querySelector(".ai-persona-name");
          var descEl = document.querySelector(".ai-persona-desc");
          var voiceEl = document.querySelector(".ai-voice-type");
          var langEl = document.querySelector(".ai-language");
          var trEl = document.querySelector(".ai-translation");
          var vEl = document.querySelector(".ai-vision");
          var hEl = document.querySelector(".ai-hearing");
          var instrEl = document.querySelector(".ai-instruction-backend");
          var memEl = document.querySelector(".ai-memory-mode");
          var statusEl = document.querySelector(".ai-status");

          var name = nameEl ? (nameEl.value || "").trim() : "";
          if (!name && nameEl) {
            name = (nameEl.getAttribute("data-default-name") || "").trim();
          }
          var desc = descEl ? (descEl.value || "").trim() : "";
          var voice = voiceEl ? (voiceEl.value || "auto") : "auto";
          var lang = langEl ? (langEl.value || "en") : "en";
          var tr = trEl && trEl.checked ? "1" : "0";
          var vision = vEl && vEl.checked ? "1" : "0";
          var hearing = hEl && hEl.checked ? "1" : "0";
          var instr = instrEl ? (instrEl.value || "hermes") : "hermes";
          var mem = "realtime";

          if (!name) { alert("Enter a persona name."); return; }
          if (statusEl) statusEl.textContent = "Creating persona…";
          try {
            var body = [
              "persona_name=" + encodeURIComponent(name),
              "persona_description=" + encodeURIComponent(desc),
              "voice_type=" + encodeURIComponent(voice),
              "language=" + encodeURIComponent(lang),
              "translation_enabled=" + encodeURIComponent(tr),
              "vision_enabled=" + encodeURIComponent(vision),
              "hearing_enabled=" + encodeURIComponent(hearing),
              "instruction_backend=" + encodeURIComponent(instr),
              "memory_backend=" + encodeURIComponent(mem)
            ].join("&");
            var res = await fetch("/hub/genesis/persona_create.php", {
              method: "POST",
              headers: { "Content-Type": "application/x-www-form-urlencoded", "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" },
              body: body,
              credentials: "include"
            });
            if (res.status === 402) {
              var j402 = null;
              try { j402 = await res.json(); } catch (e) { j402 = null; }
              if (j402 && j402.redirect) {
                window.location.href = j402.redirect;
                return;
              }
            }
            var txt = await res.text();
            var j = null;
            try { j = JSON.parse(txt); } catch (e) {}
            if (!j || j.success !== true) {
              if (statusEl) statusEl.textContent = (j && j.error) ? ("Create failed: " + j.error) : ("Create failed: " + (txt || ""));
              return;
            }
            if (statusEl) statusEl.textContent = "Created. Reloading…";
            window.location.reload();
          } catch (e) {
            if (statusEl) statusEl.textContent = "Create error.";
          }
        }

        var aiBtn = document.querySelector(".ai-create-btn");
        if (aiBtn) {
          aiBtn.addEventListener("click", function () { createAiPersona(); });
        }

        loadLanguages();
      })();
    </script>
</body>
</html>
