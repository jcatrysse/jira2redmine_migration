<?php

declare(strict_types=1);

namespace Jira2Redmine\Workflows;

/**
 * What tools/apply_workflows.rb writes into Redmine, from the three review tables of the workflow proposal
 * (Jan, decision 13): only PROPOSED rows; a REVIEW row waits for Jan, an IGNORED row is left out.
 * - transitions: per tracker and role (and project), exactly the approved transitions; every tracker and
 *   role that has a row (any status) is managed, so its temporary open workflow is replaced; a role 0 row
 *   (group not a member) counts only with the review_role_id Jan gave it. Mode "project" (Jan, 2026-10-14,
 *   redmine_project_workflows): each project gets its own Jira workflow (rows with a project id), the
 *   union (project 0 rows, cleaned by Jan) is the global workflow for projects without their own; mode
 *   "union": only the global workflow;
 * - field permissions: added or updated, never removed (a rule set by hand in Redmine stays);
 * - scripts: the custom workflows named "j2r: ..."; one no longer in the plan is removed.
 */
final class WorkflowPlan
{
    /**
     * @param list<array<string, mixed>> $transitions rows of migration_mapping_workflow_transitions
     * @param list<array<string, mixed>> $fieldPermissions rows of migration_mapping_workflow_field_permissions
     * @param list<array<string, mixed>> $scripts rows of migration_mapping_workflow_scripts
     * @param string $mode project or union
     * @param list<array{0: int, 1: int, 2: int}> $projectSets every project, tracker and role of the projects with their own
     *     workflow (mode project): a set without approved transitions is managed too, an own empty workflow, so the
     *     role does not fall back to the global union (18 sets on 7.0 offered transitions Jira does not have)
     * @return array{mode: string, transitions: list<array{project_id: ?int, tracker_id: int, role_id: int, old_status_id: int,
     *     new_status_id: int, author: bool, assignee: bool}>, managed: list<array{0: ?int, 1: int, 2: int}>,
     *     field_permissions: list<array{tracker_id: int, role_id: int,
     *     old_status_id: int, field_name: string, rule: string}>, scripts: list<array{name: string, tracker_id: int, status_id: int,
     *     project_ids: list<int>, script: string}>, waiting: array{transitions: int, field_permissions: int, scripts: int}}
     */
    public static function fromRows(
        array $transitions,
        array $fieldPermissions,
        array $scripts,
        string $mode = 'project',
        array $projectSets = []
    ): array {
        if (!in_array($mode, ['project', 'union'], true)) {
            throw new \InvalidArgumentException('migration.workflows.mode is project or union, not ' . $mode);
        }
        $plan = ['mode' => $mode, 'transitions' => [], 'managed' => [], 'field_permissions' => [], 'scripts' => [],
            'waiting' => ['transitions' => 0, 'field_permissions' => 0, 'scripts' => 0]];
        $managed = [];
        $seen = [];
        foreach ($transitions as $row) {
            $project = (int)($row['redmine_project_id'] ?? 0);
            if ($mode === 'union' && $project !== 0) {
                continue;
            }
            $projectId = $project === 0 ? null : $project;
            $role = (int)$row['redmine_role_id'];
            if ($role === WorkflowProposal::NO_ROLE) {
                $role = (int)($row['review_role_id'] ?? 0);
                if ($role === 0) {
                    $plan['waiting']['transitions'] += $row['migration_status'] === 'REVIEW' ? 1 : 0;
                    continue;
                }
            }
            $tracker = (int)$row['redmine_tracker_id'];
            $managed[$project . '|' . $tracker . '|' . $role] = [$projectId, $tracker, $role];
            if ($row['migration_status'] !== 'PROPOSED') {
                $plan['waiting']['transitions'] += $row['migration_status'] === 'REVIEW' ? 1 : 0;
                continue;
            }
            $transition = ['project_id' => $projectId, 'tracker_id' => $tracker, 'role_id' => $role,
                'old_status_id' => (int)$row['redmine_old_status_id'],
                'new_status_id' => (int)$row['redmine_new_status_id'], 'author' => false, 'assignee' => (bool)$row['only_assignee']];
            $key = implode('|', array_map('json_encode', $transition));
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $plan['transitions'][] = $transition;
            }
        }
        if ($mode === 'project') {
            foreach ($projectSets as [$project, $tracker, $role]) {
                $managed[(int)$project . '|' . (int)$tracker . '|' . (int)$role] ??= [(int)$project, (int)$tracker, (int)$role];
            }
        }
        ksort($managed);
        $plan['managed'] = array_values($managed);
        foreach ($fieldPermissions as $row) {
            if ($row['migration_status'] !== 'PROPOSED') {
                $plan['waiting']['field_permissions'] += $row['migration_status'] === 'REVIEW' ? 1 : 0;
                continue;
            }
            $plan['field_permissions'][] = ['tracker_id' => (int)$row['redmine_tracker_id'], 'role_id' => (int)$row['redmine_role_id'],
                'old_status_id' => (int)$row['redmine_status_id'], 'field_name' => (string)$row['field_name'],
                'rule' => (string)$row['rule']];
        }
        foreach ($scripts as $row) {
            if ($row['migration_status'] !== 'PROPOSED') {
                $plan['waiting']['scripts'] += $row['migration_status'] === 'REVIEW' ? 1 : 0;
                continue;
            }
            $projects = array_values(array_filter(array_map('intval', explode(',', (string)$row['redmine_project_ids']))));
            $plan['scripts'][] = ['name' => WorkflowScripts::name((int)$row['redmine_tracker_id'], (int)$row['redmine_status_id']),
                'tracker_id' => (int)$row['redmine_tracker_id'], 'status_id' => (int)$row['redmine_status_id'], 'project_ids' => $projects,
                'script' => (string)$row['script']];
        }

        return $plan;
    }
}
