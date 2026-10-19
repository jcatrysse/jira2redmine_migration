<?php

declare(strict_types=1);

namespace Jira2Redmine\Time;

/**
 * The current time in UTC, as the scripts print and store it (plan M1: the 16 copies of formatCurrentTimestamp and
 * the 7 of formatCurrentUtcTimestamp). The copies used date(), DateTimeImmutable or gmdate(); since bootstrap.php sets
 * PHP to UTC (decision 23) they all gave UTC, as these do without depending on it.
 */
final class Clock
{
    /** 2026-10-19T18:51:17+00:00, the time on the log lines of 01 to 08. */
    public static function atom(): string
    {
        return gmdate(DATE_ATOM);
    }

    /** 2026-10-19 18:51:17, the time on the log lines of 09 to 16 and in the staging tables. */
    public static function dateTime(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
