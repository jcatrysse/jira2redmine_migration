<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * Values that Jira issues hold but the field no longer offers. Jira keeps an option on old issues after
 * it is removed from the field's list (WIKI: a removed option on 294 issues, the list has a renamed
 * one). The Redmine field was created from the list only, so 10 could not set the value and held all
 * 294 issues back as MANUAL (B37). 08 now adds such values to the field and lists them, so they can be
 * retired in Redmine after the run.
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

    /**
     * One line per field and value that Jira no longer offers, over all projects and issue types.
     *
     * @param iterable<array{field_id: string, field_name: string, format: ?string, offered: array<mixed>, kept: array<mixed>}> $rows
     *        offered: the field's list in Jira for one project and issue type; kept: what 08 gives the Redmine field
     * @return list<array{field_id: string, field_name: string, format: ?string, value: string}>
     */
    public static function report(iterable $rows): array
    {
        $lines = [];
        foreach ($rows as $row) {
            foreach (self::missing($row['offered'], $row['kept']) as $value) {
                $lines[$row['field_id'] . "\0" . mb_strtolower($value, 'UTF-8')] ??= [
                    'field_id' => $row['field_id'],
                    'field_name' => $row['field_name'],
                    'format' => $row['format'],
                    'value' => $value,
                ];
            }
        }
        ksort($lines);

        return array_values($lines);
    }

    /** How to retire such a value in Redmine once the run is done; issues keep it (checked on 5.1 and 7.0). */
    public static function retireAdvice(?string $format): string
    {
        return match ($format) {
            'enumeration' => 'make the value inactive (field > Values)',
            'list' => 'remove it from the possible values',
            default => 'check the field by hand',
        };
    }

    private static function key(mixed $value): string
    {
        return is_array($value) ? mb_strtolower(trim((string)($value['value'] ?? '')), 'UTF-8') : '';
    }
}
