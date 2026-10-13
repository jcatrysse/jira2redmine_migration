<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

/**
 * Length limits of Redmine names, so a Jira name that is too long is shortened before matching and
 * creating instead of failing with 422 (B1: a Jira issue type of 36 characters).
 */
final class Names
{
    /** Redmine: Tracker validates_length_of :name, maximum: 30 (5.1 and 7.0). */
    public const TRACKER_MAX = 30;

    public static function tracker(string $name): string
    {
        return self::shorten($name, self::TRACKER_MAX);
    }

    private static function shorten(string $name, int $max): string
    {
        $name = trim($name);

        return mb_strlen($name, 'UTF-8') <= $max ? $name : rtrim(mb_substr($name, 0, $max, 'UTF-8'));
    }
}
