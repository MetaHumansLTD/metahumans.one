<?php

declare(strict_types=1);

namespace App;

use App\Config\AppConfig;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Database\SchemaLoader;
use App\Infrastructure\Epp\EppClient;
use App\Infrastructure\Persistence\CustomerRepository;
use App\Infrastructure\Persistence\DashboardRepository;
use App\Infrastructure\Persistence\DomainRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\PricingRepository;
use App\Infrastructure\Persistence\ProviderAccountRepository;
use App\Infrastructure\Persistence\TaskQueueRepository;
use App\Infrastructure\Providers\CoZaProvider;
use App\Infrastructure\Providers\NetEarthOneApiClient;
use App\Infrastructure\Providers\NetEarthOneProvider;
use App\Services\CronMatcher;
use App\Services\CueTenantSchemaProvisioner;
use App\Services\OrderService;
use App\Services\PlatformTenantContextResolver;
use App\Services\WorkerService;
use RuntimeException;

final class Application
{
    private ?AppConfig $config = null;
    private ?Database $sharedDatabase = null;
    /**
     * @var array<string, Database>
     */
    private array $tenantDatabases = [];
    /**
     * @var array<string, bool>
     */
    private array $tenantSchemaLoaded = [];
    private ?ProviderAccountRepository $providerAccountRepository = null;
    private ?CustomerRepository $customerRepository = null;
    private ?DomainRepository $domainRepository = null;
    private ?OrderRepository $orderRepository = null;
    private ?TaskQueueRepository $taskQueueRepository = null;
    private ?DashboardRepository $dashboardRepository = null;
    private ?PricingRepository $pricingRepository = null;
    private ?OrderService $orderService = null;
    private ?WorkerService $workerService = null;
    private ?PlatformTenantContextResolver $platformTenantContextResolver = null;
    private ?CueTenantSchemaProvisioner $cueTenantSchemaProvisioner = null;

    public function __construct(
        private readonly string $rootPath,
    ) {
    }

    public function rootPath(): string
    {
        return $this->rootPath;
    }

    public function projectRootPath(): string
    {
        return dirname($this->rootPath, 3);
    }

    public function config(): AppConfig
    {
        return $this->config ??= new AppConfig();
    }

    public function sharedDatabase(): Database
    {
        return $this->sharedDatabase ??= new Database(ConnectionFactory::shared($this->config()));
    }

    public function tenantDatabase(?string $configIdOverride = null): Database
    {
        $resolvedConfigId = $configIdOverride ?? $this->resolvedTenantDbConfigId();
        $cacheKey = $resolvedConfigId ?? '__default__';

        $database = $this->tenantDatabases[$cacheKey] ??= new Database(
            $resolvedConfigId === null
                ? ConnectionFactory::tenant($this->config())
                : ConnectionFactory::tenantByConfigId($this->config(), $resolvedConfigId),
        );

        if (!($this->tenantSchemaLoaded[$cacheKey] ?? false)) {
            (new SchemaLoader($database, $this->rootPath . '/database/tenant-schema.sql'))->load();
            $this->tenantSchemaLoaded[$cacheKey] = true;
        }

        return $database;
    }

    public function database(): Database
    {
        return $this->tenantDatabase();
    }

    public function sharedSchemaLoader(): SchemaLoader
    {
        return new SchemaLoader($this->sharedDatabase(), $this->rootPath . '/database/shared-schema.sql');
    }

    public function tenantSchemaLoader(): SchemaLoader
    {
        return new SchemaLoader($this->tenantDatabase(), $this->rootPath . '/database/tenant-schema.sql');
    }

    public function providerAccountRepository(): ProviderAccountRepository
    {
        return $this->providerAccountRepository ??= new ProviderAccountRepository($this->sharedDatabase());
    }

