<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Provider\Contracts\RegistrarProviderInterface;
use App\Domain\Sync\SyncContext;
use App\Jobs\Contracts\SyncJobInterface;
use RuntimeException;

final class SyncDomainDatesJob implements SyncJobInterface
{
    /**
     * @param list<string> $domainNames
     */
    public function __construct(
        private readonly RegistrarProviderInterface $provider,
        private readonly SyncContext $context,
        private readonly array $domainNames,
    ) {
    }

    public function code(): string
    {
        return 'sync_domain_dates';
    }

    public function queue(): string
    {
        return 'dates';
    }

    public function handle(): array
    {
        if (! $this->provider instanceof DomainPortfolioSyncInterface) {
            throw new RuntimeException('Provider does not support domain date sync.');
        }

        $recordsSeen = 0;

        foreach ($this->domainNames as $domainName) {
            $recordsSeen++;

            $this->provider->syncDomain($domainName, $this->context);
        }

        return [
            'job' => $this->code(),
            'provider' => $this->provider->code(),
            'records_seen' => $recordsSeen,
        ];
    }
}
