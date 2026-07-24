<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$keys = mh_oidc_load_or_create_keys();
$pub = openssl_pkey_get_public($keys['public_pem']);
$details = is_resource($pub) ? openssl_pkey_get_details($pub) : openssl_pkey_get_details($pub);

if (!is_array($details) || !isset($details['rsa']) || !is_array($details['rsa'])) {
    http_response_code(500);
    echo json_encode(['error' => 'jwks_unavailable']);
    exit;
}

$n = mh_oidc_base64url_encode($details['rsa']['n']);
$e = mh_oidc_base64url_encode($details['rsa']['e']);

echo json_encode([
    'keys' => [
        [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $keys['kid'],
            'n' => $n,
            'e' => $e
        ]
    ]
], JSON_UNESCAPED_SLASHES);
