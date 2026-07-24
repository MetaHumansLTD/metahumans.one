<?php
declare(strict_types=1);

function mh_superhumans_get_config(): array
{
    $candidates = [];
    $candidates[] = '/data/config/superhumans.json';
    if (function_exists('getDataPath')) {
        $dataPath = (string)getDataPath();
        if ($dataPath !== '') {
            $candidates[] = rtrim($dataPath, '/') . '/config/superhumans.json';
        }
    }
    if (function_exists('cue_autoload')) {
        $paths = cue_autoload('paths');
        if (is_object($paths) && method_exists($paths, 'getConfigPath')) {
            $cfgRoot = rtrim((string)$paths->getConfigPath(), '/');
            if ($cfgRoot !== '') {
                $candidates[] = $cfgRoot . '/superhumans.json';
            }
        }
    }

    $cfgPath = '';
    foreach ($candidates as $p) {
        if (is_string($p) && $p !== '' && file_exists($p)) {
            $cfgPath = $p;
            break;
        }
    }
    $cfg = [];
    if ($cfgPath !== '' && file_exists($cfgPath)) {
        $raw = file_get_contents($cfgPath);
        $parsed = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($parsed)) {
            $cfg = $parsed;
        }
    }

    $baseUrl = getenv('SUPERHUMANS_BASE_URL');
    if (is_string($baseUrl) && trim($baseUrl) !== '') {
        $cfg['base_url'] = trim($baseUrl);
    }
    if (!isset($cfg['base_url']) || !is_string($cfg['base_url']) || trim($cfg['base_url']) === '') {
        $cfg['base_url'] = 'https://superhumans.one';
    }

    $timeout = getenv('SUPERHUMANS_HTTP_TIMEOUT_SECONDS');
    if (is_string($timeout) && trim($timeout) !== '' && is_numeric($timeout)) {
        $cfg['timeout_seconds'] = (int)$timeout;
    }
    if (!isset($cfg['timeout_seconds']) || !is_int($cfg['timeout_seconds']) || $cfg['timeout_seconds'] <= 0) {
        $cfg['timeout_seconds'] = 20;
    }

    $sslVerify = getenv('SUPERHUMANS_SSL_VERIFY');
    if (is_string($sslVerify) && trim($sslVerify) !== '') {
        $cfg['ssl_verify'] = in_array(strtolower(trim($sslVerify)), ['1', 'true', 'yes', 'on'], true);
    }
    if (!array_key_exists('ssl_verify', $cfg)) {
        $cfg['ssl_verify'] = true;
    }

    return $cfg;
}

function mh_superhumans_request(string $method, string $path, ?array $jsonBody = null, array $headers = []): array
{
    $cfg = mh_superhumans_get_config();
    $baseUrl = rtrim((string)$cfg['base_url'], '/');
    $path = '/' . ltrim($path, '/');
    $url = $baseUrl . $path;

    $ch = curl_init();
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init failed', 'body' => null];
    }

    $method = strtoupper(trim($method));
    if ($method === '') $method = 'GET';

    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        if (is_int($k)) {
            $curlHeaders[] = (string)$v;
        } else {
            $curlHeaders[] = (string)$k . ': ' . (string)$v;
        }
    }

    $payload = null;
    if ($jsonBody !== null) {
        $payload = json_encode($jsonBody);
        if (!is_string($payload)) {
            $ch = null;
            return ['ok' => false, 'status' => 0, 'error' => 'json_encode failed', 'body' => null];
        }
        $curlHeaders[] = 'Content-Type: application/json';
    }

    $tokenEnv = isset($cfg['auth_token_env']) && is_string($cfg['auth_token_env']) ? trim($cfg['auth_token_env']) : '';
    if ($tokenEnv !== '') {
        $tok = getenv($tokenEnv);
        if (is_string($tok) && trim($tok) !== '') {
            $curlHeaders[] = 'Authorization: Bearer ' . trim($tok);
        }
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, (int)$cfg['timeout_seconds']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 0);
    $sslVerify = !empty($cfg['ssl_verify']);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);

    if (isset($cfg['ca_bundle_path']) && is_string($cfg['ca_bundle_path']) && trim($cfg['ca_bundle_path']) !== '') {
        curl_setopt($ch, CURLOPT_CAINFO, trim($cfg['ca_bundle_path']));
    }
    if (isset($cfg['client_cert_path']) && is_string($cfg['client_cert_path']) && trim($cfg['client_cert_path']) !== '') {
        curl_setopt($ch, CURLOPT_SSLCERT, trim($cfg['client_cert_path']));
    }
    if (isset($cfg['client_key_path']) && is_string($cfg['client_key_path']) && trim($cfg['client_key_path']) !== '') {
        curl_setopt($ch, CURLOPT_SSLKEY, trim($cfg['client_key_path']));
    }

    if (!empty($curlHeaders)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    }
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    $raw = curl_exec($ch);
    if (!is_string($raw)) {
        $err = curl_error($ch);
        $ch = null;
        return ['ok' => false, 'status' => 0, 'error' => $err !== '' ? $err : 'curl_exec failed', 'body' => null];
    }

    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $ch = null;

    $rawHeaders = substr($raw, 0, $headerSize);
    $rawBody = substr($raw, $headerSize);

    $decoded = null;
    $ct = '';
    foreach (preg_split("/\r\n|\n|\r/", (string)$rawHeaders) as $line) {
        if (stripos($line, 'content-type:') === 0) {
            $ct = trim(substr($line, strlen('content-type:')));
            break;
        }
    }
    if ($rawBody !== '' && stripos($ct, 'application/json') !== false) {
        $tmp = json_decode($rawBody, true);
        if (is_array($tmp)) {
            $decoded = $tmp;
        }
    }

    return [
        'ok' => ($status >= 200 && $status <= 299),
        'status' => $status,
        'error' => null,
        'headers_raw' => $rawHeaders,
        'body_raw' => $rawBody,
        'body' => $decoded,
        'url' => $url,
    ];
}
