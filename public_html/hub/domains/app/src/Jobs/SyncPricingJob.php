<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Provider\Contracts\PricingSyncInterface;
use App\Domain\Provider\Contracts\RegistrarProviderInterface;
use App\Domain\Sync\SyncContext;
use App\Jobs\Contracts\SyncJobInterface;
use RuntimeException;

final class SyncPricingJob implements SyncJobInterface
{
    public function __construct(
        private readonly RegistrarProviderInterface $provider,
        private readonly SyncContext $context,
    ) {
    }

    public function code(): string
    {
        return 'sync_pricing';
    }

    public function queue(): string
    {
        return 'pricing';
    }

    public function handle(): array
    {
        if (! $this->provider instanceof PricingSyncInterface) {
            throw new RuntimeException('Provider does not support pricing sync.');
        }

        $recordsSeen = 0;

        foreach ($this->provider->syncPricing($this->context) as $priceSnapshot) {
            $recordsSeen++;

            // Persist upstream base pricing and recalculate local selling prices here.
            unset($priceSnapshot);
        }

        return [
            'job' => $this->code(),
            'provider' => $this->provider->code(),
            'records_seen' => $recordsSeen,
        ];
    }
}
