<?php
declare(strict_types=1);

if (!defined('CUE_DISABLE_AUTO_UI')) define('CUE_DISABLE_AUTO_UI', true);
if (!defined('CUE_LAYOUT_MANUAL')) define('CUE_LAYOUT_MANUAL', true);
if (!defined('CUE_CLI_MODE')) define('CUE_CLI_MODE', true);

require_once __DIR__ . '/../../../.cue/cue.php';
require_once __DIR__ . '/../../../auth/auth_functions.php';

function mhw_oidc_base_dir(): string {
    if (defined('ROOT_PATH')) {
        $rootPath = rtrim((string)ROOT_PATH, '/');
        $homePath = (basename($rootPath) === 'public_html') ? dirname($rootPath) : $rootPath;
        return rtrim($homePath, '/') . '/.data/oidc';
    }
    return dirname(dirname(dirname(__DIR__))) . '/.data/oidc';
}

function mhw_base64url_decode(string $data): string {
    $data = strtr($data, '-_', '+/');
    $pad = strlen($data) % 4;
    if ($pad) $data .= str_repeat('=', 4 - $pad);
    $out = base64_decode($data, true);
    return is_string($out) ? $out : '';
}

function mhw_verify_rs256_jwt(string $jwt): ?array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $payload = json_decode(mhw_base64url_decode($p), true);
    if (!is_array($payload)) return null;
    $sig = mhw_base64url_decode($s);
    if ($sig === '') return null;
    $pubPath = mhw_oidc_base_dir() . '/keys/public.pem';
    if (!is_file($pubPath)) return null;
    $pubPem = (string)file_get_contents($pubPath);
    if ($pubPem === '') return null;
    $ok = openssl_verify($h . '.' . $p, $sig, $pubPem, OPENSSL_ALGO_SHA256);
    if ($ok !== 1) return null;
    $exp = isset($payload['exp']) ? (int)$payload['exp'] : 0;
    if ($exp !== 0 && $exp < time()) return null;
    return $payload;
}

