<?php
declare(strict_types=1);

require_once __DIR__ . '/lib.php';

mh_id_start_session();
$user = mh_id_current_user();
if ($user === '') {
    header('Location: /auth/login.php?redirect=' . urlencode('/auth/id/health.php'));
    exit;
}

$roleRaw = isset($_SESSION['mh_auth_role']) && is_string($_SESSION['mh_auth_role']) ? trim((string)$_SESSION['mh_auth_role']) : '';
$roleNorm = strtolower($roleRaw);
$groupsRaw = $_SESSION['mh_auth_groups'] ?? '';
$groupsStr = '';
if (is_array($groupsRaw)) {
    $groupsStr = implode(';', array_map('strval', $groupsRaw));
} elseif (is_string($groupsRaw)) {
    $groupsStr = $groupsRaw;
}
$groupsNorm = strtolower((string)$groupsStr);
$permRaw = $_SESSION['mh_auth_permissions'] ?? null;
$perm = null;
if (is_string($permRaw) && trim($permRaw) !== '') {
    $d = json_decode($permRaw, true);
    if (is_array($d)) $perm = $d;
} elseif (is_array($permRaw)) {
    $perm = $permRaw;
}
$permAll = false;
if (is_array($perm)) {
    $menus = $perm['menus'] ?? null;
    if (is_array($menus) && in_array('all', $menus, true)) $permAll = true;
}
$isOps = (strpos($roleNorm, 'kripz') !== false) || (strpos($groupsNorm, 'kripzmasters') !== false) || $permAll;
$csrf = isset($_SESSION['mh_id_health_csrf']) && is_string($_SESSION['mh_id_health_csrf']) ? (string)$_SESSION['mh_id_health_csrf'] : '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(16));
    $_SESSION['mh_id_health_csrf'] = $csrf;
}
$flash = isset($_SESSION['mh_id_health_flash']) && is_array($_SESSION['mh_id_health_flash']) ? $_SESSION['mh_id_health_flash'] : null;
if (is_array($flash)) {
    unset($_SESSION['mh_id_health_flash']);
}

function mh_id_mask_url(string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    $p = parse_url($url);
    if (!is_array($p)) return $url;
    $scheme = isset($p['scheme']) ? (string)$p['scheme'] : '';
    $host = isset($p['host']) ? (string)$p['host'] : '';
    $port = isset($p['port']) ? (int)$p['port'] : 0;
    $path = isset($p['path']) ? (string)$p['path'] : '';
    $out = '';
    if ($scheme !== '') $out .= $scheme . '://';
    $out .= $host !== '' ? $host : '[host]';
    if ($port > 0) $out .= ':' . $port;
    if ($path !== '') $out .= $path;
    return $out;
}

$verifierUrl = mh_id_env('MH_KYC_VERIFIER_URL', '');
$verifierSecretSet = mh_id_env('MH_KYC_VERIFIER_SECRET', '') !== '';
$mosipEnabled = mh_id_env('MH_KYC_MOSIP_ENABLED', '');
$mosipUrl = mh_id_env('MH_KYC_MOSIP_VERIFY_URL', '');
$mosipSecretSet = mh_id_env('MH_KYC_MOSIP_SECRET', '') !== '';
$mosipUpstream = mh_id_env('MOSIP_UPSTREAM_URL', '');
$nfcFull = mh_id_env('MH_NFC_FULL_VERIFY', '');
$passportTrust = mh_id_env('MH_PASSPORT_TRUST_MODE', '');
$cscaCountries = mh_id_env('MH_PASSPORT_CSCA_COUNTRIES', '');
$cscaBundle = mh_id_env('MH_PASSPORT_CSCA_BUNDLE', '');
$cscaExists = $cscaBundle !== '' && is_file($cscaBundle);
$healthTokenSet = mh_id_env('MH_HEALTH_TOKEN', '') !== '';
$ttl = mh_id_env('MH_SESSION_TTL', '');

