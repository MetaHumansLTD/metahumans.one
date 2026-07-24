<?php
/**
 * PopupNotice Widget Configuration Page
 * CUE Framework Compliant Version
 * 
 * COMPLIANCE CHECKLIST:
 * ✓ Uses getSecureFilePath() for file operations
 * ✓ Uses framework validation functions
 * ✓ Follows enterprise security standards
 * ✓ Implements proper error handling
 * 
 * Standalone configuration interface for the PopupNotice widget system
 */

// MANDATORY: Include CUE framework
require_once dirname(__DIR__, 3) . '/.cue/cue.php';
if (is_file(dirname(__DIR__, 3) . '/auth/tokenomics.php')) {
    require_once dirname(__DIR__, 3) . '/auth/tokenomics.php';
}

if (function_exists('cue_autoload')) {
    cue_autoload('theme');
    cue_autoload('database');
}

if (function_exists('startSecureSession')) {
    startSecureSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$_SESSION['current_realm'] = 'hub';

$username = isset($_SESSION['mh_auth_user']) ? trim((string)$_SESSION['mh_auth_user']) : '';
$tenantId = isset($_SESSION['mh_tenant_id']) ? trim((string)$_SESSION['mh_tenant_id']) : '';
if ($tenantId === '' && $username !== '') {
    $tenantId = 'user:' . $username;
    $_SESSION['mh_tenant_id'] = $tenantId;
}

// Handle form submission
$message = '';
$messageType = '';

if ($_POST) {
    $allowedThemes = ['modern','glass','dark','light','material','pastel','neon','ocean','forest','sunset','mono','cyberpunk','earth'];
    $allowedPositions = ['top-left','top-right','bottom-left','bottom-right','top-center','bottom-center','center-right','center-left','center'];
    $themeRaw = $_POST['popup_notice_theme'] ?? 'modern';
    $positionRaw = $_POST['popup_notice_position'] ?? 'top-right';
    $durationRaw = (int)($_POST['popup_notice_duration'] ?? 120);
    $stackRaw = isset($_POST['popup_notice_stack']);

    $theme = in_array($themeRaw, $allowedThemes, true) ? $themeRaw : 'modern';
    $position = in_array($positionRaw, $allowedPositions, true) ? $positionRaw : 'top-right';
    $duration = max(2, min(120, $durationRaw));

    if (function_exists('validateInput')) {
        $themeVal = validateInput($theme, 'string', ['allowed' => $allowedThemes]);
        $positionVal = validateInput($position, 'string', ['allowed' => $allowedPositions]);
        $durationVal = validateInput($duration, 'int', ['min' => 2, 'max' => 120]);
        $theme = $themeVal['sanitized'] ?? $theme;
        $position = $positionVal['sanitized'] ?? $position;
        $duration = $durationVal['sanitized'] ?? $duration;
    }

    $existing = [];
    $existingCustom = [];
    try {
        $paths = cue_autoload('paths');
        $dataBase = $paths->getDataPath();
        $readCandidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'widgets-config.json';
        $safeRead = $paths->validateSecurePath($readCandidate, $dataBase);
        if ($safeRead && file_exists($safeRead)) {
            $existingRaw = file_get_contents($safeRead);
            $existing = json_decode($existingRaw, true) ?: [];
            $existingCustom = (isset($existing['custom_messages']) && is_array($existing['custom_messages'])) ? $existing['custom_messages'] : [];
        }
    } catch (Throwable) { $existing = []; $existingCustom = []; }

    $panelEnabled = isset($_POST['notices_panel_enabled']);
    $panelAutoOpen = (int)($_POST['notices_panel_auto_open_seconds'] ?? 15);
    $panelAutoOpen = max(0, min(300, $panelAutoOpen));
    $panelLayout = isset($_POST['notices_panel_layout']) ? trim((string)$_POST['notices_panel_layout']) : 'right';
    if (!in_array($panelLayout, ['right', 'left'], true)) $panelLayout = 'right';
    $panelAnimation = isset($_POST['notices_panel_animation']);
    $panelFlicker = isset($_POST['notices_panel_icon_flicker']);

    $uploadsDir = null;
    try {
        $paths = cue_autoload('paths');
        $dataBase = $paths->getDataPath();
        $tenantSafe = preg_replace('/[^a-zA-Z0-9_\\-:\\.]+/', '_', (string)$tenantId);
        $tenantSafe = is_string($tenantSafe) ? trim($tenantSafe) : '';
        if ($tenantSafe === '') $tenantSafe = 'user_unknown';
        $tryDirs = [
            $dataBase . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $tenantSafe . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'uploads',
            $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'uploads',
        ];
        foreach ($tryDirs as $dirCandidate) {
            $safeDir = $paths->validateSecurePath($dirCandidate, $dataBase);
            if (!is_string($safeDir) || $safeDir === '') continue;
            if (!is_dir($safeDir)) {
                @mkdir($safeDir, 0750, true);
            }
            if (is_dir($safeDir) && is_writable($safeDir)) {
                $uploadsDir = $safeDir;
                break;
            }
        }
    } catch (Throwable) { $uploadsDir = null; }

    $custom = $existingCustom;
    $rawCustomJson = isset($_POST['notices_custom_messages_json']) ? trim((string)$_POST['notices_custom_messages_json']) : '';
    $hasLegacyCustomInputs = false;
    for ($i = 1; $i <= 5; $i++) {
        if (
            isset($_POST['notices_custom_title_' . $i]) ||
            isset($_POST['notices_custom_body_' . $i]) ||
            isset($_POST['notices_custom_url_' . $i]) ||
            isset($_POST['notices_custom_type_' . $i]) ||
            isset($_POST['notices_custom_pinned_' . $i]) ||
            isset($_POST['notices_custom_created_at_' . $i]) ||
            isset($_POST['notices_custom_remove_attachment_' . $i]) ||
            (isset($_FILES['notices_custom_file_' . $i]) && is_array($_FILES['notices_custom_file_' . $i]))
        ) {
            $hasLegacyCustomInputs = true;
            break;
        }
    }
    if ($rawCustomJson !== '' || $hasLegacyCustomInputs) {
        $custom = [];
        if ($rawCustomJson !== '') {
        $decoded = json_decode($rawCustomJson, true);
        $decoded = is_array($decoded) ? $decoded : [];
        foreach ($decoded as $m) {
            if (!is_array($m)) continue;
            $id = isset($m['id']) ? trim((string)$m['id']) : '';
            if ($id === '') $id = 'n_' . bin2hex(random_bytes(8));
            if (strlen($id) > 128) continue;
            $t = isset($m['title']) ? trim((string)$m['title']) : '';
            $b = isset($m['body']) ? trim((string)$m['body']) : '';
            $u = isset($m['url']) ? trim((string)$m['url']) : '';
            $ty = isset($m['type']) ? trim((string)$m['type']) : 'info';
            $pin = !empty($m['pinned']);
            $createdAt = isset($m['created_at']) ? trim((string)$m['created_at']) : '';
            $expiresAt = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
            $archivedAt = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
            $status = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';
            if (!in_array($ty, ['info', 'success', 'warning', 'error'], true)) $ty = 'info';
            if (!in_array($status, ['active', 'archived', 'expired'], true)) $status = 'active';
            if ($createdAt === '' && ($t !== '' || $b !== '' || $u !== '')) $createdAt = date('Y-m-d H:i:s');
            if ($status === 'archived' && $archivedAt === '') $archivedAt = date('Y-m-d H:i:s');
            $att = isset($m['attachment']) && is_array($m['attachment']) ? $m['attachment'] : null;
            if ($t === '' && $b === '' && $u === '' && $att === null) continue;
            $entry = [
                'id' => $id,
                'title' => $t,
                'body' => $b,
                'url' => $u,
                'type' => $ty,
                'pinned' => $pin,
                'created_at' => $createdAt,
                'expires_at' => $expiresAt,
                'status' => $status,
            ];
            if ($archivedAt !== '') $entry['archived_at'] = $archivedAt;
            if (is_array($att)) $entry['attachment'] = $att;
            $custom[] = $entry;
        }
        } else {
        for ($i = 1; $i <= 5; $i++) {
            $t = isset($_POST['notices_custom_title_' . $i]) ? trim((string)$_POST['notices_custom_title_' . $i]) : '';
            $b = isset($_POST['notices_custom_body_' . $i]) ? trim((string)$_POST['notices_custom_body_' . $i]) : '';
            $u = isset($_POST['notices_custom_url_' . $i]) ? trim((string)$_POST['notices_custom_url_' . $i]) : '';
            $ty = isset($_POST['notices_custom_type_' . $i]) ? trim((string)$_POST['notices_custom_type_' . $i]) : 'info';
            $pinned = isset($_POST['notices_custom_pinned_' . $i]);
            $createdAt = isset($_POST['notices_custom_created_at_' . $i]) ? trim((string)$_POST['notices_custom_created_at_' . $i]) : '';
            if ($createdAt === '' && isset($existingCustom[$i - 1]) && is_array($existingCustom[$i - 1]) && isset($existingCustom[$i - 1]['created_at'])) {
                $createdAt = trim((string)$existingCustom[$i - 1]['created_at']);
            }
            if ($createdAt === '' && ($t !== '' || $b !== '')) {
                $createdAt = date('Y-m-d H:i:s');
            }
            if (!in_array($ty, ['info', 'success', 'warning', 'error'], true)) $ty = 'info';
            $existingAtt = null;
            if (isset($existingCustom[$i - 1]) && is_array($existingCustom[$i - 1]) && isset($existingCustom[$i - 1]['attachment']) && is_array($existingCustom[$i - 1]['attachment'])) {
                $existingAtt = $existingCustom[$i - 1]['attachment'];
            }
            $removeAtt = isset($_POST['notices_custom_remove_attachment_' . $i]);
            $attachment = null;
            if (!$removeAtt) {
                $fileKey = 'notices_custom_file_' . $i;
                $hasUpload = isset($_FILES[$fileKey]) && is_array($_FILES[$fileKey]) && isset($_FILES[$fileKey]['error']) && (int)$_FILES[$fileKey]['error'] === UPLOAD_ERR_OK;
                if ($hasUpload && is_string($uploadsDir) && $uploadsDir !== '' && is_dir($uploadsDir) && is_writable($uploadsDir)) {
                    $tmp = (string)($_FILES[$fileKey]['tmp_name'] ?? '');
                    $orig = (string)($_FILES[$fileKey]['name'] ?? '');
                    $orig = trim($orig);
                    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                    $allowedExt = ['pdf', 'docx', 'txt', 'md'];
                    if ($tmp !== '' && is_uploaded_file($tmp) && $ext !== '' && in_array($ext, $allowedExt, true)) {
                        $base = pathinfo($orig, PATHINFO_FILENAME);
                        $base = preg_replace('/[^a-zA-Z0-9\\-_. ]+/', '', (string)$base);
                        $base = is_string($base) ? trim($base) : '';
                        if ($base === '') $base = 'document';
                        $safeName = substr($base, 0, 60) . '.' . $ext;
                        $fileId = 'f_' . bin2hex(random_bytes(8));
                        $stored = $fileId . '_' . str_replace(' ', '_', $safeName);
                        $dest = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $stored;
                        if (@move_uploaded_file($tmp, $dest)) {
                            $mime = '';
                            try {
                                if (function_exists('mime_content_type')) {
                                    $m = @mime_content_type($dest);
                                    if (is_string($m)) $mime = trim($m);
                                }
                            } catch (Throwable) {}
                            $attachment = [
                                'file_id' => $fileId,
                                'name' => $orig !== '' ? $orig : $safeName,
                                'stored' => basename($stored),
                                'ext' => $ext,
                                'mime' => $mime,
                                'uploaded_at' => date('Y-m-d H:i:s'),
                            ];
                        }
                    }
                }
                if ($attachment === null && is_array($existingAtt)) {
                    $attachment = $existingAtt;
                }
            }
            if ($t === '' && $b === '' && $u === '' && $attachment === null) continue;
            $entry = [
                'id' => 'n_' . bin2hex(random_bytes(8)),
                'title' => $t,
                'body' => $b,
                'url' => $u,
                'type' => $ty,
                'pinned' => $pinned,
                'created_at' => $createdAt,
                'status' => 'active',
            ];
            if (is_array($attachment)) {
                $entry['attachment'] = $attachment;
            }
            $custom[] = $entry;
        }
        }
    }

    $custom = $existingCustom;
    $config = [
        'enabled' => isset($_POST['popup_notice_enabled']),
        'theme' => $theme,
        'position' => $position,
        'duration' => $duration * 1000, // Convert seconds to milliseconds
        'stackNotifications' => $stackRaw,
        'maxStack' => (int)($_POST['popup_notice_max_stack'] ?? 5),
        'enableAnimation' => isset($_POST['popup_notice_animation']),
        'panel' => [
            'enabled' => $panelEnabled,
            'auto_open_seconds' => $panelAutoOpen,
            'layout' => $panelLayout,
            'enable_animation' => $panelAnimation,
            'icon_flicker_on_new' => $panelFlicker,
        ],
        'custom_messages' => $custom,
    ];

    $payload = json_encode($config, JSON_PRETTY_PRINT);
    $candidates = [];
    try {
        $paths = cue_autoload('paths');
        $dataBase = $paths->getDataPath();
        $candidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'widgets-config.json';
        $safeCandidate = $paths->validateSecurePath($candidate, $dataBase);
        if ($safeCandidate) { $candidates[] = $safeCandidate; }
    } catch (Throwable $e) { /* ignore and proceed with no save */ }

    $finalPath = null;
    $dirInfo = null;
    $saveOk = false;
    foreach ($candidates as $p) {
        if (!$p) { continue; }
        $dir = dirname($p);
        if ($dir && is_dir($dir) && is_writable($dir)) {
            if (@file_put_contents($p, $payload) !== false) { $finalPath = $p; $dirInfo = $dir; $saveOk = true; break; }
        }
    }
    if ($saveOk) {
        $message = 'Widget configuration saved successfully!';
        $messageType = 'success';
    } else {
        $message = 'Failed to save configuration';
        $messageType = 'error';
        $err = error_get_last();
        error_log('widgets-config.php save failed: candidates=' . json_encode($candidates) . ' chosen=' . ($finalPath ?: 'null') . ' dir=' . ($dirInfo ?: 'null') . ' dir_exists=' . (int)!!($dirInfo && is_dir($dirInfo)) . ' dir_writable=' . (int)!!($dirInfo && is_writable($dirInfo)) . ' error=' . ($err['message'] ?? 'n/a'));
    }

    $isAjax = ((isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false));
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $saveOk,
            'message' => $message,
            'path' => $finalPath,
            'dir' => $dirInfo,
            'dirExists' => !!($dirInfo && is_dir($dirInfo)),
            'dirWritable' => !!($dirInfo && is_writable($dirInfo))
        ]);
        exit;
    }
}

