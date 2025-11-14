<?php
/** @noinspection DuplicatedCode */
/** @noinspection SpellCheckingInspection */

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

const MIGRATE_GROUPS_SCRIPT_VERSION = '0.0.7';
const AVAILABLE_PHASES = [
    'jira' => 'Extract groups and memberships from Jira and persist them into staging tables.',
    'redmine' => 'Refresh the Redmine groups and memberships snapshot from the REST API.',
    'transform' => 'Reconcile Jira/Redmine data to populate group and membership mappings.',
    'push' => 'Create groups and assign missing members in Redmine via the REST API.',
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

    $phaseSummary = [];
    foreach ($phasesToRun as $phaseKey) {
        $phaseSummary[] = $phaseKey;
    }

    printf(
        "[%s] Selected phases: %s%s",
        formatCurrentTimestamp(),
        implode(', ', $phaseSummary),
        PHP_EOL
    );

    $databaseConfig = extractArrayConfig($config, 'database');
    $pdo = createDatabaseConnection($databaseConfig);

    if (in_array('jira', $phasesToRun, true)) {
        $jiraConfig = extractArrayConfig($config, 'jira');
        $jiraClient = createJiraClient($jiraConfig);

        printf("[%s] Starting Jira group extraction...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalJiraProcessed = fetchAndStoreJiraGroups($jiraClient, $pdo);

        printf(
            "[%s] Completed Jira extraction. %d group records processed.%s",
            formatCurrentTimestamp(),
            $totalJiraProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Jira group extraction (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('redmine', $phasesToRun, true)) {
        $redmineConfig = extractArrayConfig($config, 'redmine');
        $redmineClient = createRedmineClient($redmineConfig);

        printf("[%s] Starting Redmine group snapshot...%s", formatCurrentTimestamp(), PHP_EOL);

        $totalRedmineProcessed = fetchAndStoreRedmineGroups($redmineClient, $pdo);

        printf(
            "[%s] Completed Redmine snapshot. %d group records processed.%s",
            formatCurrentTimestamp(),
            $totalRedmineProcessed,
            PHP_EOL
        );
    } else {
        printf(
            "[%s] Skipping Redmine group snapshot (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }

    if (in_array('transform', $phasesToRun, true)) {
        printf(
            "[%s] Starting group reconciliation & transform phase...%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );

        $transformSummary = runGroupTransformationPhase($pdo);

        printf(
            "[%s] Completed transform phase.\n  Group sync -> Matched: %d, Ready: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
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
            printf("  Current Group migration status breakdown:%s", PHP_EOL);
            foreach ($transformSummary['status_counts'] as $status => $count) {
                printf("  - %-28s %d%s", $status, $count, PHP_EOL);
            }
        }

        $memberSummary = $transformSummary['member_summary'] ?? null;
        if (is_array($memberSummary) && $memberSummary !== []) {
            printf(
                "  Member sync -> Matched: %d, Ready: %d, Awaiting group: %d, Awaiting user: %d, Manual: %d, Overrides kept: %d, Skipped: %d, Unchanged: %d.%s",
                $memberSummary['matched'] ?? 0,
                $memberSummary['ready_for_assignment'] ?? 0,
                $memberSummary['awaiting_group'] ?? 0,
                $memberSummary['awaiting_user'] ?? 0,
                $memberSummary['manual_review'] ?? 0,
                $memberSummary['manual_overrides'] ?? 0,
                $memberSummary['skipped'] ?? 0,
                $memberSummary['unchanged'] ?? 0,
                PHP_EOL
            );
        }

        $memberStatusCounts = $transformSummary['member_status_counts'] ?? [];
        if (is_array($memberStatusCounts) && $memberStatusCounts !== []) {
            printf("  Current Member migration status breakdown:%s", PHP_EOL);
            foreach ($memberStatusCounts as $status => $count) {
                printf("  - %-26s %d%s", $status, $count, PHP_EOL);
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

        runGroupPushPhase($pdo, $config, $confirmPush, $isDryRun);
    } else {
        printf(
            "[%s] Skipping push phase (disabled via CLI option).%s",
            formatCurrentTimestamp(),
            PHP_EOL
        );
    }
}

/**
 * @param PDO $pdo
 * @param array<string, mixed> $config
 * @param bool $confirmPush
 * @param bool $isDryRun
 */
function runGroupPushPhase(PDO $pdo, array $config, bool $confirmPush, bool $isDryRun): void
{
    printf("[%s] Starting push phase (load)...%s", formatCurrentTimestamp(), PHP_EOL);

    $pendingGroupCreations = fetchPendingGroupPushOperations($pdo);
    $pendingMemberAssignments = fetchPendingGroupMemberAssignments($pdo);

    $groupCount = count($pendingGroupCreations);
    $memberCount = count($pendingMemberAssignments);

    if ($groupCount === 0 && $memberCount === 0) {
        printf("  No group creations or membership assignments are pending.%s", PHP_EOL);

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

    if ($groupCount > 0) {
        printf("  %d group(s) are marked as READY_FOR_CREATION in migration_mapping_groups.%s", $groupCount, PHP_EOL);
    } else {
        printf("  No group creations are queued.%s", PHP_EOL);
    }

    if ($memberCount > 0) {
        printf("  %d membership assignment(s) are marked as READY_FOR_ASSIGNMENT in migration_mapping_group_members.%s", $memberCount, PHP_EOL);
    } else {
        printf("  No membership assignments are queued.%s", PHP_EOL);
    }

    if ($isDryRun) {
        if ($groupCount > 0) {
            printf("  Dry-run preview of queued Redmine group creations:%s", PHP_EOL);
            outputGroupPushPreview($pendingGroupCreations);
        }

        if ($memberCount > 0) {
            printf("  Dry-run preview of queued membership assignments:%s", PHP_EOL);
            outputGroupMemberPushPreview($pendingMemberAssignments);
        }

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

    if ($groupCount > 0) {
        printf("  Push confirmation supplied; creating Redmine groups...%s", PHP_EOL);
        $groupResult = executeRedmineGroupPush($pdo, $redmineClient, $pendingGroupCreations);
        printf("  Group creation summary: %d succeeded, %d failed.%s", $groupResult[0], $groupResult[1], PHP_EOL);
    }

    if ($memberCount > 0) {
        printf("  Assigning Redmine users to groups...%s", PHP_EOL);
        $memberResult = executeRedmineGroupMemberAssignments($pdo, $redmineClient, $pendingMemberAssignments);
        printf("  Membership assignment summary: %d succeeded, %d failed.%s", $memberResult[0], $memberResult[1], PHP_EOL);
    }

    printf("[%s] Push phase finished with Redmine API interactions.%s", formatCurrentTimestamp(), PHP_EOL);
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     staging_group_name: ?string,
 *     proposed_redmine_name: ?string
 * }>
 */
function fetchPendingGroupPushOperations(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mm.mapping_id,
    mm.jira_group_id,
    mm.jira_group_name,
    sj.name AS staging_group_name,
    mm.proposed_redmine_name
FROM migration_mapping_groups AS mm
LEFT JOIN staging_jira_groups AS sj ON sj.group_id = mm.jira_group_id
WHERE mm.migration_status = 'READY_FOR_CREATION'
ORDER BY mm.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to inspect pending push operations: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $operations = [];
    foreach ($rows as $row) {
        $operations[] = [
            'mapping_id' => (int)$row['mapping_id'],
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'staging_group_name' => $row['staging_group_name'] !== null ? (string)$row['staging_group_name'] : null,
            'proposed_redmine_name' => $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null,
        ];
    }

    return $operations;
}

/**
 * @param array<int, array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     staging_group_name: ?string,
 *     proposed_redmine_name: ?string
 * }> $pendingOperations
 */
function outputGroupPushPreview(array $pendingOperations): void
{
    foreach ($pendingOperations as $operation) {
        $jiraName = $operation['staging_group_name'] ?? $operation['jira_group_name'] ?? '[unknown]';
        printf(
            "    - [mapping #%d] Jira %s (%s) => Redmine name=%s%s",
            $operation['mapping_id'],
            $operation['jira_group_id'],
            $jiraName,
            formatPushPreviewField($operation['proposed_redmine_name'] ?? $jiraName),
            PHP_EOL
        );
    }
}

function formatPushPreviewField(?string $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return '"' . addcslashes($value, "\"\\") . '"';
    }

    return $encoded;
}

function formatJiraGroupReference(string $jiraGroupId, ?string $jiraGroupName): string
{
    $normalizedName = normalizeString($jiraGroupName, 255);

    if ($normalizedName !== null) {
        return sprintf('%s [%s]', $normalizedName, $jiraGroupId);
    }

    return $jiraGroupId;
}

/**
 * @param PDO $pdo
 * @param Client $redmineClient
 * @param array<int, array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     staging_group_name: ?string,
 *     proposed_redmine_name: ?string
 * }> $pendingOperations
 * @return array{0: int, 1: int}
 */
function executeRedmineGroupPush(PDO $pdo, Client $redmineClient, array $pendingOperations): array
{
    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_groups
        SET
            redmine_group_id = :redmine_group_id,
            proposed_redmine_name = :proposed_redmine_name,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_groups during the push phase.');
    }

    $successCount = 0;
    $failureCount = 0;

    foreach ($pendingOperations as $operation) {
        $preparedName = null;

        try {
            $preparedName = prepareRedmineGroupCreationName($operation);
            $newGroupId = sendRedmineGroupCreationRequest($redmineClient, $preparedName);
        } catch (Throwable $exception) {
            $errorMessage = $exception->getMessage();
            $updateValues = buildGroupPushUpdateValues(
                $operation,
                null,
                'CREATION_FAILED',
                $preparedName,
                $errorMessage
            );

            try {
                $updateStatement->execute($updateValues);
            } catch (Throwable $updateException) {
                throw new RuntimeException(
                    sprintf(
                        'Failed to record push failure for mapping %d: %s',
                        $operation['mapping_id'],
                        $updateException->getMessage()
                    ),
                    0,
                    $updateException
                );
            }

            printf(
                "    [failed] Jira group %s (mapping #%d): %s%s",
                $operation['jira_group_id'],
                $operation['mapping_id'],
                $errorMessage,
                PHP_EOL
            );

            $failureCount++;
            continue;
        }

        $updateValues = buildGroupPushUpdateValues(
            $operation,
            $newGroupId,
            'CREATION_SUCCESS',
            $preparedName,
            null
        );

        try {
            $updateStatement->execute($updateValues);
        } catch (Throwable $updateException) {
            throw new RuntimeException(
                sprintf(
                    'Failed to record push success for mapping %d: %s',
                    $operation['mapping_id'],
                    $updateException->getMessage()
                ),
                0,
                $updateException
            );
        }

        printf(
            "    [created] Jira group %s (mapping #%d) => Redmine #%d (%s)%s",
            $operation['jira_group_id'],
            $operation['mapping_id'],
            $newGroupId,
            formatPushPreviewField($preparedName),
            PHP_EOL
        );

        $successCount++;
    }

    return [$successCount, $failureCount];
}

/**
 * @param PDO $pdo
 * @return array<int, array{
 *     member_mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     jira_account_id: string,
 *     redmine_group_id: int,
 *     redmine_user_id: int,
 *     jira_display_name: ?string,
 *     jira_email_address: ?string
 * }>
 */
function fetchPendingGroupMemberAssignments(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mmgm.member_mapping_id,
    mmgm.jira_group_id,
    mmgm.jira_group_name,
    mmgm.jira_account_id,
    mmgm.redmine_group_id,
    mmgm.redmine_user_id,
    sju.display_name AS jira_display_name,
    sju.email_address AS jira_email_address
FROM migration_mapping_group_members AS mmgm
LEFT JOIN staging_jira_users AS sju
    ON sju.account_id = mmgm.jira_account_id
WHERE mmgm.migration_status = 'READY_FOR_ASSIGNMENT'
  AND mmgm.redmine_group_id IS NOT NULL
  AND mmgm.redmine_user_id IS NOT NULL
ORDER BY mmgm.member_mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to inspect pending membership assignments: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $operations = [];
    foreach ($rows as $row) {
        $operations[] = [
            'member_mapping_id' => (int)$row['member_mapping_id'],
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'jira_account_id' => (string)$row['jira_account_id'],
            'redmine_group_id' => (int)$row['redmine_group_id'],
            'redmine_user_id' => (int)$row['redmine_user_id'],
            'jira_display_name' => $row['jira_display_name'] !== null ? (string)$row['jira_display_name'] : null,
            'jira_email_address' => $row['jira_email_address'] !== null ? (string)$row['jira_email_address'] : null,
        ];
    }

    return $operations;
}

/**
 * @param array<int, array{
 *     member_mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     jira_account_id: string,
 *     redmine_group_id: int,
 *     redmine_user_id: int,
 *     jira_display_name: ?string,
 *     jira_email_address: ?string
 * }> $pendingAssignments
 */
function outputGroupMemberPushPreview(array $pendingAssignments): void
{
    foreach ($pendingAssignments as $assignment) {
        $displayName = $assignment['jira_display_name'] ?? '[unknown]';
        $email = $assignment['jira_email_address'] ?? 'n/a';
        $groupReference = formatJiraGroupReference($assignment['jira_group_id'], $assignment['jira_group_name']);

        printf(
            "    - [member #%d] Jira %s (%s, %s) => Redmine group #%d (%s) add user #%d%s",
            $assignment['member_mapping_id'],
            $assignment['jira_account_id'],
            $displayName,
            $email,
            $assignment['redmine_group_id'],
            $groupReference,
            $assignment['redmine_user_id'],
            PHP_EOL
        );
    }
}

/**
 * @param PDO $pdo
 * @param Client $redmineClient
 * @param array<int, array{
 *     member_mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     jira_account_id: string,
 *     redmine_group_id: int,
 *     redmine_user_id: int,
 *     jira_display_name: ?string,
 *     jira_email_address: ?string
 * }> $pendingAssignments
 * @return array{0: int, 1: int}
 */
function executeRedmineGroupMemberAssignments(PDO $pdo, Client $redmineClient, array $pendingAssignments): array
{
    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_group_members
        SET
            redmine_group_id = :redmine_group_id,
            redmine_user_id = :redmine_user_id,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE member_mapping_id = :member_mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_group_members during the push phase.');
    }

    $successCount = 0;
    $failureCount = 0;

    foreach ($pendingAssignments as $assignment) {
        $groupId = (int)$assignment['redmine_group_id'];
        $userId = (int)$assignment['redmine_user_id'];

        try {
            $redmineClient->post(sprintf('groups/%d/users.json', $groupId), [
                'json' => ['user_id' => $userId],
            ]);

            $status = 'ASSIGNMENT_SUCCESS';
            $notes = null;
            $resultMessage = 'assigned';
        } catch (BadResponseException $exception) {
            $response = $exception->getResponse();
            $message = extractRedmineErrorMessage($response, $exception->getMessage());

            $normalizedMessage = strtolower($message);
            $statusCode = $response->getStatusCode();
            if (($statusCode === 422) && str_contains($normalizedMessage, 'already') && str_contains($normalizedMessage, 'group')) {
                $status = 'ASSIGNMENT_SUCCESS';
                $notes = null;
                $resultMessage = 'already present';
            } else {
                $status = 'ASSIGNMENT_FAILED';
                $notes = $message;
                $resultMessage = $message;
            }
        } catch (Throwable $exception) {
            $status = 'ASSIGNMENT_FAILED';
            $notes = $exception->getMessage();
            $resultMessage = $notes;
        }

        $automationHash = computeGroupMemberAutomationStateHash($groupId, $userId, $status, $notes);

        try {
            $updateStatement->execute([
                'redmine_group_id' => $groupId,
                'redmine_user_id' => $userId,
                'migration_status' => $status,
                'notes' => $notes,
                'automation_hash' => $automationHash,
                'member_mapping_id' => $assignment['member_mapping_id'],
            ]);
        } catch (Throwable $updateException) {
            throw new RuntimeException(
                sprintf(
                    'Failed to record membership assignment outcome for mapping %d: %s',
                    $assignment['member_mapping_id'],
                    $updateException->getMessage()
                ),
                0,
                $updateException
            );
        }

        $groupReference = formatJiraGroupReference($assignment['jira_group_id'], $assignment['jira_group_name']);

        if ($status === 'ASSIGNMENT_SUCCESS') {
            printf(
                "    [assigned] Jira %s => Redmine group #%d (%s) user #%d (%s)%s",
                $assignment['jira_account_id'],
                $groupId,
                $groupReference,
                $userId,
                $resultMessage,
                PHP_EOL
            );
            $successCount++;
        } else {
            printf(
                "    [failed] Jira %s => Redmine group #%d (%s) user #%d: %s%s",
                $assignment['jira_account_id'],
                $groupId,
                $groupReference,
                $userId,
                $resultMessage,
                PHP_EOL
            );
            $failureCount++;
        }
    }

    return [$successCount, $failureCount];
}

/**
 * @param array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     staging_group_name: ?string,
 *     proposed_redmine_name: ?string
 * } $operation
 */
function prepareRedmineGroupCreationName(array $operation): string
{
    $candidate = $operation['proposed_redmine_name'] ?? $operation['staging_group_name'] ?? $operation['jira_group_name'];
    $normalized = normalizeString($candidate, 255);

    if ($normalized === null) {
        throw new RuntimeException(sprintf('Unable to determine a Redmine group name for Jira group %s.', $operation['jira_group_id']));
    }

    return $normalized;
}

function sendRedmineGroupCreationRequest(Client $client, string $groupName): int
{
    $payload = ['group' => ['name' => $groupName]];

    try {
        $response = $client->post('groups.json', ['json' => $payload]);
    } catch (BadResponseException $exception) {
        $message = extractRedmineErrorMessage($exception->getResponse(), $exception->getMessage());
        throw new RuntimeException($message, 0, $exception);
    } catch (GuzzleException $exception) {
        throw new RuntimeException('Redmine group creation request failed: ' . $exception->getMessage(), 0, $exception);
    }

    $statusCode = $response->getStatusCode();
    if ($statusCode !== 201 && $statusCode !== 200) {
        $message = extractRedmineErrorMessage(
            $response,
            sprintf('Unexpected HTTP status %d when creating a Redmine group.', $statusCode)
        );

        throw new RuntimeException($message);
    }

    try {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('Unable to decode Redmine group creation response: ' . $exception->getMessage(), 0, $exception);
    }

    if (!is_array($decoded) || !isset($decoded['group']) || !is_array($decoded['group'])) {
        throw new RuntimeException('Unexpected structure in Redmine group creation response.');
    }

    $group = $decoded['group'];
    if (!isset($group['id']) || !is_numeric($group['id'])) {
        throw new RuntimeException('Redmine group creation response did not include a group ID.');
    }

    return (int)$group['id'];
}

/**
 * @param array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     staging_group_name: ?string,
 *     proposed_redmine_name: ?string
 * } $operation
 * @return array{
 *     mapping_id: int,
 *     redmine_group_id: ?int,
 *     proposed_redmine_name: ?string,
 *     migration_status: string,
 *     notes: ?string,
 *     automation_hash: string
 * }
 */
function buildGroupPushUpdateValues(
    array $operation,
    ?int $redmineGroupId,
    string $migrationStatus,
    ?string $finalName,
    ?string $notes
): array {
    $proposedName = $finalName ?? $operation['proposed_redmine_name'] ?? $operation['staging_group_name'] ?? $operation['jira_group_name'];

    $automationHash = computeGroupAutomationStateHash(
        $redmineGroupId,
        $migrationStatus,
        $proposedName,
        $notes
    );

    return [
        'mapping_id' => $operation['mapping_id'],
        'redmine_group_id' => $redmineGroupId,
        'proposed_redmine_name' => $proposedName,
        'migration_status' => $migrationStatus,
        'notes' => $notes,
        'automation_hash' => $automationHash,
    ];
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
        MIGRATE_GROUPS_SCRIPT_VERSION,
        PHP_EOL
    );
    echo sprintf('Usage: php %s [options]%s', $scriptName, PHP_EOL);
    echo PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  -h, --help           Display this help message and exit." . PHP_EOL;
    echo "  -V, --version        Print the script version and exit." . PHP_EOL;
    echo "      --phases=LIST    Comma-separated list of phases to execute." . PHP_EOL;
    echo "      --skip=LIST      Comma-separated list of phases to skip." . PHP_EOL;
    echo "      --confirm-push   Allow the push phase to contact Redmine (required for future writes)." . PHP_EOL;
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
    printf('%s version %s%s', basename(__FILE__), MIGRATE_GROUPS_SCRIPT_VERSION, PHP_EOL);
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

/**
 * @return array{matched: int, ready_for_creation: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int, status_counts: array<string, int>}
 */
function runGroupTransformationPhase(PDO $pdo): array
{
    synchronizeMigrationMappingGroups($pdo);
    synchronizeMigrationMappingGroupMembers($pdo);

    $redmineLookup = fetchRedmineGroupLookups($pdo);
    $mappings = fetchGroupMappingsForTransform($pdo);

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_groups
        SET
            redmine_group_id = :redmine_group_id,
            proposed_redmine_name = :proposed_redmine_name,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE mapping_id = :mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_groups during the transform phase.');
    }

    $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_CREATION', 'MATCH_FOUND'];

    $summary = [
        'matched' => 0,
        'ready_for_creation' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
    ];

    foreach ($mappings as $row) {
        $jiraGroupReference = formatJiraGroupReference($row['jira_group_id'], $row['jira_group_name'] ?? null);

        $currentStatus = (string)$row['migration_status'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        $currentAutomationHash = computeGroupAutomationStateHash(
            isset($row['redmine_group_id']) ? (int)$row['redmine_group_id'] : null,
            $currentStatus,
            $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null,
            $row['notes'] !== null ? (string)$row['notes'] : null
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            printf(
                "  [preserved] Jira group %s (mapping #%d) has manual overrides; skipping.%s",
                $jiraGroupReference,
                $row['mapping_id'],
                PHP_EOL
            );
            $summary['manual_overrides']++;
            $summary['skipped']++;
            continue;
        }

        $currentRedmineId = $row['redmine_group_id'] ?? null;
        $currentProposedName = $row['proposed_redmine_name'] ?? null;
        $currentNotes = $row['notes'] ?? null;

        $hasStagingData = $row['staging_group_name'] !== null;
        $jiraGroupName = $row['staging_group_name'] ?? $row['jira_group_name'] ?? null;
        $normalizedJiraName = normalizeString($jiraGroupName, 255);

        $newStatus = $currentStatus;
        $newRedmineId = $currentRedmineId;
        $proposedName = $currentProposedName;
        $notes = $currentNotes;

        $manualReason = null;
        $matchedGroup = null;

        if (!$hasStagingData) {
            $manualReason = 'No staging data available for this Jira group. Re-run the extraction phase.';
        } elseif ($normalizedJiraName === null) {
            $manualReason = 'Missing Jira group name in the staging snapshot.';
        } else {
            $lookupKey = buildRedmineGroupLookupKey($normalizedJiraName);
            $matches = $lookupKey !== null ? ($redmineLookup[$lookupKey] ?? []) : [];

            if ($matches !== []) {
                if (count($matches) === 1) {
                    $matchedGroup = $matches[0];
                } else {
                    $manualReason = sprintf('Multiple Redmine groups share the normalized name "%s".', $normalizedJiraName);
                }
            }

            if ($manualReason === null && $matchedGroup === null) {
                $newStatus = 'READY_FOR_CREATION';
                $newRedmineId = null;
                $proposedName = $normalizedJiraName;
            } elseif ($matchedGroup !== null) {
                $newStatus = 'MATCH_FOUND';
                $newRedmineId = (int)$matchedGroup['id'];
                $proposedName = $matchedGroup['name'];
            }
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = $manualReason;
            $newRedmineId = $matchedGroup !== null ? (int)$matchedGroup['id'] : $newRedmineId;
            if ($proposedName === null && $normalizedJiraName !== null) {
                $proposedName = $normalizedJiraName;
            }
            printf(
                "  [manual] Jira group %s: %s%s",
                $jiraGroupReference,
                $manualReason,
                PHP_EOL
            );
        } else {
            if ($newStatus !== 'MANUAL_INTERVENTION_REQUIRED') {
                $notes = null;
            }
        }

        if ($newStatus === 'READY_FOR_CREATION' && ($proposedName === null || trim($proposedName) === '')) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = 'Unable to determine a Redmine group name to create.';
            printf(
                "  [manual] Jira group %s: %s%s",
                $jiraGroupReference,
                $notes,
                PHP_EOL
            );
        }

        $normalizedProposedName = normalizeString($proposedName, 255);

        $newAutomationHash = computeGroupAutomationStateHash(
            $newRedmineId,
            $newStatus,
            $normalizedProposedName,
            $notes
        );

        $needsUpdate = (
            $currentRedmineId !== $newRedmineId
            || $currentStatus !== $newStatus
            || $currentProposedName !== $normalizedProposedName
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash
        );

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_group_id' => $newRedmineId,
            'proposed_redmine_name' => $normalizedProposedName,
            'migration_status' => $newStatus,
            'notes' => $notes,
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

    $summary['status_counts'] = fetchGroupMigrationStatusCounts($pdo);

    $memberResult = runGroupMembershipTransformation($pdo);
    $summary['member_summary'] = $memberResult['summary'];
    $summary['member_status_counts'] = $memberResult['status_counts'];

    return $summary;
}
function synchronizeMigrationMappingGroups(PDO $pdo): void
{
    $sql = <<<'SQL'
        INSERT INTO migration_mapping_groups (jira_group_id, jira_group_name)
        SELECT group_id, name
        FROM staging_jira_groups
        ON DUPLICATE KEY UPDATE
            jira_group_name = VALUES(jira_group_name)
    SQL;

    $result = $pdo->exec($sql);
    if ($result === false) {
        throw new RuntimeException('Failed to synchronize Jira groups into migration_mapping_groups.');
    }
}

function synchronizeMigrationMappingGroupMembers(PDO $pdo): void
{
    $sql = <<<'SQL'
        INSERT INTO migration_mapping_group_members (jira_group_id, jira_group_name, jira_account_id)
        SELECT
            sjgm.group_id,
            sjg.name,
            sjgm.account_id
        FROM staging_jira_group_members AS sjgm
        LEFT JOIN staging_jira_groups AS sjg ON sjg.group_id = sjgm.group_id
        ON DUPLICATE KEY UPDATE
            jira_group_name = VALUES(jira_group_name)
    SQL;

    $result = $pdo->exec($sql);
    if ($result === false) {
        throw new RuntimeException('Failed to synchronize Jira group memberships into migration_mapping_group_members.');
    }

    $updateSql = <<<'SQL'
        UPDATE migration_mapping_group_members AS mmgm
        JOIN migration_mapping_groups AS mmg ON mmg.jira_group_id = mmgm.jira_group_id
        SET
            mmgm.redmine_group_id = mmg.redmine_group_id,
            mmgm.jira_group_name = COALESCE(mmg.jira_group_name, mmgm.jira_group_name),
            mmgm.automation_hash = IF(mmg.redmine_group_id IS NOT NULL
                     AND (mmgm.redmine_group_id IS NULL OR mmgm.redmine_group_id <> mmg.redmine_group_id), NULL, mmgm.automation_hash)
        WHERE (mmg.redmine_group_id IS NOT NULL
                AND (mmgm.redmine_group_id IS NULL OR mmgm.redmine_group_id <> mmg.redmine_group_id))
           OR (mmgm.jira_group_name IS NULL AND mmg.jira_group_name IS NOT NULL)
    SQL;

    $updateResult = $pdo->exec($updateSql);
    if ($updateResult === false) {
        throw new RuntimeException('Failed to backfill Redmine group identifiers into migration_mapping_group_members.');
    }
}

/**
 * @return array<int, array{
 *     mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     redmine_group_id: ?int,
 *     proposed_redmine_name: ?string,
 *     migration_status: string,
 *     notes: ?string,
 *     automation_hash: ?string,
 *     staging_group_name: ?string
 * }>
 */
function fetchGroupMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mm.mapping_id,
    mm.jira_group_id,
    mm.jira_group_name,
    mm.redmine_group_id,
    mm.proposed_redmine_name,
    mm.migration_status,
    mm.notes,
    mm.automation_hash,
    sj.name AS staging_group_name
FROM migration_mapping_groups AS mm
LEFT JOIN staging_jira_groups AS sj ON sj.group_id = mm.jira_group_id
ORDER BY mm.mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load group mappings for transformation: ' . $exception->getMessage(), 0, $exception);
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
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'redmine_group_id' => $row['redmine_group_id'] !== null ? (int)$row['redmine_group_id'] : null,
            'proposed_redmine_name' => $row['proposed_redmine_name'] !== null ? (string)$row['proposed_redmine_name'] : null,
            'migration_status' => (string)$row['migration_status'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'automation_hash' => $row['automation_hash'] !== null ? (string)$row['automation_hash'] : null,
            'staging_group_name' => $row['staging_group_name'] !== null ? (string)$row['staging_group_name'] : null,
        ];
    }

    return $mappings;
}

/**
 * @return array<int, array{
 *     member_mapping_id: int,
 *     jira_group_id: string,
 *     jira_group_name: ?string,
 *     jira_account_id: string,
 *     redmine_group_id: ?int,
 *     redmine_user_id: ?int,
 *     migration_status: string,
 *     notes: ?string,
 *     automation_hash: ?string,
 *     has_staging_data: bool,
 *     jira_display_name: ?string,
 *     jira_email_address: ?string,
 *     mapped_redmine_group_id: ?int,
 *     group_migration_status: ?string,
 *     existing_membership_user_id: ?int,
 *     mapped_redmine_user_id: ?int,
 *     user_migration_status: ?string
 * }>
 */
function fetchGroupMemberMappingsForTransform(PDO $pdo): array
{
    $sql = <<<SQL
SELECT
    mmgm.member_mapping_id,
    mmgm.jira_group_id,
    mmgm.jira_group_name,
    mmgm.jira_account_id,
    mmgm.redmine_group_id,
    mmgm.redmine_user_id,
    mmgm.migration_status,
    mmgm.notes,
    mmgm.automation_hash,
    IF(sjgm.group_id IS NULL, 0, 1) AS has_staging_data,
    sju.display_name AS jira_display_name,
    sju.email_address AS jira_email_address,
    mmg.redmine_group_id AS mapped_redmine_group_id,
    mmg.migration_status AS group_migration_status,
    sr_members.user_id AS existing_membership_user_id,
    mmu.redmine_user_id AS mapped_redmine_user_id,
    mmu.migration_status AS user_migration_status
FROM migration_mapping_group_members AS mmgm
LEFT JOIN staging_jira_group_members AS sjgm
    ON sjgm.group_id = mmgm.jira_group_id
   AND sjgm.account_id = mmgm.jira_account_id
LEFT JOIN staging_jira_users AS sju
    ON sju.account_id = mmgm.jira_account_id
LEFT JOIN migration_mapping_groups AS mmg
    ON mmg.jira_group_id = mmgm.jira_group_id
LEFT JOIN migration_mapping_users AS mmu
    ON mmu.jira_account_id = mmgm.jira_account_id
LEFT JOIN staging_redmine_group_members AS sr_members
    ON sr_members.group_id = COALESCE(mmg.redmine_group_id, mmgm.redmine_group_id)
   AND sr_members.user_id = COALESCE(mmgm.redmine_user_id, mmu.redmine_user_id)
ORDER BY mmgm.member_mapping_id
SQL;

    try {
        $statement = $pdo->query($sql);
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to load group membership mappings for transformation: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $mappings = [];

    foreach ($rows as $row) {
        $mappings[] = [
            'member_mapping_id' => (int)$row['member_mapping_id'],
            'jira_group_id' => (string)$row['jira_group_id'],
            'jira_group_name' => $row['jira_group_name'] !== null ? (string)$row['jira_group_name'] : null,
            'jira_account_id' => (string)$row['jira_account_id'],
            'redmine_group_id' => $row['redmine_group_id'] !== null ? (int)$row['redmine_group_id'] : null,
            'redmine_user_id' => $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null,
            'migration_status' => (string)$row['migration_status'],
            'notes' => $row['notes'] !== null ? (string)$row['notes'] : null,
            'automation_hash' => $row['automation_hash'] !== null ? (string)$row['automation_hash'] : null,
            'has_staging_data' => (bool)$row['has_staging_data'],
            'jira_display_name' => $row['jira_display_name'] !== null ? (string)$row['jira_display_name'] : null,
            'jira_email_address' => $row['jira_email_address'] !== null ? (string)$row['jira_email_address'] : null,
            'mapped_redmine_group_id' => $row['mapped_redmine_group_id'] !== null ? (int)$row['mapped_redmine_group_id'] : null,
            'group_migration_status' => $row['group_migration_status'] !== null ? (string)$row['group_migration_status'] : null,
            'existing_membership_user_id' => $row['existing_membership_user_id'] !== null ? (int)$row['existing_membership_user_id'] : null,
            'mapped_redmine_user_id' => $row['mapped_redmine_user_id'] !== null ? (int)$row['mapped_redmine_user_id'] : null,
            'user_migration_status' => $row['user_migration_status'] !== null ? (string)$row['user_migration_status'] : null,
        ];
    }

    return $mappings;
}

/**
 * @return array{summary: array{matched: int, ready_for_assignment: int, awaiting_group: int, awaiting_user: int, manual_review: int, manual_overrides: int, skipped: int, unchanged: int}, status_counts: array<string, int>}
 */
function runGroupMembershipTransformation(PDO $pdo): array
{
    $mappings = fetchGroupMemberMappingsForTransform($pdo);

    $allowedStatuses = ['PENDING_ANALYSIS', 'READY_FOR_ASSIGNMENT', 'MATCH_FOUND', 'AWAITING_GROUP', 'AWAITING_USER'];

    $summary = [
        'matched' => 0,
        'ready_for_assignment' => 0,
        'awaiting_group' => 0,
        'awaiting_user' => 0,
        'manual_review' => 0,
        'manual_overrides' => 0,
        'skipped' => 0,
        'unchanged' => 0,
    ];

    if ($mappings === []) {
        return [
            'summary' => $summary,
            'status_counts' => fetchGroupMemberMigrationStatusCounts($pdo),
        ];
    }

    $updateStatement = $pdo->prepare(<<<SQL
        UPDATE migration_mapping_group_members
        SET
            redmine_group_id = :redmine_group_id,
            redmine_user_id = :redmine_user_id,
            migration_status = :migration_status,
            notes = :notes,
            automation_hash = :automation_hash
        WHERE member_mapping_id = :member_mapping_id
    SQL);

    if ($updateStatement === false) {
        throw new RuntimeException('Failed to prepare update statement for migration_mapping_group_members.');
    }

    foreach ($mappings as $row) {
        $jiraGroupReference = formatJiraGroupReference($row['jira_group_id'], $row['jira_group_name'] ?? null);

        $currentStatus = (string)$row['migration_status'];
        if (!in_array($currentStatus, $allowedStatuses, true)) {
            $summary['skipped']++;
            continue;
        }

        $storedAutomationHash = normalizeStoredAutomationHash($row['automation_hash'] ?? null);
        $currentAutomationHash = computeGroupMemberAutomationStateHash(
            $row['redmine_group_id'] !== null ? (int)$row['redmine_group_id'] : null,
            $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null,
            $currentStatus,
            $row['notes'] !== null ? (string)$row['notes'] : null
        );

        if ($storedAutomationHash !== null && $storedAutomationHash !== $currentAutomationHash) {
            printf(
                "  [preserved] Jira group %s member %s (mapping #%d) has manual overrides; skipping.%s",
                $jiraGroupReference,
                $row['jira_account_id'],
                $row['member_mapping_id'],
                PHP_EOL
            );
            $summary['manual_overrides']++;
            $summary['skipped']++;
            continue;
        }

        $currentGroupId = $row['redmine_group_id'] !== null ? (int)$row['redmine_group_id'] : null;
        $currentUserId = $row['redmine_user_id'] !== null ? (int)$row['redmine_user_id'] : null;
        $currentNotes = $row['notes'] !== null ? (string)$row['notes'] : null;

        $newGroupId = $currentGroupId;
        $newUserId = $currentUserId;
        $manualReason = null;

        if ($row['has_staging_data'] === false) {
            $manualReason = 'No staging data available for this Jira membership. Re-run the extraction phase.';
        }

        $mappedGroupId = $row['mapped_redmine_group_id'];
        if ($mappedGroupId !== null) {
            $newGroupId = $mappedGroupId;
        }

        $groupStatus = $row['group_migration_status'];
        if ($manualReason === null) {
            if (in_array($groupStatus, ['MANUAL_INTERVENTION_REQUIRED', 'IGNORED', 'CREATION_FAILED'], true)) {
                $manualReason = sprintf('Group mapping is currently %s. Resolve before assigning members.', $groupStatus);
            }
        }

        if ($manualReason !== null) {
            $newStatus = 'MANUAL_INTERVENTION_REQUIRED';
            $notes = $manualReason;
            printf(
                "  [manual] Jira group %s member %s: %s%s",
                $jiraGroupReference,
                $row['jira_account_id'],
                $manualReason,
                PHP_EOL
            );
        } elseif ($newGroupId === null) {
            if (in_array($groupStatus, ['PENDING_ANALYSIS', 'READY_FOR_CREATION'], true)) {
                $notes = sprintf(
                    'Redmine group mapping currently %s; wait for the group to exist before assigning members.',
                    $groupStatus
                );
            } else {
                $notes = 'Redmine group has not been created yet. Re-run after the group exists.';
            }
            $newStatus = 'AWAITING_GROUP';
        } else {
            $targetUserId = $newUserId ?? $row['mapped_redmine_user_id'];
            $existingMembership = $row['existing_membership_user_id'] !== null;

            if ($targetUserId !== null) {
                $newUserId = $targetUserId;
            }

            if ($targetUserId === null) {
                $newStatus = 'AWAITING_USER';
                $notes = 'No Redmine user mapping is available for this Jira account yet.';
            } elseif ($existingMembership) {
                $newStatus = 'MATCH_FOUND';
                $notes = null;
            } else {
                $userStatus = $row['user_migration_status'];
                $userReady = true;
                if ($row['mapped_redmine_user_id'] !== null && $row['mapped_redmine_user_id'] === $targetUserId) {
                    if ($userStatus !== null && !in_array($userStatus, ['MATCH_FOUND', 'CREATION_SUCCESS'], true)) {
                        $userReady = false;
                    }
                }

                if (!$userReady) {
                    $newStatus = 'AWAITING_USER';
                    $notes = sprintf(
                        'Redmine user mapping is currently %s; wait for the user to exist before assignment.',
                        $userStatus ?? 'unknown'
                    );
                } else {
                    $newStatus = 'READY_FOR_ASSIGNMENT';
                    $notes = null;
                }
            }
        }

        $newAutomationHash = computeGroupMemberAutomationStateHash(
            $newGroupId,
            $newUserId,
            $newStatus,
            $notes
        );

        $needsUpdate = (
            $currentGroupId !== $newGroupId
            || $currentUserId !== $newUserId
            || $currentStatus !== $newStatus
            || $currentNotes !== $notes
            || $storedAutomationHash !== $newAutomationHash
        );

        if (!$needsUpdate) {
            $summary['unchanged']++;
            continue;
        }

        $updateStatement->execute([
            'redmine_group_id' => $newGroupId,
            'redmine_user_id' => $newUserId,
            'migration_status' => $newStatus,
            'notes' => $notes,
            'automation_hash' => $newAutomationHash,
            'member_mapping_id' => $row['member_mapping_id'],
        ]);

        if ($newStatus === 'MATCH_FOUND' && $newStatus !== $currentStatus) {
            $summary['matched']++;
        } elseif ($newStatus === 'READY_FOR_ASSIGNMENT' && $newStatus !== $currentStatus) {
            $summary['ready_for_assignment']++;
        } elseif ($newStatus === 'AWAITING_GROUP' && $newStatus !== $currentStatus) {
            $summary['awaiting_group']++;
        } elseif ($newStatus === 'AWAITING_USER' && $newStatus !== $currentStatus) {
            $summary['awaiting_user']++;
        } elseif ($newStatus === 'MANUAL_INTERVENTION_REQUIRED' && $newStatus !== $currentStatus) {
            $summary['manual_review']++;
        }
    }

    return [
        'summary' => $summary,
        'status_counts' => fetchGroupMemberMigrationStatusCounts($pdo),
    ];
}

/**
 * @return array<string, array<int, array{id: int, name: string}>>
 */
function fetchRedmineGroupLookups(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT id, name FROM staging_redmine_groups');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to fetch Redmine group snapshot from the database: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $lookup = [];

    foreach ($rows as $row) {
        $name = $row['name'] ?? null;
        $normalizedName = normalizeString($name, 255);
        if ($normalizedName === null) {
            continue;
        }

        $lookupKey = buildRedmineGroupLookupKey($normalizedName);
        if ($lookupKey === null) {
            continue;
        }

        $lookup[$lookupKey] ??= [];
        $lookup[$lookupKey][] = [
            'id' => isset($row['id']) ? (int)$row['id'] : 0,
            'name' => $normalizedName,
        ];
    }

    return $lookup;
}

function buildRedmineGroupLookupKey(?string $name): ?string
{
    if ($name === null) {
        return null;
    }

    return lowercaseValue($name);
}

/**
 * @return array<string, int>
 */
function fetchGroupMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_groups GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute migration status counts: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($rows as $row) {
        if (!isset($row['migration_status'])) {
            continue;
        }

        $status = (string)$row['migration_status'];
        $results[$status] = isset($row['total']) ? (int)$row['total'] : 0;
    }

    return $results;
}

/**
 * @return array<string, int>
 */
function fetchGroupMemberMigrationStatusCounts(PDO $pdo): array
{
    try {
        $statement = $pdo->query('SELECT migration_status, COUNT(*) AS total FROM migration_mapping_group_members GROUP BY migration_status');
    } catch (PDOException $exception) {
        throw new RuntimeException('Failed to compute group member migration status counts: ' . $exception->getMessage(), 0, $exception);
    }

    if ($statement === false) {
        return [];
    }

    /** @var array<int, array<string, mixed>> $rows */
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $results = [];

    foreach ($rows as $row) {
        if (!isset($row['migration_status'])) {
            continue;
        }

        $status = (string)$row['migration_status'];
        $results[$status] = isset($row['total']) ? (int)$row['total'] : 0;
    }

    return $results;
}

/**
 * @throws GuzzleException|Throwable
 */
function fetchAndStoreJiraGroups(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_jira_group_members');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_jira_group_members: ' . $exception->getMessage(), 0, $exception);
    }

    $maxResults = 50;
    $startAt = 0;
    $totalInserted = 0;
    $groupIds = [];

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_groups (group_id, name, raw_payload, extracted_at)
        VALUES (:group_id, :name, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_groups.');
    }

    while (true) {
        try {
            $response = $client->get('/rest/api/3/group/bulk', [
                'query' => [
                    'startAt' => $startAt,
                    'maxResults' => $maxResults,
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch groups from Jira: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Jira response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['values']) || !is_array($decoded['values'])) {
            throw new RuntimeException('Unexpected response from Jira when fetching groups.');
        }

        $groups = $decoded['values'];
        $batchCount = count($groups);

        if ($batchCount === 0) {
            break;
        }

        $batchInserted = 0;
        $pdo->beginTransaction();

        try {
            $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

            foreach ($groups as $group) {
                if (!is_array($group)) {
                    continue;
                }

                $groupId = isset($group['groupId']) ? trim((string)$group['groupId']) : '';
                if ($groupId === '') {
                    continue;
                }

                $name = normalizeString($group['name'] ?? null, 255);
                if ($name === null) {
                    $name = $groupId;
                }

                try {
                    $rawPayload = json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Jira group payload: ' . $exception->getMessage(), 0, $exception);
                }

                $insertStatement->execute([
                    'group_id' => $groupId,
                    'name' => $name,
                    'raw_payload' => $rawPayload,
                    'extracted_at' => $extractedAt,
                ]);

                $batchInserted++;
                $groupIds[$groupId] = true;
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        $totalInserted += $batchInserted;
        printf("  Processed %d Jira groups (total inserted: %d).%s", $batchInserted, $totalInserted, PHP_EOL);

        $startAtValue = isset($decoded['startAt']) && is_numeric($decoded['startAt']) ? (int)$decoded['startAt'] : $startAt;
        $startAt = $startAtValue + $batchCount;

        if ($batchCount < $maxResults) {
            break;
        }

        $totalValue = isset($decoded['total']) && is_numeric($decoded['total']) ? (int)$decoded['total'] : null;
        if ($totalValue !== null && $startAt >= $totalValue) {
            break;
        }
    }

    $uniqueGroupIds = array_keys($groupIds);
    $membershipCount = refreshJiraGroupMemberships($client, $pdo, $uniqueGroupIds);
    printf("  Captured %d Jira group membership row(s).%s", $membershipCount, PHP_EOL);

    return $totalInserted;
}

/**
 * @throws Throwable
 */
function fetchAndStoreRedmineGroups(Client $client, PDO $pdo): int
{
    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_groups');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_groups: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_group_members');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_group_members: ' . $exception->getMessage(), 0, $exception);
    }

    try {
        $pdo->exec('TRUNCATE TABLE staging_redmine_group_project_roles');
    } catch (PDOException $exception) {
        throw new RuntimeException('Unable to truncate staging_redmine_group_project_roles: ' . $exception->getMessage(), 0, $exception);
    }

    $limit = 100;
    $offset = 0;
    $totalInserted = 0;
    $groupIds = [];

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_groups (id, name, raw_payload, retrieved_at)
        VALUES (:id, :name, :raw_payload, :retrieved_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_groups.');
    }

    while (true) {
        try {
            $response = $client->get('groups.json', [
                'query' => [
                    'limit' => $limit,
                    'offset' => $offset,
                ],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException('Failed to fetch groups from Redmine: ' . $exception->getMessage(), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Redmine response payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['groups']) || !is_array($decoded['groups'])) {
            throw new RuntimeException('Unexpected response from Redmine when fetching groups.');
        }

        $groups = $decoded['groups'];
        $batchCount = count($groups);

        if ($batchCount === 0) {
            break;
        }

        $rowsToInsert = [];
        $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $groupId = isset($group['id']) ? (int)$group['id'] : 0;
            if ($groupId <= 0) {
                continue;
            }

            $name = normalizeString($group['name'] ?? null, 255);
            if ($name === null) {
                throw new RuntimeException(sprintf('Redmine group %d is missing a name attribute.', $groupId));
            }

            try {
                $rawPayload = json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } catch (JsonException $exception) {
                throw new RuntimeException(sprintf('Failed to encode Redmine group payload for group %d: %s', $groupId, $exception->getMessage()), 0, $exception);
            }

            $rowsToInsert[] = [
                'id' => $groupId,
                'name' => $name,
                'raw_payload' => $rawPayload,
                'retrieved_at' => $retrievedAt,
            ];

            $groupIds[$groupId] = true;
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
            printf("  Processed %d Redmine groups (total inserted: %d).%s", count($rowsToInsert), $totalInserted, PHP_EOL);
        } else {
            printf("  Received %d Redmine groups but none were inserted (all skipped).%s", $batchCount, PHP_EOL);
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

    $uniqueRedmineGroupIds = array_keys($groupIds);
    $membershipCounts = refreshRedmineGroupMemberships($client, $pdo, $uniqueRedmineGroupIds);
    printf(
        "  Captured %d Redmine group membership row(s) and %d project role membership row(s).%s",
        $membershipCounts['user_memberships'],
        $membershipCounts['project_role_memberships'],
        PHP_EOL
    );

    return $totalInserted;
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @param array<int, string> $groupIds
 * @return int
 * @throws GuzzleException
 */
function refreshJiraGroupMemberships(Client $client, PDO $pdo, array $groupIds): int
{
    if ($groupIds === []) {
        return 0;
    }

    $insertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_jira_group_members (group_id, account_id, raw_payload, extracted_at)
        VALUES (:group_id, :account_id, :raw_payload, :extracted_at)
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            extracted_at = VALUES(extracted_at)
    SQL);

    if ($insertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_jira_group_members.');
    }

    $totalInserted = 0;
    $maxResults = 50;

    foreach ($groupIds as $groupId) {
        $startAt = 0;

        while (true) {
            try {
                $response = $client->get('/rest/api/3/group/member', [
                    'query' => [
                        'groupId' => $groupId,
                        'startAt' => $startAt,
                        'maxResults' => $maxResults,
                    ],
                ]);
            } catch (GuzzleException $exception) {
                throw new RuntimeException(sprintf('Failed to fetch Jira members for group %s: %s', $groupId, $exception->getMessage()), 0, $exception);
            }

            try {
                $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Unable to decode Jira group member payload: ' . $exception->getMessage(), 0, $exception);
            }

            if (!is_array($decoded) || !isset($decoded['values']) || !is_array($decoded['values'])) {
                throw new RuntimeException(sprintf('Unexpected response from Jira when fetching members for group %s.', $groupId));
            }

            $members = $decoded['values'];
            if ($members === []) {
                break;
            }

            $extractedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

            $pdo->beginTransaction();

            try {
                foreach ($members as $member) {
                    if (!is_array($member)) {
                        continue;
                    }

                    $accountId = isset($member['accountId']) ? trim((string)$member['accountId']) : '';
                    if ($accountId === '') {
                        continue;
                    }

                    try {
                        $rawPayload = json_encode($member, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    } catch (JsonException $exception) {
                        throw new RuntimeException('Failed to encode Jira group member payload: ' . $exception->getMessage(), 0, $exception);
                    }

                    $insertStatement->execute([
                        'group_id' => $groupId,
                        'account_id' => $accountId,
                        'raw_payload' => $rawPayload,
                        'extracted_at' => $extractedAt,
                    ]);

                    $totalInserted++;
                }

                $pdo->commit();
            } catch (Throwable $exception) {
                $pdo->rollBack();
                throw $exception;
            }

            $startAtValue = isset($decoded['startAt']) && is_numeric($decoded['startAt']) ? (int)$decoded['startAt'] : $startAt;
            $maxResultsValue = isset($decoded['maxResults']) && is_numeric($decoded['maxResults']) ? (int)$decoded['maxResults'] : $maxResults;
            $startAt = $startAtValue + $maxResultsValue;

            $totalValue = isset($decoded['total']) && is_numeric($decoded['total']) ? (int)$decoded['total'] : null;
            if ($totalValue !== null && $startAt >= $totalValue) {
                break;
            }
        }
    }

    return $totalInserted;
}

/**
 * @param Client $client
 * @param PDO $pdo
 * @param array<int, int> $groupIds
 * @return array{user_memberships: int, project_role_memberships: int}
 * @throws GuzzleException
 */
function refreshRedmineGroupMemberships(Client $client, PDO $pdo, array $groupIds): array
{
    if ($groupIds === []) {
        return [
            'user_memberships' => 0,
            'project_role_memberships' => 0,
        ];
    }

    $memberInsertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_group_members (group_id, user_id, raw_payload, retrieved_at)
        VALUES (:group_id, :user_id, :raw_payload, :retrieved_at)
        ON DUPLICATE KEY UPDATE
            raw_payload = VALUES(raw_payload),
            retrieved_at = VALUES(retrieved_at)
    SQL);

    if ($memberInsertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_group_members.');
    }

    $projectRoleInsertStatement = $pdo->prepare(<<<SQL
        INSERT INTO staging_redmine_group_project_roles (
            group_id,
            membership_id,
            project_id,
            project_name,
            role_id,
            role_name,
            raw_payload,
            retrieved_at
        ) VALUES (
            :group_id,
            :membership_id,
            :project_id,
            :project_name,
            :role_id,
            :role_name,
            :raw_payload,
            :retrieved_at
        )
        ON DUPLICATE KEY UPDATE
            project_name = VALUES(project_name),
            role_name = VALUES(role_name),
            raw_payload = VALUES(raw_payload),
            retrieved_at = VALUES(retrieved_at)
    SQL);

    if ($projectRoleInsertStatement === false) {
        throw new RuntimeException('Failed to prepare insert statement for staging_redmine_group_project_roles.');
    }

    $userMembershipCount = 0;
    $projectRoleMembershipCount = 0;

    foreach ($groupIds as $groupId) {
        try {
            $response = $client->get(sprintf('groups/%d.json', $groupId), [
                'query' => ['include' => 'users,memberships'],
            ]);
        } catch (GuzzleException $exception) {
            throw new RuntimeException(sprintf('Failed to fetch Redmine members for group %d: %s', $groupId, $exception->getMessage()), 0, $exception);
        }

        try {
            $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to decode Redmine group member payload: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($decoded) || !isset($decoded['group']) || !is_array($decoded['group'])) {
            throw new RuntimeException(sprintf('Unexpected response from Redmine when fetching members for group %d.', $groupId));
        }

        $group = $decoded['group'];
        $users = $group['users'] ?? [];
        $memberships = $group['memberships'] ?? [];

        if (!is_array($users)) {
            $users = [];
        }

        if (!is_array($memberships)) {
            $memberships = [];
        }

        if ($users === [] && $memberships === []) {
            continue;
        }

        $retrievedAt = formatCurrentUtcTimestamp('Y-m-d H:i:s');

        $pdo->beginTransaction();

        try {
            foreach ($users as $user) {
                if (!is_array($user) || !isset($user['id'])) {
                    continue;
                }

                $userId = (int)$user['id'];

                try {
                    $rawPayload = json_encode($user, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } catch (JsonException $exception) {
                    throw new RuntimeException('Failed to encode Redmine group member payload: ' . $exception->getMessage(), 0, $exception);
                }

                $memberInsertStatement->execute([
                    'group_id' => $groupId,
                    'user_id' => $userId,
                    'raw_payload' => $rawPayload,
                    'retrieved_at' => $retrievedAt,
                ]);

                $userMembershipCount++;
            }

            foreach ($memberships as $membership) {
                if (!is_array($membership) || !isset($membership['id'])) {
                    continue;
                }

                $membershipId = (int)$membership['id'];
                if ($membershipId <= 0) {
                    continue;
                }

                $project = $membership['project'] ?? null;
                if (!is_array($project) || !isset($project['id'])) {
                    continue;
                }

                $projectId = (int)$project['id'];
                if ($projectId <= 0) {
                    continue;
                }

                $projectName = normalizeString($project['name'] ?? null, 255);

                $roles = $membership['roles'] ?? [];
                if (!is_array($roles) || $roles === []) {
                    continue;
                }

                foreach ($roles as $role) {
                    if (!is_array($role) || !isset($role['id'])) {
                        continue;
                    }

                    $roleId = (int)$role['id'];
                    if ($roleId <= 0) {
                        continue;
                    }

                    $roleName = normalizeString($role['name'] ?? null, 255);

                    try {
                        $rawPayload = json_encode(
                            [
                                'membership_id' => $membershipId,
                                'project' => $project,
                                'role' => $role,
                            ],
                            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        );
                    } catch (JsonException $exception) {
                        throw new RuntimeException('Failed to encode Redmine group project role payload: ' . $exception->getMessage(), 0, $exception);
                    }

                    $projectRoleInsertStatement->execute([
                        'group_id' => $groupId,
                        'membership_id' => $membershipId,
                        'project_id' => $projectId,
                        'project_name' => $projectName,
                        'role_id' => $roleId,
                        'role_name' => $roleName,
                        'raw_payload' => $rawPayload,
                        'retrieved_at' => $retrievedAt,
                    ]);

                    $projectRoleMembershipCount++;
                }
            }

            $pdo->commit();
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    return [
        'user_memberships' => $userMembershipCount,
        'project_role_memberships' => $projectRoleMembershipCount,
    ];
}

function computeGroupAutomationStateHash(
    ?int $redmineGroupId,
    string $migrationStatus,
    ?string $proposedName,
    ?string $notes
): string {
    try {
        $payload = json_encode(
            [
                'redmine_group_id' => $redmineGroupId,
                'migration_status' => $migrationStatus,
                'proposed_redmine_name' => $proposedName,
                'notes' => $notes,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode automation state hash payload: ' . $exception->getMessage(), 0, $exception);
    }

    return hash('sha256', (string)$payload);
}

function computeGroupMemberAutomationStateHash(
    ?int $redmineGroupId,
    ?int $redmineUserId,
    string $migrationStatus,
    ?string $notes
): string {
    try {
        $payload = json_encode(
            [
                'redmine_group_id' => $redmineGroupId,
                'redmine_user_id' => $redmineUserId,
                'migration_status' => $migrationStatus,
                'notes' => $notes,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    } catch (JsonException $exception) {
        throw new RuntimeException('Failed to encode group membership automation state hash payload: ' . $exception->getMessage(), 0, $exception);
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

function lowercaseValue(mixed $value): ?string
{
    if (!is_string($value)) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    if (function_exists('mb_strtolower')) {
        return mb_strtolower($trimmed);
    }

    return strtolower($trimmed);
}
