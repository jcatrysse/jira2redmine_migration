<?php
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;
use Jira2Redmine\Http\ClientFactory;
use Jira2Redmine\Database\ConnectionFactory;
use Jira2Redmine\Roles\AssigneeMemberships;
use Jira2Redmine\Roles\AssignmentHash;
use Jira2Redmine\Roles\AssignmentStatus;
use Jira2Redmine\Roles\MembershipPlan;
use Jira2Redmine\Roles\PermissionGrants;
use Jira2Redmine\Roles\ProjectRolePrincipals;
use Jira2Redmine\Roles\RoleMapping;
use Jira2Redmine\Roles\WatcherMemberships;
use Jira2Redmine\Roles\WorkflowGroupRoles;

const MIGRATE_ROLES_SCRIPT_VERSION = '0.0.1';
const AVAILABLE_PHASES = [
    'jira' => 'Extract Jira project roles and their actors into the staging tables.',
    'redmine' => 'Refresh the Redmine roles snapshot from the REST API.',
    'transform' => 'Reconcile Jira roles with Redmine roles and derive project/group assignments.',
    'push' => 'Generate the manual assignment plan or use the extended API to record Redmine assignments.',
];

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('This script is intended to be run from the command line.');
}

$cliArguments = $_SERVER['argv'] ?? ($GLOBALS['argv'] ?? null);
if (!is_array($cliArguments)) {
    throw new RuntimeException('Unable to access the command-line arguments.');
}

try {
    [$cliOptions, $positionalArguments] = parseCommandLineOptions($cliArguments);

    if (!empty($cliOptions['help'])) {
        printUsage();
        exit(0);
    }

    if (!empty($cliOptions['version'])) {
        printVersion();
        exit(0);
    }

    if ($positionalArguments !== []) {
        throw new RuntimeException(sprintf(
            'Unexpected positional arguments: %s',
            implode(', ', $positionalArguments)
        ));
    }

    /** @var array<string, mixed> $config */
    $config = require __DIR__ . '/bootstrap.php';

    main($config, $cliOptions);
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf('[ERROR] %s%s', $exception->getMessage(), PHP_EOL));
    exit(1);
}

/**
 * @param array<string, mixed> $config
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool} $cliOptions
 * @throws Throwable
 */
