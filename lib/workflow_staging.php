<?php

declare(strict_types=1);

use Jira2Redmine\Roles\PermissionGrants;
use Jira2Redmine\Workflows\JiraTransitions;

/**
 * What tools/workflow_proposal.php and tools/workflow_compare.php read from staging: the mappings of the structure
 * scripts, the roles 04 gives per project, group, Jira project role and application role, the roles of the holders
 * of each permission (null when 04 staged none), and per project and issue type its Jira workflow's transitions.
 *
 * @return array{trackerOf: array<int|string, int>, statusOf: array<int|string, int>, redmineProjectOf: array<string, int>,
 *     projectRoles: array<string, list<int>>, groupRoles: array<string, array<string, list<int>>>,
 *     permissionRoles: ?array<string, array<string, list<int>>>, sources: list<array{project: string, workflow: string,
 *     issue_type: string, transitions: list<array<string, mixed>>}>, problems: list<string>}
 */
function loadWorkflowStaging(PDO $pdo): array
{
    $column = static fn (string $sql): array => ($statement = $pdo->query($sql)) !== false ? $statement->fetchAll(PDO::FETCH_KEY_PAIR) : [];
    $trackerOf = array_map('intval', $column("SELECT jira_issue_type_id, redmine_tracker_id FROM migration_mapping_trackers
        WHERE redmine_tracker_id IS NOT NULL AND migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')"));
    $statusOf = array_map('intval', $column('SELECT jira_status_id, redmine_status_id FROM migration_mapping_statuses WHERE redmine_status_id IS NOT NULL'));
    $redmineProjectOf = array_map('intval', $column("SELECT p.project_key, m.redmine_project_id FROM migration_mapping_projects m
        JOIN staging_jira_projects p ON p.id = m.jira_project_id
        WHERE m.redmine_project_id IS NOT NULL AND m.migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')"));

    // Roles per project, per group, per Jira project role and of the application role, from 04's memberships.
    $projectRoles = [];
    $groupRoles = [];
    $jiraRoleRoles = [];
    $applicationRoles = [];
    $rows = $pdo->query("SELECT jira_project_key, jira_role_id, principal_type, jira_group_id, jira_group_name, redmine_role_id
        FROM migration_mapping_project_role_groups
        WHERE redmine_role_id IS NOT NULL AND migration_status IN ('READY_FOR_ASSIGNMENT', 'ASSIGNMENT_RECORDED')");
    foreach ($rows !== false ? $rows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $project = (string)$row['jira_project_key'];
        $role = (int)$row['redmine_role_id'];
        $projectRoles[$project][] = $role;
        $jiraRoleRoles[$project][(string)$row['jira_role_id']][] = $role;
        if ($row['jira_group_id'] === PermissionGrants::APPLICATION_ROLE) {
            $applicationRoles[$project][] = $role;
        } elseif ($row['principal_type'] === 'group' && $row['jira_group_name'] !== null) {
            $groupRoles[$project][mb_strtolower((string)$row['jira_group_name'], 'UTF-8')][] = $role;
        }
    }
    $unique = static fn (array $r): array => array_values(array_unique($r));
    $projectRoles = array_map($unique, $projectRoles);
    $groupRoles = array_map(static fn (array $g): array => array_map($unique, $g), $groupRoles);

    // Rule 4: the roles of the holders of each permission in each project (04 stages the holders).
    $problems = [];
    $permissionRoles = null;
    $holderRows = $pdo->query('SELECT project_key, permission, holder_type, holder_id, holder_name FROM staging_jira_project_permission_holders');
    $holders = $holderRows !== false ? $holderRows->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($holders !== []) {
        $permissionRoles = [];
        foreach ($holders as $holder) {
            $project = (string)$holder['project_key'];
            $roles = match ((string)$holder['holder_type']) {
                'group' => $groupRoles[$project][mb_strtolower((string)$holder['holder_name'], 'UTF-8')] ?? [],
                'projectRole' => $jiraRoleRoles[$project][(string)$holder['holder_id']] ?? [],
                'applicationRole' => $applicationRoles[$project] ?? [],
                default => null,
            };
            if ($roles === null) {
                $problems[] = sprintf('%s: %s granted to a %s, not mapped to a role', $project, $holder['permission'], $holder['holder_type']);
                continue;
            }
            $permissionRoles[$project][(string)$holder['permission']] = $unique(array_merge(
                $permissionRoles[$project][(string)$holder['permission']] ?? [],
                $roles
            ));
        }
    }

    // Workflows of the migrated projects: issue type => workflow through the project's workflow scheme.
    $workflows = [];
    $workflowRows = $pdo->query("SELECT workflow_name, raw_payload FROM staging_jira_workflows WHERE source = 'search'");
    foreach ($workflowRows !== false ? $workflowRows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $workflows[(string)$row['workflow_name']] = JiraTransitions::fromWorkflow(json_decode((string)$row['raw_payload'], true) ?: []);
    }
    $sources = [];
    $sourceRows = $pdo->query("SELECT p.jira_project_id, i.jira_project_key, i.jira_issue_type_id, s.default_workflow, s.issue_type_mappings
        FROM migration_mapping_projects p
        JOIN staging_jira_issue_type_projects i ON i.jira_project_id = p.jira_project_id
        LEFT JOIN staging_jira_workflow_scheme_projects s ON s.project_id = p.jira_project_id
        WHERE p.redmine_project_id IS NOT NULL AND p.migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')
        ORDER BY i.jira_project_key, i.jira_issue_type_id");
    foreach ($sourceRows !== false ? $sourceRows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        if ($row['default_workflow'] === null) {
            $problems[] = sprintf('%s: no workflow scheme exported (run 16 again)', $row['jira_project_key']);
            continue;
        }
        $mappings = json_decode((string)$row['issue_type_mappings'], true) ?: [];
        $name = (string)($mappings[$row['jira_issue_type_id']] ?? $row['default_workflow']);
        if (!isset($workflows[$name])) {
            $problems[] = sprintf('%s: workflow "%s" not exported', $row['jira_project_key'], $name);
            continue;
        }
        $sources[] = ['project' => (string)$row['jira_project_key'], 'workflow' => $name, 'issue_type' => (string)$row['jira_issue_type_id'],
            'transitions' => $workflows[$name]];
    }

    return ['trackerOf' => $trackerOf, 'statusOf' => $statusOf, 'redmineProjectOf' => $redmineProjectOf, 'projectRoles' => $projectRoles,
        'groupRoles' => $groupRoles, 'permissionRoles' => $permissionRoles, 'sources' => $sources, 'problems' => $problems];
}
