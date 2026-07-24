<?php
declare(strict_types=1);

require_once __DIR__ . '/../widget/_lib.php';
require_once __DIR__ . '/../../auth/asset_signing.php';

function mh_safe_id(string $s): string
{
    $s = trim((string)$s);
    $s = strtolower(preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s));
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'default';
}

function mh_tenant_id(string $username): string
{
    $t = isset($_SESSION['mh_tenant_id']) && is_string($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
    if ($t === '') $t = 'user:' . $username;
    return $t;
}

function mh_apply_tenant(string $tenantId): void
{
    $tenantProvisioning = __DIR__ . '/../../auth/tenant_provisioning.php';
    if ($tenantId !== '' && !function_exists('mh_apply_tenant_context') && is_file($tenantProvisioning)) {
        require_once $tenantProvisioning;
    }
    if ($tenantId !== '' && function_exists('mh_apply_tenant_context')) {
        try { mh_apply_tenant_context($tenantId); } catch (Throwable $e) {}
    }
}

function mh_rmdir_recursive(string $path): void
{
    if (!is_dir($path)) return;
    $items = scandir($path);
    if (!is_array($items)) return;
    foreach ($items as $it) {
        if (!is_string($it) || $it === '.' || $it === '..') continue;
        $p = $path . '/' . $it;
        if (is_link($p) || is_file($p)) {
            @unlink($p);
        } elseif (is_dir($p)) {
            mh_rmdir_recursive($p);
        }
    }
    @rmdir($path);
}

function mh_rmdir_recursive_status(string $path, array &$errors): bool
{
    if (!is_dir($path)) return true;
    $items = scandir($path);
    if (!is_array($items)) {
        $errors[] = ['path' => $path, 'op' => 'scandir_failed'];
        return false;
    }
    foreach ($items as $it) {
        if (!is_string($it) || $it === '.' || $it === '..') continue;
        $p = $path . '/' . $it;
        if (is_link($p) || is_file($p)) {
            if (!@unlink($p) && (is_file($p) || is_link($p))) {
                $errors[] = ['path' => $p, 'op' => 'unlink_failed'];
            }
        } elseif (is_dir($p)) {
            mh_rmdir_recursive_status($p, $errors);
        }
    }
    if (!@rmdir($path) && is_dir($path)) {
        $errors[] = ['path' => $path, 'op' => 'rmdir_failed'];
        return false;
    }
    return !is_dir($path);
}

function mh_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_http_get_json_with_session(string $url, int $timeoutSec = 6): ?array
{
    $sid = session_id();
    $sn = session_name();
    if ($sid === '' || $sn === '') return null;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Host: metahumans.one',
        'Cookie: ' . $sn . '=' . $sid,
        'User-Agent: mh-genesis-delete/1',
    ]);
    $resp = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($resp) || $resp === '' || $httpCode < 200 || $httpCode >= 300) return null;
    $j = json_decode($resp, true);
    return is_array($j) ? $j : null;
}

$ctx = mh_widget_require_auth();
$username = (string)($ctx['username'] ?? '');
$tenantId = (string)($ctx['tenant_id'] ?? '');
$tenantSafe = mh_widget_sanitize_id(strtolower($tenantId));
if ($tenantSafe === '' || $tenantSafe === 'unknown') {
    mh_widget_json(['success' => false, 'error' => 'invalid_tenant_id'], 500);
    exit;
}

$personaId = isset($_POST['persona_id']) ? mh_safe_id((string)$_POST['persona_id']) : '';
if ($personaId === '') $personaId = 'default';
$personaNameIn = isset($_POST['persona_name']) ? trim((string)$_POST['persona_name']) : '';

$personaRootBase = '/data/tenants/' . $tenantSafe . '/personas';
$personaRoot = $personaRootBase . '/' . $personaId;

$debug = mh_http_get_json_with_session('http://127.0.0.1/hub/genesis/persona-images.php?persona=' . rawurlencode($personaId) . '&debug=1', 6);
if ((!is_array($debug) || empty($debug['avatar_exists'])) && $personaNameIn !== '') {
    $debug = mh_http_get_json_with_session('http://127.0.0.1/hub/genesis/persona-images.php?persona=' . rawurlencode($personaNameIn) . '&debug=1', 6);
}
$personaDir = '';
if (is_array($debug) && isset($debug['persona_dir']) && is_string($debug['persona_dir']) && $debug['persona_dir'] !== '') {
    $personaDir = (string)$debug['persona_dir'];
}
if ($personaDir !== '' && is_dir($personaDir)) {
    $personaRoot = $personaDir;
    $parts = explode('/', trim((string)$personaRoot, '/'));
    $last = is_array($parts) && count($parts) > 0 ? (string)$parts[count($parts) - 1] : '';
    if ($last !== '') $personaId = mh_safe_id($last);
}
$personaName = $personaNameIn !== '' ? $personaNameIn : $personaId;
if (is_dir($personaRoot)) {
    $manifestPath = $personaRoot . '/assets/manifest.json';
    if ($personaNameIn === '' && is_file($manifestPath)) {
        $raw = @file_get_contents($manifestPath);
        $j = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($j)) {
            $pn = isset($j['persona_name']) ? trim((string)$j['persona_name']) : '';
            if ($pn !== '') $personaName = $pn;
        }
    }
}

