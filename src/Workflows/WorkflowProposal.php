<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * A Redmine workflow proposal from Jira's workflows. Jira: a workflow per project and issue type, its
 * transitions limited by group, permission or "only the assignee". Redmine: per tracker and role, which
 * status may follow which, optionally only for the author or the assignee; one workflow per tracker for
 * every project. So:
 * - a transition for a group goes to the roles that group holds in the project (04's memberships);
 * - a permission condition goes to every role of the project, noted;
 * - "only the assignee" becomes Redmine's assignee flag;
 * - trackers used by several Jira workflows get the union, and the report lists them;
 * - the rest (validators, post functions, screens, field conditions) is listed as not mappable.
 */
final class WorkflowProposal
{
    /**
     * @param list<array{project: string, workflow: string, issue_type: string, transitions: list<array<string, mixed>>}> $sources
     * @param array<int|string, int> $trackerOf Jira issue type id => Redmine tracker id
     * @param array<int|string, int> $statusOf Jira status id => Redmine status id
     * @param array<string, list<int>> $projectRoles project key => Redmine role ids of its members
     * @param array<string, array<string, list<int>>> $groupRoles project key => lower-case Jira group name => role ids
     * @return array{rows: array<string, array{tracker: int, role: int, old: int, new: int, assignee_only: bool,
     *     workflows: list<string>, projects: list<string>, transitions: list<string>, notes: list<string>}>, problems: list<string>}
     */
    public static function build(array $sources, array $trackerOf, array $statusOf, array $projectRoles, array $groupRoles): array
    {
        $rows = [];
        $problems = [];
        $trackerWorkflows = [];
        foreach ($sources as $source) {
            $tracker = $trackerOf[$source['issue_type']] ?? null;
            if ($tracker === null) {
                $problems[] = sprintf(
                    '%s: issue type %s has no Redmine tracker; workflow "%s" not proposed',
                    $source['project'],
                    $source['issue_type'],
                    $source['workflow']
                );
                continue;
            }
            $trackerWorkflows[$tracker][$source['workflow']] = true;
            foreach ($source['transitions'] as $transition) {
                $roles = self::roles($source['project'], $transition, $projectRoles, $groupRoles, $problems, $source['workflow']);
                $new = $statusOf[(string)$transition['to']] ?? null;
                if ($new === null) {
                    $problems[] = sprintf(
                        'workflow "%s": status %s has no Redmine status; transition "%s" not proposed',
                        $source['workflow'],
                        $transition['to'],
                        $transition['name']
                    );
                    continue;
                }
                $notes = $transition['not_mapped'];
                foreach ($transition['permissions'] as $permission) {
                    $notes[] = 'Jira permission ' . $permission . ' (given to every role of the project)';
                }
                foreach ($transition['from'] as $from) {
                    $old = $from === '0' ? 0 : ($statusOf[$from] ?? null);
                    if ($old === null) {
                        $problems[] = sprintf(
                            'workflow "%s": status %s has no Redmine status; transition "%s" from it not proposed',
                            $source['workflow'],
                            $from,
                            $transition['name']
                        );
                        continue;
                    }
                    if ($old === $new) {
                        continue;
                    }
                    foreach ($roles as $role) {
                        $key = implode('|', [$tracker, $role, $old, $new, $transition['assignee_only'] ? 1 : 0]);
                        $rows[$key] ??= ['tracker' => $tracker, 'role' => $role, 'old' => $old, 'new' => $new,
                            'assignee_only' => (bool)$transition['assignee_only'], 'workflows' => [], 'projects' => [],
                            'transitions' => [], 'notes' => []];
                        $rows[$key]['workflows'] = self::add($rows[$key]['workflows'], [$source['workflow']]);
                        $rows[$key]['projects'] = self::add($rows[$key]['projects'], [$source['project']]);
                        $rows[$key]['transitions'] = self::add($rows[$key]['transitions'], [(string)$transition['name']]);
                        $rows[$key]['notes'] = self::add($rows[$key]['notes'], $notes);
                    }
                }
            }
        }
        foreach ($trackerWorkflows as $tracker => $workflows) {
            if (count($workflows) > 1) {
                $names = array_keys($workflows);
                sort($names);
                $problems[] = sprintf(
                    'tracker #%d: %d Jira workflows (%s); Redmine has one workflow per tracker, the proposal is their union',
                    $tracker,
                    count($names),
                    implode(', ', $names)
                );
            }
        }
        ksort($rows);
        $problems = array_values(array_unique($problems));

        return ['rows' => $rows, 'problems' => $problems];
    }

    /**
     * @param array<string, mixed> $transition
     * @param array<string, list<int>> $projectRoles
     * @param array<string, array<string, list<int>>> $groupRoles
     * @param list<string> $problems
     * @return list<int>
     */
    private static function roles(
        string $project,
        array $transition,
        array $projectRoles,
        array $groupRoles,
        array &$problems,
        string $workflow
    ): array {
        if ($transition['groups'] === []) {
            return $projectRoles[$project] ?? [];
        }
        $roles = [];
        foreach ($transition['groups'] as $group) {
            $held = $groupRoles[$project][mb_strtolower($group, 'UTF-8')] ?? [];
            if ($held === []) {
                $problems[] = sprintf(
                    '%s, workflow "%s": group "%s" is not a member of the project; its transitions go to no role',
                    $project,
                    $workflow,
                    $group
                );
            }
            $roles = array_merge($roles, $held);
        }
        $roles = array_values(array_unique($roles));
        sort($roles);

        return $roles;
    }

    /**
     * @param list<string> $list
     * @param list<string> $more
     * @return list<string>
     */
    private static function add(array $list, array $more): array
    {
        $merged = array_values(array_unique(array_merge($list, $more)));
        sort($merged);

        return $merged;
    }
}
