<?php
require_once __DIR__ . "/../../.cue/cue.php";

function mh_cfg_path(): string
{
    $base = "/data";
    if (function_exists("getDataPath")) {
        $base = (string)getDataPath();
        if ($base === "") {
            $base = "/data";
        }
    }
    return rtrim($base, "/") . "/config/plugnmeet.json";
}

function mh_read_cfg(): array
{
    $p = mh_cfg_path();
    if (!is_file($p)) {
        return [];
    }
    $raw = file_get_contents($p);
    if ($raw === false) {
        return [];
    }
    $cfg = json_decode($raw, true);
    return is_array($cfg) ? $cfg : [];
}

function mh_write_cfg(array $cfg): void
{
    $p = mh_cfg_path();
    $dir = dirname($p);
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException("Failed to encode config");
    }
    if (file_put_contents($p, $json . "\n") === false) {
        throw new RuntimeException("Failed to write config");
    }
    @chmod($p, 0600);
}

function mh_encrypt_for_cfg(string $plain): string
{
    if ($plain === "") {
        return "";
    }
    if (function_exists("cue_autoload")) {
        cue_autoload("paths");
        cue_autoload("security");
    }
    $keyPath = function_exists("paths_getEncryptionKeyPath") ? paths_getEncryptionKeyPath() : "/data/security/app.key";
    $keyRaw = @file_get_contents($keyPath);
    $key = is_string($keyRaw) ? trim($keyRaw) : "";
    if ($key === "") {
        throw new RuntimeException("Missing encryption key");
    }
    if (!function_exists("security_encryptValue")) {
        throw new RuntimeException("Missing encryption function");
    }
    return (string)security_encryptValue($plain, $key);
}

function mh_json_input(): array
{
    $raw = file_get_contents("php://input");
    if ($raw === false) {
        return [];
    }
    $v = json_decode($raw, true);
    return is_array($v) ? $v : [];
}

$method = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");
$cfg = mh_read_cfg();

if ($method === "GET") {
    $state = $cfg["server_settings_state"] ?? null;
    if (!is_array($state)) {
        $state = [];
    }
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($state, JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === "POST") {
    $state = mh_json_input();
    $cfg["server_settings_state"] = $state;

    $server = is_array($state["server"] ?? null) ? $state["server"] : [];
    $client = is_array($state["client"] ?? null) ? $state["client"] : [];

    if (is_string($server["serverUrl"] ?? null)) {
        $cfg["public_url"] = $server["serverUrl"];
    }
    if (is_string($server["apiKey"] ?? null)) {
        $cfg["api_key"] = $server["apiKey"];
        $enc = mh_encrypt_for_cfg((string)$server["apiKey"]);
        if ($enc !== "") {
            $cfg["plugnmeet_api_key"] = $enc;
        }
    }
    if (is_string($server["apiSecret"] ?? null)) {
        $cfg["secret"] = $server["apiSecret"];
        $enc = mh_encrypt_for_cfg((string)$server["apiSecret"]);
        if ($enc !== "") {
            $cfg["plugnmeet_api_secret"] = $enc;
        }
    }

    $clientCfg = is_array($cfg["client_config"] ?? null) ? $cfg["client_config"] : [];

    foreach ([
        "faviconUrl",
        "enableDynacast",
        "enableSimulcast",
        "videoCodec",
        "defaultWebcamResolution",
        "defaultScreenShareResolution",
        "defaultAudioPreset",
        "virtualBackgroundImages",
        "whiteboardPreloadedLibraryItems",
    ] as $k) {
        if (array_key_exists($k, $client)) {
            $clientCfg[$k] = $client[$k];
        }
    }

    $logoLight = is_string($client["logoLight"] ?? null) ? trim((string) $client["logoLight"]) : "";
    $logoDark = is_string($client["logoDark"] ?? null) ? trim((string) $client["logoDark"]) : "";

    if ($logoLight !== "" || $logoDark !== "") {
        $clientCfg["customLogo"] = [];
        if ($logoLight !== "") {
            $clientCfg["customLogo"]["main_logo_light"] = $logoLight;
        }
        if ($logoDark !== "") {
            $clientCfg["customLogo"]["main_logo_dark"] = $logoDark;
        }
    }

    $design = is_array($client["design"] ?? null) ? $client["design"] : [];
    $designOut = [];
    foreach ($design as $k => $v) {
        if (is_string($k)) {
            $designOut[$k] = $v;
        }
    }

    $bg = is_string($client["backgroundImage"] ?? null) ? trim((string) $client["backgroundImage"]) : "";
    if ($bg !== "") {
        $designOut["background_image"] = $bg;
    }

    $css = is_string($client["customCssUrl"] ?? null) ? trim((string) $client["customCssUrl"]) : "";
    if ($css !== "") {
        $designOut["custom_css_url"] = $css;
    }

    if ($designOut !== []) {
        $clientCfg["designCustomization"] = $designOut;
    }

    $cfg["client_config"] = $clientCfg;

    mh_write_cfg($cfg);

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["status" => true], JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(405);
header("Content-Type: application/json; charset=UTF-8");
echo json_encode(["status" => false, "error" => "Method not allowed"], JSON_UNESCAPED_SLASHES);
