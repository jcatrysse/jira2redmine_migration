<?php

declare(strict_types=1);

use Jira2Redmine\Database\ConnectionFactory;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    public function testErrorsThrowAndAttributesAreApplied(): void
    {
        $pdo = ConnectionFactory::connect(['dsn' => 'sqlite::memory:'], [PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertSame(['a' => 1], $this->fetchOne($pdo));
    }

    public function testDefaultFetchModeStaysPdoDefaultWithoutAttributes(): void
    {
        $pdo = ConnectionFactory::connect(['dsn' => 'sqlite::memory:']);
        self::assertSame(['a' => 1, 0 => 1], $this->fetchOne($pdo));
    }

    public function testMissingDsnIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        ConnectionFactory::connect([]);
    }

    /** @return array<int|string, mixed> */
    private function fetchOne(PDO $pdo): array
    {
        $statement = $pdo->query('SELECT 1 AS a');
        self::assertNotFalse($statement);

        return (array)$statement->fetch();
    }
}
