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
        $cacheKey = $configIdOverride ?? '__default__';

        return $this->tenantDatabases[$cacheKey] ??= new Database(
            $configIdOverride === null
                ? ConnectionFactory::tenant($this->config())
                : ConnectionFactory::tenantByConfigId($this->config(), $configIdOverride),
        );
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
        return match ($code) {
            'coza' => new CoZaProvider(
                new EppClient(
                    host: $this->config()->string('COZA_HOST'),
                    port: $this->config()->int('COZA_PORT', 700),
                    username: $this->config()->string('COZA_USERNAME'),
                    password: $this->config()->string('COZA_PASSWORD'),
                    clientId: $this->config()->string('COZA_CLIENT_ID', $this->config()->string('COZA_USERNAME')),
                    certificatePath: $this->config()->nullableString('COZA_CERT_PATH'),
                    certificatePassphrase: $this->config()->nullableString('COZA_CERT_PASSPHRASE'),
                    caFile: $this->config()->nullableString('COZA_CA_FILE'),
                    verifyPeer: $this->config()->bool('COZA_VERIFY_PEER', true),
                    timeoutSeconds: $this->config()->int('COZA_TIMEOUT', 30),
                    objectUris: $this->config()->csv('COZA_LOGIN_OBJECT_URIS'),
                    extensionUris: $this->config()->csv('COZA_LOGIN_EXTENSION_URIS'),
                ),
                $this->rootPath . '/config/pricing/coza.sample.json',
                $this->config()->nullableString('COZA_PRICING_JSON'),
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

}
