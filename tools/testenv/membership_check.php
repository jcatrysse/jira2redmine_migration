<?php

declare(strict_types=1);

/**
 * Proof of Jan's decision 12 (c), after `restore`: the memberships of the migrated projects in Redmine equal
 * 04's proposal (migration_mapping_project_role_groups, rows pushed or recorded), direct roles only.
 *   php tools/testenv/membership_check.php
 * Prints the differences and "memberships equal 04's proposal: yes|no".
 */

use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Roles\MembershipPlan;

$config = require __DIR__ . '/../../bootstrap.php';
$pdo = new PDO((string)$config['database']['dsn'], (string)$config['database']['username'], (string)$config['database']['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$client = ClientFactory::redmine($config['redmine']);

$expected = [];
foreach (
    $pdo->query("SELECT redmine_project_id, redmine_group_id, redmine_role_id FROM migration_mapping_project_role_groups
    WHERE migration_status = 'ASSIGNMENT_RECORDED' AND redmine_project_id IS NOT NULL AND redmine_group_id IS NOT NULL") as $row
) {
    $expected[sprintf('%d|%d|%d', $row['redmine_project_id'], $row['redmine_group_id'], $row['redmine_role_id'])] = true;
}
$projects = $pdo->query("SELECT DISTINCT redmine_project_id FROM migration_mapping_projects WHERE redmine_project_id IS NOT NULL
    AND migration_status IN ('MATCH_FOUND', 'CREATION_SUCCESS')")->fetchAll(PDO::FETCH_COLUMN);

$actual = [];
foreach ($projects as $projectId) {
    $offset = 0;
    do {
        $response = $client->get(sprintf('/projects/%d/memberships.json', $projectId), ['query' => ['offset' => $offset, 'limit' => 100]]);
        $page = json_decode((string)$response->getBody(), true);
        foreach (MembershipPlan::existingFromApi(is_array($page) ? $page : []) as $principalId => $membership) {
            foreach ($membership['role_ids'] as $roleId) {
                $actual[sprintf('%d|%d|%d', $projectId, $principalId, $roleId)] = true;
            }
        }
        $offset += 100;
    } while ($offset < (int)($page['total_count'] ?? 0));
}

$missing = array_keys(array_diff_key($expected, $actual));
$extra = array_keys(array_diff_key($actual, $expected));
printf(
    "projects %d, expected %d, in Redmine %d, missing %d, not proposed %d\n",
    count($projects),
    count($expected),
    count($actual),
    count($missing),
    count($extra)
);
foreach (array_slice($missing, 0, 10) as $key) {
    echo "  missing (project|principal|role): $key\n";
}
foreach (array_slice($extra, 0, 10) as $key) {
    echo "  not proposed (project|principal|role): $key\n";
}
printf("memberships equal 04's proposal: %s\n", $missing === [] && $extra === [] ? 'yes' : 'no');
