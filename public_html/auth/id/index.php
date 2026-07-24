<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

mh_id_start_session();

$user = mh_id_current_user();
if ($user === '') {
    header('Location: /auth/login.php?redirect=' . urlencode('/auth/id/'));
    exit;
}

$kyc = null;
$err = '';
try {
    $pdo = mh_id_biometrics_pdo();
    mh_id_ensure_schema($pdo);
    $stmt = $pdo->prepare("SELECT username, status, level, method, verified_at, expires_at, updated_at FROM user_kyc WHERE username = ? LIMIT 1");
    $stmt->execute([$user]);
    $kyc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($kyc)) $kyc = ['status' => 'none', 'level' => 0];
} catch (Throwable $e) {
    $err = 'KYC service unavailable';
    $kyc = ['status' => 'none', 'level' => 0];
}

$csrf = isset($_SESSION['mh_id_kyc_csrf']) && is_string($_SESSION['mh_id_kyc_csrf']) ? (string)$_SESSION['mh_id_kyc_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_id_kyc_csrf'] = $csrf;
}

$sessionToken = null;
$sessionKind = 'passport';
$sessionJson = null;
$sessionPayloadJson = '';
$sessionPayloadB64 = '';
$sessionDeepLink = '';
$sessionQrUrl = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    $sessionKind = mh_id_normalize_kind((string)($_POST['kind'] ?? 'passport')) ?: 'passport';
    if (!hash_equals($csrf, $postCsrf)) {
        $err = 'Invalid request';
    } else {
        try {
            $sessionId = bin2hex(random_bytes(16));
            $token = bin2hex(random_bytes(32));
            $tokenSha = hash('sha256', $token);
            $expiresAt = time() + 900;
            $expiresSql = gmdate('Y-m-d H:i:s', $expiresAt);
            $tenantSafe = mh_id_tenant_safe_from_username($user);
            $evidenceRel = mh_id_evidence_relative_path($tenantSafe, $sessionId);
            $evidenceFull = mh_id_secure_path($evidenceRel . '/', true);
            if ($evidenceFull === '') {
                throw new RuntimeException('storage_unavailable');
            }
            $stmt = $pdo->prepare("INSERT INTO user_kyc_sessions (username, session_id, token_sha256, status, method, expires_at, evidence_path) VALUES (?, ?, ?, 'created', ?, ?, ?)");
            $stmt->execute([$user, $sessionId, $tokenSha, $sessionKind, $expiresSql, $evidenceRel]);
            $sessionJson = [
                'ok' => true,
                'session_id' => $sessionId,
                'token' => $token,
                'expires_at' => $expiresAt,
                'kind' => $sessionKind,
                'upload_url' => '/auth/id/api.php?action=upload_evidence',
                'submit_url' => '/auth/id/api.php?action=submit_result',
                'verify_url' => '/auth/id/api.php?action=verify_session',
            ];
            $sessionToken = $token;

            $publicBaseUrl = '';
            if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) || !empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
                $proto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? (string)$_SERVER['HTTP_X_FORWARDED_PROTO'] : '';
                $host = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? (string)$_SERVER['HTTP_X_FORWARDED_HOST'] : '';
                $proto = trim(explode(',', $proto)[0]);
                $host = trim(explode(',', $host)[0]);
                if ($proto !== '' && $host !== '') {
                    $publicBaseUrl = $proto . '://' . $host;
                }
            }
            if ($publicBaseUrl === '' && function_exists('getBaseUrl')) {
                $publicBaseUrl = rtrim((string)getBaseUrl(), '/');
            }
            if ($publicBaseUrl === '') {
                $host = (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'metahumans.one');
                $httpsOn = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
                $publicBaseUrl = ($httpsOn ? 'https' : 'http') . '://' . $host;
            }

            $sessionPayload = [
                'session_id' => $sessionId,
                'token' => $token,
                'expires_at' => $expiresAt,
                'kind' => $sessionKind,
                'upload_url' => $publicBaseUrl . '/auth/id/api.php?action=upload_evidence',
                'submit_url' => $publicBaseUrl . '/auth/id/api.php?action=submit_result',
                'verify_url' => $publicBaseUrl . '/auth/id/api.php?action=verify_session',
            ];
            $sessionPayloadJson = json_encode($sessionPayload, JSON_UNESCAPED_SLASHES) ?: '';
            $sessionPayloadB64 = rtrim(strtr(base64_encode($sessionPayloadJson), '+/', '-_'), '=');
            $sessionDeepLink = 'metahumans://kyc?payload=' . $sessionPayloadB64;
            $sessionQrUrl = $publicBaseUrl . '/auth/id/capture.php?k=' . rawurlencode($sessionKind) . '&t=' . rawurlencode($token);
        } catch (Throwable $e) {
            $err = 'Unable to create session';
        }
    }
}

