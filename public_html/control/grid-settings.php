<?php
require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../gear/grid/sr_client.php';

if (function_exists('cue_autoload')) {
    cue_autoload('paths');
    cue_autoload('security');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    header('Location: /auth/login.php');
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
if (stripos($role, 'kripzmaster') === false) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

function mh_grid_settings_enc_key(): string
{
    $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
    $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
    return is_string($raw) ? trim($raw) : '';
}

function mh_grid_settings_encrypt(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') return '';
    $key = mh_grid_settings_enc_key();
    if ($key === '' || !function_exists('security_encryptValue')) return '';
    $enc = security_encryptValue($plain, $key);
    return is_string($enc) ? $enc : '';
}

function mh_grid_settings_decrypt(string $enc): string
{
    $enc = trim($enc);
    if ($enc === '') return '';
    $key = mh_grid_settings_enc_key();
    if ($key === '' || !function_exists('security_decryptValue')) return '';
    $plain = security_decryptValue($enc, $key);
    return is_string($plain) ? $plain : '';
}

function mh_grid_settings_read_raw(): array
{
    $p = mh_grid_cfg_path();
    if (!is_file($p) || !is_readable($p)) return [];
    $raw = @file_get_contents($p);
    $d = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($d) ? $d : [];
}

function mh_grid_settings_write_raw(array $cfg): void
{
    $p = mh_grid_cfg_path();
    $dir = dirname($p);
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('encode_failed');
    }
    if (@file_put_contents($p, $json . "\n") === false) {
        throw new RuntimeException('write_failed');
    }
    @chmod($p, 0600);
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES); }

$cfgRaw = mh_grid_settings_read_raw();

$baseUrl = isset($cfgRaw['base_url']) && is_string($cfgRaw['base_url']) ? (string)$cfgRaw['base_url'] : '';
$allowlistArr = isset($cfgRaw['allowlist']) && is_array($cfgRaw['allowlist']) ? $cfgRaw['allowlist'] : [];
$allowlistText = implode("\n", array_values(array_filter(array_map(function ($v) {
    return is_string($v) ? trim($v) : '';
}, $allowlistArr), function ($v) { return is_string($v) && $v !== ''; })));

$pubKeyPem = isset($cfgRaw['webhook_public_key_pem']) && is_string($cfgRaw['webhook_public_key_pem']) ? (string)$cfgRaw['webhook_public_key_pem'] : '';
$trustedQuorumKeysArr = isset($cfgRaw['trusted_enclave_quorum_public_keys']) && is_array($cfgRaw['trusted_enclave_quorum_public_keys'])
    ? $cfgRaw['trusted_enclave_quorum_public_keys']
    : [];
$trustedQuorumKeysText = implode("\n", array_values(array_filter(array_map(function ($v) {
    return is_string($v) ? strtolower(trim($v)) : '';
}, $trustedQuorumKeysArr), function ($v) { return is_string($v) && $v !== ''; })));

$tokenIdPlain = '';
$tokenIdEnc = isset($cfgRaw['token_id']) && is_string($cfgRaw['token_id']) ? trim((string)$cfgRaw['token_id']) : '';
if ($tokenIdEnc !== '') $tokenIdPlain = mh_grid_settings_decrypt($tokenIdEnc);

$clientSecretPlain = '';
$clientSecretEnc = isset($cfgRaw['client_secret']) && is_string($cfgRaw['client_secret']) ? trim((string)$cfgRaw['client_secret']) : '';
if ($clientSecretEnc !== '') $clientSecretPlain = mh_grid_settings_decrypt($clientSecretEnc);

