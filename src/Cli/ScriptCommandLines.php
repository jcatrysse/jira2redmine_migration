<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use InvalidArgumentException;

/**
 * Each script's own options; CommandLine applies the one rule of decision 43 to them.
 */
final class ScriptCommandLines
{
    private const PUSH = ['confirm-push' => CommandLine::FLAG, 'dry-run' => CommandLine::FLAG];
    private const PUSH_EXTENDED = self::PUSH + ['use-extended-api' => CommandLine::FLAG];

    /** By the script's file name (basename(__FILE__)); its number decides. */
    public static function for(string $script): CommandLine
    {
        return match (substr($script, 0, 2)) {
            '01', '02', '03', '12', '13', '14', '15' => new CommandLine(self::PUSH),
            '04', '05', '06', '07', '08', '10', '11' => new CommandLine(self::PUSH_EXTENDED),
            '09' => new CommandLine([
                'confirm-pull' => CommandLine::FLAG,
                'confirm-push' => CommandLine::FLAG,
                'dry-run' => CommandLine::FLAG,
                'download-limit' => CommandLine::POSITIVE_INT,
                'upload-limit' => CommandLine::POSITIVE_INT,
                'use-extended-api' => CommandLine::FLAG,
            ]),
            '16' => new CommandLine([]),
            default => throw new InvalidArgumentException(sprintf('No command line for %s.', $script)),
        };
    }
}
