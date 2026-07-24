<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/gear/meet/meet_helpers.php';

@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function mh_diag_curl(string $url, int $timeoutSec = 8): array
{
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init_failed'];
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, max(1, (int)($timeoutSec / 2)));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
    curl_setopt($ch, CURLOPT_HEADER, false);
    $start = microtime(true);
    $body = curl_exec($ch);
    $elapsed = microtime(true) - $start;
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [
        'ok' => $err === '' && $status > 0,
        'url' => $url,
        'status' => $status,
        'time_ms' => (int)round($elapsed * 1000),
        'curl_error' => $err,
        'body_sample' => is_string($body) ? substr($body, 0, 120) : '',
    ];
}

$host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string)$_SERVER['HTTP_X_FORWARDED_HOST'] : (isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : 'metahumans.one');
$host = trim(explode(',', $host)[0]);
$scheme = 'https';

$out = [
    'ok' => true,
    'server_time_utc' => gmdate('c'),
    'checks' => [],
];

$out['checks'][] = mh_diag_curl($scheme . '://' . $host . '/gear/meet/client-config.php', 6);
$out['checks'][] = mh_diag_curl($scheme . '://' . $host . '/gear/meet/client-config-real.php', 6);

try {
    $base = rtrim(pnm_get_base_url(), '/');
    $out['checks'][] = mh_diag_curl($base . '/', 8);
    $out['checks'][] = mh_diag_curl($base . '/api/health', 8);
} catch (Throwable $e) {
    $out['checks'][] = ['ok' => false, 'error' => 'pnm_config_unavailable', 'detail' => $e->getMessage()];
}

echo json_encode($out, JSON_UNESCAPED_SLASHES);

