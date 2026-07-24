<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_CLI_MODE', true);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';

cue_autoload('database');
cue_autoload('vector');
cue_autoload('graph');
cue_autoload('embeddings');

$isCli = php_sapi_name() === 'cli';
if (!$isCli) {
    if (function_exists('security_startSecureSession')) {
        security_startSecureSession();
    } elseif (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $u = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
    $r = isset($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
    $isKripz = $u !== '' && stripos($r, 'kripzmaster') !== false;
    if (!$isKripz) {
        http_response_code(404);
        echo 'Not found';
        exit;
    }
}

function mhb_try(callable $fn): array {
    try {
        $v = $fn();
        return ['ok' => true, 'result' => $v];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function mhb_http(string $method, string $url, ?string $body = null, array $headers = []): array {
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'status' => 0, 'error' => 'curl_init_failed', 'body' => ''];
    }
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    if ($headers !== []) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    $err = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    return ['ok' => $resp !== false && $err === '' && $status > 0, 'status' => $status, 'error' => $err, 'body' => is_string($resp) ? $resp : ''];
}

$checks = [];

$checks['db_biometrics'] = mhb_try(function () {
    $pdo = database_getConnectionById('biometrics');
    $stmt = $pdo->query('SELECT 1');
    $v = $stmt ? $stmt->fetchColumn() : null;
    return ['select_1' => $v];
});

$checks['db_default'] = mhb_try(function () {
    if (!function_exists('database_getConnection')) {
        throw new RuntimeException('database_getConnection_missing');
    }
    $pdo = database_getConnection();
    $stmt = $pdo->query('SELECT 1');
    $v = $stmt ? $stmt->fetchColumn() : null;
    return ['select_1' => $v];
});

$checks['qdrant_collections'] = mhb_try(function () {
    $cfg = function_exists('vector_config') ? vector_config() : ['qdrant_url' => ''];
    $base = rtrim((string)($cfg['qdrant_url'] ?? ''), '/');
    if ($base === '') {
        throw new RuntimeException('qdrant_url_missing');
    }
    $url = $base . '/collections';
    $r = mhb_http('GET', $url);
    if (!($r['ok'] ?? false) || (int)($r['status'] ?? 0) !== 200) {
        throw new RuntimeException('qdrant_http_' . (int)($r['status'] ?? 0));
    }
    $decoded = json_decode((string)($r['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('qdrant_bad_response');
    }
    $cols = $decoded['result']['collections'] ?? [];
    if (!is_array($cols)) $cols = [];
    $names = [];
    foreach ($cols as $c) {
        if (is_array($c) && isset($c['name']) && is_string($c['name'])) $names[] = $c['name'];
    }
    return ['count' => count($names), 'collections' => array_slice($names, 0, 20)];
});

$checks['qdrant_rejects_missing_tenant_filter'] = mhb_try(function () {
    $cfg = function_exists('vector_config') ? vector_config() : ['qdrant_url' => ''];
    $base = rtrim((string)($cfg['qdrant_url'] ?? ''), '/');
    if ($base === '') {
        throw new RuntimeException('qdrant_url_missing');
    }
    $url = $base . '/collections/mh_shard_0/points/search';
    $payload = json_encode(['vector' => [0.0, 0.0], 'limit' => 1, 'filter' => new stdClass()], JSON_UNESCAPED_SLASHES);
    $r = mhb_http('POST', $url, is_string($payload) ? $payload : '{}', ['Content-Type: application/json']);
    return [
        'status' => (int)($r['status'] ?? 0),
        'ok' => (int)($r['status'] ?? 0) === 403,
    ];
});

$checks['embeddings_length'] = mhb_try(function () {
    if (!function_exists('embeddings_embed_text')) {
        throw new RuntimeException('embeddings_module_missing');
    }
    $vec = embeddings_embed_text('hello');
    $len = is_array($vec) ? count($vec) : 0;
    $cfg = function_exists('vector_config') ? vector_config() : [];
    $expected = isset($cfg['vector_size']) ? (int)$cfg['vector_size'] : 1024;
    return ['len' => $len, 'expected' => $expected, 'ok' => $len === $expected];
});

$checks['neo4j_constraints'] = mhb_try(function () {
    if (!function_exists('graph_cypher')) {
        throw new RuntimeException('graph_module_missing');
    }
    $body = graph_cypher("SHOW CONSTRAINTS YIELD name RETURN name", []);
    $rows = $body['results'][0]['data'] ?? [];
    if (!is_array($rows)) $rows = [];
    $names = [];
    foreach ($rows as $r) {
        $row = $r['row'] ?? null;
        if (is_array($row) && isset($row[0]) && is_string($row[0])) $names[] = $row[0];
    }
    $required = ['entity_key', 'memory_key'];
    $present = [];
    foreach ($required as $n) {
        $present[$n] = in_array($n, $names, true);
    }
    return ['required' => $present, 'total' => count($names)];
});

$checks['graph_ingest_dry_run'] = mhb_try(function () {
    require_once dirname(__DIR__, 2) . '/hub/graph/daemon.php';
    if (!function_exists('mh_graph_daemon_ingest_all')) {
        throw new RuntimeException('graph_daemon_missing');
    }
    $r = mh_graph_daemon_ingest_all(25, true);
    if (!is_array($r) || (($r['ok'] ?? null) !== true)) {
        throw new RuntimeException('graph_dry_run_failed');
    }
    $total = (int)($r['total_processed'] ?? 0);
    $tenants = is_array($r['tenants'] ?? null) ? (array)$r['tenants'] : [];
    return ['ok' => true, 'total_processed' => $total, 'tenants_checked' => count($tenants)];
});

$checks['tenant_fs'] = mhb_try(function () {
    $dirs = glob('/data/tenants/*') ?: [];
    $count = 0;
    $sample = [];
    foreach ($dirs as $d) {
        if (!is_dir($d)) continue;
        $count++;
        if (count($sample) < 10) $sample[] = basename((string)$d);
    }
    return ['tenants' => $count, 'sample' => $sample];
});

$payload = json_encode(['ok' => true, 'checks' => $checks], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
if ($isCli) {
    echo $payload;
    exit;
}

$wantJson = false;
if (isset($_GET['format']) && is_string($_GET['format']) && strtolower(trim((string)$_GET['format'])) === 'json') {
    $wantJson = true;
} elseif (isset($_SERVER['HTTP_ACCEPT']) && is_string($_SERVER['HTTP_ACCEPT']) && stripos((string)$_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $wantJson = true;
}
if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo $payload;
    exit;
}

function mhb_status_row(string $key, array $check): array {
    $ok = (bool)($check['ok'] ?? false);
    $result = isset($check['result']) && is_array($check['result']) ? $check['result'] : null;
    $error = isset($check['error']) && is_string($check['error']) ? trim((string)$check['error']) : '';
    $title = $key;
    $summary = $ok ? 'OK' : ($error !== '' ? $error : 'Failed');

    if ($key === 'db_biometrics') {
        $title = 'Biometrics DB';
        $summary = $ok ? 'Connected' : $summary;
    } elseif ($key === 'db_default') {
        $title = 'Default DB';
        $summary = $ok ? 'Connected' : $summary;
    } elseif ($key === 'qdrant_collections') {
        $title = 'Qdrant Collections';
        if ($ok && is_array($result)) {
            $count = isset($result['count']) ? (int)$result['count'] : 0;
            $summary = $count > 0 ? ($count . ' collections') : 'No collections';
        }
    } elseif ($key === 'qdrant_rejects_missing_tenant_filter') {
        $title = 'Qdrant Enforcement';
        if ($ok && is_array($result)) {
            $status = isset($result['status']) ? (int)$result['status'] : 0;
            $summary = $status === 403 ? '403 as expected' : ('HTTP ' . $status);
        }
    } elseif ($key === 'embeddings_length') {
        $title = 'Embeddings';
        if ($ok && is_array($result)) {
            $len = isset($result['len']) ? (int)$result['len'] : 0;
            $expected = isset($result['expected']) ? (int)$result['expected'] : 0;
            $same = isset($result['ok']) ? (bool)$result['ok'] : false;
            $summary = $same ? ('Vector size ' . $len) : ('Vector size ' . $len . ' (expected ' . $expected . ')');
        }
    } elseif ($key === 'neo4j_constraints') {
        $title = 'Neo4j Constraints';
        if ($ok && is_array($result)) {
            $req = isset($result['required']) && is_array($result['required']) ? $result['required'] : [];
            $entity = !empty($req['entity_key']);
            $memory = !empty($req['memory_key']);
            $summary = ($entity && $memory) ? 'Required constraints present' : 'Missing required constraints';
        }
    } elseif ($key === 'graph_ingest_dry_run') {
        $title = 'Graph Ingest (Dry Run)';
        if ($ok && is_array($result)) {
            $total = isset($result['total_processed']) ? (int)$result['total_processed'] : 0;
            $tenants = isset($result['tenants_checked']) ? (int)$result['tenants_checked'] : 0;
            $summary = 'Tenants checked: ' . $tenants . ', processed: ' . $total;
        }
    } elseif ($key === 'tenant_fs') {
        $title = 'Tenant Filesystem';
        if ($ok && is_array($result)) {
            $tenants = isset($result['tenants']) ? (int)$result['tenants'] : 0;
            $summary = $tenants . ' tenant directories';
        }
    }
    return ['key' => $key, 'title' => $title, 'ok' => $ok, 'summary' => $summary, 'result' => $result, 'error' => $error];
}

$rows = [];
$pass = 0;
$fail = 0;
foreach ($checks as $k => $c) {
    if (!is_array($c)) continue;
    $row = mhb_status_row((string)$k, $c);
    $rows[] = $row;
    if (!empty($row['ok'])) $pass++; else $fail++;
}

header('Content-Type: text/html; charset=utf-8');
echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Validate Restore</title></head><body style="margin:0;font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;background:#0b1220;color:#e6f6ff;">';
echo '<div style="max-width:1180px;margin:0 auto;padding:22px 16px;">';
echo '<div style="display:flex;gap:12px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;">';
echo '<div><h1 style="margin:0 0 6px 0;font-size:22px;">Backup Validate / Restore</h1><div style="opacity:.85;font-size:12px;max-width:740px;">Read-only health checks for DB, vector, graph and embeddings. This page does not delete or modify data.</div></div>';
echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;justify-content:flex-end;">';
echo '<button id="mhbRerun" type="button" style="cursor:pointer;border-radius:12px;border:1px solid rgba(0,212,255,.45);background:rgba(0,212,255,.12);color:#e6f6ff;padding:10px 12px;font-weight:900;">Re-run</button>';
echo '<a href="?format=json" style="text-decoration:none;cursor:pointer;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#e6f6ff;padding:10px 12px;font-weight:900;">View JSON</a>';
echo '<button id="mhbCopy" type="button" style="cursor:pointer;border-radius:12px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.06);color:#e6f6ff;padding:10px 12px;font-weight:900;">Copy JSON</button>';
echo '</div></div>';

echo '<div style="margin-top:14px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
echo '<div style="border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.22);border-radius:12px;padding:10px 12px;">Checks: <strong>' . count($rows) . '</strong></div>';
echo '<div style="border:1px solid rgba(0,200,81,.35);background:rgba(0,200,81,.08);border-radius:12px;padding:10px 12px;color:#7fffb5;">Pass: <strong>' . $pass . '</strong></div>';
echo '<div style="border:1px solid rgba(255,68,68,.35);background:rgba(255,68,68,.08);border-radius:12px;padding:10px 12px;color:#ffb1b1;">Fail: <strong>' . $fail . '</strong></div>';
echo '</div>';

echo '<div style="margin-top:14px;border:1px solid rgba(255,255,255,.12);background:rgba(0,0,0,.22);border-radius:14px;overflow:hidden;">';
echo '<table style="width:100%;border-collapse:collapse;">';
echo '<thead><tr style="background:rgba(255,255,255,.04);"><th style="text-align:left;padding:12px 12px;font-size:12px;opacity:.9;">Check</th><th style="text-align:left;padding:12px 12px;font-size:12px;opacity:.9;">Status</th><th style="text-align:left;padding:12px 12px;font-size:12px;opacity:.9;">Details</th></tr></thead>';
echo '<tbody>';
foreach ($rows as $row) {
    $ok = !empty($row['ok']);
    $statusText = $ok ? 'OK' : 'FAIL';
    $statusColor = $ok ? '#7fffb5' : '#ffb1b1';
    $border = $ok ? 'rgba(0,200,81,.25)' : 'rgba(255,68,68,.25)';
    $details = htmlspecialchars((string)($row['summary'] ?? ''), ENT_QUOTES);
    $title = htmlspecialchars((string)($row['title'] ?? ''), ENT_QUOTES);
    $key = htmlspecialchars((string)($row['key'] ?? ''), ENT_QUOTES);
    echo '<tr style="border-top:1px solid rgba(255,255,255,.06);">';
    echo '<td style="padding:12px 12px;vertical-align:top;"><div style="font-weight:900;">' . $title . '</div><div style="opacity:.65;font-size:12px;margin-top:2px;">' . $key . '</div></td>';
    echo '<td style="padding:12px 12px;vertical-align:top;"><span style="display:inline-flex;align-items:center;gap:8px;border:1px solid ' . $border . ';background:rgba(0,0,0,.18);border-radius:999px;padding:6px 10px;color:' . $statusColor . ';font-weight:950;">' . $statusText . '</span></td>';
    echo '<td style="padding:12px 12px;vertical-align:top;">' . $details;
    if (!$ok && (string)($row['key'] ?? '') === 'embeddings_length') {
        echo '<div style="margin-top:8px;opacity:.85;font-size:12px;line-height:1.35;">Embeddings are required for vector search/memory. Check the embeddings service config and ensure the embeddings endpoint is reachable from PHP.</div>';
    }
    $res = $row['result'] ?? null;
    if (is_array($res) && $res !== []) {
        $resJson = json_encode($res, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (is_string($resJson) && $resJson !== '') {
            echo '<details style="margin-top:10px;"><summary style="cursor:pointer;opacity:.85;">Raw result</summary><pre style="margin:8px 0 0 0;white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:12px;">' . htmlspecialchars($resJson, ENT_QUOTES) . '</pre></details>';
        }
    }
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table></div>';

echo '<details style="margin-top:14px;"><summary style="cursor:pointer;opacity:.85;">Raw JSON output</summary><pre style="white-space:pre-wrap;word-break:break-word;background:rgba(255,255,255,.04);border:1px solid rgba(0,212,255,.18);border-radius:12px;padding:14px;line-height:1.4;margin-top:10px;">' . htmlspecialchars($payload, ENT_QUOTES) . '</pre></details>';

echo '</div>';
echo '<script>(function(){var p=' . json_encode($payload, JSON_UNESCAPED_SLASHES) . ';var r=document.getElementById(\"mhbRerun\");if(r)r.onclick=function(){location.reload()};var c=document.getElementById(\"mhbCopy\");if(c)c.onclick=async function(){try{await navigator.clipboard.writeText(p);c.textContent=\"Copied\";setTimeout(function(){c.textContent=\"Copy JSON\"},1200)}catch(e){}}})();</script>';
echo '</body></html>';
