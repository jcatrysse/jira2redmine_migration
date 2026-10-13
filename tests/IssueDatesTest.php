<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\IssueDates;
use PHPUnit\Framework\TestCase;

final class IssueDatesTest extends TestCase
{
    public function testStartIsTheCreationDate(): void
    {
        self::assertSame('2026-03-05', IssueDates::startDate('2026-03-05 14:41:20', '2026-04-01'));
        self::assertSame('2026-03-05', IssueDates::startDate('2026-03-05 14:41:20', null));
        self::assertNull(IssueDates::startDate(null, '2026-04-01'));
    }

    public function testADueDateBeforeTheCreationDateBecomesTheStart(): void
    {
        // B8: Redmine refuses due < start.
        self::assertSame('2026-02-27', IssueDates::startDate('2026-03-05 14:41:20', '2026-02-27'));
        self::assertSame('2026-03-05', IssueDates::startDate('2026-03-05 14:41:20', '2026-03-05'));
    }
}
