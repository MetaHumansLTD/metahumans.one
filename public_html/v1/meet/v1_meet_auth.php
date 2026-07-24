<?php
declare(strict_types=1);

function mh_meet_b64url_decode(string $s): string {
    $s = strtr($s, '-_', '+/');
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    $out = base64_decode($s, true);
    return is_string($out) ? $out : '';
}

function mh_meet_jwt_verify_hs256(string $jwt, string $secret, ?array &$payloadOut = null): bool {
    $payloadOut = null;
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$h64, $p64, $s64] = $parts;
    $hJson = mh_meet_b64url_decode($h64);
    $pJson = mh_meet_b64url_decode($p64);
    $sig = mh_meet_b64url_decode($s64);
    if ($hJson === '' || $pJson === '' || $sig === '') return false;
    $hdr = json_decode($hJson, true);
    $pl = json_decode($pJson, true);
    if (!is_array($hdr) || !is_array($pl)) return false;
    if (($hdr['alg'] ?? '') !== 'HS256') return false;
    $msg = $h64 . '.' . $p64;
    $mac = hash_hmac('sha256', $msg, $secret, true);
    if (!hash_equals($mac, $sig)) return false;
    $now = time();
    $nbf = isset($pl['nbf']) ? (int)$pl['nbf'] : 0;
    $exp = isset($pl['exp']) ? (int)$pl['exp'] : 0;
    if ($nbf && $now + 5 < $nbf) return false;
    if ($exp && $now - 5 > $exp) return false;
    $payloadOut = $pl;
    return true;
}

function mh_meet_get_plugnmeet_secret(): string {
    $cfgPath = '/data/config/plugnmeet.json';
    $raw = @file_get_contents($cfgPath);
    if (!is_string($raw) || $raw === '') return '';
    $obj = json_decode($raw, true);
    if (!is_array($obj)) return '';
    $sec = $obj['secret'] ?? '';
    return is_string($sec) ? $sec : '';
}

function mh_meet_read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') return [];
    $d = json_decode($raw, true);
    return is_array($d) ? $d : [];
}

function mh_meet_json_out(int $status, array $payload): void {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function mh_meet_extract_access_token(array $body): string {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (is_string($hdr) && stripos($hdr, 'Bearer ') === 0) {
        $tok = trim(substr($hdr, 7));
        if ($tok !== '') return $tok;
    }
    $tok = $body['access_token'] ?? '';
    return is_string($tok) ? trim($tok) : '';
}

function mh_meet_verify_access_token(string $jwt): array {
    $jwt = trim($jwt);
    if ($jwt === '') mh_meet_json_out(401, ['ok' => false, 'error' => 'missing_access_token']);
    $secret = mh_meet_get_plugnmeet_secret();
    if ($secret === '') mh_meet_json_out(500, ['ok' => false, 'error' => 'missing_server_secret']);
    $payload = null;
    if (mh_meet_jwt_verify_hs256($jwt, $secret, $payload)) return $payload ?? [];
    $decoded = base64_decode($secret, true);
    if (is_string($decoded) && $decoded !== '' && mh_meet_jwt_verify_hs256($jwt, $decoded, $payload)) return $payload ?? [];
    mh_meet_json_out(401, ['ok' => false, 'error' => 'invalid_access_token']);
    return [];
}