function main(array $config, array $cliOptions): void
{
    $phasesToRun = determinePhasesToRun($cliOptions);

    printf(
        "[%s] Selected phases: %s%s",
        formatCurrentTimestamp(),
        implode(', ', $phasesToRun),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    /** @var array<string, mixed>|null $redmineConfig */
    $redmineConfig = null;

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira project role extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $jiraSummary = fetchAndStoreJiraProjectRoles($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. Roles processed: %d, Projects processed: %d, Actor rows captured: %d.%s",
            formatCurrentTimestamp(),
            $jiraSummary['roles_processed'],
            $jiraSummary['projects_processed'],
            $jiraSummary['actor_rows'],
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira role extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine role snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineRoles = fetchAndStoreRedmineRoles($redmineClient, $pdo);
        $builtinGroups = fetchAndStoreRedmineBuiltinGroups($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d role records processed, %d built-in group(s).%s",
            formatCurrentTimestamp(),
            $totalRedmineRoles,
            $builtinGroups,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine role snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf("[%s] Starting role reconciliation & transform phase...%s", formatCurrentTimestamp(), PHP_EOL);

        $transformSummary = runRoleTransformationPhase($pdo, $config);

        $roleSummary = $transformSummary['roles'];
        printf(
            "[%s] Role mapping summary -> Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $roleSummary['matched'],
            $roleSummary['ready_for_creation'],
            $roleSummary['manual_review'],
            $roleSummary['manual_overrides'],
            $roleSummary['skipped'],
            $roleSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['role_status_counts'] !== []) {
            printf("  Current role mapping status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['role_status_counts'] as $status => $count) {
                printf("  - %-32s %d%s", $status, $count, PHP_EOL);
            }
        }

        $assignmentSummary = $transformSummary['assignments'];
        printf(
            "[%s] Project role assignment summary -> Ready: %d, Recorded: %d, Awaiting project: %d, Awaiting group: %d, Awaiting role: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $assignmentSummary['ready_for_assignment'],
            $assignmentSummary['already_recorded'],
            $assignmentSummary['awaiting_project'],
            $assignmentSummary['awaiting_group'],
            $assignmentSummary['awaiting_role'],
            $assignmentSummary['manual_review'],
            $assignmentSummary['manual_overrides'],
            $assignmentSummary['skipped'],
            $assignmentSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['assignment_status_counts'] !== []) {
            printf("  Current project role assignment status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['assignment_status_counts'] as $status => $count) {
                printf("  - %-32s %d%s", $status, $count, PHP_EOL);
            }
        }
    } else {
        printf(
            "[%s] Skipping transform phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('push', $phasesToRun, true)) {
        $confirmPush = (bool)($cliOptions['confirm_push'] ?? false);
        $isDryRun = (bool)($cliOptions['dry_run'] ?? false);
        $redmineConfig ??= extractArrayConfig($config, 'redmine');
        $useExtendedApi = shouldUseExtendedApi($redmineConfig, (bool)($cliOptions['use_extended_api'] ?? false));

        $workflowsConfig = $config['migration']['workflows'] ?? [];
        runRolePushPhase($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi, is_array($workflowsConfig) ? $workflowsConfig : []);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}

// Function implementations are appended below.

/**
 * @param Client $client
 * @param PDO $pdo
 * @return array{roles_processed: int, projects_processed: int, actor_rows: int}
 * @throws Throwable
 */
function fetchAndStoreJiraProjectRoles(Client $client, PDO $pdo): array
{
    $rolesProcessed = refreshJiraRoleDefinitions($client, $pdo);
    $assignmentSummary = refreshJiraProjectRoleActors($client, $pdo);
    $grants = refreshJiraProjectPermissionGrants($client, $pdo);
    printf("  Captured %d group grant(s) from the projects' permission schemes.%s", $grants, PHP_EOL);

    return [
        'roles_processed' => $rolesProcessed,
        'projects_processed' => $assignmentSummary['projects_processed'],
        'actor_rows' => $assignmentSummary['actor_rows'],
    ];
}

/**
 * Group grants of Browse projects, Assignable user and Administer projects in each project's permission
 * scheme: where Jira gives project access (B31).
 */
function refreshJiraProjectPermissionGrants(Client $client, PDO $pdo): int
{
    $pdo->exec('TRUNCATE TABLE staging_jira_project_permission_grants');
    $pdo->exec('TRUNCATE TABLE staging_jira_project_permission_holders');
    $holderInsert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_permission_holders (project_id, project_key, permission, holder_type, holder_id, holder_name)
        VALUES (:project_id, :project_key, :permission, :holder_type, :holder_id, :holder_name)
    SQL);
    $insert = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_permission_grants (project_id, project_key, project_name, permission, group_id, group_name)
        VALUES (:project_id, :project_key, :project_name, :permission, :group_id, :group_name)
    SQL);
    if ($insert === false || $holderInsert === false) {
        throw new RuntimeException('Failed to prepare insert statements for the Jira permission grants.');
    }

    $count = 0;
    foreach (fetchJiraProjectsForRoleExtraction($client) as $project) {
        $response = $client->get(
            sprintf('/rest/api/3/project/%s/permissionscheme', rawurlencode((string)$project['id'])),
            ['query' => ['expand' => 'permissions,group,projectRole']]
        );
        $scheme = decodeJsonResponse($response);
        $result = PermissionGrants::fromScheme(is_array($scheme) ? $scheme : []);
        foreach (PermissionGrants::holders(is_array($scheme) ? $scheme : []) as $holder) {
            $holderInsert->execute($holder + ['project_id' => (string)$project['id'], 'project_key' => $project['key']]);
        }
        foreach ($result['grants'] as $grant) {
            $insert->execute([
                'project_id' => (string)$project['id'],
                'project_key' => $project['key'],
                'project_name' => $project['name'],
                'permission' => $grant['permission'],
                'group_id' => $grant['group_id'],
                'group_name' => $grant['group_name'],
            ]);
            $count++;
        }
        foreach ($result['other_holders'] as $holder => $times) {
            printf(
                "  [note] Project %s: %s granted %d time(s) to a holder that is not a group or project role; not migrated.%s",
                $project['key'] ?? $project['id'],
                $holder,
                $times,
                PHP_EOL
            );
        }
    }

    return $count;
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @return int
 * @throws GuzzleException|JsonException
 */
function refreshJiraRoleDefinitions(Client $client, PDO $pdo): int
{
    $response = $client->get('/rest/api/3/role');
    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded)) {
        throw new RuntimeException('Unexpected response payload while fetching Jira roles.');
    }

    $roles = [];
    $timestamp = formatCurrentUtcTimestamp('Y-m-d H:i:s');

    foreach ($decoded as $role) {
        if (!is_array($role)) {
            continue;
        }

        $roleId = isset($role['id']) ? (int)$role['id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        $name = normalizeString($role['name'] ?? null, 255);
        if ($name === null) {
            throw new RuntimeException(sprintf('Jira role %d is missing a name attribute.', $roleId));
        }

        $description = isset($role['description']) && is_string($role['description'])
            ? trim($role['description'])
            : null;

        try {
            $rawPayload = json_encode($role, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Failed to encode Jira role payload for role %d: %s', $roleId, $exception->getMessage()), 0, $exception);
        }

        $roles[] = [
            'id' => $roleId,
            'name' => $name,
            'description' => $description,
            'raw_payload' => $rawPayload,
            'extracted_at' => $timestamp,
        ];
    }

    if ($roles === []) {
        return 0;
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_roles (id, name, description, raw_payload, extracted_at)
        VALUES (:id, :name, :description, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_project_roles.');
    }

    $pdo->beginTransaction();

    try {
        foreach ($roles as $row) {
            $insertStatement->execute($row);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return count($roles);
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @return array{projects_processed: int, actor_rows: int}
 * @throws Throwable
 */
function refreshJiraProjectRoleActors(Client $client, PDO $pdo): array
{
    $pdo->exec('TRUNCATE TABLE staging_jira_project_role_actors');

    $projects = fetchJiraProjectsForRoleExtraction($client);
    if ($projects === []) {
        return ['projects_processed' => 0, 'actor_rows' => 0];
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_project_role_actors (
            project_id,
            project_key,
            project_name,
            role_id,
            role_name,
            actor_id,
            actor_display,
            actor_type,
            raw_payload,
            extracted_at
        ) VALUES (
            :project_id,
            :project_key,
            :project_name,
            :role_id,
            :role_name,
            :actor_id,
            :actor_display,
            :actor_type,
            :raw_payload,
            :extracted_at
        )
        ON DUPLICATE KEY UPDATE
            project_key = VALUES(project_key),
            project_name = VALUES(project_name),
            role_name = VALUES(role_name),
            actor_display = VALUES(actor_display),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_project_role_actors.');
    }

    $projectsProcessed = 0;
    $actorRows = 0;

    foreach ($projects as $project) {
        $projectId = $project['id'];
        $projectKey = $project['key'];
        $projectName = $project['name'];

        try {
            $response = $client->get(sprintf('/rest/api/3/project/%s/role', rawurlencode((string)$projectId)));
        } catch (BadResponseException $exception) {
            printf(
                "  [warning] Failed to fetch Jira roles for project %s (%s): %s%s",
                $projectKey ?? $projectId,
                $projectId,
                $exception->getMessage(),
                PHP_EOL
            );
            continue;
        }

        $decoded = decodeJsonResponse($response);
        if (!is_array($decoded)) {
            printf(
                "  [warning] Unexpected Jira role listing response for project %s (%s); skipping.%s",
                $projectKey ?? $projectId,
                $projectId,
                PHP_EOL
            );
            continue;
        }

        $roleEndpoints = [];
        foreach ($decoded as $roleName => $roleUrl) {
            if (!is_string($roleUrl)) {
                continue;
            }

            $roleEndpoints[] = [
                'name' => is_string($roleName) ? $roleName : null,
                'url' => $roleUrl,
            ];
        }

        if ($roleEndpoints === []) {
            $projectsProcessed++;
            continue;
        }

        foreach ($roleEndpoints as $roleEndpoint) {
            try {
                $roleData = fetchJiraRoleAssignment($client, $roleEndpoint['url']);
            } catch (RuntimeException $exception) {
                $roleNameDisplay = $roleEndpoint['name'] ?? null;
                if (!is_string($roleNameDisplay) || $roleNameDisplay === '') {
                    $roleNameDisplay = '(unnamed role)';
                }

                printf(
                    "  [warning] Failed to retrieve Jira role \"%s\" for project %s (%s): %s%s",
                    $roleNameDisplay,
                    $projectKey ?? $projectId,
                    $projectId,
                    $exception->getMessage(),
                    PHP_EOL
                );

                continue;
            }

            $roleId = isset($roleData['id']) && is_numeric($roleData['id']) ? (int)$roleData['id'] : 0;
            if ($roleId <= 0) {
                continue;
            }

            $roleName = normalizeString($roleData['name'] ?? null, 255);
            $actors = isset($roleData['actors']) && is_array($roleData['actors']) ? $roleData['actors'] : [];

            foreach ($actors as $actor) {
                if (!is_array($actor)) {
                    continue;
                }

                $actorType = $actor['type'] ?? null;
                if (!is_string($actorType)) {
                    continue;
                }

                $normalizedType = strtolower(trim($actorType));
                if ($normalizedType !== 'atlassian-group-role-actor' && $normalizedType !== 'atlassian-user-role-actor') {
                    continue;
                }

                $actorId = resolveJiraRoleActorId($actor, $normalizedType);
                if ($actorId === null) {
                    continue;
                }

                $actorDisplay = normalizeString($actor['displayName'] ?? null, 255);

                try {
                    $rawPayload = json_encode($actor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException(sprintf(
                        'Failed to encode Jira role actor payload for project %s role %d: %s',
                        $projectId,
                        $roleId,
                        $exception->getMessage()
                    ), 0, $exception);
                }

                $insertStatement->execute([
                    'project_id' => (string)$projectId,
                    'project_key' => $projectKey,
                    'project_name' => $projectName,
                    'role_id' => $roleId,
                    'role_name' => $roleName,
                    'actor_id' => $actorId,
                    'actor_display' => $actorDisplay,
                    'actor_type' => $normalizedType,
                    'raw_payload' => $rawPayload,
                    'extracted_at' => formatCurrentUtcTimestamp('Y-m-d H:i:s'),
                ]);

                $actorRows++;
            }
        }

        $projectsProcessed++;
    }

    return [
        'projects_processed' => $projectsProcessed,
        'actor_rows' => $actorRows,
    ];
}

/**
 * @param Client $client
 * @return array<int, array{id: string, key: ?string, name: ?string}>
 * @throws GuzzleException
 */
function fetchJiraProjectsForRoleExtraction(Client $client): array
{
    $projects = [];
    $startAt = 0;
    $maxResults = 50;

    while (true) {
        $response = $client->get('/rest/api/3/project/search', [
            'query' => [
                'startAt' => $startAt,
                'maxResults' => $maxResults,
            ],
        ]);

        $decoded = decodeJsonResponse($response);
        if (!is_array($decoded)) {
            break;
        }

        $values = isset($decoded['values']) && is_array($decoded['values']) ? $decoded['values'] : [];
        if ($values === []) {
            break;
        }

        foreach ($values as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = $project['id'] ?? null;
            if ($projectId === null) {
                continue;
            }

            $projects[] = [
                'id' => (string)$projectId,
                'key' => normalizeString($project['key'] ?? null, 255),
                'name' => normalizeString($project['name'] ?? null, 255),
            ];
        }

        $returned = count($values);
        $startAt += $returned;

        $total = isset($decoded['total']) && is_numeric($decoded['total']) ? (int)$decoded['total'] : null;
        if ($total !== null && $startAt >= $total) {
            break;
        }

        if ($returned < $maxResults) {
            break;
        }
    }

    return $projects;
}

/**
 * @param Client $client
 * @param string $roleUrl
 * @return array<string, mixed>
 */
function fetchJiraRoleAssignment(Client $client, string $roleUrl): array
{
    $relativeUrl = normalizeJiraRoleEndpointUrl($roleUrl);

    try {
        $response = $client->get($relativeUrl);
    } catch (GuzzleException $exception) {
        throw new RuntimeException(
            sprintf('Failed to fetch Jira role endpoint %s: %s', $relativeUrl, $exception->getMessage()),
            0,
            $exception
        );
    }

    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded)) {
        throw new RuntimeException(sprintf('Unexpected response when fetching Jira role endpoint %s.', $relativeUrl));
    }

    return $decoded;
}

/**
 * @param string $roleUrl
 * @return string
 */
function normalizeJiraRoleEndpointUrl(string $roleUrl): string
{
    $trimmed = trim($roleUrl);
    if ($trimmed === '') {
        throw new RuntimeException('Encountered an empty Jira role endpoint URL.');
    }

    if (!str_starts_with($trimmed, 'http://') && !str_starts_with($trimmed, 'https://')) {
        return $trimmed;
    }

    $parsed = parse_url($trimmed);
    if (!is_array($parsed) || !isset($parsed['path']) || $parsed['path'] === '') {
        throw new RuntimeException(sprintf('Unable to parse Jira role endpoint URL: %s', $roleUrl));
    }

    $relativeUrl = $parsed['path'];
    if (isset($parsed['query']) && $parsed['query'] !== '') {
        $relativeUrl .= '?' . $parsed['query'];
    }

    return $relativeUrl;
}

/**
 * @param array<string, mixed> $actor
 * @param string $normalizedType
 * @return string|null
 */
function resolveJiraRoleActorId(array $actor, string $normalizedType): ?string
{
    if ($normalizedType === 'atlassian-group-role-actor') {
        if (isset($actor['actorGroup']) && is_array($actor['actorGroup'])) {
            $groupId = $actor['actorGroup']['groupId'] ?? null;
            if ($groupId !== null && $groupId !== '') {
                return (string)$groupId;
            }
        }
    } elseif ($normalizedType === 'atlassian-user-role-actor') {
        if (isset($actor['actorUser']) && is_array($actor['actorUser'])) {
            $accountId = $actor['actorUser']['accountId'] ?? null;
            if ($accountId !== null && $accountId !== '') {
                return (string)$accountId;
            }
        }
    }

    if (isset($actor['id']) && $actor['id'] !== '') {
        return (string)$actor['id'];
    }

    return null;
}

/**
 * Redmine's built-in groups (anonymous, non_member): a grant to a Jira application role is proposed
 * for "Non member users" (B31, decided overnight).
 */
function fetchAndStoreRedmineBuiltinGroups(Client $client, PDO $pdo): int
{
    $pdo->exec('TRUNCATE TABLE staging_redmine_builtin_groups');
    $decoded = decodeJsonResponse($client->get('/groups.json', ['query' => ['builtin' => 1, 'limit' => 100]]));
    $insert = $pdo->prepare('INSERT INTO staging_redmine_builtin_groups (id, builtin, name) VALUES (:id, :builtin, :name)');
    if ($insert === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_builtin_groups.');
    }

    $count = 0;
    foreach (is_array($decoded) && is_array($decoded['groups'] ?? null) ? $decoded['groups'] : [] as $group) {
        if (!is_array($group) || !isset($group['id'], $group['builtin']) || (string)$group['builtin'] === '') {
            continue;
        }
        $insert->execute(['id' => (int)$group['id'], 'builtin' => (string)$group['builtin'], 'name' => normalizeString($group['name'] ?? null, 255)]);
        $count++;
    }

    return $count;
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @return int
 * @throws GuzzleException|JsonException
 */
function fetchAndStoreRedmineRoles(Client $client, PDO $pdo): int
{
    $pdo->exec('TRUNCATE TABLE staging_redmine_roles');

    $response = $client->get('/roles.json');
    $decoded = decodeJsonResponse($response);

    if (!is_array($decoded) || !isset($decoded['roles']) || !is_array($decoded['roles'])) {
        throw new RuntimeException('Unexpected response from Redmine when fetching roles.');
    }

    $roles = [];
    $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

    foreach ($decoded['roles'] as $role) {
        if (!is_array($role)) {
            continue;
        }

        $roleId = isset($role['id']) ? (int)$role['id'] : 0;
        if ($roleId <= 0) {
            continue;
        }

        $name = normalizeString($role['name'] ?? null, 255);
        if ($name === null) {
            throw new RuntimeException(sprintf('Redmine role %d is missing a name attribute.', $roleId));
        }

        $assignable = isset($role['assignable']) ? (bool)$role['assignable'] : null;

        try {
            $rawPayload = json_encode($role, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Failed to encode Redmine role payload for role %d: %s', $roleId, $exception->getMessage()), 0, $exception);
        }

        $roles[] = [
            'id' => $roleId,
            'name' => $name,
            'assignable' => $assignable,
            'raw_payload' => $rawPayload,
            'retrieved_at' => $retrievedAt,
        ];
    }

    if ($roles === []) {
        return 0;
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_roles (id, name, assignable, raw_payload, retrieved_at)
        VALUES (:id, :name, :assignable, :raw_payload, :retrieved_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            assignable = VALUES(assignable),
            raw_payload = VALUES(raw_payload),
            retrieved_at = VALUES(retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_roles.');
    }

    $pdo->beginTransaction();

    try {
        foreach ($roles as $row) {
            $insertStatement->execute([
                'id' => $row['id'],
                'name' => $row['name'],
                'assignable' => $row['assignable'],
                'raw_payload' => $row['raw_payload'],
                'retrieved_at' => $row['retrieved_at'],
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    return count($roles);
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @return array{
 *     roles: array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int},
 *     role_status_counts: array<string, int>,
 *     assignments: array{ready_for_assignment: int, already_recorded: int, awaiting_project: int, awaiting_group: int, awaiting_role: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int},
 *     assignment_status_counts: array<string, int>
 * }
 */
function runRoleTransformationPhase(PDO $pdo, array $config): array
{
    synchronizeMigrationMappingRoles($pdo);
    $workflowsConfig = $config['migration']['workflows'] ?? [];
    synchronizeMigrationMappingProjectRoleGroups($pdo, $config['migration']['roles'] ?? [], is_array($workflowsConfig) ? $workflowsConfig : []);

    $roleMapping = new RoleMapping(WatcherMemberships::mappings($config['migration']['roles'] ?? []));
    $redmineRoleLookup = fetchRedmineRoleLookup($pdo);
    $roleTransformSummary = runRoleDefinitionTransformation($pdo, $redmineRoleLookup, $roleMapping);

    $existingAssignments = fetchRedmineGroupProjectRoleAssignments($pdo);
    $assignmentTransformSummary = runProjectRoleAssignmentTransformation($pdo, $config, $redmineRoleLookup, $existingAssignments, $roleMapping);

    return [
        'roles' => $roleTransformSummary['summary'],
        'role_status_counts' => $roleTransformSummary['status_counts'],
        'assignments' => $assignmentTransformSummary['summary'],
        'assignment_status_counts' => $assignmentTransformSummary['status_counts'],
    ];
}

/**
 * @param PDO $pdo
 * @return void
 */
function synchronizeMigrationMappingRoles(PDO $pdo): void
{
    $sql = <<<SQL
INSERT INTO migration_mapping_roles (jira_role_id, jira_role_name, jira_role_description)
SELECT
    sjpr.id,
    sjpr.name,
    sjpr.description
FROM staging_jira_project_roles AS sjpr
ON DUPLICATE KEY UPDATE
    jira_role_name = VALUES(jira_role_name),
    jira_role_description = VALUES(jira_role_description)
SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronize migration_mapping_roles: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @param array<string, mixed> $rolesConfig migration.roles
 */
function synchronizeMigrationMappingProjectRoleGroups(PDO $pdo, array $rolesConfig, array $workflowsConfig = []): void
{
    try {
        $actorRows = $pdo->query('SELECT project_id, project_key, project_name, role_id, role_name, actor_id, actor_display, actor_type FROM staging_jira_project_role_actors ORDER BY project_id, role_id, actor_type, actor_id');
        $groupRows = $pdo->query('SELECT group_id, name FROM staging_jira_groups');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to read Jira project role actors: ' . $exception->getMessage(), 0, $exception);
    }
    if ($actorRows === false || $groupRows === false) {
        throw new RuntimeException('Failed to read Jira project role actors.');
    }

    /** @var list<array<string, mixed>> $actors */
    $actors = $actorRows->fetchAll(PDO::FETCH_ASSOC);
    /** @var array<string, string> $groupNames */
    $groupNames = $groupRows->fetchAll(PDO::FETCH_KEY_PAIR);

    // The permission scheme's group grants, as group actors of a pseudo role (B31).
    $grantRows = $pdo->query('SELECT project_id, project_key, project_name, permission, group_id, group_name FROM staging_jira_project_permission_grants');
    foreach ($grantRows !== false ? $grantRows->fetchAll(PDO::FETCH_ASSOC) : [] as $grant) {
        [$pseudoRoleId, $pseudoRoleName] = PermissionGrants::PSEUDO_ROLES[(string)$grant['permission']] ?? [null, null];
        if ($pseudoRoleId === null) {
            continue;
        }
        $actors[] = [
            'project_id' => $grant['project_id'],
            'project_key' => $grant['project_key'],
            'project_name' => $grant['project_name'],
            'role_id' => (string)$pseudoRoleId,
            'role_name' => $pseudoRoleName,
            'actor_id' => $grant['group_id'],
            'actor_display' => $grant['group_name'],
            'actor_type' => 'atlassian-group-role-actor',
        ];
    }

    // Assignees and watchers no membership covers (B46, B50): only with the options on; by default the members
    // step gives every migrated user a temporary role during the import instead (Jan, decision 12).
    $assigneeOnly = ($rolesConfig['assignee_only_members'] ?? false) === true;
    $watcherOnly = ($rolesConfig['watcher_only_members'] ?? false) === true;
    if (!$assigneeOnly && !$watcherOnly) {
        printf("  [note] No assignee-only or watcher-only members: the temporary migration role covers them (decision 12).%s", PHP_EOL);
    }
    $members = [];
    $memberRows = $pdo->query('SELECT group_id, account_id FROM staging_jira_group_members');
    foreach ($memberRows !== false ? $memberRows->fetchAll(PDO::FETCH_ASSOC) : [] as $member) {
        $members[(string)$member['group_id']][] = (string)$member['account_id'];
    }
    $assigneeRows = !$assigneeOnly ? false : $pdo->query(<<<SQL
        SELECT DISTINCT i.project_id, p.project_key, p.name AS project_name, i.assignee_account_id AS account_id,
               JSON_UNQUOTE(JSON_EXTRACT(i.raw_payload, '$.fields.assignee.displayName')) AS display_name
        FROM staging_jira_issues i
        LEFT JOIN staging_jira_projects p ON p.id = i.project_id
        WHERE i.assignee_account_id IS NOT NULL AND i.assignee_account_id <> ''
        ORDER BY i.project_id, i.assignee_account_id
    SQL);
    $assignees = [];
    foreach ($assigneeRows !== false ? $assigneeRows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $assignees[] = [
            'project_id' => (string)$row['project_id'],
            'project_key' => $row['project_key'] !== null ? (string)$row['project_key'] : null,
            'project_name' => $row['project_name'] !== null ? (string)$row['project_name'] : null,
            'account_id' => (string)$row['account_id'],
            'display_name' => $row['display_name'] !== null ? (string)$row['display_name'] : null,
        ];
    }
    $uncovered = AssigneeMemberships::uncovered($assignees, $actors, $members, ['atlassian-addons-project-access']);
    if ($assigneeOnly && $assignees === []) {
        printf("  [note] No staged issues: assignees without membership cannot be proposed (run 10 --phases=jira before 04).%s", PHP_EOL);
    } elseif ($uncovered !== []) {
        printf("  Proposed %d assignee(s) without membership as direct members (B46).%s", count($uncovered), PHP_EOL);
    }
    $actors = array_merge($actors, $uncovered);

    // Watchers no membership covers (B50, Jan 2026-10-14): migrated, so the history shows who followed an issue.
    $watcherRows = !$watcherOnly ? false : $pdo->query(<<<SQL
        SELECT DISTINCT i.project_id, p.project_key, p.name AS project_name, w.account_id,
               JSON_UNQUOTE(JSON_EXTRACT(w.raw_payload, '$.displayName')) AS display_name
        FROM staging_jira_watchers w
        JOIN staging_jira_issues i ON i.id = w.issue_id
        LEFT JOIN staging_jira_projects p ON p.id = i.project_id
        ORDER BY i.project_id, w.account_id
    SQL);
    $watchers = [];
    foreach ($watcherRows !== false ? $watcherRows->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $watchers[] = [
            'project_id' => (string)$row['project_id'],
            'project_key' => $row['project_key'] !== null ? (string)$row['project_key'] : null,
            'project_name' => $row['project_name'] !== null ? (string)$row['project_name'] : null,
            'account_id' => (string)$row['account_id'],
            'display_name' => $row['display_name'] !== null ? (string)$row['display_name'] : null,
        ];
    }
    $uncoveredWatchers = WatcherMemberships::uncovered($watchers, $actors, $members, ['atlassian-addons-project-access']);
    if ($watcherOnly && $watchers === []) {
        printf("  [note] No staged watchers: watchers without membership cannot be proposed (run 14 --phases=jira before 04).%s", PHP_EOL);
    } elseif ($uncoveredWatchers !== []) {
        printf("  Proposed %d watcher(s) without membership as direct members (B50).%s", count($uncoveredWatchers), PHP_EOL);
    }
    $actors = array_merge($actors, $uncoveredWatchers);

    // Jan, decision 14 (option G): a group Jira limits workflow transitions to, made a member with its own role
    $stagedProjects = [];
    $projectRows = $pdo->query('SELECT id, project_key, name FROM staging_jira_projects');
    foreach ($projectRows !== false ? $projectRows->fetchAll(PDO::FETCH_ASSOC) : [] as $project) {
        $stagedProjects[(string)$project['project_key']] = ['id' => (string)$project['id'], 'name' => $project['name'] !== null ? (string)$project['name'] : null];
    }
    $stagedGroups = [];
    foreach ($groupNames as $groupId => $groupName) {
        $stagedGroups[mb_strtolower((string)$groupName, 'UTF-8')] = ['id' => (string)$groupId, 'name' => (string)$groupName];
    }
    $workflowGroups = WorkflowGroupRoles::actors($workflowsConfig, $stagedProjects, $stagedGroups);
    foreach ($workflowGroups['unknown'] as $unknown) {
        printf("  [warn] migration.workflows.group_roles: %s; no membership proposed (decision 14).%s", $unknown, PHP_EOL);
    }
    if ($workflowGroups['actors'] !== []) {
        printf("  Proposed %d group(s) as members with their own workflow role (decision 14).%s", count($workflowGroups['actors']), PHP_EOL);
    }
    $actors = array_merge($actors, $workflowGroups['actors']);

    $statement = $pdo->prepare(<<<SQL
        INSERT INTO migration_mapping_project_role_groups (
            jira_project_id,
            jira_project_key,
            jira_project_name,
            jira_role_id,
            jira_role_name,
            jira_group_id,
            jira_group_name,
            principal_type
        ) VALUES (
            :jira_project_id,
            :jira_project_key,
            :jira_project_name,
            :jira_role_id,
            :jira_role_name,
            :jira_group_id,
            :jira_group_name,
            :principal_type
        )
        ON DUPLICATE KEY UPDATE
            jira_project_key   = VALUES(jira_project_key),
            jira_project_name  = VALUES(jira_project_name),
            jira_role_name     = VALUES(jira_role_name),
            jira_group_name    = VALUES(jira_group_name)
        SQL);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare insert statement for migration_mapping_project_role_groups.');
    }

    try {
        foreach (ProjectRolePrincipals::fromActors($actors, $groupNames) as $principal) {
            $statement->execute([
                'jira_project_id' => $principal['project_id'],
                'jira_project_key' => $principal['project_key'],
                'jira_project_name' => $principal['project_name'],
                'jira_role_id' => $principal['role_id'],
                'jira_role_name' => $principal['role_name'],
                'jira_group_id' => $principal['principal_id'],
                'jira_group_name' => $principal['principal_name'],
                'principal_type' => $principal['principal_type'],
            ]);
        }
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronize migration_mapping_project_role_groups: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @param PDO $pdo
 * @return array<string, array<int, array{id: int, name: string}>>
 */
function fetchRedmineRoleLookup(PDO $pdo): array
{
    $sql = <<<SQL
SELECT id, name
FROM staging_redmine_roles
ORDER BY id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Redmine role lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array{id: int, name: string}> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $lookup = [];
    foreach ($rows as $row) {
        $name = normalizeString($row['name'], 255);
        if ($name === null) {
            continue;
        }

        $key = strtolower($name);
        $lookup[$key][] = [
            'id' => (int)$row['id'],
            'name' => $name,
        ];
    }

    return $lookup;
}

/**
 * @param PDO $pdo
 * @return array<int, array<int, array<int, bool>>>
 */
function fetchRedmineGroupProjectRoleAssignments(PDO $pdo): array
{
    $sql = <<<SQL
SELECT group_id, project_id, role_id
FROM staging_redmine_group_project_roles
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Redmine group project role assignments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $assignments = [];
    foreach ($rows as $row) {
        $groupId = isset($row['group_id']) ? (int)$row['group_id'] : 0;
        $projectId = isset($row['project_id']) ? (int)$row['project_id'] : 0;
        $roleId = isset($row['role_id']) ? (int)$row['role_id'] : 0;

        if ($groupId <= 0 || $projectId <= 0 || $roleId <= 0) {
            continue;
        }

        if (!isset($assignments[$groupId])) {
            $assignments[$groupId] = [];
        }

        if (!isset($assignments[$groupId][$projectId])) {
            $assignments[$groupId][$projectId] = [];
        }

        $assignments[$groupId][$projectId][$roleId] = true;
    }

    return $assignments;
}

/**
 * @param PDO $pdo
 * @param array<string, array<int, array{id: int, name: string}>> $redmineRoleLookup
 * @param RoleMapping $roleMapping
 * @return array{summary: array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int}, status_counts: array<string, int>}
 */
function runRoleDefinitionTransformation(PDO $pdo, array $redmineRoleLookup, RoleMapping $roleMapping): array
{
    $mappings = fetchRoleDefinitionMappings($pdo);
    if ($mappings === []) {
        return [
            'summary' => [
                'matched' => 0,
                'ready_for_creation' => 0,
                'manual_review' => 0,
                'manual_overrides' => 0,
                'skipped' => 0,
                'unchanged' => 0,
            ],
            'status_counts' => fetchRoleDefinitionStatusCounts($pdo),
        ];
    }

    // MANUAL and IGNORED rows are judged again (a new mapping in the config); hand edits keep their hash.
    $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND', 'MANUAL_INTERVENTION_REQUIRED', 'IGNORED'];

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
    ];

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_roles
        SET
            redmine_role_id = :redmine_role_id,
            proposed_redmine_role_id = :proposed_redmine_role_id,
            proposed_redmine_role_name = :proposed_redmine_role_name,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_roles.');
    }

    foreach ($mappings as $row) {
        $currentStatus = $row['migration_status'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash']);
        $currentAutomationHash = computeRoleDefinitionAutomationHash(
            $row['redmine_role_id'],
            $row['proposed_redmine_role_id'],
            $row['proposed_redmine_role_name'],
            $currentStatus,
            $row['notes']
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            printf(
                "  [preserved] Jira role %d (%s) mapping #%d has manual overrides; skipping.%s",
                $row['jira_role_id'],
                $row['jira_role_name'] ?? 'unknown',
                $row['mapping_id'],
                PHP_EOL
            );
            $summary['manual_overrides']++;
            $summary['skipped']++;
            continue;
        }

        $targetRoleName = $roleMapping->redmineRoleName($row['jira_role_name']);
        $normalizedRoleName = $targetRoleName !== null ? strtolower($targetRoleName) : null;
        $matches = $normalizedRoleName !== null ? ($redmineRoleLookup[$normalizedRoleName] ?? []) : [];

        if ($roleMapping->isIgnored($row['jira_role_name'])) {
            $newStatus = 'IGNORED';
            $newNotes = 'Ignored by migration.roles.mappings.';
            $newRedmineRoleId = null;
            $newProposedId = null;
            $newProposedName = null;
            $summary['skipped']++;
        } elseif ($normalizedRoleName === null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newNotes = 'Missing Jira role name in staging snapshot.';
            $newRedmineRoleId = null;
            $newProposedId = null;
            $newProposedName = null;
            $summary['manual_review']++;
        } elseif ($matches === []) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newNotes = sprintf(
                'No Redmine role named "%s". Map it in migration.roles.mappings or set redmine_role_id manually.',
                $targetRoleName
            );
            $newRedmineRoleId = null;
            $newProposedId = null;
            $newProposedName = null;
            $summary['manual_review']++;
        } elseif (count($matches) > 1) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newNotes = sprintf('Multiple Redmine roles share the normalized name "%s".', $row['jira_role_name']);
            $newRedmineRoleId = null;
            $newProposedId = null;
            $newProposedName = null;
            $summary['manual_review']++;
        } else {
            $match = $matches[0];
            $newStatus = 'MATCH_FOUND';
            $newRedmineRoleId = $match['id'];
            $newProposedId = $match['id'];
            $newProposedName = $match['name'];
            $newNotes = null;
            $summary['matched']++;
        }

        $newAutomationHash = computeRoleDefinitionAutomationHash(
            $newRedmineRoleId,
            $newProposedId,
            $newProposedName,
            $newStatus,
            $newNotes
        );

        $updateStatement->execute([
            'redmine_role_id' => $newRedmineRoleId,
            'proposed_redmine_role_id' => $newProposedId,
            'proposed_redmine_role_name' => $newProposedName,
            'migration_status' => $newStatus,
            'notes' => $newNotes,
            'automation_hash' => $newAutomationHash,
            'mapping_id' => $row['mapping_id'],
        ]);

        if (
            $currentStatus === $newStatus
            && $row['redmine_role_id'] === $newRedmineRoleId
            && $row['proposed_redmine_role_id'] === $newProposedId
            && $row['proposed_redmine_role_name'] === $newProposedName
            && $row['notes'] === $newNotes
        ) {
            $summary['unchanged']++;
        }
    }

    return [
        'summary' => $summary,
        'status_counts' => fetchRoleDefinitionStatusCounts($pdo),
    ];
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_role_id: int,
 *     jira_role_name: ?string,
 *     redmine_role_id: ?int,
 *     proposed_redmine_role_id: ?int,
 *     proposed_redmine_role_name: ?string,
 *     migration_status: string,
 *     notes: ?string,
 *     automation_hash: ?string
 * }>
 */
function fetchRoleDefinitionMappings(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mapping_id,
    jira_role_id,
    jira_role_name,
    redmine_role_id,
    proposed_redmine_role_id,
    proposed_redmine_role_name,
    migration_status,
    notes,
    automation_hash
FROM migration_mapping_roles
ORDER BY mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch role definition mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $mappings = [];
    foreach ($rows as $row) {
        $mappings[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_role_id' => (int)$row['jira_role_id'],
            'jira_role_name' => $row['jira_role_name'] !== null ? (string)$row['jira_role_name'] : null,
            'redmine_role_id' => $row['redmine_role_id'] !== null ? (int)$row['redmine_role_id'] : null,
            'proposed_redmine_role_id' => $row['proposed_redmine_role_id'] !== null ? (int)$row['proposed_redmine_role_id'] : null,
            'proposed_redmine_role_name' => $row['proposed_redmine_role_name'] !== null ? (string)$row['proposed_redmine_role_name'] : null,
            'migration_status' => (string)$row['migration_status'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'automation_hash' => $row['automation_hash'] !== null ? (string)$row['automation_hash'] : null,
        ];
    }

    return $mappings;
}

/**
 * @param PDO $pdo
 * @return array<string, int>
 */
function fetchRoleDefinitionStatusCounts(PDO $pdo): array
{
    $sql = <<<SQL
SELECT migration_status, COUNT(*) AS total
FROM migration_mapping_roles
GROUP BY migration_status
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch role definition status counts: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $counts = [];
    foreach ($rows as $row) {
        $counts[(string)$row['migration_status']] = (int)$row['total'];
    }

    return $counts;
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @param array<string, array<int, array{id: int, name: string}>> $redmineRoleLookup
 * @param array<int, array<int, array<int, bool>>> $existingAssignments
 * @param RoleMapping $roleMapping
 * @return array{summary: array{ready_for_assignment: int, already_recorded: int, awaiting_project: int, awaiting_group: int, awaiting_role: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int}, status_counts: array<string, int>}
 */
function runProjectRoleAssignmentTransformation(PDO $pdo, array $config, array $redmineRoleLookup, array $existingAssignments, RoleMapping $roleMapping): array
{
    $defaultRoleId = determineDefaultRedmineRoleId($config);
    $defaultRoleName = $defaultRoleId !== null ? fetchRedmineRoleName($pdo, $defaultRoleId) : null;

    $mappings = fetchProjectRoleAssignmentMappings($pdo);
    if ($mappings === []) {
        return [
            'summary' => [
                'ready_for_assignment' => 0,
                'already_recorded' => 0,
                'awaiting_project' => 0,
                'awaiting_group' => 0,
                'awaiting_role' => 0,
                'manual_review' => 0,
                'manual_overrides' => 0,
                'skipped' => 0,
                'unchanged' => 0,
            ],
            'status_counts' => fetchProjectRoleAssignmentStatusCounts($pdo),
        ];
    }

    $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_ASSIGNMENT', 'AWAITING_PROJECT', 'AWAITING_GROUP', 'AWAITING_ROLE', 'ASSIGNMENT_RECORDED', 'IGNORED'];

    $summary = [
        'ready_for_assignment' => 0,
        'already_recorded' => 0,
        'awaiting_project' => 0,
        'awaiting_group' => 0,
        'awaiting_role' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
    ];

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_project_role_groups
        SET
            redmine_project_id = :redmine_project_id,
            redmine_group_id = :redmine_group_id,
            redmine_role_id = :redmine_role_id,
            proposed_redmine_role_id = :proposed_redmine_role_id,
            proposed_redmine_role_name = :proposed_redmine_role_name,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_project_role_groups.');
    }

    foreach ($mappings as $row) {
        $currentStatus = $row['migration_status'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        // Jan reviews the proposals in this table before the push (B31): his edits stay
        if (AssignmentHash::isHandEdited($row)) {
            printf(
                "  [preserved] Jira project %s role %s group %s (mapping #%d) has manual overrides; skipping.%s",
                formatJiraProjectReference($row['jira_project_key'], $row['jira_project_name'], $row['jira_project_id']),
                $row['jira_role_name'] ?? (string)$row['jira_role_id'],
                $row['jira_group_name'] ?? (string)$row['jira_group_id'],
                $row['mapping_id'],
                PHP_EOL
            );
            $summary['manual_overrides']++;
            $summary['skipped']++;
            continue;
        }

        $newStatus = $currentStatus;
        $newGroupId = $row['redmine_group_id'];
        $newRoleId = $row['redmine_role_id'];
        $newProposedRoleId = $row['proposed_redmine_role_id'];
        $newProposedRoleName = $row['proposed_redmine_role_name'];

        $manualReason = null;
        $isUser = $row['principal_type'] === ProjectRolePrincipals::USER;
        $principalLabel = $isUser ? 'user' : 'group';

        $ignoredReason = null;
        if ($row['matched_redmine_role_status'] === 'IGNORED' || $roleMapping->isIgnored($row['jira_role_name'])) {
            $ignoredReason = 'Jira role ignored by migration.roles.mappings.';
        } elseif ($row['group_migration_status'] === 'IGNORED') {
            $ignoredReason = sprintf('Jira %s is not migrated (ignored).', $principalLabel);
        }

        if ($ignoredReason !== null) {
            $newProjectId = $row['redmine_project_id'];
            $newStatus = 'IGNORED';
            $newGroupId = null;
            $newRoleId = null;
            $newAutomationHash = computeProjectRoleAssignmentAutomationHash(
                $newProjectId,
                $newGroupId,
                $newRoleId,
                $newProposedRoleId,
                $newProposedRoleName,
                $newStatus,
                $ignoredReason
            );
            $updateStatement->execute([
                'redmine_project_id' => $newProjectId,
                'redmine_group_id' => $newGroupId,
                'redmine_role_id' => $newRoleId,
                'proposed_redmine_role_id' => $newProposedRoleId,
                'proposed_redmine_role_name' => $newProposedRoleName,
                'migration_status' => $newStatus,
                'notes' => $ignoredReason,
                'automation_hash' => $newAutomationHash,
                'mapping_id' => $row['mapping_id'],
            ]);
            if ($currentStatus === $newStatus && $row['notes'] === $ignoredReason) {
                $summary['unchanged']++;
            }
            $summary['skipped']++;
            continue;
        }

        if ($row['mapped_redmine_project_id'] === null) {
            $manualReason = 'Awaiting project migration: no Redmine project mapping available yet.';
            $newStatus = 'AWAITING_PROJECT';
            $newProjectId = null;
            $summary['awaiting_project']++;
        } elseif ($row['project_migration_status'] !== null && !in_array($row['project_migration_status'], ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
            $manualReason = sprintf('Project mapping is not ready (status: %s).', $row['project_migration_status']);
            $newStatus = 'AWAITING_PROJECT';
            $newProjectId = null;
            $summary['awaiting_project']++;
        } else {
            $newProjectId = $row['mapped_redmine_project_id'];
        }

        if ($manualReason === null) {
            if ($row['mapped_redmine_group_id'] === null) {
                $manualReason = sprintf('Awaiting %1$s migration: no Redmine %1$s mapping available yet.', $principalLabel);
                $newStatus = 'AWAITING_GROUP';
                $newGroupId = null;
                $summary['awaiting_group']++;
            } elseif ($row['group_migration_status'] !== null && !in_array($row['group_migration_status'], ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
                $manualReason = sprintf('%s mapping is not ready (status: %s).', ucfirst($principalLabel), $row['group_migration_status']);
                $newStatus = 'AWAITING_GROUP';
                $newGroupId = null;
                $summary['awaiting_group']++;
            } else {
                $newGroupId = $row['mapped_redmine_group_id'];
            }
        }

        if ($manualReason === null) {
            $resolvedRole = resolveTargetRedmineRole($row, $redmineRoleLookup, $defaultRoleId, $defaultRoleName, $roleMapping);
            if ($resolvedRole['status'] === 'AWAITING_ROLE') {
                $manualReason = (int)$row['jira_role_id'] === WorkflowGroupRoles::ROLE_ID
                    ? sprintf('Redmine has no role "%s" yet: 04\'s push creates it (decision 14).', (string)$row['jira_role_name'])
                    : $resolvedRole['message'];
                $newStatus = 'AWAITING_ROLE';
                $newRoleId = null;
                $newProposedRoleId = $resolvedRole['proposed_role_id'];
                $newProposedRoleName = $resolvedRole['proposed_role_name'];
                $summary['awaiting_role']++;
            } elseif ($row['jira_group_id'] === PermissionGrants::APPLICATION_ROLE) {
                // every licensed user in Jira: proposed for Redmine's Non member group, pushed only once Jan sets READY
                $newRoleId = $resolvedRole['role_id'];
                $newProposedRoleId = $resolvedRole['proposed_role_id'];
                $newProposedRoleName = $resolvedRole['proposed_role_name'];
                $manualReason = PermissionGrants::APPLICATION_ROLE_NOTE;
                $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            } else {
                $newRoleId = $resolvedRole['role_id'];
                $newProposedRoleId = $resolvedRole['proposed_role_id'];
                $newProposedRoleName = $resolvedRole['proposed_role_name'];
                $newStatus = 'READY_FOR_ASSIGNMENT';
                $summary['ready_for_assignment']++;
            }
        }

        if (
            $manualReason === null
            && $newStatus === 'READY_FOR_ASSIGNMENT'
            && AssignmentStatus::afterTransform(
                $currentStatus,
                [$row['redmine_project_id'], $row['redmine_group_id'], $row['redmine_role_id']],
                [$newProjectId, $newGroupId, $newRoleId],
                $newProjectId !== null && $newGroupId !== null && $newRoleId !== null
                    && redmineProjectRoleAssignmentExists($existingAssignments, $newGroupId, $newProjectId, $newRoleId)
            ) === 'ASSIGNMENT_RECORDED'
        ) {
            if ($summary['ready_for_assignment'] > 0) {
                $summary['ready_for_assignment']--;
            }

            $newStatus = 'ASSIGNMENT_RECORDED';
            $summary['already_recorded']++;
        }

        if ($manualReason !== null) {
            $newNotes = $manualReason;
            if (!in_array($newStatus, ['AWAITING_PROJECT', 'AWAITING_GROUP', 'AWAITING_ROLE'], true)) {
                $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
                $summary['manual_review']++;
            }
        } else {
            // an assignee-only or watcher-only row says so in the review table (B46, B50)
            $newNotes = match ((int)$row['jira_role_id']) {
                AssigneeMemberships::ROLE_ID => AssigneeMemberships::NOTE,
                WatcherMemberships::ROLE_ID => WatcherMemberships::NOTE,
                WorkflowGroupRoles::ROLE_ID => WorkflowGroupRoles::NOTE,
                default => null,
            };
        }

        $newAutomationHash = computeProjectRoleAssignmentAutomationHash(
            $newProjectId,
            $newGroupId,
            $newRoleId,
            $newProposedRoleId,
            $newProposedRoleName,
            $newStatus,
            $newNotes
        );

        $updateStatement->execute([
            'redmine_project_id' => $newProjectId,
            'redmine_group_id' => $newGroupId,
            'redmine_role_id' => $newRoleId,
            'proposed_redmine_role_id' => $newProposedRoleId,
            'proposed_redmine_role_name' => $newProposedRoleName,
            'migration_status' => $newStatus,
            'notes' => $newNotes,
            'automation_hash' => $newAutomationHash,
            'mapping_id' => $row['mapping_id'],
        ]);

        if (
            $currentStatus === $newStatus
            && $row['redmine_project_id'] === $newProjectId
            && $row['redmine_group_id'] === $newGroupId
            && $row['redmine_role_id'] === $newRoleId
            && $row['proposed_redmine_role_id'] === $newProposedRoleId
            && $row['proposed_redmine_role_name'] === $newProposedRoleName
            && $row['notes'] === $newNotes
        ) {
            $summary['unchanged']++;
        }
    }

    return [
        'summary' => $summary,
        'status_counts' => fetchProjectRoleAssignmentStatusCounts($pdo),
    ];
}

/**
 * @param array<string, mixed> $config
 * @return int|null
 */
function determineDefaultRedmineRoleId(array $config): ?int
{
    $migrationConfig = $config['migration'] ?? [];
    if (!is_array($migrationConfig)) {
        return null;
    }

    $roleConfig = $migrationConfig['roles'] ?? [];
    if (!is_array($roleConfig)) {
        return null;
    }

    $candidate = $roleConfig['default_redmine_role_id'] ?? null;
    if ($candidate === null) {
        return null;
    }

    if (is_int($candidate)) {
        return $candidate;
    }

    if (is_numeric($candidate)) {
        return (int)$candidate;
    }

    return null;
}

/**
 * @param PDO $pdo
 * @param int $roleId
 * @return string|null
 */
function fetchRedmineRoleName(PDO $pdo, int $roleId): ?string
{
    $sql = 'SELECT name FROM staging_redmine_roles WHERE id = :id LIMIT 1';

    try {
        $statement = $pdo->prepare($sql);
        if ($statement === false) {
            return null;
        }

        $statement->execute(['id' => $roleId]);
        $result = $statement->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Redmine role name: ' . $exception->getMessage(), 0, $exception);
    }

    if (!is_array($result) || !isset($result['name'])) {
        return null;
    }

    return (string)$result['name'];
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_project_id: string,
 *     jira_project_key: ?string,
 *     jira_project_name: ?string,
 *     jira_role_id: int,
 *     jira_role_name: ?string,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     principal_type: string,
 *     redmine_project_id: ?int,
 *     redmine_group_id: ?int,
 *     redmine_role_id: ?int,
 *     proposed_redmine_role_id: ?int,
 *     proposed_redmine_role_name: ?string,
 *     migration_status: string,
 *     notes: ?string,
 *     automation_hash: ?string,
 *     mapped_redmine_project_id: ?int,
 *     project_migration_status: ?string,
 *     mapped_redmine_group_id: ?int,
 *     group_migration_status: ?string,
 *     matched_redmine_role_id: ?int,
 *     matched_redmine_role_status: ?string
 * }>
 */
function fetchProjectRoleAssignmentMappings(PDO $pdo): array
{
    $applicationRole = PermissionGrants::APPLICATION_ROLE;
    $sql = <<<SQL
SELECT
    mmprg.mapping_id,
    mmprg.jira_project_id,
    mmprg.jira_project_key,
    mmprg.jira_project_name,
    mmprg.jira_role_id,
    mmprg.jira_role_name,
    mmprg.jira_group_id,
    mmprg.jira_group_name,
    mmprg.principal_type,
    mmprg.redmine_project_id,
    mmprg.redmine_group_id,
    mmprg.redmine_role_id,
    mmprg.proposed_redmine_role_id,
    mmprg.proposed_redmine_role_name,
    mmprg.migration_status,
    mmprg.notes,
    mmprg.automation_hash,
    mmproj.redmine_project_id AS mapped_redmine_project_id,
    mmproj.migration_status AS project_migration_status,
    COALESCE(mmgroup.redmine_group_id, mmuser.redmine_user_id, nonmember.id) AS mapped_redmine_group_id,
    COALESCE(mmgroup.migration_status, mmuser.migration_status, IF(nonmember.id IS NULL, NULL, 'MATCH_FOUND')) AS group_migration_status,
    mmroles.redmine_role_id AS matched_redmine_role_id,
    mmroles.migration_status AS matched_redmine_role_status
FROM migration_mapping_project_role_groups AS mmprg
LEFT JOIN migration_mapping_projects AS mmproj ON mmproj.jira_project_id = mmprg.jira_project_id
LEFT JOIN migration_mapping_groups AS mmgroup
       ON mmprg.principal_type = 'group' AND mmgroup.jira_group_id = mmprg.jira_group_id
LEFT JOIN migration_mapping_users AS mmuser
       ON mmprg.principal_type = 'user' AND mmuser.jira_account_id = mmprg.jira_group_id
LEFT JOIN staging_redmine_builtin_groups AS nonmember
       ON mmprg.principal_type = 'group' AND mmprg.jira_group_id = '{$applicationRole}' AND nonmember.builtin = 'non_member'
LEFT JOIN migration_mapping_roles AS mmroles ON mmroles.jira_role_id = mmprg.jira_role_id
ORDER BY mmprg.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch project role assignment mappings: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $mappings = [];
    foreach ($rows as $row) {
        $mappings[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_project_id' => (string)$row['jira_project_id'],
            'jira_project_key' => $row['jira_project_key'] !== null ? (string)$row['jira_project_key'] : null,
            'jira_project_name' => $row['jira_project_name'] !== null ? (string)$row['jira_project_name'] : null,
            'jira_role_id' => (int)$row['jira_role_id'],
            'jira_role_name' => $row['jira_role_name'] !== null ? (string)$row['jira_role_name'] : null,
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'principal_type' => (string)$row['principal_type'],
            'redmine_project_id' => $row['redmine_project_id'] !== null ? (int)$row['redmine_project_id'] : null,
            'redmine_group_id' => $row['redmine_group_id'] !== null ? (int)$row['redmine_group_id'] : null,
            'redmine_role_id' => $row['redmine_role_id'] !== null ? (int)$row['redmine_role_id'] : null,
            'proposed_redmine_role_id' => $row['proposed_redmine_role_id'] !== null ? (int)$row['proposed_redmine_role_id'] : null,
            'proposed_redmine_role_name' => $row['proposed_redmine_role_name'] !== null ? (string)$row['proposed_redmine_role_name'] : null,
            'migration_status' => (string)$row['migration_status'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'automation_hash' => $row['automation_hash'] !== null ? (string)$row['automation_hash'] : null,
            'mapped_redmine_project_id' => $row['mapped_redmine_project_id'] !== null ? (int)$row['mapped_redmine_project_id'] : null,
            'project_migration_status' => $row['project_migration_status'] !== null ? (string)$row['project_migration_status'] : null,
            'mapped_redmine_group_id' => $row['mapped_redmine_group_id'] !== null ? (int)$row['mapped_redmine_group_id'] : null,
            'group_migration_status' => $row['group_migration_status'] !== null ? (string)$row['group_migration_status'] : null,
            'matched_redmine_role_id' => $row['matched_redmine_role_id'] !== null ? (int)$row['matched_redmine_role_id'] : null,
            'matched_redmine_role_status' => $row['matched_redmine_role_status'] !== null ? (string)$row['matched_redmine_role_status'] : null,
        ];
    }

    return $mappings;
}

/**
 * @param array{
 *     redmine_role_id: ?int,
 *     matched_redmine_role_id: ?int,
 *     matched_redmine_role_status: ?string,
 *     proposed_redmine_role_id: ?int,
 *     proposed_redmine_role_name: ?string,
 *     jira_role_name: ?string
 * } $row
 * @param array<string, array<int, array{id: int, name: string}>> $redmineRoleLookup
 * @param int|null $defaultRoleId
 * @param string|null $defaultRoleName
 * @param RoleMapping $roleMapping
 * @return array{status: string, role_id: ?int, proposed_role_id: ?int, proposed_role_name: ?string, message: ?string}
 */
function resolveTargetRedmineRole(array $row, array $redmineRoleLookup, ?int $defaultRoleId, ?string $defaultRoleName, RoleMapping $roleMapping): array
{
    $targetRoleName = $roleMapping->redmineRoleName($row['jira_role_name']);
    $resolvedRoleId = $row['redmine_role_id'] ?? $row['matched_redmine_role_id'];
    if ($resolvedRoleId !== null) {
        $roleName = $row['proposed_redmine_role_name'];
        if ($roleName === null && $targetRoleName !== null) {
            $normalized = strtolower($targetRoleName);
            $matches = $redmineRoleLookup[$normalized] ?? [];
            if ($matches !== []) {
                $roleName = $matches[0]['name'];
            }
        }

        return [
            'status' => 'READY',
            'role_id' => $resolvedRoleId,
            'proposed_role_id' => $resolvedRoleId,
            'proposed_role_name' => $roleName,
            'message' => null,
        ];
    }

    if ($targetRoleName !== null) {
        $normalized = strtolower($targetRoleName);
        $matches = $redmineRoleLookup[$normalized] ?? [];
        if (count($matches) === 1) {
            $match = $matches[0];
            return [
                'status' => 'READY',
                'role_id' => $match['id'],
                'proposed_role_id' => $match['id'],
                'proposed_role_name' => $match['name'],
                'message' => null,
            ];
        } elseif (count($matches) > 1) {
            return [
                'status' => 'AWAITING_ROLE',
                'role_id' => null,
                'proposed_role_id' => null,
                'proposed_role_name' => null,
                'message' => sprintf('Multiple Redmine roles share the normalized name "%s".', $targetRoleName),
            ];
        }
    }

    if ($defaultRoleId !== null && $defaultRoleName !== null) {
        return [
            'status' => 'AWAITING_ROLE',
            'role_id' => null,
            'proposed_role_id' => $defaultRoleId,
            'proposed_role_name' => $defaultRoleName,
            'message' => sprintf('No direct role match found; defaulting to Redmine role #%d (%s). Review before assignment.', $defaultRoleId, $defaultRoleName),
        ];
    }

    return [
        'status' => 'AWAITING_ROLE',
        'role_id' => null,
        'proposed_role_id' => null,
        'proposed_role_name' => null,
        'message' => 'No Redmine role resolved automatically. Set redmine_role_id manually or configure migration.roles.default_redmine_role_id.',
    ];
}

/**
 * @param PDO $pdo
 * @return array<string, int>
 */
function fetchProjectRoleAssignmentStatusCounts(PDO $pdo): array
{
    $sql = <<<SQL
SELECT migration_status, COUNT(*) AS total
FROM migration_mapping_project_role_groups
GROUP BY migration_status
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch project role assignment status counts: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $counts = [];
    foreach ($rows as $row) {
        $counts[(string)$row['migration_status']] = (int)$row['total'];
    }

    return $counts;
}

/**
 * @param PDO $pdo
 * @param bool $confirmPush
 * @param bool $isDryRun
 * @return void
 * @throws Throwable
 */
/**
 * @param PDO $pdo
 * @param bool $confirmPush
 * @param bool $isDryRun
 * @param array<string,mixed> $redmineConfig
 * @param bool $useExtendedApi
 * @return void
 * @throws Throwable
 */
function runRolePushPhase(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi, array $workflowsConfig = []): void
{
    ensureWorkflowGroupRoles($pdo, $confirmPush, $isDryRun, $redmineConfig, $useExtendedApi, $workflowsConfig);
    $pendingAssignments = fetchPendingRoleAssignments($pdo);
    $pendingCount = count($pendingAssignments);

    if ($useExtendedApi) {
        printf("[%s] Starting push phase (Redmine extended API)...%s", formatCurrentTimestamp(), PHP_EOL);

        if ($pendingCount === 0) {
            printf("  No project role assignments are marked as READY_FOR_ASSIGNMENT.%s", PHP_EOL);
            if ($isDryRun) {
                printf("  --dry-run flag enabled: no API calls will be made.%s", PHP_EOL);
            }
            if ($confirmPush) {
                printf("  --confirm-push provided but nothing to process.%s", PHP_EOL);
            }
            return;
        }

        $redmineClient = createRedmineClient($redmineConfig);
        $extendedApiPrefix = resolveExtendedApiPrefix($redmineConfig);
        verifyExtendedApiAvailability($redmineClient, $extendedApiPrefix, 'roles.json');

        printf("  %d assignment(s) queued for automatic creation.%s", $pendingCount, PHP_EOL);

        foreach ($pendingAssignments as $assignment) {
            printf(
                "  - Project: %s | Jira role: %s | Jira %s: %s%s",
                formatJiraProjectReference($assignment['jira_project_key'], $assignment['jira_project_name'], $assignment['jira_project_id']),
                $assignment['jira_role_name'] ?? ('Role #' . $assignment['jira_role_id']),
                $assignment['principal_type'],
                $assignment['jira_group_name'] ?? ('Group #' . $assignment['jira_group_id']),
                PHP_EOL
            );
            printf(
                "    Target: Redmine %s #%d (%s) -> project #%d with role #%d (%s).%s",
                $assignment['principal_type'],
                $assignment['redmine_group_id'],
                $assignment['redmine_group_name'],
                $assignment['redmine_project_id'],
                $assignment['redmine_role_id'],
                $assignment['redmine_role_name'],
                PHP_EOL
            );

            $warnings = [];
            if ($assignment['redmine_project_id'] === null) {
                $warnings[] = 'missing Redmine project mapping';
            }
            if ($assignment['redmine_group_id'] === null) {
                $warnings[] = 'missing Redmine group mapping';
            }
            if ($assignment['redmine_role_id'] === null) {
                $warnings[] = 'missing Redmine role mapping';
            }
            if ($warnings !== []) {
                printf("    Warning: %s.%s", implode(', ', $warnings), PHP_EOL);
            }

            if ($assignment['notes'] !== null) {
                printf("    Notes: %s%s", $assignment['notes'], PHP_EOL);
            }
        }

        if (!$confirmPush) {
            printf("  --confirm-push missing: reviewed payloads only, no data was sent to Redmine.%s", PHP_EOL);
            return;
        }

        if ($isDryRun) {
            printf("  --dry-run enabled: skipping API calls after previewing the assignments.%s", PHP_EOL);
            return;
        }

        $updateStatement = $pdo->prepare(<<<SQL
            UPDATE migration_mapping_project_role_groups
            SET
                redmine_project_id = :redmine_project_id,
                redmine_group_id = :redmine_group_id,
                redmine_role_id = :redmine_role_id,
                proposed_redmine_role_id = :proposed_redmine_role_id,
                proposed_redmine_role_name = :proposed_redmine_role_name,
                migration_status = :migration_status,
                notes = :notes,
                automation_hash = :automation_hash
            WHERE mapping_id = :mapping_id
        SQL);

        if ($updateStatement === false) {
            throw new RuntimeException('Failed to prepare update statement for migration_mapping_project_role_groups during the push phase.');
        }

        $createdCount = 0;
        $updatedCount = 0;
        $presentCount = 0;
        $failureCount = 0;

        // Redmine memberships per project, per principal id (user or group), read once and kept current.
        /** @var array<int, array<int, array{id: int, role_ids: list<int>}>> $membershipsByProject */
        $membershipsByProject = [];

        $record = static function (array $assignment, string $status, ?string $notes) use ($updateStatement): void {
            $projectId = $assignment['redmine_project_id'];
            $groupId = $assignment['redmine_group_id'];
            $roleId = $assignment['redmine_role_id'];
            $proposedRoleId = $assignment['proposed_redmine_role_id'] ?? $roleId;
            $proposedRoleName = $assignment['proposed_redmine_role_name'] ?? $assignment['redmine_role_name'];

            $updateStatement->execute([
                'redmine_project_id' => $projectId,
                'redmine_group_id' => $groupId,
                'redmine_role_id' => $roleId,
                'proposed_redmine_role_id' => $proposedRoleId,
                'proposed_redmine_role_name' => $proposedRoleName,
                'migration_status' => $status,
                'notes' => $notes,
                'automation_hash' => computeProjectRoleAssignmentAutomationHash(
                    $projectId,
                    $groupId,
                    $roleId,
                    $proposedRoleId,
                    $proposedRoleName,
                    $status,
                    $notes
                ),
                'mapping_id' => (int)$assignment['mapping_id'],
            ]);
        };

        foreach ($pendingAssignments as $assignment) {
            $projectId = $assignment['redmine_project_id'];
            $groupId = $assignment['redmine_group_id'];
            $roleId = $assignment['redmine_role_id'];
            $notes = $assignment['notes'] ?? null;
            $projectReference = formatJiraProjectReference(
                $assignment['jira_project_key'],
                $assignment['jira_project_name'],
                $assignment['jira_project_id']
            );

            if ($projectId === null || $groupId === null || $roleId === null) {
                $errorMessage = 'Missing Redmine project/group/role identifiers for automatic assignment.';
                $record($assignment, 'MANUAL_INTERVENTION_REQUIRED', $errorMessage);
                printf(
                    "  [skipped] Project %s, Jira role %s: %s%s",
                    $projectReference,
                    $assignment['jira_role_name'] ?? ('Role #' . $assignment['jira_role_id']),
                    $errorMessage,
                    PHP_EOL
                );
                $failureCount++;
                continue;
            }

            try {
                if (!isset($membershipsByProject[$projectId])) {
                    $membershipsByProject[$projectId] = fetchRedmineProjectMemberships($redmineClient, $projectId);
                }
                // A principal can get several roles on a project and can already be a member (B31): add the role.
                $plan = MembershipPlan::decide($membershipsByProject[$projectId][$groupId] ?? null, $roleId);

                if ($plan['action'] === 'none') {
                    $record($assignment, 'ASSIGNMENT_RECORDED', $notes);
                    printf(
                        "  [present] Project %s -> %s #%d already has role #%d.%s",
                        $projectReference,
                        $assignment['principal_type'],
                        $groupId,
                        $roleId,
                        PHP_EOL
                    );
                    $presentCount++;
                    continue;
                }

                if ($plan['action'] === 'create') {
                    // membership[user_id] takes a user or a group id
                    $response = $redmineClient->post(
                        buildExtendedApiPath($extendedApiPrefix, sprintf('projects/%d/memberships.json', $projectId)),
                        ['json' => ['membership' => ['user_id' => $groupId, 'role_ids' => $plan['role_ids']]]]
                    );
                    $membershipId = extractCreatedMembershipId(decodeJsonResponse($response));
                    $membershipsByProject[$projectId][$groupId] = ['id' => (int)$membershipId, 'role_ids' => $plan['role_ids']];
                    $createdCount++;
                } else {
                    $membership = $membershipsByProject[$projectId][$groupId];
                    $membershipId = (string)$membership['id'];
                    // inherited roles stay: Redmine keeps them on a role_ids update
                    $redmineClient->put(
                        sprintf('memberships/%d.json', $membership['id']),
                        ['json' => ['membership' => ['role_ids' => $plan['role_ids']]]]
                    );
                    $membershipsByProject[$projectId][$groupId]['role_ids'] = $plan['role_ids'];
                    $updatedCount++;
                }

                $record($assignment, 'ASSIGNMENT_RECORDED', null);
                printf(
                    "  [%s] Project %s -> Redmine %s #%d with role #%d (membership #%s, roles %s).%s",
                    $plan['action'] === 'create' ? 'created' : 'role added',
                    $projectReference,
                    $assignment['principal_type'],
                    $groupId,
                    $roleId,
                    $membershipId,
                    implode(',', $plan['role_ids']),
                    PHP_EOL
                );
            } catch (Throwable $exception) {
                $errorMessage = summarizeExtendedApiError($exception);
                $record($assignment, 'MANUAL_INTERVENTION_REQUIRED', $errorMessage);
                printf(
                    "  [failed] Project %s -> %s #%d with role #%d: %s%s",
                    $projectReference,
                    $assignment['principal_type'],
                    $groupId,
                    $roleId,
                    $errorMessage,
                    PHP_EOL
                );

                if ($notes !== null) {
                    printf("    Previous notes: %s%s", $notes, PHP_EOL);
                }

                $failureCount++;
            }
        }

        printf(
            "  Completed extended API push. Created: %d, Role added: %d, Already present: %d, Failed: %d.%s",
            $createdCount,
            $updatedCount,
            $presentCount,
            $failureCount,
            PHP_EOL
        );

        return;
    }

    // -----------------------------
    // Klassieke push fase (manual)
    // -----------------------------

    printf("[%s] Starting push phase (manual assignment preview)...%s", formatCurrentTimestamp(), PHP_EOL);

    if ($pendingCount === 0) {
        printf("  No project role assignments are marked as READY_FOR_ASSIGNMENT.%s", PHP_EOL);
        if ($isDryRun) {
            printf("  --dry-run flag enabled: no database changes will be made.%s", PHP_EOL);
        }
        if ($confirmPush) {
            printf("  --confirm-push provided but nothing to process.%s", PHP_EOL);
        } else {
            printf("  Provide --confirm-push to acknowledge manual completion after reviewing the plan.%s", PHP_EOL);
        }
        return;
    }

    printf("  %d assignment(s) queued for manual action.%s", $pendingCount, PHP_EOL);
    foreach ($pendingAssignments as $assignment) {
        printf(
            "  - Project: %s | Jira role: %s | Jira %s: %s%s",
            formatJiraProjectReference($assignment['jira_project_key'], $assignment['jira_project_name'], $assignment['jira_project_id']),
            $assignment['jira_role_name'] ?? ('Role #' . $assignment['jira_role_id']),
            $assignment['principal_type'],
            $assignment['jira_group_name'] ?? ('Group #' . $assignment['jira_group_id']),
            PHP_EOL
        );
        printf(
            "    Assign Redmine %s #%d (%s) to Redmine project #%d with role #%d (%s).%s",
            $assignment['principal_type'],
            $assignment['redmine_group_id'],
            $assignment['redmine_group_name'],
            $assignment['redmine_project_id'],
            $assignment['redmine_role_id'],
            $assignment['redmine_role_name'],
            PHP_EOL
        );
        if ($assignment['notes'] !== null) {
            printf("    Notes: %s%s", $assignment['notes'], PHP_EOL);
        }
    }

    if (!$confirmPush) {
        printf("  --confirm-push flag missing: the assignments remain in READY_FOR_ASSIGNMENT.%s", PHP_EOL);
        return;
    }

    if ($isDryRun) {
        printf("  --dry-run flag enabled: skipping status updates despite --confirm-push.%s", PHP_EOL);
        return;
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_project_role_groups
        SET migration_status = 'ASSIGNMENT_RECORDED', automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement when marking assignments as recorded.');
    }

    $pdo->beginTransaction();

    try {
        foreach ($pendingAssignments as $assignment) {
            $newAutomationHash = computeProjectRoleAssignmentAutomationHash(
                $assignment['redmine_project_id'],
                $assignment['redmine_group_id'],
                $assignment['redmine_role_id'],
                $assignment['redmine_role_id'],
                $assignment['redmine_role_name'],
                'ASSIGNMENT_RECORDED',
                $assignment['notes']
            );

            $updateStatement->execute([
                'mapping_id' => $assignment['mapping_id'],
                'automation_hash' => $newAutomationHash,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        $pdo->rollBack();
        throw $exception;
    }

    printf("  Marked %d assignment(s) as ASSIGNMENT_RECORDED after manual confirmation.%s", $pendingCount, PHP_EOL);
}

/**
 * Jan, decision 14 (option G): creates the groups' own workflow roles Redmine lacks (Extended API), then makes their
 * memberships ready for the push below. Without --confirm-push, or with --dry-run, it only says what it would create.
 *
 * @param array<string, mixed> $redmineConfig
 * @param array<string, mixed> $workflowsConfig migration.workflows
 */
function ensureWorkflowGroupRoles(PDO $pdo, bool $confirmPush, bool $isDryRun, array $redmineConfig, bool $useExtendedApi, array $workflowsConfig): void
{
    $statement = $pdo->prepare(<<<SQL
        SELECT mapping_id, jira_role_name, redmine_project_id, redmine_group_id
        FROM migration_mapping_project_role_groups
        WHERE jira_role_id = :role_id AND migration_status = 'AWAITING_ROLE'
            AND redmine_project_id IS NOT NULL AND redmine_group_id IS NOT NULL AND jira_role_name IS NOT NULL
        ORDER BY mapping_id
    SQL);
    if ($statement === false) {
        throw new RuntimeException('Failed to prepare the query for the workflow roles of groups.');
    }
    $statement->execute(['role_id' => WorkflowGroupRoles::ROLE_ID]);
    $awaiting = $statement->fetchAll(PDO::FETCH_ASSOC);
    if ($awaiting === []) {
        return;
    }
    $names = array_values(array_unique(array_map(static fn (array $row): string => (string)$row['jira_role_name'], $awaiting)));
    $permissions = WorkflowGroupRoles::permissions($workflowsConfig['group_role_permissions'] ?? null);
    printf(
        "  %d workflow role(s) of groups (decision 14) for %d membership(s): %s; permissions %s, not assignable.%s",
        count($names),
        count($awaiting),
        implode(', ', $names),
        implode(', ', $permissions),
        PHP_EOL
    );
    if (!$useExtendedApi) {
        printf("  [manual] Create these roles in Redmine, then run 04 again: its transform finds them by name.%s", PHP_EOL);
        return;
    }
    if (!$confirmPush || $isDryRun) {
        printf("  --confirm-push missing or --dry-run: the roles are not created.%s", PHP_EOL);
        return;
    }

    $client = createRedmineClient($redmineConfig);
    $prefix = resolveExtendedApiPrefix($redmineConfig);
    verifyExtendedApiAvailability($client, $prefix, 'roles.json');
    $existing = [];
    $roleRows = $pdo->query('SELECT id, name FROM staging_redmine_roles');
    foreach ($roleRows !== false ? $roleRows->fetchAll(PDO::FETCH_ASSOC) : [] as $role) {
        $existing[mb_strtolower((string)$role['name'], 'UTF-8')] = (int)$role['id'];
    }
    $snapshot = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_roles (id, name, assignable, raw_payload, retrieved_at)
        VALUES (:id, :name, :assignable, :raw_payload, :retrieved_at)
        ON DUPLICATE KEY UPDATE name = VALUES(name), assignable = VALUES(assignable), raw_payload = VALUES(raw_payload),
            retrieved_at = VALUES(retrieved_at)
    SQL);
    if ($snapshot === false) {
        throw new RuntimeException('Failed to prepare the insert into staging_redmine_roles.');
    }
    $ids = [];
    foreach ($names as $name) {
        $id = $existing[mb_strtolower($name, 'UTF-8')] ?? null;
        if ($id === null) {
            try {
                $response = $client->post(
                    buildExtendedApiPath($prefix, 'roles.json'),
                    ['json' => WorkflowGroupRoles::rolePayload($name, $permissions)]
                );
            } catch (BadResponseException $exception) {
                printf("  [error] Workflow role \"%s\" not created: %s%s", $name, summarizeExtendedApiError($exception), PHP_EOL);
                continue;
            } catch (GuzzleException $exception) {
                printf("  [error] Workflow role \"%s\" not created: %s%s", $name, $exception->getMessage(), PHP_EOL);
                continue;
            }
            $decoded = decodeJsonResponse($response);
            $role = is_array($decoded) && is_array($decoded['role'] ?? null) ? $decoded['role'] : [];
            $id = isset($role['id']) ? (int)$role['id'] : 0;
            if ($id <= 0) {
                printf("  [error] Workflow role \"%s\": the answer has no role id.%s", $name, PHP_EOL);
                continue;
            }
            $snapshot->execute([
                'id' => $id,
                'name' => $name,
                'assignable' => 0,
                'raw_payload' => json_encode($role, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'retrieved_at' => formatCurrentUtcTimestamp('Y-m-d H:i:s'),
            ]);
            printf("  [created] Workflow role \"%s\" -> Redmine role #%d.%s", $name, $id, PHP_EOL);
        }
        $ids[$name] = $id;
    }

    $update = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_project_role_groups
        SET redmine_role_id = :role_id, proposed_redmine_role_id = :role_id, proposed_redmine_role_name = :role_name,
            migration_status = 'READY_FOR_ASSIGNMENT', notes = :notes, automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);
    if ($update === false) {
        throw new RuntimeException('Failed to prepare the update of the workflow role memberships.');
    }
    foreach ($awaiting as $row) {
        $name = (string)$row['jira_role_name'];
        if (!isset($ids[$name])) {
            continue;
        }
        $update->execute([
            'role_id' => $ids[$name],
            'role_name' => $name,
            'notes' => WorkflowGroupRoles::NOTE,
            'automation_hash' => computeProjectRoleAssignmentAutomationHash(
                (int)$row['redmine_project_id'],
                (int)$row['redmine_group_id'],
                $ids[$name],
                $ids[$name],
                $name,
                'READY_FOR_ASSIGNMENT',
                WorkflowGroupRoles::NOTE
            ),
            'mapping_id' => $row['mapping_id'],
        ]);
    }
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_project_id: string,
 *     jira_project_key: ?string,
 *     jira_project_name: ?string,
 *     jira_role_id: int,
 *     jira_role_name: ?string,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     principal_type: string,
 *     redmine_project_id: int,
 *     redmine_group_id: int,
 *     redmine_group_name: string,
 *     redmine_role_id: int,
 *     redmine_role_name: string,
 *     notes: ?string
 * }>
 */
function fetchPendingRoleAssignments(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mmprg.mapping_id,
    mmprg.jira_project_id,
    mmprg.jira_project_key,
    mmprg.jira_project_name,
    mmprg.jira_role_id,
    mmprg.jira_role_name,
    mmprg.jira_group_id,
    mmprg.jira_group_name,
    mmprg.principal_type,
    mmprg.redmine_project_id,
    mmprg.redmine_group_id,
    COALESCE(srg.name, mmprg.jira_group_name) AS redmine_group_name,
    mmprg.redmine_role_id,
    srr.name AS redmine_role_name,
    mmprg.notes
FROM migration_mapping_project_role_groups AS mmprg
LEFT JOIN staging_redmine_groups AS srg ON mmprg.principal_type = 'group' AND srg.id = mmprg.redmine_group_id
LEFT JOIN staging_redmine_roles AS srr ON srr.id = mmprg.redmine_role_id
WHERE mmprg.migration_status = 'READY_FOR_ASSIGNMENT'
ORDER BY mmprg.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch pending role assignments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $assignments = [];
    foreach ($rows as $row) {
        if (!isset($row['redmine_project_id'], $row['redmine_group_id'], $row['redmine_role_id'], $row['redmine_role_name'])) {
            continue;
        }

        $assignments[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_project_id' => (string)$row['jira_project_id'],
            'jira_project_key' => $row['jira_project_key'] !== null ? (string)$row['jira_project_key'] : null,
            'jira_project_name' => $row['jira_project_name'] !== null ? (string)$row['jira_project_name'] : null,
            'jira_role_id' => (int)$row['jira_role_id'],
            'jira_role_name' => $row['jira_role_name'] !== null ? (string)$row['jira_role_name'] : null,
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'principal_type' => (string)$row['principal_type'],
            'redmine_project_id' => (int)$row['redmine_project_id'],
            'redmine_group_id' => (int)$row['redmine_group_id'],
            'redmine_group_name' => $row['redmine_group_name'] !== null ? (string)$row['redmine_group_name'] : 'Unknown group',
            'redmine_role_id' => (int)$row['redmine_role_id'],
            'redmine_role_name' => (string)$row['redmine_role_name'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
        ];
    }

    return $assignments;
}

/**
 * @param int|null $redmineRoleId
 * @param int|null $proposedRoleId
 * @param string|null $proposedRoleName
 * @param string $status
 * @param string|null $notes
 * @return string
 */
function computeRoleDefinitionAutomationHash(?int $redmineRoleId, ?int $proposedRoleId, ?string $proposedRoleName, string $status, ?string $notes): string
{
    return hash('sha256', implode('|', [
        $redmineRoleId ?? 'null',
        $proposedRoleId ?? 'null',
        $proposedRoleName ?? 'null',
        $status,
        $notes ?? 'null',
    ]));
}

/**
 * @param array<int, array<int, array<int, bool>>> $assignments
 * @param int $groupId
 * @param int $projectId
 * @param int $roleId
 * @return bool
 */
function redmineProjectRoleAssignmentExists(array $assignments, int $groupId, int $projectId, int $roleId): bool
{
    return isset($assignments[$groupId][$projectId][$roleId]);
}

/**
 * @param int|null $projectId
 * @param int|null $groupId
 * @param int|null $roleId
 * @param int|null $proposedRoleId
 * @param string|null $proposedRoleName
 * @param string $status
 * @param string|null $notes
 * @return string
 */
function computeProjectRoleAssignmentAutomationHash(?int $projectId, ?int $groupId, ?int $roleId, ?int $proposedRoleId, ?string $proposedRoleName, string $status, ?string $notes): string
{
    return AssignmentHash::compute($projectId, $groupId, $roleId, $proposedRoleId, $proposedRoleName, $status, $notes);
}

/**
 * @param mixed $value
 * @return string|null
 */
function normalizeStoredAutomationHash(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    $candidate = is_string($value) ? trim($value) : trim((string)$value);
    if ($candidate === '') {
        return null;
    }

    if (!preg_match('/^[0-9a-f]{64}$/i', $candidate)) {
        return null;
    }

    return strtolower($candidate);
}

function shouldUseExtendedApi(array $redmineConfig, bool $cliFlag): bool
{
    if ($cliFlag) {
        return true;
    }

    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    return (bool)($extendedConfig['enabled'] ?? false);
}

function resolveExtendedApiPrefix(array $redmineConfig): string
{
    $extendedConfig = isset($redmineConfig['extended_api']) && is_array($redmineConfig['extended_api'])
        ? $redmineConfig['extended_api']
        : [];

    $prefix = isset($extendedConfig['prefix']) ? trim((string)$extendedConfig['prefix']) : '/extended_api';

    if ($prefix === '') {
        $prefix = '/extended_api';
    }

    return trim($prefix, '/');
}

function buildExtendedApiPath(string $prefix, string $resource): string
{
    $normalizedPrefix = trim($prefix, '/');
    $normalizedResource = ltrim($resource, '/');

    if ($normalizedPrefix === '') {
        return $normalizedResource;
    }

    return $normalizedPrefix . '/' . $normalizedResource;
}

function verifyExtendedApiAvailability(Client $client, string $prefix, string $resource): void
{
    $path = buildExtendedApiPath($prefix, $resource);

    try {
        $response = $client->get($path);
    } catch (BadResponseException $exception) {
        $response = $exception->getResponse();
        $statusCode = $response ? $response->getStatusCode() : 0;
        $reason = $response ? $response->getReasonPhrase() : 'unknown error';
        throw new RuntimeException(
            sprintf('Extended API availability check failed (%s): HTTP %d %s', $path, $statusCode, $reason),
            0,
            $exception
        );
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Failed to reach the extended API: ' . $exception->getMessage(), 0, $exception);
    }

    $header = $response->getHeaderLine('X-Redmine-Extended-API');
    if (trim($header) === '') {
        throw new RuntimeException('Extended API response missing X-Redmine-Extended-API header. Verify the plugin installation.');
    }
}

function summarizeExtendedApiError(Throwable $exception): string
{
    if ($exception instanceof BadResponseException) {
        $response = $exception->getResponse();
        if ($response !== null) {
            $message = sprintf('HTTP %d %s', $response->getStatusCode(), $response->getReasonPhrase());
            $details = extractExtendedApiErrorDetails($response);
            if ($details !== null) {
                $message .= ': ' . $details;
            }

            return $message;
        }
    }

    return $exception->getMessage();
}

function extractExtendedApiErrorDetails(ResponseInterface $response): ?string
{
    $body = trim((string)$response->getBody());
    if ($body === '') {
        return null;
    }

    try {
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        return $body !== '' ? $body : null;
    }

    if (is_array($decoded)) {
        if (isset($decoded['errors']) && is_array($decoded['errors'])) {
            return implode('; ', array_map('strval', $decoded['errors']));
        }

        if (isset($decoded['error']) && is_string($decoded['error'])) {
            return $decoded['error'];
        }
    }

    return $body !== '' ? $body : null;
}

/**
 * The project's memberships per principal id, with their direct roles (B31).
 *
 * @return array<int, array{id: int, role_ids: list<int>}>
 */
function fetchRedmineProjectMemberships(Client $client, int $projectId): array
{
    $memberships = [];
    $offset = 0;
    do {
        $page = decodeJsonResponse($client->get(
            sprintf('projects/%d/memberships.json', $projectId),
            ['query' => ['limit' => 100, 'offset' => $offset]]
        ));
        if (!is_array($page)) {
            throw new RuntimeException(sprintf('Unexpected memberships response for Redmine project #%d.', $projectId));
        }
        $memberships = MembershipPlan::existingFromApi($page) + $memberships;
        $count = is_array($page['memberships'] ?? null) ? count($page['memberships']) : 0;
        $offset += $count;
    } while ($count > 0 && $offset < (int)($page['total_count'] ?? 0));

    return $memberships;
}

function extractCreatedMembershipId(mixed $decoded): string
{
    if (is_array($decoded)) {
        if (isset($decoded['membership']) && is_array($decoded['membership']) && isset($decoded['membership']['id'])) {
            return (string)$decoded['membership']['id'];
        }

        if (isset($decoded['id'])) {
            return (string)$decoded['id'];
        }
    }

    return 'unknown';
}

/**
 * @param string|null $projectKey
 * @param string|null $projectName
 * @param string $projectId
 * @return string
 */
function formatJiraProjectReference(?string $projectKey, ?string $projectName, string $projectId): string
{
    $parts = [];
    if ($projectKey !== null) {
        $parts[] = $projectKey;
    }
    if ($projectName !== null && $projectName !== $projectKey) {
        $parts[] = $projectName;
    }
    $parts[] = sprintf('#%s', $projectId);

    return implode(' / ', $parts);
}

/**
 * @param array<string, mixed> $argv
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool, use_extended_api: bool}, 1: array<int, string>}
 */
function parseCommandLineOptions(array $argv): array
{
    $options = [
        'help' => false,
        'version' => false,
        'phases' => null,
        'skip' => null,
        'confirm_push' => false,
        'dry_run' => false,
        'use_extended_api' => false,
    ];

    $positionals = [];
    $argCount = count($argv);

    for ($index = 1; $index < $argCount; $index++) {
        $argument = (string)$argv[$index];
        if ($argument === '--') {
            for ($j = $index + 1; $j < $argCount; $j++) {
                $positionals[] = (string)$argv[$j];
            }
            break;
        }

        if (str_starts_with($argument, '--')) {
            if ($argument === '--help') {
                $options['help'] = true;
                continue;
            }
            if ($argument === '--version') {
                $options['version'] = true;
                continue;
            }
            if ($argument === '--confirm-push') {
                $options['confirm_push'] = true;
                continue;
            }
            if ($argument === '--dry-run') {
                $options['dry_run'] = true;
                continue;
            }
            if ($argument === '--use-extended-api') {
                $options['use_extended_api'] = true;
                continue;
            }

            if (str_starts_with($argument, '--phases=')) {
                $options['phases'] = substr($argument, 9);
                continue;
            }

            if (str_starts_with($argument, '--skip=')) {
                $options['skip'] = substr($argument, 7);
                continue;
            }

            throw new RuntimeException(sprintf('Unknown option: %s', $argument));
        }

        if ($argument === '-h') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '-V') {
            $options['version'] = true;
            continue;
        }

        throw new RuntimeException(sprintf('Unknown short option: %s', $argument));
    }

    return [$options, $positionals];
}

/**
 * @return void
 */
function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_ROLES_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Mark assignments as completed after manual review." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without updating migration status." . PHP_EOL;
    echo "      --use-extended-api  Push assignments through the redmine_extended_api plugin." . PHP_EOL;
    echo PHP_EOL;
    echo "Available phases:" . PHP_EOL;
    foreach (AVAILABLE_PHASES as $phase => $description) {
        echo sprintf("  %-7s %s%s", $phase, $description, PHP_EOL);
    }
    echo PHP_EOL;
    echo "Examples:" . PHP_EOL;
    echo sprintf('  php %s --help%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=redmine%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --skip=jira%s', $scriptName, PHP_EOL);
    echo sprintf('  php %s --phases=push --dry-run%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
}

function printVersion(): void
{
    printf('%s version %s%s', basename(__FILE__), MIGRATE_ROLES_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @return array<int, string>
 */
function determinePhasesToRun(array $cliOptions): array
{
    $phases = array_keys(AVAILABLE_PHASES);

    $selected = determinePhaseList($cliOptions['phases']);
    $skipped = determinePhaseList($cliOptions['skip']);

    if ($selected !== []) {
        $phases = array_values(array_intersect($phases, $selected));
    }

    if ($skipped !== []) {
        $phases = array_values(array_diff($phases, $skipped));
    }

    if ($phases === []) {
        throw new RuntimeException('No phases selected after applying --phases and --skip filters.');
    }

    return $phases;
}

/**
 * @param ?string $list
 * @return array<int, string>
 */
function determinePhaseList(?string $list): array
{
    if ($list === null || trim($list) === '') {
        return [];
    }

    $phases = [];
    foreach (explode(',', $list) as $candidate) {
        $normalized = strtolower(trim($candidate));
        if ($normalized === '') {
            continue;
        }

        if (!array_key_exists($normalized, AVAILABLE_PHASES)) {
            throw new RuntimeException(sprintf('Unknown phase: %s', $candidate));
        }

        $phases[] = $normalized;
    }

    return array_values(array_unique($phases));
}

/**
 * @param array<string, mixed> $config
 * @param string $key
 * @return array<string, mixed>
 */
function extractArrayConfig(array $config, string $key): array
{
    if (!isset($config[$key]) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing configuration section: %s', $key));
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $databaseConfig
 * @return PDO
 */
function createDatabaseConnection(array $databaseConfig): PDO
{
    return ConnectionFactory::connect($databaseConfig, []);
}

/**
 * @param array<string, mixed> $jiraConfig
 * @return Client
 */
function createJiraClient(array $jiraConfig): Client
{
    return ClientFactory::jira($jiraConfig, 30.0);
}

/**
 * @param array<string, mixed> $redmineConfig
 * @return Client
 */
function createRedmineClient(array $redmineConfig): Client
{
    return ClientFactory::redmine($redmineConfig, 30.0);
}

/**
 * @param ResponseInterface $response
 * @return mixed
 */
function decodeJsonResponse(ResponseInterface $response): mixed
{
    $body = (string)$response->getBody();

    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Unable to decode JSON response: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @param ?string $format
 * @return string
 */
function formatCurrentTimestamp(?string $format = null): string
{
    $format ??= DateTimeInterface::ATOM;

    return date($format);
}

/**
 * @param string $format
 * @return string
 */
function formatCurrentUtcTimestamp(string $format): string
{
    return gmdate($format);
}

/**
 * @param mixed $value
 * @param int $maxLength
 * @return string|null
 */
function normalizeString(mixed $value, int $maxLength): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        return mb_substr($trimmed, 0, $maxLength);
    }

    return substr($trimmed, 0, $maxLength);
}