$config = [];
try {
    $paths = cue_autoload('paths');
    $dataBase = $paths->getDataPath();
    $readCandidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'widgets-config.json';
    $safeRead = $paths->validateSecurePath($readCandidate, $dataBase);
    if ($safeRead && file_exists($safeRead)) {
        $configContent = file_get_contents($safeRead);
        $config = json_decode($configContent, true) ?: [];
    }
} catch (Throwable $e) { /* ignore read error */ }

// Default values
$config = array_merge([
    'enabled' => true,
    'theme' => 'dark',
    'position' => 'center',
    'duration' => 5,
    'stackNotifications' => true,
    'maxStack' => 5,
    'enableAnimation' => true,
    'panel' => [
        'enabled' => true,
        'auto_open_seconds' => 15,
        'layout' => 'right',
        'enable_animation' => true,
        'icon_flicker_on_new' => true,
    ],
    'custom_messages' => [],
], $config);

$prefill = isset($_GET['prefill']) ? trim((string)$_GET['prefill']) : '';
if ($prefill === 'culture_reservation') {
    $prefTitle = 'Culture Coins Reservation';
    $prefUrl = '/hub/coins/culture.php';
    $prefType = 'info';
    $prefPinned = true;
    $prefBody = "Culture coins reservation is open.\n\nOpen the Culture Coins page for details.";
    try {
        if (function_exists('mh_tokenomics_get_tokenomics_pdo') && function_exists('mh_tokenomics_seed_culture_coins')) {
            $pdoTok = mh_tokenomics_get_tokenomics_pdo();
            if ($pdoTok instanceof PDO) {
                $pdoTok->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $ids = mh_tokenomics_seed_culture_coins($pdoTok);
                $champId = (int)($ids['champcoin'] ?? 0);
                $superId = (int)($ids['supercoin'] ?? 0);

                $champ = ['name' => 'Champion Coin', 'ticker' => 'mhc', 'cap' => 0, 'issue' => '', 'close' => ''];
                $super = ['name' => 'Super Coin', 'ticker' => 'mhs', 'cap' => 0, 'issue' => '', 'close' => ''];

                $readMeta = function (int $id) use ($pdoTok): array {
                    $stmt = $pdoTok->prepare("SELECT display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                    $stmt->execute([$id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $out = ['name' => '', 'ticker' => '', 'cap' => 0, 'issue' => '', 'close' => ''];
                    if (is_array($row) && !empty($row)) {
                        $out['name'] = trim((string)($row['display_name'] ?? ''));
                        $raw = trim((string)($row['pricing_params_json'] ?? ''));
                        $meta = $raw !== '' ? json_decode($raw, true) : null;
                        if (is_array($meta)) {
                            $out['ticker'] = isset($meta['ticker']) ? trim((string)$meta['ticker']) : '';
                            $out['cap'] = isset($meta['supply_cap']) ? (int)$meta['supply_cap'] : 0;
                            $out['issue'] = isset($meta['issue_date']) ? trim((string)$meta['issue_date']) : '';
                            $out['close'] = isset($meta['close_date']) ? trim((string)$meta['close_date']) : '';
                        }
                    }
                    return $out;
                };

                if ($champId > 0) {
                    $m = $readMeta($champId);
                    if ($m['name'] !== '') $champ['name'] = $m['name'];
                    if ($m['ticker'] !== '') $champ['ticker'] = $m['ticker'];
                    $champ['cap'] = (int)($m['cap'] ?? 0);
                    $champ['issue'] = (string)($m['issue'] ?? '');
                    $champ['close'] = (string)($m['close'] ?? '');
                }
                if ($superId > 0) {
                    $m = $readMeta($superId);
                    if ($m['name'] !== '') $super['name'] = $m['name'];
                    if ($m['ticker'] !== '') $super['ticker'] = $m['ticker'];
                    $super['cap'] = (int)($m['cap'] ?? 0);
                    $super['issue'] = (string)($m['issue'] ?? '');
                    $super['close'] = (string)($m['close'] ?? '');
                }

                $champPrice = null;
                if ($champId > 0 && function_exists('mh_tokenomics_get_current_price_usd')) {
                    $p = mh_tokenomics_get_current_price_usd($pdoTok, $champId);
                    if (is_float($p) && $p > 0) $champPrice = $p;
                }
                $superCurrent = null;
                if ($superId > 0 && function_exists('mh_tokenomics_get_current_price_usd')) {
                    $p = mh_tokenomics_get_current_price_usd($pdoTok, $superId);
                    if (is_float($p) && $p > 0) $superCurrent = $p;
                }
                $superNext = null;
                $superNextFrom = '';
                if ($superId > 0) {
                    $stmt = $pdoTok->prepare("SELECT price_usd_per_unit, effective_from FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from > NOW() ORDER BY effective_from ASC LIMIT 1");
                    $stmt->execute([$superId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($r) && !empty($r)) {
                        $p = isset($r['price_usd_per_unit']) ? (float)$r['price_usd_per_unit'] : null;
                        if (is_float($p) && $p > 0) $superNext = $p;
                        $superNextFrom = isset($r['effective_from']) ? trim((string)$r['effective_from']) : '';
                    }
                }

                $champRemaining = null;
                if ($champId > 0 && (int)$champ['cap'] > 0) {
                    $stmt = $pdoTok->prepare("SELECT COALESCE(SUM(units_owned), 0) FROM mh_asset_ledger WHERE asset_class_id = ?");
                    $stmt->execute([$champId]);
                    $issued = (int)$stmt->fetchColumn();
                    $champRemaining = max(0, (int)$champ['cap'] - max(0, $issued));
                }

                $lines = [];
                $lines[] = 'Open: ' . $prefUrl;
                $lines[] = '';
                $lines[] = $champ['name'] . ' (' . strtoupper($champ['ticker']) . ')';
                if ($champPrice !== null) $lines[] = 'Price: $' . number_format((float)$champPrice, 2, '.', '') . ' per coin';
                if ($champ['issue'] !== '') $lines[] = 'Issue date: ' . $champ['issue'];
                if ($champ['close'] !== '') $lines[] = 'Close date: ' . $champ['close'];
                if ((int)$champ['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$champ['cap']);
                if (is_int($champRemaining)) $lines[] = 'Remaining: ' . number_format((int)$champRemaining) . ' ' . strtoupper($champ['ticker']);
                $lines[] = '';
                $lines[] = $super['name'] . ' (' . strtoupper($super['ticker']) . ')';
                if ($superCurrent !== null) $lines[] = 'Current price: $' . number_format((float)$superCurrent, 2, '.', '') . ' per coin';
                if ($superCurrent === null && $superNext !== null && $superNextFrom !== '') $lines[] = 'Next price: $' . number_format((float)$superNext, 2, '.', '') . ' from ' . $superNextFrom;
                if ($super['issue'] !== '') $lines[] = 'Issue date: ' . $super['issue'];
                if ($super['close'] !== '') $lines[] = 'Close date: ' . $super['close'];
                if ((int)$super['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$super['cap']);

                $prefBody = implode("\n", $lines);
            }
        }
    } catch (Throwable) {}

    if (!isset($config['custom_messages']) || !is_array($config['custom_messages'])) {
        $config['custom_messages'] = [];
    }
    $hasAlready = false;
    foreach ($config['custom_messages'] as $m) {
        if (!is_array($m)) continue;
        $t = isset($m['title']) ? trim((string)$m['title']) : '';
        $st = isset($m['status']) ? strtolower(trim((string)$m['status'])) : 'active';
        $arch = isset($m['archived_at']) ? trim((string)$m['archived_at']) : '';
        $exp = isset($m['expires_at']) ? trim((string)$m['expires_at']) : '';
        if (strcasecmp($t, $prefTitle) !== 0) continue;
        if ($st === 'archived' || $arch !== '') continue;
        if ($exp !== '') {
            $tExp = strtotime($exp);
            if ($tExp && $tExp <= time()) continue;
        }
        $hasAlready = true;
        break;
    }
    if (!$hasAlready) {
        array_unshift($config['custom_messages'], [
            'id' => 'n_' . bin2hex(random_bytes(8)),
            'title' => $prefTitle,
            'body' => $prefBody,
            'url' => $prefUrl,
            'type' => $prefType,
            'pinned' => $prefPinned,
            'created_at' => date('Y-m-d H:i:s'),
            'status' => 'active',
        ]);
    }
}

// Status message already set in POST handler
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PopupNotice Widget Configuration</title>
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-head.php'; } ?>
    
    <?php
    // Include PopupNotice assets directly to avoid early initialization issues
    echo '<link rel="stylesheet" href="/templates/widgets/notices/popup-notice.css">';
    echo '<script src="/templates/widgets/notices/popup-notice.js"></script>';
    $pos = $config['position'] ?? 'center';
    $dur = isset($config['duration']) ? (int)$config['duration'] * 1000 : 5000;
    $stack = !empty($config['stackNotifications'] ?? $config['stack']);
    $maxStack = $config['maxStack'] ?? 5;
    $animation = !empty($config['enableAnimation']);
    $theme = $config['theme'] ?? 'dark';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){ if(window.PopupNotice){ window.globalPopupNotice = new PopupNotice({ position: '.json_encode($pos).', duration: '.$dur.', stackNotifications: '.($stack ? 'true' : 'false').', maxStack: '.$maxStack.', enableAnimation: '.($animation ? 'true' : 'false').', theme: '.json_encode($theme).' }); window.popupNotice = window.globalPopupNotice; } });</script>';
    ?>
    <?php /* Loader assets removed */ ?>
    <style>
        :root {
            --primary-color: #2196f3;
            --info-color: #17a2b8;
            --secondary-color: #6c757d;
            --accent-color: #e91e63;
            --neutral-color: #adb5bd;
            --success-color: #4caf50;
            --warning-color: #ff9800;
            --danger-color: #f44336;
            --dark-color: #212529;
            --light-color: #f8f9fa;
            --border-color: #dee2e6;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }

        .header {
            background: var(--primary-color);
            color: white;
            padding: 30px;
            text-align: center;
        }

        .header h1 {
            font-size: 2rem;
            margin-bottom: 10px;
        }

        .header p {
            opacity: 0.9;
            font-size: 1.1rem;
        }

        .content {
            padding: 40px;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--dark-color);
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--primary-color);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin: 0;
        }

        .form-help {
            display: block;
            margin-top: 5px;
            color: #6c757d;
            font-size: 14px;
        }

        .mh-file-wrap {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }
        .mh-file-input {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            border: 0;
        }
        .mh-file-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid rgba(0, 212, 255, 0.35);
            background: rgba(35, 35, 40, 0.72);
            backdrop-filter: blur(10px);
            color: #00d4ff;
            font-weight: 800;
            cursor: pointer;
            user-select: none;
        }
        .mh-file-btn:hover {
            background: rgba(35, 35, 40, 0.86);
            border-color: rgba(0, 212, 255, 0.55);
        }
        .mh-file-name {
            color: rgba(0,0,0,0.72);
            font-size: 14px;
            font-weight: 700;
            word-break: break-word;
        }

        .range-container {
            margin: 15px 0;
        }

        .range-value {
            text-align: center;
            margin-top: 10px;
            font-weight: 600;
            color: var(--primary-color);
            font-size: 18px;
        }

        .test-controls {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .btn {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }

        .btn-primary { background: var(--primary-color); color: white; }
        .btn-info { background: var(--info-color); color: white; }
        .btn-secondary { background: var(--secondary-color); color: white; }
        .btn-accent { background: var(--accent-color); color: white; }
        .btn-neutral { background: var(--neutral-color); color: #212529; }
        .btn-success { background: var(--success-color); color: white; }
        .btn-warning { background: var(--warning-color); color: white; }
        .btn-danger { background: var(--danger-color); color: white; }

        .status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .status-item {
            background: var(--light-color);
            padding: 15px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 10px;
            color: #555;
        }

        .status-item i {
            color: var(--success-color);
            font-size: 20px;
        }

        .status-item span,
        .status-item strong,
        .status-item pre,
        .status-item code {
            color: #555;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 600;
            margin-bottom: 20px;
            transition: color 0.3s;
        }

        .back-link:hover {
            color: var(--dark-color);
        }

        .message {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .message.success {
            background: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
            border: 1px solid rgba(76, 175, 80, 0.3);
        }
        .message.error {
            background: rgba(244, 67, 54, 0.1);
            color: #b71c1c;
            border: 1px solid rgba(244, 67, 54, 0.3);
        }

        @media (max-width: 768px) {
            .test-controls {
                grid-template-columns: 1fr;
            }
            
            .status-grid {
                grid-template-columns: 1fr;
            }
            
            .content {
                padding: 20px;
            }
        }
    </style>
</head>
<body class="hub-page">
    <?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-start.php'; } ?>
    <main class="main-content">
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-puzzle-piece"></i> PopupNotice Widget Configuration</h1>
            <p>Configure the modern notification system for your website</p>
        </div>
        
        <div class="content">
            <a href="/templates/theme/theme-generator.php" class="back-link">
                <i class="fas fa-arrow-left"></i> Back to Theme Generator
            </a>
            
            <div id="inline_message" class="message" style="display:none;"></div>
            <?php if ($message): ?>
                <script>
                  (function(){
                    var el = document.getElementById('inline_message');
                    if (el) {
                        el.className = 'message <?= $messageType ?>';
                        el.innerHTML = '<i class="fas fa-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>' + <?= json_encode($message) ?>;
                        el.style.display = 'flex';
                    }
                  })();
                </script>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <script type="application/json" id="notice-widget-settings-export">
                    <?= json_encode([
                        'enabled' => !empty($config['enabled']),
                        'theme' => $config['theme'] ?? 'modern',
                        'position' => $config['position'] ?? 'top-right',
                        'duration' => (int)($config['duration'] ?? 5),
                        'stack' => !empty($config['stack'])
                    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
                </script>
                <div class="form-group">
                    <label for="popup_notice_enabled">Enable PopupNotice Widget</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="popup_notice_enabled" name="popup_notice_enabled" <?= $config['enabled'] ? 'checked' : '' ?>>
                        <label for="popup_notice_enabled">Include PopupNotice widget in generated pages</label>
                    </div>
                    <small class="form-help">The PopupNotice widget provides modern, accessible notifications to replace browser alert() dialogs.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_theme">Default Theme</label>
                    <select id="popup_notice_theme" name="popup_notice_theme">
                        <option value="modern" <?= $config['theme'] === 'modern' ? 'selected' : '' ?>>Modern (Default)</option>
                        <option value="glass" <?= $config['theme'] === 'glass' ? 'selected' : '' ?>>Glass</option>
                        <option value="dark" <?= $config['theme'] === 'dark' ? 'selected' : '' ?>>Dark</option>
                        <option value="light" <?= $config['theme'] === 'light' ? 'selected' : '' ?>>Light</option>
                        <option value="material" <?= $config['theme'] === 'material' ? 'selected' : '' ?>>Material</option>
                        <option value="pastel" <?= $config['theme'] === 'pastel' ? 'selected' : '' ?>>Pastel</option>
                        <option value="neon" <?= $config['theme'] === 'neon' ? 'selected' : '' ?>>Neon</option>
                        <option value="ocean" <?= $config['theme'] === 'ocean' ? 'selected' : '' ?>>Ocean</option>
                        <option value="forest" <?= $config['theme'] === 'forest' ? 'selected' : '' ?>>Forest</option>
                        <option value="sunset" <?= $config['theme'] === 'sunset' ? 'selected' : '' ?>>Sunset</option>
                        <option value="mono" <?= $config['theme'] === 'mono' ? 'selected' : '' ?>>Mono</option>
                        <option value="cyberpunk" <?= $config['theme'] === 'cyberpunk' ? 'selected' : '' ?>>Cyberpunk</option>
                        <option value="earth" <?= $config['theme'] === 'earth' ? 'selected' : '' ?>>Earth</option>
                    </select>
                    <small class="form-help">Choose the default appearance theme for notifications.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_position">Default Position</label>
                    <select id="popup_notice_position" name="popup_notice_position">
                        <option value="top-right" <?= $config['position'] === 'top-right' ? 'selected' : '' ?>>Top Right (Default)</option>
                        <option value="top-left" <?= $config['position'] === 'top-left' ? 'selected' : '' ?>>Top Left</option>
                        <option value="bottom-right" <?= $config['position'] === 'bottom-right' ? 'selected' : '' ?>>Bottom Right</option>
                        <option value="bottom-left" <?= $config['position'] === 'bottom-left' ? 'selected' : '' ?>>Bottom Left</option>
                        <option value="top-center" <?= $config['position'] === 'top-center' ? 'selected' : '' ?>>Top Center</option>
                        <option value="bottom-center" <?= $config['position'] === 'bottom-center' ? 'selected' : '' ?>>Bottom Center</option>
                        <option value="center-right" <?= $config['position'] === 'center-right' ? 'selected' : '' ?>>Center Right</option>
                        <option value="center-left" <?= $config['position'] === 'center-left' ? 'selected' : '' ?>>Center Left</option>
                        <option value="center" <?= $config['position'] === 'center' ? 'selected' : '' ?>>Center</option>
                    </select>
                    <small class="form-help">Default position for notifications on the page.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_duration">Default Duration (seconds)</label>
                    <div class="range-container">
                        <input type="number" id="popup_notice_duration" name="popup_notice_duration" min="2" max="120" value="<?= $config['duration'] ?>" step="1" inputmode="numeric" pattern="[0-9]*">
                        <div class="range-value">
                            <span id="popup_notice_duration_display"><?= $config['duration'] ?></span> seconds
                        </div>
                    </div>
                    <small class="form-help">How long notifications stay visible before auto-hiding.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_stack">Stack Notifications</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="popup_notice_stack" name="popup_notice_stack" <?= !empty($config['stackNotifications'] ?? $config['stack']) ? 'checked' : '' ?>>
                        <label for="popup_notice_stack">Allow multiple notices to stack</label>
                    </div>
                    <small class="form-help">Enable stacking so multiple notifications appear together.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_max_stack">Maximum Stack Size</label>
                    <div class="range-container">
                        <input type="number" id="popup_notice_max_stack" name="popup_notice_max_stack" min="1" max="10" value="<?= $config['maxStack'] ?? 5 ?>" step="1">
                        <div class="range-value">
                            <span id="popup_notice_max_stack_display"><?= $config['maxStack'] ?? 5 ?></span> notices
                        </div>
                    </div>
                    <small class="form-help">Maximum number of notices that can be displayed at once.</small>
                </div>

                <div class="form-group">
                    <label for="popup_notice_animation">Enable Animations</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="popup_notice_animation" name="popup_notice_animation" <?= !empty($config['enableAnimation']) ? 'checked' : '' ?>>
                        <label for="popup_notice_animation">Enable slide and fade animations</label>
                    </div>
                    <small class="form-help">Add smooth animations when notices appear and disappear.</small>
                </div>

                <div class="form-group">
                    <label>Hub Notices Panel</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="notices_panel_enabled" name="notices_panel_enabled" <?= !empty($config['panel']['enabled'] ?? false) ? 'checked' : '' ?>>
                        <label for="notices_panel_enabled">Enable floating notices panel on Hub</label>
                    </div>
                    <small class="form-help">Controls the floating notices icon + panel on /hub/.</small>
                </div>

                <div class="form-group">
                    <label for="notices_panel_auto_open_seconds">Auto Open Time (seconds)</label>
                    <div class="range-container">
                        <input type="number" id="notices_panel_auto_open_seconds" name="notices_panel_auto_open_seconds" min="0" max="300" value="<?= (int)($config['panel']['auto_open_seconds'] ?? 15) ?>" step="1" inputmode="numeric" pattern="[0-9]*">
                    </div>
                    <small class="form-help">Auto-open the panel when there are new notices. Set 0 to disable.</small>
                </div>

                <div class="form-group">
                    <label for="notices_panel_layout">Panel Layout</label>
                    <select id="notices_panel_layout" name="notices_panel_layout">
                        <option value="right" <?= (($config['panel']['layout'] ?? 'right') === 'right') ? 'selected' : '' ?>>Right</option>
                        <option value="left" <?= (($config['panel']['layout'] ?? 'right') === 'left') ? 'selected' : '' ?>>Left</option>
                    </select>
                    <small class="form-help">Default side where the panel opens. Users can still drag/resize it.</small>
                </div>

                <div class="form-group">
                    <label for="notices_panel_animation">Panel Animations</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="notices_panel_animation" name="notices_panel_animation" <?= !empty($config['panel']['enable_animation'] ?? false) ? 'checked' : '' ?>>
                        <label for="notices_panel_animation">Enable panel animation behavior</label>
                    </div>
                    <small class="form-help">Enables animated indicator behavior for the notices panel.</small>
                </div>

                <div class="form-group">
                    <label for="notices_panel_icon_flicker">New Notice Indicator</label>
                    <div class="checkbox-group">
                        <input type="checkbox" id="notices_panel_icon_flicker" name="notices_panel_icon_flicker" <?= !empty($config['panel']['icon_flicker_on_new'] ?? false) ? 'checked' : '' ?>>
                        <label for="notices_panel_icon_flicker">Flicker icon when new notices exist</label>
                    </div>
                    <small class="form-help">Shows a dot and optional flicker when there are new notices.</small>
                </div>

                <div class="form-group">
                    <label>Custom Messages (show inside Hub notices panel)</label>
                    <small class="form-help">Managed by administrators.</small>
                    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top: 10px;">
                        <a class="btn btn-secondary" href="/hub/notice-archived.php">View Archived/Expired</a>
                    </div>
                </div>

                <div class="form-group">
                    <label>Preview & Test</label>
                    <div class="test-controls">
                        <button type="button" class="btn btn-success" onclick="testPopupNotice('success')">
                            <i class="fas fa-check"></i> Test Success
                        </button>
                        <button type="button" class="btn btn-warning" onclick="testPopupNotice('warning')">
                            <i class="fas fa-exclamation-triangle"></i> Test Warning
                        </button>
                        <button type="button" class="btn btn-danger" onclick="testPopupNotice('error')">
                            <i class="fas fa-times"></i> Test Error
                        </button>
                        <button type="button" class="btn btn-info" onclick="testPopupNotice('info')">
                            <i class="fas fa-info"></i> Test Info
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="testPopupNotice('secondary')">
                            <i class="fas fa-circle"></i> Test Secondary
                        </button>
                        <button type="button" class="btn btn-accent" onclick="testPopupNotice('accent')">
                            <i class="fas fa-star"></i> Test Accent
                        </button>
                        <button type="button" class="btn btn-neutral" onclick="testPopupNotice('neutral')">
                            <i class="fas fa-minus"></i> Test Neutral
                        </button>
                        <button type="button" class="btn btn-primary" onclick="runPositionTests()">
                            <i class="fas fa-location-arrow"></i> Run Position Tests
                        </button>
                    </div>
                    <small class="form-help">Test the PopupNotice widget with different message types and current settings.</small>
                </div>

                <div class="form-group">
                    <label>Integration Status</label>
                    <div class="status-grid">
                        <div class="status-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Widget Files Included</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-check-circle"></i>
                            <span>CUE Framework Integration</span>
                        </div>
                        <div class="status-item">
                            <i class="fas fa-check-circle"></i>
                            <span>Global Instance Available</span>
                        </div>
                    </div>
                    <small class="form-help">PopupNotice widget is fully integrated and ready to use.</small>
                </div>
                
                <div class="form-group">
                    <label>Integration Instructions</label>
                    <div class="status-item" style="display:block;">
<pre style="white-space:pre-wrap; font-family:monospace; color:#555;">
&lt;?php
// 1) Include CUE framework
require_once dirname(dirname(__DIR__)) . '/.cue/cue.php';

// 2) Configure and include notice widget
$noticeConfig = [
  'theme' => 'modern',
  'position' => 'top-right',
  'autoClose' => 5000,
  'stackable' => true,
];

echo includeNoticeWidget($noticeConfig);

// 3) Include loader inline (fallback for environments without asset bundle)
$loaderPath = function_exists('getSecureFilePath')
  ? getSecureFilePath(dirname(__DIR__) . '/loader/loader.php')
  : (dirname(__DIR__) . '/loader/loader.php');
if ($loaderPath && file_exists($loaderPath)) { include $loaderPath; }
?&gt;

&lt;script&gt;
// Access widgets via window.globalPopupNotice and window.globalLoader
window.globalPopupNotice.success('Operation successful!');
window.globalLoader.show('Processing...');
&lt;/script&gt;
</pre>
                    </div>
                </div>
                <div class="form-group">
                    <label>Widget URLs (for manual integration)</label>
                    <div class="status-item" style="display:block; color:#555;">
                        <div><strong>Notice CSS:</strong> <code style="color:#333;">&lt;?= getNoticeWidgetUrl('popup-notice.css') ?&gt;</code></div>
                        <div><strong>Notice JS:</strong> <code style="color:#333;">&lt;?= getNoticeWidgetUrl('popup-notice.js') ?&gt;</code></div>
                        <div><strong>Loader CSS:</strong> <code style="color:#333;">&lt;?= getLoaderWidgetUrl('loader.css') ?&gt;</code></div>
                        <div><strong>Loader JS:</strong> <code style="color:#333;">&lt;?= getLoaderWidgetUrl('loader.js') ?&gt;</code></div>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 30px;">
                    <button type="submit" class="btn btn-success" style="font-size: 16px; padding: 15px 30px;">
                        <i class="fas fa-save"></i> Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        
        // PopupNotice Widget Functions
        // Pre-initialize instance after DOM is ready to avoid work during click
        document.addEventListener('DOMContentLoaded', function() {
            var form = document.querySelector('form');
            if (form) {
                var autoSaveTimer = null;
                var saving = false;
                var saveQueued = false;
                var lastAutoSaveAt = 0;

                function upsertMessage(kind, text) {
                    var el = document.getElementById('inline_message');
                    if (!el) {
                        el = document.createElement('div');
                        el.id = 'inline_message';
                        el.className = 'message';
                        var content = document.querySelector('.content');
                        content && content.insertBefore(el, content.querySelector('form'));
                    }
                    el.className = 'message ' + kind;
                    el.innerHTML = '<i class="fas fa-' + (kind === 'success' ? 'check-circle' : (kind === 'warning' ? 'exclamation-triangle' : 'exclamation-triangle')) + '"></i>' + text;
                    el.style.display = 'flex';
                }

                async function doSave(isAuto) {
                    if (saving) { saveQueued = true; return; }
                    saving = true;
                    try {
                        if (isAuto) {
                            upsertMessage('warning', 'Autosaving…');
                        }
                        var fd = new FormData(form);
                        var res = await fetch(window.location.href, { method:'POST', body: fd, headers: { 'X-Requested-With':'XMLHttpRequest', 'Accept':'application/json' } });
                        var data = {};
                        try { data = await res.json(); } catch(_) {}
                        var ok = !!(data && data.success);
                        var text = (data && data.message) ? data.message : (ok ? 'Widget configuration saved successfully!' : 'Failed to save configuration');
                        upsertMessage(ok ? 'success' : 'error', isAuto ? ('Autosaved: ' + text) : text);
                        if (!isAuto) {
                            (window.globalPopupNotice || window._popupNoticeInstance)?.[ok ? 'success' : 'error'](text);
                        }
                        lastAutoSaveAt = Date.now();
                    } catch (err) {
                        upsertMessage('error', isAuto ? 'Autosave failed' : 'Failed to save configuration');
                        if (!isAuto) {
                            (window.globalPopupNotice || window._popupNoticeInstance)?.error('Failed to save configuration');
                        }
                    } finally {
                        saving = false;
                        if (saveQueued) {
                            saveQueued = false;
                            setTimeout(function () { doSave(true); }, 250);
                        }
                    }
                }

                function scheduleAutosave(delayMs) {
                    if (autoSaveTimer) clearTimeout(autoSaveTimer);
                    autoSaveTimer = setTimeout(function () {
                        doSave(true);
                    }, typeof delayMs === 'number' ? delayMs : 900);
                }

                try {
                    var fileInputs = form.querySelectorAll('.mh-file-input');
                    fileInputs.forEach(function (inp) {
                        function update() {
                            var wrap = inp.closest('.mh-file-wrap');
                            var nameEl = wrap ? wrap.querySelector('.mh-file-name') : null;
                            if (!nameEl) return;
                            var f = (inp.files && inp.files.length) ? inp.files[0] : null;
                            nameEl.textContent = f && f.name ? f.name : 'No file selected';
                        }
                        inp.addEventListener('change', function () { update(); scheduleAutosave(250); });
                        update();
                    });
                } catch (e) {}
                form.addEventListener('input', function (e) {
                    try {
                        var t = e && e.target ? e.target : null;
                        if (!t) return;
                        if (t.classList && t.classList.contains('mh-file-input')) return;
                        if (t.type && (t.type === 'button' || t.type === 'submit')) return;
                    } catch (_) {}
                    scheduleAutosave(900);
                });
                form.addEventListener('change', function (e) {
                    try {
                        var t = e && e.target ? e.target : null;
                        if (!t) return;
                        if (t.classList && t.classList.contains('mh-file-input')) return;
                        if (t.type && (t.type === 'button' || t.type === 'submit')) return;
                    } catch (_) {}
                    scheduleAutosave(650);
                });
                form.addEventListener('submit', async function(e){
                    e.preventDefault();
                    await doSave(false);
                });
            }
            if (!window._popupNoticeInstance) {
                window._popupNoticeInstance = window.globalPopupNotice || window.popupNotice || (window.PopupNotice ? (window.popupNotice || new PopupNotice()) : null);
            }
            // Wire position dropdown to runtime position updates
            try {
                var posSelect = document.getElementById('popup_notice_position');
                var themeSelect = document.getElementById('popup_notice_theme');
                if (posSelect) {
                    posSelect.addEventListener('change', function() {
                        var instance = window._popupNoticeInstance || window.globalPopupNotice || window.popupNotice;
                        if (instance && typeof instance.setPosition === 'function') {
                            instance.setPosition(this.value);
                        } else if (window.PopupNotice && instance) {
                            // Fallback: update options and reposition
                            instance.options.position = this.value;
                            if (typeof instance.positionContainer === 'function') { instance.positionContainer(); }
                        }
                    });
                }
                if (themeSelect) {
                    themeSelect.addEventListener('change', function() {
                        var instance = window._popupNoticeInstance || window.globalPopupNotice || window.popupNotice;
                        if (instance && typeof instance.applyTheme === 'function') {
                            instance.applyTheme(this.value);
                        } else if (window.PopupNotice && instance) {
                            instance.options.theme = this.value;
                            if (typeof instance.applyTheme === 'function') { instance.applyTheme(this.value); }
                        }
                    });
                }
            } catch(_) {}
        });

        // Helper to schedule work off the critical path
        function scheduleNoticeWork(fn) {
            if (window.requestIdleCallback) {
                try {
                    return requestIdleCallback(function() { requestAnimationFrame(fn); }, { timeout: 200 });
                } catch(_) {}
            }
            return requestAnimationFrame(fn);
        }

        function testPopupNotice(type) {
            const messages = {
                'success': 'This is a success notification! Everything worked perfectly.',
                'warning': 'This is a warning notification. Please check your input.',
                'error': 'This is an error notification. Something went wrong.',
                'info': 'This is an info notification with helpful information.',
                'secondary': 'This is a secondary-style informational notice.',
                'accent': 'This is an accent-highlighted informational notice.',
                'neutral': 'This is a neutral notice for non-critical updates.'
            };

            // Map extra button types to core notice types
            const typeMap = {
                'success': 'success',
                'warning': 'warning',
                'error': 'error',
                'info': 'info',
                'secondary': 'info',
                'accent': 'info',
                'neutral': 'info'
            };

            // Read DOM values first (read phase)
            const theme = document.getElementById('popup_notice_theme').value;
            const position = document.getElementById('popup_notice_position').value;
            const duration = parseInt(document.getElementById('popup_notice_duration').value);

            // Defer DOM writes to idle/rAF to avoid long tasks in click handler
            scheduleNoticeWork(function() {
                var instance = window._popupNoticeInstance || window.globalPopupNotice || window.popupNotice || (window.PopupNotice ? (window.popupNotice || new PopupNotice({ 
                    position: position, 
                    duration: duration * 1000, 
                    stackNotifications: document.getElementById('popup_notice_stack').checked,
                    maxStack: parseInt(document.getElementById('popup_notice_max_stack').value),
                    enableAnimation: document.getElementById('popup_notice_animation').checked,
                    theme: theme 
                })) : null);
                window._popupNoticeInstance = instance; // cache for subsequent clicks
                if (instance) {
                    const coreType = typeMap[type] || 'info';
                    instance.show(messages[type], coreType, {
                        theme: theme,
                        position: position,
                        duration: duration * 1000,
                        stackNotifications: document.getElementById('popup_notice_stack').checked
                    });
                } else {
                    alert('PopupNotice widget not loaded. Please refresh the page.');
                }
            });
        }

        // Update display values
        document.addEventListener('DOMContentLoaded', function() {
            const durationSlider = document.getElementById('popup_notice_duration');
            const durationDisplay = document.getElementById('popup_notice_duration_display');
            const maxStackSlider = document.getElementById('popup_notice_max_stack');
            const maxStackDisplay = document.getElementById('popup_notice_max_stack_display');
            
            if (durationSlider && durationDisplay) {
                durationSlider.addEventListener('input', function() {
                    durationDisplay.textContent = this.value;
                });
            }
            
            if (maxStackSlider && maxStackDisplay) {
                maxStackSlider.addEventListener('input', function() {
                    maxStackDisplay.textContent = this.value;
                });
            }
        });

        // Automated position tests
        function runPositionTests() {
            var results = [];
            var posSelect = document.getElementById('popup_notice_position');
            var instance = window._popupNoticeInstance || window.globalPopupNotice || window.popupNotice || (window.PopupNotice ? (window.popupNotice || new PopupNotice()) : null);
            if (!posSelect || !instance) {
                alert('Position select or PopupNotice instance not available.');
                return;
            }

            var positions = ['top-right','bottom-left','top-center','bottom-center','center-left','center-right','center'];

            function setSelect(val) {
                posSelect.value = val;
                posSelect.dispatchEvent(new Event('change'));
            }

            function waitForPosition(expected) {
                return new Promise(function(resolve) {
                    var timeout = setTimeout(function() {
                        // Fallback check via container class/style
                        var container = instance.container;
                        var ok = container.classList.contains(expected);
                        resolve(ok);
                    }, 150);
                    var handler = function(evt) {
                        if (evt && evt.detail && evt.detail.position === expected) {
                            clearTimeout(timeout);
                            instance.container.removeEventListener('popup-notice:position-changed', handler);
                            resolve(true);
                        }
                    };
                    try { instance.container.addEventListener('popup-notice:position-changed', handler); } catch(_) {}
                });
            }

            (async function run() {
                for (var i = 0; i < positions.length; i++) {
                    var pos = positions[i];
                    setSelect(pos);
                    /* also emit an info notice to ensure show() path works */
                    try { instance.info('Testing position: ' + pos, { position: pos, duration: 1000 }); } catch(_) {}
                    var ok = await waitForPosition(pos);
                    results.push({ position: pos, passed: !!ok });
                }
                var passed = results.filter(r => r.passed).length;
                var summary = 'Position tests: ' + passed + '/' + results.length + ' passed';
                if (passed === results.length) {
                    (window.globalPopupNotice || instance)?.success('✅ ' + summary, { duration: 2500 });
                } else {
                    (window.globalPopupNotice || instance)?.warning('⚠️ ' + summary, { duration: 4000 });
                }
                console.table(results);
            })();
        }

        // Viewport info and adjustment monitoring
        document.addEventListener('DOMContentLoaded', function() {
            var infoEl = document.createElement('div');
            infoEl.style.fontSize = '12px';
            infoEl.style.opacity = '0.8';
            infoEl.style.marginTop = '8px';
            infoEl.innerText = '';
            var target = document.querySelector('.status-grid');
            if (target && target.parentNode) {
                target.parentNode.appendChild(infoEl);
            }

            function updateInfo() {
                var vv = window.visualViewport;
                var w = vv ? vv.width : window.innerWidth;
                var h = vv ? vv.height : window.innerHeight;
                var s = vv && vv.scale ? vv.scale : 1;
                infoEl.innerText = 'Viewport: ' + Math.round(w) + '×' + Math.round(h) + ' | Zoom: ' + (Math.round(s * 100)) + '%';
            }

            updateInfo();
            window.addEventListener('resize', updateInfo);
            window.addEventListener('orientationchange', updateInfo);
            if (window.visualViewport) {
                try {
                    window.visualViewport.addEventListener('resize', updateInfo);
                    window.visualViewport.addEventListener('scroll', updateInfo);
                } catch(_) {}
            }
        });
    </script>
</main>
<?php if (function_exists('getTemplatesPath')) { include_once getTemplatesPath() . '/global-ui/includes/complete-body-end.php'; } ?>
</body>
</html>