$message = '';
$error = '';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/grid-settings.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/grid-settings.php';
}
$flash = $_SESSION['mh_grid_settings_flash'] ?? null;
if (is_array($flash)) {
    $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $error = isset($flash['error']) && is_string($flash['error']) ? $flash['error'] : '';
}
unset($_SESSION['mh_grid_settings_flash']);
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = isset($_POST['action']) && is_string($_POST['action']) ? trim((string)$_POST['action']) : 'save';

    try {
        if ($action === 'sync_webhook' || $action === 'test_webhook') {
            $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? trim((string)$_SERVER['HTTP_HOST']) : 'metahumans.one';
            $hookUrl = 'https://' . $host . '/gear/grid/webhook.php';
            $cfg = mh_grid_read_cfg();

            if ($action === 'sync_webhook') {
                $resp = mh_grid_http_request($cfg, 'PATCH', '/config', [
                    'json' => [
                        'webhookEndpoint' => $hookUrl,
                    ],
                ]);
                if (($resp['ok'] ?? false) === true) {
                    $message = 'Webhook endpoint synced';
                } else {
                    $error = 'Webhook endpoint sync failed';
                }
            } else {
                $resp = mh_grid_http_request($cfg, 'POST', '/webhooks/test');
                if (($resp['ok'] ?? false) === true) {
                    $message = 'Test webhook triggered';
                } else {
                    $error = 'Test webhook failed';
                }
            }
        } else {
            $baseUrl = trim((string)($_POST['base_url'] ?? $baseUrl));
            $allowlistText = (string)($_POST['allowlist'] ?? $allowlistText);
            $pubKeyPem = (string)($_POST['webhook_public_key_pem'] ?? $pubKeyPem);
            $trustedQuorumKeysText = (string)($_POST['trusted_enclave_quorum_public_keys'] ?? $trustedQuorumKeysText);

            $tokenIdPosted = trim((string)($_POST['token_id'] ?? ''));
            $clientSecretPosted = trim((string)($_POST['client_secret'] ?? ''));

            $lines = preg_split('/\r?\n/', (string)$allowlistText);
            $allow = [];
            if (is_array($lines)) {
                foreach ($lines as $ln) {
                    $s = trim((string)$ln);
                    if ($s === '') continue;
                    $allow[] = $s;
                }
            }

            $trustedQuorumKeys = [];
            $trustedLines = preg_split('/\r?\n/', (string)$trustedQuorumKeysText);
            if (is_array($trustedLines)) {
                foreach ($trustedLines as $ln) {
                    $s = strtolower(trim((string)$ln));
                    if ($s === '' || (strlen($s) % 2) !== 0 || !preg_match('/^[0-9a-f]+$/', $s)) {
                        continue;
                    }
                    $trustedQuorumKeys[] = $s;
                }
                $trustedQuorumKeys = array_values(array_unique($trustedQuorumKeys));
            }

            $cfgRaw['base_url'] = $baseUrl;
            $cfgRaw['allowlist'] = $allow;
            $cfgRaw['webhook_public_key_pem'] = trim((string)$pubKeyPem);
            $cfgRaw['trusted_enclave_quorum_public_keys'] = $trustedQuorumKeys;

            if ($tokenIdPosted !== '') {
                $enc = mh_grid_settings_encrypt($tokenIdPosted);
                if ($enc !== '') {
                    $cfgRaw['token_id'] = $enc;
                    $tokenIdPlain = $tokenIdPosted;
                }
            }
            if ($clientSecretPosted !== '') {
                $enc = mh_grid_settings_encrypt($clientSecretPosted);
                if ($enc !== '') {
                    $cfgRaw['client_secret'] = $enc;
                    $clientSecretPlain = $clientSecretPosted;
                }
            }

            mh_grid_settings_write_raw($cfgRaw);
            $message = 'Saved';
        }
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
    if (!headers_sent()) {
        $_SESSION['mh_grid_settings_flash'] = [
            'message' => $message,
            'error' => $error,
        ];
        header('Location: ' . $requestUri, true, 303);
        exit;
    }
}

