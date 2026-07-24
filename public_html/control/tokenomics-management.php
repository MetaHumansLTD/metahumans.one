<?php
require_once __DIR__ . '/../.cue/cue.php';
require_once __DIR__ . '/../auth/auth_functions.php';
require_once __DIR__ . '/../auth/tenant_provisioning.php';
require_once __DIR__ . '/../gear/calendar/calendar_helpers.php';

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

if (!isset($_SESSION['mh_auth_user']) || !is_string($_SESSION['mh_auth_user']) || $_SESSION['mh_auth_user'] === '') {
    header('Location: /auth/login.php');
    exit;
}

$role = isset($_SESSION['mh_auth_role']) ? (string)$_SESSION['mh_auth_role'] : '';
if (stripos($role, 'kripzmaster') === false) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$message = '';
$artifactMsg = '';
$artifactErr = '';
$billingUser = isset($_GET['billing_user']) ? trim((string)$_GET['billing_user']) : '';
$billingRoom = isset($_GET['billing_room']) ? trim((string)$_GET['billing_room']) : '';
$artifactAction = isset($_GET['artifact_action']) ? trim((string)$_GET['artifact_action']) : '';
$artifactUser = isset($_GET['u']) ? trim((string)$_GET['u']) : '';
$artifactRoom = isset($_GET['room']) ? trim((string)$_GET['room']) : '';
$artifactPath = isset($_GET['p']) ? (string)$_GET['p'] : '';
$requestUri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/control/tokenomics-management.php';
if ($requestUri === '' || $requestUri[0] !== '/') {
    $requestUri = '/control/tokenomics-management.php';
}
$flash = $_SESSION['mh_tokenomics_flash'] ?? null;
if (is_array($flash)) {
    $message = isset($flash['message']) && is_string($flash['message']) ? $flash['message'] : '';
    $artifactMsg = isset($flash['artifactMsg']) && is_string($flash['artifactMsg']) ? $flash['artifactMsg'] : '';
    $artifactErr = isset($flash['artifactErr']) && is_string($flash['artifactErr']) ? $flash['artifactErr'] : '';
}
unset($_SESSION['mh_tokenomics_flash']);

function mh_control_meet_csrf_get(): string
{
    $k = $_SESSION['mh_control_meet_csrf'] ?? '';
    if (!is_string($k) || $k === '') {
        $k = bin2hex(random_bytes(16));
        $_SESSION['mh_control_meet_csrf'] = $k;
    }
    return $k;
}

function mh_control_meet_csrf_check(string $posted): bool
{
    $k = $_SESSION['mh_control_meet_csrf'] ?? '';
    return is_string($k) && $k !== '' && hash_equals($k, $posted);
}

function mh_control_custom_notices_paths(): array
{
    $dataBase = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $dataBase = $dataBase !== '' ? rtrim($dataBase, '/') : '/data';
    $file = $dataBase . '/widgets/notices/custom-notices.json';
    try {
        if (function_exists('cue_autoload')) {
            $paths = cue_autoload('paths');
            if ($paths) {
                $dataBase = (string)$paths->getDataPath();
                $dataBase = $dataBase !== '' ? rtrim($dataBase, DIRECTORY_SEPARATOR) : '/data';
                $fileCandidate = $dataBase . DIRECTORY_SEPARATOR . 'widgets' . DIRECTORY_SEPARATOR . 'notices' . DIRECTORY_SEPARATOR . 'custom-notices.json';
                $safe = $paths->validateSecurePath($fileCandidate, $dataBase);
                if (is_string($safe) && $safe !== '') $file = $safe;
            }
        }
    } catch (Throwable) {}
    return [$dataBase, $file];
}

function mh_control_custom_notices_read(string $filePath): array
{
    if (!is_file($filePath) || !is_readable($filePath)) return [];
    $raw = @file_get_contents($filePath);
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function mh_control_custom_notices_write(string $filePath, array $cfg): bool
{
    $dir = dirname($filePath);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) return false;
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) return false;
    return @file_put_contents($filePath, $json . "\n") !== false;
}

function mh_control_tokenomics_secrets_path(bool $createDir = false): string
{
    $paths = function_exists('cue_autoload') ? cue_autoload('paths') : null;
    $p = $paths && method_exists($paths, 'getSecureFilePath') ? $paths->getSecureFilePath('config/tokenomics-secrets.json', $createDir) : null;
    if (is_string($p) && $p !== '') {
        return $p;
    }
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $full = $base . '/config/tokenomics-secrets.json';
    if ($createDir) {
        $dir = dirname($full);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }
    return $full;
}

function mh_control_tokenomics_enc_key(): string
{
    if (function_exists('cue_autoload')) {
        cue_autoload('paths');
        cue_autoload('security');
    }
    $keyPath = function_exists('paths_getEncryptionKeyPath') ? (string)paths_getEncryptionKeyPath() : '/data/security/app.key';
    $raw = is_file($keyPath) ? @file_get_contents($keyPath) : false;
    return is_string($raw) ? trim($raw) : '';
}

function mh_control_tokenomics_encrypt(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') return '';
    $key = mh_control_tokenomics_enc_key();
    if ($key === '' || !function_exists('security_encryptValue')) return '';
    $enc = security_encryptValue($plain, $key);
    return is_string($enc) ? $enc : '';
}

function mh_control_tokenomics_decrypt(string $enc): string
{
    $enc = trim($enc);
    if ($enc === '') return '';
    $key = mh_control_tokenomics_enc_key();
    if ($key === '' || !function_exists('security_decryptValue')) return '';
    $plain = security_decryptValue($enc, $key);
    return is_string($plain) ? $plain : '';
}

function mh_control_tokenomics_secrets_load(): array
{
    $p = mh_control_tokenomics_secrets_path(false);
    if (!is_file($p) || !is_readable($p)) return [];
    $raw = @file_get_contents($p);
    $decoded = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function mh_control_tokenomics_secrets_write(array $cfg): void
{
    $p = mh_control_tokenomics_secrets_path(true);
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json) || $json === '') {
        throw new RuntimeException('secrets_encode_failed');
    }
    if (@file_put_contents($p, $json) === false) {
        throw new RuntimeException('secrets_write_failed');
    }
    @chmod($p, 0600);
}

function mh_control_tokenomics_secrets_status(): array
{
    $cfg = mh_control_tokenomics_secrets_load();
    $out = [
        'stripe' => ['set' => false, 'suffix' => ''],
        'brex_token' => ['set' => false, 'suffix' => ''],
        'brex_cash' => ['set' => false, 'suffix' => ''],
        'brex_bank_details' => ['set' => false, 'suffix' => ''],
    ];
    $stripeEnc = isset($cfg['stripe_secret_key']) && is_string($cfg['stripe_secret_key']) ? trim((string)$cfg['stripe_secret_key']) : '';
    if ($stripeEnc !== '') {
        $plain = mh_control_tokenomics_decrypt($stripeEnc);
        $out['stripe']['set'] = $plain !== '';
        $out['stripe']['suffix'] = $plain !== '' ? substr($plain, -4) : '';
    }
    $brexEnc = isset($cfg['brex_access_token']) && is_string($cfg['brex_access_token']) ? trim((string)$cfg['brex_access_token']) : '';
    if ($brexEnc !== '') {
        $plain = mh_control_tokenomics_decrypt($brexEnc);
        $out['brex_token']['set'] = $plain !== '';
        $out['brex_token']['suffix'] = $plain !== '' ? substr($plain, -4) : '';
    }
    $cashEnc = isset($cfg['brex_cash_account_id']) && is_string($cfg['brex_cash_account_id']) ? trim((string)$cfg['brex_cash_account_id']) : '';
    if ($cashEnc !== '') {
        $plain = mh_control_tokenomics_decrypt($cashEnc);
        $out['brex_cash']['set'] = $plain !== '';
        $out['brex_cash']['suffix'] = $plain !== '' ? substr($plain, -4) : '';
    }
    $bankEnc = isset($cfg['brex_bank_details']) && is_string($cfg['brex_bank_details']) ? trim((string)$cfg['brex_bank_details']) : '';
    if ($bankEnc !== '') {
        $plain = mh_control_tokenomics_decrypt($bankEnc);
        $out['brex_bank_details']['set'] = $plain !== '';
        $out['brex_bank_details']['suffix'] = $plain !== '' ? substr(sha1($plain), 0, 6) : '';
    }
    return $out;
}

function mh_control_tokenomics_secret_plain(string $key): string
{
    $cfg = mh_control_tokenomics_secrets_load();
    if (!is_array($cfg)) return '';
    $enc = isset($cfg[$key]) && is_string($cfg[$key]) ? trim((string)$cfg[$key]) : '';
    if ($enc === '') return '';
    $plain = mh_control_tokenomics_decrypt($enc);
    return is_string($plain) ? trim((string)$plain) : '';
}

function mh_control_meeting_db(string $username): ?PDO
{
    $username = trim($username);
    if ($username === '' || !function_exists('mh_resolve_tenant_db_config_id') || !function_exists('database_getConnectionById')) {
        return null;
    }
    $tenantId = 'user:' . $username;
    $dbId = mh_resolve_tenant_db_config_id($tenantId);
    if (!is_string($dbId) || $dbId === '') {
        return null;
    }
    $db = database_getConnectionById($dbId);
    return $db instanceof PDO ? $db : null;
}

function mh_control_delete_pending_meeting_tokens(string $username, string $roomId = ''): array
{
    $db = mh_control_meeting_db($username);
    if (!$db instanceof PDO) {
        return ['ok' => false, 'error' => 'Tenant database not found for user.'];
    }

    calendar_ensure_tables($db);
    $roomId = trim($roomId);
    $params = [':user' => $username];
    $sql = "
        DELETE FROM mh_meetings
        WHERE created_by_user = :user
          AND token_charge_status = 'pending'
    ";
    if ($roomId !== '') {
        $sql .= " AND room_id = :room";
        $params[':room'] = $roomId;
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return [
        'ok' => true,
        'count' => (int)$stmt->rowCount(),
    ];
}

function mh_control_tokenomics_http_status(string $method, string $url, array $headers = []): int
{
    $method = strtoupper(trim($method));
    if (!in_array($method, ['GET', 'POST', 'HEAD'], true)) $method = 'GET';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    }
    if ($method !== 'GET' && $method !== 'HEAD') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $ch = null;
    if (is_string($err) && $err !== '') return 0;
    return $code;
}

function mh_control_tokenomics_api_status_label(string $state): string
{
    $state = strtolower(trim($state));
    if ($state === 'connected') return 'Connected';
    if ($state === 'disconnected') return 'Disconnected';
    return 'API not configured';
}

function mh_control_tokenomics_api_status_color(string $state): string
{
    $state = strtolower(trim($state));
    if ($state === 'connected') return 'rgba(120,255,180,.95)';
    if ($state === 'disconnected') return 'rgba(255,180,180,.95)';
    return '#9aa';
}

function mh_control_tokenomics_stripe_health(): string
{
    $key = mh_control_tokenomics_secret_plain('stripe_secret_key');
    if ($key === '') return 'not_configured';
    $code = mh_control_tokenomics_http_status('GET', 'https://api.stripe.com/v1/account', [
        'Authorization: Bearer ' . $key,
    ]);
    return ($code >= 200 && $code < 300) ? 'connected' : 'disconnected';
}

function mh_control_tokenomics_brex_health(): string
{
    $token = mh_control_tokenomics_secret_plain('brex_access_token');
    $cash = mh_control_tokenomics_secret_plain('brex_cash_account_id');
    if ($token === '' || $cash === '') return 'not_configured';
    $url = 'https://api.brex.com/v2/transactions/cash/' . rawurlencode($cash) . '?' . http_build_query(['limit' => 1]);
    $code = mh_control_tokenomics_http_status('GET', $url, [
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ]);
    return ($code >= 200 && $code < 300) ? 'connected' : 'disconnected';
}

function mh_control_realpath_or_empty(string $p): string
{
    $r = realpath($p);
    return is_string($r) ? $r : '';
}

function mh_control_path_is_within(string $path, string $root): bool
{
    $rp = mh_control_realpath_or_empty($path);
    $rr = mh_control_realpath_or_empty($root);
    if ($rp === '' || $rr === '') return false;
    $rr = rtrim($rr, '/') . '/';
    return strpos($rp . (is_dir($rp) ? '/' : ''), $rr) === 0;
}

function mh_control_send_file(string $absPath, string $downloadName): void
{
    if (!is_file($absPath)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not Found';
        exit;
    }
    $size = filesize($absPath);
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (is_int($size) ? $size : 0));
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $downloadName) . '"');
    header('X-Content-Type-Options: nosniff');
    readfile($absPath);
    exit;
}

function mh_control_normalize_datetime_input(string $value, ?string $defaultIfBlank = null): ?string
{
    $value = trim($value);
    if ($value === '') {
        return $defaultIfBlank;
    }
    $value = str_replace('T', ' ', $value);
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', (int)$ts);
}

function mh_control_format_datetime_local(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d\TH:i:s', (int)$ts);
}

function mh_control_format_date_input(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }
    return date('Y-m-d', (int)$ts);
}

