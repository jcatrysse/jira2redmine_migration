<?php

declare(strict_types=1);

use Jira2Redmine\Attachments\CreatedAt;
use PHPUnit\Framework\TestCase;

final class CreatedAtTest extends TestCase
{
    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        // a server in Belgium: a value without an offset must still be read as UTC
        date_default_timezone_set('Europe/Brussels');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->timezone);
    }

    public function testJirasOffsetIsKeptAsTheInstantInUtc(): void
    {
        // B58: stored as "2024-07-04 10:31:35.833000" and pushed as 10:31:35Z, two hours late
        self::assertSame('2024-07-04 08:31:35.833000', CreatedAt::stagingValue('2024-07-04T10:31:35.833+0200'));
        self::assertSame('2024-01-10 07:00:00.000000', CreatedAt::stagingValue('2024-01-10T08:00:00.000+0100'));
    }

    public function testAStagingValueIsUtcOnTheWayToRedmine(): void
    {
        self::assertSame('2024-07-04T08:31:35.833000Z', CreatedAt::pushValue('2024-07-04 08:31:35.833000'));
        // the issue's creation time (10 stores UTC) as the fallback keeps its instant
        self::assertSame('2024-06-17 08:42:28.000000', CreatedAt::stagingValue('2024-06-17 08:42:28'));
    }

    public function testNothingOrNonsenseGivesNull(): void
    {
        self::assertNull(CreatedAt::stagingValue(null));
        self::assertNull(CreatedAt::stagingValue('  '));
        self::assertNull(CreatedAt::pushValue('not a time'));
    }

    public function testVerifyComparesInstants(): void
    {
        require_once __DIR__ . '/../lib/verify.php';

        self::assertTrue(verifySameInstant('2024-07-04T10:31:35.833+0200', '2024-07-04T08:31:35Z'));
        self::assertFalse(verifySameInstant('2024-07-04T10:31:35.833+0200', '2024-07-04T10:31:35Z'), 'the B58 case');
        self::assertFalse(verifySameInstant('', '2024-07-04T08:31:35Z'));
    }
}
