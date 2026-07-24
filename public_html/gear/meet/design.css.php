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

function mh_css_escape_url(string $url): string
{
    return str_replace(["\\", "\""], ["/", "\\\""], $url);
}

function mh_css_escape_color(string $c): string
{
    return str_replace(["\"", "\\"], ["", ""], $c);
}

$cfg = mh_read_cfg();
$client = is_array($cfg["client_config"] ?? null) ? $cfg["client_config"] : [];
$design = is_array($client["designCustomization"] ?? null) ? $client["designCustomization"] : [];

$bgImage = trim((string)($design["background_image"] ?? ""));
$bgColor = trim((string)($design["background_color"] ?? ""));

header("Content-Type: text/css; charset=UTF-8");
header("Cache-Control: no-store");

$targets = [
    "#main-area",
    ".waiting-room",
    ".error-app-bg",
    ".portrait-device",
    ".portrait-device.is-pc.admin",
];

$sel = implode(",\n", $targets);
$selDark = implode(",\n", array_map(function ($s) { return "body.dark " . $s; }, $targets));

if ($bgImage !== "") {
    $bgUrl = mh_css_escape_url($bgImage);

    echo $sel . "{\n";
    echo "  background-image:url(\"" . $bgUrl . "\") !important;\n";
    echo "  background-size:cover !important;\n";
    echo "  background-position:center !important;\n";
    echo "  background-repeat:no-repeat !important;\n";
    echo "}\n";

    echo $selDark . "{\n";
    echo "  background-image:url(\"" . $bgUrl . "\") !important;\n";
    echo "  background-size:cover !important;\n";
    echo "  background-position:center !important;\n";
    echo "  background-repeat:no-repeat !important;\n";
    echo "}\n";

    if ($bgColor !== "") {
        $c = mh_css_escape_color($bgColor);
        echo $sel . "{background-color:" . $c . " !important;}\n";
        echo $selDark . "{background-color:" . $c . " !important;}\n";
    }
} elseif ($bgColor !== "") {
    $c = mh_css_escape_color($bgColor);
    echo $sel . "{background:" . $c . " !important;}\n";
    echo $selDark . "{background:" . $c . " !important;}\n";
}

echo "body,html{background:transparent !important;}\n";
echo "#metahumans-app,#plugNmeet-app{background:transparent !important;}\n";
