<?php
declare(strict_types=1);

require_once __DIR__ . '/sr_client.php';

if (PHP_SAPI !== 'cli' && isset($_SERVER['SCRIPT_FILENAME']) && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Not a browsable page.\n";
    exit;
}

function mh_grid_whm_cfg_path(): string
{
    return '/data/config/hosting/whm.json';
}

function mh_grid_whm_root_env_path(): string
{
    $override = getenv('MH_GRID_WHM_ROOT_ENV_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }
    return '/data/config/hosting/whm-root.env';
}

function mh_grid_onemeta_env_path(): string
{
    $override = getenv('MH_GRID_ONEMETA_ENV_FILE');
    if (is_string($override) && trim($override) !== '') {
        return trim($override);
    }
    return '/data/config/hosting/onemeta.env';
}

function mh_grid_whm_parse_env_file(string $path): array
{
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $vars = [];
    foreach ($lines as $line) {
        if (!is_string($line)) {
            continue;
        }
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($key === '') {
            continue;
        }
        if (($value[0] ?? '') === '"' && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }
        if (($value[0] ?? '') === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        $vars[$key] = $value;
    }
    return $vars;
}

function mh_grid_whm_env_vars(string $profile): array
{
    static $cache = [];
    $profile = strtolower(trim($profile));
    if ($profile === '') {
        $profile = 'root';
    }
    if (isset($cache[$profile])) {
        return $cache[$profile];
    }

    $paths = [];
    if ($profile === 'onemeta') {
        $paths[] = mh_grid_onemeta_env_path();
    } else {
        $paths[] = mh_grid_whm_root_env_path();
    }

    $vars = [];
    foreach ($paths as $path) {
        foreach (mh_grid_whm_parse_env_file($path) as $key => $value) {
            if (!isset($vars[$key])) {
                $vars[$key] = $value;
            }
        }
    }

    $cache[$profile] = $vars;
    return $vars;
}

function mh_grid_whm_cfg_value(array $cfg, array $env, string $jsonKey, string $envKey, string $profile, string $default = ''): string
{
    if ($profile === 'root' && $jsonKey === 'cpanel_user') {
        return $default;
    }
    if ($profile === 'onemeta' && isset($env[$envKey]) && is_string($env[$envKey]) && trim((string)$env[$envKey]) !== '') {
        return trim((string)$env[$envKey]);
    }
    if (!($profile === 'root' && $jsonKey === 'cpanel_user')) {
        $jsonValue = isset($cfg[$jsonKey]) && is_string($cfg[$jsonKey]) ? trim((string)$cfg[$jsonKey]) : '';
        if ($jsonValue !== '') {
            return $jsonValue;
        }
    }
    if (isset($env[$envKey]) && is_string($env[$envKey]) && trim((string)$env[$envKey]) !== '') {
        return trim((string)$env[$envKey]);
    }
    $runtimeValue = getenv($envKey);
    if (is_string($runtimeValue) && trim($runtimeValue) !== '') {
        return trim($runtimeValue);
    }
    return $default;
}

function mh_grid_whm_read_cfg(string $profile = 'root'): array
{
    $profile = strtolower(trim($profile));
    if ($profile === '') {
        $profile = 'root';
    }

    $cfg = [];
    $path = mh_grid_whm_cfg_path();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $cfg = $decoded;
            }
        }
    }
    $env = mh_grid_whm_env_vars($profile);

    $baseUrl = mh_grid_whm_cfg_value($cfg, $env, 'base_url', 'MH_GRID_WHM_BASE_URL', $profile);
    $baseUrl = rtrim($baseUrl, '/');

    $authHeader = mh_grid_whm_cfg_value($cfg, $env, 'auth_header', 'MH_GRID_WHM_AUTH_HEADER', $profile);
    if ($authHeader !== '') {
        $authHeader = mh_grid_decrypt_cfg_value($authHeader);
    }

    $defaultZone = ltrim(mh_grid_whm_cfg_value($cfg, $env, 'default_zone', 'MH_GRID_WHM_DEFAULT_ZONE', $profile), '@');
    $defaultZone = strtolower($defaultZone);

    $allowedZones = [];
    $rawAllowedZones = isset($cfg['allowed_zones']) && is_array($cfg['allowed_zones']) ? $cfg['allowed_zones'] : [];
    foreach ($rawAllowedZones as $zone) {
        if (!is_string($zone)) {
            continue;
        }
        $normalized = strtolower(ltrim(trim($zone), '@'));
        if ($normalized === '' || isset($allowedZones[$normalized])) {
            continue;
        }
        $allowedZones[$normalized] = true;
    }
    if ($rawAllowedZones === []) {
        $envAllowedZones = trim((string)($env['MH_GRID_WHM_ALLOWED_ZONES'] ?? getenv('MH_GRID_WHM_ALLOWED_ZONES') ?: ''));
        if ($envAllowedZones !== '') {
            foreach (preg_split('/[\s,]+/', $envAllowedZones) as $zone) {
                if (!is_string($zone)) {
                    continue;
                }
                $normalized = strtolower(ltrim(trim($zone), '@'));
                if ($normalized === '' || isset($allowedZones[$normalized])) {
                    continue;
                }
                $allowedZones[$normalized] = true;
            }
        }
    }
    if ($defaultZone !== '' && !isset($allowedZones[$defaultZone])) {
        $allowedZones[$defaultZone] = true;
    }

    $cpanelUser = strtolower(mh_grid_whm_cfg_value($cfg, $env, 'cpanel_user', 'MH_GRID_WHM_CPANEL_USER', $profile));
    if ($profile === 'onemeta' && $cpanelUser === '' && $defaultZone === 'metahumans.one') {
        $cpanelUser = 'onemeta';
    }

    $accountOwner = mh_grid_whm_cfg_value($cfg, $env, 'account_owner', 'MH_GRID_WHM_ACCOUNT_OWNER', $profile);

    $defaultPackage = mh_grid_whm_cfg_value($cfg, $env, 'default_package', 'MH_GRID_WHM_DEFAULT_PACKAGE', $profile);

    $defaultTheme = mh_grid_whm_cfg_value($cfg, $env, 'default_theme', 'MH_GRID_WHM_DEFAULT_THEME', $profile);

    $defaultLocale = mh_grid_whm_cfg_value($cfg, $env, 'default_locale', 'MH_GRID_WHM_DEFAULT_LOCALE', $profile);

    $defaultContactEmail = mh_grid_whm_cfg_value($cfg, $env, 'default_contact_email', 'MH_GRID_WHM_DEFAULT_CONTACT_EMAIL', $profile);

    return [
        'profile' => $profile,
        'base_url' => $baseUrl,
        'auth_header' => $authHeader,
        'default_zone' => $defaultZone,
        'allowed_zones' => array_keys($allowedZones),
        'cpanel_user' => $cpanelUser,
        'account_owner' => $accountOwner,
        'default_package' => $defaultPackage,
        'default_theme' => $defaultTheme,
        'default_locale' => $defaultLocale,
        'default_contact_email' => $defaultContactEmail,
    ];
}

