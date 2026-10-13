<?php

declare(strict_types=1);

use Jira2Redmine\Attachments\PullRetry;
use PHPUnit\Framework\TestCase;

final class PullRetryTest extends TestCase
{
    public function testFailedDownloadsGetOneMorePass(): void
    {
        $failed = ['queued' => 576, 'downloaded' => 574, 'failed' => 2];

        self::assertTrue(PullRetry::anotherPass($failed, null, 1));
        self::assertFalse(PullRetry::anotherPass($failed, null, 2));
    }

    public function testNoFailuresOrALimitedPullStop(): void
    {
        self::assertFalse(PullRetry::anotherPass(['queued' => 5, 'downloaded' => 5, 'failed' => 0], null, 1));
        self::assertFalse(PullRetry::anotherPass(['queued' => 5, 'downloaded' => 4, 'failed' => 1], 5, 1));
    }

    public function testThePullPhaseUsesIt(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../09_migrate_attachments.php');

        self::assertTrue(str_contains($script, 'PullRetry::anotherPass('), '09 pulls once and leaves failures');
    }
}
