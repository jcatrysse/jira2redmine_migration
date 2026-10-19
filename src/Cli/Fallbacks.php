<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

/**
 * What a script does when PCRE fails (a backtrack limit, text that is not valid UTF-8) or a value it needs cannot be
 * used (decision 43, Jan): it keeps the text as it was or skips the field, never silently. Each fallback is one
 * "[warn]" line that names where (the Jira issue or field, and the field) and ends in "(decision 43)"; the script's
 * summary counts them. Until decision 43 these ended in a TypeError that stopped the script, or a PHP warning.
 */
final class Fallbacks
{
    private static int $count = 0;

    /**
     * The result of a preg_* call, or the text as it was when PCRE failed (the call gave null).
     */
    public static function keepText(?string $result, string $original, string $where): string
    {
        if ($result !== null) {
            return $result;
        }
        self::warn($where, sprintf('PCRE failed (%s), the text is kept as it was', preg_last_error_msg()));

        return $original;
    }

    /** A value the field needs cannot be used: the caller leaves the field out. */
    public static function skipField(string $where, string $reason): void
    {
        self::warn($where, $reason . ', the field is skipped');
    }

    /** One "[warn]" line, counted for the summary. */
    public static function warn(string $where, string $what): void
    {
        self::$count++;
        printf('  [warn] %s: %s (decision 43).%s', $where, $what, PHP_EOL);
    }

    /** The fallbacks of this run so far. */
    public static function count(): int
    {
        return self::$count;
    }

    /** The summary line of a script. */
    public static function summary(): string
    {
        return sprintf('Fallbacks (decision 43, each a [warn] line above): %d.', self::$count);
    }
}