function mh_grid_whm_default_email_domain_hint(): string
{
    $cfg = mh_grid_whm_read_cfg('onemeta');
    return trim((string)($cfg['default_zone'] ?? ''));
}

function mh_grid_whm_encrypt_secret(string $plain): string
{
    $plain = trim($plain);
    if ($plain === '') {
        return '';
    }
    $key = mh_grid_enc_key();
    if ($key === '' || !function_exists('security_encryptValue')) {
        return $plain;
    }
    $enc = security_encryptValue($plain, $key);
    return is_string($enc) && trim($enc) !== '' ? trim($enc) : $plain;
}

function mh_grid_whm_tenant_safe(string $tenantId): string
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return '';
    }
    if (function_exists('mh_tenant_safe')) {
        $safe = (string)mh_tenant_safe($tenantId);
        if (trim($safe) !== '') {
            return trim($safe);
        }
    }
    if (function_exists('mh_tenant_safe_id')) {
        $safe = (string)mh_tenant_safe_id($tenantId);
        if (trim($safe) !== '') {
            return trim($safe);
        }
    }
    $safe = preg_replace('/[^a-zA-Z0-9._:-]+/', '_', $tenantId);
    return trim((string)$safe, '._-:');
}

function mh_grid_whm_internal_mailbox_secret_dir(string $tenantId): string
{
    $tenantSafe = mh_grid_whm_tenant_safe($tenantId);
    if ($tenantSafe === '') {
        return '';
    }
    return '/data/tenants/' . $tenantSafe . '/settlement/grid';
}

function mh_grid_whm_internal_mailbox_secret_path(string $tenantId): string
{
    $dir = mh_grid_whm_internal_mailbox_secret_dir($tenantId);
    if ($dir === '') {
        return '';
    }
    return $dir . '/internal-mailbox.json';
}

function mh_grid_whm_internal_mailbox_owner(): string
{
    $cfg = mh_grid_whm_read_cfg('onemeta');
    $owner = isset($cfg['cpanel_user']) && is_string($cfg['cpanel_user'])
        ? trim((string)$cfg['cpanel_user'])
        : '';
    return $owner !== '' ? $owner : 'onemeta';
}

function mh_grid_whm_store_internal_mailbox_secret(string $tenantId, string $email, string $password): bool
{
    $tenantId = trim($tenantId);
    $email = strtolower(trim($email));
    $password = trim($password);
    $path = mh_grid_whm_internal_mailbox_secret_path($tenantId);
    if ($tenantId === '' || $path === '' || $email === '' || $password === '') {
        return false;
    }

    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }

    $payload = [
        'email' => $email,
        'password' => mh_grid_whm_encrypt_secret($password),
        'imap_host' => 'metahumans.one',
        'imap_port' => 993,
        'imap_flags' => '/imap/ssl',
        'updated_at' => gmdate('c'),
    ];
    $ok = @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    if ($ok === false) {
        return false;
    }
    $owner = mh_grid_whm_internal_mailbox_owner();
    if ($owner !== '') {
        @chown($dir, $owner);
        @chgrp($dir, $owner);
        @chown($path, $owner);
        @chgrp($path, $owner);
    }
    @chmod($path, 0600);
    @chmod($dir, 0700);
    return true;
}

function mh_grid_whm_internal_mailbox_secret(string $tenantId): array
{
    $path = mh_grid_whm_internal_mailbox_secret_path($tenantId);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [];
    }
    $email = strtolower(trim((string)($decoded['email'] ?? '')));
    $password = trim((string)($decoded['password'] ?? ''));
    if ($email === '' || $password === '') {
        return [];
    }
    return [
        'email' => $email,
        'password' => mh_grid_decrypt_cfg_value($password),
        'imap_host' => trim((string)($decoded['imap_host'] ?? 'metahumans.one')),
        'imap_port' => (int)($decoded['imap_port'] ?? 993),
        'imap_flags' => trim((string)($decoded['imap_flags'] ?? '/imap/ssl')),
        'updated_at' => trim((string)($decoded['updated_at'] ?? '')),
    ];
}

function mh_grid_whm_internal_mailbox_automation_status(string $tenantId): array
{
    $secret = mh_grid_whm_internal_mailbox_secret($tenantId);
    if (!is_array($secret) || $secret === []) {
        return [
            'ready' => false,
            'reason' => 'internal_mailbox_secret_missing',
            'message' => 'The hidden Grid EMAIL_OTP mailbox is not yet wired for automated retrieval on this tenant. Reprovision the tenant bootstrap mailbox from /control or create the tenant again through the current Grid onboarding path.',
        ];
    }
    return [
        'ready' => true,
        'reason' => '',
        'message' => '',
        'email' => strtolower(trim((string)($secret['email'] ?? ''))),
    ];
}

