<?php

declare(strict_types=1);

namespace Jira2Redmine\Attachments;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * B58: 09 stored an attachment's Jira creation time without its offset ("2024-07-04T10:31:35.833+0200" became
 * "2024-07-04 10:31:35.833") and pushed that as UTC, so Redmine showed every attachment 1 or 2 hours late (issues
 * and comments were right: 10 and 11 convert to UTC). Staging holds UTC; a value without an offset is read as UTC,
 * whatever the server's timezone.
 */
final class CreatedAt
{
    /** For staging (DATETIME(6)): the instant in UTC. */
    public static function stagingValue(?string $value): ?string
    {
        $time = self::parse($value);

        return $time?->format('Y-m-d H:i:s.u');
    }

    /** For Redmine (attachment[created_on]): ISO 8601 in UTC. */
    public static function pushValue(?string $value): ?string
    {
        $time = self::parse($value);

        return $time?->format('Y-m-d\TH:i:s.u\Z');
    }

    private static function parse(?string $value): ?DateTimeImmutable
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
