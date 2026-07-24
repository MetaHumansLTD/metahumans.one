<?php
declare(strict_types=1);

require_once __DIR__ . '/../_lib.php';

mh_widget_start_session();
$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
if ($username === '') {
    $redirect = '';
    $ref = isset($_SERVER['HTTP_REFERER']) ? trim((string)$_SERVER['HTTP_REFERER']) : '';
    $p = $ref !== '' ? parse_url($ref) : null;
    $refHost = is_array($p) ? strtolower((string)($p['host'] ?? '')) : '';
    $host = isset($_SERVER['HTTP_HOST']) ? strtolower((string)$_SERVER['HTTP_HOST']) : '';
    $refPath = is_array($p) ? (string)($p['path'] ?? '') : '';
    $refQuery = is_array($p) ? (string)($p['query'] ?? '') : '';
    if ($refHost !== '' && $host !== '' && $refHost === $host && $refPath !== '' && $refPath[0] === '/' && strpos($refPath, '/auth/') !== 0 && strpos($refPath, '/hub/widget/') !== 0) {
        $redirect = $refPath . ($refQuery !== '' ? ('?' . $refQuery) : '');
    }
    if ($redirect === '') {
        $redirect = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/hub/';
        if ($redirect === '' || $redirect[0] !== '/' || strpos($redirect, '/hub/widget/') === 0) {
            $redirect = '/hub/';
        }
    }
    mh_widget_json([
        'success' => false,
        'auth' => ['ok' => false],
        'login_url' => '/auth/login.php?redirect=' . rawurlencode($redirect),
    ], 200);
    exit;
}

$tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
if ($tenantId === '') {
    $tenantId = 'user:' . $username;
    $_SESSION['mh_tenant_id'] = $tenantId;
}
try {
    if (!function_exists('mh_apply_tenant_context') || !mh_apply_tenant_context($tenantId)) {
        mh_widget_json(['success' => false, 'error' => 'tenant_context_failed'], 200);
        exit;
    }
} catch (Throwable $e) {
    mh_widget_json(['success' => false, 'error' => 'tenant_context_failed'], 200);
    exit;
}

$personaId = isset($_SESSION['mh_selected_persona']) ? trim((string)$_SESSION['mh_selected_persona']) : '';
if ($personaId === '') {
    $personaId = isset($_SESSION['mh_auth_persona']) ? trim((string)$_SESSION['mh_auth_persona']) : '';
}
if ($personaId === '') {
    $personaId = 'MH-' . $username;
}

$setPersona = '';
if (isset($_GET['set_persona'])) {
    $setPersona = trim((string)$_GET['set_persona']);
} elseif (isset($_POST['set_persona'])) {
    $setPersona = trim((string)$_POST['set_persona']);
}
if ($setPersona !== '') {
    $setPersona = preg_replace('/[^A-Za-z0-9_-]+/', '', $setPersona);
    if ($setPersona !== '') {
        $_SESSION['mh_selected_persona'] = $setPersona;
        $personaId = $setPersona;
    }
}

$personas = mh_widget_list_personas($username, $personaId);
$personaDefault = $personaId;

$hubBase = '/hub';

mh_widget_json([
    'success' => true,
    'auth' => ['ok' => true],
    'user' => [
        'username' => $username,
    ],
    'tenant' => [
        'tenant_id' => $tenantId,
    ],
    'personas' => $personas,
    'persona_default' => $personaDefault,
    'features' => [
        'voice_supported' => true,
        'video_supported' => true,
    ],
    'endpoints' => [
        'bootstrap' => $hubBase . '/widget/bootstrap',
        'personas' => $hubBase . '/widget/personas',
        'persona' => $hubBase . '/widget/personas/{persona_id}',
        'chat' => $hubBase . '/widget/chat',
        'events' => $hubBase . '/widget/events',
        'uploads' => $hubBase . '/widget/uploads',
        'media' => $hubBase . '/widget/media/{media_id}',
        'session_start' => $hubBase . '/widget/session/start',
        'session_stop' => $hubBase . '/widget/session/stop',
    ],
]);
