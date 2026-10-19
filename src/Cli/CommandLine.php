<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use RuntimeException;

/**
 * The option parsing of the 16 scripts (plan M1). Each script had its own copy; they agree on the valid command lines
 * (--help, --version, --phases=LIST, --skip=LIST and the script's own options) and differ on the rest. The parameters
 * keep each script's own rule, so moving the copies here changed nothing (tests/fixtures/command_lines.json, made from
 * the copies); ScriptCommandLines names the rule per script.
 *
 * Every script knows --help, --version, --phases=LIST and --skip=LIST. The result holds those four, then the script's
 * own options in the order given, with an option's dashes as underscores (confirm-push => confirm_push).
 */
final class CommandLine
{
    public const FLAG = 'flag';
    public const POSITIVE_INT = 'positive int';

    /**
     * @param array<string, self::FLAG|self::POSITIVE_INT> $options the script's own options, by name without dashes
     * @param bool $shortOptions -h and -V as well (01 to 08, 10)
     * @param bool $separateValues --phases LIST and --skip LIST as well (01 to 03)
     * @param bool $trimValues the value of --phases and --skip without surrounding spaces (01 to 03)
     * @param bool $falsyValueIsNull an empty --phases= or --skip= (or "0") counts as not given (10)
     * @param string|null $unknownOption the message for an unknown option; null: it is a positional argument
     * @param string $optionPrefix what makes an argument an option for that message: "-", or "--" in 04
     * @param bool $doubleDash "--" ends the options, the rest is positional (01 to 07)
     * @param bool $skipEmpty an empty argument is left out (10, 12)
     * @param string|null $positionalError the message for any positional argument (04: there are none)
     */
    public function __construct(
        private readonly array $options,
        private readonly bool $shortOptions = false,
        private readonly bool $separateValues = false,
        private readonly bool $trimValues = false,
        private readonly bool $falsyValueIsNull = false,
        private readonly ?string $unknownOption = null,
        private readonly string $optionPrefix = '-',
        private readonly bool $doubleDash = false,
        private readonly bool $skipEmpty = false,
        private readonly ?string $positionalError = null,
    ) {
    }

    /**
     * @param array<int, mixed> $argv the script's own name first, as in $_SERVER['argv']
     * @return array{0: array<string, bool|int|string|null>, 1: list<string>} the options and the positional arguments
     */
    public function parse(array $argv): array
    {
        $options = ['help' => false, 'version' => false, 'phases' => null, 'skip' => null];
        foreach ($this->options as $name => $kind) {
            $options[self::key($name)] = $kind === self::FLAG ? false : null;
        }
        $arguments = array_map(static fn (mixed $argument): string => (string)$argument, array_values($argv));
        $positional = [];
        $count = count($arguments);

        for ($index = 1; $index < $count; $index++) {
            $argument = $arguments[$index];
            if ($this->doubleDash && $argument === '--') {
                $positional = array_merge($positional, array_slice($arguments, $index + 1));
                break;
            }
            if ($this->skipEmpty && $argument === '') {
                continue;
            }
            if ($argument === '--help' || ($this->shortOptions && $argument === '-h')) {
                $options['help'] = true;
                continue;
            }
            if ($argument === '--version' || ($this->shortOptions && $argument === '-V')) {
                $options['version'] = true;
                continue;
            }
            foreach (['phases', 'skip'] as $name) {
                if ($this->separateValues && $argument === '--' . $name) {
                    if (++$index >= $count) {
                        throw new RuntimeException(sprintf('The --%s option requires a value.', $name));
                    }
                    $options[$name] = $this->value($arguments[$index]);
                    continue 2;
                }
                if (str_starts_with($argument, '--' . $name . '=')) {
                    $options[$name] = $this->value(substr($argument, strlen($name) + 3));
                    continue 2;
                }
            }
            foreach ($this->options as $name => $kind) {
                if ($kind === self::FLAG && $argument === '--' . $name) {
                    $options[self::key($name)] = true;
                    continue 2;
                }
                if ($kind === self::POSITIVE_INT && str_starts_with($argument, '--' . $name . '=')) {
                    $options[self::key($name)] = self::positiveInt(substr($argument, strlen($name) + 3), '--' . $name);
                    continue 2;
                }
            }
            if ($this->unknownOption !== null && $argument !== '' && str_starts_with($argument, $this->optionPrefix)) {
                throw new RuntimeException(sprintf($this->unknownOption, $argument));
            }
            if ($this->positionalError !== null) {
                throw new RuntimeException(sprintf($this->positionalError, $argument));
            }
            $positional[] = $argument;
        }

        return [$options, $positional];
    }

    private function value(string $value): ?string
    {
        if ($this->trimValues) {
            return trim($value);
        }
        if ($this->falsyValueIsNull) {
            return $value ?: null;
        }

        return $value;
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
