<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class OrderRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createRegistrationOrder(
        string $customerId,
        string $providerAccountId,
        string $providerCode,
        string $domainId,
        string $customerEmail,
        int $periodYears,
        string $submissionMode,
        array $payload,
        ?float $totalAmount = null,
    ): array {
        $record = [
            'id' => Uuid::v4(),
            'order_number' => $this->generateOrderNumber(),
            'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
            'owner_type' => (string) ($payload['owner_type'] ?? 'user'),
            'owner_id' => (string) ($payload['owner_id'] ?? ($payload['tenant_id'] ?? '')),
            'acting_user_id' => $payload['acting_user_id'] ?? null,
            'acting_persona_id' => $payload['acting_persona_id'] ?? null,
            'billing_mode' => (string) ($payload['billing_mode'] ?? 'user'),
            'billing_tenant_id' => (string) ($payload['billing_tenant_id'] ?? ($payload['tenant_id'] ?? '')),
            'finance_event_ref' => $payload['finance_event_ref'] ?? null,
            'receipt_bundle_path' => $payload['receipt_bundle_path'] ?? null,
            'receipt_bundle_hash' => $payload['receipt_bundle_hash'] ?? null,
            'reference_id' => (string) ($payload['reference_id'] ?? Uuid::v4()),
            'customer_id' => $customerId,
            'provider_account_id' => $providerAccountId,
            'provider_code' => $providerCode,
            'domain_id' => $domainId,
            'action_type' => 'register',
            'submission_mode' => $submissionMode,
            'status' => 'draft',
            'period_years' => $periodYears,
            'currency_code' => 'ZAR',
            'total_amount' => $totalAmount,
            'customer_email' => strtolower($customerEmail),
            'payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES),
        ];

        $this->database->execute(
            'INSERT INTO customer_orders (
                id, order_number, tenant_id, owner_type, owner_id, acting_user_id, acting_persona_id,
                billing_mode, billing_tenant_id, finance_event_ref, receipt_bundle_path, receipt_bundle_hash,
                reference_id, customer_id, provider_account_id, provider_code, domain_id, action_type,
                submission_mode, status, period_years, currency_code, total_amount, customer_email,
                payload_json, created_at, updated_at
            ) VALUES (
                :id, :order_number, :tenant_id, :owner_type, :owner_id, :acting_user_id, :acting_persona_id,
                :billing_mode, :billing_tenant_id, :finance_event_ref, :receipt_bundle_path, :receipt_bundle_hash,
                :reference_id, :customer_id, :provider_account_id, :provider_code, :domain_id, :action_type,
                :submission_mode, :status, :period_years, :currency_code, :total_amount, :customer_email,
                :payload_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )',
            $record,
        );

        return $this->findById($record['id']) ?? $record;
    }

    public function findById(string $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM customer_orders WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }

    public function findWithRelations(string $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT o.*, d.domain_name, d.upstream_domain_id
             FROM customer_orders o
             INNER JOIN domains d ON d.id = o.domain_id
             WHERE o.id = :id
             LIMIT 1',
            ['id' => $id],
        );
    }

    public function markQueued(string $orderId): void
    {
        $this->database->execute(
            'UPDATE customer_orders
             SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['id' => $orderId, 'status' => 'queued'],
        );
    }

    public function markProcessing(string $orderId): void
    {
        $this->database->execute(
            'UPDATE customer_orders
             SET status = :status, submitted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            ['id' => $orderId, 'status' => 'processing'],
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    public function markCompleted(string $orderId, array $response): void
    {
        $this->database->execute(
            'UPDATE customer_orders
             SET status = :status,
                 provider_response_json = :provider_response_json,
                 last_error = NULL,
                 processed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $orderId,
                'status' => 'completed',
                'provider_response_json' => json_encode($response, JSON_UNESCAPED_SLASHES),
            ],
        );
    }

    public function markFailed(string $orderId, string $error, ?array $response = null): void
    {
        $this->database->execute(
            'UPDATE customer_orders
             SET status = :status,
                 provider_response_json = :provider_response_json,
                 last_error = :last_error,
                 processed_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $orderId,
                'status' => 'failed',
                'provider_response_json' => $response === null ? null : json_encode($response, JSON_UNESCAPED_SLASHES),
                'last_error' => $error,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 12): array
    {
        return $this->database->fetchAll(
            sprintf(
                'SELECT o.*, d.domain_name
                 FROM customer_orders o
                 INNER JOIN domains d ON d.id = o.domain_id
                 ORDER BY o.created_at DESC LIMIT %d',
                max(1, $limit),
            ),
        );
    }

    private function generateOrderNumber(): string
    {
        return sprintf('ORD-%s', strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)));
    }
}
