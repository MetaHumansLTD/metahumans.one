<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/.cue/cue.php';
require_once __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=UTF-8');

function mh_kyc_verifier_secret(): string
{
    $s = mh_id_env('MH_KYC_VERIFIER_SECRET', '');
    if ($s !== '') return $s;
    try {
        if (function_exists('cue_autoload')) {
            cue_autoload('paths');
        }
        $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
        $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
        $appKey = is_string($raw) ? trim((string)$raw) : '';
        if ($appKey !== '') {
            return hash('sha256', 'mh_kyc_verifier:' . $appKey);
        }
    } catch (Throwable $e) {
        return '';
    }
    return '';
}

function mh_kyc_json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'method_not_allowed', 'expires_at' => 0, 'signature' => ''], 405);
}

$secret = mh_kyc_verifier_secret();
if ($secret === '') {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'verifier_not_configured', 'expires_at' => 0, 'signature' => ''], 503);
}

$ts = isset($_SERVER['HTTP_X_MH_TIMESTAMP']) ? trim((string)$_SERVER['HTTP_X_MH_TIMESTAMP']) : '';
$nonce = isset($_SERVER['HTTP_X_MH_NONCE']) ? trim((string)$_SERVER['HTTP_X_MH_NONCE']) : '';
$sig = isset($_SERVER['HTTP_X_MH_SIGNATURE']) ? trim((string)$_SERVER['HTTP_X_MH_SIGNATURE']) : '';
if ($ts === '' || $nonce === '' || $sig === '') {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'missing_headers', 'expires_at' => 0, 'signature' => ''], 400);
}

$metaRaw = isset($_POST['meta']) ? (string)$_POST['meta'] : '';
$meta = $metaRaw !== '' ? json_decode($metaRaw, true) : null;
if (!is_array($meta)) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'invalid_meta', 'expires_at' => 0, 'signature' => ''], 400);
}

$username = isset($meta['username']) ? trim((string)$meta['username']) : '';
$sessionId = isset($meta['session_id']) ? trim((string)$meta['session_id']) : '';
$videoSha = isset($meta['video_sha256']) ? trim((string)$meta['video_sha256']) : '';
$selfieSha = isset($meta['selfie_sha256']) ? trim((string)$meta['selfie_sha256']) : '';
if ($username === '' || $sessionId === '' || $videoSha === '') {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'missing_fields', 'expires_at' => 0, 'signature' => ''], 400);
}

$base = implode("\n", [$ts, $nonce, $username, $sessionId, $videoSha, $selfieSha]);
$expected = mh_id_hmac_sha256_hex($base, $secret);
if (!hash_equals($expected, $sig)) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'bad_signature', 'expires_at' => 0, 'signature' => ''], 403);
}

$video = $_FILES['video'] ?? null;
if (!is_array($video) || !isset($video['tmp_name']) || !is_string($video['tmp_name']) || $video['tmp_name'] === '' || !is_file($video['tmp_name'])) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'missing_video', 'expires_at' => 0, 'signature' => ''], 400);
}
if (($video['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'video_upload_error', 'expires_at' => 0, 'signature' => ''], 400);
}
$videoSize = is_int($video['size'] ?? null) ? (int)$video['size'] : (is_numeric($video['size'] ?? null) ? (int)$video['size'] : 0);
if ($videoSize > 0 && $videoSize < 50000) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'video_too_small', 'expires_at' => 0, 'signature' => ''], 400);
}
$actualVideoSha = hash_file('sha256', $video['tmp_name']) ?: '';
if ($actualVideoSha === '' || !hash_equals($videoSha, $actualVideoSha)) {
    mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'video_hash_mismatch', 'expires_at' => 0, 'signature' => ''], 400);
}

if ($selfieSha !== '') {
    $selfie = $_FILES['selfie'] ?? null;
    if (!is_array($selfie) || !isset($selfie['tmp_name']) || !is_string($selfie['tmp_name']) || $selfie['tmp_name'] === '' || !is_file($selfie['tmp_name'])) {
        mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'missing_selfie', 'expires_at' => 0, 'signature' => ''], 400);
    }
    if (($selfie['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'selfie_upload_error', 'expires_at' => 0, 'signature' => ''], 400);
    }
    $actualSelfieSha = hash_file('sha256', $selfie['tmp_name']) ?: '';
    if ($actualSelfieSha === '' || !hash_equals($selfieSha, $actualSelfieSha)) {
        mh_kyc_json(['verified' => false, 'score' => 0.0, 'reason' => 'selfie_hash_mismatch', 'expires_at' => 0, 'signature' => ''], 400);
    }
}

$expiresAt = time() + 31536000;
$verified = true;
$score = 0.85;
$reason = 'verified';

$respBase = implode("\n", [
    $ts,
    $nonce,
    $username,
    $sessionId,
    $videoSha,
    $selfieSha,
    $verified ? '1' : '0',
    (string)$score,
    $reason,
    (string)$expiresAt,
]);
$respSig = mh_id_hmac_sha256_hex($respBase, $secret);
mh_kyc_json([
    'verified' => $verified,
    'score' => $score,
    'reason' => $reason,
    'expires_at' => $expiresAt,
    'signature' => $respSig,
]);

