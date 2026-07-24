<?php
declare(strict_types=1);

define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once __DIR__ . '/meet_helpers.php';

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');
ob_start();

header('Content-Type: application/json; charset=UTF-8');

function mh_agent_json_exit(int $code, array $payload): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    http_response_code($code);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_agent_env(string $k, string $default = ''): string
{
    $v = getenv($k);
    if (!is_string($v)) return $default;
    $v = trim($v);
    return $v !== '' ? $v : $default;
}

function mh_agent_bearer(): string
{
    $h = '';
    if (isset($_SERVER['HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['HTTP_AUTHORIZATION'];
    if ($h === '' && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $h = (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    $h = trim($h);
    if ($h === '' || stripos($h, 'Bearer ') !== 0) return '';
    return trim(substr($h, 7));
}

function mh_agent_hmac_hex(string $data, string $secret): string
{
    return hash_hmac('sha256', $data, $secret);
}

function mh_agent_nonce(): string
{
    return bin2hex(random_bytes(16));
}

function mh_agent_require_sig(string $secret): array
{
    $ts = isset($_SERVER['HTTP_X_MH_TIMESTAMP']) ? trim((string)$_SERVER['HTTP_X_MH_TIMESTAMP']) : '';
    $nonce = isset($_SERVER['HTTP_X_MH_NONCE']) ? trim((string)$_SERVER['HTTP_X_MH_NONCE']) : '';
    $sig = isset($_SERVER['HTTP_X_MH_SIGNATURE']) ? trim((string)$_SERVER['HTTP_X_MH_SIGNATURE']) : '';
    if ($ts === '' || $nonce === '' || $sig === '') {
        mh_agent_json_exit(401, ['ok' => false, 'error' => 'missing_signature']);
    }
    if (!ctype_digit($ts)) {
        mh_agent_json_exit(401, ['ok' => false, 'error' => 'bad_timestamp']);
    }
    $tsI = (int)$ts;
    if (abs(time() - $tsI) > 120) {
        mh_agent_json_exit(401, ['ok' => false, 'error' => 'timestamp_out_of_window']);
    }
    return [$ts, $nonce, $sig];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    mh_agent_json_exit(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$secret = mh_agent_env('MH_MEET_AGENT_SECRET', '');
if ($secret === '') {
    mh_agent_json_exit(500, ['ok' => false, 'error' => 'server_not_configured']);
}

[$ts, $nonce, $sig] = mh_agent_require_sig($secret);

$raw = file_get_contents('php://input');
$body = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($body)) {
    mh_agent_json_exit(400, ['ok' => false, 'error' => 'invalid_json']);
}

$roomId = isset($body['room_id']) ? trim((string)$body['room_id']) : '';
if ($roomId === '') {
    mh_agent_json_exit(400, ['ok' => false, 'error' => 'missing_room_id']);
}

$name = isset($body['name']) ? trim((string)$body['name']) : '';
if ($name === '') $name = 'MetaHumans Agent';

$identity = isset($body['identity']) ? trim((string)$body['identity']) : '';
if ($identity === '') $identity = 'mh_agent_' . bin2hex(random_bytes(6));

$isAdminReq = isset($body['is_admin']) ? (bool)$body['is_admin'] : false;
$allowAdmin = mh_agent_env('MH_MEET_AGENT_ALLOW_ADMIN', '') === '1';
$isAdmin = $allowAdmin ? $isAdminReq : false;

$sigBase = implode("\n", [
    $ts,
    $nonce,
    $roomId,
    $identity,
    $name,
    $isAdmin ? '1' : '0',
]);
$expected = mh_agent_hmac_hex($sigBase, $secret);
if (!hash_equals($expected, $sig)) {
    mh_agent_json_exit(401, ['ok' => false, 'error' => 'bad_signature']);
}

try {
    try {
        pnm_create_room_helper($roomId, $roomId);
    } catch (Throwable $e) {
        $m = strtolower($e->getMessage());
        if (strpos($m, 'exist') === false && strpos($m, 'already') === false) {
            throw $e;
        }
    }

    $res = null;
    for ($i = 0; $i < 5; $i++) {
        $res = pnm_get_join_token_helper($roomId, $name, $identity, $isAdmin);
        if (is_array($res) && !empty($res['status']) && !empty($res['token'])) {
            break;
        }
        $msg = isset($res['msg']) ? strtolower((string)$res['msg']) : '';
        if (strpos($msg, 'room is not active') === false && strpos($msg, 'room not found') === false) {
            break;
        }
        pnm_create_room_helper($roomId, $roomId);
        usleep(250000);
    }
    if (!is_array($res) || empty($res['status']) || empty($res['token'])) {
        $m = is_array($res) && isset($res['msg']) ? (string)$res['msg'] : 'unknown_error';
        mh_agent_json_exit(502, ['ok' => false, 'error' => 'token_failed', 'detail' => $m]);
    }

    $token = (string)$res['token'];
    $respNonce = mh_agent_nonce();
    $respBase = implode("\n", [
        $ts,
        $nonce,
        $respNonce,
        $roomId,
        $identity,
        $isAdmin ? '1' : '0',
        $token,
    ]);
    $respSig = mh_agent_hmac_hex($respBase, $secret);
    mh_agent_json_exit(200, [
        'ok' => true,
        'room_id' => $roomId,
        'identity' => $identity,
        'name' => $name,
        'is_admin' => $isAdmin,
        'token' => $token,
        'ts' => (int)$ts,
        'nonce' => $nonce,
        'resp_nonce' => $respNonce,
        'signature' => $respSig,
    ]);
} catch (Throwable $e) {
    mh_agent_json_exit(500, ['ok' => false, 'error' => 'server_error']);
}

