<?php
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ((!is_string($auth) || $auth === '') && function_exists('getallheaders')) {
    $headers = getallheaders();
    if (is_array($headers)) {
        foreach ($headers as $k => $v) {
            if (is_string($k) && is_string($v) && strcasecmp($k, 'authorization') === 0) {
                $auth = $v;
                break;
            }
        }
    }
}
if (!is_string($auth) || !preg_match('/^Bearer\\s+(.+)$/i', $auth, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

$claims = mh_oidc_verify_jwt($m[1]);
if ($claims === null) {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

$sub = isset($claims['sub']) ? (string)$claims['sub'] : '';
if ($sub === '') {
    http_response_code(401);
    echo json_encode(['error' => 'invalid_token']);
    exit;
}

echo json_encode([
    'sub' => $sub,
    'preferred_username' => isset($claims['preferred_username']) ? (string)$claims['preferred_username'] : $sub,
    'email' => isset($claims['email']) ? (string)$claims['email'] : $sub,
    'name' => isset($claims['name']) ? (string)$claims['name'] : $sub,
    'groups' => isset($claims['groups']) && is_array($claims['groups']) ? $claims['groups'] : []
], JSON_UNESCAPED_SLASHES);
