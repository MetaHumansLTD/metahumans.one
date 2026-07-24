<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
cue_autoload('security');
require_once dirname(__DIR__, 2) . '/auth/auth_classes.php';

if (function_exists('security_startSecureSession')) {
    security_startSecureSession();
} elseif (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
$isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
if (!$isKripz) {
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    if ($u !== '' && function_exists('mh_auth_load_user_context')) {
        try { mh_auth_load_user_context($u); } catch (Throwable $e) {}
        $role = isset($_SESSION['mh_auth_role']) ? strtolower((string)$_SESSION['mh_auth_role']) : '';
        $isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
    }
    if (!$isKripz) {
        http_response_code(403);
        echo 'Access denied';
        exit;
    }
}

$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
$verifiedUntil = isset($_SESSION['mh_id_identifiers_verified_until']) ? (int)$_SESSION['mh_id_identifiers_verified_until'] : 0;
$isVerified = ($verifiedUntil > time());
$csrf = isset($_SESSION['mh_id_identifiers_csrf']) ? (string)$_SESSION['mh_id_identifiers_csrf'] : '';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/gear/settings/id_identifiers.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/gear/settings/id_identifiers.php';
}
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_id_identifiers_csrf'] = $csrf;
}

$errorMsg = isset($_SESSION['mh_id_identifiers_error']) ? (string)$_SESSION['mh_id_identifiers_error'] : '';
unset($_SESSION['mh_id_identifiers_error']);
if (!$isVerified && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postUser = isset($_POST['username']) ? trim((string)$_POST['username']) : '';
    $postPin = isset($_POST['pin']) ? trim((string)$_POST['pin']) : '';
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';

    if (!hash_equals($csrf, $postCsrf)) {
        $errorMsg = 'Invalid request';
    } elseif ($postUser === '' || $postPin === '') {
        $errorMsg = 'Username and PIN are required';
    } elseif ($username !== '' && strcasecmp($postUser, $username) !== 0) {
        $errorMsg = 'Username mismatch';
    } elseif (!preg_match('/^[0-9]{5,}$/', $postPin)) {
        $errorMsg = 'Invalid PIN format';
    } else {
        try {
            $pinBackup = new MetaPinBackup();
            $pinBackup->verifyPin($postUser, $postPin);
            cue_autoload('database');
            $pdoBio = database_getConnectionById('biometrics');
            $rawRole = '';
            try {
                $stmt = $pdoBio->prepare("SELECT role AS r FROM users WHERE username = ? LIMIT 1");
                $stmt->execute([$postUser]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $rawRole = isset($row['r']) ? strtolower((string)$row['r']) : '';
            } catch (Throwable $e) {
                $rawRole = '';
            }
            if ($rawRole === '') {
                try {
                    $stmt = $pdoBio->prepare("SELECT roles AS r FROM users WHERE username = ? LIMIT 1");
                    $stmt->execute([$postUser]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $rawRole = isset($row['r']) ? strtolower((string)$row['r']) : '';
                } catch (Throwable $e) {
                    $rawRole = '';
                }
            }
            if (strpos($rawRole, 'kripzmaster') === false) {
                $errorMsg = 'Access denied';
            } else {
                $_SESSION['mh_id_identifiers_verified_until'] = time() + 900;
                $isVerified = true;
            }
        } catch (Throwable $e) {
            $errorMsg = 'Access denied';
        }
    }

    if ($isVerified) {
        header('Location: ' . $requestUri, true, 303);
        exit;
    }

    $_SESSION['mh_id_identifiers_error'] = $errorMsg;
    header('Location: ' . $requestUri, true, 303);
    exit;
}

if (!$isVerified) {
    http_response_code(401);
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Identifier Rules - Verify</title>
        <style>
            html, body { background:#1a1a1a !important; }
            body { min-height: 100vh; background:#1a1a1a !important; color:#e6f6ff; font-family: Arial, sans-serif; margin:0; padding:20px; }
            .wrap { max-width: 520px; margin: 0 auto; }
            h1 { margin: 0 0 10px 0; color:#00d4ff; }
            .muted { color:#9aa; font-size: 12px; margin-bottom: 16px; }
            .card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 12px; padding: 16px; }
            label { display:block; margin: 12px 0 6px; color:#cfefff; }
            input { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
            button { margin-top: 14px; width: 100%; padding: 12px 14px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: #0aa0b6; color:#fff; cursor:pointer; font-weight:700; }
            .error { margin-top: 12px; color: #ffb3b3; }
        </style>
    </head>
    <body>
        <div class="wrap">
            <h1>Verify Access</h1>
            <div class="muted">KripzMasters only. Please confirm username + PIN.</div>
            <div class="card">
                <form method="post" action="<?php echo htmlspecialchars($requestUri, ENT_QUOTES); ?>" autocomplete="off">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
                    <label>Username</label>
                    <input type="text" name="username" value="<?php echo htmlspecialchars($username, ENT_QUOTES); ?>" autocomplete="username" required />
                    <label>PIN</label>
                    <input type="password" name="pin" inputmode="numeric" pattern="[0-9]*" minlength="5" autocomplete="current-password" required />
                    <button type="submit">Unlock</button>
                    <?php if ($errorMsg !== ''): ?>
                        <div class="error"><?php echo htmlspecialchars($errorMsg, ENT_QUOTES); ?></div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$content = <<<MD
Reference:
- This page is the canonical source for identifier rules. Do not depend on markdown files on disk.
- Biometrics boundary is enforced by the DB router:
  - `/auth/*` and `/control/*` may access biometrics (auth/security only).
  - `/hub/*` and `/studio/*` must not open biometrics connections (no allowlist exceptions). Use tenant DB routing and session-derived auth fields.

## Canonical Identifier Spec (v1)

Character set:
- Identifiers used in URLs, DB keys, payload tags, and filesystem paths must be representable as: [a-zA-Z0-9:_\\-\\. ] (spaces allowed only for human-facing names; NOT allowed for canonical ids).
- Canonical ids must be normalized to a no-space form using _ for whitespace when derived from display names.

Length limits:
- tenant_id: 3–128
- user_id: 1–128
- persona_id: 3–128
- meta_human_id: 5–160
- device_id: 6–160
- session_id: 16–256
- room_id: 1–64
- bot_id: 8–160
- agent_name: 1–128

Formats:
- user_id:
  - Primary: biometrics.users.username (string). Normalized as-is for canonical references (case preserved).
  - Note: tenant-facing code should treat user_id as an identity string and must not query biometrics directly to resolve it.
- tenant_id:
  - Primary: user:<username> (case preserved after user:)
  - Optional org: org:<slug>
- persona_id:
  - Current (deployed): MH-<username> unless explicitly selected.
  - Rule: no spaces; derive using _ for whitespace when derived from display persona names.
- meta_human_id:
  - Current (deployed): meta:<sanitized persona_id>
  - Rule: treat this as v1 derived identity; migrate to a stored id when persona-context becomes authoritative.
- device_id:
  - Primary: biometrics.users.device_id (string)
  - Filesystem-safe derived form: device:<sanitized>
- session_id:
  - Primary: PHP session_id() string, stored for audit and correlation only (not used as an isolation boundary).
- room_id:
  - Realtime meeting room identifier used by PlugNMeet/LiveKit.
  - Character set: [A-Za-z0-9_-] only.
  - Length: 1–64.
  - Source: `mh_normalize_room()` output and meeting records `mh_meetings.room_id`.
  - Rule: room_id is NOT an isolation boundary. Always scope operations by tenant_id/persona_id; room_id is only a session locator.
- bot_id:
  - Programmatic participant identity for realtime agents ("bot").
  - Canonical format: mh_agent_<12 hex> (example: mh_agent_3f2a91b2c4d8).
  - Alternative format (explicit prefix): bot:<slug> where <slug> is sanitized [A-Za-z0-9:_\\-\\.].
  - Rule: bot_id must never overlap with user_id. Do not mint bot identities that match existing usernames.
  - Rule: bot_id is NOT an isolation boundary; it is a participant identity only.
- agent_name:
  - Human-facing participant label for an agent/bot in realtime (display name).
  - May contain spaces; must not be used as a canonical id, DB key, or filesystem path segment.
  - Rule: stable identity must use bot_id (or another canonical id), not agent_name.

Reserved prefixes (do not repurpose):
- user:
- org:
- meta:
- device:
- bot:

Vector naming and tagging:
- Collection naming: mh_shard_<N> (sharded by hash(tenant_id) mod shard_count).
- Mandatory payload tags for every point:
  - tenant_id (required)
  - persona_id (recommended)
  - meta_human_id (required for agent memory)

Graph naming and tagging:
- Multi-tenant tagging model:
  - Every node/edge carries tenant_id and meta_human_id.
  - Memory nodes keyed by (tenant_id, meta_human_id, memory_id).
  - Entity nodes keyed by (tenant_id, meta_human_id, entity_id).

Rotation and rename policy:
- If a username changes:
  - tenant_id changes (user:<username>) and requires an explicit migration process for filesystem + vector + graph.
- If a persona display name changes:
  - persona_id may change if derived from display name; do not treat persona_id as immutable unless backed by a stable mapping.
- meta_human_id should be treated as the stable agent identity; if derived from persona name, keep a cached value and migrate explicitly on rename (future: store in persona-context.json).

LoRA_id
- Auto-generated identifier for company profiles.
- Format: <first 4 alphanumeric characters of Company Name>-<5 digits>
- Example: Meta-12345
- Not editable once issued.

Owner_id
- Auto-generated identifier for an entity that can hold shares (person or company).
- Separate from username and LoRA_id.
- Format: OWN-<12 uppercase alphanumeric characters>
- Example: OWN-7F3A91B2C4D8
- Not editable once issued.
MD;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Identifier Rules</title>
    <style>
        html, body { background:#1a1a1a !important; }
        body { min-height: 100vh; background:#1a1a1a !important; color:#e6f6ff; font-family: Arial, sans-serif; margin:0; padding:20px; }
        .wrap { max-width: 1100px; margin: 0 auto; }
        h1 { margin: 0 0 10px 0; color:#00d4ff; }
        .muted { color:#9aa; font-size: 12px; margin-bottom: 16px; }
        pre { white-space: pre-wrap; word-break: break-word; background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 12px; padding: 16px; line-height: 1.35; }
        a { color:#00d4ff; text-decoration:none; }
    </style>
</head>
<body>
    <div class="wrap">
        <h1>Identifier Rules</h1>
        <div class="muted">Restricted admin view.</div>
        <pre><?php echo htmlspecialchars($content, ENT_QUOTES); ?></pre>
    </div>
</body>
</html>
