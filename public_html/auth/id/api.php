<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

mh_id_start_session();

try {
    $pdo = mh_id_biometrics_pdo();
    mh_id_ensure_schema($pdo);
} catch (Throwable $e) {
    mh_id_json(['ok' => false, 'error' => 'server_init_failed'], 500);
}

$action = isset($_GET['action']) ? trim((string)$_GET['action']) : (isset($_POST['action']) ? trim((string)$_POST['action']) : '');

if ($action === 'status') {
    $user = mh_id_require_user();
    $stmt = $pdo->prepare("SELECT username, status, level, method, verified_at, expires_at, updated_at FROM user_kyc WHERE username = ? LIMIT 1");
    $stmt->execute([$user]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        mh_id_json(['ok' => true, 'status' => 'none', 'level' => 0]);
    }
    mh_id_json(['ok' => true, 'kyc' => $row]);
}

if ($action === 'create_session') {
    $user = mh_id_require_user();
    $in = mh_id_read_json_input();
    $kind = mh_id_normalize_kind((string)($in['kind'] ?? ''));
    if ($kind === '') {
        mh_id_json(['ok' => false, 'error' => 'invalid_kind'], 400);
    }

    $requestedRoom = isset($in['room_id']) ? trim((string)$in['room_id']) : '';
    $requestedRoom = preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string)$requestedRoom);
    $requestedRoom = trim((string)$requestedRoom, '_');
    if ($requestedRoom !== '' && (strlen($requestedRoom) < 6 || strlen($requestedRoom) > 64)) {
        mh_id_json(['ok' => false, 'error' => 'invalid_room_id'], 400);
    }

    $sessionId = $requestedRoom !== '' ? $requestedRoom : bin2hex(random_bytes(16));
    $token = bin2hex(random_bytes(32));
    $tokenSha = hash('sha256', $token);
    $expiresAt = time() + 900;
    $expiresSql = gmdate('Y-m-d H:i:s', $expiresAt);
    $tenantSafe = mh_id_tenant_safe_from_username($user);
    $evidenceRel = mh_id_evidence_relative_path($tenantSafe, $sessionId);
    $evidenceFull = mh_id_secure_path($evidenceRel . '/', true);
    if ($evidenceFull === '') {
        mh_id_json(['ok' => false, 'error' => 'storage_unavailable'], 500);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO user_kyc_sessions (username, session_id, token_sha256, status, method, expires_at, evidence_path) VALUES (?, ?, ?, 'created', ?, ?, ?)");
        $stmt->execute([$user, $sessionId, $tokenSha, $kind, $expiresSql, $evidenceRel]);
    } catch (Throwable $e) {
        try {
            $stmt = $pdo->prepare("SELECT id, username FROM user_kyc_sessions WHERE session_id = ? LIMIT 1");
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $existingUser = is_array($row) ? trim((string)($row['username'] ?? '')) : '';
            $existingId = is_array($row) ? (int)($row['id'] ?? 0) : 0;
            if ($existingId > 0 && $existingUser === $user) {
                $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET token_sha256 = ?, status = 'created', method = ?, expires_at = ?, evidence_path = ? WHERE id = ?");
                $stmt->execute([$tokenSha, $kind, $expiresSql, $evidenceRel, $existingId]);
            } else {
                mh_id_json(['ok' => false, 'error' => 'session_id_unavailable'], 409);
            }
        } catch (Throwable $e2) {
            mh_id_json(['ok' => false, 'error' => 'session_id_unavailable'], 409);
        }
    }

    mh_id_json([
        'ok' => true,
        'session_id' => $sessionId,
        'token' => $token,
        'expires_at' => $expiresAt,
        'kind' => $kind,
        'upload_url' => '/auth/id/api.php?action=upload_evidence',
        'submit_url' => '/auth/id/api.php?action=submit_result',
    ]);
}

