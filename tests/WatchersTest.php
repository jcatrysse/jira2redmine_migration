<?php

declare(strict_types=1);

use Jira2Redmine\Redmine\Watchers;
use PHPUnit\Framework\TestCase;

final class WatchersTest extends TestCase
{
    public function testReadsWatcherIdsWithOrWithoutTheIssueWrapper(): void
    {
        $payload = ['issue' => ['id' => 61, 'watchers' => [['id' => 1, 'name' => 'Admin'], ['id' => 126, 'name' => 'Jane']]]];
        self::assertSame([1, 126], Watchers::storedUserIds($payload));
        self::assertSame([], Watchers::storedUserIds(['id' => 61]));
    }

    public function testReportsRequestedUsersThatRedmineDidNotStore(): void
    {
        // Redmine answered 204 for 126, 235 and 242 but kept only 235 (B20).
        self::assertSame([126, 242], Watchers::notStored([126, 235, 242], [1, 235]));
        self::assertSame([], Watchers::notStored([235], [235]));
    }
}