$tokenSet = ($tokenIdPlain !== '');
$tokenSuffix = $tokenSet ? substr($tokenIdPlain, -4) : '';
$secretSet = ($clientSecretPlain !== '');
$secretSuffix = $secretSet ? substr($clientSecretPlain, -4) : '';
$cfgPath = mh_grid_cfg_path();

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (dirname(__DIR__) . '/templates');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Grid Settings</title>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-head.php')) include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
<style>
  .mh-page{max-width:1100px;margin:0 auto;padding:18px 20px}
  .mh-page-header{display:flex;flex-direction:column;gap:12px}
  .mh-page-title{margin:0}
  .mh-page-actions{display:flex;gap:10px;flex-wrap:wrap}
  .btn{padding:12px 16px;border-radius:10px;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:8px;transition:all .18s ease;cursor:pointer}
  .btn-primary{background:linear-gradient(135deg,rgba(var(--theme-primary-rgb),.95),rgba(var(--theme-secondary-rgb),.95));color:#000;border:0}
  .btn-secondary{background:rgba(255,255,255,.06);border:1px solid rgba(var(--theme-primary-rgb),.35);color:var(--theme-text,#e8eefc)}
  .btn-secondary:hover{background:rgba(var(--theme-primary-rgb),.12);border-color:rgba(var(--theme-primary-rgb),.55)}
  .btn-primary:hover{transform:translateY(-1px);box-shadow:0 10px 28px rgba(var(--theme-primary-rgb),.18)}
  .card{background:rgba(255,255,255,.05);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:14px;margin:12px 0}
  .notice{background:rgba(var(--theme-primary-rgb),.08);border:1px solid rgba(var(--theme-primary-rgb),.18);border-radius:14px;padding:12px 14px;margin-top:12px}
  .notice.error{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.35)}
  .notice.success{background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.35)}
  .mh-help{font-size:12px;opacity:.8}
  .mh-code{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}
  .form-input,.form-select,.form-textarea{width:100%;padding:12px 15px;border:1px solid rgba(var(--theme-primary-rgb),0.3);border-radius:10px;background:rgba(255,255,255,0.06);color:var(--theme-text,#e8eefc);font-size:14px;transition:all 0.3s ease;box-sizing:border-box}
  .form-input:focus,.form-select:focus,.form-textarea:focus{outline:none;border-color:var(--theme-primary);box-shadow:0 0 15px rgba(var(--theme-primary-rgb),0.25)}
  .mh-grid{display:grid;gap:10px}
  .mh-row{display:grid;gap:6px}
  .mh-actions{display:flex;gap:10px;flex-wrap:wrap}
  @media (min-width: 900px){
    .mh-page-header{flex-direction:row;align-items:flex-end;justify-content:space-between}
  }
</style>
</head>
<body>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-start.php')) include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content mh-page">
  <div class="mh-page-header">
    <h1 class="mh-page-title">Grid Settings</h1>
    <div class="mh-page-actions">
      <a class="btn btn-secondary" href="/hub/">Hub</a>
      <a class="btn btn-secondary" href="/gear/grid/health.json.php">Grid Health</a>
      <a class="btn btn-secondary" href="/control/grid-webhooks.php">Grid Webhooks</a>
      <a class="btn btn-secondary" href="/control/grid/reprovision.php">Tenant Recovery</a>
    </div>
  </div>

  <?php if ($message !== ''): ?><div class="notice success"><?php echo h($message); ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="notice error"><?php echo h($error); ?></div><?php endif; ?>

  <form method="post" action="" style="margin-top:14px">
    <div class="card">
      <div class="mh-row">
        <div class="mh-help">Config path</div>
        <div class="mh-code"><code><?php echo h($cfgPath); ?></code></div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0">Platform Credentials</h2>
      <div class="mh-grid">
        <div class="mh-row">
          <div class="mh-help">Token ID (stored encrypted; leave blank to keep existing)</div>
          <input class="form-input" name="token_id" value="" placeholder="<?php echo $tokenSet ? 'set (…' . h($tokenSuffix) . ')' : 'not set'; ?>">
        </div>
        <div class="mh-row">
          <div class="mh-help">Client Secret (stored encrypted; leave blank to keep existing)</div>
          <input class="form-input" name="client_secret" value="" placeholder="<?php echo $secretSet ? 'set (…' . h($secretSuffix) . ')' : 'not set'; ?>">
        </div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0">Endpoints</h2>
      <div class="mh-grid">
        <div class="mh-row">
          <div class="mh-help">Base URL</div>
          <input class="form-input" name="base_url" value="<?php echo h($baseUrl); ?>" placeholder="https://api.lightspark.com/grid/2025-10-13">
        </div>
        <div class="mh-row">
          <div class="mh-help">Allowlist (one per line, exact paths; supports /* suffix)</div>
          <textarea class="form-textarea mh-code" name="allowlist" rows="8"><?php echo h($allowlistText); ?></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0">Webhook Verification</h2>
      <div class="mh-grid">
        <div class="mh-row">
          <div class="mh-help">Grid public key PEM (X-Grid-Signature verification)</div>
          <textarea class="form-textarea mh-code" name="webhook_public_key_pem" rows="10"><?php echo h($pubKeyPem); ?></textarea>
        </div>
        <div class="mh-help">Webhook endpoint to configure in Grid: <span class="mh-code"><code>https://<?php echo h((string)($_SERVER['HTTP_HOST'] ?? 'metahumans.one')); ?>/gear/grid/webhook.php</code></span></div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0">OTP Bundle Verification</h2>
      <div class="mh-grid">
        <div class="mh-row">
          <div class="mh-help">Trusted Grid enclave quorum public keys (hex, one per line). Leave this blank until Lightspark provides the official values. Do not pin a key copied from a live <code>otpEncryptionTargetBundle</code>. When configured, the browser requires <code>otpEncryptionTargetBundle.enclaveQuorumPublic</code> to match one of these keys before encrypting the OTP.</div>
          <textarea class="form-textarea mh-code" name="trusted_enclave_quorum_public_keys" rows="6"><?php echo h($trustedQuorumKeysText); ?></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <h2 style="margin:0 0 10px 0">Tenant Recovery</h2>
      <div class="notice">
        Tenant-specific Grid wallet recovery now lives on its own admin page so it is separated from platform configuration. Use the dedicated recovery flow when a tenant's stale <code>EMAIL_OTP</code> bootstrap credential can no longer be repaired in place.
      </div>
      <div class="mh-actions" style="margin-top:12px">
        <a class="btn btn-secondary" href="/control/grid/reprovision.php">Open Tenant Recovery</a>
      </div>
    </div>

    <div class="mh-actions">
      <button class="btn btn-secondary" type="submit" name="action" value="sync_webhook">Sync Webhook Endpoint</button>
      <button class="btn btn-secondary" type="submit" name="action" value="test_webhook">Send Test Webhook</button>
      <button class="btn btn-primary" type="submit">Save</button>
      <a class="btn btn-secondary" href="/control/grid-webhooks.php">Browse Webhooks</a>
    </div>
  </form>
</main>
<?php if (is_file($templatesPath . '/global-ui/includes/complete-body-end.php')) include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
</body>
</html>