function mh_grid_whm_decode_imap_part(string $body, int $encoding): string
{
    if ($body === '') {
        return '';
    }
    if ($encoding === 3) {
        $decoded = base64_decode($body, true);
        return is_string($decoded) ? $decoded : '';
    }
    if ($encoding === 4) {
        return quoted_printable_decode($body);
    }
    if ($encoding === 2 && function_exists('imap_binary')) {
        return imap_binary($body);
    }
    return $body;
}

function mh_grid_whm_collect_imap_bodies($imap, int $msgNo, object $structure, string $section = ''): array
{
    $bodies = [];
    $parts = isset($structure->parts) && is_array($structure->parts) ? $structure->parts : [];
    if ($parts === []) {
        $sectionId = $section !== '' ? $section : '1';
        $raw = (string)@imap_fetchbody($imap, $msgNo, $sectionId, FT_PEEK);
        if ($raw === '') {
            $raw = (string)@imap_body($imap, $msgNo, FT_PEEK);
        }
        $bodies[] = mh_grid_whm_decode_imap_part($raw, (int)($structure->encoding ?? 0));
        return $bodies;
    }

    foreach ($parts as $index => $part) {
        if (!is_object($part)) {
            continue;
        }
        $partNo = (string)($index + 1);
        $partSection = $section === '' ? $partNo : ($section . '.' . $partNo);
        $subtype = strtoupper(trim((string)($part->subtype ?? '')));
        if (isset($part->parts) && is_array($part->parts) && $part->parts !== []) {
            foreach (mh_grid_whm_collect_imap_bodies($imap, $msgNo, $part, $partSection) as $nestedBody) {
                if ($nestedBody !== '') {
                    $bodies[] = $nestedBody;
                }
            }
            continue;
        }
        if ($subtype !== 'PLAIN' && $subtype !== 'HTML') {
            continue;
        }
        $raw = (string)@imap_fetchbody($imap, $msgNo, $partSection, FT_PEEK);
        $decoded = mh_grid_whm_decode_imap_part($raw, (int)($part->encoding ?? 0));
        if ($decoded !== '') {
            $bodies[] = $decoded;
        }
    }

    return $bodies;
}

function mh_grid_whm_extract_otp_from_mail_text(string $body): string
{
    $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $body = strip_tags($body);
    if (preg_match('/\b(\d{6})\b/', $body, $matches)) {
        return trim((string)($matches[1] ?? ''));
    }
    return '';
}

function mh_grid_whm_mail_looks_unrelated_for_grid_otp(?object $overview): bool
{
    if (!$overview instanceof stdClass) {
        return false;
    }

    $haystack = strtolower(trim(
        (string)($overview->from ?? '') . ' ' . (string)($overview->subject ?? '')
    ));
    if ($haystack === '') {
        return false;
    }

    $negativeSignals = [
        'turnkey',
        'meta humans',
        'metahumans',
        'sign in code',
        'signin code',
        'password reset',
        'reset your password',
        'magic link',
        'verify your email',
    ];
    foreach ($negativeSignals as $signal) {
        if ($signal !== '' && str_contains($haystack, $signal)) {
            return true;
        }
    }

    return false;
}

function mh_grid_whm_mail_looks_related_for_grid_otp(?object $overview): bool
{
    if (!$overview instanceof stdClass) {
        return false;
    }

    $haystack = strtolower(trim(
        (string)($overview->from ?? '') . ' ' . (string)($overview->subject ?? '')
    ));
    if ($haystack === '') {
        return false;
    }

    $positiveSignals = [
        'lightspark',
        'grid',
    ];
    foreach ($positiveSignals as $signal) {
        if ($signal !== '' && str_contains($haystack, $signal)) {
            return true;
        }
    }

    return false;
}