function mh_control_sync_culture_coin_window_dates(PDO $pdo, int $coinId, string $effectiveFrom, ?string $effectiveTo): void
{
    if ($coinId < 1) {
        return;
    }
    $stmt = $pdo->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? AND asset_type = 'culture' LIMIT 1");
    $stmt->execute([$coinId]);
    $raw = $stmt->fetchColumn();
    if ($raw === false) {
        return;
    }
    $params = [];
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $params = $decoded;
        }
    }
    $fromTs = strtotime($effectiveFrom);
    if ($fromTs !== false) {
        $params['issue_date'] = date('Y-m-d', (int)$fromTs);
    }
    $effectiveTo = is_string($effectiveTo) ? trim($effectiveTo) : '';
    $toTs = $effectiveTo !== '' ? strtotime($effectiveTo) : false;
    if ($toTs !== false) {
        $params['close_date'] = date('Y-m-d', (int)$toTs);
    } else {
        unset($params['close_date']);
    }
    $paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES);
    if (!is_string($paramsJson)) {
        return;
    }
    $pdo->prepare("UPDATE mh_asset_classes SET pricing_params_json = ? WHERE id = ? AND asset_type = 'culture'")
        ->execute([$paramsJson, $coinId]);
}

function mh_control_tenant_safe(string $username): string
{
    $tenantId = 'user:' . $username;
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if ($safe !== '') return $safe;
    }
    return preg_replace('/[^a-zA-Z0-9:_-]+/', '_', $tenantId);
}
$commonServices = [
    ['key' => 'equity:market:sell_coin', 'label' => 'Equity: Sell coin', 'unit' => 'action'],
    ['key' => 'equity:market:sell_share', 'label' => 'Equity: Sell share', 'unit' => 'action'],
    ['key' => 'equity:market:buy_coin', 'label' => 'Equity: Buy coin', 'unit' => 'action'],
    ['key' => 'equity:market:buy_share', 'label' => 'Equity: Buy share', 'unit' => 'action'],
    ['key' => 'equity:market:remove_listing', 'label' => 'Equity: Remove listing', 'unit' => 'action'],
    ['key' => 'equity:preference:convert', 'label' => 'Equity: Convert preference', 'unit' => 'action'],
    ['key' => 'equity:primary:order_create', 'label' => 'Equity: Capital raise order', 'unit' => 'order'],
    ['key' => 'equity:primary:settle', 'label' => 'Equity: Capital raise settlement', 'unit' => 'settlement'],
    ['key' => 'benefactors:min_tokens', 'label' => 'Benefactors: Minimum MTK eligibility', 'unit' => 'tokens'],
    ['key' => 'benefactors:transfer', 'label' => 'Benefactors: Transfer execution fee', 'unit' => 'claim'],
    ['key' => 'pdf_tools:merge', 'label' => 'PDF Tools: Merge', 'unit' => 'document'],
    ['key' => 'hub_ide:chat', 'label' => 'Hub IDE: Chat', 'unit' => 'message'],
    ['key' => 'hub_ide:job_create', 'label' => 'Hub IDE: Create job', 'unit' => 'job'],
    ['key' => 'meet:meeting', 'label' => 'Meet: Meeting (billed after 5 minutes)', 'unit' => 'meeting'],
];
$commonTriggers = [
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'sell', 'service_key' => 'equity:market:sell_coin'],
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'buy', 'service_key' => 'equity:market:buy_coin'],
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'remove_listing', 'service_key' => 'equity:market:remove_listing'],
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'convert_preference', 'service_key' => 'equity:preference:convert'],
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'primary_order', 'service_key' => 'equity:primary:order_create'],
    ['method' => 'POST', 'path' => '/hub/equity/manage.php', 'selector_type' => 'post_action', 'selector_value' => 'brex_check_primary_order', 'service_key' => 'equity:primary:settle'],
    ['method' => 'POST', 'path' => '/hub/genesis/persona_create.php', 'selector_type' => 'path_only', 'selector_value' => '*', 'service_key' => 'genesis:persona_create'],
];

