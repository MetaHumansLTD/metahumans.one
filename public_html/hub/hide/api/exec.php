<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_LAYOUT_MANUAL', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 3) . '/.cue/cue.php';
require_once dirname(__DIR__, 3) . '/auth/auth_functions.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$username = (string)$_SESSION['mh_auth_user'];
mh_auth_load_user_context($username);
$pricing = mh_charge_service_tokens($username, 'hide:exec', 1, [], 2);
if (!$pricing['success']) {
    http_response_code(402);
    echo json_encode(['error' => 'insufficient_tokens', 'tokens' => (int)($pricing['tokens'] ?? 0)]);
    exit;
}
header('X-MH-Tokens-Remaining: ' . (int)($pricing['tokens'] ?? 0));

$raw = file_get_contents('php://input');
$input = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_json']);
    exit;
}

$repo = isset($input['repo']) && is_string($input['repo']) ? trim($input['repo']) : '';
$ref = isset($input['ref']) && is_string($input['ref']) ? trim($input['ref']) : 'main';
$cmd = isset($input['cmd']) && is_string($input['cmd']) ? trim($input['cmd']) : '';

if ($repo === '' || $cmd === '') {
    http_response_code(400);
    echo json_encode(['error' => 'missing_repo_or_cmd']);
    exit;
}

if (!preg_match('#^https://#i', $repo)) {
    http_response_code(400);
    echo json_encode(['error' => 'repo_must_be_https']);
    exit;
}

$baseDir = '/home/onemeta/.data/hide/workspaces';
@mkdir($baseDir, 0750, true);

$key = hash('sha256', $username . '|' . $repo . '|' . $ref);
$wsDir = $baseDir . '/' . $key;

if (!is_dir($wsDir . '/.git')) {
    @mkdir($wsDir, 0750, true);
    $cloneCmd = 'git clone --depth 1 --branch ' . escapeshellarg($ref) . ' ' . escapeshellarg($repo) . ' ' . escapeshellarg($wsDir) . ' 2>&1';
    $cloneOut = shell_exec($cloneCmd);
    if (!is_dir($wsDir . '/.git')) {
        http_response_code(500);
        echo json_encode(['error' => 'clone_failed', 'output' => is_string($cloneOut) ? $cloneOut : '']);
        exit;
    }
}

$docker = trim((string)shell_exec('command -v docker 2>/dev/null'));
if ($docker === '') {
    http_response_code(500);
    echo json_encode(['error' => 'docker_not_available']);
    exit;
}

$image = 'alpine:3.20';
$inner = 'apk add --no-cache bash git >/dev/null 2>&1; cd /workspace; ' . $cmd;
$runCmd =
    escapeshellcmd($docker) .
    ' run --rm ' .
    ' -v ' . escapeshellarg($wsDir . ':/workspace') .
    ' -w /workspace ' .
    escapeshellarg($image) .
    ' sh -lc ' . escapeshellarg($inner) .
    ' 2>&1';

$output = shell_exec($runCmd);
echo json_encode([
    'success' => true,
    'workspace' => $wsDir,
    'output' => is_string($output) ? $output : '',
]);