function mh_grid_whm_fetch_internal_mailbox_otp(string $tenantId, int $notBeforeTs = 0): array
{
    $tenantId = trim($tenantId);
    if ($tenantId === '') {
        return ['ok' => false, 'error' => 'missing_tenant_id'];
    }
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'error' => 'imap_unavailable'];
    }

    $automation = mh_grid_whm_internal_mailbox_automation_status($tenantId);
    if (($automation['ready'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => (string)($automation['reason'] ?? 'internal_mailbox_automation_unavailable'),
            'message' => (string)($automation['message'] ?? 'Internal mailbox automation is unavailable.'),
        ];
    }

    $secret = mh_grid_whm_internal_mailbox_secret($tenantId);
    $email = strtolower(trim((string)($secret['email'] ?? '')));
    $password = trim((string)($secret['password'] ?? ''));
    $host = trim((string)($secret['imap_host'] ?? 'metahumans.one'));
    $port = (int)($secret['imap_port'] ?? 993);
    $flags = trim((string)($secret['imap_flags'] ?? '/imap/ssl'));
    if ($email === '' || $password === '' || $host === '' || $port <= 0) {
        return ['ok' => false, 'error' => 'internal_mailbox_secret_invalid'];
    }

    $mailbox = '{' . $host . ':' . $port . $flags . '}INBOX';
    $imap = @imap_open($mailbox, $email, $password, OP_READONLY);
    if ($imap === false) {
        return [
            'ok' => false,
            'error' => 'imap_open_failed',
            'message' => trim((string)implode(' | ', imap_errors() ?: [])),
        ];
    }

    // Keep the IMAP scan narrow around the current challenge so a recently old
    // Grid OTP cannot be replayed into a new bootstrap attempt.
    $searchSinceTs = $notBeforeTs > 0 ? max($notBeforeTs - 300, time() - 86400) : time() - 86400;
    $search = @imap_search($imap, 'SINCE "' . date('d-M-Y', $searchSinceTs) . '"');
    $messageIds = is_array($search) ? $search : [];
    rsort($messageIds, SORT_NUMERIC);
    $trace = [
        'email' => $email,
        'mailbox' => $mailbox,
        'search_since' => gmdate('c', $searchSinceTs),
        'not_before' => $notBeforeTs > 0 ? gmdate('c', $notBeforeTs) : '',
        'messages' => [],
    ];

    foreach ($messageIds as $msgNo) {
        $overviewRows = @imap_fetch_overview($imap, (string)$msgNo, 0);
        $overview = is_array($overviewRows) && isset($overviewRows[0]) && is_object($overviewRows[0]) ? $overviewRows[0] : null;
        $messageTs = false;
        if ($overview instanceof stdClass && isset($overview->udate)) {
            $udate = (int)$overview->udate;
            if ($udate > 0) {
                $messageTs = $udate;
            }
        }
        if ($messageTs === false && $overview && isset($overview->date)) {
            $parsed = strtotime((string)$overview->date);
            if ($parsed !== false) {
                $messageTs = (int)$parsed;
            }
        }
        $isRelated = mh_grid_whm_mail_looks_related_for_grid_otp($overview);
        $isUnrelated = mh_grid_whm_mail_looks_unrelated_for_grid_otp($overview);
        $isUnknownDate = ($messageTs === false);
        $isTooOld = $notBeforeTs > 0 && $messageTs !== false && $messageTs < ($notBeforeTs - 15);
        $traceEntry = [
            'msgNo' => $msgNo,
            'date' => $messageTs !== false ? gmdate('c', (int)$messageTs) : trim((string)($overview->date ?? '')),
            'from' => trim((string)($overview->from ?? '')),
            'subject' => trim((string)($overview->subject ?? '')),
            'related' => $isRelated,
            'unrelated' => $isUnrelated,
            'unknown_date' => $isUnknownDate,
            'too_old' => $isTooOld,
            'otp_found' => false,
        ];
        if (count($trace['messages']) < 12) {
            $trace['messages'][] = $traceEntry;
        }
        if (!$isRelated) {
            continue;
        }
        if ($isUnrelated) {
            continue;
        }
        if ($isUnknownDate) {
            continue;
        }
        if ($isTooOld) {
            continue;
        }
        $structure = @imap_fetchstructure($imap, $msgNo);
        $bodies = $structure instanceof stdClass
            ? mh_grid_whm_collect_imap_bodies($imap, $msgNo, $structure)
            : [(string)@imap_body($imap, $msgNo, FT_PEEK)];
        foreach ($bodies as $body) {
            $otp = mh_grid_whm_extract_otp_from_mail_text((string)$body);
            if ($otp !== '') {
                if (!empty($trace['messages'])) {
                    $trace['messages'][array_key_last($trace['messages'])]['otp_found'] = true;
                }
                @imap_close($imap);
                return [
                    'ok' => true,
                    'found' => true,
                    'otp' => $otp,
                    'email' => $email,
                    'message_date' => $messageTs !== false ? gmdate('c', (int)$messageTs) : '',
                    'trace' => $trace,
                ];
            }
        }
    }

    @imap_close($imap);
    return [
        'ok' => true,
        'found' => false,
        'email' => $email,
        'trace' => $trace,
    ];
}

function mh_grid_whm_collect_messages($value): array
{
    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? [] : [$trimmed];
    }
    if (!is_array($value)) {
        return [];
    }

    $messages = [];
    foreach ($value as $item) {
        if (is_string($item)) {
            $trimmed = trim($item);
            if ($trimmed !== '') {
                $messages[] = $trimmed;
            }
            continue;
        }
        if (is_array($item)) {
            foreach ($item as $nested) {
                if (!is_string($nested)) {
                    continue;
                }
                $trimmed = trim($nested);
                if ($trimmed !== '') {
                    $messages[] = $trimmed;
                }
            }
        }
    }

    return $messages;
}

function mh_grid_whm_request(array $cfg, string $function, array $query = []): array
{
    $baseUrl = trim((string)($cfg['base_url'] ?? ''));
    $authHeader = trim((string)($cfg['auth_header'] ?? ''));
    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'whm_base_url_missing'];
    }
    if ($authHeader === '') {
        return ['ok' => false, 'error' => 'whm_auth_header_missing'];
    }

    $requestQuery = ['api.version' => '1'];
    foreach ($query as $key => $value) {
        if (!is_string($key) || $key === '' || $value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            continue;
        }
        $requestQuery[$key] = $value;
    }

    $url = $baseUrl . '/json-api/' . rawurlencode($function);
    $qs = http_build_query($requestQuery, '', '&', PHP_QUERY_RFC3986);
    if ($qs !== '') {
        $url .= '?' . $qs;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'whm_curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $authHeader,
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($resp)) {
        return ['ok' => false, 'error' => 'whm_curl_failed', 'detail' => $err];
    }

    $rawHeaders = $hdrSize > 0 ? substr($resp, 0, $hdrSize) : '';
    $rawBody = $hdrSize > 0 ? substr($resp, $hdrSize) : $resp;
    $json = null;
    $trimmedBody = trim($rawBody);
    if ($trimmedBody !== '' && ($trimmedBody[0] === '{' || $trimmedBody[0] === '[')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'headers_raw' => $rawHeaders,
        'body_raw' => $rawBody,
        'json' => $json,
    ];
}

