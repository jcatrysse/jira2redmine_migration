<?php

declare(strict_types=1);

namespace Jira2Redmine\Attachments;

/**
 * Whether an issue's attachments let 10 create it. An attachment still to download or upload blocks
 * the issue, so it is not created without the file; one switched off (download_enabled or
 * upload_enabled = 0, by hand or by attachments.test_sample) never arrives and must not block it.
 */
final class IssueAttachmentState
{
    /**
     * @param iterable<array{migration_status: string, download_enabled: int|string|bool, upload_enabled: int|string|bool,
     *     redmine_upload_token: ?string, sharepoint_url: ?string}> $rows the issue's migration_mapping_attachments rows
     * @return array{ready: int, blocked: int}
     */
    public static function summarize(iterable $rows): array
    {
        $ready = 0;
        $blocked = 0;
        foreach ($rows as $row) {
            $status = (string)$row['migration_status'];
            $enabled = (bool)(int)$row['download_enabled'] && (bool)(int)$row['upload_enabled'];
            $hasTarget = trim((string)($row['redmine_upload_token'] ?? '')) !== ''
                || trim((string)($row['sharepoint_url'] ?? '')) !== '';
            if ($status === 'PENDING_ASSOCIATION' && $hasTarget) {
                $ready++;
            } elseif ($enabled && in_array($status, ['PENDING_DOWNLOAD', 'PENDING_UPLOAD'], true)) {
                $blocked++;
            }
        }

        return ['ready' => $ready, 'blocked' => $blocked];
    }
}
