<?php

declare(strict_types=1);

namespace App\Domain\Provider\Contracts;

use App\Domain\Sync\SyncContext;

interface PricingSyncInterface
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public function syncPricing(SyncContext $context): iterable;
}
