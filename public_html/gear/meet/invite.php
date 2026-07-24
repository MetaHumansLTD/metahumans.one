<?php
require_once __DIR__ . "/../../.cue/cue.php";
require_once __DIR__ . "/meet_helpers.php";

$roomId = isset($_GET["room_id"]) ? trim((string) $_GET["room_id"]) : "";
$name = isset($_GET["name"]) ? trim((string) $_GET["name"]) : "";
$role = isset($_GET["role"]) ? trim((string) $_GET["role"]) : "viewer";

if ($roomId === "" || $name === "") {
    http_response_code(400);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["status" => false, "error" => "missing room_id or name"], JSON_UNESCAPED_SLASHES);
    exit;
}

$isAdmin = $role === "presenter";
$userId = ($isAdmin ? "presenter_" : "guest_") . bin2hex(random_bytes(8));

try {
    try {
        pnm_create_room_helper($roomId, $roomId);
    } catch (Throwable $e) {
        $msg = strtolower($e->getMessage());
        if (strpos($msg, "exist") === false && strpos($msg, "already") === false) {
            throw $e;
        }
    }

    $res = pnm_get_join_token_helper($roomId, $name, $userId, $isAdmin);
    $joinUrl = rtrim(pnm_get_public_url(), "/") . "/?access_token=" . $res["token"];

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["status" => true, "join_url" => $joinUrl], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["status" => false, "error" => $e->getMessage()], JSON_UNESCAPED_SLASHES);
}
