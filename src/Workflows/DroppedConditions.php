<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * Jan, decision 19 (2026-10-14): a Jira condition Redmine cannot carry is left out on purpose, per project, so the
 * transition is proposed without it instead of waiting for review (FPS: "Screen" only with one option of a select
 * field, ValueFieldCondition). migration.workflows.dropped_conditions: project key => Jira condition types. The
 * proposal and the comparison with Jira both read it here.
 */
final class DroppedConditions
{
    /**
     * @return array<string, list<string>> project key => condition types
     */
    public static function fromConfig(mixed $configured): array
    {
        $dropped = [];
        foreach (is_array($configured) ? $configured : [] as $project => $types) {
            foreach (is_array($types) ? $types : [$types] as $type) {
                $type = trim((string)$type);
                if ($type !== '') {
                    $dropped[trim((string)$project)][] = $type;
                }
            }
        }

        return $dropped;
    }

    /**
     * The transition's notes of rules not carried, without the conditions Jan dropped in this project.
     *
     * @param list<string> $notMapped JiraTransitions' not_mapped ("condition <type>", "validator <type>", ...)
     * @param array<string, list<string>> $dropped project key => condition types
     * @return list<string>
     */
    public static function filter(array $notMapped, string $project, array $dropped): array
    {
        $left = array_map(static fn (string $type): string => 'condition ' . $type, $dropped[$project] ?? []);

        return array_values(array_filter($notMapped, static fn (string $note): bool => !in_array($note, $left, true)));
    }
}
