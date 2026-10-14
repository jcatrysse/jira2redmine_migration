<?php

declare(strict_types=1);

/**
 * Decision 13 checked against Jira (after tools/apply_workflows.rb): for every migrated project, tracker and role
 * (04's memberships) and every status (0: a new issue), the statuses Redmine offers against what the project's
 * Jira workflows allow that role, through the tracker and status mappings.
 *   php tools/workflow_compare.php --sets=SETS.json          the project, tracker and role sets to look at
 *   SETS=SETS.json OUT=ANSWERS.json bundle exec rails runner tools/testenv/workflow_fingerprint.rb
 *   php tools/workflow_compare.php --answers=ANSWERS.json [--unexpected=FILE]   counts per category, the unexpected differences
 * Expected differences have a name: a group condition (the role is not one the group holds, or the group is not a
 * member: review row, or ignored by Jan's group settings), a permission condition, "only the assignee", a rule not
 * carried (review row), a row ignored in the review table. Exits 1 when a difference is unexpected.
 */

use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Workflows\GroupConditions;
use Jira2Redmine\Workflows\WorkflowComparison;

$config = require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../lib/workflow_staging.php';
$options = getopt('', ['sets:', 'answers:', 'unexpected:']);
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$staging = loadWorkflowStaging($pdo, $config['migration']['workflows']['group_roles'] ?? null);
$trackerOf = $staging['trackerOf'];
$statusOf = $staging['statusOf'];
$projectOf = $staging['redmineProjectOf'];

// What Jira allows each role: per project, tracker, role and from-status the targets, each with the reasons the role
// does not get it ([] = it does).
$expected = [];
$trackersOf = [];
$decided = [];
foreach ($pdo->query("SELECT redmine_project_id, redmine_tracker_id, redmine_role_id, redmine_old_status_id, redmine_new_status_id,
    migration_status FROM migration_mapping_workflow_transitions WHERE redmine_project_id <> 0 AND migration_status = 'IGNORED'") ?: [] as $row) {
    $decided[implode('|', [$row['redmine_project_id'], $row['redmine_tracker_id'], $row['redmine_role_id'], $row['redmine_old_status_id'],
        $row['redmine_new_status_id']])] = true;
}
foreach ($staging['sources'] as $source) {
    $project = $projectOf[$source['project']] ?? null;
    $tracker = $trackerOf[$source['issue_type']] ?? null;
    if ($project === null || $tracker === null) {
        continue;
    }
    $trackersOf[$project][$tracker] = true;
    foreach ($staging['projectRoles'][$source['project']] ?? [] as $role) {
        foreach ($source['transitions'] as $transition) {
            $to = $statusOf[(string)$transition['to']] ?? null;
            if ($to === null) {
                continue;
            }
            $reasons = [];
            if ($transition['groups'] !== []) {
                $resolved = GroupConditions::resolve(
                    $source['project'],
                    array_values(array_map('strval', $transition['groups'])),
                    $staging['groupRoles'],
                    $staging['groupModes'],
                    $staging['groupUsersRoles']
                );
                $held = $resolved['roles'];
                if ($held === [] && $resolved['review'] === []) {
                    $reasons[] = 'group condition: the group is not a member of the project, ignored (Jan, decision 14)';
                } elseif ($held === []) {
                    $reasons[] = 'group condition: the group is not a member of the project (review row for Jan)';
                } elseif (!in_array($role, $held, true)) {
                    $reasons[] = 'group condition: the role is not one the group holds';
                }
            }
            foreach ($transition['permissions'] as $permission) {
                if ($staging['permissionRoles'] !== null && !in_array($role, $staging['permissionRoles'][$source['project']][$permission] ?? [], true)) {
                    $reasons[] = 'permission condition: the role does not hold the permission';
                    break;
                }
            }
            if (($transition['assignee_only'] ?? false) === true) {
                $reasons[] = 'only the assignee (Redmine offers it to the assignee)';
            }
            foreach ($transition['not_mapped'] as $note) {
                $reasons[] = 'not carried: ' . $note;
            }
            foreach ($transition['from'] as $from) {
                $old = $from === '0' ? 0 : ($statusOf[$from] ?? null);
                if ($old === null || $old === $to) {
                    continue;
                }
                $why = $reasons;
                if (isset($decided[implode('|', [$project, $tracker, $role, $old, $to])])) {
                    $why[] = 'ignored in the review table';
                }
                $key = implode('|', [$project, $tracker, $role, $old]);
                // a target any of the project's transitions allows the role is allowed
                if (!isset($expected[$key][$to]) || $why === []) {
                    $expected[$key][$to] = $why;
                }
            }
        }
    }
}

if (isset($options['sets'])) {
    $sets = [];
    foreach ($trackersOf as $project => $trackers) {
        $key = array_search($project, $projectOf, true);
        foreach (array_keys($trackers) as $tracker) {
            foreach ($staging['projectRoles'][(string)$key] ?? [] as $role) {
                $sets[] = [$project, $tracker, $role];
            }
        }
    }
    file_put_contents((string)$options['sets'], json_encode($sets, JSON_THROW_ON_ERROR));
    printf("%d project, tracker and role sets written to %s\n", count($sets), $options['sets']);
    exit(0);
}

$answers = json_decode((string)file_get_contents((string)($options['answers'] ?? '')), true, 512, JSON_THROW_ON_ERROR);
// the fingerprint covers every status for each set; Jira's expectation only the statuses a transition starts from
foreach (array_keys($answers) as $key) {
    $expected[$key] ??= [];
}
// each tracker's default status, from Redmine itself (staging does not always hold it)
$defaults = [];
$trackers = json_decode((string)ClientFactory::redmine($config['redmine'])->get('/trackers.json')->getBody(), true);
foreach (is_array($trackers) ? ($trackers['trackers'] ?? []) : [] as $tracker) {
    if (isset($tracker['id'], $tracker['default_status']['id'])) {
        $defaults[(int)$tracker['id']] = (int)$tracker['default_status']['id'];
    }
}
$result = WorkflowComparison::compare(array_intersect_key($expected, $answers), $answers, $defaults);
foreach ($result['counts'] as $category => $n) {
    printf("%-100s %6d\n", $category, $n);
}
if (isset($options['unexpected'])) {
    file_put_contents((string)$options['unexpected'], implode("\n", $result['unexpected']) . "\n");
}
$unexpected = count($result['unexpected']);
printf("unexpected: %d%s\n", $unexpected, $unexpected > 0 ? '; first: ' . implode(' | ', array_slice($result['unexpected'], 0, 10)) : '');
exit($unexpected > 0 ? 1 : 0);
