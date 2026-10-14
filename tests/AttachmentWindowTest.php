<?php

declare(strict_types=1);

use Jira2Redmine\Journals\AttachmentWindow;
use PHPUnit\Framework\TestCase;

final class AttachmentWindowTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private const ATTACHMENTS = [
        ['mapping_id' => 1, 'created_at' => '2025-03-10 09:58:00'],
        ['mapping_id' => 2, 'created_at' => '2025-03-10 10:04:59'],
        ['mapping_id' => 3, 'created_at' => '2025-03-10 10:05:01'],
        ['mapping_id' => 4, 'created_at' => null],
    ];

    public function testWindowOffListsNothing(): void
    {
        self::assertSame([], AttachmentWindow::select(self::ATTACHMENTS, '2025-03-10 10:00:00', 0));
        self::assertSame(0, AttachmentWindow::seconds([]));
        self::assertSame(0, AttachmentWindow::seconds(['migration' => ['journals' => ['comment_attachment_window' => 'x']]]));
        self::assertSame(300, AttachmentWindow::seconds(['migration' => ['journals' => ['comment_attachment_window' => 300]]]));
    }

    public function testWindowListsTheAttachmentsAroundTheComment(): void
    {
        $ids = array_column(AttachmentWindow::select(self::ATTACHMENTS, '2025-03-10 10:00:00', 300), 'mapping_id');

        self::assertSame([1, 2, 4], $ids);
    }

    public function testTimesAreUtcWhateverTheDefaultTimezone(): void
    {
        $zone = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            // 2025-10-26 Brussels goes back from 03:00 to 02:00; read as local times these UTC times are 64 minutes apart
            $rows = [['mapping_id' => 1, 'created_at' => '2025-10-26 02:02:00']];
            self::assertCount(1, AttachmentWindow::select($rows, '2025-10-26 01:58:00', 300));
        } finally {
            date_default_timezone_set($zone);
        }
    }

    public function testElevenListsChangelogAttachmentsByItemsOnly(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../11_migrate_journals.php');

        self::assertTrue(str_contains($script, 'AttachmentWindow::select('), '11 keeps its own 5 minute window');
        self::assertSame(
            2,
            substr_count($script, 'fetchJournalAttachmentsForNote($pdo, $jiraIssueId, $createdAt, $commentWindow)'),
            'only the comment transform and the comment push list attachments by time'
        );
        self::assertSame(3, substr_count($script, 'fetchJournalAttachmentsForNote('), 'the definition and the two comment calls');
    }
}
