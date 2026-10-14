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
$options = getopt('', ['out:']);
$out = (string)($options['out'] ?? rtrim((string)$config['paths']['tmp'], '/') . '/workflow-plan.json');
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$rows = static fn (string $table): array => $pdo->query("SELECT * FROM $table ORDER BY id")->fetchAll();

$plan = WorkflowPlan::fromRows(
    $rows('migration_mapping_workflow_transitions'),
    $rows('migration_mapping_workflow_field_permissions'),
    $rows('migration_mapping_workflow_scripts')
);
file_put_contents($out, json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
printf(
    "Plan: %d transitions over %d tracker/role pairs, %d field permissions, %d scripts; waiting for review: %d transitions, "
    . "%d field permissions, %d scripts.\nWritten to %s\n",
    count($plan['transitions']),
    count($plan['managed']),
    count($plan['field_permissions']),
    count($plan['scripts']),
    $plan['waiting']['transitions'],
    $plan['waiting']['field_permissions'],
    $plan['waiting']['scripts'],
    $out
);
