<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/core.php';

function mh_pdf_migrate_arg(string $name, $default = null) {
    global $argv;
    foreach ($argv as $a) {
        if (!is_string($a)) continue;
        if (strpos($a, '--' . $name . '=') === 0) {
            return substr($a, strlen('--' . $name . '='));
        }
        if ($a === '--' . $name) {
            return true;
        }
    }
    return $default;
}

$dbPath = (string)mh_pdf_migrate_arg('db', '/data/pdf-stack/pdf-editor/wopi.db');
$dryRun = (bool)mh_pdf_migrate_arg('dry-run', false);
$archive = (bool)mh_pdf_migrate_arg('archive', true);

if ($dbPath === '' || !is_file($dbPath)) {
    fwrite(STDERR, "missing_db\n");
    exit(2);
}

try {
    $src = new PDO('sqlite:' . $dbPath);
    $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    fwrite(STDERR, "sqlite_open_failed\n");
    exit(2);
}

$rows = [];
try {
    $stmt = $src->query('SELECT id, owner_id, filename, path, token, token_expires_at, version, created_at, updated_at FROM wopi_files');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    fwrite(STDERR, "sqlite_read_failed\n");
    exit(2);
}

$migrated = 0;
$skipped = 0;
$errors = 0;
$byOwner = [];

foreach ($rows as $r) {
    $id = isset($r['id']) ? trim((string)$r['id']) : '';
    $owner = isset($r['owner_id']) ? trim((string)$r['owner_id']) : '';
    $filename = isset($r['filename']) ? (string)$r['filename'] : '';
    $path = isset($r['path']) ? (string)$r['path'] : '';
    $token = isset($r['token']) ? (string)$r['token'] : '';
    $tokenExp = isset($r['token_expires_at']) ? (int)$r['token_expires_at'] : 0;
    $version = isset($r['version']) ? (int)$r['version'] : 1;
    $createdAt = isset($r['created_at']) ? (int)$r['created_at'] : 0;
    $updatedAt = isset($r['updated_at']) ? (int)$r['updated_at'] : 0;

    if ($id === '' || $owner === '' || preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
        $skipped++;
        continue;
    }

    try {
        $pdo = mh_pdf_editor_tenant_db($owner);
        $t = mh_pdf_editor_table();
        if ($dryRun) {
            $migrated++;
            $byOwner[$owner] = ($byOwner[$owner] ?? 0) + 1;
            continue;
        }
        $sql = "INSERT INTO `{$t}` (id, owner_id, filename, path, token, token_expires_at, version, created_at, updated_at)\n"
            . "VALUES (:id, :owner_id, :filename, :path, :token, :token_expires_at, :version, :created_at, :updated_at)\n"
            . "ON DUPLICATE KEY UPDATE\n"
            . "filename=VALUES(filename), path=VALUES(path), token=VALUES(token), token_expires_at=VALUES(token_expires_at),\n"
            . "version=GREATEST(version, VALUES(version)), updated_at=GREATEST(updated_at, VALUES(updated_at))";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':owner_id' => $owner,
            ':filename' => $filename,
            ':path' => $path,
            ':token' => $token,
            ':token_expires_at' => $tokenExp,
            ':version' => max(1, $version),
            ':created_at' => $createdAt > 0 ? $createdAt : time(),
            ':updated_at' => $updatedAt > 0 ? $updatedAt : time(),
        ]);
        $migrated++;
        $byOwner[$owner] = ($byOwner[$owner] ?? 0) + 1;
    } catch (Throwable $e) {
        $errors++;
    }
}

$result = [
    'ok' => $errors === 0,
    'db' => $dbPath,
    'dry_run' => $dryRun,
    'rows' => count($rows),
    'migrated' => $migrated,
    'skipped' => $skipped,
    'errors' => $errors,
    'by_owner' => $byOwner,
    'ts' => time(),
];

if (!$dryRun && $archive) {
    $suffix = gmdate('Ymd_His');
    $archived = $dbPath . '.migrated.' . $suffix;
    if (@rename($dbPath, $archived)) {
        $result['archived_to'] = $archived;
        foreach (['-wal', '-shm'] as $ext) {
            $sidecar = $dbPath . $ext;
            if (is_file($sidecar)) {
                @rename($sidecar, $archived . $ext);
            }
        }
    } else {
        $result['archive_failed'] = true;
    }
}

echo json_encode($result, JSON_UNESCAPED_SLASHES) . "\n";
exit($result['ok'] ? 0 : 2);
