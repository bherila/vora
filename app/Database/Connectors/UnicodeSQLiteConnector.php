<?php

namespace App\Database\Connectors;

use Illuminate\Database\Connectors\SQLiteConnector;
use PDO;
use Pdo\Sqlite;

class UnicodeSQLiteConnector extends SQLiteConnector
{
    /** @param array<string, mixed> $config */
    public function connect(array $config): PDO
    {
        $connection = parent::connect($config);
        // Laravel uses PDO::connect on our supported PHP versions, yielding
        // the driver-specific PDO class for every fresh/read/reconnected handle.
        assert($connection instanceof Sqlite);
        $connection->createFunction(
            'vora_unicode_lower',
            static fn (?string $value): ?string => $value === null ? null : mb_strtolower($value, 'UTF-8'),
            1,
            PDO::SQLITE_DETERMINISTIC,
        );

        return $connection;
    }
}
