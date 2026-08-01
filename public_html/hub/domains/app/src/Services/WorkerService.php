<?php

declare(strict_types=1);

namespace App\Services;

use App\Application;
use App\Domain\Provider\Contracts\DomainMutationInterface;
use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Provider\Contracts\PricingSyncInterface;
use App\Domain\Sync\SyncContext;
use App\Infrastructure\Persistence\DomainRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\PricingRepository;
use App\Infrastructure\Persistence\ProviderAccountRepository;
use App\Infrastructure\Persistence\TaskQueueRepository;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class WorkerService
{
    public function __construct(
        private readonly Application $app,
        private readonly TaskQueueRepository $taskQueueRepository,
        private readonly OrderRepository $orderRepository,
        private readonly DomainRepository $domainRepository,
        private readonly ProviderAccountRepository $providerAccountRepository,
        private readonly PricingRepository $pricingRepository,
        private readonly CronMatcher $cronMatcher,
        private readonly array $jobConfig,
    ) {
    }

    public function run(): void
    {
        $pollSeconds = max(1, $this->app->config()->int('WORKER_POLL_SECONDS', 5));
        $runOnce = $this->app->config()->bool('WORKER_RUN_ONCE', false);

        fwrite(STDOUT, sprintf("Worker started (poll=%ds)\n", $pollSeconds));

        do {
            $this->scheduleRecurringTasks();
            $task = $this->taskQueueRepository->claimNext();

            if ($task === null) {
                if ($runOnce) {
                    fwrite(STDOUT, "No queued tasks found.\n");
                    break;
                }

                sleep($pollSeconds);
                continue;
            }

            $this->handleTask($task);

            if ($runOnce) {
                break;
            }
        } while (true);
    }

    /**
     * @param array<string, mixed> $task
     */
    private function handleTask(array $task): void
    {
        try {
            $payload = $this->decodeJson((string) ($task['payload_json'] ?? '{}'));
            $result = match ($task['task_type']) {
                'submit_domain_registration' => $this->processDomainRegistration($payload),
                'sync_pricing' => $this->processPricingSync($payload),
                'sync_domain_dates' => $this->processDomainDateSync($payload),
                'sync_domain_portfolio' => $this->processDomainPortfolioSync($payload),
                'retry_failed_sync_runs' => ['retried' => $this->taskQueueRepository->retryFailedTasks()],
                default => throw new RuntimeException(sprintf('Unknown task type "%s".', $task['task_type'])),
            };

            $this->taskQueueRepository->markCompleted((string) $task['id'], $result);
            fwrite(STDOUT, sprintf("Completed task %s (%s)\n", $task['id'], $task['task_type']));
        } catch (Throwable $throwable) {
            $attempts = (int) ($task['attempts'] ?? 0);
            $maxAttempts = (int) ($task['max_attempts'] ?? 1);
            $shouldRetry = $attempts < $maxAttempts;
            $this->taskQueueRepository->markFailed(
                (string) $task['id'],
                $throwable->getMessage(),
                ['exception' => $throwable::class],
                $shouldRetry,
            );
            fwrite(STDERR, sprintf("Task %s failed: %s\n", $task['id'], $throwable->getMessage()));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function processDomainRegistration(array $payload): array
    {
        $tenantOrderRepository = $this->tenantOrderRepository($payload);
        $tenantDomainRepository = $this->tenantDomainRepository($payload);
        $orderId = (string) ($payload['order_id'] ?? '');
        $order = $tenantOrderRepository->findWithRelations($orderId);

        if ($order === null) {
            throw new RuntimeException(sprintf('Order %s not found.', $orderId));
        }

        $tenantOrderRepository->markProcessing($orderId);
        $providerCode = (string) ($order['provider_code'] ?? $payload['provider_code'] ?? '');
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainMutationInterface) {
            throw new RuntimeException(sprintf('Provider %s cannot submit registrations.', $providerCode));
        }

        $requestPayload = $this->decodeJson((string) ($order['payload_json'] ?? '{}'));
        $response = $provider->registerDomain($requestPayload);

        if (! ($response['ok'] ?? false)) {
            $error = (string) ($response['message'] ?? 'Provider registration failed.');
            $tenantOrderRepository->markFailed($orderId, $error, $response);
            $tenantDomainRepository->markFailed((string) $order['domain_id'], $error);
            throw new RuntimeException($error);
        }

        $result = $response + ['provider' => $providerCode];
        if ($provider instanceof DomainPortfolioSyncInterface) {
            $sync = $provider->syncDomain((string) $order['domain_name'], new SyncContext($providerCode, 'worker-registration'));
            if (($sync['ok'] ?? false) === true) {
                $result = array_replace_recursive($result, $sync);
                $result['registration_response'] = $response;
                $result['post_registration_sync'] = $sync;
            } else {
                $result['post_registration_sync'] = $sync;
            }
        }

        $tenantOrderRepository->markCompleted($orderId, $result);
        $tenantDomainRepository->markRegistered((string) $order['domain_id'], $result);

        return [
            'order_id' => $orderId,
            'domain_name' => $order['domain_name'],
            'provider' => $providerCode,
            'provider_response' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function processPricingSync(array $payload): array
    {
        $providerCode = (string) ($payload['provider_code'] ?? 'coza');
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof PricingSyncInterface) {
            throw new RuntimeException(sprintf('Provider %s cannot sync pricing.', $providerCode));
        }

        $providerAccount = $this->providerAccountRepository->getOrCreate(
            $providerCode,
            $providerCode === 'coza' ? '.co.za' : 'NetEarthOne',
            $provider::class,
        );

        $context = new SyncContext($providerCode, 'worker', true);
        $count = 0;
        foreach ($provider->syncPricing($context) as $snapshot) {
            if (! is_array($snapshot) || ! isset($snapshot['tld'])) {
                continue;
            }

            $tld = $this->pricingRepository->upsertTld(
                (string) $snapshot['tld'],
                $providerCode,
                (string) ($snapshot['currency_code'] ?? 'ZAR'),
            );
            $this->pricingRepository->addSnapshot($tld['id'], $providerAccount['id'], $snapshot);
            $count++;
        }

        return ['provider' => $providerCode, 'snapshots_saved' => $count];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function processDomainDateSync(array $payload): array
    {
        $tenantDomainRepository = $this->tenantDomainRepository($payload);
        $providerCode = (string) ($payload['provider_code'] ?? 'coza');
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainPortfolioSyncInterface) {
            throw new RuntimeException(sprintf('Provider %s cannot sync domain dates.', $providerCode));
        }

        $providerAccount = $this->providerAccountRepository->getOrCreate(
            $providerCode,
            $providerCode === 'coza' ? '.co.za' : 'NetEarthOne',
            $provider::class,
        );

        $count = 0;
        foreach ($tenantDomainRepository->listByProvider($providerAccount['id']) as $domain) {
            $sync = $provider->syncDomain((string) $domain['domain_name'], new SyncContext($providerCode, 'worker'));
            if (($sync['ok'] ?? true) === false) {
                continue;
            }

            $tenantDomainRepository->updateFromSync((string) $domain['id'], $sync);
            $count++;
        }

        return ['provider' => $providerCode, 'domains_synced' => $count];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function processDomainPortfolioSync(array $payload): array
    {
        $providerCode = (string) ($payload['provider_code'] ?? 'coza');
        $provider = $this->app->provider($providerCode);
        if (! $provider instanceof DomainPortfolioSyncInterface) {
            return ['provider' => $providerCode, 'status' => 'skipped', 'message' => 'Provider does not expose portfolio sync.'];
        }

        $context = new SyncContext($providerCode, 'worker', true);
        $seen = 0;
        try {
            foreach ($provider->listDomains($context) as $domain) {
                unset($domain);
                $seen++;
            }
        } catch (Throwable $throwable) {
            return [
                'provider' => $providerCode,
                'status' => 'skipped',
                'message' => $throwable->getMessage(),
            ];
        }

        return ['provider' => $providerCode, 'domains_seen' => $seen];
    }

    private function scheduleRecurringTasks(): void
    {
        $now = new DateTimeImmutable('now');
        $minuteKey = $now->format('YmdHi');

        foreach (($this->jobConfig['jobs'] ?? []) as $job) {
            $expression = (string) ($job['schedule'] ?? 'manual');
            if (! $this->cronMatcher->isDue($expression, $now)) {
                continue;
            }

            foreach (($job['providers'] ?? ['coza']) as $providerCode) {
                if (! is_string($providerCode)) {
                    continue;
                }

                $uniqueKey = sprintf('schedule:%s:%s:%s', $job['code'], $providerCode, $minuteKey);
                $this->taskQueueRepository->enqueue(
                    (string) $job['code'],
                    (string) ($job['queue'] ?? 'default'),
                    array_replace($this->app->tenantContext(), ['provider_code' => $providerCode]),
                    priority: 10,
                    maxAttempts: 3,
                    uniqueKey: $uniqueKey,
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tenantDomainRepository(array $payload): DomainRepository
    {
        return new DomainRepository($this->app->tenantDatabase($this->tenantDbConfigId($payload)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tenantOrderRepository(array $payload): OrderRepository
    {
        return new OrderRepository($this->app->tenantDatabase($this->tenantDbConfigId($payload)));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function tenantDbConfigId(array $payload): ?string
    {
        $configId = $payload['tenant_db_config_id'] ?? null;

        return is_string($configId) && $configId !== '' ? $configId : null;
    }
}
