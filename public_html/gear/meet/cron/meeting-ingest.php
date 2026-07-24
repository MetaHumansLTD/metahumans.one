<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/.cue/cue.php';
require_once dirname(__DIR__, 2) . '/calendar/calendar_helpers.php';
require_once dirname(__DIR__) . '/meet_helpers.php';
require_once dirname(__DIR__, 3) . '/auth/tenant_provisioning.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

function mh_ingest_tenant_safe(string $username): string
{
    $tenantId = trim($username);
    if ($tenantId === '') {
        $tenantId = 'user:unknown';
    }
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') {
            return $safe;
        }
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}

function mh_ingest_meeting_root(string $tenantSafe, string $roomId): string
{
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    return $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
}

function mh_ingest_mkdir(string $dir): void
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
}

function mh_ingest_safe_filename(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $name);
    $name = trim($name, "._-");
    if ($name === '') {
        $name = 'file';
    }
    return $name;
}

function mh_ingest_download_to(string $url, string $destPath, int $timeout = 120): bool
{
    $fp = @fopen($destPath . '.part', 'wb');
    if (!is_resource($fp)) {
        return false;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        @fclose($fp);
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FAILONERROR => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $ok = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ch = null;
    @fclose($fp);

    if ($ok === false || $http < 200 || $http >= 300) {
        @unlink($destPath . '.part');
        return false;
    }

    return @rename($destPath . '.part', $destPath);
}

function mh_ingest_load_index(string $path): array
{
    if (!is_file($path)) {
        return ['version' => 1, 'items' => []];
    }
    $raw = @file_get_contents($path);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return ['version' => 1, 'items' => []];
    }
    return $decoded;
}

function mh_ingest_save_index(string $path, array $index): void
{
    $tmp = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($tmp) || $tmp === '') {
        return;
    }
    @file_put_contents($path, $tmp . "\n", LOCK_EX);
}

function mh_list_tenant_db_config_ids(): array
{
    if (function_exists('cue_autoload')) {
        cue_autoload('database');
    }
    if (!function_exists('database_loadConfigurations')) {
        return [];
    }
    $cfgs = database_loadConfigurations();
    if (!is_array($cfgs)) {
        return [];
    }
    $out = [];
    foreach ($cfgs as $id => $cfg) {
        if (!is_string($id) || $id === '' || !is_array($cfg)) {
            continue;
        }
        $ctx = isset($cfg['context']) ? strtolower(trim((string)$cfg['context'])) : '';
        $name = isset($cfg['name']) ? strtolower(trim((string)$cfg['name'])) : '';
        $db = isset($cfg['database']) ? strtolower(trim((string)$cfg['database'])) : '';
        if ($ctx === 'tenant' || strpos($id, 'tenant_') === 0 || strpos($name, 'tenant_') === 0 || strpos($name, 'tenant:') === 0 || strpos($db, 'tenant_') === 0) {
            $out[] = $id;
        }
    }
    sort($out);
    return $out;
}

$tenantDbIds = mh_list_tenant_db_config_ids();
if ($tenantDbIds === []) {
    fwrite(STDERR, "no_tenant_dbs\n");
    exit(0);
}

$ingestedCount = 0;
$scannedMeetings = 0;

