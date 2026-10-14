<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * B47: in a later batch, issues can hold a value that a field created by an earlier batch does not have
 * (a value only that batch's projects use, or one Jira no longer offers, B37). 08 added it in staging
 * only; the push updated an existing field's projects and trackers, not its values, so 10 held every
 * such issue back (WIKI batch: 294 issues). These are the values to add to the Redmine field.
 */
final class EnumerationGap
{
    /**
     * @param list<string> $wanted labels the issues and Jira's list hold
     * @param array<mixed> $enumerations the Redmine field's enumerations, [{id, name, ...}]
     * @return list<string> labels of $wanted that the field lacks (case-insensitive), in their order
     */
    public static function missing(array $wanted, array $enumerations): array
    {
        $known = [];
        foreach ($enumerations as $enumeration) {
            if (is_array($enumeration) && isset($enumeration['name'])) {
                $known[mb_strtolower(trim((string)$enumeration['name']), 'UTF-8')] = true;
            }
        }
        $missing = [];
        foreach ($wanted as $label) {
            $key = mb_strtolower(trim($label), 'UTF-8');
            if ($key !== '' && !isset($known[$key])) {
                $known[$key] = true;
                $missing[] = trim($label);
            }
        }

        return $missing;
    }
}
