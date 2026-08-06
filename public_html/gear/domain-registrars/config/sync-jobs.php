<?php

declare(strict_types=1);

return [
    'jobs' => [
        [
            'code' => 'import_provider_domains',
            'handler' => \App\Jobs\ImportProviderDomainsJob::class,
            'queue' => 'imports',
            'schedule' => 'manual',
            'providers' => ['coza', 'netearthone'],
            'notes' => 'Initial or forced portfolio import from an upstream account.',
        ],
        [
            'code' => 'sync_domain_portfolio',
            'handler' => \App\Jobs\SyncDomainPortfolioJob::class,
            'queue' => 'sync',
            'schedule' => '0 */6 * * *',
            'providers' => ['coza', 'netearthone'],
            'notes' => 'Refresh domain list, status, nameservers, and transfer state for implemented providers.',
        ],
        [
            'code' => 'sync_domain_dates',
            'handler' => \App\Jobs\SyncDomainDatesJob::class,
            'queue' => 'dates',
            'schedule' => '15 2 * * *',
            'providers' => ['coza', 'netearthone'],
            'notes' => 'Refresh expiry, renewal due, grace, and redemption dates for implemented providers.',
        ],
        [
            'code' => 'sync_pricing',
            'handler' => \App\Jobs\SyncPricingJob::class,
            'queue' => 'pricing',
            'schedule' => '0 3 * * *',
            'providers' => ['coza', 'netearthone'],
            'notes' => 'Refresh upstream base pricing and trigger local price recalculation for implemented providers.',
        ],
        [
            'code' => 'retry_failed_sync_runs',
            'handler' => null,
            'queue' => 'retries',
            'schedule' => '*/30 * * * *',
            'providers' => ['coza', 'netearthone'],
            'notes' => 'Requeue failed sync runs after the configured backoff policy.',
        ],
    ],
];
