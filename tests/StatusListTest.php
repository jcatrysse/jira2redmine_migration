<?php

declare(strict_types=1);

use Jira2Redmine\Jira\StatusList;
use PHPUnit\Framework\TestCase;

final class StatusListTest extends TestCase
{
    public function testAStatusOnlyTheSearchShowsIsAdded(): void
    {
        // B57: the list left out statuses a workflow uses, so their transitions were dropped
        $listed = [['id' => '1', 'name' => 'Open', 'statusCategory' => ['key' => 'new', 'name' => 'To Do']]];
        $searched = [
            ['id' => '1', 'name' => 'Open', 'statusCategory' => 'TODO', 'scope' => ['type' => 'GLOBAL']],
            ['id' => '7', 'name' => 'Approved', 'statusCategory' => 'DONE', 'scope' => ['type' => 'GLOBAL']],
        ];

        $statuses = StatusList::merge($listed, $searched);

        self::assertSame(['1', '7'], array_map(fn (array $s): string => (string)$s['id'], $statuses));
        self::assertSame($listed[0], $statuses[0], 'the list answer stays as it was');
        self::assertSame(['key' => 'done', 'name' => 'DONE'], $statuses[1]['statusCategory']);
        self::assertSame('Approved', $statuses[1]['name']);
    }

    public function testOnlyAStatusAStagedProjectUsesIsAdded(): void
    {
        // B57: of 25 statuses the list lacks, 9 were in a migrated project's workflow, 16 in none
        $listed = [['id' => '1', 'name' => 'Open', 'statusCategory' => ['key' => 'new', 'name' => 'To Do']]];
        $searched = [
            ['id' => '1', 'name' => 'Open', 'statusCategory' => 'TODO'],
            ['id' => '7', 'name' => 'Approved', 'statusCategory' => 'DONE'],
            ['id' => '8', 'name' => 'Unused', 'statusCategory' => 'TODO'],
            ['id' => '9', 'name' => 'Elsewhere', 'statusCategory' => 'IN_PROGRESS'],
        ];
        $usages = ['1' => ['100'], '7' => ['100', '300'], '8' => [], '9' => ['200']];
        $asked = [];
        $projectsOf = function (string $id) use ($usages, &$asked): array {
            $asked[] = $id;

            return $usages[$id];
        };

        $missing = StatusList::missingUsed($listed, $searched, $projectsOf, ['100', '101']);
        $statuses = StatusList::merge($listed, $missing['used']);

        self::assertSame(['1', '7'], array_map(fn (array $s): string => (string)$s['id'], $statuses));
        self::assertSame(2, $missing['unused']);
        self::assertSame(['7', '8', '9'], $asked, 'a status the list has needs no usage call');
        self::assertSame(['key' => 'done', 'name' => 'DONE'], $statuses[1]['statusCategory']);
    }

    public function testWithoutStagedProjectsNothingIsAddedAndNothingAsked(): void
    {
        $missing = StatusList::missingUsed(
            [],
            [['id' => '7', 'name' => 'Approved', 'statusCategory' => 'DONE']],
            fn (string $id): array => throw new LogicException('no usage call without staged projects'),
            []
        );

        self::assertSame(['used' => [], 'unused' => 1], $missing);
    }

    public function testEveryPageOfTheProjectUsagesIsRead(): void
    {
        $pages = [
            '' => ['statusId' => '7', 'projects' => ['nextPageToken' => 'b', 'values' => [['id' => '100'], ['id' => 101]]]],
            'b' => ['statusId' => '7', 'projects' => ['nextPageToken' => null, 'values' => [['id' => '102'], ['id' => '100']]]],
        ];

        $ids = StatusList::projectIds(fn (?string $token): array => $pages[$token ?? '']);

        self::assertSame(['100', '101', '102'], $ids);
    }

    public function testTheSearchCategoriesBecomeTheKeysTheTransformKnows(): void
    {
        $keys = [];
        foreach (['TODO', 'IN_PROGRESS', 'DONE', 'Something'] as $category) {
            $status = StatusList::fromSearch(['id' => '1', 'name' => 'x', 'statusCategory' => $category]);
            $keys[] = $status['statusCategory']['key'];
        }

        // 05's transform: done is closed; new, todo and indeterminate are open
        self::assertSame(['new', 'indeterminate', 'done', 'something'], $keys);
    }

    public function testEachIdOnceAndBrokenEntriesSkipped(): void
    {
        $statuses = StatusList::merge(
            [['id' => 3, 'name' => 'A'], 'junk', ['name' => 'no id']],
            [['id' => '3', 'name' => 'A again'], ['id' => '4', 'name' => 'B'], ['id' => '4', 'name' => 'B again'], ['id' => '']]
        );

        self::assertSame(['A', 'B'], array_column($statuses, 'name'));
    }

    public function testEveryPageOfTheSearchIsRead(): void
    {
        $all = array_map(fn (int $i): array => ['id' => (string)$i, 'name' => "S$i", 'statusCategory' => 'TODO'], range(1, 64));
        $asked = [];
        $values = StatusList::searchAll(function (int $startAt) use ($all, &$asked): array {
            $asked[] = $startAt;
            // Jira may give fewer than asked
            $values = array_slice($all, $startAt, 25);

            return ['startAt' => $startAt, 'maxResults' => 25, 'total' => 64, 'isLast' => $startAt + 25 >= 64, 'values' => $values];
        });

        self::assertSame([0, 25, 50], $asked);
        self::assertSame($all, $values);
    }

    public function testAnEmptySearchAddsNothing(): void
    {
        $values = StatusList::searchAll(fn (int $startAt): array => ['startAt' => 0, 'total' => 0, 'isLast' => true, 'values' => []]);

        self::assertSame([], $values);
        self::assertSame([['id' => '1', 'name' => 'Open']], StatusList::merge([['id' => '1', 'name' => 'Open']], $values));
    }
}
