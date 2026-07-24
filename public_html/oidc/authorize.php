<?php
require_once __DIR__ . '/lib.php';

header('Cache-Control: no-store');

$issuer = mh_oidc_issuer();
$clientId = isset($_GET['client_id']) ? (string)$_GET['client_id'] : '';
$redirectUri = isset($_GET['redirect_uri']) ? (string)$_GET['redirect_uri'] : '';
$responseType = isset($_GET['response_type']) ? (string)$_GET['response_type'] : '';
$scope = isset($_GET['scope']) ? (string)$_GET['scope'] : '';
$state = isset($_GET['state']) ? (string)$_GET['state'] : '';
$nonce = isset($_GET['nonce']) ? (string)$_GET['nonce'] : '';
$loginHint = isset($_GET['login_hint']) ? trim((string)$_GET['login_hint']) : '';

if ($clientId === '' || $redirectUri === '' || $responseType !== 'code') {
    http_response_code(400);
    echo 'invalid_request';
    exit;
}

$client = mh_oidc_get_client($clientId);
if ($client === null) {
    http_response_code(400);
    echo 'invalid_client';
    exit;
}

$allowedUris = isset($client['redirect_uris']) && is_array($client['redirect_uris']) ? $client['redirect_uris'] : [];
if (!in_array($redirectUri, $allowedUris, true)) {
    http_response_code(400);
    echo 'invalid_redirect_uri';
    exit;
}

$returnTo = $issuer . '/authorize.php?' . http_build_query($_GET);
mh_oidc_require_login($returnTo);

$username = (string)$_SESSION['mh_auth_user'];
mh_auth_load_user_context($username, null, $loginHint);
$email = isset($_SESSION['mh_auth_email']) && is_string($_SESSION['mh_auth_email']) ? trim((string)$_SESSION['mh_auth_email']) : '';
$displayName = isset($_SESSION['mh_auth_display']) && is_string($_SESSION['mh_auth_display']) ? trim((string)$_SESSION['mh_auth_display']) : '';
if (($email === '' || $displayName === '') && function_exists('mh_auth_user_store_pdo')) {
    try {
        $pdoAuth = mh_auth_user_store_pdo();
        $stmt = $pdoAuth->prepare("SELECT email, persona_name, name FROM users WHERE username = ? LIMIT 1");
        $stmt->execute([$username]);
        $identityRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($identityRow)) {
            if ($email === '') {
                $dbEmail = isset($identityRow['email']) ? trim((string)$identityRow['email']) : '';
                if ($dbEmail !== '' && filter_var($dbEmail, FILTER_VALIDATE_EMAIL)) {
                    $email = $dbEmail;
                    $_SESSION['mh_auth_email'] = $email;
                }
            }
            if ($displayName === '') {
                $displayCandidate = isset($identityRow['persona_name']) ? trim((string)$identityRow['persona_name']) : '';
                if ($displayCandidate === '') {
                    $displayCandidate = isset($identityRow['name']) ? trim((string)$identityRow['name']) : '';
                }
                if ($displayCandidate !== '') {
                    $displayName = $displayCandidate;
                    $_SESSION['mh_auth_display'] = $displayName;
                }
            }
        }
    } catch (Throwable) {
    }
}
if ($email === '' && $loginHint !== '' && filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
    $email = $loginHint;
    $_SESSION['mh_auth_email'] = $email;
}
if ($displayName === '') {
    $displayName = $username;
}

$groups = $_SESSION['mh_auth_groups'] ?? null;
if (is_string($groups)) {
    $groups = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $groups) ?: []), function($g) { return $g !== ''; }));
} elseif (!is_array($groups)) {
    $groups = [];
}

$role = $_SESSION['mh_auth_role'] ?? null;
if (is_string($role) && $role !== '') {
    if ($role === 'KripzMasters' && !in_array('KripzMasters', $groups, true)) {
        $groups[] = 'KripzMasters';
    }
    if ($role === 'Users' && !in_array('Users', $groups, true)) {
        $groups[] = 'Users';
    }
}

$code = mh_oidc_create_code([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => $scope,
    'username' => $username,
    'email' => $email,
    'display_name' => $displayName,
    'nonce' => $nonce,
    'groups' => $groups
]);

$qs = ['code' => $code];
if ($state !== '') $qs['state'] = $state;
$sep = (strpos($redirectUri, '?') === false) ? '?' : '&';
header('Location: ' . $redirectUri . $sep . http_build_query($qs));
exit;
