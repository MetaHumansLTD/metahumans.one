<?php
declare(strict_types=1);

function mh_multica_cfg_path(): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    if ($base === '') $base = '/data';
    return rtrim($base, '/') . '/config/brain/multica.json';
}

function mh_multica_read_cfg(): array
{
    $p = mh_multica_cfg_path();
    if (!is_file($p)) return [];
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_multica_write_cfg(array $cfg): void
{
    $p = mh_multica_cfg_path();
    $dir = dirname($p);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('multica_cfg_encode_failed');
    }
    if (file_put_contents($p, $json . "\n") === false) {
        throw new RuntimeException('multica_cfg_write_failed');
    }
    @chmod($p, 0600);
}

function mh_multica_config(): array
{
    $cfg = mh_multica_read_cfg();
    $uiUrl = isset($cfg['ui_url']) && is_string($cfg['ui_url']) ? trim((string)$cfg['ui_url']) : '';
    $apiUrl = isset($cfg['api_url']) && is_string($cfg['api_url']) ? trim((string)$cfg['api_url']) : '';
    $mode = isset($cfg['mode']) && is_string($cfg['mode']) ? trim((string)$cfg['mode']) : 'cloud';
    $runtimeLabel = isset($cfg['runtime_label']) && is_string($cfg['runtime_label']) ? trim((string)$cfg['runtime_label']) : 'metahumans.one';

    if ($mode === '') $mode = 'cloud';
    if ($runtimeLabel === '') $runtimeLabel = 'metahumans.one';

    return [
        'mode' => $mode,
        'ui_url' => $uiUrl,
        'api_url' => $apiUrl,
        'runtime_label' => $runtimeLabel,
        'cfg_path' => mh_multica_cfg_path(),
    ];
}

function mh_multica_find_bin(): string
{
    $candidates = [
        '/usr/local/bin/multica',
        '/usr/bin/multica',
        dirname(__DIR__) . '/gear/multica/bin/multica',
    ];
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && is_file($p) && is_executable($p)) {
            return $p;
        }
    }
    return '';
}

function mh_multica_sso_secret(): string
{
    $env = getenv('MH_SSO_SECRET');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }

    $p = '/data/config/brain/multica.selfhost.env';
    $raw = @file_get_contents($p);
    if (is_string($raw) && $raw !== '') {
        $lines = preg_split("/\\r\\n|\\n|\\r/", $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim((string)$line);
            if ($line === '' || str_starts_with($line, '#')) continue;
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) continue;
            $k = trim((string)$parts[0]);
            if ($k !== 'MH_SSO_SECRET') continue;
            $v = trim((string)$parts[1]);
            if ($v !== '') return $v;
        }
    }

    $p = '/data/security/app.key';
    $raw = @file_get_contents($p);
    $key = is_string($raw) ? trim($raw) : '';
    return $key !== '' ? $key : '';
}

function mh_multica_selfhost_origin(): string
{
    $host = isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one';
    if ($host === '') $host = 'metahumans.one';
    if (str_contains($host, ':') && !str_starts_with($host, '[')) {
        $host = explode(':', $host, 2)[0];
        if ($host === '') $host = 'metahumans.one';
    }
    return 'https://' . $host . ':8445';
}

function mh_multica_workspace_slug(string $tenantId): string
{
    $tenantId = trim($tenantId);
    $base = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $tenantId) ?? '');
    $base = trim($base, '-');
    if ($base === '') $base = 'tenant';
    if (strlen($base) > 30) $base = substr($base, 0, 30);
    $hash = substr(hash('sha256', $tenantId), 0, 6);
    return $base . '-' . $hash;
}

function mh_multica_sso_signature(int $ts, string $tenantId, string $username): string
{
    $secret = mh_multica_sso_secret();
    $msg = (string)$ts . "\n" . $tenantId . "\n" . $username;
    return hash_hmac('sha256', $msg, $secret);
}

function mh_multica_provision_tenant(string $tenantId, string $username = ''): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') return ['success' => false, 'error' => 'tenant_id_required'];
    $username = trim($username);
    if ($username === '') $username = $tenantId;
    $secret = mh_multica_sso_secret();
    if ($secret === '') return ['success' => false, 'error' => 'sso_secret_missing'];

    $ts = time();
    $sig = mh_multica_sso_signature($ts, $tenantId, $username);
    $url = 'http://127.0.0.1:18080/auth/mh/provision';

    $body = json_encode(['tenant_id' => $tenantId, 'user' => $username], JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) return ['success' => false, 'error' => 'encode_failed'];

    $headers = [
        'Content-Type: application/json',
        'X-MH-TS: ' . (string)$ts,
        'X-MH-Signature: ' . $sig,
    ];

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 3,
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw)) return ['success' => false, 'error' => 'request_failed'];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'error' => 'bad_response'];
}