function mh_grid_cpanel_uapi_request(array $cfg, string $module, string $function, array $query = []): array
{
    $baseUrl = trim((string)($cfg['base_url'] ?? ''));
    $authHeader = trim((string)($cfg['auth_header'] ?? ''));
    if ($baseUrl === '') {
        return ['ok' => false, 'error' => 'cpanel_base_url_missing'];
    }
    if ($authHeader === '') {
        return ['ok' => false, 'error' => 'cpanel_auth_header_missing'];
    }

    $requestQuery = [];
    foreach ($query as $key => $value) {
        if (!is_string($key) || $key === '' || $value === null) {
            continue;
        }
        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_int($value) || is_float($value)) {
            $value = (string)$value;
        }
        if (!is_string($value)) {
            continue;
        }
        $requestQuery[$key] = $value;
    }

    $url = $baseUrl . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function);
    $qs = http_build_query($requestQuery, '', '&', PHP_QUERY_RFC3986);
    if ($qs !== '') {
        $url .= '?' . $qs;
    }

    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'cpanel_curl_init_failed'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $authHeader,
        ],
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HEADER => true,
    ]);

    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    $hdrSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if (!is_string($resp)) {
        return ['ok' => false, 'error' => 'cpanel_curl_failed', 'detail' => $err];
    }

    $rawHeaders = $hdrSize > 0 ? substr($resp, 0, $hdrSize) : '';
    $rawBody = $hdrSize > 0 ? substr($resp, $hdrSize) : $resp;
    $json = null;
    $trimmedBody = trim($rawBody);
    if ($trimmedBody !== '' && ($trimmedBody[0] === '{' || $trimmedBody[0] === '[')) {
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            $json = $decoded;
        }
    }

    return [
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'headers_raw' => $rawHeaders,
        'body_raw' => $rawBody,
        'json' => $json,
    ];
}

function mh_grid_whm_api1(array $cfg, string $function, array $args = []): array
{
    $resp = mh_grid_whm_request($cfg, $function, $args);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    $json = $resp['json'] ?? null;
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'whm_invalid_json', 'detail' => $resp];
    }

    $metadata = isset($json['metadata']) && is_array($json['metadata']) ? $json['metadata'] : [];
    $messages = array_merge(
        mh_grid_whm_collect_messages($metadata['reason'] ?? null),
        mh_grid_whm_collect_messages($metadata['warnings'] ?? null),
        mh_grid_whm_collect_messages($metadata['output'] ?? null)
    );

    if (array_key_exists('result', $metadata) && (int)($metadata['result'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'error' => 'whm_api1_failed',
            'detail' => $resp,
            'metadata' => $metadata,
            'messages' => $messages,
        ];
    }

    return [
        'ok' => true,
        'status' => (int)($resp['status'] ?? 0),
        'data' => $json['data'] ?? null,
        'metadata' => $metadata,
        'messages' => $messages,
        'detail' => $resp,
    ];
}

function mh_grid_whm_match_zone(array $cfg, string $domain): string
{
    $domain = strtolower(ltrim(trim($domain), '@'));
    if ($domain === '') {
        return '';
    }

    $matches = [];
    $allowedZones = isset($cfg['allowed_zones']) && is_array($cfg['allowed_zones']) ? $cfg['allowed_zones'] : [];
    foreach ($allowedZones as $zone) {
        if (!is_string($zone)) {
            continue;
        }
        $zone = strtolower(ltrim(trim($zone), '@'));
        if ($zone === '') {
            continue;
        }
        if ($domain === $zone || str_ends_with($domain, '.' . $zone)) {
            $matches[] = $zone;
        }
    }
    if ($matches === []) {
        return '';
    }

    usort($matches, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });
    return $matches[0];
}

function mh_grid_whm_lookup_account_by_domain(array $cfg, string $domain): array
{
    $domain = strtolower(ltrim(trim($domain), '@'));
    if ($domain === '') {
        return ['ok' => false, 'error' => 'missing_domain'];
    }

    $resp = mh_grid_whm_api1($cfg, 'listaccts', [
        'searchtype' => 'domain',
        'search' => $domain,
    ]);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    $accounts = isset($resp['data']['acct']) && is_array($resp['data']['acct']) ? $resp['data']['acct'] : [];
    foreach ($accounts as $account) {
        if (!is_array($account)) {
            continue;
        }
        $accountDomain = strtolower(trim((string)($account['domain'] ?? '')));
        if ($accountDomain === $domain) {
            return [
                'ok' => true,
                'account' => $account,
                'detail' => $resp,
            ];
        }
    }

    return [
        'ok' => false,
        'error' => 'whm_account_not_found',
        'domain' => $domain,
        'detail' => $resp,
    ];
}

function mh_grid_whm_resolve_cpanel_user(array $cfg, string $domain = ''): string
{
    $explicit = strtolower(trim((string)($cfg['cpanel_user'] ?? '')));
    if ($explicit !== '') {
        return $explicit;
    }

    $domain = strtolower(ltrim(trim($domain), '@'));
    if ($domain === '') {
        return '';
    }

    $zone = mh_grid_whm_match_zone($cfg, $domain);
    if ($zone === '') {
        $zone = $domain;
    }
    $lookup = mh_grid_whm_lookup_account_by_domain($cfg, $zone);
    if (($lookup['ok'] ?? false) !== true) {
        return '';
    }

    $user = strtolower(trim((string)(($lookup['account'] ?? [])['user'] ?? '')));
    return $user;
}