if ($action === 'upload_evidence') {
    $token = mh_id_session_bearer_token();
    if ($token === '') {
        mh_id_json(['ok' => false, 'error' => 'missing_bearer'], 401);
    }
    $sess = mh_id_find_session_by_token($pdo, $token);
    if (!$sess) {
        mh_id_json(['ok' => false, 'error' => 'invalid_token'], 401);
    }
    $expiresAt = isset($sess['expires_at']) ? strtotime((string)$sess['expires_at']) : 0;
    if ($expiresAt > 0 && $expiresAt < time()) {
        mh_id_json(['ok' => false, 'error' => 'session_expired'], 401);
    }

    $in = mh_id_read_json_input();
    $name = isset($in['name']) ? trim((string)$in['name']) : '';
    $b64 = isset($in['base64']) ? (string)$in['base64'] : '';
    $allowed = [
        'document_front.jpg',
        'document_back.jpg',
        'selfie.jpg',
        'selfie_video.mp4',
        'passport_dg2_face.jpg',
        'nfc_dump.json',
        'checks.json',
    ];
    if (!in_array($name, $allowed, true)) {
        mh_id_json(['ok' => false, 'error' => 'invalid_name'], 400);
    }

    $relBase = isset($sess['evidence_path']) ? (string)$sess['evidence_path'] : '';
    if ($relBase === '') {
        mh_id_json(['ok' => false, 'error' => 'missing_evidence_path'], 500);
    }
    $full = mh_id_secure_path($relBase . '/' . $name, true);
    if ($full === '') {
        mh_id_json(['ok' => false, 'error' => 'storage_unavailable'], 500);
    }

    $sha = '';
    if (isset($_FILES['file']) && is_array($_FILES['file']) && (($name === 'selfie_video.mp4') || ($name === 'selfie.jpg') || ($name === 'document_front.jpg') || ($name === 'document_back.jpg') || ($name === 'passport_dg2_face.jpg'))) {
        $tmp = isset($_FILES['file']['tmp_name']) ? (string)$_FILES['file']['tmp_name'] : '';
        $size = isset($_FILES['file']['size']) ? (int)$_FILES['file']['size'] : 0;
        $err = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : UPLOAD_ERR_NO_FILE;
        if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp)) {
            mh_id_json(['ok' => false, 'error' => 'upload_failed'], 400);
        }
        if ($size < 1) {
            mh_id_json(['ok' => false, 'error' => 'empty_file'], 400);
        }
        if ($size > 60 * 1024 * 1024) {
            mh_id_json(['ok' => false, 'error' => 'file_too_large'], 413);
        }
        if ($name === 'selfie_video.mp4') {
            $origName = isset($_FILES['file']['name']) ? strtolower(trim((string)$_FILES['file']['name'])) : '';
            $ext = pathinfo($origName, PATHINFO_EXTENSION);
            $ext = is_string($ext) ? strtolower($ext) : '';
            $mime = isset($_FILES['file']['type']) ? strtolower(trim((string)$_FILES['file']['type'])) : '';
            $hdr = '';
            try {
                $fh = @fopen($tmp, 'rb');
                if (is_resource($fh)) {
                    $hdr = (string)@fread($fh, 4);
                    @fclose($fh);
                }
            } catch (Throwable $e) { $hdr = ''; }
            $isWebm = ($ext === 'webm') || ($mime === 'video/webm') || ($hdr === "\x1A\x45\xDF\xA3");
            if ($isWebm) {
                $tmpBase = tempnam(sys_get_temp_dir(), 'mhkyc_vid_');
                if (!is_string($tmpBase) || $tmpBase === '') {
                    mh_id_json(['ok' => false, 'error' => 'temp_failed'], 500);
                }
                $dst = $tmpBase . '.mp4';
                @unlink($tmpBase);
                $cmd = 'ffmpeg -hide_banner -loglevel error -y -i ' . escapeshellarg($tmp) . ' -c:v libx264 -pix_fmt yuv420p -movflags +faststart -an ' . escapeshellarg($dst);
                $out = [];
                $code = 0;
                @exec($cmd . ' 2>&1', $out, $code);
                if ($code !== 0 || !is_file($dst) || filesize($dst) < 1024) {
                    if (is_file($dst)) @unlink($dst);
                    mh_id_json(['ok' => false, 'error' => 'ffmpeg_failed'], 500);
                }
                $raw = file_get_contents($dst);
                if (!is_string($raw) || $raw === '') {
                    @unlink($dst);
                    mh_id_json(['ok' => false, 'error' => 'read_failed'], 500);
                }
                $sha = hash('sha256', $raw);
                if (file_put_contents($full, $raw, LOCK_EX) === false) {
                    @unlink($dst);
                    mh_id_json(['ok' => false, 'error' => 'write_failed'], 500);
                }
                @unlink($dst);
            } else {
                $raw = file_get_contents($tmp);
                if (!is_string($raw) || $raw === '') {
                    mh_id_json(['ok' => false, 'error' => 'read_failed'], 500);
                }
                $sha = hash('sha256', $raw);
                if (file_put_contents($full, $raw, LOCK_EX) === false) {
                    mh_id_json(['ok' => false, 'error' => 'write_failed'], 500);
                }
            }
        } else {
            $raw = file_get_contents($tmp);
            if (!is_string($raw) || $raw === '') {
                mh_id_json(['ok' => false, 'error' => 'read_failed'], 500);
            }
            $sha = hash('sha256', $raw);
            if (file_put_contents($full, $raw, LOCK_EX) === false) {
                mh_id_json(['ok' => false, 'error' => 'write_failed'], 500);
            }
        }
    } else {
        if ($name === '' || $b64 === '') {
            mh_id_json(['ok' => false, 'error' => 'missing_fields'], 400);
        }
        $raw = base64_decode($b64, true);
        if (!is_string($raw) || $raw === '') {
            mh_id_json(['ok' => false, 'error' => 'invalid_base64'], 400);
        }
        if (strlen($raw) > 60 * 1024 * 1024) {
            mh_id_json(['ok' => false, 'error' => 'file_too_large'], 413);
        }
        $sha = hash('sha256', $raw);
        if (file_put_contents($full, $raw, LOCK_EX) === false) {
            mh_id_json(['ok' => false, 'error' => 'write_failed'], 500);
        }
    }

    $evidenceJsonCur = null;
    try {
        $stmt = $pdo->prepare("SELECT evidence_json FROM user_kyc_sessions WHERE id = ? LIMIT 1");
        $stmt->execute([(int)$sess['id']]);
        $v = $stmt->fetchColumn();
        $evidenceJsonCur = is_string($v) ? (string)$v : null;
    } catch (Throwable $e) {
        $evidenceJsonCur = null;
    }
    $nextJson = mh_id_evidence_upsert_hash_json($evidenceJsonCur, $name, $sha);
    $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET status = 'evidence', evidence_json = ? WHERE id = ?");
    $stmt->execute([$nextJson, (int)$sess['id']]);

    mh_id_json(['ok' => true, 'name' => $name, 'sha256' => $sha]);
}

