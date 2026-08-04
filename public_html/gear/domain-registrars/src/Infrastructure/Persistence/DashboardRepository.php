<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;

final class DashboardRepository
{
    public function __construct(
        private readonly Database $tenantDatabase,
        private readonly Database $sharedDatabase,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        return [
            'domains' => (int) ($this->tenantDatabase->fetchValue('SELECT COUNT(*) FROM domains') ?? 0),
            'orders' => (int) ($this->tenantDatabase->fetchValue('SELECT COUNT(*) FROM customer_orders') ?? 0),
            'queued_tasks' => (int) ($this->sharedDatabase->fetchValue("SELECT COUNT(*) FROM worker_tasks WHERE status = 'queued'") ?? 0),
            'failed_tasks' => (int) ($this->sharedDatabase->fetchValue("SELECT COUNT(*) FROM worker_tasks WHERE status = 'failed'") ?? 0),
        ];
    }
}
