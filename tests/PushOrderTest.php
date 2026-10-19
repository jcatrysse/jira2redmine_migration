<?php

declare(strict_types=1);

use Jira2Redmine\Journals\PushOrder;
use PHPUnit\Framework\TestCase;

/**
 * Redmine's "Last updated by" is the author of the journal with the highest id. 11 pushed all comments first and all
 * changelog entries after them, so an issue's last updated user could be someone who did not act last in Jira.
 */
final class PushOrderTest extends TestCase
{
    /** @return array<string, mixed> */
    private function row(int $issue, string $created, int $mapping): array
    {
        return ['redmine_issue_id' => $issue, 'created_at' => $created, 'mapping_id' => $mapping, 'jira_entity_id' => (string)$mapping];
    }

    public function testEachIssueGoesInJiraTimeOrderAcrossCommentsAndChangelogEntries(): void
    {
        $timeline = PushOrder::timeline(
            [$this->row(7, '2024-01-01 10:00:00', 1), $this->row(7, '2024-01-03 10:00:00', 2), $this->row(5, '2024-02-01 10:00:00', 3)],
            [$this->row(7, '2024-01-02 10:00:00', 4), $this->row(5, '2024-01-01 09:00:00', 5)]
        );
        self::assertSame(
            [['changelog', 5], ['comment', 3], ['comment', 1], ['changelog', 4], ['comment', 2]],
            array_map(static fn (array $event): array => [$event[0], $event[1]['mapping_id']], $timeline)
        );
    }

    public function testOnTheSameSecondTheAttachmentEntryComesBeforeTheComment(): void
    {
        $timeline = PushOrder::timeline([$this->row(7, '2024-01-01 10:00:00', 1)], [$this->row(7, '2024-01-01 10:00:00', 9)]);
        self::assertSame(['changelog', 'comment'], array_column($timeline, 0));
    }

    public function testHistoryJournalsGoInBetweenAndASeriesWithoutACommentIsOneRequest(): void
    {
        $timeline = PushOrder::timeline(
            [$this->row(7, '2024-01-03 10:00:00', 1)],
            [$this->row(7, '2024-01-01 10:00:00', 2)],
            [
                $this->row(7, '2024-01-01 10:00:00', 100),
                $this->row(7, '2024-01-02 10:00:00', 101),
                $this->row(7, '2024-01-02 11:00:00', 102),
                $this->row(7, '2024-01-04 10:00:00', 103),
                $this->row(8, '2024-01-01 10:00:00', 104),
            ]
        );
        // on the same second the history first, then the attachment entry
        self::assertSame(
            ['history', 'changelog', 'history', 'history', 'comment', 'history', 'history'],
            array_column($timeline, 0)
        );
        $steps = array_map(
            static fn (array $step): string => $step[0] === 'history'
                ? 'history:' . implode(',', array_column($step[1], 'mapping_id'))
                : $step[0] . ':' . $step[1]['mapping_id'],
            PushOrder::steps($timeline)
        );
        self::assertSame(['history:100', 'changelog:2', 'history:101,102', 'comment:1', 'history:103', 'history:104'], $steps);
    }

    public function testThe11PushesAndPreviewsInThatOrder(): void
    {
        $eleven = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');
        self::assertStringContainsString(
            '$timeline = PushOrder::timeline($candidateComments, $candidateChangelogs, $historyEvents);',
            $eleven
        );
        self::assertSame(2, substr_count($eleven, 'foreach (PushOrder::steps($timeline) as [$kind, $item]) {'));
        self::assertStringNotContainsString('foreach ($candidateComments as $comment) {', $eleven);
    }
}
