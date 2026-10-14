<?php

declare(strict_types=1);

namespace Jira2Redmine\Cli;

use RuntimeException;

/**
 * Which phases a script runs: --phases (default every phase) without --skip, in the script's own order. The 16
 * scripts each had their own rule (plan M1): 09, 11 and 13 to 16 ignored an unknown phase and ran every phase when
 * nothing was left (a typo such as --phases=pul ran jira, pull, transform and push); 01 to 03 ran the phases in the
 * order given (02 and 03 loop over them). Now one rule for all: an unknown phase, an option without a value, or
 * nothing left stops the script.
 */
final class Phases
{
    /**
     * @param list<string> $available the script's phases, in the order it runs them
     * @return list<string>
     */
    public static function select(array $available, ?string $phases, ?string $skip): array
    {
        $chosen = $phases === null ? $available : self::parse('--phases', $phases, $available);
        $skipped = $skip === null ? [] : self::parse('--skip', $skip, $available);
        $selected = array_values(array_filter(
            $available,
            static fn (string $phase): bool => in_array($phase, $chosen, true) && !in_array($phase, $skipped, true)
        ));
        if ($selected === []) {
            throw new RuntimeException('No phases left to run after --phases and --skip.');
        }

        return $selected;
    }

    /**
     * @param list<string> $available
     * @return list<string>
     */
    private static function parse(string $option, string $value, array $available): array
    {
        $names = [];
        foreach (preg_split('/[\s,]+/', $value) ?: [] as $name) {
            $name = strtolower(trim($name));
            if ($name === '') {
                continue;
            }
            if (!in_array($name, $available, true)) {
                throw new RuntimeException(
                    sprintf('Unknown phase "%s" in %s. Valid phases: %s', $name, $option, implode(', ', $available))
                );
            }
            $names[] = $name;
        }
        if ($names === []) {
            throw new RuntimeException(
                sprintf('The %s option was given without a phase. Valid phases: %s', $option, implode(', ', $available))
            );
        }

        return $names;
    }
}
