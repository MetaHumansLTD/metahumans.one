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
                    baseUrl: $this->config()->string('NETEARTHONE_API_BASE_URL'),
                    authUserId: $this->config()->string('NETEARTHONE_AUTH_USER_ID'),
                    apiKey: $this->config()->string('NETEARTHONE_API_KEY'),
                    timeoutSeconds: $this->config()->int('NETEARTHONE_TIMEOUT', 30),
                ),
                $this->rootPath . '/config/pricing/netearthone.sample.json',
                $this->config()->nullableString('NETEARTHONE_PRICING_JSON'),
                $this->config()->nullableString('NETEARTHONE_DEFAULT_CUSTOMER_ID'),
                $this->config()->string('NETEARTHONE_DEFAULT_INVOICE_OPTION', 'NoInvoice'),
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

        $resolved = array_replace(
            [
                'host' => $this->config()->nullableString('COZA_HOST'),
                'port' => $this->config()->int('COZA_PORT', 700),
                'username' => $this->config()->nullableString('COZA_USERNAME'),
                'password' => $this->config()->nullableString('COZA_PASSWORD'),
                'client_id' => $this->config()->nullableString('COZA_CLIENT_ID') ?? $this->config()->nullableString('COZA_USERNAME'),
                'cert_path' => $this->config()->nullableString('COZA_CERT_PATH'),
                'cert_passphrase' => $this->config()->nullableString('COZA_CERT_PASSPHRASE'),
                'ca_file' => $this->config()->nullableString('COZA_CA_FILE'),
                'verify_peer' => $this->config()->bool('COZA_VERIFY_PEER', true),
                'timeout' => $this->config()->int('COZA_TIMEOUT', 30),
                'login_object_uris' => $this->config()->csv('COZA_LOGIN_OBJECT_URIS'),
                'login_extension_uris' => $this->config()->csv('COZA_LOGIN_EXTENSION_URIS'),
                'pricing_json' => $this->config()->nullableString('COZA_PRICING_JSON'),
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

}