$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!$isOps) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    $postCsrf = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
    if (!hash_equals($csrf, $postCsrf)) {
        $_SESSION['mh_id_health_flash'] = ['type' => 'error', 'msg' => 'Invalid request'];
        header('Location: /auth/id/health.php');
        exit;
    } else {
        $action = isset($_POST['action']) ? trim((string)$_POST['action']) : '';
        $ok = true;
        $msgs = [];
        if ($action === 'mosip_enable') {
            $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_ENABLED', '1');
            $url = isset($_POST['mosip_url']) ? trim((string)$_POST['mosip_url']) : '';
            if ($url !== '') $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_VERIFY_URL', $url);
            $msgs[] = 'MOSIP enabled';
        } elseif ($action === 'verifier_set_url') {
            $url = isset($_POST['verifier_url']) ? trim((string)$_POST['verifier_url']) : '';
            $ok = $ok && mh_id_env_set('MH_KYC_VERIFIER_URL', $url !== '' ? $url : null);
            $msgs[] = 'Verifier URL updated';
        } elseif ($action === 'verifier_set_secret') {
            $sec = isset($_POST['verifier_secret']) ? trim((string)$_POST['verifier_secret']) : '';
            $ok = $ok && mh_id_env_set('MH_KYC_VERIFIER_SECRET', $sec !== '' ? $sec : null);
            $msgs[] = $sec !== '' ? 'Verifier secret set' : 'Verifier secret cleared';
        } elseif ($action === 'verifier_clear_secret') {
            $ok = $ok && mh_id_env_set('MH_KYC_VERIFIER_SECRET', null);
            $msgs[] = 'Verifier secret cleared';
        } elseif ($action === 'mosip_disable') {
            $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_ENABLED', '0');
            $msgs[] = 'MOSIP disabled';
        } elseif ($action === 'mosip_set_url') {
            $url = isset($_POST['mosip_url']) ? trim((string)$_POST['mosip_url']) : '';
            $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_VERIFY_URL', $url !== '' ? $url : null);
            $msgs[] = 'MOSIP verify URL updated';
        } elseif ($action === 'mosip_set_secret') {
            $sec = isset($_POST['mosip_secret']) ? trim((string)$_POST['mosip_secret']) : '';
            $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_SECRET', $sec !== '' ? $sec : null);
            $msgs[] = $sec !== '' ? 'MOSIP secret set' : 'MOSIP secret cleared';
        } elseif ($action === 'mosip_clear_secret') {
            $ok = $ok && mh_id_env_set('MH_KYC_MOSIP_SECRET', null);
            $msgs[] = 'MOSIP secret cleared';
        } elseif ($action === 'mosip_set_upstream') {
            $url = isset($_POST['mosip_upstream']) ? trim((string)$_POST['mosip_upstream']) : '';
            $ok = $ok && mh_id_env_set('MOSIP_UPSTREAM_URL', $url !== '' ? $url : null);
            $msgs[] = $url !== '' ? 'MOSIP upstream updated' : 'MOSIP upstream cleared';
        } elseif ($action === 'mosip_clear_upstream') {
            $ok = $ok && mh_id_env_set('MOSIP_UPSTREAM_URL', null);
            $msgs[] = 'MOSIP upstream cleared';
        } elseif ($action === 'nfc_full_on') {
            $ok = $ok && mh_id_env_set('MH_NFC_FULL_VERIFY', '1');
            $msgs[] = 'NFC full verify enabled';
        } elseif ($action === 'nfc_full_off') {
            $ok = $ok && mh_id_env_set('MH_NFC_FULL_VERIFY', '0');
            $msgs[] = 'NFC full verify disabled';
        } elseif ($action === 'set_csca_bundle') {
            $p = isset($_POST['csca_bundle']) ? trim((string)$_POST['csca_bundle']) : '';
            $ok = $ok && mh_id_env_set('MH_PASSPORT_CSCA_BUNDLE', $p !== '' ? $p : null);
            $msgs[] = $p !== '' ? 'CSCA bundle path updated' : 'CSCA bundle cleared';
        } elseif ($action === 'clear_csca_bundle') {
            $ok = $ok && mh_id_env_set('MH_PASSPORT_CSCA_BUNDLE', null);
            $msgs[] = 'CSCA bundle cleared';
        } elseif ($action === 'set_passport_trust') {
            $p = isset($_POST['passport_trust']) ? strtolower(trim((string)$_POST['passport_trust'])) : '';
            if ($p !== 'none' && $p !== 'csca') $p = '';
            $ok = $ok && mh_id_env_set('MH_PASSPORT_TRUST_MODE', $p !== '' ? $p : null);
            $msgs[] = $p !== '' ? ('Passport trust mode set: ' . $p) : 'Passport trust mode cleared';
        } elseif ($action === 'set_csca_countries') {
            $p = isset($_POST['csca_countries']) ? trim((string)$_POST['csca_countries']) : '';
            $ok = $ok && mh_id_env_set('MH_PASSPORT_CSCA_COUNTRIES', $p !== '' ? $p : null);
            $msgs[] = $p !== '' ? 'CSCA countries updated' : 'CSCA countries cleared';
        } elseif ($action === 'set_health_token') {
            $p = isset($_POST['health_token']) ? trim((string)$_POST['health_token']) : '';
            $ok = $ok && mh_id_env_set('MH_HEALTH_TOKEN', $p !== '' ? $p : null);
            $msgs[] = $p !== '' ? 'Health token set' : 'Health token cleared';
        } elseif ($action === 'clear_health_token') {
            $ok = $ok && mh_id_env_set('MH_HEALTH_TOKEN', null);
            $msgs[] = 'Health token cleared';
        } elseif ($action === 'set_ttl') {
            $v = isset($_POST['session_ttl']) ? (int)$_POST['session_ttl'] : 0;
            if ($v < 1800) $v = 1800;
            $ok = $ok && mh_id_env_set('MH_SESSION_TTL', (string)$v);
            $msgs[] = 'Session TTL updated';
        }
        $_SESSION['mh_id_health_flash'] = [
            'type' => $ok ? 'ok' : 'error',
            'msg' => $ok ? implode(' · ', $msgs) : ('Failed to persist settings. Check data path permissions for kyc/env_overrides.json'),
        ];
        header('Location: /auth/id/health.php');
        exit;
    }
}

