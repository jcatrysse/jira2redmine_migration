<?php

declare(strict_types=1);

namespace Jira2Redmine\Journals;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Which attachments a Jira comment lists as "> attachment:" lines: those uploaded within the window around it.
 * While B58 made every attachment one or two hours late, the window (5 minutes) matched 5 comments of the 5.1 test
 * run, each with files uploaded an hour or two before it; with the times right it gave 199 comments extra lines and
 * 228 status changes a note of attachments uploaded around them (B59). migration.journals.comment_attachment_window
 * sets it in seconds, 0 (default) lists none. A changelog entry lists only the attachments its items name.
 */
final class AttachmentWindow
{
    public const CONFIG_KEY = 'comment_attachment_window';

    /**
     * @param array<string, mixed> $config
     */
    public static function seconds(array $config): int
    {
        $value = $config['migration']['journals'][self::CONFIG_KEY] ?? 0;

        return is_numeric($value) && (int)$value > 0 ? (int)$value : 0;
    }

    /**
     * @param list<array<string, mixed>> $attachments rows with created_at, staging times in UTC
     * @return list<array<string, mixed>>
     */
    public static function select(array $attachments, ?string $journalCreatedAt, int $windowSeconds): array
    {
        if ($windowSeconds <= 0) {
            return [];
        }
        $journal = self::timestamp($journalCreatedAt);
        if ($journal === null) {
            return $attachments;
        }

        return array_values(array_filter($attachments, static function (array $row) use ($journal, $windowSeconds): bool {
            $created = self::timestamp(isset($row['created_at']) ? (string)$row['created_at'] : null);

            return $created === null || abs($created - $journal) <= $windowSeconds;
        }));
    }

    private static function timestamp(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }
}