    public function providerAccount(string $code): array
    {
        $metadata = $this->providerAccountMetadata($code);

        return $this->providerAccountRepository()->getOrCreate(
            $code,
            $metadata['display_name'],
            $metadata['driver_class'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function providerStoredConfig(string $code): array
    {
        return $this->providerAccountRepository()->decodeConfig($this->providerAccount($code));
    }

    /**
     * @return array<string, mixed>
     */
    public function providerEffectiveConfig(string $code): array
    {
        return match ($code) {
            'coza' => $this->cozaEffectiveConfig(),
            'netearthone' => $this->netearthoneEffectiveConfig(),
            default => [],
        };
    }

    /**
     * Return non-sensitive provider diagnostics that reflect the effective runtime
     * configuration handed into the provider client.
     *
     * @return array<string, mixed>
     */
    public function providerRuntimeDiagnostics(string $code): array
    {
        $stored = $this->providerStoredConfig($code);
        $effective = $this->providerEffectiveConfig($code);

        return match ($code) {
            'coza' => [
                'provider' => 'coza',
                'stored_override' => [
                    'cert_path' => $this->nullableConfigString($stored['cert_path'] ?? null),
                    'ca_file' => $this->nullableConfigString($stored['ca_file'] ?? null),
                    'verify_peer' => array_key_exists('verify_peer', $stored) ? (bool) $stored['verify_peer'] : null,
                    'timeout' => array_key_exists('timeout', $stored) ? (int) $stored['timeout'] : null,
                    'pricing_json' => $this->nullableConfigString($stored['pricing_json'] ?? null),
                ],
                'effective' => [
                    'host' => $this->nullableConfigString($effective['host'] ?? null),
                    'port' => (int) ($effective['port'] ?? 700),
                    'username_present' => $this->nullableConfigString($effective['username'] ?? null) !== null,
                    'password_present' => $this->nullableConfigString($effective['password'] ?? null) !== null,
                    'client_id_present' => $this->nullableConfigString($effective['client_id'] ?? null) !== null,
                    'cert_path' => $this->nullableConfigString($effective['cert_path'] ?? null),
                    'ca_file' => $this->nullableConfigString($effective['ca_file'] ?? null),
                    'verify_peer' => (bool) ($effective['verify_peer'] ?? true),
                    'timeout' => (int) ($effective['timeout'] ?? 30),
                    'login_object_uris' => is_array($effective['login_object_uris'] ?? null) ? array_values($effective['login_object_uris']) : [],
                    'login_extension_uris' => is_array($effective['login_extension_uris'] ?? null) ? array_values($effective['login_extension_uris']) : [],
                ],
                'resolved' => $this->resolvedFileDiagnostics($effective),
            ],
            'netearthone' => [
                'provider' => 'netearthone',
                'stored_override' => [
                    'api_base_url' => $this->nullableConfigString($stored['api_base_url'] ?? null),
                    'auth_user_id_present' => $this->nullableConfigString($stored['auth_user_id'] ?? null) !== null,
                    'ip_address' => $this->nullableConfigString($stored['ip_address'] ?? null),
                    'api_key_present' => $this->nullableConfigString($stored['api_key'] ?? null) !== null,
                    'timeout' => array_key_exists('timeout', $stored) ? (int) $stored['timeout'] : null,
                    'pricing_json' => $this->nullableConfigString($stored['pricing_json'] ?? null),
                    'default_customer_id_present' => $this->nullableConfigString($stored['default_customer_id'] ?? null) !== null,
                    'default_invoice_option' => $this->nullableConfigString($stored['default_invoice_option'] ?? null),
                ],
                'effective' => [
                    'api_base_url' => $this->nullableConfigString($effective['api_base_url'] ?? null),
                    'auth_user_id_present' => $this->nullableConfigString($effective['auth_user_id'] ?? null) !== null,
                    'ip_address' => $this->nullableConfigString($effective['ip_address'] ?? null),
                    'api_key_present' => $this->nullableConfigString($effective['api_key'] ?? null) !== null,
                    'timeout' => (int) ($effective['timeout'] ?? 30),
                    'pricing_json' => $this->nullableConfigString($effective['pricing_json'] ?? null),
                    'default_customer_id_present' => $this->nullableConfigString($effective['default_customer_id'] ?? null) !== null,
                    'default_invoice_option' => $this->nullableConfigString($effective['default_invoice_option'] ?? 'NoInvoice'),
                ],
            ],
            default => [],
        };
    }

    public function customerRepository(): CustomerRepository
    {
        return $this->customerRepository ??= new CustomerRepository($this->tenantDatabase());
    }

    public function domainRepository(): DomainRepository
    {
        return $this->domainRepository ??= new DomainRepository($this->tenantDatabase());
    }

    public function orderRepository(): OrderRepository
    {
        return $this->orderRepository ??= new OrderRepository($this->tenantDatabase());
    }

    public function taskQueueRepository(): TaskQueueRepository
    {
        return $this->taskQueueRepository ??= new TaskQueueRepository($this->sharedDatabase());
    }

    public function dashboardRepository(): DashboardRepository
    {
        return $this->dashboardRepository ??= new DashboardRepository(
            $this->tenantDatabase(),
            $this->sharedDatabase(),
        );
    }

    public function pricingRepository(): PricingRepository
    {
        return $this->pricingRepository ??= new PricingRepository($this->sharedDatabase());
    }

    public function orderService(): OrderService
    {
        return $this->orderService ??= new OrderService(
            $this->tenantDatabase(),
            $this->customerRepository(),
            $this->providerAccountRepository(),
            $this->domainRepository(),
            $this->orderRepository(),
            $this->taskQueueRepository(),
        );
    }

    public function workerService(): WorkerService
    {
        return $this->workerService ??= new WorkerService(
            $this,
            $this->taskQueueRepository(),
            $this->orderRepository(),
            $this->domainRepository(),
            $this->providerAccountRepository(),
            $this->pricingRepository(),
            new CronMatcher(),
            require $this->rootPath . '/config/sync-jobs.php',
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function tenantContext(): array
    {
        return $this->platformTenantContextResolver()->resolve();
    }

    public function cueTenantSchemaProvisioner(): CueTenantSchemaProvisioner
    {
        return $this->cueTenantSchemaProvisioner ??= new CueTenantSchemaProvisioner($this);
    }

    public function platformTenantContextResolver(): PlatformTenantContextResolver
    {
        return $this->platformTenantContextResolver ??= new PlatformTenantContextResolver($this->config());
    }

    public function provider(string $code): object
    {
        $cozaConfig = $code === 'coza' ? $this->providerEffectiveConfig('coza') : [];
        $neoConfig = $code === 'netearthone' ? $this->providerEffectiveConfig('netearthone') : [];

        return match ($code) {
            'coza' => new CoZaProvider(
                new EppClient(
                    host: (string) ($cozaConfig['host'] ?? ''),
                    port: (int) ($cozaConfig['port'] ?? 700),
                    username: (string) ($cozaConfig['username'] ?? ''),
                    password: (string) ($cozaConfig['password'] ?? ''),
                    clientId: (string) ($cozaConfig['client_id'] ?? ''),
                    certificatePath: $this->resolveWorkspacePath($this->nullableConfigString($cozaConfig['cert_path'] ?? null)),
                    certificatePassphrase: $this->nullableConfigString($cozaConfig['cert_passphrase'] ?? null),
                    caFile: $this->resolveWorkspacePath($this->nullableConfigString($cozaConfig['ca_file'] ?? null)),
                    verifyPeer: (bool) ($cozaConfig['verify_peer'] ?? true),
                    timeoutSeconds: (int) ($cozaConfig['timeout'] ?? 30),
                    objectUris: is_array($cozaConfig['login_object_uris'] ?? null) ? $cozaConfig['login_object_uris'] : [],
                    extensionUris: is_array($cozaConfig['login_extension_uris'] ?? null) ? $cozaConfig['login_extension_uris'] : [],
                ),
                $this->rootPath . '/config/pricing/coza.sample.json',
                $this->resolveWorkspacePath($this->nullableConfigString($cozaConfig['pricing_json'] ?? null)),
            ),
            'netearthone' => new NetEarthOneProvider(
                new NetEarthOneApiClient(
                    baseUrl: (string) ($neoConfig['api_base_url'] ?? ''),
                    authUserId: (string) ($neoConfig['auth_user_id'] ?? ''),
                    apiKey: (string) ($neoConfig['api_key'] ?? ''),
                    timeoutSeconds: (int) ($neoConfig['timeout'] ?? 30),
                ),
                $this->rootPath . '/config/pricing/netearthone.sample.json',
                $this->resolveWorkspacePath($this->nullableConfigString($neoConfig['pricing_json'] ?? null)),
                $this->nullableConfigString($neoConfig['default_customer_id'] ?? null),
                (string) ($neoConfig['default_invoice_option'] ?? 'NoInvoice'),
            ),
            default => throw new RuntimeException(sprintf('Unknown provider "%s".', $code)),
        };
    }

    private function sessionValue(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array{display_name: string, driver_class: string}
     */
    private function providerAccountMetadata(string $code): array
    {
        return match ($code) {
            'coza' => [
                'display_name' => '.co.za',
                'driver_class' => CoZaProvider::class,
            ],
            'netearthone' => [
                'display_name' => 'NetEarthOne',
                'driver_class' => NetEarthOneProvider::class,
            ],
            default => throw new RuntimeException(sprintf('Unknown provider "%s".', $code)),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function cozaEffectiveConfig(): array
    {
        $stored = $this->providerStoredConfig('coza');
        $defaultObjectUris = [
            'urn:ietf:params:xml:ns:domain-1.0',
            'urn:ietf:params:xml:ns:contact-1.0',
            'urn:ietf:params:xml:ns:host-1.0',
        ];

        $verifyPeerRaw = $this->firstEnvString([
            'COZA_VERIFY_PEER',
            'COZA_SSL_VERIFY_PEER',
        ]) ?? $this->firstEnvString(['VERIFY_PEER', 'SSL_VERIFY_PEER']);
        $loginObjectUrisCsv = $this->firstEnvString([
            'COZA_LOGIN_OBJECT_URIS',
        ]);
        $loginExtensionUrisCsv = $this->firstEnvString([
            'COZA_LOGIN_EXTENSION_URIS',
        ]);
        $loginObjectUris = is_string($loginObjectUrisCsv) && trim($loginObjectUrisCsv) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $loginObjectUrisCsv)), static fn ($v) => $v !== ''))
            : [];
        $loginExtensionUris = is_string($loginExtensionUrisCsv) && trim($loginExtensionUrisCsv) !== ''
            ? array_values(array_filter(array_map('trim', explode(',', $loginExtensionUrisCsv)), static fn ($v) => $v !== ''))
            : [];

        $resolved = array_replace(
            [
                'host' => $this->firstEnvString([
                    'COZA_HOST',
                    'COZA_EPP_HOST',
                    'COZA_EPP_SERVER',
                    'COZA_SERVER',
                    'EPP_HOST',
                ]) ?? $this->firstEnvString(['HOST', 'SERVER', 'EPP_SERVER']),
                'port' => (int) ($this->firstEnvString([
                    'COZA_PORT',
                    'COZA_EPP_PORT',
                    'EPP_PORT',
                ]) ?? '700') ?: 700,
                'username' => $this->firstEnvString([
                    'COZA_USERNAME',
                    'COZA_EPP_USERNAME',
                    'COZA_USER',
                    'COZA_LOGIN',
                ]) ?? $this->firstEnvString(['USERNAME', 'USER', 'LOGIN', 'EPP_USERNAME']),
                'password' => $this->firstEnvString([
                    'COZA_PASSWORD',
                    'COZA_EPP_PASSWORD',
                    'COZA_PASS',
                    'COZA_SECRET',
                ]) ?? $this->firstEnvString(['PASSWORD', 'PASS', 'SECRET', 'EPP_PASSWORD']),
                'client_id' => $this->firstEnvString([
                    'COZA_CLIENT_ID',
                    'COZA_CLIENTID',
                    'COZA_EPP_CLIENT_ID',
                ]) ?? $this->firstEnvString(['CLIENT_ID', 'CLIENTID', 'EPP_CLIENT_ID']),
                'cert_path' => $this->firstEnvString([
                    'COZA_CERT_PATH',
                    'COZA_CERTIFICATE_PATH',
                    'COZA_CLIENT_CERT',
                    'COZA_PEM_PATH',
                ]) ?? $this->firstEnvString(['CERT_PATH', 'CERTIFICATE_PATH', 'CLIENT_CERT', 'PEM_PATH']),
                'cert_passphrase' => $this->firstEnvString([
                    'COZA_CERT_PASSPHRASE',
                    'COZA_CERT_PASSWORD',
                    'COZA_PASSPHRASE',
                ]) ?? $this->firstEnvString(['CERT_PASSPHRASE', 'CERT_PASSWORD', 'PASSPHRASE']),
                'ca_file' => $this->firstEnvString([
                    'COZA_CA_FILE',
                    'COZA_CA_CERT',
                    'COZA_CA_BUNDLE',
                ]) ?? $this->firstEnvString(['CA_FILE', 'CA_CERT', 'CA_BUNDLE']),
                'verify_peer' => is_string($verifyPeerRaw) && in_array(strtolower($verifyPeerRaw), ['0', 'false', 'no', 'off'], true) ? false : true,
                'timeout' => (int) ($this->firstEnvString([
                    'COZA_TIMEOUT',
                    'COZA_EPP_TIMEOUT',
                ]) ?? '30') ?: 30,
                'login_object_uris' => $loginObjectUris,
                'login_extension_uris' => $loginExtensionUris,
                'pricing_json' => $this->firstEnvString([
                    'COZA_PRICING_JSON',
                    'COZA_PRICING_JSON_PATH',
                ]) ?? $this->firstEnvString(['PRICING_JSON', 'PRICING_JSON_PATH']),
            ],
            array_intersect_key($stored, array_flip([
                'verify_peer',
                'timeout',
                'cert_path',
                'ca_file',
                'pricing_json',
            ])),
        );

        if (! is_array($resolved['login_object_uris'] ?? null) || $resolved['login_object_uris'] === []) {
            $resolved['login_object_uris'] = $defaultObjectUris;
        }

        if (! is_array($resolved['login_extension_uris'] ?? null)) {
            $resolved['login_extension_uris'] = [];
        }

        $resolved['client_id'] = $this->nullableConfigString($resolved['client_id'] ?? null)
            ?? $this->nullableConfigString($resolved['username'] ?? null);

        return $resolved;
    }

    /**
     * @param list<string> $candidates
     */
    private function firstEnvString(array $candidates): ?string
    {
        foreach ($candidates as $key) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }
            $value = $this->config()->nullableString($key);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function netearthoneEffectiveConfig(): array
    {
        return [
            'api_base_url' => $this->firstEnvString([
                'NETEARTHONE_API_BASE_URL',
                'NETEARTHONE_BASE_URL',
                'NETEARTHONE_ENDPOINT',
                'NEO_API_BASE_URL',
                'NEO_BASE_URL',
                'HTTPAPI_BASE_URL',
            ]) ?? $this->firstEnvString(['API_BASE_URL', 'BASE_URL', 'ENDPOINT']),
            'auth_user_id' => $this->firstEnvString([
                'NETEARTHONE_AUTH_USER_ID',
                'NETEARTHONE_USER_ID',
                'NETEARTHONE_RESELLER_ID',
                'NETEARTHONE_RESELLERID',
                'NETEARTHONE_RESSELLER_ID',
                'NETEARTHONE_RESSELLERID',
                'NEO_RESELLER_ID',
                'NEO_AUTH_USER_ID',
                'NEO_USER_ID',
            ]) ?? $this->firstEnvString(['RESELLER_ID', 'RESELLERID', 'RESSELLER_ID', 'RESSELLERID', 'AUTH_USER_ID', 'USER_ID']),
            'api_key' => $this->firstEnvString([
                'NETEARTHONE_API_KEY',
                'NETEARTHONE_APIKEY',
                'NETEARTHONE_PASSWORD',
                'NETEARTHONE_AUTH_KEY',
                'NEO_API_KEY',
                'NEO_APIKEY',
                'NEO_PASSWORD',
            ]) ?? $this->firstEnvString(['API_KEY', 'APIKEY', 'PASSWORD', 'AUTH_KEY', 'SECRET']),
            'ip_address' => $this->firstEnvString([
                'NETEARTHONE_IP_ADDRESS',
                'NETEARTHONE_IP',
                'NETEARTHONE_WHITELIST_IP',
                'NEO_IP_ADDRESS',
                'NEO_IP',
            ]) ?? $this->firstEnvString(['IP_ADDRESS', 'IP', 'WHITELIST_IP', 'CLIENT_IP']),
            'timeout' => (int) ($this->firstEnvString([
                'NETEARTHONE_TIMEOUT',
                'NEO_TIMEOUT',
            ]) ?? '30') ?: 30,
            'pricing_json' => $this->firstEnvString([
                'NETEARTHONE_PRICING_JSON',
                'NEO_PRICING_JSON',
            ]) ?? $this->firstEnvString(['PRICING_JSON', 'PRICING_JSON_PATH']),
            'default_customer_id' => $this->firstEnvString([
                'NETEARTHONE_DEFAULT_CUSTOMER_ID',
                'NETEARTHONE_CUSTOMER_ID',
                'NETEARTHONE_SUBCUSTOMER_ID',
                'NEO_DEFAULT_CUSTOMER_ID',
                'NEO_CUSTOMER_ID',
                'NEO_SUBCUSTOMER_ID',
            ]) ?? $this->firstEnvString(['DEFAULT_CUSTOMER_ID', 'CUSTOMER_ID', 'SUBCUSTOMER_ID']),
            'default_invoice_option' => $this->firstEnvString([
                'NETEARTHONE_DEFAULT_INVOICE_OPTION',
                'NETEARTHONE_INVOICE_OPTION',
                'NEO_DEFAULT_INVOICE_OPTION',
                'NEO_INVOICE_OPTION',
            ]) ?? $this->firstEnvString(['DEFAULT_INVOICE_OPTION', 'INVOICE_OPTION']) ?? 'NoInvoice',
        ];
    }

    private function resolvedTenantDbConfigId(): ?string
    {
        $context = $this->tenantContext();

        return $this->nullableConfigString($context['tenant_db_config_id'] ?? null)
            ?? $this->config()->nullableString('TENANT_DB_CONFIG_ID');
    }

    private function resolveWorkspacePath(?string $path): ?string
    {
        $trimmed = $this->nullableConfigString($path);
        if ($trimmed === null) {
            return null;
        }

        if ($this->isAbsolutePath($trimmed)) {
            return $trimmed;
        }

        return $this->projectRootPath() . '/' . ltrim(str_replace('\\', '/', $trimmed), '/');
    }

    private function nullableConfigString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function isAbsolutePath(string $path): bool
    {
        return preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/');
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function resolvedFileDiagnostics(array $config): array
    {
        $certPath = $this->resolveWorkspacePath($this->nullableConfigString($config['cert_path'] ?? null));
        $caFile = $this->resolveWorkspacePath($this->nullableConfigString($config['ca_file'] ?? null));
        $pricingJson = $this->resolveWorkspacePath($this->nullableConfigString($config['pricing_json'] ?? null));

        return [
            'cert_path' => $certPath,
            'cert_exists' => $certPath !== null ? is_file($certPath) : null,
            'ca_file' => $caFile,
            'ca_exists' => $caFile !== null ? is_file($caFile) : null,
            'pricing_json' => $pricingJson,
            'pricing_exists' => $pricingJson !== null ? is_file($pricingJson) : null,
        ];
    }

}
