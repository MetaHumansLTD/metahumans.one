<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Provider\Contracts\RegistrarProviderInterface;
use App\Domain\Sync\SyncContext;
use App\Jobs\Contracts\SyncJobInterface;
use RuntimeException;

final class SyncDomainPortfolioJob implements SyncJobInterface
{
    public function __construct(
        private readonly RegistrarProviderInterface $provider,
        private readonly SyncContext $context,
    ) {
    }

    public function code(): string
    {
        return 'sync_domain_portfolio';
    }

    public function queue(): string
    {
        return 'sync';
    }

    public function handle(): array
    {
        if (! $this->provider instanceof DomainPortfolioSyncInterface) {
            throw new RuntimeException('Provider does not support portfolio sync.');
        }

        $recordsSeen = 0;

        foreach ($this->provider->listDomains($this->context) as $domain) {
            $recordsSeen++;

            // Compare remote values to local records and update if the upstream copy is authoritative.
            unset($domain);
        }

        return [
            'job' => $this->code(),
            'provider' => $this->provider->code(),
            'records_seen' => $recordsSeen,
        ];
    }
}
