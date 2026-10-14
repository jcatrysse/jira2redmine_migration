<?php

declare(strict_types=1);

namespace Jira2Redmine\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * The one place that opens the staging database for the migration scripts.
 */
final class ConnectionFactory
{
    /**
     * Opens the staging database with the configured options. Errors always throw. $attributes are
     * set after connecting and win over the configured options (scripts differ: some fetch
     * associative arrays by default, some switch emulated prepares off).
     *
     * @param array<string, mixed> $databaseConfig dsn, username, password, options
     * @param array<int, mixed> $attributes PDO attributes
     */
    public static function connect(array $databaseConfig, array $attributes = []): PDO
    {
        $dsn = trim((string)($databaseConfig['dsn'] ?? ''));
        if ($dsn === '') {
            throw new RuntimeException('Database configuration must include a DSN.');
        }

        $options = $databaseConfig['options'] ?? [];
        if (!is_array($options)) {
            throw new RuntimeException('Database options configuration must be an array.');
        }

        try {
            $pdo = new PDO($dsn, (string)($databaseConfig['username'] ?? ''), (string)($databaseConfig['password'] ?? ''), $options);
        } catch (PDOException $exception) {
            throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
        }

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ($attributes as $attribute => $value) {
            $pdo->setAttribute($attribute, $value);
        }
        foreach (self::sessionStatements((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) as $statement) {
            $pdo->exec($statement);
        }

        return $pdo;
    }

    /**
     * MySQL reads and writes a TIMESTAMP column in the session's timezone, the server's own by default. The scripts
     * write UTC: a server set to Europe/Brussels stored those values 1 or 2 hours off from CURRENT_TIMESTAMP.
     *
     * @return list<string>
     */
    public static function sessionStatements(string $driver): array
    {
        return $driver === 'mysql' ? ["SET time_zone = '+00:00'"] : [];
    }
}
