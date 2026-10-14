<?php

declare(strict_types=1);

/**
 * Redmine workflow proposal from the Jira workflows (after 04, 05, 07 and 16).
 *
 *   php tools/workflow_proposal.php [--report=FILE]
 *
 * Writes three review tables (Jan, decision 13), each row PROPOSED, or REVIEW when there is something to
 * decide (in notes); a row edited by hand (status, notes, and the role of a transition row) keeps its edit on
 * a rerun; a row no longer proposed is removed unless edited:
 * - migration_mapping_workflow_transitions: one row per tracker, role, old and new status (and "only the
 *   assignee"); a transition limited to a group that is not a member of the project has role 0: Jan sets
 *   review_role_id and PROPOSED to give it to that role, or IGNORED;
 * - migration_mapping_workflow_field_permissions: fields a transition screen requires, per tracker, role and
 *   target status;
 * - migration_mapping_workflow_scripts: "assign to the current user" as redmine_custom_workflows scripts.
 * tools/apply_workflows.rb writes the PROPOSED rows into Redmine. The report lists per tracker what is proposed
 * and everything that cannot be mapped. It holds Jira workflow, group and status names: keep it local.
 */

use Jira2Redmine\Roles\PermissionGrants;
use Jira2Redmine\Workflows\JiraTransitions;
use Jira2Redmine\Workflows\WorkflowProposal;
use Jira2Redmine\Workflows\WorkflowScripts;

$config = require __DIR__ . '/../bootstrap.php';
$options = getopt('', ['report::']);
$reportPath = (string)($options['report'] ?? rtrim((string)$config['paths']['tmp'], '/') . '/workflow-proposal.md');
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$column = static fn (string $sql): array => $pdo->query($sql)->fetchAll(PDO::FETCH_KEY_PAIR);