if ($action === 'verify_session') {
    $token = mh_id_session_bearer_token();
    if ($token === '') {
        mh_id_json(['ok' => false, 'error' => 'missing_bearer'], 401);
    }
    $sess = mh_id_find_session_by_token($pdo, $token);
    if (!$sess) {
        mh_id_json(['ok' => false, 'error' => 'invalid_token'], 401);
    }

    $expiresAt = isset($sess['expires_at']) ? strtotime((string)$sess['expires_at']) : 0;
    if ($expiresAt > 0 && $expiresAt < time()) {
        mh_id_json(['ok' => false, 'error' => 'session_expired'], 401);
    }

    $evidenceRel = isset($sess['evidence_path']) ? (string)$sess['evidence_path'] : '';
    if ($evidenceRel === '') {
        mh_id_json(['ok' => false, 'error' => 'missing_evidence_path'], 500);
    }
    $videoPath = mh_id_secure_path($evidenceRel . '/selfie_video.mp4', false);
    $selfiePath = mh_id_secure_path($evidenceRel . '/selfie.jpg', false);

    $evidenceHashes = mh_id_evidence_extract_hashes_from_json(isset($sess['evidence_json']) ? (string)$sess['evidence_json'] : null);

    $kind = isset($sess['method']) ? (string)$sess['method'] : '';
    $nfc = mh_id_validate_nfc_evidence($kind, $evidenceRel, $evidenceHashes);
    if (empty($nfc['ok'])) {
        mh_id_json(['ok' => false, 'error' => (string)($nfc['error'] ?? 'nfc_invalid')], 400);
    }

    $call = mh_id_kyc_verifier_call($sess, $evidenceHashes, $videoPath, is_file($selfiePath) ? $selfiePath : null);
    if (empty($call['ok'])) {
        mh_id_json(['ok' => false, 'error' => (string)($call['error'] ?? 'verifier_failed'), 'detail' => $call], 502);
    }

    $result = (array)($call['result'] ?? []);
    $verified = (bool)($result['verified'] ?? false);
    $score = isset($result['score']) ? (float)$result['score'] : 0.0;
    $reason = isset($result['reason']) ? (string)$result['reason'] : '';
    $expiresUnix = isset($result['expires_at']) ? (int)$result['expires_at'] : 0;
    $expiresSql = $expiresUnix > 0 ? gmdate('Y-m-d H:i:s', $expiresUnix) : null;

    $user = isset($sess['username']) ? trim((string)$sess['username']) : '';
    if ($user === '') {
        mh_id_json(['ok' => false, 'error' => 'invalid_session_user'], 500);
    }

    $status = $verified ? 'verified' : 'failed';
    $method = isset($sess['method']) ? (string)$sess['method'] : null;
    $level = 0;
    try {
        $st = $pdo->prepare("SELECT level FROM user_kyc WHERE username = ? LIMIT 1");
        $st->execute([$user]);
        $lvl = $st->fetchColumn();
        $level = is_numeric($lvl) ? (int)$lvl : 0;
    } catch (Throwable $e) {
        $level = 0;
    }
    $evidenceOut = [
        'hashes' => $evidenceHashes,
        'verifier' => $result,
        'nfc' => $nfc['summary'] ?? [],
        'score' => $score,
        'reason' => $reason,
    ];
    $evidenceJson = json_encode($evidenceOut, JSON_UNESCAPED_SLASHES);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET status = ?, completed_at = NOW(), evidence_json = ? WHERE id = ?");
        $stmt->execute([$status, $evidenceJson, (int)$sess['id']]);

        $stmt = $pdo->prepare("INSERT INTO user_kyc (username, status, level, method, verified_at, expires_at, evidence_json) VALUES (?, ?, ?, ?, NOW(), ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), level = GREATEST(VALUES(level), level), method = VALUES(method), verified_at = VALUES(verified_at), expires_at = VALUES(expires_at), evidence_json = VALUES(evidence_json), updated_at = NOW()");
        $stmt->execute([$user, $status, $level, $method, $expiresSql, $evidenceJson]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        mh_id_json(['ok' => false, 'error' => 'persist_failed'], 500);
    }

    mh_id_json(['ok' => true, 'status' => $status, 'verified' => $verified, 'score' => $score, 'reason' => $reason, 'expires_at' => $expiresUnix]);
}

