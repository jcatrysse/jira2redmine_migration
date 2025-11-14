<?php /** @noinspection PhpArrayIndexImmediatelyRewrittenInspection */
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_PROJECTS_SCRIPT_VERSION = '0.0.10';
const AVAILABLE_PHASES = [
    'jira' => 'Extract projects from Jira and persist them into staging tables.',
    'redmine' => 'Refresh the Redmine project snapshot from the REST API.',
    'transform' => 'Reconcile Jira/Redmine project data to populate the mapping table.',
    'push' => 'Create projects in Redmine when they do not yet exist.',
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
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
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

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira project extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraProjects($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d project records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira project extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine project snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineProjects($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d project records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine project snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf(
            "[%s] Starting project reconciliation & transform phase...%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );

        $transformSummary = runProjectTransformationPhase($pdo);

        printf(
            "[%s] Completed transform phase. Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
            formatCurrentTimestamp(),
            $transformSummary['matched'],
            $transformSummary['ready_for_creation'],
            $transformSummary['manual_review'],
            $transformSummary['manual_overrides'],
            $transformSummary['skipped'],
            $transformSummary['unchanged'],
            PHP_EOL
        );

        if ($transformSummary['status_counts'] !== []) {
            printf("  Current project migration status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-28s %d%s", $status, $count, PHP_EOL);
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

        runProjectPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}

/**
 * @throws Throwable
 */
function fetchAndStoreJiraProjects(Client $client, PDO $pdo): int
{
    $maxResults = 50;
    $startAt = 0;
    $totalInserted = 0;

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_projects (id, project_key, name, description, is_private, lead_account_id, raw_payload, extracted_at)
        VALUES (:id, :project_key, :name, :description, :is_private, :lead_account_id, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            project_key = VALUES(project_key),
            name = VALUES(name),
            description = VALUES(description),
            is_private = VALUES(is_private),
            lead_account_id = VALUES(lead_account_id),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_projects.');
    }

    while (true) {
        try {
            $response = $client->get('/rest/api/3/project/search', [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                    'expand' => 'lead,description',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch projects from Jira: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Jira response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['values']) || !is_array($decoded['values'])) {
            throw new RuntimeException('Unexpected response from Jira when fetching projects.');
        }

        $projects = $decoded['values'];
        $batchCount = count($projects);

        if ($batchCount === 0) {
            break;
        }

        $batchInserted = 0;
        $pdo->beginTransaction();

        try {
            $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

            foreach ($projects as $project) {
                if (!is_array($project)) {
                    continue;
                }

                $projectId = isset($project['id']) ? trim((string)$project['id']) : '';
                if ($projectId === '') {
                    continue;
                }

                $projectKey = normalizeString($project['key'] ?? null, 255);
                $name = normalizeString($project['name'] ?? null, 255) ?? $projectKey ?? $projectId;
                $description = normalizeMultilineString($project['description'] ?? null);
                $isPrivate = null;
                if (array_key_exists('isPrivate', $project)) {
                    $isPrivate = normalizeBooleanDatabaseValue($project['isPrivate']);
                }

                $leadAccountId = null;
                if (isset($project['lead']['accountId']) && is_array($project['lead'])) {
                    $leadAccountId = trim((string)$project['lead']['accountId']);
                    if ($leadAccountId === '') {
                        $leadAccountId = null;
                    }
                }

                try {
                    $rawPayload = json_encode($project, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Jira project payload: ' . $exception->getMessage(), 0, $exception);
                }

                $insertStatement->execute([
                    'id' => $projectId,
                    'project_key' => $projectKey,
                    'name' => $name,
                    'description' => $description,
                    'is_private' => $isPrivate,
                    'lead_account_id' => $leadAccountId,
                    'raw_payload' => $rawPayload,
                    'extracted_at' => $extractedAt,
                ]);

                $batchInserted++;
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $totalInserted += $batchInserted;
        printf("  Processed %d Jira projects (total inserted: %d).%s", $batchInserted, $totalInserted, PHP_EOL);

        $responseStartAt = isset($decoded['startAt']) && is_numeric($decoded['startAt']) ? (int)$decoded['startAt'] : $startAt;
        $startAt = $responseStartAt + $batchCount;

        $totalValue = isset($decoded['total']) && is_numeric($decoded['total']) ? (int)$decoded['total'] : null;
        if ($batchCount < $maxResults || ($totalValue !== null && $startAt >= $totalValue)) {
            break;
        }
    }

    return $totalInserted;
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineProjects(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_projects');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_projects: ' . $exception->getMessage(), 0, $exception);
    }

    $limit = 100;
    $offset = 0;
    $totalInserted = 0;

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_projects (id, name, identifier, description, is_public, parent_id, raw_payload, retrieved_at)
        VALUES (:id, :name, :identifier, :description, :is_public, :parent_id, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_projects.');
    }

    while (true) {
        try {
            $response = $client->get('projects.json', [
                'query' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'include' => 'trackers',
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch projects from Redmine: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Redmine response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['projects']) || !is_array($decoded['projects'])) {
            throw new RuntimeException('Unexpected response from Redmine when fetching projects.');
        }

        $projects = $decoded['projects'];
        $batchCount = count($projects);

        if ($batchCount === 0) {
            break;
        }

        $rowsToInsert = [];
        $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

        foreach ($projects as $project) {
            if (!is_array($project)) {
                continue;
            }

            $projectId = isset($project['id']) ? (int)$project['id'] : 0;
            if ($projectId <= 0) {
                continue;
            }

            $identifier = normalizeString($project['identifier'] ?? null, 255);
            $name = normalizeString($project['name'] ?? null, 255) ?? $identifier ?? (string)$projectId;
            $description = normalizeMultilineString($project['description'] ?? null);
            $isPublic = null;
            if (array_key_exists('is_public', $project)) {
                $isPublic = normalizeBooleanDatabaseValue($project['is_public']);
            }

            $parentId = null;
            if (isset($project['parent']['id']) && is_array($project['parent'])) {
                $parentId = (int)$project['parent']['id'];
                if ($parentId <= 0) {
                    $parentId = null;
                }
            }

            try {
                $rawPayload = json_encode($project, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Failed to encode Redmine project payload for project %d: %s', $projectId, $exception->getMessage()), 0, $exception);
            }

            $rowsToInsert[] = [
                'id' => $projectId,
                'name' => $name,
                'identifier' => $identifier,
                'description' => $description,
                'is_public' => $isPublic,
                'parent_id' => $parentId,
                'raw_payload' => $rawPayload,
                'retrieved_at' => $retrievedAt,
            ];
        }

        if ($rowsToInsert !== []) {
            $pdo->beginTransaction();

            try {
                foreach ($rowsToInsert as $row) {
                    $insertStatement->execute($row);
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $totalInserted += count($rowsToInsert);
            printf("  Processed %d Redmine projects (total inserted: %d).%s", count($rowsToInsert), $totalInserted, PHP_EOL);
        } else {
            printf("  Received %d Redmine projects but none were inserted (all skipped).%s", $batchCount, PHP_EOL);
        }

        $responseOffset = isset($decoded['offset']) && is_numeric($decoded['offset']) ? (int)$decoded['offset'] : $offset;
        $responseLimit = isset($decoded['limit']) && is_numeric($decoded['limit']) ? (int)$decoded['limit'] : $limit;
        $offset = $responseOffset + $responseLimit;

        $totalCount = isset($decoded['total_count']) && is_numeric($decoded['total_count']) ? (int)$decoded['total_count'] : null;
        if ($totalCount !== null && $offset >= $totalCount) {
            break;
        }

        if ($batchCount < $limit) {
            break;
        }
    }

    return $totalInserted;
}

/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runProjectTransformationPhase(PDO $pdo): array
{
    synchronizeMigrationMappingProjects($pdo);

    $redmineLookup = buildRedmineProjectLookup($pdo);
    $mappings = fetchProjectMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_projects
        SET
            redmine_project_id = :redmine_project_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_identifier = :proposed_identifier,
            proposed_name = :proposed_name,
            proposed_description = :proposed_description,
            proposed_is_public = :proposed_is_public,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_projects during the transform phase.');
    }

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
        'status_counts' => [],
    ];

    foreach ($mappings as $row) {
        $currentStatus = (string)$row['migration_status'];
        $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $jiraProjectId = (string)$row['jira_project_id'];
        $projectKey = $row['project_key'] !== null ? (string)$row['project_key'] : null;
        $projectName = $row['project_name'] !== null ? (string)$row['project_name'] : null;
        $jiraDescription = normalizeMultilineString($row['project_description'] ?? null);
        $jiraIsPrivate = normalizeBooleanFlag($row['project_is_private'] ?? null);
        $defaultIsPublic = !$jiraIsPrivate;

        $currentRedmineId = $row['redmine_project_id'] !== null ? (int)$row['redmine_project_id'] : null;
        $currentNotes = $row['notes'] !== null ? (string)$row['notes'] : null;
        $currentIdentifier = $row['proposed_identifier'] !== null ? (string)$row['proposed_identifier'] : null;
        $currentName = $row['proposed_name'] !== null ? (string)$row['proposed_name'] : null;
        $currentDescription = $row['proposed_description'] !== null ? (string)$row['proposed_description'] : null;
        $currentIsPublic = normalizeBooleanFlag($row['proposed_is_public'] ?? null);

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        $currentAutomationHash = computeProjectAutomationStateHash(
            $currentRedmineId,
            $currentStatus,
            $currentIdentifier,
            $currentName,
            $currentDescription,
            $currentIsPublic,
            $currentNotes
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            $summary['manual_overrides']++;
            printf(
                "  [preserved] Jira project %s has manual overrides; skipping automated changes.%s",
                $projectKey ?? $jiraProjectId,
                PHP_EOL
            );
            continue;
        }

        $defaultName = normalizeString($projectName, 255);
        if ($defaultName === null && $projectKey !== null) {
            $defaultName = normalizeString($projectKey, 255);
        }
        if ($defaultName === null) {
            $defaultName = normalizeString($jiraProjectId, 255);
        }

        $manualReason = null;
        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;
        $proposedIdentifier = null;
        $proposedName = $defaultName;
        $proposedDescription = $jiraDescription;
        $proposedIsPublic = $defaultIsPublic;

        if ($projectKey === null || $projectKey === '') {
            $manualReason = 'Missing Jira project key in the staging snapshot. Re-run extraction or update manually.';
        } else {
            $normalizedIdentifier = sanitizeRedmineIdentifier($projectKey);
            if ($normalizedIdentifier === null && $projectName !== null) {
                $normalizedIdentifier = sanitizeRedmineIdentifier($projectName);
            }

            if ($normalizedIdentifier === null) {
                $manualReason = sprintf('Unable to derive a valid Redmine identifier from Jira key "%s".', $projectKey);
            } else {
                $matchedProject = $redmineLookup[$normalizedIdentifier] ?? null;

                if ($matchedProject !== null) {
                    $newStatus = 'MATCH_FOUND';
                    $newRedmineId = (int)$matchedProject['id'];
                    $proposedIdentifier = normalizeString($matchedProject['identifier'] ?? null, 255) ?? $normalizedIdentifier;
                    $proposedName = normalizeString($matchedProject['name'] ?? null, 255) ?? $proposedIdentifier;
                    $proposedDescription = normalizeMultilineString($matchedProject['description'] ?? null) ?? $proposedDescription;
                    $matchedIsPublic = normalizeBooleanFlag($matchedProject['is_public'] ?? null);
                    if ($matchedIsPublic !== null) {
                        $proposedIsPublic = $matchedIsPublic;
                    }
                } else {
                    $newStatus = 'READY_FOR_CREATION';
                    $newRedmineId = null;
                    $proposedIdentifier = $normalizedIdentifier;
                    $proposedName = $proposedName ?? $normalizedIdentifier;
                }
            }
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $newRedmineId = null;
            if ($proposedIdentifier === null && $projectKey !== null) {
                $proposedIdentifier = sanitizeRedmineIdentifier($projectKey);
            }
            if ($proposedIdentifier === null && $projectName !== null) {
                $proposedIdentifier = sanitizeRedmineIdentifier($projectName);
            }
            if ($proposedName === null) {
                $proposedName = $defaultName ?? $proposedIdentifier ?? $jiraProjectId;
            }
            $notes = $manualReason;

            printf(
                "  [manual] Jira project %s: %s%s",
                $projectKey ?? $jiraProjectId,
                $manualReason,
                PHP_EOL
            );
        } else {
            $notes = null;
        }

        if ($proposedIdentifier === null && $projectKey !== null) {
            $proposedIdentifier = sanitizeRedmineIdentifier($projectKey);
        }
        if ($proposedIdentifier === null && $projectName !== null) {
            $proposedIdentifier = sanitizeRedmineIdentifier($projectName);
        }
        if ($proposedName === null) {
            $proposedName = $defaultName ?? $proposedIdentifier ?? $jiraProjectId;
        }

        $newAutomationHash = computeProjectAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $proposedIdentifier,
            $proposedName,
            $proposedDescription,
            $proposedIsPublic,
            $notes
        );

        $needsUpdate = $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentIdentifier !== $proposedIdentifier
            || $currentName !== $proposedName
            || $currentDescription !== $proposedDescription
            || $currentIsPublic !== $proposedIsPublic
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash;

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $proposedIsPublicValue = normalizeBooleanDatabaseValue($proposedIsPublic);
        $updateStatement->execute([
            'redmine_project_id' => $newRedmineId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'proposed_identifier' => $proposedIdentifier,
            'proposed_name' => $proposedName,
            'proposed_description' => $proposedDescription,
            'proposed_is_public' => $proposedIsPublicValue,
            'automation_hash' => $newAutomationHash,
            'mapping_id' => $row['mapping_id'],
        ]);

        if ($newStatus === 'MATCH_FOUND' && $newStatus !== $currentStatus) {
            $summary['matched']++;
        } elseif ($newStatus === 'READY_FOR_CREATION' && $newStatus !== $currentStatus) {
            $summary['ready_for_creation']++;
        } elseif ($newStatus === 'MANUAL_INTERVENTION_REQUIRED' && $newStatus !== $currentStatus) {
            $summary['manual_review']++;
        }
    }

    $summary['status_counts'] = fetchProjectStatusBreakdown($pdo);

    return $summary;
}

function synchronizeMigrationMappingProjects(PDO $pdo): void
{
    $sql = <<<SQL
INSERT INTO migration_mapping_projects (jira_project_id)
SELECT jp.id
FROM staging_jira_projects AS jp
ON DUPLICATE KEY UPDATE last_updated_at = last_updated_at
SQL;

    try {
        $pdo->exec($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to synchronise migration_mapping_projects: ' . $exception->getMessage(), 0, $exception);
    }
}

/**
 * @return array<string, array{id: int, identifier: string, name: ?string, description: ?string, is_public: ?bool}>
 */
function buildRedmineProjectLookup(PDO $pdo): array
{
    $sql = <<<SQL
SELECT id, identifier, name, description, is_public
FROM staging_redmine_projects
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load Redmine project lookup: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $lookup = [];
    foreach ($statement as $row) {
        $identifier = isset($row['identifier']) ? strtolower((string)$row['identifier']) : null;
        if ($identifier === null || $identifier === '') {
            continue;
        }

        $lookup[$identifier] = [
            'id' => (int)$row['id'],
            'identifier' => (string)$row['identifier'],
            'name' => isset($row['name']) ? (string)$row['name'] : null,
            'description' => normalizeMultilineString($row['description'] ?? null),
            'is_public' => normalizeBooleanFlag($row['is_public'] ?? null),
        ];
    }

    return $lookup;
}

/**
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_project_id: string,
 *     migration_status: string,
 *     redmine_project_id: ?int,
 *     notes: ?string,
 *     proposed_identifier: ?string,
 *     proposed_name: ?string,
 *     proposed_description: ?string,
 *     proposed_is_public: ?int,
 *     automation_hash: ?string,
 *     project_key: ?string,
 *     project_name: ?string,
 *     project_description: ?string,
 *     project_is_private: ?int
 * }>
 */
function fetchProjectMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mp.mapping_id,
    mp.jira_project_id,
    mp.migration_status,
    mp.redmine_project_id,
    mp.notes,
    mp.proposed_identifier,
    mp.proposed_name,
    mp.proposed_description,
    mp.proposed_is_public,
    mp.automation_hash,
    sj.project_key,
    sj.name AS project_name,
    sj.description AS project_description,
    sj.is_private AS project_is_private
FROM migration_mapping_projects AS mp
LEFT JOIN staging_jira_projects AS sj ON sj.id = mp.jira_project_id
ORDER BY mp.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load project mappings for transform: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, int>
 */
function fetchProjectStatusBreakdown(PDO $pdo): array
{
    $sql = <<<SQL
SELECT migration_status, COUNT(*) AS total
FROM migration_mapping_projects
GROUP BY migration_status
ORDER BY migration_status
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute project migration status breakdown: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    $results = [];
    foreach ($statement as $row) {
        $status = isset($row['migration_status']) ? (string)$row['migration_status'] : null;
        $count = isset($row['total']) ? (int)$row['total'] : 0;

        if ($status !== null) {
            $results[$status] = $count;
        }
    }

    return $results;
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @param bool $confirmPush
 * @param bool $isDryRun
 */
function runProjectPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
{
    printf("[%s] Starting push phase (load)...%s", formatCurrentTimestamp(), PHP_EOL);

    $pendingCreations = fetchPendingProjectPushOperations($pdo);
    $queueSize = count($pendingCreations);

    if ($queueSize === 0) {
        printf("  No project creations are pending.%s", PHP_EOL);

        if ($isDryRun) {
            printf("  --dry-run flag enabled: Redmine will not be modified.%s", PHP_EOL);
        }

        if ($confirmPush) {
            printf("  No pending operations: Redmine API was not contacted.%s", PHP_EOL);
        } else {
            printf("  --confirm-push flag missing: no Redmine API calls were attempted.%s", PHP_EOL);
        }

        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    printf("  %d project(s) are marked as READY_FOR_CREATION in migration_mapping_projects.%s", $queueSize, PHP_EOL);

    if ($isDryRun) {
        printf("  Dry-run preview of queued Redmine project creations:%s", PHP_EOL);
        outputProjectPushPreview($pendingCreations);
        printf("  --dry-run flag enabled: Redmine will not be modified.%s", PHP_EOL);
        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    if (!$confirmPush) {
        printf("  Re-run with --dry-run to preview the exact payloads before enabling the push.%s", PHP_EOL);
        printf("  --confirm-push flag missing: no Redmine API calls were attempted.%s", PHP_EOL);
        printf("[%s] Push phase finished without contacting Redmine.%s", formatCurrentTimestamp(), PHP_EOL);

        return;
    }

    $redmineClient = createRedmineClient(extractArrayConfig($config, 'redmine'));

    printf("  Push confirmation supplied; creating Redmine projects...%s", PHP_EOL);
    $result = executeRedmineProjectPush($pdo, $redmineClient, $pendingCreations);
    printf("  Project creation summary: %d succeeded, %d failed.%s", $result[0], $result[1], PHP_EOL);

    printf("[%s] Push phase finished with Redmine API interactions.%s", formatCurrentTimestamp(), PHP_EOL);
}

/**
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_project_id: string,
 *     project_key: ?string,
 *     project_name: ?string,
 *     jira_description: ?string,
 *     jira_is_private: ?int,
 *     proposed_identifier: ?string,
 *     proposed_name: ?string,
 *     proposed_description: ?string,
 *     proposed_is_public: ?int,
 *     automation_hash: ?string
 * }>
 */
function fetchPendingProjectPushOperations(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mp.mapping_id,
    mp.jira_project_id,
    mp.proposed_identifier,
    mp.proposed_name,
    mp.proposed_description,
    mp.proposed_is_public,
    mp.automation_hash,
    sj.project_key,
    sj.name AS project_name,
    sj.description AS jira_description,
    sj.is_private AS jira_is_private
FROM migration_mapping_projects AS mp
INNER JOIN staging_jira_projects AS sj ON sj.id = mp.jira_project_id
WHERE mp.migration_status = 'READY_FOR_CREATION'
ORDER BY mp.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to inspect pending project push operations: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param array<int, array{mapping_id: int, jira_project_id: string, project_key: ?string, project_name: ?string, jira_description: ?string, jira_is_private: ?int, proposed_identifier: ?string, proposed_name: ?string, proposed_description: ?string, proposed_is_public: ?int, automation_hash: ?string}> $operations
 */
function outputProjectPushPreview(array $operations): void
{
    foreach ($operations as $operation) {
        try {
            $payload = buildProjectCreationPayload($operation);
        } catch (RuntimeException $exception) {
            printf(
                "    [invalid] Jira project %s: %s%s",
                $operation['project_key'] ?? $operation['jira_project_id'],
                $exception->getMessage(),
                PHP_EOL
            );
            continue;
        }

        printf(
            "    [queued] Jira project %s => Redmine identifier '%s' (name: %s, public: %s)%s",
            $operation['project_key'] ?? $operation['jira_project_id'],
            $payload['project']['identifier'],
            $payload['project']['name'],
            $payload['project']['is_public'] ? 'yes' : 'no',
            PHP_EOL
        );
    }
}

/**
 * @param array{mapping_id: int, jira_project_id: string, project_key: ?string, project_name: ?string, jira_description: ?string, jira_is_private: ?int, proposed_identifier: ?string, proposed_name: ?string, proposed_description: ?string, proposed_is_public: ?int, automation_hash: ?string} $operation
 * @return array{project: array{name: string, identifier: string, description: ?string, is_public: bool}}
 */
function buildProjectCreationPayload(array $operation): array
{
    $projectKey = $operation['project_key'] !== null ? (string)$operation['project_key'] : null;
    $projectName = $operation['project_name'] !== null ? (string)$operation['project_name'] : null;
    $jiraDescription = normalizeMultilineString($operation['jira_description'] ?? null);
    $jiraIsPrivate = normalizeBooleanFlag($operation['jira_is_private'] ?? null);
    $fallbackIsPublic = !$jiraIsPrivate;

    $identifier = $operation['proposed_identifier'] !== null
        ? sanitizeRedmineIdentifier((string)$operation['proposed_identifier'])
        : null;
    if ($identifier === null) {
        $identifier = sanitizeRedmineIdentifier($projectKey);
    }
    if ($identifier === null && $projectName !== null) {
        $identifier = sanitizeRedmineIdentifier($projectName);
    }
    if ($identifier === null) {
        $identifier = sanitizeRedmineIdentifier($operation['jira_project_id']);
    }

    if ($identifier === null) {
        throw new RuntimeException(sprintf('Unable to derive a Redmine identifier for Jira project %s.', $operation['jira_project_id']));
    }

    $name = normalizeString($operation['proposed_name'] ?? null, 255);
    if ($name === null) {
        $name = normalizeString($projectName, 255);
    }
    if ($name === null && $projectKey !== null) {
        $name = normalizeString($projectKey, 255);
    }
    if ($name === null) {
        $name = $identifier;
    }

    $description = normalizeMultilineString($operation['proposed_description'] ?? null);
    if ($description === null) {
        $description = $jiraDescription;
    }

    $isPublic = normalizeBooleanFlag($operation['proposed_is_public'] ?? null);
    if ($isPublic === null) {
        $isPublic = $fallbackIsPublic;
    }

    return [
        'project' => [
            'name' => $name,
            'identifier' => $identifier,
            'description' => $description,
            'is_public' => $isPublic,
        ],
    ];
}

/**
 * @param array{mapping_id: int, jira_project_id: string, project_key: ?string, project_name: ?string, jira_description: ?string, jira_is_private: ?int, proposed_identifier: ?string, proposed_name: ?string, proposed_description: ?string, proposed_is_public: ?int, automation_hash: ?string} $operation
 * @param array{project: array{name: string, identifier: string, description: ?string, is_public: bool}}|null $payload
 * @param string $migrationStatus
 * @param int|null $redmineProjectId
 * @param string|null $notes
 * @return array<string, mixed>
 */
function buildProjectPushUpdateValues(
    array $operation,
    ?array $payload,
    string $migrationStatus,
    ?int $redmineProjectId,
    ?string $notes
): array {
    $projectData = $payload['project'] ?? null;

    $identifier = $projectData['identifier'] ?? ($operation['proposed_identifier'] ?? null);
    $identifier = $identifier !== null ? sanitizeRedmineIdentifier((string)$identifier) : null;

    $name = $projectData['name'] ?? ($operation['proposed_name'] ?? null);
    $name = $name !== null ? normalizeString((string)$name, 255) : null;

    $description = $projectData['description'] ?? ($operation['proposed_description'] ?? null);
    $description = normalizeMultilineString($description);

    $isPublic = $projectData['is_public'] ?? normalizeBooleanFlag($operation['proposed_is_public'] ?? null);
    if ($isPublic === null) {
        $jiraIsPrivate = normalizeBooleanFlag($operation['jira_is_private'] ?? null);
        $isPublic = !$jiraIsPrivate;
    }

    $automationHash = computeProjectAutomationStateHash(
        $redmineProjectId,
        $migrationStatus,
        $identifier,
        $name,
        $description,
        $isPublic,
        $notes
    );

    return [
        'mapping_id' => $operation['mapping_id'],
        'redmine_project_id' => $redmineProjectId,
        'migration_status' => $migrationStatus,
        'notes' => $notes,
        'proposed_identifier' => $identifier,
        'proposed_name' => $name,
        'proposed_description' => $description,
        'proposed_is_public' => normalizeBooleanDatabaseValue($isPublic),
        'automation_hash' => $automationHash,
    ];
}

/**
 * @param PDO $pdo
 * @param Client $redmineClient
 * @param array<int, array{mapping_id: int, jira_project_id: string, project_key: ?string, project_name: ?string, jira_description: ?string, jira_is_private: ?int, proposed_identifier: ?string, proposed_name: ?string, proposed_description: ?string, proposed_is_public: ?int, automation_hash: ?string}> $operations
 * @return array{0: int, 1: int}
 */
function executeRedmineProjectPush(PDO $pdo, Client $redmineClient, array $operations): array
{
    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_projects
        SET
            redmine_project_id = :redmine_project_id,
            migration_status = :migration_status,
            notes = :notes,
            proposed_identifier = :proposed_identifier,
            proposed_name = :proposed_name,
            proposed_description = :proposed_description,
            proposed_is_public = :proposed_is_public,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_projects during the push phase.');
    }

    $successCount = 0;
    $failureCount = 0;

    foreach ($operations as $operation) {
        $payload = null;

        try {
            $payload = buildProjectCreationPayload($operation);
        } catch (RuntimeException $exception) {
            $updateValues = buildProjectPushUpdateValues(
                $operation,
                null,
                'MANUAL_INTERVENTION_REQUIRED',
                null,
                $exception->getMessage()
            );
            $updateStatement->execute($updateValues);

            printf(
                "    [skipped] Jira project %s: %s%s",
                $operation['project_key'] ?? $operation['jira_project_id'],
                $exception->getMessage(),
                PHP_EOL
            );

            $failureCount++;
            continue;
        }

        try {
            $response = $redmineClient->post('projects.json', [
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode !== 201) {
                throw new RuntimeException(sprintf('Unexpected HTTP status %d when creating a Redmine project.', $statusCode));
            }

            try {
                $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to decode Redmine project creation payload: ' . $exception->getMessage(), 0, $exception);
            }

            if (!is_array($decoded) || !isset($decoded['project']) || !is_array($decoded['project']) || !isset($decoded['project']['id'])) {
                throw new RuntimeException('Unexpected response structure from Redmine when creating a project.');
            }

            $redmineProjectId = (int)$decoded['project']['id'];

            $updateValues = buildProjectPushUpdateValues(
                $operation,
                $payload,
                'CREATION_SUCCESS',
                $redmineProjectId,
                null
            );
            $updateStatement->execute($updateValues);

            printf(
                "    [created] Jira project %s => Redmine project #%d (%s)%s",
                $operation['project_key'] ?? $operation['jira_project_id'],
                $redmineProjectId,
                $payload['project']['identifier'],
                PHP_EOL
            );

            $successCount++;
        } catch (BadResponseException $exception) {
            $message = extractRedmineErrorMessage($exception->getResponse(), $exception->getMessage());

            $updateValues = buildProjectPushUpdateValues(
                $operation,
                $payload,
                'CREATION_FAILED',
                null,
                $message
            );
            $updateStatement->execute($updateValues);

            printf(
                "    [failed] Jira project %s: %s%s",
                $operation['project_key'] ?? $operation['jira_project_id'],
                $message,
                PHP_EOL
            );

            $failureCount++;
        } catch (Throwable $exception) {
            $updateValues = buildProjectPushUpdateValues(
                $operation,
                $payload,
                'CREATION_FAILED',
                null,
                $exception->getMessage()
            );
            $updateStatement->execute($updateValues);

            printf(
                "    [failed] Jira project %s: %s%s",
                $operation['project_key'] ?? $operation['jira_project_id'],
                $exception->getMessage(),
                PHP_EOL
            );

            $failureCount++;
        }
    }

    return [$successCount, $failureCount];
}

function sanitizeRedmineIdentifier(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $candidate = trim($value);
    if ($candidate === '') {
        return null;
    }

    if (function_exists('mb_strtolower')) {
        $candidate = mb_strtolower($candidate);
    } else {
        $candidate = strtolower($candidate);
    }

    $candidate = preg_replace('/[^a-z0-9\-_]+/', '-', $candidate) ?? '';
    $candidate = preg_replace('/[-_]{2,}/', '-', $candidate) ?? '';
    $candidate = trim($candidate, '-_');

    if ($candidate === '') {
        return null;
    }

    if (function_exists('mb_substr')) {
        $candidate = mb_substr($candidate, 0, 100);
    } else {
        $candidate = substr($candidate, 0, 100);
    }

    return $candidate;
}

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

function normalizeMultilineString(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    if (is_array($value)) {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new RuntimeException('Failed to encode multi-line value: ' . $exception->getMessage(), 0, $exception);
        }

        return $encoded !== '' ? $encoded : null;
    }

    return null;
}

function normalizeBooleanDatabaseValue(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_bool($value)) {
        return $value ? 1 : 0;
    }

    if (is_int($value) || is_float($value)) {
        $intValue = (int)$value;
        if ($intValue === 0 || $intValue === 1) {
            return $intValue;
        }
    }

    if (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === '0' || $trimmed === '1') {
            return (int)$trimmed;
        }

        $value = $trimmed;
    }

    $filtered = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($filtered === null) {
        return null;
    }

    return $filtered ? 1 : 0;
}

function computeProjectAutomationStateHash(
    ?int $redmineProjectId,
    string $migrationStatus,
    ?string $proposedIdentifier,
    ?string $proposedName,
    ?string $proposedDescription,
    ?bool $proposedIsPublic,
    ?string $notes
): string {
    try {
        $payload = json_encode(
            [
                'redmine_project_id' => $redmineProjectId,
                'migration_status' => $migrationStatus,
                'proposed_identifier' => $proposedIdentifier,
                'proposed_name' => $proposedName,
                'proposed_description' => $proposedDescription,
                'proposed_is_public' => $proposedIsPublic,
                'notes' => $notes,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode automation state hash payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', (string)$payload);
}

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

function normalizeBooleanFlag(mixed $value): ?bool
{
    $normalized = normalizeBooleanDatabaseValue($value);
    if ($normalized === null) {
        return null;
    }

    return $normalized === 1;
}

function extractRedmineErrorMessage(?ResponseInterface $response, string $fallback): string
{
    $fallback = trim($fallback);
    if ($response === null) {
        return $fallback !== '' ? $fallback : 'Unknown error when contacting Redmine.';
    }

    $statusCode = $response->getStatusCode();
    $message = null;
    $body = (string)$response->getBody();

    if ($body !== '') {
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }

        if (is_array($decoded)) {
            if (isset($decoded['errors']) && is_array($decoded['errors'])) {
                $parts = [];
                foreach ($decoded['errors'] as $error) {
                    if (!is_string($error)) {
                        continue;
                    }

                    $trimmed = trim($error);
                    if ($trimmed !== '') {
                        $parts[] = $trimmed;
                    }
                }

                if ($parts !== []) {
                    $message = implode('; ', $parts);
                }
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $candidate = trim($decoded['error']);
                if ($candidate !== '') {
                    $message = $candidate;
                }
            }
        }

        if ($message === null) {
            $stripped = trim(strip_tags($body));
            if ($stripped !== '') {
                if (function_exists('mb_substr')) {
                    $message = mb_substr($stripped, 0, 500);
                } else {
                    $message = substr($stripped, 0, 500);
                }
            }
        }
    }

    if ($message === null || $message === '') {
        $message = $fallback !== '' ? $fallback : 'Unknown error response received from Redmine.';
    }

    if (function_exists('mb_strlen')) {
        if (mb_strlen($message) > 500) {
            $message = mb_substr($message, 0, 500) . '…';
        }
    } elseif (strlen($message) > 500) {
        $message = substr($message, 0, 500) . '…';
    }

    return sprintf('HTTP %d: %s', $statusCode, $message);
}

/**
 * @param array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool} $cliOptions
 * @return array<int, string>
 */
function determinePhasesToRun(array $cliOptions): array
{
    $availablePhaseKeys = array_keys(AVAILABLE_PHASES);
    $phasesToRun = $availablePhaseKeys;

    if (array_key_exists('phases', $cliOptions) && $cliOptions['phases'] !== null) {
        if ($cliOptions['phases'] === '') {
            throw new RuntimeException('The --phases option was provided without any values.');
        }

        $phasesToRun = normalizePhaseList($cliOptions['phases'], $availablePhaseKeys);
    }

    if (array_key_exists('skip', $cliOptions) && $cliOptions['skip'] !== null) {
        if ($cliOptions['skip'] === '') {
            throw new RuntimeException('The --skip option was provided without any values.');
        }

        $phasesToSkip = normalizePhaseList($cliOptions['skip'], $availablePhaseKeys);
        $phasesToRun = array_values(array_diff($phasesToRun, $phasesToSkip));
    }

    if ($phasesToRun === []) {
        throw new RuntimeException('No phases remain to execute after applying the provided CLI options.');
    }

    return $phasesToRun;
}

/**
 * @param array<int, string> $argv
 * @return array{0: array{help: bool, version: bool, phases: ?string, skip: ?string, confirm_push: bool, dry_run: bool}, 1: array<int, string>}
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
    ];

    $positionalArguments = [];
    $argumentCount = count($argv);

    for ($index = 1; $index < $argumentCount; $index++) {
        $argument = $argv[$index];

        if ($argument === '--') {
            for ($nextIndex = $index + 1; $nextIndex < $argumentCount; $nextIndex++) {
                $positionalArguments[] = $argv[$nextIndex];
            }

            break;
        }

        if ($argument === '-h' || $argument === '--help') {
            $options['help'] = true;
            continue;
        }

        if ($argument === '-V' || $argument === '--version') {
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

        if ($argument === '--phases') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --phases option requires a value.');
            }

            $phaseValue = $argv[$index];
            if (!is_string($phaseValue)) {
                throw new RuntimeException('The --phases option expects string arguments.');
            }

            $options['phases'] = trim($phaseValue);
            continue;
        }

        if (str_starts_with($argument, '--phases=')) {
            $options['phases'] = trim(substr($argument, 9));
            continue;
        }

        if ($argument === '--skip') {
            $index++;
            if ($index >= $argumentCount) {
                throw new RuntimeException('The --skip option requires a value.');
            }

            $skipValue = $argv[$index];
            if (!is_string($skipValue)) {
                throw new RuntimeException('The --skip option expects string arguments.');
            }

            $options['skip'] = trim($skipValue);
            continue;
        }

        if (str_starts_with($argument, '--skip=')) {
            $options['skip'] = trim(substr($argument, 7));
            continue;
        }

        if ($argument !== '' && $argument[0] === '-') {
            throw new RuntimeException(sprintf('Unknown option provided: %s', $argument));
        }

        $positionalArguments[] = $argument;
    }

    return [$options, $positionalArguments];
}

/**
 * @param string $value
 * @param array<int, string> $availablePhases
 * @return array<int, string>
 */
function normalizePhaseList(string $value, array $availablePhases): array
{
    $segments = preg_split('/[\s,]+/', trim($value)) ?: [];
    $segments = array_filter($segments, static fn(string $segment): bool => $segment !== '');

    if ($segments === []) {
        throw new RuntimeException(
            sprintf('No valid phases supplied. Valid phases: %s', implode(', ', $availablePhases))
        );
    }

    $normalized = [];
    foreach ($segments as $segment) {
        $normalizedSegment = strtolower($segment);
        if (!in_array($normalizedSegment, $availablePhases, true)) {
            throw new RuntimeException(
                sprintf('Unknown phase "%s". Valid phases: %s', $segment, implode(', ', $availablePhases))
            );
        }

        if (!in_array($normalizedSegment, $normalized, true)) {
            $normalized[] = $normalizedSegment;
        }
    }

    return $normalized;
}

function printUsage(): void
{
    $scriptName = basename(__FILE__);

    echo sprintf(
        "%s (version %s)%s",
        $scriptName,
        MIGRATE_PROJECTS_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Allow the push phase to contact Redmine (required for writes)." . PHP_EOL;
    echo "      --dry-run        Preview push-phase actions without contacting Redmine." . PHP_EOL;
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
    printf('%s version %s%s', basename(__FILE__), MIGRATE_PROJECTS_SCRIPT_VERSION, PHP_EOL);
}

/**
 * @param array<string, mixed> $config
 * @return array<string, mixed>
 */
function extractArrayConfig(array $config, string $key): array
{
    if (!array_key_exists($key, $config) || !is_array($config[$key])) {
        throw new RuntimeException(sprintf('Missing or invalid configuration section: %s', $key));
    }

    return $config[$key];
}

/**
 * @param array<string, mixed> $databaseConfig
 */
function createDatabaseConnection(array $databaseConfig): PDO
{
    $dsn = trim((string)($databaseConfig['dsn'] ?? ''));
    $username = (string)($databaseConfig['username'] ?? '');
    $password = (string)($databaseConfig['password'] ?? '');

    if ($dsn === '') {
        throw new RuntimeException('Database DSN is not configured.');
    }

    $options = $databaseConfig['options'] ?? [];
    if (!is_array($options)) {
        throw new RuntimeException('Database options configuration must be an array.');
    }

    $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
    $options[PDO::ATTR_DEFAULT_FETCH_MODE] = PDO::FETCH_ASSOC;
    $options[PDO::ATTR_EMULATE_PREPARES] = false;

    try {
        $pdo = new PDO($dsn, $username, $password, $options);
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to connect to the database: ' . $exception->getMessage(), 0, $exception);
    }

    return $pdo;
}

/**
 * @param array<string, mixed> $jiraConfig
 */
function createJiraClient(array $jiraConfig): Client
{
    $baseUrl = trim((string)($jiraConfig['base_url'] ?? ''));
    $username = (string)($jiraConfig['username'] ?? '');
    $apiToken = (string)($jiraConfig['api_token'] ?? '');

    if ($baseUrl === '' || $username === '' || $apiToken === '') {
        throw new RuntimeException('Jira configuration must include base_url, username, and api_token.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'auth' => [$username, $apiToken],
        'headers' => [
            'Accept' => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

/**
 * @param array<string, mixed> $redmineConfig
 */
function createRedmineClient(array $redmineConfig): Client
{
    $baseUrl = trim((string)($redmineConfig['base_url'] ?? ''));
    $apiKey = (string)($redmineConfig['api_key'] ?? '');

    if ($baseUrl === '' || $apiKey === '') {
        throw new RuntimeException('Redmine configuration must include base_url and api_key.');
    }

    return new Client([
        'base_uri' => rtrim($baseUrl, '/') . '/',
        'headers' => [
            'X-Redmine-API-Key' => $apiKey,
            'Accept' => 'application/json',
        ],
        'timeout' => 30,
    ]);
}

function formatCurrentTimestamp(?string $format = null): string
{
    $format ??= DateTimeInterface::ATOM;

    return date($format);
}

function formatCurrentUtcTimestamp(string $format): string
{
    return gmdate($format);
}