try {
    require_once __DIR__ . '/../hub/equity/db.php';
    require_once __DIR__ . '/../.cue/tokenization.php';
    $pdo = function_exists('getEquityConnectionStrict') ? getEquityConnectionStrict() : getEquityConnection();
    if (function_exists('mh_tokenomics_ensure_schema')) {
        mh_tokenomics_ensure_schema($pdo);
        mh_tokenomics_seed_utility_token($pdo);
    }
    if (function_exists('tokenization_ensure_schema')) {
        tokenization_ensure_schema($pdo);
    }

    $shouldRedirectAfterPost = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
        $shouldRedirectAfterPost = ($action !== '');

        if ($action === 'save_service') {
            $serviceKey = isset($_POST['service_key']) ? trim((string)$_POST['service_key']) : '';
            $tpu = isset($_POST['tokens_per_unit']) ? (int)$_POST['tokens_per_unit'] : 1;
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $unitName = isset($_POST['unit_name']) ? trim((string)$_POST['unit_name']) : 'unit';

            if ($serviceKey === '' || $tpu < 0) {
                $message = 'Invalid service pricing.';
            } else {
                $stmt = $pdo->prepare("INSERT INTO mh_service_pricing (service_key, tokens_per_unit, unit_name, enabled, effective_from, effective_to) VALUES (?, ?, ?, ?, NOW(), NULL) ON DUPLICATE KEY UPDATE tokens_per_unit = VALUES(tokens_per_unit), unit_name = VALUES(unit_name), enabled = VALUES(enabled), updated_at = CURRENT_TIMESTAMP");
                $stmt->execute([$serviceKey, $tpu, $unitName !== '' ? $unitName : 'unit', $enabled]);
                $message = 'Service pricing saved.';
            }
        } elseif ($action === 'save_trigger') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $method = isset($_POST['http_method']) ? strtoupper(trim((string)$_POST['http_method'])) : 'POST';
            $path = isset($_POST['path_pattern']) ? trim((string)$_POST['path_pattern']) : '';
            $selectorType = isset($_POST['selector_type']) ? trim((string)$_POST['selector_type']) : 'post_action';
            $selectorKey = isset($_POST['selector_key']) ? trim((string)$_POST['selector_key']) : 'action';
            $selectorValue = isset($_POST['selector_value']) ? trim((string)$_POST['selector_value']) : '';
            $serviceKey = isset($_POST['service_key']) ? trim((string)$_POST['service_key']) : '';
            $unitsMode = isset($_POST['units_mode']) ? trim((string)$_POST['units_mode']) : 'fixed';
            $unitsValue = isset($_POST['units_value']) ? (int)$_POST['units_value'] : 1;
            $priority = isset($_POST['priority']) ? (int)$_POST['priority'] : 100;

            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) $method = 'POST';
            if (!in_array($selectorType, ['post_action', 'path_only'], true)) $selectorType = 'post_action';
            if (!in_array($unitsMode, ['fixed', 'post_int'], true)) $unitsMode = 'fixed';
            if ($selectorType === 'path_only') {
                $selectorKey = '';
                if ($selectorValue === '') $selectorValue = '*';
            } else {
                if ($selectorKey === '') $selectorKey = 'action';
            }
            $unitsValue = max(1, $unitsValue);
            $priority = max(1, min(9999, $priority));

            if ($path === '' || $path[0] !== '/' || $serviceKey === '' || ($selectorType === 'post_action' && $selectorValue === '')) {
                $message = 'Invalid trigger config.';
            } else {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE mh_service_triggers SET enabled=?, http_method=?, path_pattern=?, selector_type=?, selector_key=?, selector_value=?, service_key=?, units_mode=?, units_value=?, priority=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
                    $stmt->execute([$enabled, $method, $path, $selectorType, $selectorKey, $selectorValue, $serviceKey, $unitsMode, $unitsValue, $priority, $id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO mh_service_triggers (enabled, http_method, path_pattern, selector_type, selector_key, selector_value, service_key, units_mode, units_value, priority) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$enabled, $method, $path, $selectorType, $selectorKey, $selectorValue, $serviceKey, $unitsMode, $unitsValue, $priority]);
                }
                $message = 'Trigger saved.';
            }
        } elseif ($action === 'delete_trigger') {
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            if ($id < 1) {
                $message = 'Invalid trigger id.';
            } else {
                $stmt = $pdo->prepare("DELETE FROM mh_service_triggers WHERE id = ?");
                $stmt->execute([$id]);
                $message = 'Trigger deleted.';
            }
        } elseif ($action === 'import_triggers') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                $raw = isset($_POST['triggers_json']) ? (string)$_POST['triggers_json'] : '';
                $raw = trim($raw);
                $replaceAll = isset($_POST['replace_all']) ? 1 : 0;
                $decoded = $raw !== '' ? json_decode($raw, true) : null;
                if (!is_array($decoded)) {
                    $message = 'Invalid JSON.';
                } else {
                    $rows = array_values($decoded);
                    $pdo->beginTransaction();
                    try {
                        if ($replaceAll) {
                            $pdo->exec("DELETE FROM mh_service_triggers");
                        }
                        $ins = $pdo->prepare("INSERT INTO mh_service_triggers (enabled, http_method, path_pattern, selector_type, selector_key, selector_value, service_key, units_mode, units_value, priority)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), service_key=VALUES(service_key), units_mode=VALUES(units_mode), units_value=VALUES(units_value), priority=VALUES(priority), updated_at=CURRENT_TIMESTAMP");
                        $count = 0;
                        foreach ($rows as $r) {
                            if (!is_array($r)) continue;
                            $enabled = isset($r['enabled']) ? (int)$r['enabled'] : 1;
                            $enabled = $enabled ? 1 : 0;
                            $method = isset($r['http_method']) ? strtoupper(trim((string)$r['http_method'])) : 'POST';
                            if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) $method = 'POST';
                            $path = isset($r['path_pattern']) ? trim((string)$r['path_pattern']) : '';
                            if ($path === '' || $path[0] !== '/') continue;
                            $selectorType = isset($r['selector_type']) ? trim((string)$r['selector_type']) : 'post_action';
                            if (!in_array($selectorType, ['post_action', 'path_only'], true)) $selectorType = 'post_action';
                            $selectorKey = isset($r['selector_key']) ? trim((string)$r['selector_key']) : 'action';
                            $selectorValue = isset($r['selector_value']) ? trim((string)$r['selector_value']) : '';
                            if ($selectorType === 'path_only') {
                                $selectorKey = '';
                                if ($selectorValue === '') $selectorValue = '*';
                            } else {
                                if ($selectorKey === '') $selectorKey = 'action';
                                if ($selectorValue === '') continue;
                            }
                            $serviceKey = isset($r['service_key']) ? trim((string)$r['service_key']) : '';
                            if ($serviceKey === '') continue;
                            $unitsMode = isset($r['units_mode']) ? trim((string)$r['units_mode']) : 'fixed';
                            if (!in_array($unitsMode, ['fixed', 'post_int'], true)) $unitsMode = 'fixed';
                            $unitsValue = isset($r['units_value']) ? (int)$r['units_value'] : 1;
                            $unitsValue = max(1, $unitsValue);
                            $priority = isset($r['priority']) ? (int)$r['priority'] : 100;
                            $priority = max(1, min(9999, $priority));
                            $ins->execute([$enabled, $method, $path, $selectorType, $selectorKey, $selectorValue, $serviceKey, $unitsMode, $unitsValue, $priority]);
                            $count++;
                        }
                        $pdo->commit();
                        $message = 'Triggers imported: ' . (int)$count . '.';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        $message = 'Import failed: ' . $e->getMessage();
                    }
                }
            }
        } elseif ($action === 'set_token_price') {
            $price = isset($_POST['price_usd_per_token']) ? (float)$_POST['price_usd_per_token'] : 0.0;
            if ($price <= 0) {
                $message = 'Invalid token price.';
            } else {
                $utilityId = (int)$pdo->query("SELECT id FROM mh_asset_classes WHERE asset_key = 'utility:meta' LIMIT 1")->fetchColumn();
                if ($utilityId < 1) {
                    $message = 'Utility token class not found.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE mh_asset_pricing_rules SET effective_to = NOW() WHERE asset_class_id = ? AND (effective_to IS NULL OR effective_to > NOW())")->execute([$utilityId]);
                        $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, NOW(), NULL)")->execute([$utilityId, $price]);
                        $pdo->commit();
                        $message = 'Token price updated.';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
            }
        } elseif ($action === 'set_token_bonus_scale') {
            $utilityId = (int)$pdo->query("SELECT id FROM mh_asset_classes WHERE asset_key = 'utility:meta' LIMIT 1")->fetchColumn();
            if ($utilityId < 1) {
                $message = 'Utility token class not found.';
            } else {
                $startUsd = isset($_POST['bonus_start_usd']) ? (int)$_POST['bonus_start_usd'] : 100;
                $basePct = isset($_POST['bonus_base_pct']) ? (float)$_POST['bonus_base_pct'] : 5.0;
                $stepUsd = isset($_POST['bonus_step_usd']) ? (int)$_POST['bonus_step_usd'] : 50;
                $stepPct = isset($_POST['bonus_step_pct']) ? (float)$_POST['bonus_step_pct'] : 1.0;

                $startUsd = max(1, $startUsd);
                $stepUsd = max(1, $stepUsd);
                $basePct = max(0.0, min(100.0, $basePct));
                $stepPct = max(0.0, min(100.0, $stepPct));
                $stmt = $pdo->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$utilityId]);
                $raw = $stmt->fetchColumn();
                $params = [];
                if ($raw !== false && is_string($raw) && trim($raw) !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        $params = $decoded;
                    }
                }
                $params['bonus_scale'] = [
                    'start_usd' => $startUsd,
                    'base_bonus_pct' => $basePct,
                    'step_usd' => $stepUsd,
                    'step_bonus_pct' => $stepPct,
                ];
                $json = json_encode($params, JSON_UNESCAPED_SLASHES);
                if (!is_string($json) || $json === '') {
                    $message = 'Failed to encode bonus scale.';
                } else {
                    $pdo->prepare("UPDATE mh_asset_classes SET pricing_params_json = ? WHERE id = ?")->execute([$json, $utilityId]);
                    $message = 'Token bonus scale updated.';
                }
            }
        } elseif ($action === 'save_culture_coin_meta') {
            $coinId = isset($_POST['coin_id']) ? (int)$_POST['coin_id'] : 0;
            $assetKey = isset($_POST['asset_key']) ? trim((string)$_POST['asset_key']) : '';
            $displayName = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';
            $ticker = isset($_POST['ticker']) ? trim((string)$_POST['ticker']) : '';
            $supplyCap = isset($_POST['supply_cap']) ? (int)$_POST['supply_cap'] : 0;
            $decimals = isset($_POST['decimals']) ? (int)$_POST['decimals'] : 0;
            $issueDate = isset($_POST['issue_date']) ? trim((string)$_POST['issue_date']) : '';
            $closeDate = isset($_POST['close_date']) ? trim((string)$_POST['close_date']) : '';

            if ($coinId < 1 || $assetKey === '' || !str_starts_with($assetKey, 'culture:')) {
                $message = 'Invalid culture coin.';
            } else {
                $stmt = $pdo->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? AND asset_key = ? LIMIT 1");
                $stmt->execute([$coinId, $assetKey]);
                $raw = $stmt->fetchColumn();
                if ($raw === false) {
                    $message = 'Culture coin not found.';
                } else {
                    $params = [];
                    if (is_string($raw) && trim($raw) !== '') {
                        $decoded = json_decode($raw, true);
                        if (is_array($decoded)) {
                            $params = $decoded;
                        }
                    }
                    if ($ticker !== '') {
                        $params['ticker'] = $ticker;
                    } else {
                        unset($params['ticker']);
                    }
                    if ($supplyCap > 0) {
                        $params['supply_cap'] = $supplyCap;
                    } else {
                        unset($params['supply_cap']);
                    }
                    if ($issueDate !== '') {
                        $params['issue_date'] = $issueDate;
                    } else {
                        unset($params['issue_date']);
                    }
                    if ($closeDate !== '') {
                        $params['close_date'] = $closeDate;
                    } else {
                        unset($params['close_date']);
                    }
                    $paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES);
                    if (!is_string($paramsJson)) {
                        $paramsJson = null;
                    }
                    $decimals = max(0, min(18, $decimals));
                    $displayName = $displayName !== '' ? $displayName : $assetKey;
                    $pdo->prepare("UPDATE mh_asset_classes SET display_name = ?, decimals = ?, pricing_params_json = ? WHERE id = ? AND asset_key = ?")
                        ->execute([$displayName, $decimals, $paramsJson, $coinId, $assetKey]);
                    $message = 'Culture coin details updated.';
                }
            }
        } elseif ($action === 'save_culture_coin_rule') {
            $coinId = isset($_POST['coin_id']) ? (int)$_POST['coin_id'] : 0;
            $priceRuleId = isset($_POST['price_rule_id']) ? (int)$_POST['price_rule_id'] : 0;
            $price = isset($_POST['price_usd_per_unit']) ? (float)$_POST['price_usd_per_unit'] : 0.0;
            $priceFrom = isset($_POST['price_effective_from']) ? trim((string)$_POST['price_effective_from']) : '';
            $priceTo = isset($_POST['price_effective_to']) ? trim((string)$_POST['price_effective_to']) : '';

            if ($coinId < 1) {
                $message = 'Invalid culture coin.';
            } elseif ($price <= 0) {
                $message = 'Price must be greater than zero.';
            } else {
                $effFrom = mh_control_normalize_datetime_input($priceFrom, null);
                $effTo = mh_control_normalize_datetime_input($priceTo, null);
                if ($effFrom === null) {
                    $message = 'Price effective-from date is invalid.';
                } elseif ($priceTo !== '' && $effTo === null) {
                    $message = 'Price effective-to date is invalid.';
                } elseif ($effTo !== null && strtotime($effTo) <= strtotime($effFrom)) {
                    $message = 'Price end must be later than the start date.';
                } else {
                    $nowTs = time();
                    $fromTs = strtotime($effFrom);
                    $toTs = $effTo !== null ? strtotime($effTo) : false;
                    if ($priceRuleId > 0) {
                        $stmt = $pdo->prepare("UPDATE mh_asset_pricing_rules SET price_usd_per_unit = ?, effective_from = ?, effective_to = ? WHERE id = ? AND asset_class_id = ?");
                        $stmt->execute([$price, $effFrom, $effTo, $priceRuleId, $coinId]);
                        $message = 'Pricing window updated.';
                    } else {
                        $stmt = $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, ?, ?)");
                        $stmt->execute([$coinId, $price, $effFrom, $effTo]);
                        $message = 'Pricing window added.';
                    }
                    if ($fromTs !== false && $fromTs <= $nowTs && ($toTs === false || $toTs > $nowTs)) {
                        mh_control_sync_culture_coin_window_dates($pdo, $coinId, $effFrom, $effTo);
                    }
                }
            }
        } elseif ($action === 'delete_culture_coin_rule') {
            $coinId = isset($_POST['coin_id']) ? (int)$_POST['coin_id'] : 0;
            $priceRuleId = isset($_POST['price_rule_id']) ? (int)$_POST['price_rule_id'] : 0;
            if ($coinId < 1 || $priceRuleId < 1) {
                $message = 'Invalid pricing window.';
            } else {
                $pdo->prepare("DELETE FROM mh_asset_pricing_rules WHERE id = ? AND asset_class_id = ?")->execute([$priceRuleId, $coinId]);
                $message = 'Pricing window deleted.';
            }
        } elseif ($action === 'save_culture_coin') {
            $coinId = isset($_POST['coin_id']) ? (int)$_POST['coin_id'] : 0;
            $priceRuleId = isset($_POST['price_rule_id']) ? (int)$_POST['price_rule_id'] : 0;
            $assetKey = isset($_POST['asset_key']) ? trim((string)$_POST['asset_key']) : '';
            $displayName = isset($_POST['display_name']) ? trim((string)$_POST['display_name']) : '';
            $ticker = isset($_POST['ticker']) ? trim((string)$_POST['ticker']) : '';
            $supplyCap = isset($_POST['supply_cap']) ? (int)$_POST['supply_cap'] : 0;
            $decimals = isset($_POST['decimals']) ? (int)$_POST['decimals'] : 0;
            $issueDate = isset($_POST['issue_date']) ? trim((string)$_POST['issue_date']) : '';
            $closeDate = isset($_POST['close_date']) ? trim((string)$_POST['close_date']) : '';
            $price = isset($_POST['price_usd_per_unit']) ? (float)$_POST['price_usd_per_unit'] : 0.0;
            $priceFrom = isset($_POST['price_effective_from']) ? trim((string)$_POST['price_effective_from']) : '';
            $priceTo = isset($_POST['price_effective_to']) ? trim((string)$_POST['price_effective_to']) : '';

            if ($assetKey === '' && $coinId > 0) {
                $stmt = $pdo->prepare("SELECT asset_key FROM mh_asset_classes WHERE id = ? LIMIT 1");
                $stmt->execute([$coinId]);
                $assetKey = trim((string)($stmt->fetchColumn() ?: ''));
            }
            if ($assetKey === '' || !str_starts_with($assetKey, 'culture:')) {
                $message = 'Invalid asset key (must start with culture:).';
            } else {
                $pdo->beginTransaction();
                try {
                    $existingId = 0;
                    $existingParams = [];
                    $stmt = $pdo->prepare("SELECT id, pricing_params_json FROM mh_asset_classes WHERE asset_key = ? LIMIT 1");
                    $stmt->execute([$assetKey]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (is_array($row) && !empty($row)) {
                        $existingId = (int)($row['id'] ?? 0);
                        $raw = isset($row['pricing_params_json']) ? trim((string)$row['pricing_params_json']) : '';
                        $decoded = $raw !== '' ? json_decode($raw, true) : null;
                        if (is_array($decoded)) $existingParams = $decoded;
                    }

                    $coinId = $coinId > 0 ? $coinId : $existingId;
                    if ($coinId < 1 && $existingId > 0) $coinId = $existingId;

                    $params = is_array($existingParams) ? $existingParams : [];
                    if ($ticker !== '') $params['ticker'] = $ticker;
                    else unset($params['ticker']);
                    if ($supplyCap > 0) $params['supply_cap'] = $supplyCap;
                    else unset($params['supply_cap']);
                    if ($issueDate !== '') $params['issue_date'] = $issueDate;
                    else unset($params['issue_date']);
                    if ($closeDate !== '') $params['close_date'] = $closeDate;
                    else unset($params['close_date']);

                    $paramsJson = json_encode($params, JSON_UNESCAPED_SLASHES);
                    if (!is_string($paramsJson)) $paramsJson = null;

                    $decimals = max(0, min(18, $decimals));
                    $displayName = $displayName !== '' ? $displayName : $assetKey;

                    if ($coinId > 0) {
                        $upd = $pdo->prepare("UPDATE mh_asset_classes SET asset_type = 'culture', display_name = ?, decimals = ?, pricing_strategy = 'fixed', pricing_params_json = ? WHERE id = ?");
                        $upd->execute([$displayName, $decimals, $paramsJson, $coinId]);
                    } else {
                        $ins = $pdo->prepare("INSERT INTO mh_asset_classes (asset_key, asset_type, display_name, decimals, pricing_strategy, pricing_params_json) VALUES (?, 'culture', ?, ?, 'fixed', ?)");
                        $ins->execute([$assetKey, $displayName, $decimals, $paramsJson]);
                        $coinId = (int)$pdo->lastInsertId();
                    }

                    if ($coinId > 0 && $price > 0) {
                        $effFrom = mh_control_normalize_datetime_input($priceFrom, date('Y-m-d H:i:s'));
                        $effTo = mh_control_normalize_datetime_input($priceTo, null);
                        if ($effFrom === null || ($priceTo !== '' && $effTo === null)) {
                            throw new RuntimeException('invalid_price_schedule');
                        }
                        if ($effTo !== null && strtotime($effTo) <= strtotime($effFrom)) {
                            throw new RuntimeException('price_window_invalid');
                        }

                        $resolvedPriceRuleId = 0;
                        if ($priceRuleId > 0) {
                            $ruleStmt = $pdo->prepare("SELECT id FROM mh_asset_pricing_rules WHERE id = ? AND asset_class_id = ? LIMIT 1");
                            $ruleStmt->execute([$priceRuleId, $coinId]);
                            $resolvedPriceRuleId = (int)$ruleStmt->fetchColumn();
                        }

                        if ($resolvedPriceRuleId > 0) {
                            $pdo->prepare("UPDATE mh_asset_pricing_rules SET price_usd_per_unit = ?, effective_from = ?, effective_to = ? WHERE id = ? AND asset_class_id = ?")
                                ->execute([$price, $effFrom, $effTo, $resolvedPriceRuleId, $coinId]);
                        } else {
                            $pdo->prepare("UPDATE mh_asset_pricing_rules SET effective_to = ? WHERE asset_class_id = ? AND effective_from < ? AND (effective_to IS NULL OR effective_to > ?)")
                                ->execute([$effFrom, $coinId, $effFrom, $effFrom]);
                            $pdo->prepare("INSERT INTO mh_asset_pricing_rules (asset_class_id, price_usd_per_unit, pricing_strategy, pricing_params_json, effective_from, effective_to) VALUES (?, ?, 'fixed', NULL, ?, ?)")
                                ->execute([$coinId, $price, $effFrom, $effTo]);
                        }
                    }

                    $pdo->commit();
                    $message = 'Culture coin saved.';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $message = 'Save failed: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'archive_culture_coin') {
            $coinId = isset($_POST['coin_id']) ? (int)$_POST['coin_id'] : 0;
            if ($coinId < 1) {
                $message = 'Invalid coin id.';
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE mh_asset_classes SET asset_type = 'archived' WHERE id = ?")->execute([$coinId]);
                    $pdo->prepare("UPDATE mh_asset_pricing_rules SET effective_to = NOW() WHERE asset_class_id = ? AND (effective_to IS NULL OR effective_to > NOW())")->execute([$coinId]);
                    $pdo->commit();
                    $message = 'Culture coin archived.';
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    $message = 'Archive failed: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'create_culture_reservation_notice') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                [$dataBase, $filePath] = mh_control_custom_notices_paths();
                $cfg = mh_control_custom_notices_read($filePath);
                $list = isset($cfg['custom_messages']) && is_array($cfg['custom_messages']) ? $cfg['custom_messages'] : [];

                $body = "Open: /hub/coins/culture.php\n";
                try {
                    if (function_exists('mh_tokenomics_seed_culture_coins') && function_exists('mh_tokenomics_get_current_price_usd')) {
                        $ids = mh_tokenomics_seed_culture_coins($pdo);
                        $champId = (int)($ids['champcoin'] ?? 0);
                        $superId = (int)($ids['supercoin'] ?? 0);

                        $readMeta = function (int $id) use ($pdo): array {
                            $stmt = $pdo->prepare("SELECT display_name, pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
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
                        $readPricing = function (int $id) use ($pdo): array {
                            $out = [
                                'current_price' => null,
                                'current_from' => '',
                                'next_price' => null,
                                'next_from' => '',
                            ];
                            if ($id < 1) {
                                return $out;
                            }
                            $stmtCur = $pdo->prepare("SELECT price_usd_per_unit, effective_from FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
                            $stmtCur->execute([$id]);
                            $cur = $stmtCur->fetch(PDO::FETCH_ASSOC);
                            if (is_array($cur) && isset($cur['price_usd_per_unit'])) {
                                $out['current_price'] = (float)$cur['price_usd_per_unit'];
                                $out['current_from'] = trim((string)($cur['effective_from'] ?? ''));
                            }
                            $stmtNext = $pdo->prepare("SELECT price_usd_per_unit, effective_from FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from > NOW() ORDER BY effective_from ASC LIMIT 1");
                            $stmtNext->execute([$id]);
                            $next = $stmtNext->fetch(PDO::FETCH_ASSOC);
                            if (is_array($next) && isset($next['price_usd_per_unit'])) {
                                $out['next_price'] = (float)$next['price_usd_per_unit'];
                                $out['next_from'] = trim((string)($next['effective_from'] ?? ''));
                            }
                            return $out;
                        };

                        $champ = ['name' => 'Champion Coin', 'ticker' => 'mhc', 'cap' => 0, 'issue' => '', 'close' => ''];
                        $super = ['name' => 'Super Coin', 'ticker' => 'mhs', 'cap' => 0, 'issue' => '', 'close' => ''];
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

                        $champPricing = $readPricing($champId);
                        $superPricing = $readPricing($superId);

                        $lines = [];
                        $lines[] = 'Open: /hub/coins/culture.php';
                        $lines[] = '';
                        $lines[] = $champ['name'] . ' (' . strtoupper($champ['ticker']) . ')';
                        if (is_float($champPricing['current_price']) && $champPricing['current_price'] > 0) {
                            $lines[] = 'Price: $' . number_format((float)$champPricing['current_price'], 2, '.', '') . ' per coin';
                        } elseif (is_float($champPricing['next_price']) && $champPricing['next_price'] > 0) {
                            $label = $champPricing['next_from'] !== '' ? 'Price from ' . $champPricing['next_from'] : 'Scheduled price';
                            $lines[] = $label . ': $' . number_format((float)$champPricing['next_price'], 2, '.', '') . ' per coin';
                        }
                        if ($champ['issue'] !== '') $lines[] = 'Issue date: ' . $champ['issue'];
                        if ($champ['close'] !== '') $lines[] = 'Close date: ' . $champ['close'];
                        if ((int)$champ['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$champ['cap']);
                        $lines[] = '';
                        $lines[] = $super['name'] . ' (' . strtoupper($super['ticker']) . ')';
                        if (is_float($superPricing['current_price']) && $superPricing['current_price'] > 0) {
                            $lines[] = 'Current price: $' . number_format((float)$superPricing['current_price'], 2, '.', '') . ' per coin';
                        } elseif (is_float($superPricing['next_price']) && $superPricing['next_price'] > 0) {
                            $label = $superPricing['next_from'] !== '' ? 'Price from ' . $superPricing['next_from'] : 'Scheduled price';
                            $lines[] = $label . ': $' . number_format((float)$superPricing['next_price'], 2, '.', '') . ' per coin';
                        }
                        if ($super['issue'] !== '') $lines[] = 'Issue date: ' . $super['issue'];
                        if ($super['close'] !== '') $lines[] = 'Close date: ' . $super['close'];
                        if ((int)$super['cap'] > 0) $lines[] = 'Supply cap: ' . number_format((int)$super['cap']);
                        $body = implode("\n", $lines);
                    }
                } catch (Throwable) {}

                $now = date('Y-m-d H:i:s');
                $noticeId = 'custom:culture_reservation';
                $title = 'Culture Coins Reservation';
                $entry = [
                    'id' => $noticeId,
                    'title' => $title,
                    'body' => $body,
                    'url' => '/hub/coins/culture.php',
                    'type' => 'info',
                    'pinned' => true,
                    'created_at' => $now,
                    'expires_at' => '',
                    'status' => 'active',
                ];

                $found = -1;
                foreach ($list as $i => $m) {
                    if (!is_array($m)) continue;
                    $id = isset($m['id']) ? trim((string)$m['id']) : '';
                    $t = isset($m['title']) ? trim((string)$m['title']) : '';
                    if ($id !== '' && hash_equals($noticeId, $id)) {
                        $found = (int)$i;
                        break;
                    }
                    if ($id === '' && $t !== '' && strcasecmp($t, $title) === 0) {
                        $found = (int)$i;
                    }
                }

                if ($found >= 0 && isset($list[$found]) && is_array($list[$found])) {
                    $existing = $list[$found];
                    $entry['created_at'] = isset($existing['created_at']) && is_string($existing['created_at']) && trim($existing['created_at']) !== '' ? trim($existing['created_at']) : $now;
                    $list[$found] = array_merge($existing, $entry);
                } else {
                    array_unshift($list, $entry);
                }

                $cfg['custom_messages'] = array_values($list);
                if (!mh_control_custom_notices_write($filePath, $cfg)) {
                    $message = 'Failed to write custom notice.';
                } else {
                    $message = 'Culture reservation notice saved to custom notices.';
                }
            }
        } elseif ($action === 'save_api_keys') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                $stripe = isset($_POST['stripe_secret_key']) ? trim((string)$_POST['stripe_secret_key']) : '';
                $brexToken = isset($_POST['brex_access_token']) ? trim((string)$_POST['brex_access_token']) : '';
                $brexCash = isset($_POST['brex_cash_account_id']) ? trim((string)$_POST['brex_cash_account_id']) : '';

                $cfg = mh_control_tokenomics_secrets_load();
                if (!is_array($cfg)) $cfg = [];
                $changed = 0;

                if ($stripe !== '') {
                    $enc = mh_control_tokenomics_encrypt($stripe);
                    if ($enc === '') {
                        $message = 'Encryption unavailable. Cannot store Stripe key.';
                    } else {
                        $cfg['stripe_secret_key'] = $enc;
                        $changed++;
                    }
                }
                if ($brexToken !== '') {
                    $enc = mh_control_tokenomics_encrypt($brexToken);
                    if ($enc === '') {
                        $message = 'Encryption unavailable. Cannot store Brex token.';
                    } else {
                        $cfg['brex_access_token'] = $enc;
                        $changed++;
                    }
                }
                if ($brexCash !== '') {
                    $enc = mh_control_tokenomics_encrypt($brexCash);
                    if ($enc === '') {
                        $message = 'Encryption unavailable. Cannot store Brex cash account id.';
                    } else {
                        $cfg['brex_cash_account_id'] = $enc;
                        $changed++;
                    }
                }

                if ($message === '' && $changed > 0) {
                    $cfg['updated_at'] = gmdate('c');
                    mh_control_tokenomics_secrets_write($cfg);
                    $message = 'API keys saved.';
                } elseif ($message === '' && $changed === 0) {
                    $message = 'No changes.';
                }
            }
        } elseif ($action === 'save_bank_details') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                $details = isset($_POST['brex_bank_details']) ? trim((string)$_POST['brex_bank_details']) : '';
                $cfg = mh_control_tokenomics_secrets_load();
                if (!is_array($cfg)) $cfg = [];
                if ($details === '') {
                    unset($cfg['brex_bank_details']);
                    $cfg['updated_at'] = gmdate('c');
                    mh_control_tokenomics_secrets_write($cfg);
                    $message = 'Bank details cleared.';
                } else {
                    $enc = mh_control_tokenomics_encrypt($details);
                    if ($enc === '') {
                        $message = 'Encryption unavailable. Cannot store bank details.';
                    } else {
                        $cfg['brex_bank_details'] = $enc;
                        $cfg['updated_at'] = gmdate('c');
                        mh_control_tokenomics_secrets_write($cfg);
                        $message = 'Bank details saved.';
                    }
                }
            }
        } elseif ($action === 'clear_api_keys') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                $cfg = mh_control_tokenomics_secrets_load();
                if (!is_array($cfg)) $cfg = [];
                unset($cfg['stripe_secret_key'], $cfg['brex_access_token'], $cfg['brex_cash_account_id']);
                $cfg['updated_at'] = gmdate('c');
                mh_control_tokenomics_secrets_write($cfg);
                $message = 'API keys cleared.';
            }
        } elseif ($action === 'clear_meeting_pending_tokens') {
            $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
            if (!mh_control_meet_csrf_check($posted)) {
                $message = 'Invalid request.';
            } else {
                $billingUser = isset($_POST['billing_user']) ? trim((string)$_POST['billing_user']) : $billingUser;
                $billingRoom = isset($_POST['billing_room']) ? trim((string)$_POST['billing_room']) : $billingRoom;
                if ($billingUser === '') {
                    $message = 'Username is required.';
                } else {
                    try {
                        $result = mh_control_delete_pending_meeting_tokens($billingUser, $billingRoom);
                        if (($result['ok'] ?? false) !== true) {
                            $message = (string)($result['error'] ?? 'Failed to delete pending meeting charges.');
                        } else {
                            $count = (int)($result['count'] ?? 0);
                            $message = $count > 0
                                ? 'Deleted ' . $count . ' pending meeting row' . ($count === 1 ? '' : 's') . '.'
                                : 'No pending meeting charges found for that filter.';
                        }
                    } catch (Throwable $e) {
                        $message = 'Delete failed: ' . $e->getMessage();
                    }
                }
            }
        }
        if ($shouldRedirectAfterPost && !headers_sent()) {
            $_SESSION['mh_tokenomics_flash'] = [
                'message' => $message,
                'artifactMsg' => '',
                'artifactErr' => '',
            ];
            header('Location: ' . $requestUri, true, 303);
            exit;
        }
    }

    $services = $pdo->query("SELECT service_key, tokens_per_unit, unit_name, enabled, updated_at FROM mh_service_pricing ORDER BY service_key ASC")->fetchAll(PDO::FETCH_ASSOC);
    $triggers = $pdo->query("SELECT id, enabled, http_method, path_pattern, selector_type, selector_key, selector_value, service_key, units_mode, units_value, priority, updated_at FROM mh_service_triggers ORDER BY priority ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    $equityClasses = [];
    try {
        $equityClasses = $pdo->query("SELECT id, name, fractional_units_per_share FROM equity_classes ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $equityClasses = [];
    }
    $utilityId = (int)$pdo->query("SELECT id FROM mh_asset_classes WHERE asset_key = 'utility:meta' LIMIT 1")->fetchColumn();
    $tokenPrice = 0.0;
    $bonusStartUsd = 100;
    $bonusBasePct = 5.0;
    $bonusStepUsd = 50;
    $bonusStepPct = 1.0;
    if ($utilityId > 0) {
        $stmt = $pdo->prepare("SELECT price_usd_per_unit FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
        $stmt->execute([$utilityId]);
        $tokenPrice = (float)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT pricing_params_json FROM mh_asset_classes WHERE id = ? LIMIT 1");
        $stmt->execute([$utilityId]);
        $raw = $stmt->fetchColumn();
        if ($raw !== false && is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && isset($decoded['bonus_scale']) && is_array($decoded['bonus_scale'])) {
                $bs = $decoded['bonus_scale'];
                if (isset($bs['start_usd'])) $bonusStartUsd = max(1, (int)$bs['start_usd']);
                if (isset($bs['base_bonus_pct'])) $bonusBasePct = max(0.0, min(100.0, (float)$bs['base_bonus_pct']));
                if (isset($bs['step_usd'])) $bonusStepUsd = max(1, (int)$bs['step_usd']);
                if (isset($bs['step_bonus_pct'])) $bonusStepPct = max(0.0, min(100.0, (float)$bs['step_bonus_pct']));
            }
        }
    }

    $cultureCoins = [];
    $cultureCoinRows = [];
    try {
        $cultureCoins = $pdo->query("SELECT id, asset_key, display_name, decimals, pricing_params_json FROM mh_asset_classes WHERE asset_type = 'culture' ORDER BY asset_key ASC")->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($cultureCoins)) {
            $stmtCur = $pdo->prepare("SELECT id, price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from <= NOW() AND (effective_to IS NULL OR effective_to > NOW()) ORDER BY effective_from DESC LIMIT 1");
            $stmtNext = $pdo->prepare("SELECT id, price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? AND effective_from > NOW() ORDER BY effective_from ASC LIMIT 1");
            $stmtRules = $pdo->prepare("SELECT id, price_usd_per_unit, effective_from, effective_to FROM mh_asset_pricing_rules WHERE asset_class_id = ? ORDER BY effective_from DESC, id DESC");
            $nowTs = time();
            foreach ($cultureCoins as $c) {
                if (!is_array($c)) continue;
                $id = (int)($c['id'] ?? 0);
                if ($id < 1) continue;
                $assetKey = trim((string)($c['asset_key'] ?? ''));
                $displayName = trim((string)($c['display_name'] ?? ''));
                $decimals = (int)($c['decimals'] ?? 0);
                $raw = trim((string)($c['pricing_params_json'] ?? ''));
                $meta = $raw !== '' ? json_decode($raw, true) : null;
                $ticker = is_array($meta) && isset($meta['ticker']) ? trim((string)$meta['ticker']) : '';
                $supplyCap = is_array($meta) && isset($meta['supply_cap']) ? (int)$meta['supply_cap'] : 0;
                $issueDate = is_array($meta) && isset($meta['issue_date']) ? trim((string)$meta['issue_date']) : '';
                $closeDate = is_array($meta) && isset($meta['close_date']) ? trim((string)$meta['close_date']) : '';
                $stmtCur->execute([$id]);
                $cur = $stmtCur->fetch(PDO::FETCH_ASSOC);
                $curRuleId = is_array($cur) && isset($cur['id']) ? (int)$cur['id'] : 0;
                $curPrice = is_array($cur) && isset($cur['price_usd_per_unit']) ? (float)$cur['price_usd_per_unit'] : 0.0;
                $curFrom = is_array($cur) && isset($cur['effective_from']) ? trim((string)$cur['effective_from']) : '';
                $curTo = is_array($cur) && isset($cur['effective_to']) ? trim((string)$cur['effective_to']) : '';

                $stmtNext->execute([$id]);
                $nxt = $stmtNext->fetch(PDO::FETCH_ASSOC);
                $nextRuleId = is_array($nxt) && isset($nxt['id']) ? (int)$nxt['id'] : 0;
                $nextPrice = is_array($nxt) && isset($nxt['price_usd_per_unit']) ? (float)$nxt['price_usd_per_unit'] : 0.0;
                $nextFrom = is_array($nxt) && isset($nxt['effective_from']) ? trim((string)$nxt['effective_from']) : '';
                $nextTo = is_array($nxt) && isset($nxt['effective_to']) ? trim((string)$nxt['effective_to']) : '';

                $pricingRules = [];
                $stmtRules->execute([$id]);
                $ruleRows = $stmtRules->fetchAll(PDO::FETCH_ASSOC);
                foreach ($ruleRows as $ruleRow) {
                    if (!is_array($ruleRow)) {
                        continue;
                    }
                    $ruleId = isset($ruleRow['id']) ? (int)$ruleRow['id'] : 0;
                    if ($ruleId < 1) {
                        continue;
                    }
                    $ruleFrom = isset($ruleRow['effective_from']) ? trim((string)$ruleRow['effective_from']) : '';
                    $ruleTo = isset($ruleRow['effective_to']) ? trim((string)$ruleRow['effective_to']) : '';
                    $fromTs = $ruleFrom !== '' ? strtotime($ruleFrom) : false;
                    $toTs = $ruleTo !== '' ? strtotime($ruleTo) : false;
                    $status = 'future';
                    if ($fromTs !== false && $fromTs <= $nowTs && ($toTs === false || $toTs > $nowTs)) {
                        $status = 'current';
                    } elseif ($toTs !== false && $toTs <= $nowTs) {
                        $status = 'past';
                    }
                    $pricingRules[] = [
                        'id' => $ruleId,
                        'price_usd_per_unit' => isset($ruleRow['price_usd_per_unit']) ? (float)$ruleRow['price_usd_per_unit'] : 0.0,
                        'effective_from' => $ruleFrom,
                        'effective_to' => $ruleTo,
                        'effective_from_input' => mh_control_format_datetime_local($ruleFrom),
                        'effective_to_input' => mh_control_format_datetime_local($ruleTo),
                        'status' => $status,
                    ];
                }
                $displayIssueDate = $issueDate !== '' ? $issueDate : '';
                if ($curFrom !== '') {
                    $displayIssueDate = substr($curFrom, 0, 10);
                } elseif ($displayIssueDate === '' && $nextFrom !== '') {
                    $displayIssueDate = substr($nextFrom, 0, 10);
                }
                $displayCloseDate = $closeDate !== '' ? $closeDate : '';
                if ($curTo !== '') {
                    $displayCloseDate = substr($curTo, 0, 10);
                } elseif ($displayCloseDate === '' && $nextTo !== '') {
                    $displayCloseDate = substr($nextTo, 0, 10);
                }

                $cultureCoinRows[] = [
                    'id' => $id,
                    'asset_key' => $assetKey,
                    'display_name' => $displayName,
                    'ticker' => $ticker,
                    'supply_cap' => $supplyCap,
                    'decimals' => $decimals,
                    'issue_date' => $displayIssueDate,
                    'issue_date_input' => mh_control_format_date_input($issueDate),
                    'close_date' => $displayCloseDate,
                    'close_date_input' => mh_control_format_date_input($closeDate),
                    'current_price_usd_per_unit' => $curPrice,
                    'current_price_rule_id' => $curRuleId,
                    'current_effective_from' => $curFrom,
                    'current_effective_to' => $curTo,
                    'next_price_usd_per_unit' => $nextPrice,
                    'next_price_rule_id' => $nextRuleId,
                    'next_effective_from' => $nextFrom,
                    'next_effective_to' => $nextTo,
                    'pricing_rules' => $pricingRules,
                ];
            }
        }
    } catch (Throwable) {
        $cultureCoins = [];
        $cultureCoinRows = [];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'System Error: ' . htmlspecialchars($e->getMessage());
    exit;
}

$templatesPath = function_exists('getTemplatesPath') ? (string)getTemplatesPath() : (__DIR__ . '/../templates');
$csrf = mh_control_meet_csrf_get();
$apiSecrets = mh_control_tokenomics_secrets_status();
$stripeHealth = mh_control_tokenomics_stripe_health();
$brexHealth = mh_control_tokenomics_brex_health();
$brexBankDetailsPlain = mh_control_tokenomics_secret_plain('brex_bank_details');
$exportTriggers = [];
if (isset($triggers) && is_array($triggers)) {
    foreach ($triggers as $t) {
        if (!is_array($t)) continue;
        $exportTriggers[] = [
            'enabled' => (int)($t['enabled'] ?? 0),
            'http_method' => (string)($t['http_method'] ?? 'POST'),
            'path_pattern' => (string)($t['path_pattern'] ?? ''),
            'selector_type' => (string)($t['selector_type'] ?? 'post_action'),
            'selector_key' => (string)($t['selector_key'] ?? ''),
            'selector_value' => (string)($t['selector_value'] ?? ''),
            'service_key' => (string)($t['service_key'] ?? ''),
            'units_mode' => (string)($t['units_mode'] ?? 'fixed'),
            'units_value' => (int)($t['units_value'] ?? 1),
            'priority' => (int)($t['priority'] ?? 100),
        ];
    }
}
$exportTriggersJson = json_encode($exportTriggers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if (!is_string($exportTriggersJson) || $exportTriggersJson === '') {
    $exportTriggersJson = "[]";
}

$billingRows = [];
if ($billingUser !== '') {
    $tenantId = 'user:' . $billingUser;
    $dbId = function_exists('mh_resolve_tenant_db_config_id') ? mh_resolve_tenant_db_config_id($tenantId) : null;
    if (is_string($dbId) && $dbId !== '' && function_exists('database_getConnectionById')) {
        try {
            $calDb = database_getConnectionById($dbId);
            if ($calDb instanceof PDO) {
                calendar_ensure_tables($calDb);
                $sql = "SELECT id, room_id, title, created_at_utc, scheduled_for_text, token_charge_status, token_charge_amount, token_charge_due_utc, token_charged_at_utc, token_charge_error
                        FROM mh_meetings
                        WHERE created_by_user = ?
                        " . ($billingRoom !== '' ? "AND room_id = ? " : "") . "
                        ORDER BY id DESC
                        LIMIT 100";
                $stmt = $calDb->prepare($sql);
                $params = [$billingUser];
                if ($billingRoom !== '') $params[] = $billingRoom;
                $stmt->execute($params);
                $billingRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        } catch (Throwable $e) {
            $artifactErr = 'Calendar lookup failed: ' . $e->getMessage();
        }
    } else {
        $artifactErr = 'Tenant database not found for user.';
    }
}

if ($artifactUser !== '' && $artifactRoom !== '' && $artifactAction !== '') {
    $tenantSafe = mh_control_tenant_safe($artifactUser);
    $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
    $base = $base !== '' ? rtrim($base, '/') : '/data';
    $meetingsRoot = $base . '/tenants/' . $tenantSafe . '/meetings/' . $artifactRoom;
    $abs = $meetingsRoot . '/' . ltrim($artifactPath, '/');

    if (!mh_control_path_is_within($abs, $meetingsRoot)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }

    if ($artifactAction === 'download') {
        mh_control_send_file($abs, basename($abs));
    }
    if ($artifactAction === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $posted = isset($_POST['csrf']) ? (string)$_POST['csrf'] : '';
        if (!mh_control_meet_csrf_check($posted)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
        if (is_file($abs)) {
            @unlink($abs);
            $artifactMsg = 'Deleted.';
        }
        if (!headers_sent()) {
            $_SESSION['mh_tokenomics_flash'] = [
                'message' => '',
                'artifactMsg' => $artifactMsg,
                'artifactErr' => $artifactErr,
            ];
            header('Location: ' . $requestUri, true, 303);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tokenomics Management | Meta Humans</title>
    <?php include_once $templatesPath . '/global-ui/includes/complete-head.php'; ?>
    <style>
        :root { --primary: #00d4ff; --glass: rgba(255, 255, 255, 0.05); --border: rgba(0, 212, 255, 0.2); --text-main: #e0e0e0; }
        main.main-content.tokenomics-page { background-color: #1a1a1a; color: var(--text-main); font-family: 'Rajdhani', sans-serif; margin: 0; min-height: 100vh; }
        .tokenomics-page .container { max-width: 1400px; margin: 0 auto; padding: 0 20px 40px; }
        .tokenomics-page h1, .tokenomics-page h2 { font-family: 'Orbitron', sans-serif; color: var(--primary); margin: 0 0 10px; }
        .tokenomics-page .grid { display: grid; grid-template-columns: minmax(360px, 520px) 1fr; gap: 30px; align-items: start; }
        .tokenomics-page .panel-span-all { grid-column: 1 / -1; }
        .tokenomics-page .panel { background: var(--glass); border: 1px solid var(--border); padding: 25px; border-radius: 12px; margin-bottom: 25px; position: relative; overflow: visible; }
        .tokenomics-page .panel h2 { margin-top: 0; }
        .tokenomics-page table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .tokenomics-page th, .tokenomics-page td { padding: 12px; text-align: left; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: top; white-space: normal; word-break: break-word; overflow-wrap: anywhere; }
        .tokenomics-page th { color: var(--primary); font-family: 'Orbitron', sans-serif; font-size: 0.9rem; }
        .tokenomics-page label { display: block; margin: 12px 0 6px; font-weight: 600; }
        .tokenomics-page select, .tokenomics-page input, .tokenomics-page button { background: rgba(0,0,0,0.3); border: 1px solid var(--border); color: #fff; padding: 11px 12px; width: 100%; border-radius: 6px; box-sizing: border-box; }
        .tokenomics-page button { margin-top: 12px; background: var(--primary); color: #000; font-weight: bold; cursor: pointer; text-transform: uppercase; }
        .tokenomics-page button:hover { opacity: 0.9; }
        .tokenomics-page .alert { background: rgba(0, 212, 255, 0.1); border: 1px solid var(--primary); color: var(--primary); padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .tokenomics-page .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .tokenomics-page .hint { color:#9aa; font-size:0.85rem; margin-top: 6px; }
        .tokenomics-page .btn-inline { padding: 8px 12px; width: auto; margin-top: 0; }
        .tokenomics-page .inline-form { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap; width: 100%; max-width: 100%; }
        .tokenomics-page .inline-form input { width: auto; flex: 1 1 190px; min-width: 150px; max-width: 100%; }
        .tokenomics-page .inline-form .small { flex: 0 1 120px; min-width: 110px; }
        .tokenomics-page .table-scroll { overflow-x: auto; max-width: 100%; border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; margin-top: 15px; }
        .tokenomics-page .table-scroll table { margin-top: 0; min-width: 100%; table-layout: auto; }
        .tokenomics-page .mh-edit-row { display: none; }
        .tokenomics-page .mh-edit-row.mh-open { display: table-row; }
        .tokenomics-page .mh-edit-row > td { padding: 14px; background: rgba(255,255,255,0.02); }
        .tokenomics-page .culture-add-grid,
        .tokenomics-page .culture-meta-grid,
        .tokenomics-page .culture-rule-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
        .tokenomics-page .culture-edit-shell { display:grid; gap:16px; width:100%; max-width:100%; box-sizing:border-box; }
        .tokenomics-page .culture-edit-block { border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:14px; background:rgba(255,255,255,0.02); }
        .tokenomics-page .culture-edit-block h3 { margin:0 0 8px; color: var(--primary); font-family:'Orbitron', sans-serif; font-size:1rem; }
        .tokenomics-page .culture-edit-block .hint { margin-bottom: 8px; }
        .tokenomics-page .culture-meta-actions,
        .tokenomics-page .culture-rule-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:12px; }
        .tokenomics-page .culture-meta-actions .btn-inline,
        .tokenomics-page .culture-rule-actions .btn-inline { min-width: 130px; }
        .tokenomics-page .culture-rule-list { display:grid; gap:12px; }
        .tokenomics-page .culture-rule-card { border:1px solid rgba(255,255,255,0.08); border-radius:10px; padding:14px; background:rgba(0,0,0,0.18); }
        .tokenomics-page .culture-rule-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; flex-wrap:wrap; }
        .tokenomics-page .culture-rule-label { font-weight:700; color:#fff; }
        .tokenomics-page .culture-rule-status { display:inline-flex; align-items:center; padding:4px 8px; border-radius:999px; font-size:0.8rem; font-weight:700; text-transform:uppercase; letter-spacing:0.05em; }
        .tokenomics-page .culture-rule-status.current { background:rgba(16,185,129,0.18); color:#8ef0c7; border:1px solid rgba(16,185,129,0.35); }
        .tokenomics-page .culture-rule-status.future { background:rgba(59,130,246,0.18); color:#93c5fd; border:1px solid rgba(59,130,246,0.35); }
        .tokenomics-page .culture-rule-status.past { background:rgba(148,163,184,0.14); color:#cbd5e1; border:1px solid rgba(148,163,184,0.28); }
        @media (max-width: 1100px) {
            .tokenomics-page .grid { grid-template-columns: 1fr; }
            .tokenomics-page .table-scroll { overflow-x: auto; }
        }
    </style>
</head>
<body>
<?php include_once $templatesPath . '/global-ui/includes/complete-body-start.php'; ?>
<main class="main-content tokenomics-page">
<div class="container">
    <h1>Tokenomics Management</h1>
    <p class="hint">Manage service token costs and the reference USD price for the Utility token.</p>
    <p class="hint"><a href="/control/loans/" style="color: var(--primary); text-decoration: none; font-weight: 700;">Open Loans</a></p>

    <?php if ($message !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($artifactMsg !== ''): ?>
        <div class="alert"><?php echo htmlspecialchars($artifactMsg); ?></div>
    <?php endif; ?>
    <?php if ($artifactErr !== ''): ?>
        <div class="alert" style="border-color: rgba(255,80,80,.55); color: rgba(255,170,170,.95); background: rgba(255,80,80,.10);"><?php echo htmlspecialchars($artifactErr); ?></div>
    <?php endif; ?>

    <div class="grid">
        <div class="panel panel-span-all">
            <h2>Culture Coins</h2>
            <form method="POST">
                <input type="hidden" name="action" value="save_culture_coin">
                <div class="culture-add-grid">
                    <div>
                        <label>Asset key</label>
                        <input type="text" name="asset_key" placeholder="culture:champcoin" required>
                    </div>
                    <div>
                        <label>Name</label>
                        <input type="text" name="display_name" placeholder="Champion Coin" required>
                    </div>
                    <div>
                        <label>Ticker</label>
                        <input type="text" name="ticker" placeholder="mhc">
                    </div>
                </div>
                <div class="row">
                    <div>
                        <label>Supply cap</label>
                        <input type="number" min="0" step="1" name="supply_cap" value="0">
                    </div>
                    <div>
                        <label>Decimals</label>
                        <input type="number" min="0" max="18" step="1" name="decimals" value="0">
                    </div>
                    <div>
                        <label>Issue date</label>
                        <input type="date" name="issue_date">
                    </div>
                    <div>
                        <label>Close date</label>
                        <input type="date" name="close_date">
                    </div>
                    <div>
                        <label>USD / Unit</label>
                        <input type="number" min="0" step="0.0001" name="price_usd_per_unit" value="0">
                    </div>
                    <div>
                        <label>Price effective from</label>
                        <input type="datetime-local" step="1" name="price_effective_from">
                    </div>
                    <div>
                        <label>Price effective to</label>
                        <input type="datetime-local" step="1" name="price_effective_to">
                    </div>
                </div>
                <div class="hint">Use one pricing window per row below when editing existing coins. The culture page reads the active window directly from the database.</div>
                <button type="submit">Add Coin</button>
            </form>

            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Key</th>
                            <th>Name</th>
                            <th>Ticker</th>
                            <th>Cap</th>
                            <th>Decimals</th>
                            <th>Issue</th>
                            <th>Close</th>
                            <th>Current USD</th>
                            <th>Current From</th>
                            <th>Current To</th>
                            <th>Next USD</th>
                            <th>Next From</th>
                            <th>Next To</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach (($cultureCoinRows ?? []) as $c): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo htmlspecialchars((string)($c['asset_key'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['display_name'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['ticker'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo number_format((int)($c['supply_cap'] ?? 0)); ?></td>
                            <td><?php echo (int)($c['decimals'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['issue_date'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['close_date'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo number_format((float)($c['current_price_usd_per_unit'] ?? 0.0), 4, '.', ''); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['current_effective_from'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['current_effective_to'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo number_format((float)($c['next_price_usd_per_unit'] ?? 0.0), 4, '.', ''); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['next_effective_from'] ?? ''), ENT_QUOTES); ?></td>
                            <td><?php echo htmlspecialchars((string)($c['next_effective_to'] ?? ''), ENT_QUOTES); ?></td>
                            <td style="white-space:nowrap;">
                                <button type="button" class="btn-inline" onclick="this.closest('tr').nextElementSibling && this.closest('tr').nextElementSibling.classList.toggle('mh-open')">Edit</button>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="archive_culture_coin">
                                    <input type="hidden" name="coin_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                    <button type="submit" class="btn-inline" style="background: rgba(255,80,80,.85); color:#000;">Archive</button>
                                </form>
                            </td>
                        </tr>
                        <tr class="mh-edit-row">
                            <td colspan="14" style="padding: 14px;">
                                <div class="culture-edit-shell">
                                    <div class="culture-edit-block">
                                        <h3>Coin Details</h3>
                                        <div class="hint">These fields update the metadata shown on the culture page.</div>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="save_culture_coin_meta">
                                            <input type="hidden" name="coin_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                            <input type="hidden" name="asset_key" value="<?php echo htmlspecialchars((string)($c['asset_key'] ?? ''), ENT_QUOTES); ?>">
                                            <div class="culture-meta-grid">
                                                <div>
                                                    <label>Name</label>
                                                    <input type="text" name="display_name" value="<?php echo htmlspecialchars((string)($c['display_name'] ?? ''), ENT_QUOTES); ?>">
                                                </div>
                                                <div>
                                                    <label>Ticker</label>
                                                    <input type="text" name="ticker" value="<?php echo htmlspecialchars((string)($c['ticker'] ?? ''), ENT_QUOTES); ?>">
                                                </div>
                                                <div>
                                                    <label>Supply cap</label>
                                                    <input type="number" min="0" step="1" name="supply_cap" value="<?php echo (int)($c['supply_cap'] ?? 0); ?>">
                                                </div>
                                                <div>
                                                    <label>Decimals</label>
                                                    <input type="number" min="0" max="18" step="1" name="decimals" value="<?php echo (int)($c['decimals'] ?? 0); ?>">
                                                </div>
                                                <div>
                                                    <label>Issue date</label>
                                                    <input type="date" name="issue_date" value="<?php echo htmlspecialchars((string)($c['issue_date_input'] ?? ''), ENT_QUOTES); ?>">
                                                </div>
                                                <div>
                                                    <label>Close date</label>
                                                    <input type="date" name="close_date" value="<?php echo htmlspecialchars((string)($c['close_date_input'] ?? ''), ENT_QUOTES); ?>">
                                                </div>
                                            </div>
                                            <div class="culture-meta-actions">
                                                <button type="submit" class="btn-inline">Save Details</button>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="culture-edit-block">
                                        <h3>Pricing Schedule</h3>
                                        <div class="hint">Edit the exact pricing window you want. The row marked <strong>Current</strong> is what <a href="/hub/coins/culture.php" style="color: var(--primary);">/hub/coins/culture.php</a> shows now.</div>
                                        <div class="culture-rule-list">
                                            <?php foreach (($c['pricing_rules'] ?? []) as $rule): ?>
                                                <div class="culture-rule-card">
                                                    <div class="culture-rule-head">
                                                        <div class="culture-rule-label">Rule #<?php echo (int)($rule['id'] ?? 0); ?></div>
                                                        <span class="culture-rule-status <?php echo htmlspecialchars((string)($rule['status'] ?? 'future'), ENT_QUOTES); ?>">
                                                            <?php echo htmlspecialchars(strtoupper((string)($rule['status'] ?? 'future')), ENT_QUOTES); ?>
                                                        </span>
                                                    </div>
                                                    <form method="POST" style="margin:0;">
                                                        <input type="hidden" name="action" value="save_culture_coin_rule">
                                                        <input type="hidden" name="coin_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                                        <input type="hidden" name="price_rule_id" value="<?php echo (int)($rule['id'] ?? 0); ?>">
                                                        <div class="culture-rule-grid">
                                                            <div>
                                                                <label>USD / Unit</label>
                                                                <input type="number" min="0" step="0.0001" name="price_usd_per_unit" value="<?php echo number_format((float)($rule['price_usd_per_unit'] ?? 0.0), 4, '.', ''); ?>">
                                                            </div>
                                                            <div>
                                                                <label>Effective from</label>
                                                                <input type="datetime-local" step="1" name="price_effective_from" value="<?php echo htmlspecialchars((string)($rule['effective_from_input'] ?? ''), ENT_QUOTES); ?>">
                                                            </div>
                                                            <div>
                                                                <label>Effective to</label>
                                                                <input type="datetime-local" step="1" name="price_effective_to" value="<?php echo htmlspecialchars((string)($rule['effective_to_input'] ?? ''), ENT_QUOTES); ?>">
                                                            </div>
                                                        </div>
                                                        <div class="culture-rule-actions">
                                                            <button type="submit" class="btn-inline">Save Window</button>
                                                        </div>
                                                    </form>
                                                    <form method="POST" style="margin-top:10px;">
                                                        <input type="hidden" name="action" value="delete_culture_coin_rule">
                                                        <input type="hidden" name="coin_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                                        <input type="hidden" name="price_rule_id" value="<?php echo (int)($rule['id'] ?? 0); ?>">
                                                        <div class="culture-rule-actions">
                                                            <button type="submit" class="btn-inline" style="background: rgba(255,80,80,.14); border-color: rgba(255,80,80,.35); color: rgba(255,180,180,.95);">Delete Window</button>
                                                        </div>
                                                    </form>
                                                </div>
                                            <?php endforeach; ?>

                                            <div class="culture-rule-card">
                                                <div class="culture-rule-head">
                                                    <div class="culture-rule-label">Add Pricing Window</div>
                                                    <span class="culture-rule-status future">NEW</span>
                                                </div>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="action" value="save_culture_coin_rule">
                                                    <input type="hidden" name="coin_id" value="<?php echo (int)($c['id'] ?? 0); ?>">
                                                    <input type="hidden" name="price_rule_id" value="0">
                                                    <div class="culture-rule-grid">
                                                        <div>
                                                            <label>USD / Unit</label>
                                                            <input type="number" min="0" step="0.0001" name="price_usd_per_unit" value="">
                                                        </div>
                                                        <div>
                                                            <label>Effective from</label>
                                                            <input type="datetime-local" step="1" name="price_effective_from" value="">
                                                        </div>
                                                        <div>
                                                            <label>Effective to</label>
                                                            <input type="datetime-local" step="1" name="price_effective_to" value="">
                                                        </div>
                                                    </div>
                                                    <div class="culture-rule-actions">
                                                        <button type="submit" class="btn-inline">Add Window</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="hint">Culture coin metadata drives /hub/coins/culture.php. Notices are managed via the notices configuration, not hardcoded in the feed.</div>
            <div style="margin-top: 12px;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="create_culture_reservation_notice">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars((string)$csrf, ENT_QUOTES); ?>">
                    <button type="submit" class="btn-inline">Create Culture Reservation Notice</button>
                </form>
            </div>
        </div>

        <div>
            <div class="panel">
                <h2>Utility Token Price</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="set_token_price">
                    <label>USD / Token</label>
                    <input type="number" min="0" step="0.0001" name="price_usd_per_token" value="<?php echo number_format($tokenPrice, 4, '.', ''); ?>" required>
                    <div class="hint">Reference price only (billing/export); on-chain pricing can replace this later.</div>
                    <button type="submit">Update Price</button>
                </form>

                <form method="POST" style="margin-top: 16px;">
                    <input type="hidden" name="action" value="set_token_bonus_scale">
                    <label>Utility Token Bonus Scale</label>
                    <div class="row">
                        <div>
                            <label>Start (USD)</label>
                            <input type="number" min="1" step="1" name="bonus_start_usd" value="<?php echo (int)$bonusStartUsd; ?>" required>
                        </div>
                        <div>
                            <label>Base Bonus (%)</label>
                            <input type="number" min="0" max="100" step="0.1" name="bonus_base_pct" value="<?php echo number_format((float)$bonusBasePct, 1, '.', ''); ?>" required>
                        </div>
                    </div>
                    <div class="row">
                        <div>
                            <label>Step (USD)</label>
                            <input type="number" min="1" step="1" name="bonus_step_usd" value="<?php echo (int)$bonusStepUsd; ?>" required>
                        </div>
                        <div>
                            <label>Step Bonus (%)</label>
                            <input type="number" min="0" max="100" step="0.1" name="bonus_step_pct" value="<?php echo number_format((float)$bonusStepPct, 1, '.', ''); ?>" required>
                        </div>
                    </div>
                    <?php
                        $calcBonusPct = function (int $amount) use ($bonusStartUsd, $bonusBasePct, $bonusStepUsd, $bonusStepPct): float {
                            if ($amount < (int)$bonusStartUsd) return 0.0;
                            $steps = (int)floor(((float)($amount - (int)$bonusStartUsd)) / max(1, (int)$bonusStepUsd));
                            return max(0.0, (float)$bonusBasePct + ((float)$steps * (float)$bonusStepPct));
                        };
                    ?>
                    <div class="hint">
                        Sliding scale examples:
                        $<?php echo (int)$bonusStartUsd; ?> = <?php echo number_format($calcBonusPct((int)$bonusStartUsd), 1, '.', ''); ?>% more tokens,
                        $<?php echo (int)($bonusStartUsd + $bonusStepUsd); ?> = <?php echo number_format($calcBonusPct((int)($bonusStartUsd + $bonusStepUsd)), 1, '.', ''); ?>% more,
                        $<?php echo (int)($bonusStartUsd + (2 * $bonusStepUsd)); ?> = <?php echo number_format($calcBonusPct((int)($bonusStartUsd + (2 * $bonusStepUsd))), 1, '.', ''); ?>% more.
                    </div>
                    <button type="submit">Update Bonus Scale</button>
                </form>
            </div>

            <div class="panel">
                <h2>Equity Coins / Share</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Coins / Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equityClasses as $ec): ?>
                            <?php
                                $ups = (int)($ec['fractional_units_per_share'] ?? 1);
                                if ($ups < 1) $ups = 1;
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($ec['name'] ?? '')); ?></td>
                                <td><?php echo (int)$ups; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($equityClasses)): ?>
                            <tr><td colspan="2" style="color:#9aa;">No equity classes found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="hint">Updates come from Company Equity Classes.</div>
            </div>

            <div class="panel">
                <h2>Add / Update Service Cost</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="save_service">
                    <label>Common Actions</label>
                    <select id="commonServiceSelect">
                        <option value="">Select…</option>
                        <?php foreach ($commonServices as $cs): ?>
                            <option value="<?php echo htmlspecialchars((string)$cs['key']); ?>" data-unit="<?php echo htmlspecialchars((string)$cs['unit']); ?>"><?php echo htmlspecialchars((string)$cs['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Service Key</label>
                    <input type="text" id="serviceKeyInput" name="service_key" placeholder="e.g. equity:market:buy_coin" required>
                    <div class="row">
                        <div>
                            <label>Tokens / Unit</label>
                            <input type="number" min="1" step="1" name="tokens_per_unit" value="1" required>
                        </div>
                        <div>
                            <label>Unit Name</label>
                            <input type="text" id="unitNameInput" name="unit_name" placeholder="action" value="unit">
                        </div>
                    </div>
                    <label>
                        <input type="checkbox" name="enabled" value="1" checked style="width:auto; margin-right:8px;">
                        Enabled
                    </label>
                    <button type="submit">Save Service Pricing</button>
                </form>
                <div class="hint" style="margin-top:10px;">
                    Meeting billing uses service key <b>meet:meeting</b> and only charges the meeting creator/presenter when the meeting runs for 5 minutes with participants.
                    <a href="#meetingBillingPanel" style="color:var(--primary); text-decoration:none; margin-left:8px;">Open meetings billing</a>
                </div>
            </div>

            <div class="panel">
                <h2>Add / Update Trigger</h2>
                <form method="POST">
                    <input type="hidden" name="action" value="save_trigger">
                    <input type="hidden" name="id" id="triggerIdInput" value="0">
                    <label>Common Trigger Templates</label>
                    <select id="commonTriggerSelect">
                        <option value="">Select…</option>
                        <?php foreach ($commonTriggers as $ct): ?>
                            <option
                                value="<?php echo htmlspecialchars((string)($ct['service_key'] ?? '')); ?>"
                                data-method="<?php echo htmlspecialchars((string)($ct['method'] ?? 'POST')); ?>"
                                data-path="<?php echo htmlspecialchars((string)($ct['path'] ?? '')); ?>"
                                data-selector-type="<?php echo htmlspecialchars((string)($ct['selector_type'] ?? 'post_action')); ?>"
                                data-selector-value="<?php echo htmlspecialchars((string)($ct['selector_value'] ?? '')); ?>"
                            ><?php echo htmlspecialchars((string)($ct['path'] ?? '') . ' -> ' . (string)($ct['service_key'] ?? '')); ?></option>
                        <?php endforeach; ?>
                    </select>

                    <div class="row">
                        <div>
                            <label>HTTP Method</label>
                            <select name="http_method" id="triggerMethodInput">
                                <option value="POST" selected>POST</option>
                                <option value="PUT">PUT</option>
                                <option value="PATCH">PATCH</option>
                                <option value="DELETE">DELETE</option>
                            </select>
                        </div>
                        <div>
                            <label>Priority</label>
                            <input type="number" min="1" max="9999" step="1" name="priority" id="triggerPriorityInput" value="100">
                        </div>
                    </div>

                    <label>Path Pattern</label>
                    <input type="text" name="path_pattern" id="triggerPathInput" placeholder="/hub/equity/manage.php" required>

                    <div class="row">
                        <div>
                            <label>Selector Type</label>
                            <select name="selector_type" id="triggerSelectorTypeInput">
                                <option value="post_action" selected>post_action</option>
                                <option value="path_only">path_only</option>
                            </select>
                        </div>
                        <div>
                            <label>Selector Key</label>
                            <input type="text" name="selector_key" id="triggerSelectorKeyInput" value="action">
                        </div>
                    </div>

                    <label>Selector Value</label>
                    <input type="text" name="selector_value" id="triggerSelectorValueInput" placeholder="buy">

                    <label>Service Key</label>
                    <input type="text" name="service_key" id="triggerServiceKeyInput" placeholder="equity:market:buy_coin" required>

                    <div class="row">
                        <div>
                            <label>Units Mode</label>
                            <select name="units_mode" id="triggerUnitsModeInput">
                                <option value="fixed" selected>fixed</option>
                                <option value="post_int">post_int</option>
                            </select>
                        </div>
                        <div>
                            <label>Units Value</label>
                            <input type="number" min="1" step="1" name="units_value" id="triggerUnitsValueInput" value="1">
                        </div>
                    </div>

                    <label>
                        <input type="checkbox" name="enabled" id="triggerEnabledInput" value="1" checked style="width:auto; margin-right:8px;">
                        Enabled
                    </label>
                    <button type="submit">Save Trigger</button>
                </form>
                <div class="hint" style="margin-top:10px;">Triggers map requests to service keys for nationwide charging with no per-page edits.</div>
            </div>
        </div>

        <div>
            <div class="panel">
                <h2>Service Pricing Table</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Service Key</th>
                            <th>Tokens / Unit</th>
                            <th>Unit</th>
                            <th>Enabled</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $s): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($s['service_key'] ?? '')); ?></td>
                                <td><?php echo (int)($s['tokens_per_unit'] ?? 1); ?></td>
                                <td><?php echo htmlspecialchars((string)($s['unit_name'] ?? 'unit')); ?></td>
                                <td><?php echo (int)($s['enabled'] ?? 0) === 1 ? 'Yes' : 'No'; ?></td>
                                <td><?php echo htmlspecialchars((string)($s['updated_at'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($services)): ?>
                            <tr><td colspan="5" style="color:#9aa;">No service pricing rows yet. They auto-create on first charge.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Trigger Table</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Enabled</th>
                            <th>Match</th>
                            <th>Service Key</th>
                            <th>Units</th>
                            <th>Priority</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($triggers as $t): ?>
                            <tr>
                                <td><?php echo (int)($t['id'] ?? 0); ?></td>
                                <td><?php echo ((int)($t['enabled'] ?? 0) === 1) ? 'Yes' : 'No'; ?></td>
                                <td>
                                    <div><?php echo htmlspecialchars((string)($t['http_method'] ?? 'POST')); ?> <?php echo htmlspecialchars((string)($t['path_pattern'] ?? '')); ?></div>
                                    <div class="hint"><?php echo htmlspecialchars((string)($t['selector_type'] ?? 'post_action')); ?> · <?php echo htmlspecialchars((string)($t['selector_key'] ?? '')); ?>=<?php echo htmlspecialchars((string)($t['selector_value'] ?? '')); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($t['service_key'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($t['units_mode'] ?? 'fixed')); ?> (<?php echo (int)($t['units_value'] ?? 1); ?>)</td>
                                <td><?php echo (int)($t['priority'] ?? 100); ?></td>
                                <td style="white-space:nowrap;">
                                    <button type="button" class="btn-inline trigger-edit-btn"
                                        data-id="<?php echo (int)($t['id'] ?? 0); ?>"
                                        data-enabled="<?php echo (int)($t['enabled'] ?? 0); ?>"
                                        data-method="<?php echo htmlspecialchars((string)($t['http_method'] ?? 'POST')); ?>"
                                        data-path="<?php echo htmlspecialchars((string)($t['path_pattern'] ?? '')); ?>"
                                        data-selector-type="<?php echo htmlspecialchars((string)($t['selector_type'] ?? 'post_action')); ?>"
                                        data-selector-key="<?php echo htmlspecialchars((string)($t['selector_key'] ?? 'action')); ?>"
                                        data-selector-value="<?php echo htmlspecialchars((string)($t['selector_value'] ?? '')); ?>"
                                        data-service-key="<?php echo htmlspecialchars((string)($t['service_key'] ?? '')); ?>"
                                        data-units-mode="<?php echo htmlspecialchars((string)($t['units_mode'] ?? 'fixed')); ?>"
                                        data-units-value="<?php echo (int)($t['units_value'] ?? 1); ?>"
                                        data-priority="<?php echo (int)($t['priority'] ?? 100); ?>"
                                    >Edit</button>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="delete_trigger">
                                        <input type="hidden" name="id" value="<?php echo (int)($t['id'] ?? 0); ?>">
                                        <button type="submit" class="btn-inline" style="background:rgba(255,80,80,.14);border-color:rgba(255,80,80,.35);color:rgba(255,180,180,.95);">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($triggers)): ?>
                            <tr><td colspan="7" style="color:#9aa;">No triggers configured.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="panel">
                <h2>Export / Import Trigger JSON</h2>
                <div class="hint">Export your trigger policy and import it into another environment.</div>
                <label>Trigger JSON</label>
                <textarea id="triggersJsonTextarea" style="width:100%; min-height: 240px; background: rgba(0,0,0,0.3); border: 1px solid var(--border); color: #fff; padding: 11px 12px; border-radius: 6px; box-sizing: border-box; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace; font-size: 12px; line-height: 1.35;"><?php echo htmlspecialchars($exportTriggersJson); ?></textarea>
                <div class="inline-form" style="margin-top: 10px;">
                    <button type="button" class="btn-inline" id="copyTriggersBtn" style="height:44px;">Copy JSON</button>
                </div>
                <form method="POST" style="margin-top: 14px;">
                    <input type="hidden" name="action" value="import_triggers">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="triggers_json" id="triggersJsonHidden" value="">
                    <label>
                        <input type="checkbox" name="replace_all" value="1" style="width:auto; margin-right:8px;">
                        Replace all existing triggers
                    </label>
                    <button type="submit">Import JSON</button>
                </form>
                <div class="hint" style="margin-top:10px;">Import uses upsert on the trigger unique key (method + path + selector). Invalid rows are skipped.</div>
            </div>

            <div class="panel" id="meetingBillingPanel">
                <h2>Meetings: Billing + Artifacts</h2>
                <form method="GET" class="inline-form">
                    <div>
                        <label>Username</label>
                        <input type="text" name="billing_user" value="<?php echo htmlspecialchars($billingUser); ?>" placeholder="e.g. pieter" required>
                    </div>
                    <div>
                        <label>Room ID (optional)</label>
                        <input type="text" name="billing_room" value="<?php echo htmlspecialchars($billingRoom); ?>" placeholder="e.g. Dev_Sync">
                    </div>
                    <button class="btn-inline" type="submit" style="height:44px;">Load</button>
                </form>
                <?php if ($billingUser !== ''): ?>
                    <form method="POST" class="inline-form" style="margin-top:10px;">
                        <input type="hidden" name="action" value="clear_meeting_pending_tokens">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                        <input type="hidden" name="billing_user" value="<?php echo htmlspecialchars($billingUser); ?>">
                        <input type="hidden" name="billing_room" value="<?php echo htmlspecialchars($billingRoom); ?>">
                        <button class="btn-inline" type="submit" style="height:44px;background:rgba(255,120,120,.16);border:1px solid rgba(255,120,120,.35);color:rgba(255,210,210,.98);">Delete Pending Tokens</button>
                    </form>
                <?php endif; ?>
                <div class="hint">Billing comes from each user tenant DB table <b>mh_meetings</b>. Artifacts are served from <b>/data/tenants/&lt;tenantSafe&gt;/meetings/&lt;roomId&gt;</b>.</div>

                <?php if ($billingUser !== '' && is_array($billingRows)): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Room</th>
                                <th>When</th>
                                <th>Billing</th>
                                <th>Artifacts</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($billingRows)): ?>
                            <tr><td colspan="4" style="color:#9aa;">No meetings found.</td></tr>
                        <?php else: foreach ($billingRows as $r): ?>
                            <?php
                                $roomId = (string)($r['room_id'] ?? '');
                                $when = (string)($r['scheduled_for_text'] ?? '');
                                if ($when === '') $when = (string)($r['created_at_utc'] ?? '');
                                $status = (string)($r['token_charge_status'] ?? 'none');
                                $amt = (int)($r['token_charge_amount'] ?? 0);
                                $due = (string)($r['token_charge_due_utc'] ?? '');
                                $chargedAt = (string)($r['token_charged_at_utc'] ?? '');
                                $err = (string)($r['token_charge_error'] ?? '');

                                $tenantSafe = mh_control_tenant_safe($billingUser);
                                $base = function_exists('getDataPath') ? (string)getDataPath() : '/data';
                                $base = $base !== '' ? rtrim($base, '/') : '/data';
                                $root = $base . '/tenants/' . $tenantSafe . '/meetings/' . $roomId;
                                $recIndex = $root . '/recordings/index.json';
                                $recCount = 0;
                                if (is_file($recIndex)) {
                                    $raw = @file_get_contents($recIndex);
                                    $d = is_string($raw) ? json_decode($raw, true) : null;
                                    if (is_array($d) && isset($d['items']) && is_array($d['items'])) $recCount = count($d['items']);
                                }
                                $hasTranscripts = is_dir($root . '/transcripts');
                            ?>
                            <tr>
                                <td>
                                    <div style="font-weight:800;"><?php echo htmlspecialchars($roomId); ?></div>
                                    <div class="hint"><?php echo htmlspecialchars((string)($r['title'] ?? '')); ?></div>
                                </td>
                                <td>
                                    <div><?php echo htmlspecialchars($when); ?></div>
                                    <div class="hint">Due: <?php echo htmlspecialchars($due !== '' ? $due : '—'); ?> · Charged: <?php echo htmlspecialchars($chargedAt !== '' ? $chargedAt : '—'); ?></div>
                                </td>
                                <td>
                                    <div style="font-weight:800;"><?php echo htmlspecialchars($status); ?><?php echo $amt > 0 ? (' · ' . (int)$amt . ' tokens') : ''; ?></div>
                                    <?php if ($err !== ''): ?><div class="hint" style="color:rgba(255,160,160,.95)"><?php echo htmlspecialchars($err); ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <div class="hint">Recordings: <b><?php echo (int)$recCount; ?></b><?php echo $hasTranscripts ? ' · Transcripts: <b>yes</b>' : ''; ?></div>
                                    <div class="inline-form" style="margin-top:8px">
                                        <a class="btn-inline" style="display:inline-flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);border:1px solid var(--border);color:#fff;text-decoration:none;border-radius:6px;padding:10px 12px;" href="/hub/meetings/recordings.php">User view</a>
                                        <?php if (is_file($recIndex)): ?>
                                            <?php $p = rawurlencode('recordings/index.json'); ?>
                                            <a class="btn-inline" style="display:inline-flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);border:1px solid var(--border);color:#fff;text-decoration:none;border-radius:6px;padding:10px 12px;" href="/control/tokenomics-management.php?artifact_action=download&u=<?php echo rawurlencode($billingUser); ?>&room=<?php echo rawurlencode($roomId); ?>&p=<?php echo $p; ?>">Index</a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>

                    <?php
                        $artifactRoomUi = $billingRoom;
                        if ($artifactRoomUi === '' && is_array($billingRows) && !empty($billingRows)) {
                            $artifactRoomUi = (string)($billingRows[0]['room_id'] ?? '');
                        }
                        $tenantSafeUi = mh_control_tenant_safe($billingUser);
                        $baseUi = function_exists('getDataPath') ? (string)getDataPath() : '/data';
                        $baseUi = $baseUi !== '' ? rtrim($baseUi, '/') : '/data';
                        $rootUi = $baseUi . '/tenants/' . $tenantSafeUi . '/meetings/' . $artifactRoomUi;
                        $recDir = $rootUi . '/recordings';
                        $trDir = $rootUi . '/transcripts';
                        $tlDir = $rootUi . '/translations';
                        $files = [];
                        foreach ([$recDir => 'recordings', $trDir => 'transcripts', $tlDir => 'translations'] as $dir => $label) {
                            if (!is_dir($dir)) continue;
                            $scan = scandir($dir);
                            if (!is_array($scan)) continue;
                            foreach ($scan as $f) {
                                if (!is_string($f) || $f === '.' || $f === '..') continue;
                                if (is_dir($dir . '/' . $f)) continue;
                                $files[] = ['label' => $label, 'file' => $f, 'dir' => $dir];
                            }
                        }
                        $files = array_slice($files, 0, 50);
                    ?>
                    <?php if ($artifactRoomUi !== '' && !empty($files)): ?>
                        <h3 style="margin:18px 0 8px 0;color:var(--primary);font-family:'Orbitron',sans-serif;">Artifacts (<?php echo htmlspecialchars($billingUser); ?> / <?php echo htmlspecialchars($artifactRoomUi); ?>)</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>File</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($files as $f): ?>
                                <?php
                                    $label = (string)$f['label'];
                                    $fn = (string)$f['file'];
                                    $p = rawurlencode($label . '/' . $fn);
                                    $dl = '/control/tokenomics-management.php?artifact_action=download&u=' . rawurlencode($billingUser) . '&room=' . rawurlencode($artifactRoomUi) . '&p=' . $p;
                                    $del = '/control/tokenomics-management.php?artifact_action=delete&u=' . rawurlencode($billingUser) . '&room=' . rawurlencode($artifactRoomUi) . '&p=' . $p;
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($label); ?></td>
                                    <td><?php echo htmlspecialchars($fn); ?></td>
                                    <td style="text-align:right;">
                                        <a class="btn-inline" style="display:inline-flex;align-items:center;justify-content:center;background:rgba(0,0,0,.3);border:1px solid var(--border);color:#fff;text-decoration:none;border-radius:6px;padding:10px 12px;" href="<?php echo htmlspecialchars($dl); ?>">Download</a>
                                        <form method="post" action="<?php echo htmlspecialchars($del); ?>" style="display:inline;">
                                            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
                                            <button class="btn-inline" type="submit" style="background:rgba(255,80,80,.14);border-color:rgba(255,80,80,.35);color:rgba(255,180,180,.95);">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="panel">
        <h2>API Keys</h2>
        <div class="hint" style="margin-bottom:10px;">
            Stripe is required for Genesis token purchases. Brex is used for Equity deposit reconciliation.
        </div>
        <div class="hint" style="margin-bottom:12px;">
            Stripe: <?php echo ($apiSecrets['stripe']['set'] ? ('Stored (…' . htmlspecialchars((string)$apiSecrets['stripe']['suffix']) . ')') : 'Not set'); ?>
            <span style="color:<?php echo htmlspecialchars(mh_control_tokenomics_api_status_color($stripeHealth)); ?>; font-weight:800;">(<?php echo htmlspecialchars(mh_control_tokenomics_api_status_label($stripeHealth)); ?>)</span>
            · Brex Token: <?php echo ($apiSecrets['brex_token']['set'] ? ('Stored (…' . htmlspecialchars((string)$apiSecrets['brex_token']['suffix']) . ')') : 'Not set'); ?>
            · Brex Cash Account: <?php echo ($apiSecrets['brex_cash']['set'] ? ('Stored (…' . htmlspecialchars((string)$apiSecrets['brex_cash']['suffix']) . ')') : 'Not set'); ?>
            <span style="color:<?php echo htmlspecialchars(mh_control_tokenomics_api_status_color($brexHealth)); ?>; font-weight:800;">(<?php echo htmlspecialchars(mh_control_tokenomics_api_status_label($brexHealth)); ?>)</span>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_api_keys">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <label>Stripe Secret Key</label>
            <input type="password" name="stripe_secret_key" autocomplete="off" placeholder="sk_live_... (leave blank to keep current)">
            <label>Brex Access Token</label>
            <input type="password" name="brex_access_token" autocomplete="off" placeholder="Bearer token (leave blank to keep current)">
            <label>Brex Cash Account ID</label>
            <input type="text" name="brex_cash_account_id" autocomplete="off" placeholder="cash account id (leave blank to keep current)">
            <button type="submit">Save API Keys</button>
        </form>
        <form method="POST" style="margin-top:10px;">
            <input type="hidden" name="action" value="clear_api_keys">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <button type="submit" style="background:rgba(255,80,80,.14);border-color:rgba(255,80,80,.35);color:rgba(255,180,180,.95);">Clear Stored Keys</button>
        </form>
    </div>

    <div class="panel">
        <h2>Brex Bank Details</h2>
        <div class="hint" style="margin-bottom:10px;">
            These details show in the “Brex Bank Details” modal for users placing capital raise orders.
        </div>
        <div class="hint" style="margin-bottom:12px;">
            Status: <?php echo ($apiSecrets['brex_bank_details']['set'] ? ('Stored (ref ' . htmlspecialchars((string)$apiSecrets['brex_bank_details']['suffix']) . ')') : 'Not set'); ?>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="save_bank_details">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf); ?>">
            <label>Bank Details</label>
            <textarea name="brex_bank_details" rows="10" style="width:100%; background: rgba(0,0,0,0.3); border: 1px solid var(--border); color:#fff; padding: 11px 12px; border-radius: 6px; box-sizing: border-box;"><?php echo htmlspecialchars((string)$brexBankDetailsPlain); ?></textarea>
            <button type="submit">Save Bank Details</button>
        </form>
    </div>
</div>
</main>
<?php include_once $templatesPath . '/global-ui/includes/complete-body-end.php'; ?>
<script>
    (function () {
        const sel = document.getElementById('commonServiceSelect');
        const key = document.getElementById('serviceKeyInput');
        const unit = document.getElementById('unitNameInput');
        if (sel && key && unit) {
            sel.addEventListener('change', function () {
                const v = String(sel.value || '').trim();
                if (v === '') return;
                key.value = v;
                const opt = sel.options[sel.selectedIndex];
                const u = opt ? String(opt.getAttribute('data-unit') || '').trim() : '';
                if (u !== '') unit.value = u;
            });
        }

        const cts = document.getElementById('commonTriggerSelect');
        const trId = document.getElementById('triggerIdInput');
        const trEnabled = document.getElementById('triggerEnabledInput');
        const trMethod = document.getElementById('triggerMethodInput');
        const trPath = document.getElementById('triggerPathInput');
        const trSelType = document.getElementById('triggerSelectorTypeInput');
        const trSelKey = document.getElementById('triggerSelectorKeyInput');
        const trSelVal = document.getElementById('triggerSelectorValueInput');
        const trSvc = document.getElementById('triggerServiceKeyInput');
        const trUnitsMode = document.getElementById('triggerUnitsModeInput');
        const trUnitsVal = document.getElementById('triggerUnitsValueInput');
        const trPriority = document.getElementById('triggerPriorityInput');

        function applySelectorMode() {
            if (!trSelType || !trSelKey || !trSelVal) return;
            const t = String(trSelType.value || 'post_action');
            if (t === 'path_only') {
                trSelKey.value = '';
                trSelKey.setAttribute('readonly', 'readonly');
                if (String(trSelVal.value || '').trim() === '') trSelVal.value = '*';
            } else {
                trSelKey.removeAttribute('readonly');
                if (String(trSelKey.value || '').trim() === '') trSelKey.value = 'action';
                if (String(trSelVal.value || '').trim() === '*' || String(trSelVal.value || '').trim() === '') trSelVal.value = '';
            }
        }

        if (trSelType) {
            trSelType.addEventListener('change', applySelectorMode);
            applySelectorMode();
        }

        if (cts && trMethod && trPath && trSelType && trSelVal && trSvc) {
            cts.addEventListener('change', function () {
                const opt = cts.options[cts.selectedIndex];
                if (!opt) return;
                trId.value = '0';
                trEnabled.checked = true;
                trMethod.value = String(opt.getAttribute('data-method') || 'POST');
                trPath.value = String(opt.getAttribute('data-path') || '');
                trSelType.value = String(opt.getAttribute('data-selector-type') || 'post_action');
                trSelVal.value = String(opt.getAttribute('data-selector-value') || '');
                trSvc.value = String(opt.value || '');
                trUnitsMode.value = 'fixed';
                trUnitsVal.value = '1';
                trPriority.value = '100';
                applySelectorMode();
            });
        }

        document.querySelectorAll('.trigger-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                if (!trId) return;
                trId.value = String(btn.getAttribute('data-id') || '0');
                if (trEnabled) trEnabled.checked = String(btn.getAttribute('data-enabled') || '0') === '1';
                if (trMethod) trMethod.value = String(btn.getAttribute('data-method') || 'POST');
                if (trPath) trPath.value = String(btn.getAttribute('data-path') || '');
                if (trSelType) trSelType.value = String(btn.getAttribute('data-selector-type') || 'post_action');
                if (trSelKey) trSelKey.value = String(btn.getAttribute('data-selector-key') || 'action');
                if (trSelVal) trSelVal.value = String(btn.getAttribute('data-selector-value') || '');
                if (trSvc) trSvc.value = String(btn.getAttribute('data-service-key') || '');
                if (trUnitsMode) trUnitsMode.value = String(btn.getAttribute('data-units-mode') || 'fixed');
                if (trUnitsVal) trUnitsVal.value = String(btn.getAttribute('data-units-value') || '1');
                if (trPriority) trPriority.value = String(btn.getAttribute('data-priority') || '100');
                applySelectorMode();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        const triggersTa = document.getElementById('triggersJsonTextarea');
        const copyBtn = document.getElementById('copyTriggersBtn');
        const triggersHidden = document.getElementById('triggersJsonHidden');
        if (copyBtn && triggersTa) {
            copyBtn.addEventListener('click', function () {
                try {
                    triggersTa.select();
                    triggersTa.setSelectionRange(0, triggersTa.value.length);
                    document.execCommand('copy');
                } catch (e) {}
            });
        }
        if (triggersHidden && triggersTa) {
            const form = triggersHidden.closest('form');
            if (form) {
                form.addEventListener('submit', function () {
                    triggersHidden.value = String(triggersTa.value || '').trim();
                });
            }
        }
    })();
</script>
</body>
</html>