function mh_grid_whm_uapi_cpanel(array $cfg, string $module, string $function, array $args = [], ?string $cpanelUser = null): array
{
    if (($cfg['profile'] ?? '') === 'onemeta') {
        $resp = mh_grid_cpanel_uapi_request($cfg, $module, $function, $args);
        if (($resp['ok'] ?? false) !== true) {
            return $resp;
        }

        $json = $resp['json'] ?? null;
        if (!is_array($json)) {
            return ['ok' => false, 'error' => 'cpanel_invalid_json', 'detail' => $resp];
        }

        $messages = array_merge(
            mh_grid_whm_collect_messages($json['errors'] ?? null),
            mh_grid_whm_collect_messages($json['warnings'] ?? null),
            mh_grid_whm_collect_messages($json['messages'] ?? null)
        );

        if ((int)($json['status'] ?? 0) !== 1) {
            return [
                'ok' => false,
                'error' => 'cpanel_uapi_failed',
                'detail' => $resp,
                'uapi' => $json,
                'messages' => $messages,
            ];
        }

        return [
            'ok' => true,
            'status' => (int)($resp['status'] ?? 0),
            'uapi' => $json,
            'messages' => $messages,
            'detail' => $resp,
        ];
    }

    $cpanelUser = strtolower(trim((string)($cpanelUser ?? ($cfg['cpanel_user'] ?? ''))));
    if ($cpanelUser === '') {
        return ['ok' => false, 'error' => 'whm_cpanel_user_missing'];
    }

    $query = [
        'cpanel.user' => $cpanelUser,
        'cpanel.module' => $module,
        'cpanel.function' => $function,
    ];
    foreach ($args as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        $query[$key] = $value;
    }

    $resp = mh_grid_whm_request($cfg, 'uapi_cpanel', $query);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    $json = $resp['json'] ?? null;
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'whm_invalid_json', 'detail' => $resp];
    }

    $meta = isset($json['metadata']) && is_array($json['metadata']) ? $json['metadata'] : [];
    if ((int)($meta['result'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'error' => 'whm_uapi_bridge_failed',
            'detail' => $resp,
            'metadata' => $meta,
        ];
    }

    $uapi = isset($json['data']['uapi']) && is_array($json['data']['uapi']) ? $json['data']['uapi'] : null;
    if (!is_array($uapi)) {
        return ['ok' => false, 'error' => 'whm_uapi_missing', 'detail' => $resp];
    }

    $messages = array_merge(
        mh_grid_whm_collect_messages($uapi['errors'] ?? null),
        mh_grid_whm_collect_messages($uapi['warnings'] ?? null),
        mh_grid_whm_collect_messages($uapi['messages'] ?? null)
    );

    if ((int)($uapi['status'] ?? 0) !== 1) {
        return [
            'ok' => false,
            'error' => 'whm_uapi_failed',
            'detail' => $resp,
            'uapi' => $uapi,
            'messages' => $messages,
        ];
    }

    return [
        'ok' => true,
        'status' => (int)($resp['status'] ?? 0),
        'uapi' => $uapi,
        'messages' => $messages,
        'detail' => $resp,
    ];
}

function mh_grid_whm_generate_account_password(): string
{
    return mh_grid_whm_generate_mailbox_password();
}

function mh_grid_whm_create_account(string $domain, string $username, ?string $password = null, array $options = []): array
{
    $cfg = mh_grid_whm_read_cfg('root');
    $domain = strtolower(ltrim(trim($domain), '@'));
    $username = strtolower(trim($username));
    $password = is_string($password) ? trim($password) : '';
    if ($domain === '' || $username === '') {
        return ['ok' => false, 'error' => 'invalid_account_payload'];
    }
    if ($password === '') {
        $password = mh_grid_whm_generate_account_password();
    }

    $args = [
        'domain' => $domain,
        'username' => $username,
        'password' => $password,
    ];

    $contactEmail = trim((string)($options['contactemail'] ?? $cfg['default_contact_email'] ?? ''));
    if ($contactEmail !== '') {
        $args['contactemail'] = $contactEmail;
    }
    $plan = trim((string)($options['plan'] ?? $cfg['default_package'] ?? ''));
    if ($plan !== '') {
        $args['plan'] = $plan;
    }
    $owner = trim((string)($options['owner'] ?? $cfg['account_owner'] ?? ''));
    if ($owner !== '') {
        $args['owner'] = $owner;
    }
    $theme = trim((string)($options['theme'] ?? $cfg['default_theme'] ?? ''));
    if ($theme !== '') {
        $args['theme'] = $theme;
    }
    $locale = trim((string)($options['locale'] ?? $cfg['default_locale'] ?? ''));
    if ($locale !== '') {
        $args['locale'] = $locale;
    }

    foreach (['pkgname', 'featurelist', 'hascgi', 'hasshell', 'ip', 'max_emailacct_quota'] as $key) {
        if (!array_key_exists($key, $options)) {
            continue;
        }
        $value = $options[$key];
        if (is_bool($value)) {
            $args[$key] = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $args[$key] = (string)$value;
        }
    }

    $resp = mh_grid_whm_api1($cfg, 'createacct', $args);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    return [
        'ok' => true,
        'status' => 'created',
        'domain' => $domain,
        'username' => $username,
        'detail' => $resp,
    ];
}

function mh_grid_whm_create_subdomain(string $fqdn, array $options = []): array
{
    $cfg = mh_grid_whm_read_cfg((string)($options['config_profile'] ?? 'root'));
    $fqdn = strtolower(ltrim(trim($fqdn), '@'));
    if ($fqdn === '' || !str_contains($fqdn, '.')) {
        return ['ok' => false, 'error' => 'invalid_subdomain'];
    }

    $zone = mh_grid_whm_match_zone($cfg, $fqdn);
    if ($zone === '') {
        return [
            'ok' => false,
            'error' => 'whm_domain_not_allowed',
            'domain' => $fqdn,
            'allowed_zones' => $cfg['allowed_zones'] ?? [],
        ];
    }

    $subLabel = substr($fqdn, 0, -1 * (strlen($zone) + 1));
    $subLabel = trim($subLabel, '.');
    if ($subLabel === '') {
        return ['ok' => false, 'error' => 'invalid_subdomain_label', 'domain' => $fqdn];
    }

    $cpanelUser = trim((string)($options['cpanel_user'] ?? ''));
    if ($cpanelUser === '') {
        $cpanelUser = mh_grid_whm_resolve_cpanel_user($cfg, $zone);
    }
    if ($cpanelUser === '') {
        return ['ok' => false, 'error' => 'whm_cpanel_user_missing', 'domain' => $fqdn, 'zone' => $zone];
    }

    $documentRoot = trim((string)($options['document_root'] ?? ''));
    if ($documentRoot === '') {
        $documentRoot = 'public_html/' . str_replace('.', '/', $subLabel);
    }

    $resp = mh_grid_whm_uapi_cpanel($cfg, 'SubDomain', 'addsubdomain', [
        'domain' => $subLabel,
        'rootdomain' => $zone,
        'dir' => $documentRoot,
        'canoff' => 1,
    ], $cpanelUser);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    return [
        'ok' => true,
        'status' => 'created',
        'domain' => $fqdn,
        'zone' => $zone,
        'cpanel_user' => $cpanelUser,
        'document_root' => $documentRoot,
        'detail' => $resp,
    ];
}