function mhw_get_bearer_token(): string {
    $hdr = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if ($hdr === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $hdr = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    if ($hdr === '' && isset($_SERVER['HTTP_X_FORWARDED_ACCESS_TOKEN'])) $hdr = 'Bearer ' . (string)$_SERVER['HTTP_X_FORWARDED_ACCESS_TOKEN'];
    $hdr = trim($hdr);
    if ($hdr === '') return '';
    if (stripos($hdr, 'Bearer ') !== 0) return '';
    return trim(substr($hdr, 7));
}

function mhw_start_session(): void {
    if (function_exists('startSecureSession')) {
        startSecureSession();
        return;
    }
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function mhw_json(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function mhw_ollama_base_url(): string {
    if (function_exists('mh_internal_endpoint_url')) {
        $v = mh_internal_endpoint_url('ollama');
        if (is_string($v) && trim($v) !== '') {
            return rtrim(trim($v), '/');
        }
    }
    $v = getenv('MH_OLLAMA_BASE_URL');
    if (!is_string($v) || trim($v) === '') $v = getenv('OLLAMA_BASE_URL');
    if (!is_string($v) || trim($v) === '') $v = getenv('OLLAMA_HOST');
    $v = is_string($v) ? trim($v) : '';
    if ($v === '') $v = 'http://meta.superhumans.one:11434';
    return rtrim($v, '/');
}

function mhw_ollama_chat_completions_url(): string {
    $base = mhw_ollama_base_url();
    if (preg_match('~/v1/chat/completions/?$~', $base)) {
        return rtrim($base, '/');
    }
    return $base . '/v1/chat/completions';
}

function mhw_sanitize_id(string $s): string {
    $s = trim($s);
    $s = preg_replace('/[^a-zA-Z0-9:_\\-\\.]+/', '_', $s);
    $s = trim((string)$s, '._-');
    return $s !== '' ? $s : 'unknown';
}

function mhw_get_tenant_id(): string {
    $tenant = isset($_SESSION['mh_tenant_id']) ? (string)$_SESSION['mh_tenant_id'] : '';
    if ($tenant !== '') return $tenant;
    $u = isset($_SESSION['mh_auth_user']) ? (string)$_SESSION['mh_auth_user'] : '';
    return $u !== '' ? ('user:' . $u) : 'user:unknown';
}

function mhw_get_persona_id(): string {
    $persona = isset($_SESSION['mh_selected_persona']) ? (string)$_SESSION['mh_selected_persona'] : '';
    if ($persona !== '') return $persona;
    $persona = isset($_SESSION['mh_auth_persona']) ? (string)$_SESSION['mh_auth_persona'] : '';
    if ($persona !== '') return $persona;
    $u = isset($_SESSION['mh_auth_user']) ? (string)$_SESSION['mh_auth_user'] : '';
    return $u !== '' ? ('MH-' . $u) : 'MH-unknown';
}

function mhw_get_meta_human_id(): string {
    $mh = isset($_SESSION['mh_meta_human_id']) ? (string)$_SESSION['mh_meta_human_id'] : '';
    if ($mh !== '') return $mh;
    $persona = mhw_get_persona_id();
    $mh = 'meta:' . strtolower(mhw_sanitize_id($persona));
    $_SESSION['mh_meta_human_id'] = $mh;
    return $mh;
}

function mhw_require_context(): array {
    mhw_start_session();
    $hadSessionUser = !empty($_SESSION['mh_auth_user']);
    $authSource = $hadSessionUser ? 'session' : '';
    if (empty($_SESSION['mh_auth_user'])) {
        $jwt = mhw_get_bearer_token();
        if ($jwt !== '') {
            $claims = mhw_verify_rs256_jwt($jwt);
            if (is_array($claims)) {
                $u = (string)($claims['preferred_username'] ?? ($claims['sub'] ?? ''));
                if ($u !== '') {
                    $_SESSION['mh_auth_user'] = $u;
                    $authSource = 'bearer';
                    $groups = $claims['groups'] ?? null;
                    mh_auth_load_user_context($u, $groups);
                }
            }
        }
        if (empty($_SESSION['mh_auth_user'])) {
            mhw_json(['success' => false, 'error' => 'unauthorized'], 403);
            exit;
        }
    }
    $username = (string)$_SESSION['mh_auth_user'];
    mh_auth_load_user_context($username);

    $userId = isset($_SESSION['mh_user_internal_id']) ? (string)$_SESSION['mh_user_internal_id'] : '';
    $role = isset($_SESSION['mh_auth_role']) ? strtolower(trim((string)$_SESSION['mh_auth_role'])) : '';
    $isKripz = ($role !== '' && strpos($role, 'kripzmaster') !== false);
    $tenantId = mhw_get_tenant_id();
    $personaId = mhw_get_persona_id();
    $metaHumanId = mhw_get_meta_human_id();

    $ctx = [
        'success' => true,
        'auth_source' => $authSource !== '' ? $authSource : 'session',
        'user_id' => $userId !== '' ? $userId : $username,
        'username' => $username,
        'role' => $role,
        'is_kripz' => $isKripz,
        'tenant_id' => $tenantId,
        'persona_id' => $personaId,
        'meta_human_id' => $metaHumanId,
        'session_id' => session_id(),
        'device_id' => isset($_SESSION['mh_device_id']) ? (string)$_SESSION['mh_device_id'] : '',
    ];

    $tenantRoot = mhw_get_tenant_root($ctx);
    $workspaceRoot = mhw_get_workspace_root($ctx, 'default');
    mhw_ensure_dir($tenantRoot);
    mhw_ensure_dir($workspaceRoot);

    return $ctx;
}

function mhw_get_tenant_root(array $ctx): string {
    $tenantSafe = strtolower(mhw_sanitize_id((string)$ctx['tenant_id']));
    return '/data/tenants/' . $tenantSafe;
}

function mhw_get_workspace_root(array $ctx, string $projectId = 'default'): string {
    $tenantRoot = mhw_get_tenant_root($ctx);
    $personaSafe = strtolower(mhw_sanitize_id((string)$ctx['persona_id']));
    $projectSafe = strtolower(mhw_sanitize_id($projectId));
    return $tenantRoot . '/workspaces/' . $personaSafe . '/' . $projectSafe;
}

function mhw_normalize_relpath(string $path): string {
    $path = str_replace('\\', '/', $path);
    $path = ltrim($path, '/');
    $parts = [];
    foreach (explode('/', $path) as $p) {
        $p = trim($p);
        if ($p === '' || $p === '.') continue;
        if ($p === '..') {
            array_pop($parts);
            continue;
        }
        $p2 = preg_replace('/[^a-zA-Z0-9._\\-\\/]+/', '_', $p);
        $parts[] = $p2;
    }
    return implode('/', $parts);
}

function mhw_join_under(string $root, string $rel): string {
    $root = rtrim($root, '/');
    $rel = mhw_normalize_relpath($rel);
    return $rel === '' ? $root : ($root . '/' . $rel);
}

function mhw_ensure_dir(string $dir): bool {
    if (is_dir($dir)) return true;
    return @mkdir($dir, 0700, true);
}
