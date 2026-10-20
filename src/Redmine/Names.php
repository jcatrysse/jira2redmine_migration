<?php

declare(strict_types=1);

namespace Jira2Redmine\Redmine;

/**
 * Length limits of Redmine names, so a Jira name that is too long is shortened before matching and
 * creating instead of failing with 422 (B1: a Jira issue type of 36 characters; B88: a custom field of 31).
 */
final class Names
{
    /** Redmine: Tracker validates_length_of :name, maximum: 30 (5.1 and 7.0). */
    public const TRACKER_MAX = 30;

    /** Redmine: CustomField validates_length_of :name, maximum: 30 (5.1 and 7.0; B88). */
    public const CUSTOM_FIELD_MAX = 30;

    public static function tracker(string $name): string
    {
        return self::shorten($name, self::TRACKER_MAX);
    }

    /**
     * A custom field name within Redmine's 30 characters; a suffix (" (Parent)" of a cascading field's parent) is
     * kept whole and the name before it shortened, so the parent never takes the child's name.
     */
    public static function customField(string $name, string $suffix = ''): string
    {
        return self::shorten($name, self::CUSTOM_FIELD_MAX - mb_strlen($suffix, 'UTF-8')) . $suffix;
    }

    private static function shorten(string $name, int $max): string
    {
        $name = trim($name);

        return mb_strlen($name, 'UTF-8') <= $max ? $name : rtrim(mb_substr($name, 0, $max, 'UTF-8'));
    }
}