function mh_grid_whm_create_email_account(string $email, ?string $password = null, array $options = []): array
{
    $cfg = mh_grid_whm_read_cfg((string)($options['config_profile'] ?? 'onemeta'));
    $email = strtolower(trim($email));
    $password = is_string($password) ? trim($password) : '';
    $tenantId = isset($options['tenant_id']) ? trim((string)$options['tenant_id']) : '';
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email', 'email' => $email];
    }

    [$localPart, $domain] = explode('@', $email, 2);
    if (!mh_grid_whm_mailbox_domain_allowed($cfg, $domain)) {
        return [
            'ok' => false,
            'error' => 'whm_domain_not_allowed',
            'email' => $email,
            'domain' => $domain,
            'allowed_zones' => $cfg['allowed_zones'] ?? [],
        ];
    }

    $cpanelUser = trim((string)($options['cpanel_user'] ?? ''));
    if ($cpanelUser === '') {
        $cpanelUser = mh_grid_whm_resolve_cpanel_user($cfg, $domain);
    }
    if ($cpanelUser === '') {
        return ['ok' => false, 'error' => 'whm_cpanel_user_missing', 'email' => $email, 'domain' => $domain];
    }

    $lookup = mh_grid_whm_mailbox_lookup(array_merge($cfg, ['cpanel_user' => $cpanelUser]), $email);
    if (($lookup['ok'] ?? false) === true && ($lookup['exists'] ?? false) === true) {
        $secretStored = false;
        if ($tenantId !== '') {
            $existingSecret = mh_grid_whm_internal_mailbox_secret($tenantId);
            $existingSecretEmail = strtolower(trim((string)($existingSecret['email'] ?? '')));
            $existingSecretPassword = trim((string)($existingSecret['password'] ?? ''));

            if ($password !== '') {
                $secretStored = mh_grid_whm_store_internal_mailbox_secret($tenantId, $email, $password);
            } elseif ($existingSecretEmail === $email && $existingSecretPassword !== '') {
                $secretStored = true;
            } else {
                $password = mh_grid_whm_generate_mailbox_password();
                $reset = mh_grid_whm_update_email_account_password($email, $password, [
                    'config_profile' => (string)($options['config_profile'] ?? 'onemeta'),
                    'cpanel_user' => $cpanelUser,
                ]);
                if (($reset['ok'] ?? false) !== true) {
                    return [
                        'ok' => false,
                        'error' => 'whm_mailbox_secret_backfill_failed',
                        'email' => $email,
                        'cpanel_user' => $cpanelUser,
                        'detail' => $reset,
                    ];
                }
                $secretStored = mh_grid_whm_store_internal_mailbox_secret($tenantId, $email, $password);
            }
        }
        return [
            'ok' => true,
            'status' => 'exists',
            'email' => $email,
            'cpanel_user' => $cpanelUser,
            'secret_stored' => $secretStored,
            'detail' => $lookup,
        ];
    }
    if (($lookup['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'whm_mailbox_lookup_failed',
            'email' => $email,
            'cpanel_user' => $cpanelUser,
            'detail' => $lookup,
        ];
    }

    if ($password === '') {
        $password = mh_grid_whm_generate_mailbox_password();
    }

    $args = [
        'email' => $localPart,
        'domain' => $domain,
        'password' => $password,
        'send_welcome_email' => 0,
        'skip_update_db' => 0,
    ];
    if (array_key_exists('quota', $options) && is_scalar($options['quota'])) {
        $args['quota'] = (string)$options['quota'];
    }

    $create = mh_grid_whm_uapi_cpanel($cfg, 'Email', 'add_pop', $args, $cpanelUser);
    if (($create['ok'] ?? false) !== true) {
        $messages = $create['messages'] ?? [];
        if (is_array($messages)) {
            foreach ($messages as $message) {
                if (!is_string($message)) {
                    continue;
                }
                if (stripos($message, 'already exists') !== false) {
                    return [
                        'ok' => true,
                        'status' => 'exists',
                        'email' => $email,
                        'cpanel_user' => $cpanelUser,
                        'detail' => $create,
                    ];
                }
            }
        }
        return [
            'ok' => false,
            'error' => 'whm_mailbox_create_failed',
            'email' => $email,
            'cpanel_user' => $cpanelUser,
            'detail' => $create,
        ];
    }

    $secretStored = false;
    if ($tenantId !== '') {
        $secretStored = mh_grid_whm_store_internal_mailbox_secret($tenantId, $email, $password);
    }

    return [
        'ok' => true,
        'status' => 'created',
        'email' => $email,
        'cpanel_user' => $cpanelUser,
        'secret_stored' => $secretStored,
        'detail' => $create,
    ];
}