try {
    mh_apply_tenant($tenantId);
} catch (Throwable $e) {
}

try {
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (function_exists('database_getContextAwareConnection')) {
        $pdo = database_getContextAwareConnection();
        if ($pdo instanceof PDO) {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec("CREATE TABLE IF NOT EXISTS mh_personas (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                owner_username VARCHAR(255) NOT NULL,
                persona_name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_owner_persona (owner_username, persona_name),
                KEY idx_owner (owner_username)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $pdo->prepare("SELECT persona_name FROM mh_personas WHERE owner_username = ?");
            $stmt->execute([$username]);
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $toDelete = [];
            foreach ($rows as $row) {
                $pn = is_string($row) ? trim($row) : '';
                if ($pn === '') continue;
                if ($pn === $personaName) $toDelete[] = $pn;
                if ($pn === $personaId) $toDelete[] = $pn;
                if (mh_safe_id($pn) === $personaId) $toDelete[] = $pn;
            }
            $toDelete = array_values(array_unique($toDelete));
            if (!$toDelete && $personaName !== '') $toDelete[] = $personaName;
            $del = $pdo->prepare("DELETE FROM mh_personas WHERE owner_username = ? AND persona_name = ?");
            foreach ($toDelete as $pn) {
                $del->execute([$username, $pn]);
            }
        }
    }
} catch (Throwable $e) {
}

try {
    $regPath = __DIR__ . '/../../auth/persona_registry.php';
    if (is_file($regPath)) {
        require_once $regPath;
        $pdoReg = mh_persona_registry_pdo();
        mh_persona_registry_release($pdoReg, $username, $personaName);
        if ($personaId !== '' && $personaId !== $personaName) {
            mh_persona_registry_release($pdoReg, $username, $personaId);
        }
    }
} catch (Throwable $e) {
}

try {
    $voiceDir = '/data/tenants/' . $tenantSafe . '/voices/' . $personaId;
    if (is_dir($voiceDir)) {
        $errs = [];
        mh_rmdir_recursive_status($voiceDir, $errs);
    }
} catch (Throwable $e) {
}

$errsPersona = [];
$errsVoice = [];
try {
    if (is_dir($personaRootBase)) {
        $entries = scandir($personaRootBase);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (!is_string($e) || $e === '.' || $e === '..') continue;
                $dir = $personaRootBase . '/' . $e;
                if (!is_dir($dir)) continue;
                if (mh_safe_id($e) !== $personaId) continue;
                mh_rmdir_recursive_status($dir, $errsPersona);
            }
        }
    }
} catch (Throwable $e) {
}

try {
    $voicesBase = '/data/tenants/' . $tenantSafe . '/voices';
    if (is_dir($voicesBase)) {
        $entries = scandir($voicesBase);
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if (!is_string($e) || $e === '.' || $e === '..') continue;
                $dir = $voicesBase . '/' . $e;
                if (!is_dir($dir)) continue;
                if (mh_safe_id($e) !== $personaId) continue;
                mh_rmdir_recursive_status($dir, $errsVoice);
            }
        }
    }
} catch (Throwable $e) {
}

$stillPersona = is_dir($personaRoot);
$stillVoice = is_dir('/data/tenants/' . $tenantSafe . '/voices/' . $personaId);
if ($stillPersona || $stillVoice) {
    mh_widget_json([
        'success' => false,
        'error' => 'delete_failed',
        'persona_id' => $personaId,
        'persona_root' => $personaRoot,
        'persona_exists' => $stillPersona,
        'voice_exists' => $stillVoice,
        'persona_errors' => $errsPersona,
        'voice_errors' => $errsVoice,
        'hint' => 'Check filesystem permissions/ownership under /data/tenants/<tenant>/personas and /data/tenants/<tenant>/voices.',
    ], 500);
    exit;
}

mh_widget_json(['success' => true, 'persona_id' => $personaId, 'persona_root' => $personaRoot]);
exit;
