<?php

declare(strict_types=1);

namespace Jira2Redmine\Priorities;

/**
 * 06's proposal for each Jira priority (Jan, decision 35: one priority scheme for every project): the Redmine
 * priority `migration.priorities.mappings` names for it, else the one of the same name (case aside); anything
 * else is a review row Jan maps by hand. A new priority only with `migration.priorities.create_missing`.
 * A new priority gets no position of its own (B73): Redmine's API lists priorities without one, so 06 counted
 * none and proposed 1, 2, 3, and Redmine inserted the new ones there, below every existing priority (the new
 * "Blocker" became the lowest). Without a position Redmine puts it after the existing ones and moves none.
 * A position set by hand on the mapping row is kept.
 */
final class PriorityProposal
{
    public const UNMAPPED_NOTE = 'No Redmine priority named "%s": map it in migration.priorities.mappings, or set '
        . 'redmine_priority_id and MATCH_FOUND on this row (decision 35).';
    public const UNKNOWN_TARGET_NOTE = 'migration.priorities.mappings maps "%s" to "%s", which this Redmine does not have.';

    /** @var array<string, string> lowercased Jira name => Redmine name */
    private array $mappings = [];

    /**
     * @param array<string, array{id: int, name: string, is_default: ?bool, position: ?int}> $redmineByName the
     *     Redmine priorities by lowercased name
     * @param array<mixed> $mappings `migration.priorities.mappings`: Jira name => Redmine name
     */
    public function __construct(private readonly array $redmineByName, array $mappings = [], private readonly bool $createMissing = false)
    {
        foreach ($mappings as $jiraName => $redmineName) {
            if (is_string($redmineName) && trim($redmineName) !== '') {
                $this->mappings[strtolower(trim((string)$jiraName))] = trim($redmineName);
            }
        }
    }

    /**
     * @param string|null $jiraName the Jira name, trimmed; null when Jira gave none
     * @return array{status: string, redmine_id: ?int, name: string, is_default: bool, position: ?int, notes: ?string}
     */
    public function propose(string $jiraId, ?string $jiraName, ?string $currentName, ?bool $currentIsDefault, ?int $currentPosition): array
    {
        $name = $currentName ?? $jiraName;
        $isDefault = $currentIsDefault ?? false;
        $position = $currentPosition;
        $redmineId = null;
        $notes = null;
        $mapped = $jiraName === null ? null : ($this->mappings[strtolower($jiraName)] ?? null);
        $match = $jiraName === null ? null : ($this->redmineByName[strtolower($mapped ?? $jiraName)] ?? null);
        if ($jiraName === null) {
            $status = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Missing Jira priority name in the staging snapshot.';
        } elseif ($mapped !== null && $match === null) {
            $status = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = sprintf(self::UNKNOWN_TARGET_NOTE, $jiraName, $mapped);
        } elseif ($match !== null) {
            $status = 'MATCH_FOUND';
            $redmineId = $match['id'];
            $name = $match['name'];
            $isDefault = $match['is_default'] ?? false;
            $position = $match['position'];
        } elseif ($this->createMissing) {
            $status = 'READY_FOR_CREATION';
            $name = $jiraName;
        } else {
            $status = 'MANUAL_INTERVENTION_REQUIRED';
            $name = $jiraName;
            $notes = sprintf(self::UNMAPPED_NOTE, $jiraName);
        }
        $name ??= $jiraId;

        return ['status' => $status, 'redmine_id' => $redmineId, 'name' => $name, 'is_default' => $isDefault,
            'position' => $position, 'notes' => $notes];
    }
}