// Mappings of the structure scripts.
$trackerOf = array_map('intval', $column("SELECT jira_issue_type_id, redmine_tracker_id FROM migration_mapping_trackers
    WHERE redmine_tracker_id IS NOT NULL AND migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')"));
$statusOf = array_map('intval', $column('SELECT jira_status_id, redmine_status_id FROM migration_mapping_statuses WHERE redmine_status_id IS NOT NULL'));
$trackerNames = $column('SELECT redmine_tracker_id, MIN(COALESCE(proposed_redmine_name, jira_issue_type_name)) FROM migration_mapping_trackers
    WHERE redmine_tracker_id IS NOT NULL GROUP BY redmine_tracker_id');
$statusNames = $column('SELECT redmine_status_id, MIN(COALESCE(proposed_redmine_name, jira_status_name)) FROM migration_mapping_statuses
    WHERE redmine_status_id IS NOT NULL GROUP BY redmine_status_id') + [0 => '(new issue)'];
$roleNames = $column('SELECT id, name FROM staging_redmine_roles') + [WorkflowProposal::NO_ROLE => '(no role: group not a member)'];
$redmineProjectOf = array_map('intval', $column("SELECT p.project_key, m.redmine_project_id FROM migration_mapping_projects m
    JOIN staging_jira_projects p ON p.id = m.jira_project_id
    WHERE m.redmine_project_id IS NOT NULL AND m.migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')"));

// Roles per project, per group, per Jira project role and of the application role, from 04's memberships.
$projectRoles = [];
$groupRoles = [];
$jiraRoleRoles = [];
$applicationRoles = [];
foreach (
    $pdo->query("SELECT jira_project_key, jira_role_id, principal_type, jira_group_id, jira_group_name, redmine_role_id
    FROM migration_mapping_project_role_groups
    WHERE redmine_role_id IS NOT NULL AND migration_status IN ('READY_FOR_ASSIGNMENT', 'ASSIGNMENT_RECORDED')") as $row
) {
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
$context = [];
$problems = [];
$holders = $pdo->query('SELECT project_key, permission, holder_type, holder_id, holder_name FROM staging_jira_project_permission_holders')
    ->fetchAll();
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
    $context['permission_roles'] = $permissionRoles;
}

// Rule 3: the fields on each screen, which field configurations require them, and their Redmine field.
$screenFields = [];
foreach ($pdo->query('SELECT screen_id, field_id FROM staging_jira_screen_tab_fields') as $row) {
    $screenFields[(string)$row['screen_id']][] = (string)$row['field_id'];
}
$context['screen_fields'] = $screenFields;
$configurationNames = $column('SELECT configuration_id, name FROM staging_jira_field_configurations');
$items = $pdo->query('SELECT configuration_id, field_id, is_required FROM staging_jira_field_configuration_items')->fetchAll();
if ($items !== []) {
    $configurations = count(array_unique(array_column($items, 'configuration_id')));
    $rules = [];
    foreach ($items as $item) {
        $rules[(string)$item['field_id']] ??= ['required_in' => [], 'configurations' => $configurations];
        if ((int)$item['is_required'] === 1) {
            $rules[(string)$item['field_id']]['required_in'][] = (string)($configurationNames[$item['configuration_id']] ?? $item['configuration_id']);
        }
    }
    $context['field_rules'] = $rules;
}
// Jira system fields Redmine has an attribute for; the rest of the system fields has none
$context['field_targets'] = array_map('strval', $column("SELECT jira_field_id, redmine_custom_field_id FROM migration_mapping_custom_fields
    WHERE redmine_custom_field_id IS NOT NULL AND migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')")) + [
    'assignee' => 'assigned_to_id', 'duedate' => 'due_date', 'priority' => 'priority_id', 'description' => 'description',
    'summary' => 'subject', 'timeoriginalestimate' => 'estimated_hours', 'timetracking' => 'estimated_hours',
    'parent' => 'parent_issue_id', 'startdate' => 'start_date',
];

// Workflows of the migrated projects: issue type => workflow through the project's workflow scheme.
$workflows = [];
foreach ($pdo->query("SELECT workflow_name, raw_payload FROM staging_jira_workflows WHERE source = 'search'") as $row) {
    $workflows[(string)$row['workflow_name']] = JiraTransitions::fromWorkflow(json_decode((string)$row['raw_payload'], true) ?: []);
}
$sources = [];
$sql = "SELECT p.jira_project_id, i.jira_project_key, i.jira_issue_type_id, s.default_workflow, s.issue_type_mappings
    FROM migration_mapping_projects p
    JOIN staging_jira_issue_type_projects i ON i.jira_project_id = p.jira_project_id
    LEFT JOIN staging_jira_workflow_scheme_projects s ON s.project_id = p.jira_project_id
    WHERE p.redmine_project_id IS NOT NULL AND p.migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')
    ORDER BY i.jira_project_key, i.jira_issue_type_id";
foreach ($pdo->query($sql) as $row) {
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

$proposal = WorkflowProposal::build($sources, $trackerOf, $statusOf, $projectRoles, $groupRoles, $context);
$problems = array_values(array_unique(array_merge($problems, $proposal['problems'])));

/**
 * Writes the proposed rows of one review table; a row edited by hand (status, notes, or an edit column) keeps
 * its edit, a row no longer proposed is removed unless edited.
 *
 * @param array<string, array<string, mixed>> $proposed key => column values; 'notes' (list) decides PROPOSED or REVIEW
 * @param list<string> $keyColumns the columns of the unique key, in the order of the proposal's keys
 * @param list<string> $editColumns columns a reviewer may change besides status and notes (in the hash)
 * @return array{PROPOSED: int, REVIEW: int, 'kept edits': int, removed: int}
 */
function writeReviewTable(PDO $pdo, string $table, array $proposed, array $keyColumns, array $editColumns = []): array
{
    $hash = static function (array $row) use ($editColumns): string {
        $parts = [(string)$row['migration_status'], $row['notes'] ?? 'null'];
        foreach ($editColumns as $column) {
            // an empty edit column leaves the hash as it was before the column existed
            if (($row[$column] ?? '') !== '' && $row[$column] !== null) {
                $parts[] = $column . '=' . $row[$column];
            }
        }

        return hash('sha256', implode('|', $parts));
    };
    $existing = [];
    foreach ($pdo->query("SELECT * FROM $table") ?: [] as $row) {
        $key = implode('|', array_map(static fn (string $c): string => (string)$row[$c], $keyColumns));
        $existing[$key] = $row + ['edited' => $row['automation_hash'] !== $hash($row)];
    }
    $counts = ['PROPOSED' => 0, 'REVIEW' => 0, 'kept edits' => 0, 'removed' => 0];
    foreach ($proposed as $key => $values) {
        $notes = $values['notes'];
        unset($values['notes']);
        $values['migration_status'] = $notes === [] ? 'PROPOSED' : 'REVIEW';
        $values['notes'] = $notes === [] ? null : implode('; ', $notes);
        if (isset($existing[$key]) && $existing[$key]['edited']) {
            // only the Jira references follow the proposal
            $refresh = array_filter($values, static fn (string $c): bool => str_starts_with($c, 'jira_'), ARRAY_FILTER_USE_KEY);
            if ($refresh !== []) {
                $set = implode(', ', array_map(static fn (string $c): string => "$c = :$c", array_keys($refresh)));
                $pdo->prepare("UPDATE $table SET $set WHERE id = :id")->execute($refresh + ['id' => $existing[$key]['id']]);
            }
            $counts['kept edits']++;
        } else {
            $values['automation_hash'] = $hash($values);
            $columns = array_keys($values);
            $update = implode(', ', array_map(static fn (string $c): string => "$c = VALUES($c)", array_diff($columns, $keyColumns)));
            $pdo->prepare(sprintf(
                'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
                $table,
                implode(', ', $columns),
                implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns)),
                $update
            ))->execute($values);
            $counts[$values['migration_status']]++;
        }
        unset($existing[$key]);
    }
    foreach ($existing as $row) {
        if (!$row['edited']) {
            $pdo->prepare("DELETE FROM $table WHERE id = :id")->execute(['id' => $row['id']]);
            $counts['removed']++;
        }
    }

    return $counts;
}

$transitionRows = [];
foreach ($proposal['rows'] as $key => $row) {
    $transitionRows[$key] = ['redmine_tracker_id' => $row['tracker'], 'redmine_role_id' => $row['role'], 'redmine_old_status_id' => $row['old'],
        'redmine_new_status_id' => $row['new'], 'only_assignee' => $row['assignee_only'] ? 1 : 0,
        'jira_workflows' => implode(', ', $row['workflows']), 'jira_projects' => implode(', ', $row['projects']),
        'jira_transitions' => implode(', ', $row['transitions']), 'notes' => $row['notes']];
}
$counts = writeReviewTable($pdo, 'migration_mapping_workflow_transitions', $transitionRows, ['redmine_tracker_id', 'redmine_role_id',
    'redmine_old_status_id', 'redmine_new_status_id', 'only_assignee'], ['review_role_id']);

$permissionRows = [];
foreach ($proposal['field_permissions'] as $key => $row) {
    $permissionRows[$key] = ['redmine_tracker_id' => $row['tracker'], 'redmine_role_id' => $row['role'], 'redmine_status_id' => $row['status'],
        'field_name' => $row['field'], 'rule' => $row['rule'], 'jira_field_id' => $row['jira_field'],
        'jira_workflows' => implode(', ', $row['workflows']), 'jira_projects' => implode(', ', $row['projects']), 'notes' => $row['notes']];
}
$permissionCounts = writeReviewTable($pdo, 'migration_mapping_workflow_field_permissions', $permissionRows, ['redmine_tracker_id',
    'redmine_role_id', 'redmine_status_id', 'field_name'], ['rule']);

$scriptRows = [];
foreach ($proposal['scripts'] as $key => $row) {
    $projects = array_values(array_filter(array_map(static fn (string $p): ?int => $redmineProjectOf[$p] ?? null, $row['projects'])));
    $scriptRows[$key] = ['redmine_tracker_id' => $row['tracker'], 'redmine_status_id' => $row['status'],
        'redmine_old_status_ids' => implode(',', $row['old']), 'redmine_project_ids' => implode(',', $projects),
        'jira_workflows' => implode(', ', $row['workflows']), 'jira_projects' => implode(', ', $row['projects']),
        'jira_transitions' => implode(', ', $row['transitions']),
        'script' => WorkflowScripts::assignToCurrentUser($row['tracker'], $row['status'], $row['old'], $row['transitions']), 'notes' => []];
}
$scriptCounts = writeReviewTable($pdo, 'migration_mapping_workflow_scripts', $scriptRows, ['redmine_tracker_id', 'redmine_status_id'],
    ['script', 'redmine_project_ids']);

// Report.
$lines = ['# Workflow proposal', '', sprintf(
    '%s; %d Jira workflow(s) over %d project/issue type pair(s).',
    date('c'),
    count(array_unique(array_column($sources, 'workflow'))),
    count($sources)
), ''];
foreach (['Transitions' => $counts, 'Field permissions' => $permissionCounts, 'Scripts' => $scriptCounts] as $title => $c) {
    $lines[] = sprintf('%s: %d proposed, %d to review, %d hand edits kept, %d removed.', $title, $c['PROPOSED'], $c['REVIEW'], $c['kept edits'], $c['removed']);
}
$lines[] = sprintf('Permission conditions: %s.', isset($context['permission_roles'])
    ? 'given to the roles that hold the permission in the project (decision 13)' : 'holders not staged (run 04), given to every role, to review');
$lines[] = sprintf('Transition screens: %s.', isset($context['field_rules'])
    ? sprintf('%d required field(s) with a Redmine field', count($proposal['field_permissions']))
    : 'field configurations not exported (run 16), to review');
$lines[] = '';
$lines[] = '| tracker | role | transitions | only assignee | to review |';
$lines[] = '|---|---|---:|---:|---:|';
$perTracker = [];
foreach ($proposal['rows'] as $row) {
    $k = $row['tracker'] . '|' . $row['role'];
    $perTracker[$k] ??= [$trackerNames[$row['tracker']] ?? ('#' . $row['tracker']), $roleNames[$row['role']] ?? ('#' . $row['role']), 0, 0, 0];
    $perTracker[$k][2]++;
    $perTracker[$k][3] += $row['assignee_only'] ? 1 : 0;
    $perTracker[$k][4] += $row['notes'] === [] ? 0 : 1;
}
foreach ($perTracker as $t) {
    $lines[] = sprintf('| %s | %s | %d | %d | %d |', ...$t);
}
$toReview = [];
foreach ($proposal['rows'] as $row) {
    foreach ($row['notes'] as $note) {
        $toReview[$note] = ($toReview[$note] ?? 0) + 1;
    }
}
arsort($toReview);
$lines[] = '';
$lines[] = '## To review (rows)';
$lines[] = '';
foreach ($toReview as $note => $n) {
    $lines[] = sprintf('- %s: %d', $note, $n);
}
// Union per tracker (Jan, decision 13): he marks the transitions he does not want anywhere IGNORED; per
// transition the Jira workflows and projects it comes from and how many issues sit in its from-status.
$issuesIn = [];
foreach (
    $pdo->query('SELECT p.project_key, i.issuetype_id, i.status_id, COUNT(*) AS n FROM staging_jira_issues i
    LEFT JOIN staging_jira_projects p ON p.id = i.project_id GROUP BY p.project_key, i.issuetype_id, i.status_id') as $row
) {
    $tracker = $trackerOf[$row['issuetype_id']] ?? null;
    $status = $statusOf[$row['status_id']] ?? null;
    if ($tracker !== null && $status !== null) {
        $issuesIn[$tracker][$status][(string)$row['project_key']] = ($issuesIn[$tracker][$status][(string)$row['project_key']] ?? 0)
            + (int)$row['n'];
    }
}
$union = [];
foreach ($proposal['rows'] as $row) {
    $k = $row['old'] . '|' . $row['new'];
    $union[$row['tracker']][$k] ??= ['old' => $row['old'], 'new' => $row['new'], 'workflows' => [], 'projects' => [], 'roles' => []];
    $union[$row['tracker']][$k]['workflows'] = array_unique(array_merge($union[$row['tracker']][$k]['workflows'], $row['workflows']));
    $union[$row['tracker']][$k]['projects'] = array_unique(array_merge($union[$row['tracker']][$k]['projects'], $row['projects']));
    $union[$row['tracker']][$k]['roles'][] = (string)($roleNames[$row['role']] ?? $row['role']);
}
$trackerWorkflowCount = [];
foreach ($sources as $source) {
    if (isset($trackerOf[$source['issue_type']])) {
        $trackerWorkflowCount[$trackerOf[$source['issue_type']]][$source['workflow']] = true;
    }
}
$lines[] = '';
$lines[] = '## Union per tracker (mark the transitions you do not want anywhere IGNORED)';
foreach ($union as $tracker => $transitions) {
    $all = count($trackerWorkflowCount[$tracker] ?? []);
    $lines[] = '';
    $lines[] = sprintf('### %s (tracker #%d, %d Jira workflow(s))', $trackerNames[$tracker] ?? $tracker, $tracker, $all);
    $lines[] = '';
    foreach ($transitions as $t) {
        $inFrom = $t['old'] === 0 ? [] : ($issuesIn[$tracker][$t['old']] ?? []);
        arsort($inFrom);
        $lines[] = sprintf(
            '- %s -> %s: from %d of %d workflow(s) (%s); projects %s; roles %s; issues in %s: %d%s',
            $statusNames[$t['old']] ?? $t['old'],
            $statusNames[$t['new']] ?? $t['new'],
            count($t['workflows']),
            $all,
            implode(', ', $t['workflows']),
            implode(', ', $t['projects']),
            implode(', ', array_unique($t['roles'])),
            $t['old'] === 0 ? '(creation)' : (string)($statusNames[$t['old']] ?? $t['old']),
            array_sum($inFrom),
            $inFrom === [] ? '' : ' (' . implode(', ', array_map(static fn (string $p, int $n): string => "$p $n", array_keys($inFrom), $inFrom)) . ')'
        );
    }
}
$lines[] = '';
$lines[] = '## Scripts ("assign to the current user")';
$lines[] = '';
foreach ($proposal['scripts'] as $row) {
    $lines[] = sprintf(
        '- %s, to %s from %s: %s (%s)',
        $trackerNames[$row['tracker']] ?? $row['tracker'],
        $statusNames[$row['status']] ?? $row['status'],
        implode(', ', array_map(static fn (int $s): string => (string)($statusNames[$s] ?? $s), $row['old'])),
        implode(', ', $row['projects']),
        implode(', ', $row['transitions'])
    );
}
$lines[] = '';
$lines[] = '## Not proposed, or approximated';
$lines[] = '';
foreach ($problems as $problem) {
    $lines[] = '- ' . $problem;
}
$lines[] = '';
$lines[] = '## Transitions per tracker';
foreach ($proposal['rows'] as $row) {
    $lines[] = sprintf(
        '- %s, %s: %s -> %s%s',
        $trackerNames[$row['tracker']] ?? $row['tracker'],
        $roleNames[$row['role']] ?? $row['role'],
        $statusNames[$row['old']] ?? $row['old'],
        $statusNames[$row['new']] ?? $row['new'],
        $row['assignee_only'] ? ' (only the assignee)' : ''
    );
}
file_put_contents($reportPath, implode("\n", $lines) . "\n");
printf(
    "Transitions: proposed %d, to review %d, hand edits kept %d, removed %d. Field permissions: %d proposed, %d to review. "
    . "Scripts: %d proposed. %d item(s) not proposed or approximated.\nReport: %s\n",
    $counts['PROPOSED'],
    $counts['REVIEW'],
    $counts['kept edits'],
    $counts['removed'],
    $permissionCounts['PROPOSED'],
    $permissionCounts['REVIEW'],
    $scriptCounts['PROPOSED'],
    count($problems),
    $reportPath
);