$ttlInt = (int)$ttl;
if ($ttlInt <= 0) $ttlInt = 43200;
$ttlHours = round($ttlInt / 3600, 2);

$rows = [
    ['key' => 'MH_KYC_VERIFIER_URL', 'value' => $verifierUrl !== '' ? mh_id_mask_url($verifierUrl) : '', 'enabled' => $verifierUrl !== ''],
    ['key' => 'MH_KYC_VERIFIER_SECRET', 'value' => $verifierSecretSet ? 'set' : 'not set', 'enabled' => $verifierSecretSet],
    ['key' => 'MH_KYC_MOSIP_ENABLED', 'value' => $mosipEnabled !== '' ? $mosipEnabled : '0', 'enabled' => $mosipEnabled === '1'],
    ['key' => 'MH_KYC_MOSIP_VERIFY_URL', 'value' => $mosipUrl !== '' ? mh_id_mask_url($mosipUrl) : '', 'enabled' => ($mosipEnabled === '1' && $mosipUrl !== '')],
    ['key' => 'MH_KYC_MOSIP_SECRET', 'value' => $mosipSecretSet ? 'set' : 'not set', 'enabled' => $mosipSecretSet],
    ['key' => 'MOSIP_UPSTREAM_URL', 'value' => $mosipUpstream !== '' ? mh_id_mask_url($mosipUpstream) : '', 'enabled' => $mosipUpstream !== ''],
    ['key' => 'MH_NFC_FULL_VERIFY', 'value' => $nfcFull !== '' ? $nfcFull : '0', 'enabled' => $nfcFull === '1'],
    ['key' => 'MH_PASSPORT_TRUST_MODE', 'value' => $passportTrust !== '' ? $passportTrust : 'none', 'enabled' => true],
    ['key' => 'MH_PASSPORT_CSCA_COUNTRIES', 'value' => $cscaCountries !== '' ? $cscaCountries : '', 'enabled' => $cscaCountries !== ''],
    ['key' => 'MH_PASSPORT_CSCA_BUNDLE', 'value' => $cscaBundle !== '' ? ($cscaBundle . ($cscaExists ? ' (found)' : ' (missing)')) : '', 'enabled' => $cscaExists],
    ['key' => 'MH_HEALTH_TOKEN', 'value' => $healthTokenSet ? 'set' : 'not set', 'enabled' => $healthTokenSet],
    ['key' => 'MH_SESSION_TTL', 'value' => (string)$ttlInt . ' seconds (' . (string)$ttlHours . ' hours)', 'enabled' => true],
    ['key' => 'OPS_ROLE', 'value' => $roleRaw !== '' ? $roleRaw : 'unknown', 'enabled' => $isOps],
];

