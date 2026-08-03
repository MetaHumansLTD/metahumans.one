<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Provider\Contracts\RegistrarProviderInterface;
use App\Domain\Sync\SyncContext;
use App\Jobs\Contracts\SyncJobInterface;
use RuntimeException;

final class ImportProviderDomainsJob implements SyncJobInterface
{
    public function __construct(
        private readonly RegistrarProviderInterface $provider,
        private readonly SyncContext $context,
    ) {
    }

    public function code(): string
    {
        return 'import_provider_domains';
    }

    public function queue(): string
    {
        return 'imports';
    }

    public function handle(): array
    {
        if (! $this->provider instanceof DomainPortfolioSyncInterface) {
            throw new RuntimeException('Provider does not support domain portfolio imports.');
        }

        $recordsSeen = 0;

        foreach ($this->provider->listDomains($this->context) as $domain) {
            $recordsSeen++;

            // Persist or reconcile the normalized domain payload here.
            unset($domain);
        }

        return [
            'job' => $this->code(),
            'provider' => $this->provider->code(),
            'records_seen' => $recordsSeen,
        ];
    }
}
