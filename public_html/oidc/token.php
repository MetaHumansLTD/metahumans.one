<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method_not_allowed']);
    exit;
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$clientId = '';
$clientSecret = '';

if (is_string($auth) && preg_match('/^Basic\\s+(.+)$/i', $auth, $m)) {
    $decoded = base64_decode($m[1], true);
    if (is_string($decoded) && strpos($decoded, ':') !== false) {
        [$clientId, $clientSecret] = explode(':', $decoded, 2);
        $clientId = (string)$clientId;
        $clientSecret = (string)$clientSecret;
    }
}

if ($clientId === '' && isset($_POST['client_id'])) $clientId = (string)$_POST['client_id'];
if ($clientSecret === '' && isset($_POST['client_secret'])) $clientSecret = (string)$_POST['client_secret'];
if ($clientId === '' && isset($_SERVER['PHP_AUTH_USER'])) $clientId = (string)$_SERVER['PHP_AUTH_USER'];
if ($clientSecret === '' && isset($_SERVER['PHP_AUTH_PW'])) $clientSecret = (string)$_SERVER['PHP_AUTH_PW'];

$grantType = isset($_POST['grant_type']) ? (string)$_POST['grant_type'] : '';
$code = isset($_POST['code']) ? (string)$_POST['code'] : '';
$redirectUri = isset($_POST['redirect_uri']) ? (string)$_POST['redirect_uri'] : '';

if ($grantType !== 'authorization_code' || $clientId === '' || $clientSecret === '' || $code === '' || $redirectUri === '') {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_request', 'token request missing required fields', [
        'grant_type' => $grantType,
        'client_id_present' => ($clientId !== ''),
        'client_secret_present' => ($clientSecret !== ''),
        'code_present' => ($code !== ''),
        'redirect_uri' => $redirectUri,
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'invalid_request']);
    exit;
}

$client = mh_oidc_get_client($clientId);
if ($client === null) {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_client', 'token request client not found', [
        'client_id' => $clientId,
    ]);
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
    exit;
}

$expectedSecret = isset($client['client_secret']) ? (string)$client['client_secret'] : '';
if (!hash_equals($expectedSecret, $clientSecret)) {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_client', 'token request client secret mismatch', [
        'client_id' => $clientId,
    ]);
    http_response_code(401);
    echo json_encode(['error' => 'invalid_client']);
    exit;
}

$stored = mh_oidc_consume_code($code);
if ($stored === null) {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_grant', 'authorization code missing or invalid at consume', [
        'client_id' => $clientId,
        'code' => $code,
        'redirect_uri' => $redirectUri,
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant']);
    exit;
}
if (($stored['client_id'] ?? '') !== $clientId || ($stored['redirect_uri'] ?? '') !== $redirectUri) {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_grant', 'authorization code client or redirect mismatch', [
        'client_id' => $clientId,
        'stored_client_id' => $stored['client_id'] ?? null,
        'redirect_uri' => $redirectUri,
        'stored_redirect_uri' => $stored['redirect_uri'] ?? null,
        'code' => $code,
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant']);
    exit;
}

$issuer = mh_oidc_issuer();
$now = time();
$exp = $now + 86400;
$username = (string)($stored['username'] ?? '');
$email = isset($stored['email']) ? trim((string)$stored['email']) : '';
$displayName = isset($stored['display_name']) ? trim((string)$stored['display_name']) : '';
if ($username === '') {
    mh_oidc_debug_emit('T', 'oidc/token.php:invalid_grant', 'authorization code missing username', [
        'client_id' => $clientId,
        'code' => $code,
        'stored_keys' => array_keys($stored),
    ]);
    http_response_code(400);
    echo json_encode(['error' => 'invalid_grant']);
    exit;
}

$groups = isset($stored['groups']) && is_array($stored['groups']) ? $stored['groups'] : [];
$preferredUsername = $email !== '' ? $email : $username;
$nameClaim = $displayName !== '' ? $displayName : $username;

$idToken = mh_oidc_sign_jwt([
    'iss' => $issuer,
    'aud' => $clientId,
    'sub' => $username,
    'iat' => $now,
    'exp' => $exp,
    'nonce' => (string)($stored['nonce'] ?? ''),
    'name' => $nameClaim,
    'preferred_username' => $preferredUsername,
    'email' => $email !== '' ? $email : $username,
    'groups' => $groups
]);

$accessToken = mh_oidc_sign_jwt([
    'iss' => $issuer,
    'aud' => $clientId,
    'sub' => $username,
    'iat' => $now,
    'exp' => $exp,
    'name' => $nameClaim,
    'preferred_username' => $preferredUsername,
    'email' => $email !== '' ? $email : $username,
    'groups' => $groups,
    'scope' => (string)($stored['scope'] ?? 'openid profile email')
]);

echo json_encode([
    'access_token' => $accessToken,
    'id_token' => $idToken,
    'token_type' => 'Bearer',
    'expires_in' => 86400
], JSON_UNESCAPED_SLASHES);
