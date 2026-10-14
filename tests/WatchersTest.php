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

    public function testAWatcherWithoutProjectAccessIsTold(): void
    {
        $page = ['memberships' => [
            ['id' => 1, 'user' => ['id' => 7], 'roles' => [['id' => 4, 'inherited' => true]]],
            ['id' => 2, 'group' => ['id' => 30], 'roles' => [['id' => 4]]],
        ]];

        // a member through a group counts; the group itself is no user
        self::assertSame([7], Watchers::memberUserIds($page));
        self::assertStringStartsWith(Watchers::NO_ACCESS, Watchers::noAccessNote(215));
        $fourteen = (string)file_get_contents(__DIR__ . '/../14_migrate_watchers.php');
        self::assertTrue(str_contains($fourteen, 'Watchers::noAccessNote($redmineUserId)'), '14 cannot tell a watcher without access');
        // Jan, 2026-10-14: they are migrated (04 makes them members), so verify expects them present
        $verify = (string)file_get_contents(__DIR__ . '/../tools/verify.php');
        self::assertTrue(
            str_contains($verify, '$missingWatchers = array_diff($expectedWatchers, $actualWatchers);'),
            'verify lets a watcher without access be absent'
        );
        self::assertFalse(str_contains($verify, "'watchers: no project access"), 'verify lets a watcher without access be absent');
    }
}
