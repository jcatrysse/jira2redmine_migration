<?php

declare(strict_types=1);

/**
 * Redmine workflow proposal from the Jira workflows (after 04, 05, 07 and 16).
 *
 *   php tools/workflow_proposal.php [--report=FILE]
 *
 * Writes migration_mapping_workflow_transitions: one row per tracker, role, old and new status (and "only
 * the assignee"), PROPOSED, or REVIEW when Jira has rules Redmine cannot apply (in notes). A row edited
 * by hand (status, notes) keeps its edit on a rerun; a row no longer proposed is removed unless edited.
 * The report lists per tracker what is proposed and everything that cannot be mapped. It holds Jira
 * workflow, group and status names: keep it local. Nothing is sent to Redmine.
 */

use Jira2Redmine\Workflows\JiraTransitions;
use Jira2Redmine\Workflows\WorkflowProposal;

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
$roleNames = $column('SELECT id, name FROM staging_redmine_roles');

// Roles per project and per group, from 04's memberships (proposed or pushed).
$projectRoles = [];
$groupRoles = [];
foreach (
    $pdo->query("SELECT jira_project_key, principal_type, jira_group_name, redmine_role_id FROM migration_mapping_project_role_groups
    WHERE redmine_role_id IS NOT NULL AND migration_status IN ('READY_FOR_ASSIGNMENT', 'ASSIGNMENT_RECORDED')") as $row
) {
    $project = (string)$row['jira_project_key'];
    $projectRoles[$project][] = (int)$row['redmine_role_id'];
    if ($row['principal_type'] === 'group' && $row['jira_group_name'] !== null) {
        $groupRoles[$project][mb_strtolower((string)$row['jira_group_name'], 'UTF-8')][] = (int)$row['redmine_role_id'];
    }
}
$projectRoles = array_map(static fn (array $r): array => array_values(array_unique($r)), $projectRoles);
$groupRoles = array_map(static fn (array $g): array => array_map(static fn (array $r): array => array_values(array_unique($r)), $g), $groupRoles);

// Workflows of the migrated projects: issue type => workflow through the project's workflow scheme.
$workflows = [];
foreach ($pdo->query("SELECT workflow_name, raw_payload FROM staging_jira_workflows WHERE source = 'search'") as $row) {
    $workflows[(string)$row['workflow_name']] = JiraTransitions::fromWorkflow(json_decode((string)$row['raw_payload'], true) ?: []);
}
$sources = [];
$problems = [];
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

$proposal = WorkflowProposal::build($sources, $trackerOf, $statusOf, $projectRoles, $groupRoles);
$problems = array_values(array_unique(array_merge($problems, $proposal['problems'])));

// Write the proposal; hand edits stay.
$hash = static fn (string $status, ?string $notes): string => hash('sha256', $status . '|' . ($notes ?? 'null'));
$existing = [];
foreach ($pdo->query('SELECT * FROM migration_mapping_workflow_transitions') as $row) {
    $key = implode('|', [$row['redmine_tracker_id'], $row['redmine_role_id'], $row['redmine_old_status_id'], $row['redmine_new_status_id'],
        (int)$row['only_assignee']]);
    $existing[$key] = $row + ['edited' => $row['automation_hash'] !== $hash((string)$row['migration_status'], $row['notes'])];
}
$upsert = $pdo->prepare('INSERT INTO migration_mapping_workflow_transitions (redmine_tracker_id, redmine_role_id, redmine_old_status_id,
        redmine_new_status_id, only_assignee, jira_workflows, jira_projects, jira_transitions, migration_status, notes, automation_hash)
    VALUES (:tracker, :role, :old, :new, :assignee, :workflows, :projects, :transitions, :status, :notes, :hash)
    ON DUPLICATE KEY UPDATE jira_workflows = VALUES(jira_workflows), jira_projects = VALUES(jira_projects),
        jira_transitions = VALUES(jira_transitions), migration_status = VALUES(migration_status), notes = VALUES(notes),
        automation_hash = VALUES(automation_hash)');
$keepEdit = $pdo->prepare('UPDATE migration_mapping_workflow_transitions SET jira_workflows = :workflows, jira_projects = :projects,
    jira_transitions = :transitions WHERE id = :id');
$counts = ['PROPOSED' => 0, 'REVIEW' => 0, 'kept edits' => 0, 'removed' => 0];
foreach ($proposal['rows'] as $key => $row) {
    $status = $row['notes'] === [] ? 'PROPOSED' : 'REVIEW';
    $notes = $row['notes'] === [] ? null : implode('; ', $row['notes']);
    $values = ['workflows' => implode(', ', $row['workflows']), 'projects' => implode(', ', $row['projects']),
        'transitions' => implode(', ', $row['transitions'])];
    if (isset($existing[$key]) && $existing[$key]['edited']) {
        $keepEdit->execute($values + ['id' => $existing[$key]['id']]);
        $counts['kept edits']++;
    } else {
        $upsert->execute($values + ['tracker' => $row['tracker'], 'role' => $row['role'], 'old' => $row['old'], 'new' => $row['new'],
            'assignee' => $row['assignee_only'] ? 1 : 0, 'status' => $status, 'notes' => $notes, 'hash' => $hash($status, $notes)]);
        $counts[$status]++;
    }
    unset($existing[$key]);
}
$delete = $pdo->prepare('DELETE FROM migration_mapping_workflow_transitions WHERE id = :id');
foreach ($existing as $row) {
    if (!$row['edited']) {
        $delete->execute(['id' => $row['id']]);
        $counts['removed']++;
    }
}

// Report.
$lines = ['# Workflow proposal', '', sprintf(
    '%s; %d Jira workflow(s) over %d project/issue type pair(s).',
    date('c'),
    count(array_unique(array_column($sources, 'workflow'))),
    count($sources)
), ''];
$lines[] = sprintf(
    'Rows: %d proposed, %d to review (Jira rules Redmine cannot apply), %d hand edits kept, %d removed.',
    $counts['PROPOSED'],
    $counts['REVIEW'],
    $counts['kept edits'],
    $counts['removed']
);
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
$notMapped = [];
foreach ($proposal['rows'] as $row) {
    foreach ($row['notes'] as $note) {
        $notMapped[$note] = ($notMapped[$note] ?? 0) + 1;
    }
}
arsort($notMapped);
$lines[] = '';
$lines[] = '## Jira rules Redmine cannot apply (rows)';
$lines[] = '';
foreach ($notMapped as $note => $n) {
    $lines[] = sprintf('- %s: %d', $note, $n);
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
    "Proposed %d, to review %d, hand edits kept %d, removed %d; %d item(s) not proposed or approximated.\nReport: %s\n",
    $counts['PROPOSED'],
    $counts['REVIEW'],
    $counts['kept edits'],
    $counts['removed'],
    count($problems),
    $reportPath
);
