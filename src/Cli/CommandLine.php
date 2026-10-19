<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use RuntimeException;

/**
 * The option parsing of the 16 scripts, one rule for all (decision 43): every script knows -h/--help, -V/--version,
 * --phases=LIST, --skip=LIST and its own options (ScriptCommandLines); anything else stops the script with an error,
 * --help or not: an unknown option, an option without its value, a positional or an empty argument. Until decision 43
 * each script kept the rule of its own copy (phase 6, tests/fixtures/command_lines.json).
 *
 * The result holds help, version, phases and skip, then the script's own options in its order, with an option's
 * dashes as underscores (confirm-push => confirm_push).
 */
final class CommandLine
{
    public const FLAG = 'flag';
    public const POSITIVE_INT = 'positive int';

    /**
     * @param array<string, self::FLAG|self::POSITIVE_INT> $options the script's own options, by name without dashes
     */
    public function __construct(private readonly array $options)
    {
    }

    /**
     * @param array<int, mixed> $argv the script's own name first, as in $_SERVER['argv']
     * @return array<string, bool|int|string|null>
     */
    public function parse(array $argv): array
    {
        $options = ['help' => false, 'version' => false, 'phases' => null, 'skip' => null];
        $values = ['phases' => 'LIST', 'skip' => 'LIST'];
        foreach ($this->options as $name => $kind) {
            $options[self::key($name)] = $kind === self::FLAG ? false : null;
            if ($kind === self::POSITIVE_INT) {
                $values[$name] = 'N';
            }
        }
        $arguments = array_map(static fn (mixed $argument): string => (string)$argument, array_values($argv));

        foreach (array_slice($arguments, 1) as $argument) {
            if ($argument === '--help' || $argument === '-h') {
                $options['help'] = true;
                continue;
            }
            if ($argument === '--version' || $argument === '-V') {
                $options['version'] = true;
                continue;
            }
            foreach ($values as $name => $placeholder) {
                if ($argument === '--' . $name) {
                    throw new RuntimeException(
                        sprintf('The --%s option requires a value: --%s=%s', $name, $name, $placeholder)
                    );
                }
                if (str_starts_with($argument, '--' . $name . '=')) {
                    $value = substr($argument, strlen($name) + 3);
                    $options[self::key($name)] = $placeholder === 'N' ? self::positiveInt($value, '--' . $name) : $value;
                    continue 2;
                }
            }
            if (str_starts_with($argument, '--') && ($this->options[substr($argument, 2)] ?? null) === self::FLAG) {
                $options[self::key(substr($argument, 2))] = true;
                continue;
            }
            if (str_starts_with($argument, '-')) {
                throw new RuntimeException(sprintf('Unknown option: %s', $argument));
            }

            throw new RuntimeException(sprintf('Unexpected positional argument: "%s"', $argument));
        }

        return $options;
    }

    private static function key(string $name): string
    {
        return str_replace('-', '_', $name);
    }

    /** 09's limits: digits only, spaces around them allowed, above zero. */
    private static function positiveInt(string $value, string $option): int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !preg_match('/^\d+$/', $trimmed)) {
            throw new RuntimeException(sprintf('Invalid %s value: %s', $option, $value));
        }
        $number = (int)$trimmed;
        if ($number <= 0) {
            throw new RuntimeException(sprintf('%s expects a positive integer.', $option));
        }

        return $number;
    }
}