foreach ($tenantDbIds as $dbId) {
    $tenantId = function_exists('mh_find_tenant_id_by_db_config_id') ? mh_find_tenant_id_by_db_config_id($dbId) : null;
    if (!is_string($tenantId) || trim($tenantId) === '') {
        continue;
    }
    $tenantId = trim($tenantId);
    try {
        $db = database_getConnectionById($dbId);
    } catch (Throwable) {
        continue;
    }
    if (!$db instanceof PDO) {
        continue;
    }
    calendar_ensure_tables($db);

    $stmt = $db->prepare("
        SELECT id, room_id, created_by_user
        FROM mh_meetings
        WHERE created_by_user IS NOT NULL AND created_by_user <> ''
        ORDER BY id DESC
        LIMIT 50
    ");
    $stmt->execute();
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($meetings) || $meetings === []) {
        continue;
    }

    foreach ($meetings as $m) {
        $scannedMeetings++;
        $roomId = isset($m['room_id']) ? trim((string)$m['room_id']) : '';
        $user = isset($m['created_by_user']) ? trim((string)$m['created_by_user']) : '';
        if ($roomId === '' || $user === '') {
            continue;
        }

    $tenantSafe = mh_ingest_tenant_safe($tenantId);
    $meetingRoot = mh_ingest_meeting_root($tenantSafe, $roomId);
    $recordingsDir = $meetingRoot . '/recordings';
    $transcriptsDir = $meetingRoot . '/transcripts';
    mh_ingest_mkdir($recordingsDir);
    mh_ingest_mkdir($transcriptsDir);

    $indexPath = $recordingsDir . '/index.json';
    $index = mh_ingest_load_index($indexPath);
    $items = isset($index['items']) && is_array($index['items']) ? $index['items'] : [];
    $known = [];
    foreach ($items as $it) {
        if (is_array($it) && isset($it['record_id']) && is_string($it['record_id'])) {
            $known[$it['record_id']] = true;
        }
    }

    try {
        $resp = pnm_fetch_recordings_helper([$roomId], 0, 50, 'DESC');
    } catch (Throwable) {
        continue;
    }

    $result = $resp['result'] ?? null;
    if (!is_array($result)) {
        continue;
    }
    $list = $result['recordings_list'] ?? null;
    if (!is_array($list) || $list === []) {
        continue;
    }

    foreach ($list as $r) {
        if (!is_array($r)) {
            continue;
        }
        $recordId = isset($r['record_id']) ? trim((string)$r['record_id']) : '';
        if ($recordId === '' || isset($known[$recordId])) {
            continue;
        }

        $filePath = isset($r['file_path']) ? (string)$r['file_path'] : '';
        $ext = '';
        $baseName = '';
        if ($filePath !== '') {
            $baseName = basename($filePath);
            $dot = strrpos($baseName, '.');
            if ($dot !== false) {
                $ext = substr($baseName, $dot);
                if (strlen($ext) > 8) {
                    $ext = '';
                }
            }
        }
        if ($ext === '') {
            $ext = '.mp4';
        }
        $destName = mh_ingest_safe_filename($recordId) . $ext;
        $destPath = $recordingsDir . '/' . $destName;

        try {
            $t = pnm_get_recording_download_token_helper($recordId);
        } catch (Throwable) {
            continue;
        }
        $token = isset($t['token']) ? trim((string)$t['token']) : '';
        if ($token === '') {
            continue;
        }

        $urls = pnm_build_recording_download_urls($token);
        $downloaded = false;
        foreach ($urls as $u) {
            if (mh_ingest_download_to($u, $destPath, 240)) {
                $downloaded = true;
                break;
            }
        }
        if (!$downloaded) {
            continue;
        }

        $metaPath = $recordingsDir . '/' . mh_ingest_safe_filename($recordId) . '.json';
        $meta = [
            'record_id' => $recordId,
            'room_id' => $roomId,
            'tenant_id' => $tenantId,
            'created_by_user' => $user,
            'downloaded_at_utc' => gmdate('c'),
            'local_file' => basename($destPath),
            'source' => $r,
        ];
        @file_put_contents($metaPath, json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

        $metadata = $r['metadata'] ?? null;
        if (is_array($metadata)) {
            $subs = $metadata['subtitles'] ?? null;
            if (is_array($subs)) {
                foreach ($subs as $lang => $sub) {
                    if (!is_string($lang) || !is_array($sub)) {
                        continue;
                    }
                    $subUrl = isset($sub['url']) ? trim((string)$sub['url']) : '';
                    if ($subUrl === '') {
                        continue;
                    }
                    $subDest = $transcriptsDir . '/' . mh_ingest_safe_filename($recordId) . '_' . mh_ingest_safe_filename($lang) . '.vtt';
                    if (!is_file($subDest)) {
                        mh_ingest_download_to($subUrl, $subDest, 60);
                    }
                }
            }
        }

        $items[] = [
            'record_id' => $recordId,
            'local_file' => basename($destPath),
            'meta_file' => basename($metaPath),
            'downloaded_at_utc' => gmdate('c'),
        ];
        $known[$recordId] = true;
        $ingestedCount++;
    }

    $index['items'] = $items;
    $index['updated_at_utc'] = gmdate('c');
    mh_ingest_save_index($indexPath, $index);
    }
}

fwrite(STDOUT, "ok scanned=" . $scannedMeetings . " ingested=" . $ingestedCount . "\n");
