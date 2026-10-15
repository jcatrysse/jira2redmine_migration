<?php

declare(strict_types=1);

/**
 * The workflow plan for tools/apply_workflows.rb (Jan, decision 13): the PROPOSED rows of the review tables
 * of tools/workflow_proposal.php, as JSON with Redmine ids only.
 *   php tools/workflow_plan.php --out=FILE
 * Then, in the Redmine root: bundle exec rails runner apply_workflows.rb FILE (preview), and with --confirm-push.
 */

use Jira2Redmine\Workflows\WorkflowPlan;

$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/workflow_staging.php';
$options = getopt('', ['out:']);
$out = (string)($options['out'] ?? rtrim((string)$config['paths']['tmp'], '/') . '/workflow-plan.json');
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$rows = static fn (string $table): array => $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll();

// every project, tracker and role of the projects with their own workflow: one without approved transitions is an
// own, empty workflow, not the global one
$staging = loadWorkflowStaging($pdo);
$projectSets = [];
foreach ($staging['sources'] as $source) {
    $project = $staging['redmineProjectOf'][$source['project']] ?? null;
    $tracker = $staging['trackerOf'][$source['issue_type']] ?? null;
    if ($project !== null && $tracker !== null) {
        foreach ($staging['projectRoles'][$source['project']] ?? [] as $role) {
            $projectSets[] = [$project, $tracker, $role];
        }
    }
}
// Jan, decision 34: only the trackers 07 created get the union as their global workflow; 07 marks them, since a matched
// tracker also ends CREATION_SUCCESS once its push added it to the projects
$newTrackers = array_map('intval', $pdo->query("SELECT DISTINCT redmine_tracker_id FROM migration_mapping_trackers
    WHERE created_by_migration = 1 AND redmine_tracker_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
$plan = WorkflowPlan::fromRows(
    $rows('migration_mapping_workflow_transitions'),
    $rows('migration_mapping_workflow_field_permissions'),
    $rows('migration_mapping_workflow_scripts'),
    (string)($config['migration']['workflows']['mode'] ?? 'project'),
    $projectSets,
    $newTrackers
);
file_put_contents($out, json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
printf(
    "Plan (%s): %d transitions over %d project/tracker/role sets, %d field permissions, %d scripts; waiting for review: %d transitions, "
    . "%d field permissions, %d scripts.\nWritten to %s\n",
    $plan['mode'],
    count($plan['transitions']),
    count($plan['managed']),
    count($plan['field_permissions']),
    count($plan['scripts']),
    $plan['waiting']['transitions'],
    $plan['waiting']['field_permissions'],
    $plan['waiting']['scripts'],
    $out
);
if ($plan['kept_global'] !== []) {
    printf(
        "Global workflow left as it is (decision 34) for %d tracker(s) Redmine already had: %s\n",
        count($plan['kept_global']),
        implode(', ', $plan['kept_global'])
    );
}
