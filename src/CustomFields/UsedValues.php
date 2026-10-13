<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * Values that Jira issues hold but the field no longer offers. Jira keeps an option on old issues after
 * it is removed from the field's list (WIKI: Department "Survey", option 10748, on 294 issues; the list
 * has "Survey Department"). The Redmine field was created from the list only, so 10 could not set the
 * value and held all 294 issues back as MANUAL (B37). 08 now adds such values to the field.
 */
final class UsedValues
{
    /**
     * @param array<mixed> $offered normalised flat payload {mode: flat, values: [{id, value}]} from Jira's list
     * @param array<mixed> $used the same shape, derived from the staged issues
     * @return list<string> labels of $used missing from $offered (case-insensitive), in their order
     */
    public static function missing(array $offered, array $used): array
    {
        if (($offered['mode'] ?? null) !== 'flat' || ($used['mode'] ?? null) !== 'flat') {
            return [];
        }
        $known = [];
        foreach ($offered['values'] ?? [] as $value) {
            $known[self::key($value)] = true;
        }
        $missing = [];
        foreach ($used['values'] ?? [] as $value) {
            $key = self::key($value);
            if ($key !== '' && !isset($known[$key])) {
                $known[$key] = true;
                $missing[] = trim((string)$value['value']);
            }
        }

        return $missing;
    }

    private static function key(mixed $value): string
    {
        return is_array($value) ? mb_strtolower(trim((string)($value['value'] ?? '')), 'UTF-8') : '';
    }
}
