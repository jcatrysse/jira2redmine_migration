<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use InvalidArgumentException;

/**
 * Each script's command line: its own options and its own rule for what is not a valid option (CommandLine). The
 * differences are those of the copies the scripts had before phase 6 (plan M1), kept as they were; they only show on
 * a command line the runbook never uses (-h, "--", an unknown option, --phases without "=").
 */
final class ScriptCommandLines
{
    private const PUSH = ['confirm-push' => CommandLine::FLAG, 'dry-run' => CommandLine::FLAG];
    private const PUSH_EXTENDED = self::PUSH + ['use-extended-api' => CommandLine::FLAG];

    /** By the script's file name (basename(__FILE__)); its number decides. */
    public static function for(string $script): CommandLine
    {
        return match (substr($script, 0, 2)) {
            '01', '02', '03' => new CommandLine(
                self::PUSH,
                shortOptions: true,
                separateValues: true,
                trimValues: true,
                unknownOption: 'Unknown option provided: %s',
                doubleDash: true,
            ),
            '04' => new CommandLine(
                self::PUSH_EXTENDED,
                shortOptions: true,
                unknownOption: 'Unknown option: %s',
                optionPrefix: '--',
                doubleDash: true,
                positionalError: 'Unknown short option: %s',
            ),
            '05', '06', '07' => new CommandLine(
                self::PUSH_EXTENDED,
                shortOptions: true,
                unknownOption: 'Unknown option: %s',
                doubleDash: true,
            ),
            '08' => new CommandLine(self::PUSH_EXTENDED, shortOptions: true, unknownOption: 'Unknown option "%s".'),
            '09' => new CommandLine([
                'confirm-pull' => CommandLine::FLAG,
                'confirm-push' => CommandLine::FLAG,
                'dry-run' => CommandLine::FLAG,
                'download-limit' => CommandLine::POSITIVE_INT,
                'upload-limit' => CommandLine::POSITIVE_INT,
                'use-extended-api' => CommandLine::FLAG,
            ]),
            '10' => new CommandLine(self::PUSH_EXTENDED, shortOptions: true, falsyValueIsNull: true, skipEmpty: true),
            '11' => new CommandLine(self::PUSH_EXTENDED),
            '12' => new CommandLine(self::PUSH, unknownOption: 'Unknown option: %s', skipEmpty: true),
            '13', '14', '15' => new CommandLine(self::PUSH),
            '16' => new CommandLine([]),
            default => throw new InvalidArgumentException(sprintf('No command line for %s.', $script)),
        };
    }
}
