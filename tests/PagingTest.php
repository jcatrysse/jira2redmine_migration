<?php

declare(strict_types=1);

use Jira2Redmine\Jira\Paging;
use PHPUnit\Framework\TestCase;

final class PagingTest extends TestCase
{
    public function testAShorterPageThanAskedContinuesAfterTheItemsReceived(): void
    {
        // asked for 100, Jira gave 50 of 64 (workflow/search)
        $payload = ['startAt' => 0, 'maxResults' => 50, 'total' => 64, 'isLast' => false];

        self::assertSame(50, Paging::nextStartAt($payload, 0, 50));
    }

    public function testEveryItemIsReadOnceOverAllPages(): void
    {
        $all = range(1, 64);
        $read = [];
        $startAt = 0;
        do {
            // Jira caps each page at 50, whatever is asked
            $values = array_slice($all, $startAt, 50);
            $payload = [
                'startAt' => $startAt,
                'maxResults' => 50,
                'total' => 64,
                'isLast' => $startAt + 50 >= 64,
                'values' => $values,
            ];
            $read = array_merge($read, $values);
            $startAt = Paging::nextStartAt($payload, $startAt, count($values));
        } while ($startAt !== null);

        self::assertSame($all, $read);
    }

    public function testLastPageStops(): void
    {
        self::assertNull(Paging::nextStartAt(['startAt' => 50, 'total' => 64, 'isLast' => true], 50, 14));
        self::assertNull(Paging::nextStartAt(['startAt' => 50, 'total' => 64], 50, 14));
        self::assertNull(Paging::nextStartAt(['startAt' => 0, 'total' => 10], 0, 0));
    }

    public function testAPlainListDoesNotPage(): void
    {
        self::assertNull(Paging::nextStartAt([['id' => 1], ['id' => 2]], 0, 2));
    }

    public function testIsLastWithoutTotal(): void
    {
        self::assertSame(100, Paging::nextStartAt(['startAt' => 0, 'isLast' => false], 0, 100));
        self::assertNull(Paging::nextStartAt(['startAt' => 100, 'isLast' => true], 100, 3));
    }
}
