<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDO;
use Throwable;

final class Database
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback)
    {
        $this->pdo->beginTransaction();

        try {
            $result = $callback($this);
            $this->pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        $result = $statement->fetch();

        return $result === false ? null : $result;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->fetchOne($sql, $params);
        if ($row === null) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->rowCount();
    }
}
