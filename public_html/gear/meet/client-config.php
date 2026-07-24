<?php
require_once __DIR__ . "/../../.cue/cue.php";

function mh_cfg_path(): string
{
    $base = "/data";
    if (function_exists("getDataPath")) {
        $base = (string) getDataPath();
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

$cfg = mh_read_cfg();
$client = is_array($cfg["client_config"] ?? null) ? $cfg["client_config"] : [];

$out = [];
$out["staticAssetsPath"] = "/meet/assets";
$out["serverUrl"] = (string) ($cfg["public_url"] ?? "https://metahumans.one/meet");

foreach ([
    "faviconUrl",
    "enableDynacast",
    "enableSimulcast",
    "videoCodec",
    "defaultWebcamResolution",
    "defaultScreenShareResolution",
    "defaultAudioPreset",
    "customLogo",
    "designCustomization",
    "virtualBackgroundImages",
    "whiteboardPreloadedLibraryItems",
] as $k) {
    if (array_key_exists($k, $client)) {
        $out[$k] = $client[$k];
    }
}

$out['defaultAudioPreset'] = 'speech';
$out['defaultWebcamResolution'] = 'h540';
$out['defaultScreenShareResolution'] = 'h720fps15';

header("Content-Type: application/json; charset=UTF-8");
echo json_encode($out, JSON_UNESCAPED_SLASHES);
