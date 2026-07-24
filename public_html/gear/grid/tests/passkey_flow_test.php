<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/passkey_flow.php';

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . "\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$sandboxPolicy = mh_grid_passkeys_registration_policy([
    'proxyUmaSubdomain' => 'metahumansltdsandbox',
    'umaDomain' => 'metahumansltdsandbox.umaaas.uma.money',
], false);

assert_same('sandbox', $sandboxPolicy['environment'], 'Sandbox config should be detected as sandbox.');
assert_same(true, $sandboxPolicy['autoCompletePendingSignature'], 'Sandbox config should auto-complete pending signatures.');
assert_same(false, $sandboxPolicy['showManualRetryUi'], 'Manual retry UI should stay hidden for normal users.');

$productionPolicy = mh_grid_passkeys_registration_policy([
    'proxyUmaSubdomain' => 'metahumansltd',
    'umaDomain' => 'metahumansltd.umaaas.uma.money',
], false);

assert_same('production', $productionPolicy['environment'], 'Production config should be detected as production.');
assert_same(false, $productionPolicy['autoCompletePendingSignature'], 'Production config should not auto-complete sandbox signatures.');
assert_same(false, $productionPolicy['showManualRetryUi'], 'Manual retry UI should stay hidden for normal users in production.');

$debugPolicy = mh_grid_passkeys_registration_policy([
    'proxyUmaSubdomain' => 'metahumansltd',
], true);

assert_same(true, $debugPolicy['showManualRetryUi'], 'Debug mode should expose manual retry UI.');

echo "passkey_flow_test: ok\n";
