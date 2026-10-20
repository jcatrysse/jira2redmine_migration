<?php

declare(strict_types=1);

namespace Jira2Redmine\CustomFields;

/**
 * The labels of a label app field ("label-manager-for-jira": {"labels": [...], "colors": [...]}). The app stores some
 * selections of several labels as one comma-joined label, its colours joined the same way ("Geo_Ocean_IV,
 * Geo_Ocean_VII" with "light-grey, light-grey"): 08 made such a joined label one value of the Redmine field, or none
 * at all above Redmine's 60 characters, and 10 held the issue back (4 SURV issues, B77). Each label counts on its own.
 */
final class LabelManagerLabels
{
    /**
     * @return list<string> the labels of one entry of the "labels" list, trimmed, empty ones left out
     */
    public static function split(mixed $label): array
    {
        if (!is_scalar($label)) {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', (string)$label)),
            static fn (string $part): bool => $part !== ''
        ));
    }

    /**
     * @param array<mixed> $entries the "labels" list
     * @return list<string> every label of the list, in order
     */
    public static function all(array $entries): array
    {
        $labels = [];
        foreach ($entries as $entry) {
            array_push($labels, ...self::split($entry));
        }

        return $labels;
    }

    /** A field of the label app, by its Jira type ("...static/label-manager-for-jira"). */
    public static function isApp(?string $schemaCustom): bool
    {
        return $schemaCustom !== null && str_contains($schemaCustom, 'label-manager-for-jira');
    }

    /**
     * Whether an issue's value of a label app field holds a label. Jira gives every issue in a project that has the
     * field an object, also without labels ({"labels": [], "colors": []}): 08's usage counted those as values (B91,
     * "Remotely Operated Towed Vehicle": 111 issues counted, 14 with a label in the 14 projects).
     */
    public static function hasLabels(mixed $value): bool
    {
        return is_array($value) && is_array($value['labels'] ?? null) && self::all($value['labels']) !== [];
    }
}
