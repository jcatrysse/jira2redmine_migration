<?php

declare(strict_types=1);

use Jira2Redmine\Attachments\IssueAttachmentState;
use PHPUnit\Framework\TestCase;

final class IssueAttachmentStateTest extends TestCase
{
    /**
     * @return array{migration_status: string, download_enabled: int, upload_enabled: int,
     *     redmine_upload_token: ?string, sharepoint_url: ?string}
     */
    private static function row(string $status, int $download = 1, int $upload = 1, ?string $token = null): array
    {
        return [
            'migration_status' => $status,
            'download_enabled' => $download,
            'upload_enabled' => $upload,
            'redmine_upload_token' => $token,
            'sharepoint_url' => null,
        ];
    }

    public function testAnAttachmentStillToDownloadBlocksTheIssue(): void
    {
        self::assertSame(['ready' => 1, 'blocked' => 1], IssueAttachmentState::summarize([
            self::row('PENDING_ASSOCIATION', 1, 1, '12.abc'),
            self::row('PENDING_DOWNLOAD'),
        ]));
    }

    public function testASwitchedOffAttachmentDoesNotBlock(): void
    {
        self::assertSame(['ready' => 1, 'blocked' => 0], IssueAttachmentState::summarize([
            self::row('PENDING_ASSOCIATION', 1, 1, '12.abc'),
            // skipped by sample: never downloaded
            self::row('PENDING_DOWNLOAD', 0, 0),
            // downloaded, upload switched off by hand
            self::row('PENDING_UPLOAD', 1, 0),
        ]));
    }

    public function testAssociatedAttachmentsAreNeitherReadyNorBlocking(): void
    {
        self::assertSame(['ready' => 0, 'blocked' => 0], IssueAttachmentState::summarize([
            self::row('SUCCESS', 1, 1, '12.abc'),
            self::row('PENDING_ASSOCIATION'),
        ]));
    }

    public function testTenUsesThisRule(): void
    {
        $script = (string)file_get_contents(__DIR__ . '/../10_migrate_issues.php');

        self::assertTrue(
            str_contains($script, 'IssueAttachmentState::summarize('),
            '10 counts pending attachments itself, switched-off ones included'
        );
    }
}
