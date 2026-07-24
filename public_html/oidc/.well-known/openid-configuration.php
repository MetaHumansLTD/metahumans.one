<?php
require_once __DIR__ . '/../lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$issuer = mh_oidc_issuer();

echo json_encode([
    'issuer' => $issuer,
    'authorization_endpoint' => $issuer . '/authorize.php',
    'token_endpoint' => $issuer . '/token.php',
    'userinfo_endpoint' => $issuer . '/userinfo.php',
    'jwks_uri' => $issuer . '/jwks.php',
    'response_types_supported' => ['code'],
    'subject_types_supported' => ['public'],
    'id_token_signing_alg_values_supported' => ['RS256'],
    'scopes_supported' => ['openid', 'profile', 'email'],
    'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
    'claims_supported' => ['sub', 'iss', 'aud', 'exp', 'iat', 'preferred_username', 'name', 'email', 'groups'],
], JSON_UNESCAPED_SLASHES);
