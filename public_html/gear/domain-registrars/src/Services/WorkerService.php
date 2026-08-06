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
        $disableScheduler = $this->app->config()->bool('WORKER_DISABLE_SCHEDULER', ! $this->app->config()->bool('ENABLE_SCHEDULER', true));

        fwrite(STDOUT, sprintf("Worker started (poll=%ds, scheduler=%s)\n", $pollSeconds, $disableScheduler ? 'disabled' : 'enabled'));

        do {
            if (! $disableScheduler) {
                $this->scheduleRecurringTasks();
            }
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

        try {
            $providerAccount = $this->app->providerAccount($providerCode);
        } catch (Throwable $throwable) {
            return [
                'provider' => $providerCode,
                'status' => 'skipped',
                'message' => 'Provider account missing: ' . $throwable->getMessage(),
            ];
        }
        $providerAccountId = (string) ($providerAccount['id'] ?? '');
        if ($providerAccountId === '') {
            return [
                'provider' => $providerCode,
                'status' => 'skipped',
                'message' => 'No provider_accounts row found for ' . $providerCode . '.',
            ];
        }

        $context = new SyncContext($providerCode, 'worker', true);
        $seen = 0;
        $inserted = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        try {
            foreach ($provider->listDomains($context) as $row) {
                if (! is_array($row) || ! isset($row['domain_name'])) {
                    ++$skipped;
                    continue;
                }
                ++$seen;
                $domainName = strtolower(trim((string) $row['domain_name']));
                if ($domainName === '') {
                    ++$skipped;
                    continue;
                }
                try {
                    $existing = $this->domainRepository->findByName($domainName);
                    $syncPayload = [
                        'tenant_id' => (string) ($row['tenant_id'] ?? ('registrar:' . $providerCode)),
                        'owner_type' => (string) ($row['owner_type'] ?? 'registrar'),
                        'owner_id' => (string) ($row['owner_id'] ?? ('pool:' . $providerCode)),
                        'billing_mode' => (string) ($row['billing_mode'] ?? 'registrar'),
                        'billing_tenant_id' => (string) ($row['billing_tenant_id'] ?? ('registrar:' . $providerCode)),
                        'provider_code' => $providerCode,
                        'registrar_status' => (string) ($row['registrar_status'] ?? 'active'),
                        'tld' => (string) ($row['tld'] ?? (str_contains($domainName, '.') ? substr($domainName, strpos($domainName, '.') + 1) : $domainName)),
                        'customer_id' => (string) ($row['customer_id'] ?? ''),
                        'registered_at' => $row['registered_at'] ?? null,
                        'expires_at' => $row['expires_at'] ?? null,
                        'renewal_due_at' => $row['renewal_due_at'] ?? ($row['expires_at'] ?? null),
                        'grace_period_ends_at' => $row['grace_period_ends_at'] ?? null,
                        'redemption_period_ends_at' => $row['redemption_period_ends_at'] ?? null,
                        'auto_renew_enabled' => $row['auto_renew_enabled'] ?? null,
                        'registrant' => $row['registrant'] ?? null,
                        'autorenew' => $row['auto_renew_enabled'] ?? null,
                        'contacts' => $row['contacts'] ?? null,
                    ];
                    $saved = $this->domainRepository->upsertImportedDomain(
                        $providerAccountId,
                        $providerCode,
                        $domainName,
                        $syncPayload,
                    );
                    if (isset($row['upstream_domain_id']) || isset($row['upstream_order_id']) || isset($row['raw']) || $existing === null) {
                        $updates = [];
                        $params = ['id' => (string) ($saved['id'] ?? '')];
                        if (isset($row['upstream_domain_id']) && trim((string) $row['upstream_domain_id']) !== '') {
                            $updates[] = 'upstream_domain_id = :upstream_domain_id';
                            $params['upstream_domain_id'] = trim((string) $row['upstream_domain_id']);
                        }
                        if (isset($row['upstream_order_id']) && trim((string) $row['upstream_order_id']) !== '') {
                            $updates[] = 'upstream_order_id = :upstream_order_id';
                            $params['upstream_order_id'] = trim((string) $row['upstream_order_id']);
                        }
                        if (isset($row['raw']) || $existing === null) {
                            $rowMeta = $existing ?? ['id' => $params['id']];
                            $previous = [];
                            if (isset($rowMeta['metadata_json'])) {
                                if (is_string($rowMeta['metadata_json']) && trim((string) $rowMeta['metadata_json']) !== '') {
                                    $decoded = json_decode((string) $rowMeta['metadata_json'], true);
                                    if (is_array($decoded)) {
                                        $previous = $decoded;
                                    }
                                } elseif (is_array($rowMeta['metadata_json'])) {
                                    $previous = $rowMeta['metadata_json'];
                                }
                            }
                            $merged = array_replace_recursive($previous, [
                                'portfolio_sync' => [
                                    'provider' => $providerCode,
                                    'sync_time' => date('c'),
                                    'raw' => $row['raw'] ?? null,
                                ],
                            ]);
                            $updates[] = 'metadata_json = :metadata_json';
                            $params['metadata_json'] = json_encode($merged, JSON_UNESCAPED_SLASHES) ?: '{}';
                            $updates[] = 'last_synced_at = CURRENT_TIMESTAMP';
                            $updates[] = 'last_sync_source = :last_sync_source';
                            $params['last_sync_source'] = 'worker:' . $providerCode;
                        }
                        if ($updates !== [] && $params['id'] !== '') {
                            $this->domainRepository->updateFields($params['id'], $updates, $params);
                        }
                    }
                    if ($existing === null) {
                        ++$inserted;
                    } else {
                        ++$updated;
                    }
                } catch (Throwable $t) {
                    ++$skipped;
                    $errors[] = sprintf('%s: %s', $domainName, $t->getMessage());
                    if (count($errors) >= 50) {
                        break;
                    }
                }
                if ($seen >= 50000) {
                    break;
                }
            }
        } catch (Throwable $throwable) {
            return [
                'provider' => $providerCode,
                'status' => 'error',
                'domains_seen' => $seen,
                'inserted' => $inserted,
                'updated' => $updated,
                'skipped' => $skipped,
                'message' => $throwable->getMessage(),
                'errors' => array_slice($errors, 0, 25),
            ];
        }

        return [
            'provider' => $providerCode,
            'domains_seen' => $seen,
            'inserted' => $inserted,
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 25),
            'error_count' => count($errors),
        ];
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