$mosipDefaultUrl = 'https://metahumans.one/gear/mosip/verify.php';
$mosipDemoUrl = 'https://metahumans.one/gear/mosip/upstream-demo.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>KYC Ops Health</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    <style>
        .mh-wrap { max-width: 1100px; margin: 0 auto; padding: 28px 18px; }
        .mh-card { background: rgba(255,255,255,0.03); border: 1px solid rgba(0,212,255,0.2); border-radius: 14px; padding: 18px; }
        .mh-row { display:flex; gap: 14px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .mh-title { margin:0 0 6px 0; color:#00d4ff; }
        .mh-muted { color:#9aa; font-size: 12px; }
        .mh-badge { display:inline-flex; align-items:center; gap:8px; border-radius: 999px; padding: 6px 10px; border: 1px solid rgba(255,255,255,0.14); font-weight: 700; font-size: 12px; }
        .mh-ok { background: rgba(16,185,129,0.14); border-color: rgba(16,185,129,0.35); color:#c8ffe8; }
        .mh-bad { background: rgba(239,68,68,0.12); border-color: rgba(239,68,68,0.35); color:#ffd0d0; }
        .mh-btn { padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.25); color:#e6f6ff; cursor:pointer; font-weight:700; }
        .mh-btn-primary { background: #0aa0b6; border-color: rgba(0,212,255,0.35); }
        .mh-grid { display:grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
        @media (min-width: 900px) { .mh-grid { grid-template-columns: 1fr 1fr; } }
        .mh-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .mh-table th, .mh-table td { text-align:left; padding: 10px 12px; border-bottom: 1px solid rgba(0, 212, 255, 0.15); font-size: 0.95rem; color: rgba(255,255,255,0.9); vertical-align: top; }
        .mh-table th { color:#00d4ff; font-weight: 700; }
        .mh-pre { white-space: pre-wrap; word-break: break-word; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.12); border-radius: 12px; padding: 12px; margin-top: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px; }
        .mh-field { margin-top: 10px; }
        .mh-field label { display:block; margin: 0 0 6px 0; color:#cfefff; font-size: 12px; }
        .mh-field input { width: 100%; box-sizing:border-box; padding: 10px 12px; border-radius: 10px; border: 1px solid rgba(0,212,255,0.25); background: rgba(0,0,0,0.35); color:#fff; }
    </style>
</head>
<body>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
<main class="main-content">
    <div class="mh-wrap">
        <div class="mh-row" style="margin-bottom: 14px;">
            <div>
                <h1 class="mh-title">KYC Ops Health</h1>
                <div class="mh-muted">Shows whether key environment variables are active. Secrets are never displayed.</div>
            </div>
            <div class="mh-muted">Signed in as <?php echo htmlspecialchars($user, ENT_QUOTES); ?></div>
        </div>

        <div class="mh-grid">
            <div class="mh-card">
                <div style="font-weight:800; color:#e6f6ff;">Environment</div>
                <?php if (is_array($flash) && !empty($flash['msg'])): ?>
                    <div class="mh-pre" style="border-color: <?php echo (($flash['type'] ?? '') === 'ok') ? 'rgba(16,185,129,0.35)' : 'rgba(239,68,68,0.35)'; ?>; background: <?php echo (($flash['type'] ?? '') === 'ok') ? 'rgba(16,185,129,0.08)' : 'rgba(239,68,68,0.08)'; ?>;"><?php echo htmlspecialchars((string)$flash['msg'], ENT_QUOTES); ?></div>
                <?php endif; ?>
                <table class="mh-table">
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Status</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php $ok = !empty($r['enabled']); ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$r['key'], ENT_QUOTES); ?></td>
                                <td><span class="mh-badge <?php echo $ok ? 'mh-ok' : 'mh-bad'; ?>"><?php echo $ok ? 'ACTIVE' : 'INACTIVE'; ?></span></td>
                                <td class="mh-muted"><?php echo htmlspecialchars((string)$r['value'], ENT_QUOTES); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div style="margin-top: 12px;">
                    <a class="mh-btn" href="/auth/id/" style="display:inline-block; text-decoration:none;">Back to KYC</a>
                </div>
            </div>

            <div class="mh-card">
                <div style="font-weight:800; color:#e6f6ff;">MOSIP Config</div>
                <div class="mh-muted" style="margin-top: 6px;"><?php echo $isOps ? 'Ops actions write to kyc/env_overrides.json for this host.' : 'Read-only. Sign in with KripzMasters privileges to enable actions.'; ?></div>
                <?php if ($notice !== ''): ?>
                    <div class="mh-pre"><?php echo htmlspecialchars($notice, ENT_QUOTES); ?></div>
                <?php endif; ?>

                <div class="mh-field" style="margin-top: 12px;">
                    <label>MH_KYC_MOSIP_VERIFY_URL</label>
                    <input id="mhMosipUrl" type="text" value="<?php echo htmlspecialchars($mosipDefaultUrl, ENT_QUOTES); ?>">
                </div>
                <div class="mh-field">
                    <label>MH_KYC_MOSIP_SECRET (paste your shared secret)</label>
                    <input id="mhMosipSecret" type="text" value="">
                </div>

                <div style="margin-top: 12px; display:flex; gap: 10px; flex-wrap: wrap;">
                    <button class="mh-btn mh-btn-primary" type="button" onclick="mhCopyMosipApache()">Copy Apache SetEnv</button>
                    <button class="mh-btn" type="button" onclick="mhCopyMosipCurl()">Copy curl test</button>
                </div>

                <div class="mh-pre" id="mhMosipOut">Use the buttons above to copy a snippet.</div>

                <?php if ($isOps): ?>
                    <div style="margin-top: 14px; border-top: 1px solid rgba(255,255,255,0.10); padding-top: 14px;">
                        <div style="font-weight:800; color:#e6f6ff;">Actions</div>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="verifier_set_url">
                            <div class="mh-field">
                                <label>Set Verifier URL</label>
                                <input name="verifier_url" type="text" value="<?php echo htmlspecialchars($verifierUrl, ENT_QUOTES); ?>" placeholder="http://10.10.0.50:8787">
                            </div>
                            <button class="mh-btn" type="submit">Update Verifier URL</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="verifier_set_secret">
                            <div class="mh-field">
                                <label>Set Verifier Secret</label>
                                <div style="display:flex; gap: 10px; align-items: center;">
                                    <input id="mhVerifierSecret" name="verifier_secret" type="text" value="" style="flex: 1;">
                                    <button class="mh-btn" type="button" onclick="mhGenInto('mhVerifierSecret')">Generate</button>
                                </div>
                            </div>
                            <button class="mh-btn" type="submit">Set Verifier Secret</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="verifier_clear_secret">
                            <button class="mh-btn" type="submit">Clear Verifier Secret</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_enable">
                            <input type="hidden" name="mosip_url" value="<?php echo htmlspecialchars($mosipDefaultUrl, ENT_QUOTES); ?>">
                            <button class="mh-btn mh-btn-primary" type="submit">Enable MOSIP (default URL)</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_disable">
                            <button class="mh-btn" type="submit">Disable MOSIP</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_set_url">
                            <div class="mh-field">
                                <label>Set MOSIP Verify URL</label>
                                <input name="mosip_url" type="text" value="<?php echo htmlspecialchars($mosipUrl !== '' ? $mosipUrl : $mosipDefaultUrl, ENT_QUOTES); ?>">
                            </div>
                            <button class="mh-btn" type="submit">Update URL</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_set_secret">
                            <div class="mh-field">
                                <label>Set MOSIP Secret</label>
                                <div style="display:flex; gap: 10px; align-items: center;">
                                    <input id="mhMosipSecret2" name="mosip_secret" type="text" value="" style="flex: 1;">
                                    <button class="mh-btn" type="button" onclick="mhGenInto('mhMosipSecret2')">Generate</button>
                                </div>
                            </div>
                            <button class="mh-btn" type="submit">Set Secret</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_clear_secret">
                            <button class="mh-btn" type="submit">Clear Secret</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_set_upstream">
                            <div class="mh-field">
                                <label>Set MOSIP Upstream URL</label>
                                <input name="mosip_upstream" type="text" value="<?php echo htmlspecialchars($mosipUpstream, ENT_QUOTES); ?>" placeholder="https://...">
                            </div>
                            <button class="mh-btn" type="submit">Update Upstream</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_set_upstream">
                            <input type="hidden" name="mosip_upstream" value="<?php echo htmlspecialchars($mosipDemoUrl, ENT_QUOTES); ?>">
                            <button class="mh-btn" type="submit">Use Demo Upstream</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="mosip_clear_upstream">
                            <button class="mh-btn" type="submit">Clear Upstream</button>
                        </form>

                        <div style="margin-top: 14px;">
                            <form method="post" class="mh-row">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="nfc_full_on">
                                <button class="mh-btn" type="submit">Enable NFC Full Verify</button>
                            </form>
                            <form method="post" class="mh-row" style="margin-top: 10px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="nfc_full_off">
                                <button class="mh-btn" type="submit">Disable NFC Full Verify</button>
                            </form>
                            <form method="post" class="mh-row" style="margin-top: 10px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="set_csca_bundle">
                                <input type="hidden" name="csca_bundle" value="/data/trust/passport-csca.pem">
                                <button class="mh-btn" type="submit">Use /data/trust/passport-csca.pem</button>
                            </form>
                        </div>
                        <form method="post" style="margin-top: 14px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="set_csca_bundle">
                            <div class="mh-field">
                                <label>Passport CSCA Bundle Path</label>
                                <input name="csca_bundle" type="text" value="<?php echo htmlspecialchars($cscaBundle, ENT_QUOTES); ?>" placeholder="/data/trust/passport-csca.pem">
                            </div>
                            <button class="mh-btn" type="submit">Update CSCA Bundle</button>
                        </form>
                        <div style="margin-top: 14px;">
                            <form method="post" class="mh-row">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="set_passport_trust">
                                <input type="hidden" name="passport_trust" value="none">
                                <button class="mh-btn" type="submit">Passport Trust: Hash Only</button>
                            </form>
                            <form method="post" class="mh-row" style="margin-top: 10px;">
                                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                                <input type="hidden" name="action" value="set_passport_trust">
                                <input type="hidden" name="passport_trust" value="csca">
                                <button class="mh-btn" type="submit">Passport Trust: CSCA</button>
                            </form>
                        </div>
                        <form method="post" style="margin-top: 14px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="set_csca_countries">
                            <div class="mh-field">
                                <label>CSCA Enabled Countries (MRZ issuer codes)</label>
                                <input name="csca_countries" type="text" value="<?php echo htmlspecialchars($cscaCountries, ENT_QUOTES); ?>" placeholder="USA,GBR,ZAF,NAM">
                            </div>
                            <div class="mh-muted" style="margin-top: 6px;">Full authenticity (CSCA) is only applied for these countries. All other passports run hash-integrity-only.</div>
                            <button class="mh-btn" type="submit" style="margin-top: 10px;">Update CSCA Countries</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="clear_csca_bundle">
                            <button class="mh-btn" type="submit">Clear CSCA Bundle</button>
                        </form>
                        <form method="post" style="margin-top: 14px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="set_health_token">
                            <div class="mh-field">
                                <label>Health JSON Token</label>
                                <input name="health_token" type="text" value="">
                            </div>
                            <button class="mh-btn" type="submit">Set Health Token</button>
                        </form>
                        <form method="post" style="margin-top: 10px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="clear_health_token">
                            <button class="mh-btn" type="submit">Clear Health Token</button>
                        </form>

                        <form method="post" style="margin-top: 14px;">
                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>">
                            <input type="hidden" name="action" value="set_ttl">
                            <div class="mh-field">
                                <label>Session TTL (seconds)</label>
                                <input name="session_ttl" type="text" value="<?php echo htmlspecialchars((string)$ttlInt, ENT_QUOTES); ?>">
                            </div>
                            <button class="mh-btn" type="submit">Update Session TTL</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
<script>
function mhSetOut(text) {
  const el = document.getElementById('mhMosipOut');
  if (el) el.textContent = String(text || '');
}
function mhRandHex(bytes) {
  const b = Math.max(16, Math.min(128, parseInt(bytes || '32', 10) || 32));
  const arr = new Uint8Array(b);
  (window.crypto || window.msCrypto).getRandomValues(arr);
  return Array.from(arr).map(x => x.toString(16).padStart(2,'0')).join('');
}
function mhGenInto(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.value = mhRandHex(32);
  try { el.focus(); el.select(); } catch (e) {}
}
function mhCopyText(text) {
  const v = String(text || '');
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(v).catch(() => {});
  } else {
    const ta = document.createElement('textarea');
    ta.value = v;
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }
}
function mhCopyMosipApache() {
  const url = (document.getElementById('mhMosipUrl') || {}).value || '';
  const sec = (document.getElementById('mhMosipSecret') || {}).value || '';
  const snippet = [
    'SetEnv MH_KYC_MOSIP_ENABLED 1',
    'SetEnv MH_KYC_MOSIP_VERIFY_URL ' + url,
    sec ? ('SetEnv MH_KYC_MOSIP_SECRET ' + sec) : '# SetEnv MH_KYC_MOSIP_SECRET <shared secret>',
  ].join('\\n');
  mhSetOut(snippet);
  mhCopyText(snippet);
}
function mhCopyMosipCurl() {
  const url = (document.getElementById('mhMosipUrl') || {}).value || '';
  const sec = (document.getElementById('mhMosipSecret') || {}).value || '';
  const ts = Math.floor(Date.now() / 1000);
  const nonce = 'testnonce';
  const cmd = [
    "curl -sS -X POST " + JSON.stringify(url) + " \\",
    "  -H 'Accept: application/json' \\",
    "  -H 'Content-Type: application/json' \\",
    (sec ? ("  -H 'X-MH-Timestamp: " + ts + "' \\") : "  -H 'X-MH-Timestamp: <ts>' \\"),
    (sec ? ("  -H 'X-MH-Nonce: " + nonce + "' \\") : "  -H 'X-MH-Nonce: <nonce>' \\"),
    (sec ? "  -H 'X-MH-Signature: <computed>' \\" : "  -H 'X-MH-Signature: <computed>' \\"),
    "  --data '{" + "\"username\":\"test\",\"session_id\":\"test\",\"kind\":\"mosip\",\"evidence_hashes\":{}" + "}'",
  ].join('\\n');
  mhSetOut(cmd);
  mhCopyText(cmd);
}
</script>
</body>
</html>