$kycStatus = strtolower((string)($kyc['status'] ?? 'none'));
$kycMethod = strtolower((string)($kyc['method'] ?? ''));

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KYC Verification</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 18px; }
        .mh-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 18px; }
        .mh-row { display:flex; gap: 14px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .mh-title { margin:0 0 6px 0; color:#00d4ff; }
        .mh-muted { color:#9aa; font-size: 12px; }
        .mh-badge { display:inline-flex; align-items:center; gap:8px; border-radius: 999px; padding: 6px 10px; border: 1px solid rgba(255,255,255,0.14); font-weight: 700; font-size: 12px; }
        .mh-ok { background: rgba(16,185,129,0.14); border-color: rgba(16,185,129,0.35); color:#c8ffe8; }
        .mh-warn { background: rgba(245,158,11,0.12); border-color: rgba(245,158,11,0.35); color:#ffe7bf; }
        .mh-bad { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color:#ffd0d0; }
        .mh-btn { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight:700; }
        .mh-btn-primary { background: #0aa0b6; border-color: rgba(0,212,255,0.35); }
        .mh-grid { display:grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
        @media (min-width: 900px) { .mh-grid { grid-template-columns: 1fr 1fr; } }
        .mh-field { margin-top: 10px; }
        .mh-field label { display:block; margin: 0 0 6px 0; color:#cfefff; font-size: 12px; }
        .mh-field select, .mh-field input, .mh-field textarea { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
        .mh-pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px; margin-top: 12px; }
        .mh-error { color:#ffb3b3; margin-top: 10px; }
        .mh-qr { width: 260px; height: 260px; border-radius: 12px; border: 1px solid rgba(255,255,255,0.12); background: rgba(0,0,0,0.25); }
    </style>
</head>
<body data-mh-kyc-status="<?php echo htmlspecialchars($kycStatus, ENT_QUOTES); ?>" data-mh-kyc-method="<?php echo htmlspecialchars($kycMethod, ENT_QUOTES); ?>">
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <div class="mh-wrap">
        <div class="mh-row" style="margin-bottom: 14px;">
            <div>
                <h1 class="mh-title">KYC Verification</h1>
                <div class="mh-muted">Mobile-first. NFC required for passports and NFC-enabled national IDs.</div>
            </div>
            <div class="mh-muted">Signed in as <?php echo htmlspecialchars($user, ENT_QUOTES); ?></div>
        </div>

        <div class="mh-card">
            <div class="mh-row">
                <div>
                    <div class="mh-muted">Status</div>
                    <?php
                        $st = strtolower((string)($kyc['status'] ?? 'none'));
                        $badgeClass = 'mh-warn';
                        if ($st === 'verified') $badgeClass = 'mh-ok';
                        if ($st === 'rejected' || $st === 'expired') $badgeClass = 'mh-bad';
                    ?>
                    <div class="mh-badge <?php echo $badgeClass; ?>">
                        <?php echo htmlspecialchars(strtoupper($st), ENT_QUOTES); ?>
                        <span style="opacity:0.8;">L<?php echo (int)($kyc['level'] ?? 0); ?></span>
                    </div>
                </div>
                <div class="mh-muted">
                    <?php if (!empty($kyc['updated_at'])): ?>
                        Updated: <?php echo htmlspecialchars((string)$kyc['updated_at'], ENT_QUOTES); ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($err !== ''): ?>
                <div class="mh-error"><?php echo htmlspecialchars($err, ENT_QUOTES); ?></div>
            <?php endif; ?>

            <div class="mh-grid">
                <div class="mh-card" style="padding: 14px;">
                    <div style="font-weight:800; color:#e6f6ff;">Start Mobile Verification</div>
                    <div class="mh-muted" style="margin-top: 6px;">Creates a short-lived bearer token for the MH mobile app.</div>

                    <form method="post" style="margin-top: 12px;">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>" />
                        <div class="mh-field">
                            <label>Document type</label>
                            <select name="kind">
                                <option value="passport"<?php echo $sessionKind === 'passport' ? ' selected' : ''; ?>>Passport (ICAO NFC)</option>
                                <option value="national_id"<?php echo $sessionKind === 'national_id' ? ' selected' : ''; ?>>National ID (NFC)</option>
                                <option value="mosip"<?php echo $sessionKind === 'mosip' ? ' selected' : ''; ?>>MOSIP ID Flow</option>
                            </select>
                        </div>
                        <div style="margin-top: 12px;">
                            <button class="mh-btn mh-btn-primary" type="submit">Create Mobile Session</button>
                        </div>
                    </form>

                    <?php if (is_array($sessionJson)): ?>
                        <div class="mh-muted" style="margin-top:10px;">Session created. Scan the QR code or open the capture link to continue.</div>
                        <?php if ($sessionQrUrl !== ''): ?>
                            <div style="margin-top: 14px;">
                                <div class="mh-muted" style="margin-bottom: 8px;">Scan QR with your phone camera (opens capture tool)</div>
                                <div id="mhKycQr" class="mh-qr"></div>
                            </div>
                        <?php endif; ?>
                        <?php if ($sessionQrUrl !== ''): ?>
                            <div class="mh-field" style="margin-top: 12px;">
                                <label>Capture link (mobile)</label>
                                <input id="mhCaptureLink" type="text" readonly value="<?php echo htmlspecialchars($sessionQrUrl, ENT_QUOTES); ?>">
                            </div>
                            <div style="margin-top: 10px; display:flex; gap: 10px; flex-wrap: wrap;">
                                <button class="mh-btn" type="button" onclick="mhCopy('mhCaptureLink')">Copy capture link</button>
                                <a class="mh-btn" href="<?php echo htmlspecialchars($sessionQrUrl, ENT_QUOTES); ?>" style="display:inline-block; text-decoration:none;">Open capture (this device)</a>
                            </div>
                            <?php if ($sessionKind === 'passport' || $sessionKind === 'national_id'): ?>
                                <div class="mh-muted" style="margin-top:10px;">
                                    Note: the capture tool only records the selfie video. Passport/National ID verification also requires NFC evidence (`nfc_dump.json` + `checks.json`) from the mobile app.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endif; ?>

                    <div style="margin-top: 12px;">
                        <a class="mh-btn" href="/auth/id/capture.php?k=mosip" style="display:inline-block; text-decoration:none;">Open Live Video Capture</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<?php if ($sessionQrUrl !== ''): ?>
<script src="/gear/qr/qrcode.js"></script>
<script>
(function () {
  try {
    var el = document.getElementById('mhKycQr');
    if (!el || typeof QRCode === 'undefined') return;
    el.innerHTML = '';
    new QRCode(el, { text: <?php echo json_encode($sessionQrUrl, JSON_UNESCAPED_SLASHES); ?>, width: 260, height: 260, correctLevel: QRCode.CorrectLevel.L });
  } catch (e) {}
})();
</script>
<?php endif; ?>
<script>
function mhCopy(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const v = el.value || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(v).catch(() => {});
  } else {
    el.focus();
    el.select();
    document.execCommand('copy');
  }
}
function mhCopyText(id) {
  const el = document.getElementById(id);
  if (!el) return;
  const v = el.value || el.textContent || '';
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(v).catch(() => {});
  } else {
    el.focus();
    el.select();
    document.execCommand('copy');
  }
}
</script>
<script>
(function() {
  var st = document.body ? String(document.body.getAttribute('data-mh-kyc-status') || '') : '';
  var method = document.body ? String(document.body.getAttribute('data-mh-kyc-method') || '') : '';
  if (st !== 'verified' || method !== 'mosip') return;
  var returnTo = '';
  try { returnTo = String(sessionStorage.getItem('mh_post_kyc_return_to') || ''); } catch (e) { returnTo = ''; }
  if (!returnTo) return;
  try {
    var u = new URL(returnTo, window.location.origin);
    if (u.origin !== window.location.origin) return;
    try { sessionStorage.removeItem('mh_post_kyc_return_to'); } catch (e) {}
    window.location.replace(u.href);
  } catch (e) {}
})();
</script>
</body>
</html>
