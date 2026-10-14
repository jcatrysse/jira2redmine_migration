<?php

declare(strict_types=1);

namespace Jira2Redmine\Issues;

/**
 * Issues whose Jira parent has no Redmine issue yet stay READY_FOR_REWRITE; the run that migrates the
 * parent's project links them (B38, decided overnight 2026-10-13/14, for Jan to confirm: "cross-project
 * subtasks: with all projects" stays on). This is the list of parents that have not arrived, per project,
 * printed by every 10 run so a parent that never arrives does not go unnoticed.
 */
final class WaitingParents
{
    /**
     * @param iterable<array{issue_key: string, parent_key: ?string}> $rows
     * @return array{per_project: array<string, int>, lines: list<string>}
     */
    public static function report(iterable $rows): array
    {
        $perProject = [];
        $lines = [];
        foreach ($rows as $row) {
            $parent = trim((string)$row['parent_key']);
            $project = $parent !== '' && str_contains($parent, '-') ? substr($parent, 0, (int)strrpos($parent, '-')) : '?';
            $perProject[$project] = ($perProject[$project] ?? 0) + 1;
            $lines[] = sprintf('%s waits for its parent %s', $row['issue_key'], $parent !== '' ? $parent : '(key unknown)');
        }
        ksort($perProject);

        return ['per_project' => $perProject, 'lines' => $lines];
    }
}
