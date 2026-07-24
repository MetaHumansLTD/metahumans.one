<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

$token = (string)(mh_id_env('MH_HEALTH_TOKEN', '') ?: '');
$provided = '';
if (isset($_SERVER['HTTP_X_MH_HEALTH_TOKEN'])) $provided = (string)$_SERVER['HTTP_X_MH_HEALTH_TOKEN'];
if ($provided === '' && isset($_GET['token'])) $provided = (string)$_GET['token'];
$provided = trim($provided);

if ($token !== '' && !hash_equals($token, $provided)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($token === '') {
    mh_id_start_session();
    if (mh_id_current_user() === '') {
        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_SLASHES);
        exit;
    }
}

function mh_id_mask_url_json(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $p = parse_url($url);
    if (!is_array($p)) return $url;
    $scheme = isset($p['scheme']) ? (string)$p['scheme'] : '';
    $host = isset($p['host']) ? (string)$p['host'] : '';
    $port = isset($p['port']) ? (int)$p['port'] : 0;
    $path = isset($p['path']) ? (string)$p['path'] : '';
    $out = '';
    if ($scheme !== '') $out .= $scheme . '://';
    $out .= $host !== '' ? $host : '[host]';
    if ($port > 0) $out .= ':' . $port;
    if ($path !== '') $out .= $path;
    return $out;
}

$verifierUrl = mh_id_env('MH_KYC_VERIFIER_URL', '');
$verifierSecretSet = mh_id_env('MH_KYC_VERIFIER_SECRET', '') !== '';
if ($verifierUrl === '' && is_file(__DIR__ . '/verifier.php')) {
    $base = function_exists('getBaseUrl') ? rtrim((string)getBaseUrl(), '/') : '';
    if ($base === '') {
        $proto = '';
        $host = '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
            $proto = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
            $host = trim(explode(',', (string)$_SERVER['HTTP_X_FORWARDED_HOST'])[0]);
        }
        if ($proto !== '' && $host !== '') {
            $base = $proto . '://' . $host;
        }
    }
    if ($base === '' && !empty($_SERVER['HTTP_HOST'])) {
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $base = ($isHttps ? 'https://' : 'http://') . (string)$_SERVER['HTTP_HOST'];
    }
    if ($base === '') {
        $base = 'https://metahumans.one';
    }
    $verifierUrl = rtrim($base, '/') . '/auth/id/verifier.php';
}
if (!$verifierSecretSet) {
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('paths');
        }
        $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
        $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        $appKey = is_string($raw) ? trim((string)$raw) : '';
        if ($appKey !== '') {
            $verifierSecretSet = true;
        }
    } catch (Throwable) {}
}
$mosipEnabled = mh_id_env('MH_KYC_MOSIP_ENABLED', '') === '1';
$mosipUrl = mh_id_env('MH_KYC_MOSIP_VERIFY_URL', '');
$mosipSecretSet = mh_id_env('MH_KYC_MOSIP_SECRET', '') !== '';
$mosipUpstream = mh_id_env('MOSIP_UPSTREAM_URL', '');
$nfcFull = mh_id_env('MH_NFC_FULL_VERIFY', '') === '1';
$trustMode = mh_id_env('MH_PASSPORT_TRUST_MODE', '');
$cscaCountries = mh_id_env('MH_PASSPORT_CSCA_COUNTRIES', '');
$cscaBundle = mh_id_env('MH_PASSPORT_CSCA_BUNDLE', '');
$cscaExists = $cscaBundle !== '' && is_file($cscaBundle);
$ttl = (int)mh_id_env('MH_SESSION_TTL', '43200');
if ($ttl <= 0) $ttl = 43200;

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo json_encode([
    'ok' => true,
    'time' => time(),
    'verifier' => [
        'configured' => $verifierUrl !== '',
        'url' => $verifierUrl !== '' ? mh_id_mask_url_json($verifierUrl) : '',
        'secret_set' => $verifierSecretSet,
    ],
    'mosip' => [
        'enabled' => $mosipEnabled,
        'url_configured' => $mosipUrl !== '',
        'url' => $mosipUrl !== '' ? mh_id_mask_url_json($mosipUrl) : '',
        'secret_set' => $mosipSecretSet,
        'upstream_configured' => $mosipUpstream !== '',
        'upstream_url' => $mosipUpstream !== '' ? mh_id_mask_url_json($mosipUpstream) : '',
        'upstream_stub' => $mosipUpstream === '',
    ],
    'nfc' => [
        'full_verify' => $nfcFull,
        'passport_trust_mode' => $trustMode !== '' ? $trustMode : 'none',
        'passport_csca_countries' => $cscaCountries !== '' ? $cscaCountries : '',
        'csca_bundle_configured' => $cscaBundle !== '',
        'csca_bundle_exists' => $cscaExists,
    ],
    'session' => [
        'ttl_seconds' => $ttl,
    ],
    'health_token' => [
        'required' => $token !== '',
        'set' => $token !== '',
    ],
], JSON_UNESCAPED_SLASHES);
