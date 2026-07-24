<?php
define('CUE_DISABLE_AUTO_UI', true);
define('CUE_DISABLE_AUTO_LAYOUT', true);
define('CUE_CLI_MODE', true);

$root = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
require_once $root . '/.cue/cue.php';
cue_autoload('database');

$paths = cue_autoload('paths');
$cfgRoot = (string)$paths->getConfigPath();
$dbConfigsFile = $cfgRoot . '/db_configs.json';
$ctxFile = $cfgRoot . '/database-contexts.json';

$result = [
    'ok' => true,
    'warnings' => [],
    'files' => [
        'db_configs' => $dbConfigsFile,
        'contexts' => $ctxFile,
    ],
];

$configs = [];
if (file_exists($dbConfigsFile)) {
    $raw = file_get_contents($dbConfigsFile);
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) $configs = $decoded;
}

$contexts = null;
if (file_exists($ctxFile)) {
    $raw = file_get_contents($ctxFile);
    $decoded = json_decode((string)$raw, true);
    if (is_array($decoded)) $contexts = $decoded;
}

$resolveConfig = function (string $id) use ($configs): ?array {
    if ($id === '') return null;
    $c = $configs[$id] ?? null;
    return is_array($c) ? $c : null;
};

$isWhm = function (?array $cfg): bool {
    if (!is_array($cfg)) return false;
    $port = (string)($cfg['port'] ?? '');
    $profile = (string)($cfg['storage_profile'] ?? '');
    if ($profile !== '' && $profile === 'whm_mysql') return true;
    return $port === '3306';
};

if (is_array($contexts)) {
    $targets = [
        '/templates/menus' => null,
        '/templates/global-ui' => null,
    ];
    $pageMappings = $contexts['page_mappings'] ?? null;
    $dirMappings = $contexts['directory_mappings'] ?? null;
    if (is_array($pageMappings)) {
        foreach ($targets as $k => $_) {
            if (isset($pageMappings[$k]) && is_string($pageMappings[$k])) $targets[$k] = $pageMappings[$k];
        }
    }
    if (is_array($dirMappings)) {
        foreach ($targets as $k => $_) {
            if ($targets[$k] !== null) continue;
            if (isset($dirMappings[$k]) && is_string($dirMappings[$k])) $targets[$k] = $dirMappings[$k];
        }
    }
    foreach ($targets as $path => $id) {
        if (!is_string($id) || $id === '') {
            $result['warnings'][] = ['type' => 'missing_mapping', 'path' => $path];
            continue;
        }
        $cfg = $resolveConfig($id);
        if ($isWhm($cfg)) {
            $result['warnings'][] = [
                'type' => 'whm_selected_for_runtime_path',
                'path' => $path,
                'config_id' => $id,
                'name' => (string)($cfg['name'] ?? ''),
                'port' => (string)($cfg['port'] ?? ''),
                'storage_profile' => (string)($cfg['storage_profile'] ?? ''),
            ];
        }
    }
} else {
    $result['warnings'][] = ['type' => 'contexts_missing_or_invalid', 'file' => $ctxFile];
}

header('Content-Type: application/json; charset=UTF-8');
echo json_encode($result, JSON_UNESCAPED_SLASHES);

