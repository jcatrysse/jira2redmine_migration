<?php

declare(strict_types=1);

use Jira2Redmine\Issues\OpenParentStatus;
use PHPUnit\Framework\TestCase;

final class OpenParentStatusTest extends TestCase
{
    private const STATUSES = [
        ['id' => 1, 'name' => 'New', 'is_closed' => 0],
        ['id' => 3, 'name' => 'Resolved', 'is_closed' => 0],
        ['id' => 5, 'name' => 'Closed', 'is_closed' => 1],
    ];

    public function testFindsTheOpenStatusByName(): void
    {
        self::assertSame(3, OpenParentStatus::resolve(self::STATUSES, 'resolved'));
    }

    public function testStopsOnAClosedStatus(): void
    {
        $this->expectException(RuntimeException::class);
        OpenParentStatus::resolve(self::STATUSES, 'Closed');
    }

    public function testStopsOnAMissingStatus(): void
    {
        $this->expectException(RuntimeException::class);
        OpenParentStatus::resolve(self::STATUSES, 'Done');
    }

    public function testNoteCarriesTheMarkerAndTheJiraStatus(): void
    {
        $note = OpenParentStatus::note('Resolved', 'Closed');
        self::assertStringStartsWith('[open-parent]', $note);
        self::assertStringContainsString('Jira status "Closed"', $note);
    }
}
