<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;
use Throwable;

final class TaskQueueRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueue(
        string $taskType,
        string $queueName,
        array $payload,
        int $priority = 0,
        int $maxAttempts = 3,
        ?string $availableAt = null,
        ?string $uniqueKey = null,
    ): ?array {
        $record = [
            'id' => Uuid::v4(),
            'task_type' => $taskType,
            'queue_name' => $queueName,
            'status' => 'queued',
            'unique_key' => $uniqueKey,
            'priority' => $priority,
            'max_attempts' => $maxAttempts,
            'tenant_id' => $payload['tenant_id'] ?? null,
            'tenant_db_config_id' => $payload['tenant_db_config_id'] ?? null,
            'owner_type' => $payload['owner_type'] ?? null,
            'owner_id' => $payload['owner_id'] ?? null,
            'acting_user_id' => $payload['acting_user_id'] ?? null,
            'acting_persona_id' => $payload['acting_persona_id'] ?? null,
            'billing_mode' => $payload['billing_mode'] ?? null,
            'billing_tenant_id' => $payload['billing_tenant_id'] ?? null,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
            'available_at' => $availableAt ?? date('Y-m-d H:i:s'),
        ];

        try {
            $this->database->execute(
                'INSERT INTO worker_tasks (
                    id, task_type, queue_name, status, unique_key, priority, max_attempts,
                    tenant_id, tenant_db_config_id, owner_type, owner_id, acting_user_id, acting_persona_id,
                    billing_mode, billing_tenant_id, payload_json, available_at, created_at, updated_at
                ) VALUES (
                    :id, :task_type, :queue_name, :status, :unique_key, :priority, :max_attempts,
                    :tenant_id, :tenant_db_config_id, :owner_type, :owner_id, :acting_user_id, :acting_persona_id,
                    :billing_mode, :billing_tenant_id, :payload_json, :available_at, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                )',
                $record,
            );
        } catch (Throwable $throwable) {
            if ($uniqueKey !== null && str_contains(strtolower($throwable->getMessage()), 'unique')) {
                return null;
            }

            throw $throwable;
        }

        return $this->findById($record['id']);
    }

    public function claimNext(): ?array
    {
        return $this->database->transaction(function (Database $database): ?array {
            $task = $database->fetchOne(
                'SELECT * FROM worker_tasks
                 WHERE status = :status AND available_at <= CURRENT_TIMESTAMP
                 ORDER BY priority DESC, available_at ASC, created_at ASC
                 LIMIT 1',
                ['status' => 'queued'],
            );

            if ($task === null) {
                return null;
            }

            $updated = $database->execute(
                'UPDATE worker_tasks
                 SET status = :next_status,
                     attempts = attempts + 1,
                     started_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND status = :current_status',
                [
                    'id' => $task['id'],
                    'next_status' => 'processing',
                    'current_status' => 'queued',
                ],
            );

            if ($updated === 0) {
                return null;
            }

            return $database->fetchOne(
                'SELECT * FROM worker_tasks WHERE id = :id LIMIT 1',
                ['id' => $task['id']],
            );
        });
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markCompleted(string $taskId, array $result): void
    {
        $this->database->execute(
            'UPDATE worker_tasks
             SET status = :status,
                 result_json = :result_json,
                 finished_at = CURRENT_TIMESTAMP,
                 last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $taskId,
                'status' => 'completed',
                'result_json' => json_encode($result, JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function markFailed(string $taskId, string $error, ?array $result = null, bool $retry = false): void
    {
        if ($retry) {
            $nextAttemptAt = date('Y-m-d H:i:s', time() + (5 * 60));

            $this->database->execute(
                'UPDATE worker_tasks
                 SET status = :status,
                     result_json = :result_json,
                     last_error = :last_error,
                     available_at = :available_at,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'id' => $taskId,
                    'status' => 'queued',
                    'result_json' => $result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES),
                    'last_error' => $error,
                    'available_at' => $nextAttemptAt,
                ],
            );

            return;
        }

        $this->database->execute(
            'UPDATE worker_tasks
             SET status = :status,
                 result_json = :result_json,
                 last_error = :last_error,
                 finished_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $taskId,
                'status' => 'failed',
                'result_json' => $result === null ? null : json_encode($result, JSON_UNESCAPED_SLASHES),
                'last_error' => $error,
            ],
        );
    }

    public function retryFailedTasks(int $limit = 25): int
    {
        return $this->database->execute(
            sprintf(
                'UPDATE worker_tasks
                 SET status = :status,
                     available_at = CURRENT_TIMESTAMP,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id IN (
                    SELECT id FROM worker_tasks
                    WHERE status = :failed_status AND attempts < max_attempts
                    ORDER BY updated_at ASC
                    LIMIT %d
                 )',
                max(1, $limit),
            ),
            [
                'status' => 'queued',
                'failed_status' => 'failed',
            ],
        );
    }

    public function findById(string $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM worker_tasks WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 12): array
    {
        return $this->database->fetchAll(
            sprintf('SELECT * FROM worker_tasks ORDER BY created_at DESC LIMIT %d', max(1, $limit)),
        );
    }
}