function mh_grid_whm_mailbox_domain_allowed(array $cfg, string $domain): bool
{
    $normalized = strtolower(ltrim(trim($domain), '@'));
    if ($normalized === '') {
        return false;
    }
    $allowedZones = isset($cfg['allowed_zones']) && is_array($cfg['allowed_zones']) ? $cfg['allowed_zones'] : [];
    if ($allowedZones === []) {
        return true;
    }
    return in_array($normalized, $allowedZones, true);
}

function mh_grid_whm_mailbox_address_from_row(array $row, string $fallbackDomain): string
{
    $email = trim(strtolower((string)($row['email'] ?? '')));
    if ($email !== '') {
        if (str_contains($email, '@')) {
            return $email;
        }
        if ($fallbackDomain !== '') {
            return $email . '@' . $fallbackDomain;
        }
    }

    $login = trim(strtolower((string)($row['login'] ?? $row['user'] ?? '')));
    $domain = trim(strtolower((string)($row['domain'] ?? $fallbackDomain)));
    if ($login !== '' && $domain !== '') {
        return $login . '@' . $domain;
    }

    return '';
}

function mh_grid_whm_mailbox_lookup(array $cfg, string $email): array
{
    $normalizedEmail = strtolower(trim($email));
    if (!str_contains($normalizedEmail, '@')) {
        return ['ok' => false, 'error' => 'invalid_email'];
    }

    [$localPart, $domain] = explode('@', $normalizedEmail, 2);
    if ($localPart === '' || $domain === '') {
        return ['ok' => false, 'error' => 'invalid_email'];
    }

    $resp = mh_grid_whm_uapi_cpanel($cfg, 'Email', 'list_pops', [
        'skip_main' => 1,
        'no_validate' => 1,
    ]);
    if (($resp['ok'] ?? false) !== true) {
        return $resp;
    }

    $rows = isset($resp['uapi']['data']) && is_array($resp['uapi']['data']) ? $resp['uapi']['data'] : [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $candidate = mh_grid_whm_mailbox_address_from_row($row, $domain);
        if ($candidate === $normalizedEmail) {
            return ['ok' => true, 'exists' => true, 'row' => $row, 'detail' => $resp];
        }
    }

    return ['ok' => true, 'exists' => false, 'detail' => $resp];
}

function mh_grid_whm_generate_mailbox_password(): string
{
    try {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    } catch (Throwable $e) {
        return substr(hash('sha256', uniqid('grid-mailbox-', true)), 0, 32);
    }
}

function mh_grid_whm_update_email_account_password(string $email, string $password, array $options = []): array
{
    $cfg = mh_grid_whm_read_cfg((string)($options['config_profile'] ?? 'onemeta'));
    $email = strtolower(trim($email));
    $password = trim($password);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email', 'email' => $email];
    }
    if ($password === '') {
        return ['ok' => false, 'error' => 'missing_password', 'email' => $email];
    }

    [$localPart, $domain] = explode('@', $email, 2);
    if (!mh_grid_whm_mailbox_domain_allowed($cfg, $domain)) {
        return [
            'ok' => false,
            'error' => 'whm_domain_not_allowed',
            'email' => $email,
            'domain' => $domain,
            'allowed_zones' => $cfg['allowed_zones'] ?? [],
        ];
    }

    $cpanelUser = trim((string)($options['cpanel_user'] ?? ''));
    if ($cpanelUser === '') {
        $cpanelUser = mh_grid_whm_resolve_cpanel_user($cfg, $domain);
    }
    if ($cpanelUser === '') {
        return ['ok' => false, 'error' => 'whm_cpanel_user_missing', 'email' => $email, 'domain' => $domain];
    }

    $resp = mh_grid_whm_uapi_cpanel($cfg, 'Email', 'passwd_pop', [
        'email' => $localPart,
        'domain' => $domain,
        'password' => $password,
        'skip_update_db' => 0,
    ], $cpanelUser);
    if (($resp['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'error' => 'whm_mailbox_password_update_failed',
            'email' => $email,
            'cpanel_user' => $cpanelUser,
            'detail' => $resp,
        ];
    }

    return [
        'ok' => true,
        'status' => 'updated',
        'email' => $email,
        'cpanel_user' => $cpanelUser,
        'detail' => $resp,
    ];
}

function mh_grid_email_is_internal_address(string $email): bool
{
    $normalizedEmail = strtolower(trim($email));
    if (!str_contains($normalizedEmail, '@')) {
        return false;
    }
    $internalDomain = function_exists('mh_grid_internal_email_domain')
        ? strtolower(ltrim(trim(mh_grid_internal_email_domain()), '@'))
        : '';
    if ($internalDomain === '') {
        return false;
    }
    return str_ends_with($normalizedEmail, '@' . $internalDomain);
}

function mh_grid_whm_ensure_internal_mailbox_for_email(string $email, string $tenantId = ''): array
{
    $email = trim($email);
    $tenantId = trim($tenantId);
    if ($email === '') {
        return ['ok' => false, 'error' => 'missing_email'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'invalid_email', 'email' => $email];
    }
    if (!mh_grid_email_is_internal_address($email)) {
        return ['ok' => true, 'status' => 'skipped_non_internal', 'email' => $email];
    }

    $cfg = mh_grid_whm_read_cfg('onemeta');
    $normalizedEmail = strtolower($email);
    [$localPart, $domain] = explode('@', $normalizedEmail, 2);

    if (!mh_grid_whm_mailbox_domain_allowed($cfg, $domain)) {
        return [
            'ok' => false,
            'error' => 'whm_domain_not_allowed',
            'email' => $normalizedEmail,
            'domain' => $domain,
            'allowed_zones' => $cfg['allowed_zones'] ?? [],
        ];
    }

    return mh_grid_whm_create_email_account($normalizedEmail, null, [
        'tenant_id' => $tenantId,
    ]);
}
