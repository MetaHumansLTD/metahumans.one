<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use PDOException;
use RuntimeException;

final class SchemaLoader
{
    public function __construct(
        private readonly Database $database,
        private readonly string $schemaPath,
    ) {
    }

    public function load(): void
    {
        if (! is_file($this->schemaPath)) {
            error_log('[SchemaLoader] Schema file not found: ' . $this->schemaPath);
            return;
        }

        $sql = file_get_contents($this->schemaPath);

        if ($sql === false) {
            error_log('[SchemaLoader] Unable to read schema file: ' . $this->schemaPath);
            return;
        }

        $statements = preg_split('/;\s*(?:\R|$)/', $sql) ?: [];
        foreach ($statements as $statement) {
            $statement = trim($statement);
            if ($statement === '') {
                continue;
            }

            try {
                $this->database->pdo()->exec($statement);
            } catch (PDOException $exception) {
                if ($this->isIgnorableSchemaError($exception)) {
                    continue;
                }

                error_log('[SchemaLoader] ' . $this->schemaPath . ': ' . $exception->getMessage());
            }
        }
    }

    private function isIgnorableSchemaError(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        foreach ([
            'already exists',
            'duplicate key name',
            'duplicate index name',
        ] as $fragment) {
            if (str_contains($message, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
