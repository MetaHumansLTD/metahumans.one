<?php
// lemonldap-handler.php
// Handles LemonLDAP::NG SSO headers and user synchronization

if (file_exists(__DIR__ . '/auth_functions.php')) {
    require_once __DIR__ . '/auth_functions.php';
}

function lemonldap_process_headers(): ?array {
    // Check for manual logout cookie to prevent loop
    if (isset($_COOKIE['mh_sso_logged_out']) && $_COOKIE['mh_sso_logged_out'] === '1') {
        return null;
    }

    // In production, we read $_SERVER headers directly
    $headers = $_SERVER;

    $username = '';
    $userHeaderKeys = [
        'HTTP_AUTH_USER',
        'REMOTE_USER',
        'HTTP_REMOTE_USER',
        'HTTP_X_REMOTE_USER',
        'HTTP_X_FORWARDED_USER',
        'HTTP_X_AUTH_REQUEST_USER',
        'HTTP_X_AUTH_USER',
        'HTTP_X_FORWARDED_PREFERRED_USERNAME',
    ];
    $userSource = '';
    foreach ($userHeaderKeys as $k) {
        if (isset($headers[$k]) && is_string($headers[$k]) && trim((string)$headers[$k]) !== '') {
            $username = trim((string)$headers[$k]);
            $userSource = $k;
            break;
        }
    }

    $groupHeaderKeys = [
        'HTTP_AUTH_GROUPS',
        'HTTP_X_AUTH_GROUPS',
        'HTTP_X_REMOTE_GROUPS',
        'HTTP_X_FORWARDED_GROUPS',
        'HTTP_X_AUTH_REQUEST_GROUPS',
        'HTTP_X_FORWARDED_ROLES',
    ];
    $groups = '';
    $groupsSource = '';
    foreach ($groupHeaderKeys as $k) {
        if (isset($headers[$k]) && is_string($headers[$k]) && trim((string)$headers[$k]) !== '') {
            $groups = trim((string)$headers[$k]);
            $groupsSource = $k;
            break;
        }
    }

    $emailHeaderKeys = [
        'HTTP_AUTH_EMAIL',
        'HTTP_X_AUTH_EMAIL',
        'HTTP_MAIL',
        'HTTP_X_FORWARDED_EMAIL',
        'HTTP_X_AUTH_REQUEST_EMAIL',
    ];
    $email = '';
    foreach ($emailHeaderKeys as $k) {
        if (isset($headers[$k]) && is_string($headers[$k]) && trim((string)$headers[$k]) !== '') {
            $email = trim((string)$headers[$k]);
            break;
        }
    }

    if ($username !== '') {
        return [
            'username' => $username,
            'groups' => $groups,
            'email' => $email,
            'source' => $userSource,
            'groups_source' => $groupsSource,
        ];
    }
    
    return null;
}

function lemonldap_sync_user(array $ssoData): void {
    // Logic to sync SSO user to local biometrics DB
    // This is called from login.php
    
    // We can add logic here to update the user record if needed
    // For now, login.php handles the auth-backed user context loading/creation.
    
    // Example: Update email if changed
    // $pdo = get_biometrics_pdo(); ...
}
