<?php

declare(strict_types=1);

use Jira2Redmine\Journals\JournalStatus;
use PHPUnit\Framework\TestCase;

final class JournalStatusTest extends TestCase
{
    public function testAPushedJournalIsNotPushedAgain(): void
    {
        self::assertSame('SUCCESS', JournalStatus::afterTransform('SUCCESS', 1490, 4));
        self::assertSame('SUCCESS', JournalStatus::afterTransform('READY_FOR_PUSH', 1490, 4));
    }

    public function testFailedAndSkippedRowsAreJudgedAgain(): void
    {
        self::assertSame('READY_FOR_PUSH', JournalStatus::afterTransform('FAILED', null, 4));
        self::assertSame('READY_FOR_PUSH', JournalStatus::afterTransform('SKIPPED', null, 4));
    }

    public function testRowsWaitForTheirIssue(): void
    {
        self::assertSame('PENDING', JournalStatus::afterTransform('READY_FOR_PUSH', null, null));
        self::assertSame('READY_FOR_PUSH', JournalStatus::afterTransform('PENDING', null, 4));
        self::assertSame('IGNORED', JournalStatus::afterTransform('IGNORED', null, 4));
    }

    public function testElevenUsesThisRule(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');

        self::assertTrue(str_contains($script, 'JournalStatus::afterTransform('), '11 resets pushed journals to READY_FOR_PUSH');
    }
}
