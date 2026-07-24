<?php
define('CUE_DISABLE_AUTO_UI', true);
require_once dirname(dirname(dirname(__DIR__))) . '/.cue/cue.php';
require_once dirname(dirname(dirname(__DIR__))) . '/auth/auth_functions.php';

function wantsJson(array $input): bool
{
    if (isset($input['format']) && (string)$input['format'] === 'json') {
        return true;
    }
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    if (stripos($accept, 'application/json') !== false) {
        return true;
    }
    $xhr = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    return stripos($xhr, 'xmlhttprequest') !== false;
}

function respondJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function respondHtml(int $statusCode, string $html): void
{
    http_response_code($statusCode);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
    exit;
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function curlJson(string $method, string $url, ?array $body, int $timeoutSeconds): array
{
    $ch = curl_init();
    if ($ch === false) {
        respondJson(500, ['ok' => false, 'error' => 'curl_init_failed']);
    }

    $headers = ['Accept: application/json'];
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeoutSeconds));
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSeconds);

    if ($body !== null) {
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            respondJson(400, ['ok' => false, 'error' => 'invalid_json_body']);
        }
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $respBody = curl_exec($ch);
    $curlErr = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($respBody === false) {
        respondJson(502, ['ok' => false, 'error' => 'upstream_unreachable', 'detail' => $curlErr]);
    }

    $decoded = json_decode((string)$respBody, true);
    if (!is_array($decoded)) {
        respondJson(502, ['ok' => false, 'error' => 'upstream_bad_json', 'status' => $status, 'body' => substr((string)$respBody, 0, 2000)]);
    }

    return [$status, $decoded];
}

function streamMp4(string $filePath): void
{
    if (!is_file($filePath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Video not found.';
        exit;
    }

    $size = filesize($filePath);
    if (!is_int($size) || $size <= 0) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Video not found.';
        exit;
    }

    $fh = fopen($filePath, 'rb');
    if ($fh === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Failed to open video.';
        exit;
    }

    $start = 0;
    $end = $size - 1;
    $status = 200;

    $range = $_SERVER['HTTP_RANGE'] ?? '';
    if (preg_match('/bytes=(\\d+)-(\\d*)/', $range, $m)) {
        $start = (int)$m[1];
        if ($m[2] !== '') {
            $end = min($end, (int)$m[2]);
        }
        if ($start <= $end) {
            $status = 206;
        } else {
            fclose($fh);
            http_response_code(416);
            header('Content-Range: bytes */' . $size);
            exit;
        }
    }

    $length = $end - $start + 1;
    http_response_code($status);
    header('Content-Type: video/mp4');
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . $length);
    if ($status === 206) {
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Disposition: inline; filename="video.mp4"');

    fseek($fh, $start);
    $remaining = $length;
    while ($remaining > 0 && !feof($fh)) {
        $chunk = fread($fh, (int)min(1024 * 1024, $remaining));
        if ($chunk === false || $chunk === '') {
            break;
        }
        echo $chunk;
        $remaining -= strlen($chunk);
        flush();
    }
    fclose($fh);
    exit;
}