if ($action === 'submit_result') {
    $token = mh_id_session_bearer_token();
    if ($token === '') {
        mh_id_json(['ok' => false, 'error' => 'missing_bearer'], 401);
    }
    $sess = mh_id_find_session_by_token($pdo, $token);
    if (!$sess) {
        mh_id_json(['ok' => false, 'error' => 'invalid_token'], 401);
    }
    $expiresAt = isset($sess['expires_at']) ? strtotime((string)$sess['expires_at']) : 0;
    if ($expiresAt > 0 && $expiresAt < time()) {
        mh_id_json(['ok' => false, 'error' => 'session_expired'], 401);
    }

    $in = mh_id_read_json_input();
    $kind = mh_id_normalize_kind((string)($in['kind'] ?? ($sess['method'] ?? '')));
    if ($kind === '') {
        mh_id_json(['ok' => false, 'error' => 'invalid_kind'], 400);
    }
    $level = isset($in['level']) ? (int)$in['level'] : 0;
    $level = max(0, min(10, $level));

    $user = isset($sess['username']) ? trim((string)$sess['username']) : '';
    if ($user === '') {
        mh_id_json(['ok' => false, 'error' => 'invalid_session_user'], 500);
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET status = 'pending', method = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$kind, (int)$sess['id']]);

        $stmt = $pdo->prepare("INSERT INTO user_kyc (username, status, level, method, verified_at, expires_at, evidence_json) VALUES (?, 'pending', ?, ?, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE status = VALUES(status), level = GREATEST(VALUES(level), level), method = VALUES(method), updated_at = NOW()");
        $stmt->execute([$user, $level, $kind]);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        mh_id_json(['ok' => false, 'error' => 'submit_failed'], 500);
    }

    if ($kind === 'mosip' && mh_id_env('MH_KYC_MOSIP_ENABLED', '') === '1') {
        $evidenceHashes = mh_id_evidence_extract_hashes_from_json(isset($sess['evidence_json']) ? (string)$sess['evidence_json'] : null);
        $mosip = mh_id_mosip_verify_call($sess, $evidenceHashes);
        if (!empty($mosip['ok'])) {
            $res = (array)($mosip['result'] ?? []);
            $verified = (bool)($res['verified'] ?? false);
            $score = isset($res['score']) ? (float)$res['score'] : 0.0;
            $reason = isset($res['reason']) ? (string)$res['reason'] : 'mosip';
            $expiresUnix = isset($res['expires_at']) ? (int)$res['expires_at'] : 0;
            $expiresSql = $expiresUnix > 0 ? gmdate('Y-m-d H:i:s', $expiresUnix) : null;
            $status = $verified ? 'verified' : 'failed';
            $evidenceOut = [
                'hashes' => $evidenceHashes,
                'mosip' => $res,
                'score' => $score,
                'reason' => $reason,
            ];
            $evidenceJson = json_encode($evidenceOut, JSON_UNESCAPED_SLASHES);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET status = ?, completed_at = NOW(), evidence_json = ? WHERE id = ?");
                $stmt->execute([$status, $evidenceJson, (int)$sess['id']]);
                $stmt = $pdo->prepare("UPDATE user_kyc SET status = ?, verified_at = NOW(), expires_at = ?, evidence_json = ?, updated_at = NOW() WHERE username = ?");
                $stmt->execute([$status, $expiresSql, $evidenceJson, $user]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                mh_id_json(['ok' => true, 'status' => 'pending', 'mosip_verify' => 'persist_failed']);
            }
            mh_id_json(['ok' => true, 'status' => $status, 'verified' => $verified, 'score' => $score, 'reason' => $reason, 'expires_at' => $expiresUnix]);
        }
    }

    if (mh_id_env('MH_KYC_AUTO_VERIFY', '') === '1') {
        $evidenceRel = isset($sess['evidence_path']) ? (string)$sess['evidence_path'] : '';
        $videoPath = $evidenceRel !== '' ? mh_id_secure_path($evidenceRel . '/selfie_video.mp4', false) : '';
        $selfiePath = $evidenceRel !== '' ? mh_id_secure_path($evidenceRel . '/selfie.jpg', false) : '';
        $evidenceHashes = mh_id_evidence_extract_hashes_from_json(isset($sess['evidence_json']) ? (string)$sess['evidence_json'] : null);
        $kind = isset($sess['method']) ? (string)$sess['method'] : '';
        $nfc = $evidenceRel !== '' ? mh_id_validate_nfc_evidence($kind, $evidenceRel, $evidenceHashes) : ['ok' => true, 'summary' => []];
        if (empty($nfc['ok'])) {
            mh_id_json(['ok' => true, 'status' => 'pending', 'auto_verify' => (string)($nfc['error'] ?? 'nfc_invalid')]);
        }
        $call = ($videoPath !== '' && is_file($videoPath)) ? mh_id_kyc_verifier_call($sess, $evidenceHashes, $videoPath, is_file($selfiePath) ? $selfiePath : null) : ['ok' => false];
        if (!empty($call['ok'])) {
            $result = (array)($call['result'] ?? []);
            $verified = (bool)($result['verified'] ?? false);
            $score = isset($result['score']) ? (float)$result['score'] : 0.0;
            $reason = isset($result['reason']) ? (string)$result['reason'] : '';
            $expiresUnix = isset($result['expires_at']) ? (int)$result['expires_at'] : 0;
            $expiresSql = $expiresUnix > 0 ? gmdate('Y-m-d H:i:s', $expiresUnix) : null;
            $status = $verified ? 'verified' : 'failed';
            $evidenceOut = [
                'hashes' => $evidenceHashes,
                'verifier' => $result,
                'nfc' => $nfc['summary'] ?? [],
                'score' => $score,
                'reason' => $reason,
            ];
            $evidenceJson = json_encode($evidenceOut, JSON_UNESCAPED_SLASHES);
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE user_kyc_sessions SET status = ?, completed_at = NOW(), evidence_json = ? WHERE id = ?");
                $stmt->execute([$status, $evidenceJson, (int)$sess['id']]);
                $stmt = $pdo->prepare("UPDATE user_kyc SET status = ?, verified_at = NOW(), expires_at = ?, evidence_json = ?, updated_at = NOW() WHERE username = ?");
                $stmt->execute([$status, $expiresSql, $evidenceJson, $user]);
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                mh_id_json(['ok' => true, 'status' => 'pending', 'auto_verify' => 'persist_failed']);
            }
            mh_id_json(['ok' => true, 'status' => $status, 'verified' => $verified, 'score' => $score, 'reason' => $reason, 'expires_at' => $expiresUnix]);
        }
    }

    mh_id_json(['ok' => true, 'status' => 'pending']);
}

mh_id_json(['ok' => false, 'error' => 'unknown_action'], 404);
