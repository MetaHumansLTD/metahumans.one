<?php
declare(strict_types=1);

function mh_grid_passkeys_environment_from_platform_config(array $platformConfig): string
{
    $signals = [
        isset($platformConfig['umaDomain']) ? (string)$platformConfig['umaDomain'] : '',
        isset($platformConfig['proxyUmaSubdomain']) ? (string)$platformConfig['proxyUmaSubdomain'] : '',
        isset($platformConfig['environment']) ? (string)$platformConfig['environment'] : '',
    ];

    foreach ($signals as $signal) {
        $signal = strtolower(trim($signal));
        if ($signal !== '' && str_contains($signal, 'sandbox')) {
            return 'sandbox';
        }
    }

    return 'production';
}

function mh_grid_passkeys_registration_policy(array $platformConfig, bool $debugMode = false): array
{
    $environment = mh_grid_passkeys_environment_from_platform_config($platformConfig);
    $sandboxOtpShortcut = isset($_GET['sandbox_otp_shortcut']) ? trim((string)$_GET['sandbox_otp_shortcut']) : '';
    $allowSandboxOtpShortcut = $debugMode
        && $environment === 'sandbox'
        && $sandboxOtpShortcut === '1';

    return [
        'environment' => $environment,
        'allowSandboxOtpShortcut' => $allowSandboxOtpShortcut,
        'autoCompletePendingSignature' => false,
        'showManualRetryUi' => $debugMode,
    ];
}