function deleteTree(string $dirPath): bool
{
    if (!is_dir($dirPath)) {
        return false;
    }
    $it = new RecursiveDirectoryIterator($dirPath, FilesystemIterator::SKIP_DOTS);
    $ri = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($ri as $item) {
        $p = $item->getPathname();
        if ($item->isDir()) {
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }
    return @rmdir($dirPath);
}

function sanitizeUrlString(string $s): string
{
    $s = trim($s);
    $s = trim($s, " \t\n\r\0\x0B`");
    return $s;
}

function isKripzMasterSession(): bool
{
    $role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
    return stripos($role, 'kripzmaster') !== false;
}

function jobOwnerFromMarker(string $jobDir): ?string
{
    $p = rtrim($jobDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'owner.json';
    if (!is_file($p)) {
        return null;
    }
    $raw = @file_get_contents($p);
    if (!is_string($raw) || trim($raw) === '') {
        return null;
    }
    $j = json_decode($raw, true);
    if (!is_array($j)) {
        return null;
    }
    $u = isset($j['user']) ? trim((string)$j['user']) : '';
    return $u !== '' ? $u : null;
}

function writeJobOwnerMarker(string $jobId, string $username): void
{
    $jobId = trim($jobId);
    $username = trim($username);
    if ($jobId === '' || $username === '') {
        return;
    }
    $outputRoot = dirname(__DIR__) . '/video/output';
    $jobDir = $outputRoot . DIRECTORY_SEPARATOR . $jobId;
    if (!is_dir($jobDir)) {
        @mkdir($jobDir, 0775, true);
    }
    $markerPath = $jobDir . DIRECTORY_SEPARATOR . 'owner.json';
    if (!is_file($markerPath)) {
        $payload = json_encode(['user' => $username, 'created_ts' => time()], JSON_UNESCAPED_SLASHES);
        if (is_string($payload) && $payload !== '') {
            @file_put_contents($markerPath, $payload);
        }
    }
    @chmod($jobDir, 02775);
    @chmod($markerPath, 0664);
}

function isSafeVideoUrl(string $url): bool
{
    $url = sanitizeUrlString($url);
    $u = parse_url($url);
    if (!is_array($u)) {
        return false;
    }
    $scheme = strtolower((string)($u['scheme'] ?? ''));
    if ($scheme !== 'https' && $scheme !== 'http') {
        return false;
    }
    $host = strtolower((string)($u['host'] ?? ''));
    if ($host !== 'metahumans.one' && $host !== 'usa.metahumans.one') {
        return false;
    }
    $path = (string)($u['path'] ?? '');
    return str_starts_with($path, '/gear/generators/video/output/');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = array_merge($_GET, $_POST, readJsonBody());
$asJson = wantsJson($input);

if (!isset($_SESSION['mh_auth_user'])) {
    if ($asJson) {
        respondJson(401, ['ok' => false, 'error' => 'unauthorized']);
    }
    header('Location: /auth/login.php');
    exit;
}

$configPath = dirname(dirname(dirname(__DIR__))) . '/ai/vimax.json';
$cfgRaw = @file_get_contents($configPath);
$cfg = is_string($cfgRaw) ? json_decode($cfgRaw, true) : null;
$baseUrl = function_exists('mh_internal_endpoint_url') ? (string)mh_internal_endpoint_url('vimax') : '';
$baseUrl = is_string($baseUrl) ? rtrim(trim($baseUrl), '/') : '';
if ($baseUrl === '') {
    $baseUrl = is_array($cfg) ? rtrim(trim((string)($cfg['base_url'] ?? '')), '/') : '';
}
$jobsPath = is_array($cfg) ? (string)($cfg['jobs_path'] ?? '/jobs') : '/jobs';
$generatePath = is_array($cfg) ? (string)($cfg['generate_path'] ?? '/generate') : '/generate';
$timeoutSeconds = is_array($cfg) ? (int)($cfg['timeout_seconds'] ?? 20) : 20;
if ($timeoutSeconds <= 0) {
    $timeoutSeconds = 20;
}

$jobId = isset($input['job_id']) ? (string)$input['job_id'] : '';
if ($jobId !== '') {
    if (!preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $jobId)) {
        if ($asJson) {
            respondJson(400, ['ok' => false, 'error' => 'invalid_job_id']);
        }
        respondHtml(400, '<!doctype html><meta charset="utf-8"><title>Video</title><p>Invalid job id.</p>');
    }

    $delete = isset($input['delete']) && (string)$input['delete'] === '1';
    if ($delete) {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            respondJson(405, ['ok' => false, 'error' => 'method_not_allowed']);
        }

        $outputRoot = realpath(dirname(__DIR__) . '/video/output');
        if ($outputRoot === false || !is_dir($outputRoot)) {
            respondJson(500, ['ok' => false, 'error' => 'output_root_missing']);
        }

        $candidate = $outputRoot . DIRECTORY_SEPARATOR . $jobId;
        $resolved = realpath($candidate);
        if ($resolved === false || !is_dir($resolved) || strncmp($resolved, $outputRoot . DIRECTORY_SEPARATOR, strlen($outputRoot) + 1) !== 0) {
            respondJson(404, ['ok' => false, 'error' => 'job_not_found']);
        }

        $sessionUser = trim((string)($_SESSION['mh_auth_user'] ?? ''));
        if (!isKripzMasterSession() && $sessionUser !== '') {
            $owner = jobOwnerFromMarker($resolved);
            if (is_string($owner) && $owner !== '' && strcasecmp($owner, $sessionUser) !== 0) {
                respondJson(403, ['ok' => false, 'error' => 'owner_mismatch', 'job_id' => $jobId]);
            }
            if ($owner === null && $baseUrl !== '') {
                $jobsUrl = $baseUrl . '/' . trim($jobsPath, '/') . '/' . rawurlencode($jobId);
                [$st, $j] = curlJson('GET', $jobsUrl, null, 5);
                if ($st >= 200 && $st < 300 && is_array($j) && isset($j['user']) && is_string($j['user']) && trim($j['user']) !== '') {
                    if (strcasecmp(trim((string)$j['user']), $sessionUser) !== 0) {
                        respondJson(403, ['ok' => false, 'error' => 'owner_mismatch', 'job_id' => $jobId]);
                    }
                }
            }
        }

        if (!is_writable($outputRoot) || !is_writable($resolved)) {
            respondJson(200, [
                'ok' => false,
                'error' => 'permission_denied',
                'job_id' => $jobId,
                'output_root' => $outputRoot,
            ]);
        }

        $ok = deleteTree($resolved);
        respondJson(200, ['ok' => $ok, 'job_id' => $jobId, 'error' => $ok ? null : 'delete_failed']);
    }

    $download = isset($input['download']) && (string)$input['download'] === '1';
    if ($download) {
        $candidates = [
            dirname(__DIR__) . '/video/output/' . $jobId . '/video.mp4',
        ];
        foreach ($candidates as $p) {
            if (is_file($p)) {
                streamMp4($p);
            }
        }
        if ($baseUrl !== '') {
            $jobsUrl = $baseUrl . '/' . trim($jobsPath, '/') . '/' . rawurlencode($jobId);
            [$status, $payload] = curlJson('GET', $jobsUrl, null, 10);
            if ($status >= 200 && $status < 300) {
                $videoUrl = '';
                if (isset($payload['video_url']) && is_string($payload['video_url'])) {
                    $videoUrl = sanitizeUrlString($payload['video_url']);
                } elseif (isset($payload['planned_video_url']) && is_string($payload['planned_video_url'])) {
                    $videoUrl = sanitizeUrlString($payload['planned_video_url']);
                }
                if ($videoUrl !== '' && isSafeVideoUrl($videoUrl)) {
                    header('Location: ' . $videoUrl, true, 302);
                    exit;
                }
            }
        }
        streamMp4($candidates[0]);
    }

    if ($baseUrl === '') {
        if ($asJson) {
            respondJson(503, ['ok' => false, 'error' => 'missing_base_url']);
        }
        respondHtml(503, '<!doctype html><meta charset="utf-8"><title>Video</title><p>Video backend not configured.</p>');
    }

    $jobsUrl = $baseUrl . '/' . trim($jobsPath, '/') . '/' . rawurlencode($jobId);
    [$status, $payload] = curlJson('GET', $jobsUrl, null, 10);

    if (isset($payload['video_url']) && is_string($payload['video_url'])) {
        $payload['video_url'] = sanitizeUrlString($payload['video_url']);
    }
    if (isset($payload['planned_video_url']) && is_string($payload['planned_video_url'])) {
        $payload['planned_video_url'] = sanitizeUrlString($payload['planned_video_url']);
    }

    if ($asJson) {
        respondJson($status >= 200 && $status < 300 ? 200 : 502, $payload);
    }

    $safeJob = htmlspecialchars($jobId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<!doctype html><meta charset="utf-8"><title>Video Job</title>';
    $html .= '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;margin:24px;max-width:900px} .row{margin:10px 0} .bar{height:10px;background:#eee;border-radius:8px;overflow:hidden} .bar>div{height:100%;background:#2563eb;width:0%} .muted{color:#555} .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace}</style>';
    $html .= '<h1>Video Job</h1>';
    $html .= '<div class="row muted">Job ID: <span class="mono">' . $safeJob . '</span></div>';
    $html .= '<div class="row">Status: <strong id="status">Loading…</strong></div>';
    $html .= '<div class="row muted" id="stage"></div>';
    $html .= '<div class="row bar"><div id="bar"></div></div>';
    $html .= '<div class="row" id="links"></div>';
    $html .= '<div class="row"><video id="player" controls style="width:100%;max-width:900px;display:none"></video></div>';
    $html .= '<script>
    const jobId = ' . json_encode($jobId) . ';
    async function tick() {
      const r = await fetch(`generate.php?job_id=${encodeURIComponent(jobId)}&format=json`, {cache: "no-store", headers: {"Accept":"application/json"}});
      const d = await r.json();
      const status = document.getElementById("status");
      const stage = document.getElementById("stage");
      const bar = document.getElementById("bar");
      const links = document.getElementById("links");
      const player = document.getElementById("player");
      status.textContent = d.status || "unknown";
      const pct = Math.max(0, Math.min(100, Math.round((d.progress ?? 0) * 100)));
      stage.textContent = (d.stage ? (d.stage + " · ") : "") + pct + "%";
      bar.style.width = pct + "%";
      if (d.status === "completed") {
        const proxy = `generate.php?job_id=${encodeURIComponent(jobId)}&download=1`;
        if (d.video_url) {
          links.innerHTML = `<a href="${d.video_url}" download>Download video</a> · <a href="${proxy}" download>Download (fallback)</a>`;
          try { const head = await fetch(d.video_url, {method:"HEAD", cache:"no-store"}); player.src = head.ok ? d.video_url : proxy; } catch (_) { player.src = proxy; }
        } else {
          links.innerHTML = `<a href="${proxy}" download>Download (fallback)</a>`;
          player.src = proxy;
        }
        player.style.display = "block";
        return;
      }
      if (d.status === "failed") {
        links.textContent = d.error ? ("Failed: " + d.error) : "Failed.";
        return;
      }
      setTimeout(tick, 1200);
    }
    tick();
    </script>';
    respondHtml(200, $html);
}

$prompt = isset($input['prompt']) ? trim((string)$input['prompt']) : '';
if ($prompt === '') {
    if ($asJson) {
        respondJson(400, ['ok' => false, 'error' => 'missing_prompt']);
    }
    respondHtml(400, '<!doctype html><meta charset="utf-8"><title>Video</title><p>Missing prompt.</p>');
}

if ($baseUrl === '') {
    if ($asJson) {
        respondJson(503, ['ok' => false, 'error' => 'missing_base_url']);
    }
    respondHtml(503, '<!doctype html><meta charset="utf-8"><title>Video</title><p>Video backend not configured.</p>');
}

$mode = isset($input['mode']) ? (string)$input['mode'] : null;
$seed = isset($input['seed']) && $input['seed'] !== '' ? (int)$input['seed'] : null;
$durationSeconds = isset($input['duration_seconds']) ? (int)$input['duration_seconds'] : 6;
$initImageB64 = isset($input['init_image_b64']) ? (string)$input['init_image_b64'] : null;

$url = $baseUrl . '/' . ltrim($generatePath, '/');
$payload = [
    'prompt' => $prompt,
    'mode' => $mode,
    'seed' => $seed,
    'duration_seconds' => $durationSeconds,
    'user' => (string)($_SESSION['mh_auth_user'] ?? ''),
    'init_image_b64' => $initImageB64,
];

[$status, $resp] = curlJson('POST', $url, $payload, $timeoutSeconds);

if ($status < 200 || $status >= 300) {
    $msg = null;
    if (isset($resp['error']) && is_string($resp['error']) && trim($resp['error']) !== '') {
        $msg = trim($resp['error']);
    } elseif (isset($resp['detail'])) {
        $detail = $resp['detail'];
        if (is_string($detail) && trim($detail) !== '') {
            $msg = trim($detail);
        } elseif (is_array($detail)) {
            $msg = json_encode($detail, JSON_UNESCAPED_SLASHES);
        }
    } elseif (isset($resp['message']) && is_string($resp['message']) && trim($resp['message']) !== '') {
        $msg = trim($resp['message']);
    }

    $code = ($status >= 400 && $status < 500) ? 400 : 502;
    respondJson($code, [
        'ok' => false,
        'error' => 'upstream_rejected',
        'status' => $status,
        'message' => $msg,
        'upstream' => $resp,
    ]);
}

$newJobId = isset($resp['job_id']) ? (string)$resp['job_id'] : '';
if ($newJobId === '') {
    $err = null;
    if (isset($resp['error']) && is_string($resp['error']) && trim($resp['error']) !== '') {
        $err = trim($resp['error']);
    } elseif (isset($resp['detail']) && is_string($resp['detail']) && trim($resp['detail']) !== '') {
        $err = trim($resp['detail']);
    } elseif (isset($resp['message']) && is_string($resp['message']) && trim($resp['message']) !== '') {
        $err = trim($resp['message']);
    }
    respondJson(502, ['ok' => false, 'error' => 'upstream_missing_job_id', 'message' => $err, 'upstream' => $resp]);
}

$sessionUser = trim((string)($_SESSION['mh_auth_user'] ?? ''));
if ($sessionUser !== '') {
    writeJobOwnerMarker($newJobId, $sessionUser);
}

$pollUrl = '/gear/generators/video/generate.php?job_id=' . rawurlencode($newJobId);
$resp['poll_url'] = $pollUrl;

if ($asJson) {
    respondJson(200, $resp);
}

header('Location: ' . $pollUrl, true, 303);
exit;
?>
